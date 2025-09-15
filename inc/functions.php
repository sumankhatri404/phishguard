<?php
/**
 * inc/functions.php
 * Session + CSRF helpers, Streaks/levels/XP helpers, Spot-the-Phish idempotency.
 */
declare(strict_types=1);

/* =========================
   Session boot (InfinityFree/XAMPP friendly)
========================= */
if (session_status() !== PHP_SESSION_ACTIVE) {
  $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
  // SameSite=Lax so POSTs keep the session on mobile
  session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
  session_start();
}

require_once __DIR__ . '/db.php';

/* =========================
   CSRF helpers (2h TTL + rotate on success)
========================= */
const CSRF_KEY = 'csrf_token';
const CSRF_EXP = 'csrf_token_exp';
const CSRF_TTL = 7200; // seconds

function csrf_token(): string {
  $now = time();
  $tok = $_SESSION[CSRF_KEY] ?? '';
  $exp = (int)($_SESSION[CSRF_EXP] ?? 0);

  if (!is_string($tok) || strlen($tok) < 32 || $now >= $exp) {
    $raw = random_bytes(32);
    $tok = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    $_SESSION[CSRF_KEY] = $tok;
    $_SESSION[CSRF_EXP] = $now + CSRF_TTL;
  }
  return $tok;
}

function verify_csrf(?string $t): bool {
  if (!is_string($t) || $t === '') return false;
  $sess = $_SESSION[CSRF_KEY] ?? '';
  $exp  = (int)($_SESSION[CSRF_EXP] ?? 0);
  if (!is_string($sess) || $sess === '' || time() >= $exp) return false;
  return hash_equals($sess, $t);
}

/** Call after a *successful* POST to rotate the token. */
function rotate_csrf(): void {
  unset($_SESSION[CSRF_KEY], $_SESSION[CSRF_EXP]);
  csrf_token(); // reseed
}

function redirect_msg(string $p, string $m): void {
  $join = (strpos($p, '?') !== false) ? '&' : '?';
  header('Location: ' . $p . $join . 'msg=' . urlencode($m));
  exit;
}

/* =========================
   DB helpers
========================= */
function pg_tbl_exists(PDO $pdo, string $t): bool {
  $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
  $q->execute([$t]);
  return (int)$q->fetchColumn() > 0;
}
function pg_col_exists(PDO $pdo, string $t, string $c): bool {
  $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");
  $q->execute([$t,$c]);
  return (int)$q->fetchColumn() > 0;
}

/* =========================
   Constants
========================= */
if (!defined('XP_MODULE_SPOT')) define('XP_MODULE_SPOT', 0);

/* =========================
   Streaks table + bump
========================= */
function pg_ensure_streaks_table(PDO $pdo): void {
  try {
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS user_streaks (
        user_id        INT PRIMARY KEY,
        streak_current INT NOT NULL DEFAULT 0,
        streak_best    INT NOT NULL DEFAULT 0,
        last_day       DATE NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
  } catch (Throwable $e) {}
}

/** Bumps streak for “activity today” (UTC). */
function pg_bump_streak(PDO $pdo, int $userId): void {
  try { $pdo->exec("SET time_zone = '+00:00'"); } catch (Throwable $e) {}
  pg_ensure_streaks_table($pdo);
  try {
    $pdo->prepare("
      INSERT INTO user_streaks (user_id,streak_current,streak_best,last_day)
      VALUES (?,1,1,CURDATE())
      ON DUPLICATE KEY UPDATE
        streak_current = CASE
          WHEN last_day = CURDATE() - INTERVAL 1 DAY THEN streak_current + 1
          WHEN last_day = CURDATE()                      THEN streak_current
          ELSE 1
        END,
        streak_best = GREATEST(
          streak_best,
          CASE
            WHEN last_day = CURDATE() - INTERVAL 1 DAY THEN streak_current + 1
            WHEN last_day = CURDATE()                      THEN streak_current
            ELSE 1
          END
        ),
        last_day = CURDATE()
    ")->execute([$userId]);
  } catch (Throwable $e) {}
}

/* =========================
   Levels
========================= */
function pg_level_from_xp(int $xp): array {
  if ($xp >= 10000) return ['idx'=>6,'name'=>'Master'];
  if ($xp >=  5000) return ['idx'=>5,'name'=>'Diamond'];
  if ($xp >=  2500) return ['idx'=>4,'name'=>'Platinum'];
  if ($xp >=  1000) return ['idx'=>3,'name'=>'Gold'];
  if ($xp >=   250) return ['idx'=>2,'name'=>'Silver'];
  return ['idx'=>1,'name'=>'Bronze'];
}
function pg_ensure_user_level_columns(PDO $pdo): void {
  try {
    if (!pg_col_exists($pdo,'users','level'))            $pdo->exec("ALTER TABLE users ADD COLUMN level INT NOT NULL DEFAULT 1");
    if (!pg_col_exists($pdo,'users','level_name'))       $pdo->exec("ALTER TABLE users ADD COLUMN level_name VARCHAR(32) NOT NULL DEFAULT 'Bronze'");
    if (!pg_col_exists($pdo,'users','level_updated_at')) $pdo->exec("ALTER TABLE users ADD COLUMN level_updated_at DATETIME NULL");
  } catch (Throwable $e) {}
}
function pg_update_user_level(PDO $pdo, int $userId): array {
  pg_ensure_user_level_columns($pdo);
  $st=$pdo->prepare("SELECT COALESCE(SUM(points),0) FROM user_xp WHERE user_id=?");
  $st->execute([$userId]); $xp=(int)$st->fetchColumn();
  $L=pg_level_from_xp($xp);

  $cur=$pdo->prepare("SELECT level, level_name FROM users WHERE id=?");
  $cur->execute([$userId]);
  $row=$cur->fetch(PDO::FETCH_ASSOC) ?: ['level'=>null,'level_name'=>null];

  $changed=((int)$row['level']!==$L['idx']) || (($row['level_name']??null)!==$L['name']);
  if($changed){
    $upd=$pdo->prepare("UPDATE users SET level=?, level_name=?, level_updated_at=NOW() WHERE id=?");
    $upd->execute([$L['idx'],$L['name'],$userId]);
  }
  return ['xp'=>$xp,'level'=>$L['idx'],'name'=>$L['name'],'changed'=>$changed];
}

/* =========================
   XP shape detection + helpers
========================= */
function pg_user_xp_is_ledger(PDO $pdo): bool {
  try {
    if (!pg_tbl_exists($pdo,'user_xp')) return true;
    return pg_col_exists($pdo,'user_xp','id');
  } catch (Throwable $e) { return false; }
}
function pg_total_xp(PDO $pdo, int $userId): int {
  $q=$pdo->prepare("SELECT COALESCE(SUM(points),0) FROM user_xp WHERE user_id=?");
  $q->execute([$userId]); return (int)$q->fetchColumn();
}

/* Triggers: bump streaks on ANY XP gain */
function pg_ensure_xp_triggers(PDO $pdo): void {
  try {
    $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND trigger_name IN ('uxp_ai_streak','uxp_au_streak')");
    $check->execute(); $count=(int)$check->fetchColumn();
    if ($count >= 2) return;

    pg_ensure_streaks_table($pdo);

    $pdo->exec("
      CREATE TRIGGER uxp_ai_streak AFTER INSERT ON user_xp FOR EACH ROW
      INSERT INTO user_streaks (user_id,streak_current,streak_best,last_day)
      SELECT NEW.user_id, 1, 1, CURDATE()
      FROM DUAL
      WHERE (NEW.points > 0)
      ON DUPLICATE KEY UPDATE
        streak_current = CASE
          WHEN last_day = CURDATE() - INTERVAL 1 DAY THEN streak_current + 1
          WHEN last_day = CURDATE()                      THEN streak_current
          ELSE 1
        END,
        streak_best = GREATEST(
          streak_best,
          CASE
            WHEN last_day = CURDATE() - INTERVAL 1 DAY THEN streak_current + 1
            WHEN last_day = CURDATE()                      THEN streak_current
            ELSE 1
          END
        ),
        last_day = CURDATE()
    ");

    $pdo->exec("
      CREATE TRIGGER uxp_au_streak AFTER UPDATE ON user_xp FOR EACH ROW
      INSERT INTO user_streaks (user_id,streak_current,streak_best,last_day)
      SELECT NEW.user_id, 1, 1, CURDATE()
      FROM DUAL
      WHERE (NEW.points > IFNULL(OLD.points, -9223372036854775808))
      ON DUPLICATE KEY UPDATE
        streak_current = CASE
          WHEN last_day = CURDATE() - INTERVAL 1 DAY THEN streak_current + 1
          WHEN last_day = CURDATE()                      THEN streak_current
          ELSE 1
        END,
        streak_best = GREATEST(
          streak_best,
          CASE
            WHEN last_day = CURDATE() - INTERVAL 1 DAY THEN streak_current + 1
            WHEN last_day = CURDATE()                      THEN streak_current
            ELSE 1
          END
        ),
        last_day = CURDATE()
    ");
  } catch (Throwable $e) { /* lack of TRIGGER privilege is ok */ }
}

/**
 * Adds XP (ledger/bucket), bumps streak (via code), then syncs level.
 */
function pg_add_xp(PDO $pdo, int $userId, int $moduleId, int $earned): int {
  try { $pdo->exec("SET time_zone = '+00:00'"); } catch (Throwable $e) {}
  pg_ensure_xp_triggers($pdo);

  $earned = (int)$earned;
  if ($earned !== 0) {
    $isLedger = pg_user_xp_is_ledger($pdo);
    if ($isLedger) {
      $hasCreated = pg_col_exists($pdo,'user_xp','created_at');
      if ($hasCreated) {
        $pdo->prepare("INSERT INTO user_xp (user_id,module_id,points,created_at) VALUES (?,?,?,NOW())")
            ->execute([$userId,$moduleId,$earned]);
      } else {
        $pdo->prepare("INSERT INTO user_xp (user_id,module_id,points) VALUES (?,?,?)")
            ->execute([$userId,$moduleId,$earned]);
      }
      if ($earned > 0) pg_bump_streak($pdo,$userId);
    } else {
      $pdo->prepare("
        INSERT INTO user_xp (user_id,module_id,points)
        VALUES (:uid,:mid,:p)
        ON DUPLICATE KEY UPDATE points = points + VALUES(points)
      ")->execute([':uid'=>$userId,':mid'=>$moduleId,':p'=>$earned]);
      if ($earned > 0) pg_bump_streak($pdo,$userId);
    }
  }
  $total = pg_total_xp($pdo,$userId);
  pg_update_user_level($pdo,$userId);
  return $total;
}
function pg_add_xp_no_tx(PDO $pdo, int $userId, int $moduleId, int $earned): int {
  return pg_add_xp($pdo,$userId,$moduleId,$earned);
}

/* =========================
   Spot-the-Phish idempotency
========================= */
function pg_bootstrap_spot(PDO $pdo): void {
  try {
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS user_task_runs (
        id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id      INT NOT NULL,
        task_id      INT NOT NULL,
        module_id    INT NOT NULL DEFAULT 0,
        round_id     INT NOT NULL,
        outcome      ENUM('correct','incorrect','skipped') NOT NULL,
        points_delta INT NOT NULL,
        created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_user_task_round (user_id,task_id,round_id),
        KEY idx_user_round (user_id,round_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
  } catch (Throwable $e) {}
  pg_ensure_streaks_table($pdo);
  pg_ensure_xp_triggers($pdo);
}

/** daily round id (UTC) */
function pg_round_id(PDO $pdo): int {
  return max(20000101, (int)gmdate('Ymd'));
}

/** Apply Spot result once per (user,task,round). Allows negatives. */
function pg_apply_spot_result(PDO $pdo, int $userId, int $taskId, bool $isCorrect, int $deltaCorrect=6, int $deltaWrong=-2): array {
  pg_bootstrap_spot($pdo);
  try { $pdo->exec("SET time_zone = '+00:00'"); } catch (Throwable $e) {}

  $roundId = pg_round_id($pdo);
  $delta   = $isCorrect ? (int)$deltaCorrect : (int)$deltaWrong;
  if ($delta > 10)  $delta = 10;
  if ($delta < -10) $delta = -10;

  $pdo->beginTransaction();
  try {
    $ins = $pdo->prepare("
      INSERT INTO user_task_runs (user_id,task_id,module_id,round_id,outcome,points_delta)
      VALUES (?,?,?,?,?,?)
      ON DUPLICATE KEY UPDATE id=id
    ");
    $ins->execute([$userId,$taskId,XP_MODULE_SPOT,$roundId,$isCorrect?'correct':'incorrect',$delta]);

    $already = ($ins->rowCount() === 0);

    if (!$already) {
      pg_add_xp($pdo,$userId,XP_MODULE_SPOT,$delta);

      if (!$isCorrect || $delta <= 0) {
        $pdo->prepare("INSERT IGNORE INTO user_streaks (user_id,streak_current,streak_best,last_day) VALUES (?,0,0,CURDATE())")->execute([$userId]);
        $pdo->prepare("UPDATE user_streaks SET streak_current=0 WHERE user_id=?")->execute([$userId]);
      }
    }

    $totalXp = pg_total_xp($pdo,$userId);
    $pdo->commit();
    pg_update_user_level($pdo,$userId);

    return ['applied'=>!$already,'already'=>$already,'total_xp'=>$totalXp];
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}

/* =========================
   Admin helpers
========================= */
function is_admin(PDO $pdo): bool {
  if (empty($_SESSION['user_id'])) return false;
  $st=$pdo->prepare("SELECT role FROM users WHERE id=? LIMIT 1");
  $st->execute([(int)$_SESSION['user_id']]);
  return ($st->fetchColumn()==='admin');
}
function require_admin(PDO $pdo): void {
  if (!is_admin($pdo)) { http_response_code(403); echo 'Forbidden'; exit; }
}

<?php
// /admin/index.php
declare(strict_types=1);
require_once __DIR__ . '/boot.php';

admin_require_login();
$base = admin_base();

/* =================== DB HELPERS =================== */
function tbl_exists(PDO $pdo, string $table): bool {
  $q = $pdo->prepare("
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = ?
  ");
  $q->execute([$table]);
  return (int)$q->fetchColumn() > 0;
}
function col_exists(PDO $pdo, string $table, string $col): bool {
  $q = $pdo->prepare("
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
  ");
  $q->execute([$table, $col]);
  return (int)$q->fetchColumn() > 0;
}

/* Training modules bootstrap (needed by tm_* actions) */
function tm_bootstrap(PDO $pdo): void {
  $pdo->exec("CREATE TABLE IF NOT EXISTS training_modules (
     id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  if (!col_exists($pdo,'training_modules','title'))        $pdo->exec("ALTER TABLE training_modules ADD COLUMN title VARCHAR(200) NOT NULL DEFAULT ''");
  if (!col_exists($pdo,'training_modules','channel'))      $pdo->exec("ALTER TABLE training_modules ADD COLUMN channel ENUM('email','sms','web') NOT NULL DEFAULT 'email'");
  if (!col_exists($pdo,'training_modules','spot_task_id')) $pdo->exec("ALTER TABLE training_modules ADD COLUMN spot_task_id INT UNSIGNED NULL");
  if (!col_exists($pdo,'training_modules','description'))  $pdo->exec("ALTER TABLE training_modules ADD COLUMN description TEXT NULL");
  if (!col_exists($pdo,'training_modules','difficulty'))   $pdo->exec("ALTER TABLE training_modules ADD COLUMN difficulty TINYINT NOT NULL DEFAULT 1");
  if (!col_exists($pdo,'training_modules','is_active'))    $pdo->exec("ALTER TABLE training_modules ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1");
}

/* ---------------- Pairing helpers -----------------
   Work on the *paired* cohort: users with latest PRE and POST (total > 0). */
function get_paired_last_rows(PDO $pdo): array {
  if (!tbl_exists($pdo,'test_attempts')) return [];
  $sql = "
    SELECT u.id AS user_id,
           COALESCE(u.username, CONCAT('User #',u.id)) AS username,
           COALESCE(tp.accuracy_pct,(tp.score*100.0)/NULLIF(tp.total,0)) AS pre_pct,
           COALESCE(ts.accuracy_pct,(ts.score*100.0)/NULLIF(ts.total,0)) AS post_pct
    FROM (
      SELECT p.user_id, p.last_pre, q.last_post
      FROM (SELECT user_id, MAX(submitted_at) last_pre
              FROM test_attempts
             WHERE kind='pre'  AND total>0 AND submitted_at IS NOT NULL
             GROUP BY user_id) p
      JOIN (SELECT user_id, MAX(submitted_at) last_post
              FROM test_attempts
             WHERE kind='post' AND total>0 AND submitted_at IS NOT NULL
             GROUP BY user_id) q
        ON p.user_id=q.user_id
    ) j
    JOIN test_attempts tp ON tp.user_id=j.user_id AND tp.kind='pre'  AND tp.submitted_at=j.last_pre
    JOIN test_attempts ts ON ts.user_id=j.user_id AND ts.kind='post' AND ts.submitted_at=j.last_post
    JOIN users u ON u.id=j.user_id
    ORDER BY (COALESCE(ts.accuracy_pct,(ts.score*100.0)/NULLIF(ts.total,0))
            - COALESCE(tp.accuracy_pct,(tp.score*100.0)/NULLIF(tp.total,0))) DESC
  ";
  return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/* Pre/Post summary computed on the paired cohort (fair, not biased by different Ns) */
function prepost_summary_paired(PDO $pdo): array {
  $rows = get_paired_last_rows($pdo);
  $n = count($rows);
  if ($n === 0) {
    return ['pre_avg'=>0.0,'pre_n'=>0,'post_avg'=>0.0,'post_n'=>0,'delta_abs'=>0.0,'delta_rel'=>null,'paired_n'=>0];
  }
  $pre = 0.0; $post = 0.0;
  foreach ($rows as $r) { $pre += (float)$r['pre_pct']; $post += (float)$r['post_pct']; }
  $pre_avg  = round($pre / $n, 1);
  $post_avg = round($post / $n, 1);
  $delta    = round($post_avg - $pre_avg, 1);
  $rel      = ($pre_avg>0) ? round(($delta/$pre_avg)*100, 1) : null;
  return ['pre_avg'=>$pre_avg,'pre_n'=>$n,'post_avg'=>$post_avg,'post_n'=>$n,'delta_abs'=>$delta,'delta_rel'=>$rel,'paired_n'=>$n];
}

/* Paired rows for the small table */
function get_paired_rows(PDO $pdo): array {
  $paired = get_paired_last_rows($pdo);
  $out = [];
  foreach ($paired as $r) {
    $pre  = (float)$r['pre_pct'];
    $post = (float)$r['post_pct'];
    $out[] = [
      'user_id'   => (int)$r['user_id'],
      'username'  => (string)$r['username'],
      'pre_pct'   => $pre,
      'post_pct'  => $post,
      'delta_pct' => $post - $pre
    ];
  }
  return $out; // already ordered by delta desc
}

/* ------------- Student-t (no stats extension needed) ------------- */
function _gammaln(float $x): float {
  $cof=[76.18009172947146,-86.50532032941677,24.01409824083091,-1.231739572450155,0.001208650973866179,-5.395239384953E-6];
  $y=$x; $tmp=$x+5.5; $tmp-=($x+0.5)*log($tmp); $ser=1.000000000190015;
  for($j=0;$j<6;$j++){ $y+=1; $ser+=$cof[$j]/$y; }
  return -$tmp+log(2.5066282746310005*$ser/$x);
}
function _betacf(float $a,float $b,float $x): float {
  $MAXIT=200; $EPS=3.0e-7; $FPMIN=1.0e-30;
  $qab=$a+$b; $qap=$a+1.0; $qam=$a-1.0;
  $c=1.0; $d=1.0-($qab*$x)/$qap; if (abs($d)<$FPMIN) $d=$FPMIN; $d=1.0/$d; $h=$d;
  for($m=1,$m2=2;$m<=$MAXIT;$m++,$m2+=2){
    $aa=$m*($b-$m)*$x/(($qam+$m2)*($a+$m2));
    $d=1.0+$aa*$d; if(abs($d)<$FPMIN) $d=$FPMIN; $c=1.0+$aa/$c; if(abs($c)<$FPMIN) $c=$FPMIN; $d=1.0/$d; $h*=$d*$c;
    $aa=-( $a+$m )*( $qab+$m )*$x/( ( $a+$m2 )*( $qap+$m2 ) );
    $d=1.0+$aa*$d; if(abs($d)<$FPMIN) $d=$FPMIN; $c=1.0+$aa/$c; if(abs($c)<$FPMIN) $c=$FPMIN; $d=1.0/$d; $del=$d*$c; $h*=$del;
    if (abs($del-1.0)<$EPS) break;
  }
  return $h;
}
function _betai(float $a,float $b,float $x): float {
  if ($x<=0.0) return 0.0;
  if ($x>=1.0) return 1.0;
  $bt = exp( _gammaln($a+$b) - _gammaln($a) - _gammaln($b) + $a*log($x) + $b*log(1.0-$x) );
  if ($x < ($a+1.0)/($a+$b+2.0)) return $bt*_betacf($a,$b,$x)/$a;
  return 1.0 - $bt*_betacf($b,$a,1.0-$x)/$b;
}
function student_t_cdf(float $t, int $df): float {
  if ($df <= 0) return NAN;
  $x = $df / ($df + $t*$t);
  $ib = _betai($df/2.0, 0.5, $x);
  return ($t > 0) ? (1.0 - 0.5*$ib) : (0.5*$ib);
}
/* Paired t-test on the paired cohort */
function get_paired_ttest(PDO $pdo): array {
  $rows = get_paired_rows($pdo);
  $deltas = [];
  foreach ($rows as $r) if ($r['delta_pct'] !== null) $deltas[] = (float)$r['delta_pct'];
  $n = count($deltas);
  if ($n === 0) return ['n'=>0,'df'=>0,'mean_delta'=>null,'sd'=>null,'se'=>null,'t'=>null,'p_two'=>null];
  if ($n === 1) return ['n'=>1,'df'=>0,'mean_delta'=>round($deltas[0],3),'sd'=>null,'se'=>null,'t'=>null,'p_two'=>null];
  $mean = array_sum($deltas)/$n;
  $ss=0.0; foreach ($deltas as $x){ $d=$x-$mean; $ss+=$d*$d; }
  $sd = sqrt($ss/($n-1));
  $se = $sd>0 ? $sd/sqrt($n) : 0.0;
  $t  = ($se>0) ? ($mean/$se) : null;
  $df = $n-1;
  $p = null;
  if ($t !== null && is_finite($t)) {
    $cdf = student_t_cdf(abs($t), $df);
    $p   = 2.0 * (1.0 - $cdf);
    if ($p < 0) $p = 0; if ($p > 1) $p = 1;
  }
  return [
    'n'=>$n,'df'=>$df,
    'mean_delta'=>round($mean,3),
    'sd'=>round($sd,3),
    'se'=>round($se,3),
    't'=>($t===null?null:round($t,3)),
    'p_two'=>($p===null?null:round($p,4)),
  ];
}

/* =================== AJAX API =================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json; charset=UTF-8');
  $csrfRaw = $_POST['csrf'] ?? '';
  $isAjax  = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
  if (!admin_verify_csrf($csrfRaw) && !($isAjax && admin_logged_in())) { echo json_encode(['ok'=>false, 'message'=>'Bad CSRF']); exit; }
  try { $pdo->exec("SET time_zone = '+00:00'"); } catch (Throwable $e) {}
  $act = $_POST['action'] ?? '';

  try {
    switch ($act) {
      case 'stats': {
        $row = $pdo->query("
          SELECT
            (SELECT COUNT(*) FROM users) AS users_total,
            (SELECT COUNT(*) FROM users WHERE role='admin') AS admins_total,
            (SELECT COUNT(*) FROM spot_tasks) AS tasks_total,
            (SELECT COUNT(*) FROM user_spot_sessions WHERE DATE(started_at)=DATE(UTC_TIMESTAMP())) AS sessions_today
        ")->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['ok'=>true,'data'=>$row]); exit;
      }

      case 'prepost_summary': {
        // Fair apples-to-apples summary: ONLY users who have BOTH a pre and a post
        $s = prepost_summary_paired($pdo);
        echo json_encode(['ok'=>true,'data'=>$s]); exit;
      }

      case 'paired_rows':   echo json_encode(['ok'=>true,'rows'=>get_paired_rows($pdo)]); exit;
      case 'paired_ttest':  echo json_encode(['ok'=>true,'data'=>get_paired_ttest($pdo)]); exit;

      case 'age_insights': {
        if (!tbl_exists($pdo,'users')) { echo json_encode(['ok'=>true,'rows'=>[]]); exit; }
        $hasAge = col_exists($pdo,'users','age'); $hasDob = col_exists($pdo,'users','dob');
        if (!$hasAge && !$hasDob) { echo json_encode(['ok'=>true,'rows'=>[],'message'=>'No age/dob column']); exit; }
        $ageVal = $hasAge ? "NULLIF(NULLIF(u.age, -1), 0)" : "TIMESTAMPDIFF(YEAR, u.dob, CURDATE())";
        $bucket = "
          CASE
            WHEN {$ageVal} IS NULL THEN 'Unknown'
            WHEN {$ageVal} < 18 THEN '<18'
            WHEN {$ageVal} BETWEEN 18 AND 24 THEN '18–24'
            WHEN {$ageVal} BETWEEN 25 AND 34 THEN '25–34'
            WHEN {$ageVal} BETWEEN 35 AND 44 THEN '35–44'
            WHEN {$ageVal} BETWEEN 45 AND 54 THEN '45–54'
            WHEN {$ageVal} BETWEEN 55 AND 64 THEN '55–64'
            ELSE '65+'
          END";
        $hk_source = "
          SELECT user_id, 1 AS is_high FROM (
            SELECT user_id,
                   SUM(CASE WHEN submitted_at IS NOT NULL THEN 1 ELSE 0 END) a,
                   SUM(CASE WHEN is_correct=1 THEN 1 ELSE 0 END) c
            FROM user_spot_sessions
            WHERE started_at >= UTC_DATE() - INTERVAL 90 DAY
            GROUP BY user_id
          ) t WHERE a >= 3 AND (c*100/a) >= 80
        ";
        $falls_source = "
          SELECT user_id, 1 AS is_fall FROM (
            SELECT user_id,
                   SUM(CASE WHEN submitted_at IS NOT NULL THEN 1 ELSE 0 END) a,
                   SUM(CASE WHEN is_correct=1 THEN 1 ELSE 0 END) c
            FROM user_spot_sessions
            WHERE started_at >= UTC_DATE() - INTERVAL 90 DAY
            GROUP BY user_id
          ) t WHERE a >= 3 AND (c*100/a) < 60
        ";
        $sql = "
          SELECT bucket,
                 COUNT(*) AS total_users,
                 SUM(CASE WHEN hk.is_high=1 THEN 1 ELSE 0 END) AS high_knowledge,
                 SUM(CASE WHEN ff.is_fall=1 THEN 1 ELSE 0 END) AS falls
          FROM (SELECT u.id AS user_id, {$bucket} AS bucket FROM users u) a
          LEFT JOIN ({$hk_source}) hk ON hk.user_id = a.user_id
          LEFT JOIN ({$falls_source}) ff ON ff.user_id = a.user_id
          GROUP BY bucket
          ORDER BY FIELD(bucket,'<18','18–24','25–34','35–44','45–54','55–64','65+','Unknown')
        ";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        echo json_encode(['ok'=>true,'rows'=>$rows]); exit;
      }

      case 'chart_sessions_daily': {
        $rows=[];
        if (tbl_exists($pdo,'user_spot_sessions')) {
          $q=$pdo->query("
            SELECT DATE(started_at) d,
                   COUNT(*) AS started,
                   SUM(CASE WHEN submitted_at IS NOT NULL THEN 1 ELSE 0 END) AS submitted,
                   SUM(CASE WHEN is_correct=1 THEN 1 ELSE 0 END) AS corrects
            FROM user_spot_sessions
            WHERE started_at >= UTC_DATE() - INTERVAL 29 DAY
            GROUP BY DATE(started_at)
            ORDER BY DATE(started_at)
          "); $rows=$q->fetchAll();
        }
        echo json_encode(['ok'=>true,'rows'=>$rows]); exit;
      }

      case 'chart_accuracy_channel': {
        $rows=[];
        if (tbl_exists($pdo,'user_spot_sessions') && tbl_exists($pdo,'spot_tasks')) {
          $q=$pdo->query("
            SELECT t.channel,
                   SUM(CASE WHEN s.submitted_at IS NOT NULL THEN 1 ELSE 0 END) AS attempts,
                   SUM(CASE WHEN s.is_correct=1 THEN 1 ELSE 0 END) AS corrects
            FROM user_spot_sessions s
            JOIN spot_tasks t ON t.id=s.task_id
            WHERE s.started_at >= UTC_DATE() - INTERVAL 90 DAY
            GROUP BY t.channel
          "); $rows=$q->fetchAll();
        }
        echo json_encode(['ok'=>true,'rows'=>$rows]); exit;
      }

      case 'chart_top_users_xp': {
        $q=$pdo->query("
          SELECT u.id, u.username, COALESCE(SUM(x.points),0) AS xp
          FROM users u
          LEFT JOIN user_xp x ON x.user_id=u.id
          GROUP BY u.id
          ORDER BY xp DESC
          LIMIT 10
        "); echo json_encode(['ok'=>true,'rows'=>$q->fetchAll()]); exit;
      }

      case 'chart_training_engagement': {
        $rows=[];
        foreach (['training_mail_progress'=>'Mail','training_sms_progress'=>'SMS','training_web_progress'=>'Web'] as $tbl=>$label) {
          if (tbl_exists($pdo,$tbl)) {
            $c=(int)$pdo->query("SELECT COUNT(*) FROM {$tbl}")->fetchColumn();
            $rows[]=['channel'=>$label,'count'=>$c];
          }
        }
        echo json_encode(['ok'=>true,'rows'=>$rows]); exit;
      }

      case 'security_overview': {
        $resp=['score'=>0,'at_risk'=>0];
        if (tbl_exists($pdo,'user_spot_sessions')) {
          $row=$pdo->query("
            SELECT
              SUM(CASE WHEN submitted_at IS NOT NULL THEN 1 ELSE 0 END) AS attempts,
              SUM(CASE WHEN is_correct=1 THEN 1 ELSE 0 END) AS corrects
            FROM user_spot_sessions
            WHERE started_at >= UTC_DATE() - INTERVAL 30 DAY
          ")->fetch(PDO::FETCH_ASSOC);
          $att=max(0,(int)($row['attempts']??0));
          $cor=max(0,(int)($row['corrects']??0));
          $resp['score']=$att? round($cor*100/$att) : 0;

          $ar=$pdo->query("
            SELECT COUNT(*) FROM (
              SELECT user_id,
                     SUM(CASE WHEN submitted_at IS NOT NULL THEN 1 ELSE 0 END) a,
                     SUM(CASE WHEN is_correct=1 THEN 1 ELSE 0 END) c
              FROM user_spot_sessions
              WHERE started_at >= UTC_DATE() - INTERVAL 30 DAY
              GROUP BY user_id
              HAVING a >= 3 AND (c*100/a) < 60
            ) t
          ")->fetchColumn();
          $resp['at_risk']=(int)$ar;
        }
        echo json_encode(['ok'=>true,'data'=>$resp]); exit;
      }

      case 'recent_task_report': {
        $rows=[];
        if (tbl_exists($pdo,'user_spot_sessions') && tbl_exists($pdo,'spot_tasks')) {
          $q=$pdo->query("
            SELECT
              t.id,
              t.title,
              t.channel,
              COUNT(*) AS reports,
              ROUND(
                SUM(CASE WHEN s.is_correct=1 THEN 1 ELSE 0 END) * 100 /
                NULLIF(SUM(CASE WHEN s.submitted_at IS NOT NULL THEN 1 ELSE 0 END),0)
              ) AS detected_pct,
              DATE_FORMAT(MAX(s.started_at), '%Y-%m-%d %H:%i:%s') AS last_dt
            FROM user_spot_sessions s
            JOIN spot_tasks t ON t.id = s.task_id
            WHERE s.started_at >= UTC_DATE() - INTERVAL 180 DAY
            GROUP BY t.id, t.title, t.channel
            ORDER BY MAX(s.started_at) DESC
            LIMIT 5
          ");
          $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        }
        echo json_encode(['ok'=>true,'rows'=>$rows]); exit;
      }

      case 'users_overview': {
        $rows=[];
        if (tbl_exists($pdo,'users')) {
          $sql="
            SELECT u.id, u.username, u.email, u.role,
                   COALESCE(ua.attempts,0) attempts,
                   COALESCE(ua.corrects,0) corrects,
                   ua.last_dt
            FROM users u
            LEFT JOIN (
              SELECT s.user_id,
                     SUM(CASE WHEN s.submitted_at IS NOT NULL THEN 1 ELSE 0 END) attempts,
                     SUM(CASE WHEN s.is_correct=1 THEN 1 ELSE 0 END) corrects,
                     MAX(COALESCE(s.submitted_at,s.started_at)) last_dt
              FROM user_spot_sessions s
              WHERE s.started_at >= UTC_DATE() - INTERVAL 30 DAY
              GROUP BY s.user_id
            ) ua ON ua.user_id=u.id
            ORDER BY u.id ASC
            LIMIT 200
          ";
          $rows=$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        }
        echo json_encode(['ok'=>true,'rows'=>$rows]); exit;
      }

      case 'tasks_by_channel': {
        $chan = $_POST['channel'] ?? '';
        if (!in_array($chan, ['email','sms','web'], true)) { echo json_encode(['ok'=>false,'message'=>'Bad channel']); exit; }
        if (!tbl_exists($pdo,'spot_tasks')) { echo json_encode(['ok'=>true,'rows'=>[]]); exit; }
        $st=$pdo->prepare("SELECT id, title FROM spot_tasks WHERE channel=? ORDER BY id DESC LIMIT 500");
        $st->execute([$chan]);
        echo json_encode(['ok'=>true,'rows'=>$st->fetchAll(PDO::FETCH_ASSOC)]); exit;
      }

      case 'task_quick_create': {
        if (!tbl_exists($pdo,'spot_tasks')) { echo json_encode(['ok'=>false,'message'=>'spot_tasks missing']); exit; }
        $chan = $_POST['channel'] ?? 'email';
        if (!in_array($chan, ['email','sms','web'], true)) { echo json_encode(['ok'=>false,'message'=>'Bad channel']); exit; }
        $title = trim((string)($_POST['title'] ?? 'Campaign task'));
        $from  = trim((string)($_POST['from_line'] ?? ''));
        $meta  = trim((string)($_POST['meta_line'] ?? ''));
        $body  = (string)($_POST['body_html'] ?? '');
        $ttl   = max(10, (int)($_POST['time_limit_sec'] ?? 180));
        $st=$pdo->prepare("INSERT INTO spot_tasks (channel,title,from_line,meta_line,body_html,time_limit_sec) VALUES (?,?,?,?,?,?)");
        $st->execute([$chan,$title,$from,$meta,$body,$ttl]);
        echo json_encode(['ok'=>true,'task_id'=>(int)$pdo->lastInsertId()]); exit;
      }

      case 'tm_list': {
        tm_bootstrap($pdo);
        $rows=$pdo->query("
          SELECT id,title,channel,spot_task_id,COALESCE(description,'') AS description,difficulty,is_active
          FROM training_modules
          ORDER BY id DESC LIMIT 1000
        ")->fetchAll();
        echo json_encode(['ok'=>true,'rows'=>$rows]); exit;
      }
      case 'tm_save': {
        tm_bootstrap($pdo);
        $id    =(int)($_POST['id']??0);
        $title =trim((string)($_POST['title']??''));
        $chan  =in_array($_POST['channel']??'email',['email','sms','web'],true)?$_POST['channel']:'email';
        $desc  =(string)($_POST['description']??'');
        $diff  =max(1,min(5,(int)($_POST['difficulty']??1)));
        $active=(int)($_POST['is_active']??1);
        $spot  =(int)($_POST['spot_task_id']??0);
        $spotParam = $spot>0 ? $spot : null;

        if ($id>0){
          $st=$pdo->prepare("UPDATE training_modules
                                SET title=?, channel=?, description=?, difficulty=?, is_active=?, spot_task_id=?
                              WHERE id=?");
          $st->execute([$title,$chan,$desc,$diff,$active,$spotParam,$id]);
        } else {
          $st=$pdo->prepare("INSERT INTO training_modules (title,channel,description,difficulty,is_active,spot_task_id) VALUES (?,?,?,?,?,?)");
          $st->execute([$title,$chan,$desc,$diff,$active,$spotParam]);
          $id=(int)$pdo->lastInsertId();
        }
        echo json_encode(['ok'=>true,'id'=>$id]); exit;
      }
      case 'tm_delete': {
        tm_bootstrap($pdo);
        $id=(int)$_POST['id'];
        $pdo->prepare("DELETE FROM training_modules WHERE id=?")->execute([$id]);
        echo json_encode(['ok'=>true]); exit;
      }

      case 'export_users_csv': {
        header_remove('Content-Type');
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="users_xp.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['id', 'username', 'email', 'role', 'created_at']);
        foreach ($pdo->query("
            SELECT id, username, email, role,
                   DATE_FORMAT(created_at,'%Y-%m-%d %H:%i') AS created_at
            FROM users
            ORDER BY id
        ") as $r) { fputcsv($out, $r); }
        fclose($out);
        exit;
      }

      default: echo json_encode(['ok'=>false,'message'=>'Unknown action']); exit;
    }
  } catch (Throwable $e) {
    echo json_encode(['ok'=>false,'message'=>'Admin action failed','debug'=>$e->getMessage()]);
  }
  exit;
}

/* =================== PAGE =================== */
$csrf = admin_csrf_token();
$adminName = htmlspecialchars($_SESSION['admin']['username'] ?? 'admin');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>PhishGuard · Admin</title>
<link rel="icon" type="image/svg+xml"
        href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='12' fill='%236d28d9'/%3E%3Cpath d='M18 38l14-20 14 20-14 8z' fill='white'/%3E%3C/svg%3E">
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root{
    --bg:#f6f7fb; --text:#111827; --muted:#6b7280;
    --card:#ffffff; --line:#e5e7eb; --brand:#3b82f6; --brand-2:#0ea5e9;
    --good:#10b981; --warn:#f59e0b; --bad:#ef4444; --chip:#f1f5f9;
  }
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--text);font:14px/1.45 system-ui,Segoe UI,Roboto,Arial}
  a{color:inherit}
  .app{display:grid;grid-template-columns:240px 1fr;min-height:100vh}
  .side{background:#0f172a;color:#e2e8f0;display:flex;flex-direction:column}
  .brand{display:flex;align-items:center;gap:12px;padding:16px 18px;border-bottom:1px solid rgba(255,255,255,.08);font-weight:600}
  .brand .logo{width:28px;height:28px;border-radius:8px;background:linear-gradient(135deg,#2563eb,#06b6d4)}
  .nav{padding:10px}
  .nav a{display:block;padding:10px 12px;border-radius:8px;color:#e2e8f0;text-decoration:none;margin:4px 0}
  .nav a.active, .nav a:hover{background:rgba(255,255,255,.08)}
  header{display:flex;align-items:center;gap:12px;padding:16px 20px;border-bottom:1px solid var(--line);background:var(--card)}
  header .spacer{flex:1}
  .chip{background:var(--chip);border:1px solid var(--line);padding:6px 10px;border-radius:999px}
  main{padding:20px;display:grid;gap:16px}
  .cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px}
  .card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:14px}
  .k{display:flex;align-items:center;gap:12px}
  .k .icon{width:36px;height:36px;border-radius:10px;display:grid;place-items:center;color:#fff}
  .k .v{font-size: clamp(18px, 1.8vw, 22px); font-weight:700;}
  .i-green{background:linear-gradient(135deg,#10b981,#34d399)}
  .i-blue{background:linear-gradient(135deg,#3b82f6,#60a5fa)}
  .i-red{background:linear-gradient(135deg,#ef4444,#f97316)}
  .i-violet{background:linear-gradient(135deg,#8b5cf6,#06b6d4)}
  .grid2{display:grid;grid-template-columns:2fr 1fr;gap:16px}
  .grid2b{display:grid;grid-template-columns:1fr 1fr;gap:16px}
  .section-title{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}
  .muted{color:var(--muted)}
  .table{width:100%;border-collapse:collapse}
  .table th,.table td{padding:10px;border-bottom:1px solid var(--line);text-align:left}
  .pill{padding:2px 8px;border-radius:999px;font-size:12px;border:1px solid var(--line)}
  .pill.secure{background:#ecfdf5;color:#065f46;border-color:#a7f3d0}
  .pill.medium{background:#fffbeb;color:#92400e;border-color:#fde68a}
  .pill.high{background:#fef2f2;color:#991b1b;border-color:#fecaca}
  .prog{height:6px;border-radius:999px;background:#e5e7eb;overflow:hidden}
  .prog > span{display:block;height:100%}
  .btn{padding:8px 12px;border:1px solid var(--line);border-radius:8px;background:#fff;cursor:pointer}
  .btn.primary{background:var(--brand);border-color:var(--brand);color:#fff}

  .pp-wrap{display:grid;grid-template-columns:1fr 1fr;gap:16px}
  .pp-stat{display:flex;flex-direction:column;gap:6px}
  .pp-big{font-size: clamp(18px, 1.8vw, 22px); font-weight: 700; line-height: 1.1;}
  .pp-bar{height:8px;border-radius:999px;background:#eef2f7;overflow:hidden}
  .pp-bar > span{display:block;height:100%}
  .pp-bad{color:#991b1b}
  .pp-good{color:#065f46}

  /* Age Insights sizing */
  .age-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
  @media (max-width:1100px){ .age-grid{grid-template-columns:1fr} }
  canvas.age-can{width:100% !important;height:220px !important;display:block}
  #chAgeStack.age-can{height:260px !important}

  .spacer{flex:1}

  /* Users table controls + pagination */
  .tbl-controls{display:flex;align-items:center;gap:10px;margin:8px 0}
  .tbl-controls .select{padding:6px 10px;border:1px solid var(--line);border-radius:8px;background:#fff;}
  .pager{display:flex;align-items:center;gap:6px;justify-content:flex-end;margin-top:10px}
  .pager button{padding:6px 10px;border:1px solid var(--line);background:#fff;border-radius:8px;cursor:pointer}
  .pager button[disabled]{opacity:.5;cursor:not-allowed}
  .pager .num.active{background:var(--brand);border-color:var(--brand);color:#fff}
  .pager .ellipsis{padding:0 4px;color:var(--muted)}

  /* Recent Reports list */
  .reports-list .row{
    display:grid;
    grid-template-columns:1fr auto auto;
    gap:8px;
    padding:10px 0;
    border-bottom:1px solid var(--line);
    align-items:center;
  }
  .reports-list .meta{ color:var(--muted); font-size:13px; }
  .reports-list .when{ color:var(--muted); text-align:right; white-space:nowrap; }
  .reports-list .rr-chip{
    display:inline-block;
    align-self:center;
    justify-self:center;
    padding:6px 10px;
    border:1px solid var(--line);
    border-radius:999px;
    font-size:12px;
    white-space:nowrap;
  }
  .reports-list .rr-chip.secure{ background:#ecfdf5; color:#065f46; border-color:#a7f3d0; }
  .reports-list .rr-chip.medium{ background:#fffbeb; color:#92400e; border-color:#fde68a; }
  .reports-list .rr-chip.high{ background:#fef2f2; color:#991b1b; border-color:#fecaca; }
  .reports-list strong{ display:block; }

  body, html {
  margin: 0;
  padding: 0;
  overflow-x: hidden; /* 🚫 no sideways scroll */
}
main {
  width: 100%;
  max-width: 100%;
  overflow-x: hidden;
}

</style>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body data-base="<?= htmlspecialchars($base) ?>">
<div class="app">
  <aside class="side">
    <div class="brand"><div class="logo"></div> PhishGuard</div>
    <nav class="nav">
      <a href="#" class="active" data-panel="dash">Dashboard</a>
      <a href="user.php" data-panel="users">Users</a>
      <a href="campaigns.php" data-panel="campaigns">Modules</a>
      <a href="reports.php" data-panel="reports">Reports</a>
      <a href="<?= htmlspecialchars($base) ?>/paired.php">Paired Evaluation</a>
    </nav>
  </aside>

  <section>
    <header>
      <h2 style="margin:0">Dashboard Overview</h2>
      <div class="spacer"></div>
      <div class="chip">Signed in as <?= $adminName ?></div>
      <a class="btn" href="<?= htmlspecialchars($base) ?>/logout.php">Logout</a>
    </header>

    <main>
      <!-- KPI cards -->
      <div class="cards">
        <div class="card">
          <div class="k"><div class="icon i-blue">👥</div>
            <div><div class="muted">Total Users</div><div class="v" id="kUsers">—</div></div>
          </div>
        </div>
        <div class="card">
          <div class="k"><div class="icon i-green">🛡️</div>
            <div><div class="muted">Security Score</div><div class="v"><span id="kSec">—</span><span class="muted">%</span></div></div>
          </div>
        </div>
        <div class="card">
          <div class="k"><div class="icon i-red">⚠️</div>
            <div><div class="muted">At-Risk Users</div><div class="v" id="kRisk">—</div></div>
          </div>
        </div>
        <div class="card">
          <div class="k"><div class="icon i-violet">🎯</div>
            <div><div class="muted">Active Campaigns</div><div class="v" id="kCamps">—</div></div>
          </div>
        </div>
      </div>

      <!-- Learning Impact -->
      <div class="card" id="ppCard">
        <div class="section-title">
          <strong>Learning Impact — Pre vs Post</strong>
          <span class="muted" id="ppSample">—</span>
        </div>
        <div class="pp-wrap">
          <div class="pp-stat">
            <div class="muted">Average Pre-Test</div>
            <div class="pp-big" id="ppPre">—%</div>
            <div class="pp-bar"><span id="ppPreBar" style="width:0%;background:#f59e0b"></span></div>
          </div>
          <div class="pp-stat">
            <div class="muted">Average Post-Test</div>
            <div class="pp-big" id="ppPost">—%</div>
            <div class="pp-bar"><span id="ppPostBar" style="width:0%;background:#10b981"></span></div>
          </div>
        </div>
        <div style="margin-top:10px;display:flex;align-items:center;gap:10px;">
          <div class="muted">Improvement</div>
          <div id="ppDelta" class="pp-big">—</div>
          <div id="ppDeltaRel" class="muted"></div>
        </div>
      </div>

      <!-- Paired Evaluation (t-test) -->
      <div class="card" id="pairedCard">
        <div class="section-title" style="gap:10px">
          <strong>Paired Evaluation (t-test)</strong>
          <span class="muted" id="pairedN">—</span>
          <div class="spacer"></div>
          <a class="btn" href="paired.php">View all</a>
        </div>
        <div class="muted" id="pairedSummary">Loading…</div>
        <div style="margin-top:10px; overflow:auto">
          <table class="table" id="tblPaired">
            <thead>
              <tr>
                <th style="width:160px">User</th>
                <th>Pre %</th>
                <th>Post %</th>
                <th>Δ (pts)</th>
              </tr>
            </thead>
            <tbody>
              <tr><td colspan="4" class="muted">Loading…</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Age Insights -->
      <div class="card">
        <div class="section-title">
          <strong>Age Insights</strong>
          <div>
            <button class="chip" id="ageModePct">Percent</button>
            <button class="chip" id="ageModeCnt">Count</button>
          </div>
        </div>
        <div class="muted" id="ageTotals">—</div>

        <div class="age-grid">
          <div>
            <div class="muted">High Knowledge</div>
            <canvas id="chAgeHigh" class="age-can"></canvas>
          </div>
          <div>
            <div class="muted">At-Risk</div>
            <canvas id="chAgeFalls" class="age-can"></canvas>
          </div>
        </div>

        <div style="margin-top:10px">
          <div class="muted">Distribution by Age Group</div>
          <canvas id="chAgeStack" class="age-can"></canvas>
        </div>
      </div>

      <!-- Progress + recent reports -->
      <div class="grid2">
        <div class="card">
          <div class="section-title">
            <strong>Security Awareness Progress</strong>
            <span class="muted">Last 30 days</span>
          </div>
          <canvas id="chDaily"></canvas>
        </div>

        <div class="card">
          <div class="section-title"><strong>Recent Phishing Reports</strong>
            <a class="muted" href="#" id="viewReports">View All Reports</a>
          </div>
          <div id="recentReports" class="muted">No data</div>
        </div>
      </div>

      <!-- Users overview -->
      <div class="card">
        <div class="section-title">
          <strong>Users Overview</strong>
          <form method="post" action="" id="exportForm" class="right" style="display:inline">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="export_users_csv">
            <button class="btn">Export CSV</button>
          </form>
        </div>

        <!-- table controls -->
        <div class="tbl-controls">
          <span class="muted">Rows per page</span>
          <select id="usersPageSize" class="select">
            <option value="6" selected>6</option>
            <option value="10">10</option>
            <option value="20">20</option>
            <option value="50">50</option>
            <option value="100">100</option>
          </select>
          <div class="spacer"></div>
          <span class="muted" id="usersShowing">—</span>
        </div>

        <table class="table" id="tblUsers">
          <thead><tr>
            <th style="width:80px">ID</th><th>Name</th><th>Email</th>
            <th>Security Score</th><th>Last Training</th><th>Status</th>
          </tr></thead>
          <tbody></tbody>
        </table>

        <div class="pager" id="usersPager"></div>
      </div>

      <!-- Hidden form for AJAX -->
      <form id="hidden" style="display:none">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
      </form>
    </main>
  </section>
</div>

<script>
(function(){
  const $ = s => document.querySelector(s);

  // small helper to avoid || defaults in template strings
  function nz(v, fallback){ return (v === null || v === undefined) ? fallback : v; }

  function getCtx(id){
    const el = document.getElementById(id);
    return (el && typeof el.getContext === 'function') ? el.getContext('2d') : null;
  }

  async function api(action,payload={}){
    const fd=new FormData(document.getElementById('hidden'));
    fd.set('action',action);
    for (const [k,v] of Object.entries(payload)) fd.append(k,v);
    try{
      const res = await fetch(window.location.href, {
        method:'POST', body:fd, credentials:'same-origin',
        headers:{'X-Requested-With':'XMLHttpRequest'}
      });
      const text = await res.text();
      try { return JSON.parse(text); }
      catch(e){ console.error('API JSON parse failed for', action, 'response:', text); return {ok:false}; }
    }catch(err){ console.error('API request failed for', action, err); return {ok:false}; }
  }

  /* ---- KPIs ---- */
  async function loadKPIs(){
    const s=await api('stats'); if(s.ok && s.data){ $('#kUsers').textContent=s.data.users_total; }
    const so=await api('security_overview'); if(so.ok && so.data){ $('#kSec').textContent=so.data.score; $('#kRisk').textContent=so.data.at_risk; }
    const tm=await api('tm_list'); if(tm.ok){ const active=(tm.rows||[]).filter(r=>+r.is_active===1); const n=active.length; const el=$('#kCamps'); if(el) el.textContent=n; }
  }

  /* ---- Learning Impact (paired) ---- */
  async function loadPrePost(){
    const elPre  = $('#ppPre'),  barPre  = $('#ppPreBar');
    const elPost = $('#ppPost'), barPost = $('#ppPostBar');
    const boxSample = $('#ppSample');
    const d = await api('prepost_summary');
    if (!d.ok || !d.data){ if(boxSample) boxSample.textContent='No data'; return; }

    const preVal = Number(d.data.pre_avg);
    const postVal = Number(d.data.post_avg);
    const pre  = Math.max(0, Math.min(100, Number.isFinite(preVal)  ? preVal  : 0));
    const post = Math.max(0, Math.min(100, Number.isFinite(postVal) ? postVal : 0));

    if (elPre)  elPre.textContent  = pre.toFixed(1) + '%';
    if (elPost) elPost.textContent = post.toFixed(1) + '%';
    if (barPre)  barPre.style.width  = pre + '%';
    if (barPost) barPost.style.width = post + '%';

    if (boxSample) boxSample.textContent = 'Sample: Pre n=' + nz(d.data.pre_n,0) + ', Post n=' + nz(d.data.post_n,0);

    const deltaAbs = (d.data.delta_abs != null ? d.data.delta_abs : (post - pre));
    const deltaRel = d.data.delta_rel;
    const elDelta  = $('#ppDelta');
    const elDeltaR = $('#ppDeltaRel');
    const good = deltaAbs >= 0;

    if (elDelta){
      elDelta.textContent = (good?'+':'') + Number(deltaAbs).toFixed(1) + ' pts';
      elDelta.className = 'pp-big ' + (good ? 'pp-good' : 'pp-bad');
    }
    if (elDeltaR){
      if (deltaRel == null) elDeltaR.textContent = '';
      else {
        const dr = (typeof deltaRel === 'number') ? deltaRel : 0;
        elDeltaR.textContent = '(' + (good?'+':'') + dr.toFixed(1) + '%)';
      }
    }
  }

  /* ---- Paired t-test ---- */
  async function loadPaired(){
    const t = await api('paired_ttest');
    const box = document.getElementById('pairedSummary'), nbox = document.getElementById('pairedN');
    if (!t.ok || !t.data){
      if(box) box.textContent='No data';
      if(nbox) nbox.textContent='—';
    } else {
      const d=t.data;
      if(nbox) nbox.textContent = 'Paired users n=' + (Number.isFinite(+d.n) ? +d.n : 0);
      if (box){
        if ((d.n||0) < 2) box.textContent = 'Not enough paired users (n='+(d.n||0)+') to run a t-test.';
        else box.textContent = 'mean Δ='+d.mean_delta+' pts · SD='+d.sd+' · SE='+d.se+' · t='+(d.t==null?'—':d.t)+' · df='+d.df+' · '+(d.p_two==null?'p=—':'p='+d.p_two);
      }
    }

    const r = await api('paired_rows');
    const rows = (r.ok && Array.isArray(r.rows)) ? r.rows : [];
    const top6 = rows.slice(0, 6);

    function renderRows(tbody, list){
      tbody.innerHTML = '';
      if (!list.length){
        tbody.innerHTML = '<tr><td colspan="4" class="muted">No paired users yet.</td></tr>';
        return;
      }
      list.forEach((row, i)=>{
        const pre   = row.pre_pct  != null ? (+row.pre_pct).toFixed(2)  : '—';
        const post  = row.post_pct != null ? (+row.post_pct).toFixed(2) : '—';
        const delta = row.delta_pct!= null ? (+row.delta_pct).toFixed(2) : '—';
        const up = row.delta_pct != null && +row.delta_pct >= 0;
        const color = up ? '#065f46' : '#991b1b';
        const uname = 'Participant ' + String(i+1).padStart(2,'0');
        const tr = document.createElement('tr');
        tr.innerHTML =
          '<td>'+ uname +'</td>'+
          '<td>'+ pre  +'%</td>'+
          '<td>'+ post +'%</td>'+
          '<td style="font-weight:700;color:'+color+'">'+ (up?'+':'') + delta +' pts</td>';
        tbody.appendChild(tr);
      });
    }

    const tbCard = document.querySelector('#tblPaired tbody');
    if (tbCard) renderRows(tbCard, top6);
  }

  /* ---- Recent Reports ---- */
  async function loadReports(){
    const box  = document.getElementById('recentReports');
    const link = document.getElementById('viewReports');
    if (link) link.href = 'reports.php';
    if (box) box.classList.add('reports-list');

    const r = await api('recent_task_report');
    const rows = (r.ok && Array.isArray(r.rows)) ? r.rows : [];

    if (!rows.length){
      if (box) box.textContent = 'No reports in the selected period.';
      return;
    }

    function toUpper(s){ return (s===null || s===undefined ? '' : String(s)).toUpperCase(); }
    function timeago(dtStr){
      if (!dtStr) return '';
      const d = new Date(dtStr.replace(' ','T')+'Z');
      const diff = (Date.now() - d.getTime())/1000;
      const days = Math.floor(diff/86400); if (days >= 1) return days + 'd ago';
      const hrs  = Math.floor(diff/3600);  if (hrs  >= 1) return hrs  + 'h ago';
      const mins = Math.floor(diff/60);    return mins + 'm ago';
    }

    const frag = document.createElement('div');
    rows.forEach(row=>{
      const det = (row.detected_pct != null) ? +row.detected_pct : null;
      const cls = det==null ? 'medium' : (det>=80 ? 'secure' : (det<50 ? 'high' : 'medium'));
      const title = (row.title && row.title.length) ? row.title : ('Task #'+row.id);
      const reportsCount = (row.reports === null || row.reports === undefined) ? 0 : row.reports;

      const el = document.createElement('div');
      el.className = 'row';
      el.innerHTML = `
        <div>
          <strong>${title}</strong>
          <div class="meta">${toUpper(row.channel)} · ${reportsCount} reports</div>
        </div>
        <div class="rr-chip ${cls}">${det==null?'—':det+'%'} detected</div>
        <div class="when">${timeago(row.last_dt)}</div>
      `;
      // Make the row clickable to open the report details page
      el.addEventListener('click', function(){
        try { window.location.href = 'reports.php?open='+ encodeURIComponent(row.id); } catch(e){}
      });
      frag.appendChild(el);
    });

    if (box){ box.innerHTML = ''; box.appendChild(frag); }
  }

  /* ---- Age Insights ---- */
  let chAgeHigh=null, chAgeFalls=null, chAgeStack=null;
  let AGE_MODE='pct';
  async function loadAgeInsights(){
    const r = await api('age_insights');
    if (!r.ok || !Array.isArray(r.rows)) return;
    const order=['<18','18–24','25–34','35–44','45–54','55–64','65+','Unknown'];
    const map={}; order.forEach(b=>map[b]={bucket:b,total_users:0,high_knowledge:0,falls:0});
    r.rows.forEach(x=>{
      const k = (x.bucket && map[x.bucket]) ? x.bucket : 'Unknown';
      map[k]={bucket:k,total_users:+x.total_users||0,high_knowledge:+x.high_knowledge||0,falls:+x.falls||0};
    });
    const rows=order.map(k=>map[k]);
    const labels=rows.map(x=>x.bucket), totals=rows.map(x=>x.total_users),
          highs=rows.map(x=>x.high_knowledge), falls=rows.map(x=>x.falls);
    const sum=a=>a.reduce((s,v)=>s+(+v||0),0);
    const pct=(n,d)=>d>0?Math.round((n*100)/d):0;
    const highPct=highs.map((v,i)=>pct(v,totals[i])), fallPct=falls.map((v,i)=>pct(v,totals[i]));
    const totalsBox=document.getElementById('ageTotals'); if(totalsBox) totalsBox.textContent = sum(totals)+' users · High knowledge: '+sum(highs)+' · At-risk: '+sum(falls);

    const chipPct=document.getElementById('ageModePct'), chipCnt=document.getElementById('ageModeCnt');
    if (chipPct) chipPct.onclick=function(){AGE_MODE='pct'; draw();};
    if (chipCnt) chipCnt.onclick=function(){AGE_MODE='cnt'; draw();};

    function draw(){
      const donutHigh = AGE_MODE==='pct'?highPct:highs;
      const donutFall = AGE_MODE==='pct'?fallPct:falls;
      const barHigh   = AGE_MODE==='pct'?highPct:highs;
      const barFall   = AGE_MODE==='pct'?fallPct:falls;

      if (chAgeHigh) chAgeHigh.destroy();
      if (chAgeFalls) chAgeFalls.destroy();
      if (chAgeStack) chAgeStack.destroy();

      const ctx1=getCtx('chAgeHigh'), ctx2=getCtx('chAgeFalls'), ctx3=getCtx('chAgeStack');
      if (!ctx1 || !ctx2 || !ctx3) return;
      ctx1.canvas.height = 220; ctx2.canvas.height = 220; ctx3.canvas.height = 260;

      chAgeHigh = new Chart(ctx1,{
        type:'doughnut',
        data:{labels:labels,datasets:[{data:donutHigh}]},
        options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'}}}
      });
      chAgeFalls = new Chart(ctx2,{
        type:'doughnut',
        data:{labels:labels,datasets:[{data:donutFall}]},
        options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'}}}
      });
      chAgeStack = new Chart(ctx3,{
        type:'bar',
        data:{labels:labels,datasets:[
          {label:'High knowledge',data:barHigh,backgroundColor:'#10b981',stack:'x'},
          {label:'At-risk',data:barFall,backgroundColor:'#ef4444',stack:'x'}
        ]},
        options:{
          responsive:true,maintainAspectRatio:false,
          scales:{
            x:{stacked:true},
            y:{stacked:true,beginAtZero:true,max:(AGE_MODE==='pct'?100:undefined),
               ticks:{callback:function(v){return AGE_MODE==='pct'?v+'%':v;}}}
          },
          plugins:{legend:{position:'bottom'}}
        }
      });
    }
    draw();
  }

  /* ---- Daily chart ---- */
  let chDaily=null;
  async function loadDaily(){
    const d=await api('chart_sessions_daily'); const rows=Array.isArray(d.rows)?d.rows:[];
    const labels=rows.map(r=>r.d), started=rows.map(r=>+r.started||0), correct=rows.map(r=>+r.corrects||0);
    if (chDaily) chDaily.destroy();
    const ctx=getCtx('chDaily'); if(!ctx) return;
    chDaily=new Chart(ctx,{type:'line',data:{labels:labels,datasets:[
      {label:'Started',data:started,borderColor:'#3b82f6',backgroundColor:'rgba(59,130,246,.2)',tension:.3},
      {label:'Correct',data:correct,borderColor:'#10b981',backgroundColor:'rgba(16,185,129,.2)',tension:.3}
    ]},options:{responsive:true,plugins:{legend:{position:'bottom'}}}});
  }

  /* ---- Users overview (with pagination) ---- */
  function riskPill(pct){
    var cls='medium', label='Medium Risk';
    if (pct>=80){ cls='secure'; label='Secure'; }
    else if (pct<50){ cls='high'; label='High Risk'; }
    return '<span class="pill '+cls+'">'+label+'</span>';
  }
  function lastAgo(dt){
    if(!dt) return '—';
    var d=new Date(dt.replace(' ','T')+'Z'); var diff=(Date.now()-d.getTime())/1000;
    var days=Math.floor(diff/86400); if(days>=1) return days+' day'+(days>1?'s':'')+' ago';
    var hrs=Math.floor(diff/3600); if(hrs>=1) return hrs+'h ago';
    var mins=Math.floor(diff/60); return mins+'m ago';
  }

  let USERS_ROWS=[], USERS_PAGE=1, USERS_SIZE=6;

  function renderUsersTable(slice){
    const tb=document.querySelector('#tblUsers tbody'); if(!tb) return;
    tb.innerHTML='';
    if(!slice.length){ tb.innerHTML='<tr><td colspan="6">No data</td></tr>'; return; }
    slice.forEach(function(u){
      const aNum = Number(u.attempts); const attempts = Number.isFinite(aNum) ? aNum : 0;
      const cNum = Number(u.corrects); const corrects = Number.isFinite(cNum) ? cNum : 0;
      const pct=attempts?Math.round(corrects*100/attempts):0;
      const tr=document.createElement('tr');
      tr.innerHTML =
        '<td>'+u.id+'</td>'+
        '<td>'+(u.username?u.username:'')+'</td>'+
        '<td>'+(u.email?u.email:'')+'</td>'+
        '<td><div class="prog"><span style="width:'+pct+'%;background:'+(pct>=80?'#10b981':(pct<50?'#ef4444':'#f59e0b'))+'"></span></div>'+
        '<div class="muted">'+pct+'% ('+corrects+'/'+attempts+')</div></td>'+
        '<td>'+lastAgo(u.last_dt)+'</td>'+
        '<td>'+riskPill(pct)+'</td>';
      tb.appendChild(tr);
    });
  }

  function makePageButton(label, page, disabled=false, active=false){
    const btn=document.createElement('button');
    btn.textContent=label;
    btn.className='num' + (active?' active':'');
    if(disabled){ btn.disabled=true; }
    btn.onclick=function(){ USERS_PAGE=page; renderUsers(); };
    return btn;
  }

  function renderUsersPager(total){
    const pager=$('#usersPager'); if(!pager) return;
    pager.innerHTML='';

    const totalPages = Math.max(1, Math.ceil(total / USERS_SIZE));
    const cur = Math.min(USERS_PAGE, totalPages);

    pager.appendChild(makePageButton('Prev', Math.max(1, cur-1), cur===1));

    function addEllipsis(){ const sp=document.createElement('span'); sp.className='ellipsis'; sp.textContent='…'; pager.appendChild(sp); }
    const pages = [];
    pages.push(1);
    if (cur-1>2) pages.push('...');
    if (cur-1>=2) pages.push(cur-1);
    if (cur!==1 && cur!==totalPages) pages.push(cur);
    if (cur+1<=totalPages-1) pages.push(cur+1);
    if (cur+1<totalPages-1) pages.push('...');
    if (totalPages>1) pages.push(totalPages);

    let added=new Set();
    pages.forEach(p=>{
      if (p==='...'){ addEllipsis(); return; }
      if (added.has(p)) return;
      added.add(p);
      pager.appendChild(makePageButton(String(p), p, false, p===cur));
    });

    pager.appendChild(makePageButton('Next', Math.min(totalPages, cur+1), cur===totalPages));
  }

  function renderUsers(){
    const total = USERS_ROWS.length;
    const from  = (USERS_PAGE-1)*USERS_SIZE;
    const to    = Math.min(total, from + USERS_SIZE);
    const slice = USERS_ROWS.slice(from, to);
    renderUsersTable(slice);
    renderUsersPager(total);
    const showing=$('#usersShowing');
    if (showing) showing.textContent = total ? ('Showing '+(from+1)+'–'+to+' of '+total) : 'No users';
  }

  async function loadUsers(){
    const r=await api('users_overview');
    if (!r.ok || !Array.isArray(r.rows)){ USERS_ROWS=[]; renderUsers(); return; }
    USERS_ROWS = r.rows;
    renderUsers();
  }

  async function init(){
    await loadKPIs();
    await loadPrePost();
    await loadPaired();
    await loadAgeInsights();
    await loadDaily();
    await loadUsers();
    await loadReports();
  }
  init();
})();
</script>

</body>
</html>

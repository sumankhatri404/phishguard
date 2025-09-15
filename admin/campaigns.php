<?php
// /admin/campaigns.php
declare(strict_types=1);
require_once __DIR__ . '/boot.php';

admin_require_login();
$base = admin_base();

/* ===================== DB helpers (safe) ===================== */
function tbl_exists(PDO $pdo, string $t): bool {
  $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables
                    WHERE table_schema = DATABASE() AND table_name=?");
  $q->execute([$t]); return (int)$q->fetchColumn() > 0;
}
function col_exists(PDO $pdo, string $t, string $c): bool {
  $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.columns
                    WHERE table_schema = DATABASE() AND table_name=? AND column_name=?");
  $q->execute([$t,$c]); return (int)$q->fetchColumn() > 0;
}

/* training_modules bootstrap */
function tm_bootstrap(PDO $pdo): void {
  $pdo->exec("CREATE TABLE IF NOT EXISTS training_modules (
     id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  if (!col_exists($pdo,'training_modules','title'))       $pdo->exec("ALTER TABLE training_modules ADD COLUMN title VARCHAR(200) NOT NULL DEFAULT ''");
  if (!col_exists($pdo,'training_modules','channel'))     $pdo->exec("ALTER TABLE training_modules ADD COLUMN channel ENUM('email','sms','web') NOT NULL DEFAULT 'email'");
  if (!col_exists($pdo,'training_modules','cases_table')) $pdo->exec("ALTER TABLE training_modules ADD COLUMN cases_table VARCHAR(64) NULL AFTER channel");
  if (!col_exists($pdo,'training_modules','description')) $pdo->exec("ALTER TABLE training_modules ADD COLUMN description TEXT NULL");
  if (!col_exists($pdo,'training_modules','difficulty'))  $pdo->exec("ALTER TABLE training_modules ADD COLUMN difficulty TINYINT NOT NULL DEFAULT 1");
  if (!col_exists($pdo,'training_modules','is_active'))   $pdo->exec("ALTER TABLE training_modules ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1");
  if (!col_exists($pdo,'training_modules','created_at'))  $pdo->exec("ALTER TABLE training_modules ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
}

/* legacy default pools (email/sms/web) */
function cases_bootstrap(PDO $pdo): void {
  // email (legacy pool)
  $pdo->exec("CREATE TABLE IF NOT EXISTS training_mail_cases (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  foreach ([['module_id',"INT UNSIGNED NOT NULL"],['title',"VARCHAR(200) NOT NULL DEFAULT ''"],['body',"MEDIUMTEXT NULL"],['image_url',"VARCHAR(500) NULL"],['is_active',"TINYINT(1) NOT NULL DEFAULT 1"],['created_at',"DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP"]] as $c)
    if (!col_exists($pdo,'training_mail_cases',$c[0])) $pdo->exec("ALTER TABLE training_mail_cases ADD COLUMN {$c[0]} {$c[1]}");
  try { $pdo->exec("CREATE INDEX idx_tmc_mod ON training_mail_cases(module_id)"); } catch (Throwable $e) {}

  // author feedback
  if (!col_exists($pdo,'training_mail_cases','explain_html')) {
    $after = col_exists($pdo,'training_mail_cases','forwarded_email_html') ? " AFTER forwarded_email_html" : "";
    $pdo->exec("ALTER TABLE training_mail_cases ADD COLUMN explain_html MEDIUMTEXT NULL{$after}");
  }

  // sms
  $pdo->exec("CREATE TABLE IF NOT EXISTS training_sms_cases (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  foreach ([['module_id',"INT UNSIGNED NOT NULL"],['title',"VARCHAR(200) NOT NULL DEFAULT ''"],['body',"MEDIUMTEXT NULL"],['image_url',"VARCHAR(500) NULL"],['is_active',"TINYINT(1) NOT NULL DEFAULT 1"],['created_at',"DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP"]] as $c)
    if (!col_exists($pdo,'training_sms_cases',$c[0])) $pdo->exec("ALTER TABLE training_sms_cases ADD COLUMN {$c[0]} {$c[1]}");
  try { $pdo->exec("CREATE INDEX idx_tsc_mod ON training_sms_cases(module_id)"); } catch (Throwable $e) {}
  // add feedback column for SMS if missing
  if (!col_exists($pdo,'training_sms_cases','explain_html')) {
    $pdo->exec("ALTER TABLE training_sms_cases ADD COLUMN explain_html MEDIUMTEXT NULL");
  }

  // web (legacy)
  $pdo->exec("CREATE TABLE IF NOT EXISTS training_web_cases (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  foreach ([['module_id',"INT UNSIGNED NOT NULL"],['title',"VARCHAR(200) NOT NULL DEFAULT ''"],['body',"MEDIUMTEXT NULL"],['image_url',"VARCHAR(500) NULL"],['screenshot_path',"VARCHAR(500) NULL"],['is_active',"TINYINT(1) NOT NULL DEFAULT 1"],['created_at',"DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP"]] as $c)
    if (!col_exists($pdo,'training_web_cases',$c[0])) $pdo->exec("ALTER TABLE training_web_cases ADD COLUMN {$c[0]} {$c[1]}");
  try { $pdo->exec("CREATE INDEX idx_twc_mod ON training_web_cases(module_id)"); } catch (Throwable $e) {}
  if (!col_exists($pdo,'training_web_cases','explain_html')) {
    $pdo->exec("ALTER TABLE training_web_cases ADD COLUMN explain_html MEDIUMTEXT NULL");
  }
}

/* SPOT THE PHISH bootstrap */
function spot_bootstrap(PDO $pdo): void {
  $pdo->exec("CREATE TABLE IF NOT EXISTS spot_tasks (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    channel ENUM('email','sms','web') NOT NULL DEFAULT 'email',
    title VARCHAR(200) NOT NULL DEFAULT '',
    from_line VARCHAR(200) NULL,
    meta_line VARCHAR(200) NULL,
    body_html MEDIUMTEXT NULL,
    correct_answer ENUM('phish','legit') NOT NULL DEFAULT 'phish',
    is_phish TINYINT(1) NOT NULL DEFAULT 1,
    correct_rationale TEXT NULL,
    points_right INT NOT NULL DEFAULT 6,
    points_wrong INT NOT NULL DEFAULT -2,
    time_limit_sec INT NOT NULL DEFAULT 30,
    image_url VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/* pick table for channel (prefer sim_scenarios for web) */
function cases_table_for(PDO $pdo, string $channel): string {
  if ($channel === 'web' && tbl_exists($pdo,'sim_scenarios')) return 'sim_scenarios';
  if ($channel === 'sms' && tbl_exists($pdo,'training_sms_cases')) return 'training_sms_cases';
  if ($channel === 'email' && tbl_exists($pdo,'training_mail_cases')) return 'training_mail_cases';
  return match($channel){
    'sms'  => 'training_sms_cases',
    'web'  => 'sim_scenarios',
    default=> 'training_mail_cases'
  };
}

/* per-module helpers */
function get_module_row(PDO $pdo, int $moduleId): ?array {
  $st=$pdo->prepare("SELECT id,title,channel,cases_table FROM training_modules WHERE id=?");
  $st->execute([$moduleId]);
  $r=$st->fetch(PDO::FETCH_ASSOC);
  return $r ?: null;
}
function get_cases_table_for_module(PDO $pdo, int $moduleId): string {
  $m = get_module_row($pdo, $moduleId);
  if (!$m) throw new RuntimeException('Module not found');
  return $m['cases_table'] ?: cases_table_for($pdo, $m['channel']);
}
function ensure_cases_table(PDO $pdo, int $moduleId): string {
  $table = "training_cases_m{$moduleId}";
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS `$table` (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      module_id INT UNSIGNED NOT NULL,
      title VARCHAR(200) NOT NULL DEFAULT '',
      body MEDIUMTEXT NULL,
      image_url VARCHAR(500) NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_module (module_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
  $st=$pdo->prepare("UPDATE training_modules SET cases_table=:t WHERE id=:id");
  $st->execute([':t'=>$table, ':id'=>$moduleId]);
  return $table;
}

/* helper: upsert level title for web scenarios */
function upsert_level_title(PDO $pdo, int $levelNo, string $title): void {
  if ($title==='' || !tbl_exists($pdo,'sim_levels')) return;
  try {
    $pdo->prepare("
      INSERT INTO sim_levels (level_no, title)
      VALUES (:ln,:tt)
      ON DUPLICATE KEY UPDATE title=VALUES(title), updated_at=NOW()
    ")->execute([':ln'=>$levelNo, ':tt'=>$title]);
  } catch (Throwable $e) { /* ignore */ }
}

/* ============================ AJAX ============================ */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  header('Content-Type: application/json; charset=UTF-8');
  header('Cache-Control: no-store, no-cache, must-revalidate');
  header('Pragma: no-cache');
  header('Expires: 0');

  $csrfRaw = $_POST['csrf'] ?? '';
  $isAjax  = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
  if (!admin_verify_csrf($csrfRaw) && !($isAjax && admin_logged_in())) { echo json_encode(['ok'=>false,'message'=>'Bad CSRF']); exit; }
  try { $pdo->exec("SET time_zone = '+00:00'"); } catch(Throwable $e){}
  tm_bootstrap($pdo); cases_bootstrap($pdo); spot_bootstrap($pdo);

  $act = $_POST['action'] ?? '';
  try {
    switch ($act) {

      /* ---------- SPOT THE PHISH (tasks) ---------- */
      case 'spot_list': {
        $q       = trim((string)($_POST['q']??''));
        $channel = $_POST['channel'] ?? 'all';
        $type    = $_POST['type'] ?? 'all';
        $page    = max(1, (int)($_POST['page'] ?? 1));
        $per     = max(1, min(100, (int)($_POST['per_page'] ?? 5))); // default 5

        // Use distinct placeholders for native prepares
        $w = ["(title LIKE :q1 OR COALESCE(from_line,'') LIKE :q2 OR COALESCE(meta_line,'') LIKE :q3)"];
        $b = [':q1'=>"%$q%", ':q2'=>"%$q%", ':q3'=>"%$q%"];
        if (in_array($channel,['email','sms','web'],true)) { $w[]="channel=:ch"; $b[':ch']=$channel; }
        if (in_array($type,['phish','legit'],true))       { $w[]="correct_answer=:ca"; $b[':ca']=$type; }
        $where = implode(' AND ',$w);

        $cnt=$pdo->prepare("SELECT COUNT(*) FROM spot_tasks WHERE $where");
        foreach($b as $k=>$v) $cnt->bindValue($k,$v);
        $cnt->execute(); $total=(int)$cnt->fetchColumn();

        $offset = max(0, ($page-1)*$per);
        $sql = "SELECT id, channel, title, correct_answer, is_phish,
                       points_right, points_wrong, time_limit_sec,
                       DATE_FORMAT(created_at,'%Y-%m-%d %H:%i') created_at
                FROM spot_tasks
                WHERE $where
                ORDER BY id ASC
                LIMIT $offset, $per";
        $st=$pdo->prepare($sql);
        foreach($b as $k=>$v) $st->bindValue($k,$v);
        $st->execute();

        echo json_encode([
          'ok'=>true,
          'rows'=>$st->fetchAll(),
          'page'=>$page,
          'per_page'=>$per,
          'total'=>$total
        ]);
        exit;
      }

      case 'spot_get': {
        $id=(int)($_POST['id']??0);
        $st=$pdo->prepare("SELECT * FROM spot_tasks WHERE id=? LIMIT 1");
        $st->execute([$id]);
        echo json_encode(['ok'=>true,'row'=>$st->fetch(PDO::FETCH_ASSOC) ?: null]); exit;
      }

      case 'spot_save': {
        $id=(int)($_POST['id']??0);
        $channel = in_array($_POST['channel']??'email',['email','sms','web'],true) ? $_POST['channel'] : 'email';
        $title   = trim((string)($_POST['title']??''));
        $from    = trim((string)($_POST['from_line']??''));
        $meta    = trim((string)($_POST['meta_line']??''));
        $body    = (string)($_POST['body_html']??'');
        $answer  = in_array($_POST['correct_answer']??'phish',['phish','legit'],true) ? $_POST['correct_answer'] : 'phish';
        $isPhish = $answer === 'phish' ? 1 : (int)($_POST['is_phish'] ?? 0);
        $rat     = (string)($_POST['correct_rationale']??'');
        $pr      = (int)($_POST['points_right'] ?? 6);
        $pw      = (int)($_POST['points_wrong'] ?? -2);
        $tl      = (int)($_POST['time_limit_sec'] ?? 30);
        $img     = trim((string)($_POST['image_url']??''));

        if ($id>0){
          $st=$pdo->prepare("UPDATE spot_tasks
                             SET channel=?, title=?, from_line=?, meta_line=?, body_html=?,
                                 correct_answer=?, is_phish=?, correct_rationale=?, points_right=?,
                                 points_wrong=?, time_limit_sec=?, image_url=? WHERE id=?");
          $st->execute([$channel,$title,$from,$meta,$body,$answer,$isPhish,$rat,$pr,$pw,$tl,$img,$id]);
        } else {
          $st=$pdo->prepare("INSERT INTO spot_tasks
              (channel,title,from_line,meta_line,body_html,correct_answer,is_phish,correct_rationale,points_right,points_wrong,time_limit_sec,image_url)
              VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
          $st->execute([$channel,$title,$from,$meta,$body,$answer,$isPhish,$rat,$pr,$pw,$tl,$img]);
          $id=(int)$pdo->lastInsertId();
        }
        echo json_encode(['ok'=>true,'id'=>$id]); exit;
      }

      case 'spot_delete': {
        $id=(int)($_POST['id']??0);
        $pdo->prepare("DELETE FROM spot_tasks WHERE id=? LIMIT 1")->execute([$id]);
        echo json_encode(['ok'=>true]); exit;
      }

      /* ---------- MODULES / CASES (existing) ---------- */
      case 'tm_list': {
        $q = trim((string)($_POST['q']??'')); $channel = $_POST['channel'] ?? 'all';
        $status  = $_POST['status']  ?? 'all'; $order   = $_POST['order']   ?? 'newest';
        $limit   = max(10,min(300,(int)($_POST['limit']??100)));

        $w = ["(title LIKE :q1 OR COALESCE(description,'') LIKE :q2)"]; $b = [':q1'=>"%$q%", ':q2'=>"%$q%"];
        if (in_array($channel,['email','sms','web'],true)) { $w[]="channel=:chan"; $b[':chan']=$channel; }
        if (in_array($status,['active','inactive'],true)) { $w[]="is_active=:ia"; $b[':ia']= $status==='active'?1:0; }
        $orderSql = $order==='name' ? "ORDER BY title ASC" : "ORDER BY id DESC";

        $st=$pdo->prepare("SELECT id,title,channel,COALESCE(description,'') description,
                                   difficulty,is_active,COALESCE(cases_table,'') cases_table,
                                   DATE_FORMAT(created_at,'%Y-%m-%d %H:%i') created_at
                           FROM training_modules
                           WHERE ".implode(' AND ',$w)." $orderSql LIMIT :lim");
        foreach($b as $k=>$v) $st->bindValue($k,$v);
        $st->bindValue(':lim',$limit,PDO::PARAM_INT);
        $st->execute();
        echo json_encode(['ok'=>true,'rows'=>$st->fetchAll()]); exit;
      }

      case 'tm_save': {
        $id=(int)($_POST['id']??0); $t=trim((string)($_POST['title']??'')); 
        $ch=in_array($_POST['channel']??'email',['email','sms','web'],true)?$_POST['channel']:'email';
        $desc=(string)($_POST['description']??''); $diff=max(1,min(5,(int)($_POST['difficulty']??1)));
        $actv=(int)($_POST['is_active']??1); $storage=$_POST['case_storage'] ?? 'default';

        if ($id>0){
          $st=$pdo->prepare("UPDATE training_modules SET title=?, channel=?, description=?, difficulty=?, is_active=? WHERE id=?");
          $st->execute([$t,$ch,$desc,$diff,$actv,$id]);
          if ($storage==='dedicated') ensure_cases_table($pdo, $id);
        } else {
          $st=$pdo->prepare("INSERT INTO training_modules (title,channel,description,difficulty,is_active) VALUES (?,?,?,?,?)");
          $st->execute([$t,$ch,$desc,$diff,$actv]); $id=(int)$pdo->lastInsertId();
          if ($storage==='dedicated') ensure_cases_table($pdo, $id);
        }
        echo json_encode(['ok'=>true,'id'=>$id]); exit;
      }

      case 'tm_delete': {
        $id = (int)$_POST['id'];
        $m  = get_module_row($pdo, $id);
        if ($m) {
          $tbl = $m['cases_table'] ?: cases_table_for($pdo, $m['channel']);
          if ($tbl !== 'sim_scenarios') { // sim_scenarios has no module_id
            $pdo->prepare("DELETE FROM `$tbl` WHERE module_id=?")->execute([$id]);
          }
          $pdo->prepare("DELETE FROM training_modules WHERE id=?")->execute([$id]);
        }
        echo json_encode(['ok'=>true]); exit;
      }

      case 'tm_toggle': {
        $id=(int)$_POST['id']; $to=(int)$_POST['is_active'];
        $pdo->prepare("UPDATE training_modules SET is_active=? WHERE id=?")->execute([$to,$id]);
        echo json_encode(['ok'=>true]); exit;
      }

      /* ---- cases list ---- */
      case 'cases_list': {
        $mid=(int)$_POST['module_id'];
        $m=get_module_row($pdo,$mid);
        if(!$m){ echo json_encode(['ok'=>true,'rows'=>[],'channel'=>null]); exit; }
        $tbl=get_cases_table_for_module($pdo,$mid);

        if ($tbl === 'sim_scenarios') {
          $sql = "
            SELECT s.id, s.level_no, s.status, s.url_in_bar, s.content_html,
                   DATE_FORMAT(s.created_at,'%Y-%m-%d %H:%i') AS created_at,
                   COALESCE(l.title, CONCAT('Level ', s.level_no)) AS level_title
            FROM sim_scenarios s
            LEFT JOIN sim_levels l ON l.level_no = s.level_no
            INNER JOIN (SELECT url_in_bar, MAX(id) AS max_id FROM sim_scenarios GROUP BY url_in_bar) d
                    ON d.max_id = s.id
            ORDER BY s.id DESC
            LIMIT 1000";
          $rows=[]; foreach($pdo->query($sql) as $r){
            $rows[] = [
              'id'        => (int)$r['id'],
              'title'     => (string)($r['level_title'] ?: ('Scenario #'.$r['id'])),
              'image_url' => (string)($r['url_in_bar'] ?? ''),
              'body'      => (string)$r['content_html'],
              'is_active' => (int)(strtolower((string)$r['status']) === 'published'),
              'created_at'=> (string)$r['created_at'],
            ];
          }
          echo json_encode(['ok'=>true,'rows'=>$rows,'channel'=>$m['channel'],'cases_table'=>$tbl]); exit;
        }

        // legacy tables
        $hasSubject = col_exists($pdo,$tbl,'subject');
        if ($hasSubject) {
          $sql = "SELECT id, module_id,
                         subject AS title,
                         COALESCE(from_avatar,'') AS image_url,
                         COALESCE(forwarded_email_html,'') AS body,
                         COALESCE(explain_html,'') AS explain_html,
                         is_active,
                         DATE_FORMAT(created_at,'%Y-%m-%d %H:%i') created_at
                  FROM `$tbl`
                  WHERE module_id=? ORDER BY id DESC LIMIT 1000";
        } else {
          $hasShot       = col_exists($pdo,$tbl,'screenshot_path');
          $hasFromAvatar = col_exists($pdo,$tbl,'from_avatar');
          $hasSmsHtml    = col_exists($pdo,$tbl,'sms_html');
          // support both legacy explain_html and alternate feedback_html
          $hasExplain    = col_exists($pdo,$tbl,'explain_html') || col_exists($pdo,$tbl,'feedback_html');
          // Prefer from_avatar over image_url/screenshot_path for SMS-like tables
          $parts = [];
          if ($hasFromAvatar) $parts[] = "NULLIF(from_avatar,'')";
          $parts[] = "NULLIF(image_url,'')";
          if ($hasShot)       $parts[] = "NULLIF(screenshot_path,'')";
          $imgExpr = "COALESCE(".implode(',', $parts).", '')";
          // Prefer sms_html over body when present
          $bodyExpr = $hasSmsHtml ? "COALESCE(sms_html, body, '')" : "COALESCE(body,'')";
          $exSel   = $hasExplain
                    ? (col_exists($pdo,$tbl,'feedback_html')
                        ? ", COALESCE(feedback_html,'') AS explain_html"
                        : ", COALESCE(explain_html,'') AS explain_html")
                    : '';
          $sql = "SELECT id, module_id, title, $imgExpr AS image_url, $bodyExpr AS body$exSel,
                         is_active, DATE_FORMAT(created_at,'%Y-%m-%d %H:%i') created_at
                  FROM `$tbl`
                  WHERE module_id=? ORDER BY id DESC LIMIT 1000";
        }
        $rows=$pdo->prepare($sql); $rows->execute([$mid]);
        echo json_encode(['ok'=>true,'rows'=>$rows->fetchAll(),'channel'=>$m['channel'],'cases_table'=>$tbl]); exit;
      }

      case 'case_get': {
        $id=(int)$_POST['id']; $mid=(int)$_POST['module_id'];
        $tbl=get_cases_table_for_module($pdo,$mid);

        if ($tbl === 'sim_scenarios') {
          $sql="SELECT s.id, s.level_no, s.status, s.url_in_bar, s.content_html,
                       COALESCE(l.title, CONCAT('Level ', s.level_no)) AS level_title
                FROM sim_scenarios s
                LEFT JOIN sim_levels l ON l.level_no = s.level_no
                WHERE s.id=? LIMIT 1";
          $st=$pdo->prepare($sql); $st->execute([$id]);
          if ($r=$st->fetch(PDO::FETCH_ASSOC)) {
            echo json_encode(['ok'=>true,'row'=>[
              'id'        => (int)$r['id'],
              'title'     => (string)($r['level_title'] ?? ('Level '.$r['level_no'])),
              'body'      => (string)$r['content_html'],
              'image_url' => (string)$r['url_in_bar'],
              'is_active' => (int)(strtolower((string)$r['status']) === 'published'),
            ]]); exit;
          }
          echo json_encode(['ok'=>true,'row'=>null]); exit;
        }

        // legacy
        $hasSubject = col_exists($pdo,$tbl,'subject');
        if ($hasSubject) {
          $sql="SELECT id,module_id,
                       subject AS title,
                       COALESCE(forwarded_email_html,'') AS body,
                       COALESCE(from_avatar,'') AS image_url,
                       COALESCE(explain_html,'') AS explain_html,
                       is_active
                FROM `$tbl`
                WHERE id=? AND module_id=? LIMIT 1";
        } else {
          $hasShot = col_exists($pdo,$tbl,'screenshot_path');
          $hasFromAvatar = col_exists($pdo,$tbl,'from_avatar');
          $hasSmsHtml    = col_exists($pdo,$tbl,'sms_html');
          $hasExplain    = col_exists($pdo,$tbl,'explain_html') || col_exists($pdo,$tbl,'feedback_html');
          $parts=[ ]; if($hasFromAvatar)$parts[]="NULLIF(from_avatar,'')"; $parts[]="NULLIF(image_url,'')"; if($hasShot)$parts[]="NULLIF(screenshot_path,'')";
          $imgExpr="COALESCE(".implode(',', $parts).", '')";
          $bodyExpr=$hasSmsHtml ? "COALESCE(sms_html, body, '')" : "COALESCE(body,'')";
          $exSel = $hasExplain
                    ? (col_exists($pdo,$tbl,'feedback_html')
                        ? ", COALESCE(feedback_html,'') AS explain_html"
                        : ", COALESCE(explain_html,'') AS explain_html")
                    : '';
          $sql="SELECT id,module_id,title,$bodyExpr AS body,$imgExpr AS image_url$exSel,is_active
                FROM `$tbl` WHERE id=? AND module_id=? LIMIT 1";
        }
        $st=$pdo->prepare($sql); $st->execute([$id,$mid]);
        echo json_encode(['ok'=>true,'row'=>$st->fetch()?:null]); exit;
      }

      case 'case_save': {
        $id  = (int)($_POST['id']??0);
        $mid = (int)($_POST['module_id']??0);
        $tbl = get_cases_table_for_module($pdo,$mid);

        $title = trim((string)($_POST['title']??'')); // UI title
        $body  = (string)($_POST['body']??'');        // email/web content
        $url   = (string)($_POST['image_url']??'');   // url_in_bar for web
        $actv  = (int)($_POST['is_active']??1);       // -> status
        $expl  = (string)($_POST['explain_html'] ?? '');

        if ($tbl === 'sim_scenarios') {
          // determine level_no for this scenario (for title upsert)
          $levelNo = 1;
          if ($id>0) {
            $s=$pdo->prepare("SELECT level_no FROM sim_scenarios WHERE id=?");
            $s->execute([$id]); $levelNo = (int)($s->fetchColumn() ?: 1);
          }
          $status = $actv ? 'published' : 'draft';

          if ($id>0){
            $sql = "UPDATE sim_scenarios
                    SET url_in_bar       = :url,
                        content_html     = :html,
                        status           = :st,
                        show_padlock     = COALESCE(show_padlock,0),
                        show_not_secure  = COALESCE(show_not_secure,0),
                        updated_at       = NOW()
                    WHERE id = :id
                    LIMIT 1";
            $st = $pdo->prepare($sql);
            $st->execute([':url'=>$url, ':html'=>$body, ':st'=>$status, ':id'=>$id]);
          } else {
            $sql = "INSERT INTO sim_scenarios
                      (level_no,version,status,url_in_bar,
                       show_padlock,show_not_secure,
                       countdown_seconds,content_html,hint_order_json,
                       created_at,updated_at)
                    VALUES
                      (:ln,1,:st,:url,0,0,:cd,:html,'[]',NOW(),NOW())";
            $st = $pdo->prepare($sql);
            $st->execute([':ln'=>$levelNo, ':st'=>$status, ':url'=>$url, ':cd'=>0, ':html'=>$body]);
            $id=(int)$pdo->lastInsertId();
          }

          // NEW: keep the displayed title in sync with sim_levels
          upsert_level_title($pdo, $levelNo, $title);

          echo json_encode(['ok'=>true,'id'=>$id]); exit;
        }

        // ---------- legacy pools ----------
        $hasSubject = col_exists($pdo,$tbl,'subject');
        if ($hasSubject) {
          if ($id>0){
            $st=$pdo->prepare("UPDATE `$tbl`
                               SET subject=?, forwarded_email_html=?, from_avatar=?, explain_html=?, is_active=?
                               WHERE id=? AND module_id=?");
            $st->execute([$title,$body,$url,$expl,$actv,$id,$mid]);
          } else {
            $st=$pdo->prepare("INSERT INTO `$tbl` (module_id, subject, forwarded_email_html, from_avatar, explain_html, is_active)
                               VALUES (?,?,?,?,?,?)");
            $st->execute([$mid,$title,$body,$url,$expl,$actv]);
            $id=(int)$pdo->lastInsertId();
          }
        } else {
          $hasShot = col_exists($pdo,$tbl,'screenshot_path');
          // choose which feedback column we have
          $colExpl = col_exists($pdo,$tbl,'feedback_html') ? 'feedback_html'
                     : (col_exists($pdo,$tbl,'explain_html') ? 'explain_html' : '');
          // map admin fields to proper SMS columns when available
          $colBody = col_exists($pdo,$tbl,'sms_html') ? 'sms_html' : (col_exists($pdo,$tbl,'body') ? 'body' : '');
          $colImg  = col_exists($pdo,$tbl,'from_avatar') ? 'from_avatar' : (col_exists($pdo,$tbl,'image_url') ? 'image_url' : '');
          if ($id>0){
            if ($hasShot) {
              if ($colExpl !== '' && $colBody !== '' && $colImg !== '') {
                $st=$pdo->prepare("UPDATE `$tbl` SET title=?, {$colBody}=?, {$colImg}=?, screenshot_path=?, {$colExpl}=?, is_active=? WHERE id=? AND module_id=?");
                $st->execute([$title,$body,$url,$url,$expl,$actv,$id,$mid]);
              } else {
                $st=$pdo->prepare("UPDATE `$tbl` SET title=?, body=?, image_url=?, screenshot_path=?, is_active=? WHERE id=? AND module_id=?");
                $st->execute([$title,$body,$url,$url,$actv,$id,$mid]);
              }
            } else {
              if ($colExpl !== '' && $colBody !== '' && $colImg !== '') {
                $st=$pdo->prepare("UPDATE `$tbl` SET title=?, {$colBody}=?, {$colImg}=?, {$colExpl}=?, is_active=? WHERE id=? AND module_id=?");
                $st->execute([$title,$body,$url,$expl,$actv,$id,$mid]);
              } else {
                $st=$pdo->prepare("UPDATE `$tbl` SET title=?, body=?, image_url=?, is_active=? WHERE id=? AND module_id=?");
                $st->execute([$title,$body,$url,$actv,$id,$mid]);
              }
            }
          } else {
            if ($hasShot) {
              if ($colExpl !== '' && $colBody !== '' && $colImg !== '') {
                $st=$pdo->prepare("INSERT INTO `$tbl` (module_id, title, {$colBody}, {$colImg}, screenshot_path, {$colExpl}, is_active) VALUES (?,?,?,?,?,?,?)");
                $st->execute([$mid,$title,$body,$url,$url,$expl,$actv]);
              } else {
                $st=$pdo->prepare("INSERT INTO `$tbl` (module_id, title, body, image_url, screenshot_path, is_active) VALUES (?,?,?,?,?,?)");
                $st->execute([$mid,$title,$body,$url,$url,$actv]);
              }
            } else {
              if ($colExpl !== '' && $colBody !== '' && $colImg !== '') {
                $st=$pdo->prepare("INSERT INTO `$tbl` (module_id, title, {$colBody}, {$colImg}, {$colExpl}, is_active) VALUES (?,?,?,?,?,?)");
                $st->execute([$mid,$title,$body,$url,$expl,$actv]);
              } else {
                $st=$pdo->prepare("INSERT INTO `$tbl` (module_id, title, body, image_url, is_active) VALUES (?,?,?,?,?)");
                $st->execute([$mid,$title,$body,$url,$actv]);
              }
            }
            $id=(int)$pdo->lastInsertId();
          }
        }
        echo json_encode(['ok'=>true,'id'=>$id]); exit;
      }

      case 'case_delete': {
        $id=(int)$_POST['id']; $mid=(int)$_POST['module_id'];
        $tbl=get_cases_table_for_module($pdo,$mid);
        if ($tbl==='sim_scenarios') {
          $pdo->prepare("DELETE FROM sim_scenarios WHERE id=? LIMIT 1")->execute([$id]);
        } else {
          $pdo->prepare("DELETE FROM `$tbl` WHERE id=? AND module_id=?")->execute([$id,$mid]);
        }
        echo json_encode(['ok'=>true]); exit;
      }

      case 'auto_fill_cases': {
        $mid   = (int)($_POST['module_id'] ?? 0);
        $m = get_module_row($pdo,$mid);
        if(!$m){ echo json_encode(['ok'=>false,'message'=>'Module not found']); exit; }

        if (get_cases_table_for_module($pdo,$mid)==='sim_scenarios') {
          echo json_encode(['ok'=>false,'message'=>'Auto-fill is not available for Website Impersonation scenarios.']); exit;
        }

        $count = max(1, (int)($_POST['count'] ?? 3));
        $src = cases_table_for($pdo, $m['channel']);
        $dst = get_cases_table_for_module($pdo,$mid);

        $q=$pdo->prepare("SELECT * FROM `$src` WHERE is_active=1 ORDER BY RAND() LIMIT :lim");
        $q->bindValue(':lim',$count,PDO::PARAM_INT); $q->execute(); $rows=$q->fetchAll(PDO::FETCH_ASSOC);

        $toEmail    = col_exists($pdo,$dst,'subject');
        $toWebShot  = (!$toEmail && col_exists($pdo,$dst,'screenshot_path'));

        if ($toEmail) {
          $ins=$pdo->prepare("INSERT INTO `$dst` (module_id, subject, forwarded_email_html, from_avatar, explain_html, is_active)
                              VALUES (:m,:t,:b,:img,:ex,1)");
        } elseif ($toWebShot) {
          $ins=$pdo->prepare("INSERT INTO `$dst` (module_id, title, body, image_url, screenshot_path, is_active) VALUES (:m,:t,:b,:img,:img,1)");
        } else {
          $ins=$pdo->prepare("INSERT INTO `$dst` (module_id, title, body, image_url, is_active) VALUES (:m,:t,:b,:img,1)");
        }

        $added=0;
        foreach($rows as $r){
          $t = $r['title'] ?? ($r['subject'] ?? 'Untitled');
          $b = $r['body'] ?? ($r['forwarded_email_html'] ?? '');
          $i = $r['image_url'] ?? ($r['from_avatar'] ?? ($r['screenshot_path'] ?? ''));
          $ex = $r['explain_html'] ?? '';
          if ($toEmail) {
            $ins->execute([':m'=>$mid, ':t'=>$t, ':b'=>$b, ':img'=>$i, ':ex'=>$ex]);
          } else {
            $ins->execute([':m'=>$mid, ':t'=>$t, ':b'=>$b, ':img'=>$i]);
          }
          $added++;
        }
        echo json_encode(['ok'=>true,'added'=>$added]); exit;
      }

      case 'upload_image': {
        if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) { echo json_encode(['ok'=>false,'message'=>'No file']); exit; }
        $f=$_FILES['file']; $ext=strtolower(pathinfo($f['name'],PATHINFO_EXTENSION));
        if (!in_array($ext,['png','jpg','jpeg','gif','webp','svg'],true)) { echo json_encode(['ok'=>false,'message'=>'Invalid type']); exit; }
        if ($f['size']>5*1024*1024){ echo json_encode(['ok'=>false,'message'=>'Max 5MB']); exit; }
        $dir = dirname(__DIR__).'/uploads'; if(!is_dir($dir)) @mkdir($dir,0775,true);
        $name='case_'.date('Ymd_His').'_'.bin2hex(random_bytes(4)).'.'.$ext;
        $dest=$dir.'/'.$name;
        if(!move_uploaded_file($f['tmp_name'],$dest)){ echo json_encode(['ok'=>false,'message'=>'Move failed']); exit; }
        $site = rtrim(dirname($base), '/');
        $url  = $site . '/uploads/' . $name;
        echo json_encode(['ok'=>true,'url'=>$url]); exit;
      }

      case 'export_modules_csv': {
        header_remove('Content-Type');
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="training_modules.csv"');
        $out=fopen('php://output','w');
        fputcsv($out,['id','title','channel','cases_table','difficulty','is_active','created_at']);
        foreach ($pdo->query("SELECT id,title,channel,COALESCE(cases_table,'') cases_table,difficulty,is_active,DATE_FORMAT(created_at,'%Y-%m-%d %H:%i') created_at FROM training_modules ORDER BY id DESC") as $r)
          fputcsv($out,$r);
        fclose($out); exit;
      }

      default: echo json_encode(['ok'=>false,'message'=>'Unknown action']); exit;
    }
  } catch (Throwable $e) {
    echo json_encode(['ok'=>false,'message'=>'Action failed','debug'=>$e->getMessage()]); exit;
  }
}

$csrf = admin_csrf_token();
$adminName = htmlspecialchars($_SESSION['admin']['username'] ?? 'admin');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Campaigns (Training Modules) · PhishGuard Admin</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{
  --bg:#f8fafc; --panel:#ffffff; --ring:#e5e7eb; --text:#0f172a; --muted:#64748b;
  --accent:#6366f1; --accent-soft:#eef2ff; --ok:#10b981; --warn:#f59e0b; --red:#ef4444;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font:14px/1.45 system-ui,Segoe UI,Roboto,Arial}
a{color:inherit;text-decoration:none}

.wrap{display:grid;grid-template-columns:240px 1fr;min-height:100vh}
.sidebar{background:#0f172a;color:#e2e8f0;padding:16px 12px}
.brand{display:flex;align-items:center;gap:10px;font-weight:700;margin-bottom:8px}
.brand .dot{width:24px;height:24px;border-radius:8px;background:linear-gradient(135deg,#4f46e5,#06b6d4)}
.nav a{display:block;color:#cbd5e1;padding:10px 12px;border-radius:8px}
.nav a.active,.nav a:hover{background:#111827;color:#fff}

.header{display:flex;align-items:center;gap:12px;justify-content:space-between;padding:14px 18px;border-bottom:1px solid var(--ring);background:var(--panel)}
.header .badge{padding:6px 10px;border:1px solid var(--ring);border-radius:999px;background:#fff}

.main{padding:18px}
.card{background:var(--panel);border:1px solid var(--ring);border-radius:12px;padding:14px;margin-bottom:14px}
.controls{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px}
input,select,button,textarea{border:1px solid var(--ring);background:#fff;color:#var(--text);padding:8px 10px;border-radius:8px}
textarea{min-height:80px}
button{cursor:pointer}
.btn{background:#fff}
.btn.primary{background:var(--accent);border-color:var(--accent);color:#fff}
.btn.soft{background:var(--accent-soft);border-color:var(--accent-soft);color:#3730a3}
.btn.danger{background:var(--red);border-color:var(--red);color:#fff}
.btn.small{padding:6px 10px;font-size:12px;border-radius:7px}
.badge{display:inline-block;padding:2px 8px;border:1px solid var(--ring);border-radius:999px;background:#fff}
.badge.on{background:#ecfdf5;border-color:#a7f3d0;color:#065f46}
.badge.off{background:#fef2f2;border-color:#fecaca;color:#991b1b}

table{width:100%;border-collapse:collapse}
th,td{padding:10px 8px;border-bottom:1px solid var(--ring);text-align:left;vertical-align:middle}
th{color:#334155}

.grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media(max-width:1100px){.grid2{grid-template-columns:1fr}}

.smallmuted{font-size:12px;color:var(--muted)}

/* modal */
.modal{position:fixed;inset:0;display:none;align-items:center;justify-content:center;background:rgba(0,0,0,.25);padding:10px;z-index:1000}
.modal.open{display:flex}
.modal .box{width:min(980px,100%);background:var(--panel);border:1px solid var(--ring);border-radius:14px;padding:16px;position:relative}
.modal .top{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}
.modal .x{position:absolute;right:12px;top:10px;border:1px solid var(--ring);border-radius:8px;padding:6px 10px;background:#fff}

.btn[disabled]{opacity:.5;cursor:not-allowed}
.card-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:10px}
.card-title{margin:0;font-weight:700;font-size:1.1rem}
@media (max-width:720px){.card-title{font-size:1rem}}
</style>
</head>
<body data-base="<?= htmlspecialchars($base) ?>">
<div id="appRoot" class="wrap">
  <aside class="sidebar">
    <div class="brand"><div class="dot"></div> PhishGuard</div>
    <div class="nav">
      <a href="<?= htmlspecialchars($base) ?>/index.php">Dashboard</a>
      <a href="<?= htmlspecialchars($base) ?>/user.php">Users</a>
      <a href="<?= htmlspecialchars($base) ?>/campaigns.php" class="active">Modules</a>
      <a href="<?= htmlspecialchars($base) ?>/reports.php">Reports</a>
      <a href="<?= htmlspecialchars($base) ?>/paired.php">Paired Evaluation</a>
    </div>
  </aside>

  <section>
    <div class="header">
      <h2 style="margin:0">Campaigns (Training Modules)</h2>
      <div>
        <span class="badge">Signed in as <?= $adminName ?></span>
        <a class="btn" href="<?= htmlspecialchars($base) ?>/logout.php">Logout</a>
      </div>
    </div>

    <div class="main">
      <!-- Module editor -->
      <div class="card">
        <h3 style="margin:0 0 10px">Create / Edit module</h3>
        <div class="grid2">
          <div>
            <label class="smallmuted">Title</label>
            <input id="mTitle" placeholder="e.g. Email Phishing Fundamentals" style="width:100%">
          </div>
          <div>
            <label class="smallmuted">Channel</label>
            <select id="mChannel" style="width:100%">
              <option value="email">email</option>
              <option value="sms">sms</option>
              <option value="web">web</option>
            </select>
          </div>
          <div>
            <label class="smallmuted">Difficulty</label>
            <select id="mDiff" style="width:100%">
              <option value="1">Beginner</option><option value="2">Novice</option>
              <option value="3">Intermediate</option><option value="4">Advanced</option><option value="5">Expert</option>
            </select>
          </div>
          <div>
            <label class="smallmuted">Active</label>
            <select id="mActive" style="width:100%"><option value="1">Yes</option><option value="0">No</option></select>
          </div>
        </div>

        <div style="margin-top:8px" id="storageRow">
          <label class="smallmuted">Case storage</label>
          <div>
            <label><input type="radio" name="case_storage" value="default" checked> Use default pool (email/sms/web)</label>
            <label style="margin-left:16px"><input type="radio" name="case_storage" value="dedicated"> Dedicated table for this module</label>
          </div>
          <div class="smallmuted" id="storageHint"></div>
        </div>

        <div style="margin-top:8px">
          <textarea id="mDesc" placeholder="Short description / notes…" style="width:100%"></textarea>
        </div>
        <div style="display:flex;gap:8px;margin-top:8px">
          <button class="btn primary" id="btnMSave">Save</button>
          <button class="btn" id="btnMReset">Reset</button>

          <form method="post" action="" id="modCSV" style="margin-left:auto">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="export_modules_csv">
            <button class="btn">Export CSV</button>
          </form>
        </div>
        <div id="mMsg" class="smallmuted"></div>
      </div>

      <!-- Cases panel -->
      <div id="casesCard" class="card" style="display:none">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap">
          <h3 style="margin:0">Cases for: <span id="casesFor" class="smallmuted">—</span></h3>
          <div style="display:flex;gap:8px;align-items:center">
            <form id="autoFillForm" style="display:flex;gap:6px;align-items:center">
              <input type="number" id="autoCount" value="3" min="1" max="100" style="width:70px">
              <button class="btn soft small" id="btnAutoFill">Auto-fill</button>
            </form>
            <input type="file" id="caseFile" accept=".png,.jpg,.jpeg,.gif,.webp,.svg" style="display:none">
            <button class="btn soft small" id="btnCaseAdd">Add case</button>
          </div>
        </div>
        <div class="smallmuted" id="tableInfo"></div>
        <table id="tblCases">
          <thead>
            <tr>
              <th style="width:80px">ID</th>
              <th>Title</th>
              <th id="colLabel">Image</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>

      <!-- Spot the Phish -->
      <div class="card" role="region" aria-labelledby="spotTitle">
        <div class="card-head">
          <h3 class="card-title" id="spotTitle">Spot the Phish (Tasks)</h3>
          <div class="controls" style="margin:0;flex-wrap:wrap;gap:8px">
            <input id="sq" placeholder="Search tasks…">
            <select id="schannel">
              <option value="all">All channels</option>
              <option value="email">email</option>
              <option value="sms">sms</option>
              <option value="web">web</option>
            </select>
            <select id="stype">
              <option value="all">All types</option>
              <option value="phish">phish</option>
              <option value="legit">legit</option>
            </select>
            <button class="btn" id="sreload">Reload</button>
            <div style="margin-left:auto;display:flex;align-items:center;gap:8px">
              <button class="btn" id="sPrev">← Prev</button>
              <span class="smallmuted" id="sPageInfo">Page 1 of 1</span>
              <button class="btn" id="sNext">Next →</button>
              <button class="btn soft" id="btnSpotAdd">Add task</button>
            </div>
          </div>
        </div>

        <table id="tblSpot">
          <thead>
            <tr>
              <th style="width:80px">ID</th>
              <th>Title</th>
              <th>Channel</th>
              <th>Type</th>
              <th>Points</th>
              <th>Time</th>
              <th>Created</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>

      <!-- Module list -->
      <div class="card" role="region" aria-labelledby="modsTitle">
        <div class="card-head">
          <h3 class="card-title" id="modsTitle">Training Modules</h3>
        <div class="controls">
          <input id="q" placeholder="Search modules…">
          <select id="fChannel">
            <option value="all">All channels</option>
            <option value="email">email</option>
            <option value="sms">sms</option>
            <option value="web">web</option>
          </select>
          <select id="fStatus"><option value="all">All</option><option value="active">Active</option><option value="inactive">Inactive</option></select>
          <select id="order"><option value="newest">Newest</option><option value="name">Name A→Z</option></select>
          <select id="limit"><option>50</option><option>100</option><option>200</option></select>
          <button class="btn" id="reload">Reload</button>
        </div>
        </div>
        <table id="tblMods">
          <thead>
            <tr>
              <th style="width:80px">ID</th>
              <th>Title</th>
              <th>Channel</th>
              <th>Difficulty</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </section>
</div>

<!-- Case editor modal -->
<div id="caseModal" class="modal">
  <div class="box" role="dialog" aria-modal="true" aria-labelledby="caseTitleLbl">
    <button class="x" id="caseClose" type="button">Close</button>
    <div class="top"><strong id="caseTitleLbl">Case Editor</strong></div>
    <div>
      <div style="display:grid;grid-template-columns:2fr 1fr;gap:12px">
        <div>
          <label class="smallmuted">Title</label>
          <input id="cTitle" style="width:100%">
        </div>
        <div>
          <label class="smallmuted">Active</label>
          <select id="cActive" style="width:100%"><option value="1">Yes</option><option value="0">No</option></select>
        </div>
      </div>

      <div style="margin-top:8px">
        <label class="smallmuted" id="cImgLbl">Image URL (optional)</label>
        <div style="display:flex;gap:8px">
          <input id="cImg" style="flex:1">
          <button class="btn" id="btnUpload">Upload</button>
        </div>
      </div>

      <div style="margin-top:8px">
        <label class="smallmuted">Body / template (HTML allowed for email/web; plain for sms)</label>
        <textarea id="cBody" style="width:100%;min-height:220px"></textarea>
      </div>

      <div style="margin-top:8px">
        <label class="smallmuted">Feedback / expected answer (HTML shown after submit)</label>
        <textarea id="cExplain" style="width:100%;min-height:140px" placeholder="Example: &lt;p&gt;This email &lt;strong&gt;is phishing&lt;/strong&gt; because...&lt;/p&gt;"></textarea>
      </div>

      <div style="margin-top:10px;display:flex;justify-content:flex-end">
        <button class="btn primary" id="btnCaseSave" type="button">Save case</button>
      </div>
      <div id="cMsg" class="smallmuted"></div>
    </div>
  </div>
</div>

<!-- Spot editor modal -->
<div id="spotModal" class="modal">
  <div class="box" role="dialog" aria-modal="true" aria-labelledby="spotTitleLbl">
    <button class="x" id="spotClose" type="button">Close</button>
    <div class="top"><strong id="spotTitleLbl">Spot the Phish — Task</strong></div>
    <div>
      <div style="display:grid;grid-template-columns:2fr 120px 120px;gap:12px">
        <div>
          <label class="smallmuted">Title</label>
          <input id="sTitle" style="width:100%">
        </div>
        <div>
          <label class="smallmuted">Channel</label>
          <select id="sChannel" style="width:100%">
            <option value="email">email</option>
            <option value="sms">sms</option>
            <option value="web">web</option>
          </select>
        </div>
        <div>
          <label class="smallmuted">Type</label>
          <select id="sAnswer" style="width:100%">
            <option value="phish">phish</option>
            <option value="legit">legit</option>
          </select>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:8px">
        <div>
          <label class="smallmuted">From line</label>
          <input id="sFrom" style="width:100%" placeholder="e.g. Security Team &lt;security@company.com&gt;">
        </div>
        <div>
          <label class="smallmuted">Meta line</label>
          <input id="sMeta" style="width:100%" placeholder="e.g. (555) 123-4567 • via sendgrid-mail.com">
        </div>
      </div>

      <div style="margin-top:8px">
        <label class="smallmuted">Body (HTML allowed)</label>
        <textarea id="sBody" style="width:100%;min-height:220px"></textarea>
      </div>

      <div style="margin-top:8px">
        <label class="smallmuted">Correct rationale (why)</label>
        <textarea id="sWhy" style="width:100%;min-height:110px" placeholder="Short explanation learners see after submitting…"></textarea>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-top:8px">
        <div>
          <label class="smallmuted">Points (right)</label>
          <input id="sPR" type="number" value="6" style="width:100%">
        </div>
        <div>
          <label class="smallmuted">Points (wrong)</label>
          <input id="sPW" type="number" value="-2" style="width:100%">
        </div>
        <div>
          <label class="smallmuted">Time limit (sec)</label>
          <input id="sTL" type="number" value="30" style="width:100%">
        </div>
      </div>

      <div style="margin-top:8px">
        <label class="smallmuted">Image URL (optional)</label>
        <input id="sImg" style="width:100%" placeholder="Shown alongside the task (optional)">
      </div>

      <div style="margin-top:10px;display:flex;justify-content:flex-end;gap:8px">
        <button class="btn primary" id="btnSpotSave" type="button">Save task</button>
      </div>
      <div id="sMsg" class="smallmuted"></div>
    </div>
  </div>
</div>

<form id="hidden" style="display:none">
  <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
</form>

<script>
let currentCasesTable = '';
let editingModuleId = 0;
let editingCaseId = 0;
let lastFocusEl = null;
let sPage = 1;
const sPer = 5;
let sTotal = 0;

/* ===== helpers ===== */
const $=s=>document.querySelector(s), $$=s=>Array.from(document.querySelectorAll(s));
function api(action,payload={}){
  const fd = new FormData($('#hidden'));
  fd.append('action', action);
  for (const [k,v] of Object.entries(payload)) fd.append(k,v);
  return fetch('', {
    method: 'POST',
    body: fd,
    credentials: 'same-origin',
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(r => r.text())
  .then(t => { try { return JSON.parse(t); } catch(e){ return {ok:false,message:'Bad JSON'} } })
  .catch(e => ({ ok:false, message:String(e) }));
}

/* ===== case modal ===== */
function openCaseModal(){
  lastFocusEl = document.activeElement;
  $('#caseModal').classList.add('open');
  $('#appRoot').setAttribute('inert','');
  setTimeout(()=>$('#cTitle').focus(),0);
}
function closeCaseModal(){
  if (document.activeElement && $('#caseModal').contains(document.activeElement)) document.activeElement.blur();
  $('#caseModal').classList.remove('open'); $('#appRoot').removeAttribute('inert');
  if (lastFocusEl && document.body.contains(lastFocusEl)) lastFocusEl.focus();
}
$('#caseClose').onclick = closeCaseModal;
$('#caseModal').addEventListener('click', (e)=>{ if(e.target===e.currentTarget) closeCaseModal(); });
document.addEventListener('keydown',(e)=>{ if(e.key==='Escape' && $('#caseModal').classList.contains('open')) closeCaseModal(); });

/* ===== MODULE FORM ===== */
function resetModuleForm(){
  editingModuleId=0; $('#mTitle').value=''; $('#mChannel').value='email'; $('#mDiff').value='1';
  $('#mActive').value='1'; $('#mDesc').value=''; document.querySelector('input[name="case_storage"][value="default"]').checked = true;
  $('#storageHint').textContent=''; $('#mMsg').textContent=''; hideCases();
}
$('#btnMReset').onclick=resetModuleForm;

$('#btnMSave').onclick=async()=>{
  const storage = document.querySelector('input[name="case_storage"]:checked')?.value || 'default';
  const p={ id:editingModuleId, title:$('#mTitle').value.trim(), channel:$('#mChannel').value,
            description:$('#mDesc').value, difficulty:$('#mDiff').value, is_active:$('#mActive').value,
            case_storage: storage };
  const r=await api('tm_save',p);
  if(r.ok){
    $('#mMsg').textContent='Saved.';
    if(!editingModuleId) editingModuleId=r.id;
    loadModules();
    showCases(editingModuleId,$('#mTitle').value,$('#mChannel').value);
  } else { $('#mMsg').textContent=r.message||'Save failed'; }
};

/* ===== MODULE LIST ===== */
async function loadModules(){
  const r=await api('tm_list',{q:$('#q').value.trim(),channel:$('#fChannel').value,status:$('#fStatus').value,order:$('#order').value,limit:$('#limit').value});
  const tb=$('#tblMods tbody'); tb.innerHTML='';
  if(!r.ok){ tb.innerHTML='<tr><td colspan="6">Failed</td></tr>'; return; }
  r.rows.forEach(m=>{
    const tr=document.createElement('tr');
    tr.innerHTML=`
      <td>TM-${m.id}</td>
      <td>${m.title}</td>
      <td>${m.channel}</td>
      <td>${m.difficulty}</td>
      <td>${m.is_active==1?'<span class="badge on">active</span>':'<span class="badge off">inactive</span>'}</td>
      <td>
        <button class="btn small" data-edit="${m.id}">Edit</button>
        <button class="btn small" data-cases="${m.id}">Cases</button>
        <button class="btn small" data-toggle="${m.id}" data-to="${m.is_active==1?0:1}">${m.is_active==1?'Deactivate':'Activate'}</button>
        <button class="btn small danger" data-del="${m.id}">Delete</button>
      </td>`;
    tr.dataset.cases_table = m.cases_table || '';
    tb.appendChild(tr);
  });

  tb.querySelectorAll('button[data-edit]').forEach(b=>b.onclick=()=>startEditModule(+b.dataset.edit));
  tb.querySelectorAll('button[data-cases]').forEach(b=>b.onclick=()=>openCasesFor(+b.dataset.cases));
  tb.querySelectorAll('button[data-toggle]').forEach(b=>b.onclick=async()=>{await api('tm_toggle',{id:b.dataset.toggle,is_active:b.dataset.to}); loadModules();});
  tb.querySelectorAll('button[data-del]').forEach(b=>b.onclick=async()=>{if(!confirm('Delete module and its cases?'))return; await api('tm_delete',{id:b.dataset.del}); if(editingModuleId==+b.dataset.del) resetModuleForm(); loadModules();});
}
$('#reload').onclick=loadModules;
$('#q').addEventListener('keydown',e=>{ if(e.key==='Enter'){ e.preventDefault(); loadModules(); }});
$('#fChannel').onchange=loadModules; $('#fStatus').onchange=loadModules; $('#order').onchange=loadModules; $('#limit').onchange=loadModules;

async function startEditModule(id){
  const r=await api('tm_list',{q:'',channel:'all',status:'all',order:'newest',limit:300});
  const row=(r.rows||[]).find(x=>+x.id===+id);
  if(!row){ alert('Not found'); return; }
  editingModuleId=+row.id;
  $('#mTitle').value=row.title||''; $('#mChannel').value=row.channel||'email';
  $('#mDiff').value=row.difficulty||'1'; $('#mActive').value=row.is_active||'1'; $('#mDesc').value=row.description||'';
  const storageRadio = row.cases_table ? 'dedicated' : 'default';
  document.querySelector(`input[name="case_storage"][value="${storageRadio}"]`).checked = true;
  $('#storageHint').textContent = row.cases_table ? `Using table: ${row.cases_table}` : 'Using default pool for this channel.';
  window.scrollTo({top:0,behavior:'smooth'}); showCases(editingModuleId,row.title,row.channel);
}
function openCasesFor(id){ startEditModule(id); }

/* ===== CASES ===== */
function hideCases(){ $('#casesCard').style.display='none'; $('#casesFor').textContent='—'; $('#tblCases tbody').innerHTML=''; $('#tableInfo').textContent=''; }
async function showCases(moduleId,title,channel){
  $('#casesFor').textContent=`${title} (${channel})`; $('#casesCard').style.display='';
  await loadCases(moduleId);
}

async function loadCases(moduleId){
  const r = await api('cases_list', { module_id: moduleId });
  const tb = $('#tblCases tbody'); tb.innerHTML = '';
  if (!r.ok) { tb.innerHTML = '<tr><td colspan="5">Failed</td></tr>'; return; }

  currentCasesTable = r.cases_table || '';
  $('#tableInfo').textContent = currentCasesTable ? `Storage: ${currentCasesTable}` : `Storage: default pool (${r.channel})`;
  $('#colLabel').textContent = (currentCasesTable === 'sim_scenarios') ? 'URL' : 'Image';
  $('#cImgLbl').textContent  = (currentCasesTable === 'sim_scenarios') ? 'URL shown in browser bar' : 'Image URL (optional)';
  $('#btnUpload').style.display = (currentCasesTable === 'sim_scenarios') ? 'none' : '';

  (r.rows||[]).forEach(c=>{
    const tr=document.createElement('tr');
    let cell3 = '—';
    if (c.image_url) {
      if (currentCasesTable === 'sim_scenarios') {
        const safe = c.image_url.replace(/"/g,'&quot;');
        cell3 = `<a href="${safe}" target="_blank" rel="noopener noreferrer">${safe}</a>`;
      } else {
        const base = (document.body.dataset.base || '').replace(/\/$/, '');
        const qp = encodeURIComponent(c.image_url).replace(/%2F/gi, '/');
        const href = `${base}/image_proxy.php?p=${qp}`;
        const label = c.image_url.split('/').pop() || 'view';
        cell3 = `<a href="${href}" target="_blank" rel="noopener" title="${c.image_url}">${label}</a>`;
      }
    }
    tr.innerHTML = `
      <td>${c.id}</td>
      <td>${c.title || ''}</td>
      <td>${cell3}</td>
      <td>${+c.is_active === 1 ? '<span class="badge on">active</span>' : '<span class="badge off">inactive</span>'}</td>
      <td>
        <button class="btn small" data-cedit="${c.id}">Edit</button>
        <button class="btn small danger" data-cdel="${c.id}">Delete</button>
      </td>`;
    tb.appendChild(tr);
  });

  tb.querySelectorAll('button[data-cedit]').forEach(b=>b.onclick = () => editCase(+b.dataset.cedit));
  tb.querySelectorAll('button[data-cdel]').forEach(b=>b.onclick = async () => {
    if (!confirm('Delete case?')) return;
    await api('case_delete', { id: b.dataset.cdel, module_id: editingModuleId });
    loadCases(editingModuleId);
  });
}

$('#btnCaseAdd').onclick = () => {
  editingCaseId = 0; 
  $('#cTitle').value = ''; 
  $('#cBody').value = ''; 
  $('#cImg').value = ''; 
  $('#cActive').value = '1'; 
  $('#cExplain').value = '';
  $('#cMsg').textContent = '';
  openCaseModal();
};

async function editCase(id){
  const r=await api('case_get',{id, module_id:editingModuleId});
  if(r.ok && r.row){
    editingCaseId=+id; 
    $('#cTitle').value   = r.row.title || '';
    $('#cBody').value    = r.row.body || '';
    $('#cImg').value     = r.row.image_url || '';
    $('#cActive').value  = r.row.is_active || '1';
    $('#cExplain').value = r.row.explain_html || '';
    $('#cMsg').textContent='';
    openCaseModal();
  }
}

$('#btnUpload').onclick=async(e)=>{
  e.preventDefault();
  if (currentCasesTable === 'sim_scenarios') return; // not applicable
  const input=$('#caseFile');
  input.onchange=async()=>{
    const f=input.files[0]; if(!f) return;
    const fd=new FormData($('#hidden')); fd.append('action','upload_image'); fd.append('file',f);
    const r=await fetch('',{method:'POST',body:fd}).then(x=>x.json()).catch(()=>({ok:false}));
    if(r.ok){ $('#cImg').value=r.url; } else { alert(r.message||'Upload failed'); }
    input.value='';
  };
  input.click();
};

$('#btnCaseSave').onclick=async()=>{
  const p={ 
    id:editingCaseId, 
    module_id:editingModuleId, 
    title:$('#cTitle').value.trim(),
    body:$('#cBody').value, 
    image_url:$('#cImg').value.trim(), 
    is_active:$('#cActive').value,
    explain_html: $('#cExplain').value
  };
  const r=await api('case_save',p);
  if(r.ok){ $('#cMsg').textContent='Saved.'; closeCaseModal(); loadCases(editingModuleId); }
  else    { $('#cMsg').textContent=r.message||'Save failed'; }
};

/* Auto-fill */
$('#autoFillForm').addEventListener('submit', async (e)=>{
  e.preventDefault();
  const n = Math.max(1, parseInt($('#autoCount').value||'3',10));
  const r = await api('auto_fill_cases',{ module_id: editingModuleId, count: n });
  if(!r.ok){ alert(r.message||'Auto-fill failed'); return; }
  loadCases(editingModuleId);
});

/* ===== SPOT THE PHISH (CRUD) ===== */
let spotEditingId = 0;

async function loadSpot(){
  const r = await api('spot_list',{
    q: $('#sq').value.trim(),
    channel: $('#schannel').value,
    type: $('#stype').value,
    page: sPage,
    per_page: sPer
  });

  const tb = $('#tblSpot tbody'); tb.innerHTML='';
  if(!r.ok){ tb.innerHTML = '<tr><td colspan="8">Failed</td></tr>'; return; }

  sTotal = r.total || 0;
  const rows = r.rows || [];
  rows.forEach(row=>{
    const tr=document.createElement('tr');
    const pts=`${row.points_right}/${row.points_wrong}`;
    tr.innerHTML=`
      <td>${row.id}</td>
      <td>${row.title||''}</td>
      <td>${row.channel}</td>
      <td>${row.correct_answer}${row.is_phish==1?' (phish)':''}</td>
      <td>${pts}</td>
      <td>${row.time_limit_sec||30}s</td>
      <td>${row.created_at||''}</td>
      <td>
        <button class="btn small" data-sedit="${row.id}">Edit</button>
        <button class="btn small danger" data-sdel="${row.id}">Delete</button>
      </td>`;
    tb.appendChild(tr);
  });

  tb.querySelectorAll('button[data-sedit]').forEach(b=>b.onclick=()=>editSpot(+b.dataset.sedit));
  tb.querySelectorAll('button[data-sdel]').forEach(b=>b.onclick=async()=>{
    if(!confirm('Delete this task?')) return;
    await api('spot_delete',{id:+b.dataset.sdel});
    const lastPage = Math.max(1, Math.ceil((sTotal-1)/sPer));
    if (sPage > lastPage) sPage = lastPage;
    loadSpot();
  });

  const totalPages = Math.max(1, Math.ceil(sTotal / sPer));
  $('#sPageInfo').textContent = `Page ${Math.min(sPage,totalPages)} of ${totalPages}`;
  $('#sPrev').disabled = (sPage <= 1);
  $('#sNext').disabled = (sPage >= totalPages);
}

// listeners
$('#sreload').onclick = ()=>{ sPage = 1; loadSpot(); };
$('#sq').addEventListener('keydown',e=>{ if(e.key==='Enter'){ e.preventDefault(); sPage=1; loadSpot(); }});
$('#schannel').onchange = ()=>{ sPage=1; loadSpot(); };
$('#stype').onchange   = ()=>{ sPage=1; loadSpot(); };

$('#sPrev').onclick = ()=>{ if(sPage>1){ sPage--; loadSpot(); } };
$('#sNext').onclick = ()=>{
  const totalPages = Math.max(1, Math.ceil(sTotal / sPer));
  if(sPage < totalPages){ sPage++; loadSpot(); }
};

function openSpotModal(){
  lastFocusEl = document.activeElement;
  $('#spotModal').classList.add('open');
  $('#appRoot').setAttribute('inert','');
  setTimeout(()=>$('#sTitle').focus(),0);
}
function closeSpotModal(){
  if (document.activeElement && $('#spotModal').contains(document.activeElement)) document.activeElement.blur();
  $('#spotModal').classList.remove('open'); $('#appRoot').removeAttribute('inert');
  if (lastFocusEl && document.body.contains(lastFocusEl)) lastFocusEl.focus();
}
$('#spotClose').onclick=closeSpotModal;
$('#spotModal').addEventListener('click',(e)=>{ if(e.target===e.currentTarget) closeSpotModal(); });
document.addEventListener('keydown',(e)=>{ if(e.key==='Escape' && $('#spotModal').classList.contains('open')) closeSpotModal(); });

$('#btnSpotAdd').onclick=()=>{
  spotEditingId=0;
  $('#sTitle').value=''; $('#sChannel').value='email'; $('#sAnswer').value='phish';
  $('#sFrom').value=''; $('#sMeta').value=''; $('#sBody').value=''; $('#sWhy').value='';
  $('#sPR').value='6'; $('#sPW').value='-2'; $('#sTL').value='30'; $('#sImg').value='';
  $('#sMsg').textContent='';
  openSpotModal();
};

async function editSpot(id){
  const r=await api('spot_get',{id});
  if(!r.ok || !r.row){ alert('Not found'); return; }
  const x=r.row;
  spotEditingId=+x.id;
  $('#sTitle').value=x.title||'';
  $('#sChannel').value=x.channel||'email';
  $('#sAnswer').value=x.correct_answer||'phish';
  $('#sFrom').value=x.from_line||'';
  $('#sMeta').value=x.meta_line||'';
  $('#sBody').value=x.body_html||'';
  $('#sWhy').value=x.correct_rationale||'';
  $('#sPR').value=(x.points_right??6);
  $('#sPW').value=(x.points_wrong??-2);
  $('#sTL').value=(x.time_limit_sec??30);
  $('#sImg').value=x.image_url||'';
  $('#sMsg').textContent='';
  openSpotModal();
}

$('#btnSpotSave').onclick=async()=>{
  const p={
    id: spotEditingId,
    channel: $('#sChannel').value,
    title: $('#sTitle').value.trim(),
    from_line: $('#sFrom').value.trim(),
    meta_line: $('#sMeta').value.trim(),
    body_html: $('#sBody').value,
    correct_answer: $('#sAnswer').value,
    is_phish: $('#sAnswer').value==='phish'?1:0,
    correct_rationale: $('#sWhy').value,
    points_right: $('#sPR').value||6,
    points_wrong: $('#sPW').value||-2,
    time_limit_sec: $('#sTL').value||30,
    image_url: $('#sImg').value.trim()
  };
  const r=await api('spot_save',p);
  if(r.ok){ $('#sMsg').textContent='Saved.'; closeSpotModal(); loadSpot(); }
  else    { $('#sMsg').textContent=r.message||'Save failed'; }
};

/* ===== INIT ===== */
(function(){ 
  resetModuleForm(); 
  loadModules(); 
  loadSpot(); 
})();
</script>
</body>
</html>

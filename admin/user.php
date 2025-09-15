<?php
// /admin/users.php  — light theme to match index.php
declare(strict_types=1);
require_once __DIR__ . '/boot.php';

admin_require_login();
$base = admin_base();

/* ---------- tiny helpers ---------- */
function tbl_exists(PDO $pdo, string $t): bool {
  $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name=?");
  $q->execute([$t]);
  return (int)$q->fetchColumn() > 0;
}


/* ===== Device-binding helpers (add this) ===== */

/** Ensure the devices table exists (shape compatible with binding). */
function ensure_devices_table(PDO $pdo): void {
  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS devices (
      device_hash CHAR(64) PRIMARY KEY,
      first_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      registered_user_id INT NULL,
      registered_at DATETIME NULL,
      KEY (registered_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  } catch (Throwable $e) { /* ignore on shared hosts */ }
}

/**
 * Unbind devices for a user.
 * If $deviceHash is null, unbinds ALL this user’s devices.
 * Returns number of affected rows.
 */
function release_devices(PDO $pdo, int $userId, ?string $deviceHash=null): int {
  ensure_devices_table($pdo);

  $where = 'registered_user_id = ?';
  $params = [$userId];

  if ($deviceHash !== null && $deviceHash !== '') {
    $where .= ' AND device_hash = ?';
    $params[] = $deviceHash;
  }

  // Unpair (preserve row/history)
  $sql = "UPDATE devices
             SET registered_user_id = NULL,
                 registered_at = NULL
           WHERE $where";

  $st = $pdo->prepare($sql);
  $st->execute($params);
  return (int)$st->rowCount();
}


/* ---------- AJAX actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json; charset=UTF-8');
  $csrfRaw = $_POST['csrf'] ?? '';
  $isAjax  = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
  $csrfOk  = admin_verify_csrf($csrfRaw) || ($isAjax && admin_logged_in());
  if (!$csrfOk) { echo json_encode(['ok'=>false,'message'=>'Bad CSRF']); exit; }
  try { $pdo->exec("SET time_zone = '+00:00'"); } catch(Throwable $e){}

  $act = $_POST['action'] ?? '';

  try {
    switch ($act) {

      case 'users_list': {
        // filters
        $q      = trim((string)($_POST['q'] ?? ''));
        $role   = ($_POST['role'] ?? 'all') === 'admin' ? 'admin' : (($_POST['role'] ?? 'all') === 'user' ? 'user' : 'all');
        $locked = $_POST['locked'] ?? 'any'; // any|1|0
        $order  = $_POST['order']  ?? 'newest'; // newest|oldest|xp|name
        $limit  = max(5, min(100, (int)($_POST['limit'] ?? 25)));
        $page   = max(1, (int)($_POST['page'] ?? 1));
        $off    = ($page-1)*$limit;

        // base where (duplicate named params are not supported with native prepares)
        $where = ["(u.username LIKE :q1 OR u.email LIKE :q2)"];
        $bind  = [':q1'=>"%$q%", ':q2'=>"%$q%"];

        if ($role !== 'all') { $where[] = "u.role = :role";  $bind[':role'] = $role; }
        if ($locked === '1') { $where[] = "u.is_locked = 1"; }
        if ($locked === '0') { $where[] = "u.is_locked = 0"; }

        $whereSql = $where ? ('WHERE '.implode(' AND ',$where)) : '';

        // order
        $orderSql = "ORDER BY u.id DESC";
        if ($order === 'oldest') $orderSql = "ORDER BY u.id ASC";
        if ($order === 'name')   $orderSql = "ORDER BY u.username ASC";
        if ($order === 'xp')     $orderSql = "ORDER BY total_xp DESC";

        // accuracy (7d) + last_seen derived tables if sessions table exists
        $accJoin  = '';
        $seenJoin = '';
        if (tbl_exists($pdo, 'user_spot_sessions')) {
          $accJoin  = "LEFT JOIN (
              SELECT user_id,
                     SUM(CASE WHEN started_at >= NOW() - INTERVAL 7 DAY THEN 1 ELSE 0 END) AS attempts7,
                     SUM(CASE WHEN is_correct=1 AND started_at >= NOW() - INTERVAL 7 DAY THEN 1 ELSE 0 END) AS correct7
              FROM user_spot_sessions
              GROUP BY user_id
            ) a ON a.user_id = u.id";
          $seenJoin = "LEFT JOIN (
              SELECT user_id, MAX(COALESCE(submitted_at, started_at)) AS last_seen
              FROM user_spot_sessions
              GROUP BY user_id
            ) s ON s.user_id = u.id";
        }

        // XP sum
        $xpJoin = "LEFT JOIN (
            SELECT user_id, COALESCE(SUM(points),0) AS total_xp
            FROM user_xp
            GROUP BY user_id
          ) x ON x.user_id = u.id";

        // total count for pagination
        $stc = $pdo->prepare("SELECT COUNT(*) FROM users u $whereSql");
        $stc->execute($bind);
        $total = (int)$stc->fetchColumn();

        $sql = "
          SELECT
            u.id, u.username, u.email, u.role, u.is_locked,
            DATE_FORMAT(u.created_at,'%Y-%m-%d %H:%i') AS created_at,
            COALESCE(x.total_xp,0) AS total_xp,
            ".($accJoin? "COALESCE(a.attempts7,0) AS attempts7, COALESCE(a.correct7,0) AS correct7," : "0 AS attempts7, 0 AS correct7,")."
            ".($seenJoin? "DATE_FORMAT(s.last_seen,'%Y-%m-%d %H:%i') AS last_seen" : "NULL AS last_seen")."
          FROM users u
          $xpJoin
          $accJoin
          $seenJoin
          $whereSql
          $orderSql
          LIMIT :lim OFFSET :off";

        $st = $pdo->prepare($sql);
        foreach ($bind as $k=>$v) $st->bindValue($k,$v);
        $st->bindValue(':lim',$limit,PDO::PARAM_INT);
        $st->bindValue(':off',$off,PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll();

        echo json_encode(['ok'=>true,'rows'=>$rows,'total'=>$total,'page'=>$page,'limit'=>$limit]); exit;
      }

      case 'user_promote': {
        $uid=(int)$_POST['user_id']; $role = ($_POST['role']??'user')==='admin'?'admin':'user';
        $pdo->prepare("UPDATE users SET role=? WHERE id=?")->execute([$role,$uid]);
        echo json_encode(['ok'=>true]); exit;
      }

      case 'user_lock': {
        $uid=(int)$_POST['user_id']; $locked=(int)($_POST['locked']??0);
        $pdo->prepare("UPDATE users SET is_locked=? WHERE id=?")->execute([$locked,$uid]);
        echo json_encode(['ok'=>true]); exit;
      }

      case 'user_reset_xp': {
        $uid=(int)$_POST['user_id'];
        $pdo->beginTransaction();
        try {
          if (tbl_exists($pdo,'user_xp'))           $pdo->prepare("DELETE FROM user_xp WHERE user_id=?")->execute([$uid]);
          if (tbl_exists($pdo,'user_task_runs'))    $pdo->prepare("DELETE FROM user_task_runs WHERE user_id=?")->execute([$uid]);
          if (tbl_exists($pdo,'user_points'))       $pdo->prepare("UPDATE user_points SET points=0 WHERE user_id=?")->execute([$uid]);
          if (tbl_exists($pdo,'user_streaks'))      $pdo->prepare("UPDATE user_streaks SET streak_current=0 WHERE user_id=?")->execute([$uid]);
          $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        echo json_encode(['ok'=>true]); exit;
      }


            case 'user_reset_modules': {
        // Wipe per-module PROGRESS for this user (does NOT touch XP)
        $uid = (int)$_POST['user_id'];
        $pdo->beginTransaction();
        try {
          // standard progress tables used by your 3 modules
          foreach (['training_mail_progress','training_sms_progress','training_web_progress'] as $t) {
            if (tbl_exists($pdo, $t)) {
              $pdo->prepare("DELETE FROM `$t` WHERE user_id = ?")->execute([$uid]);
            }
          }
          // optional logs created by the web/sandbox player
          foreach (['training_sandbox_log'] as $t) {
            if (tbl_exists($pdo, $t)) {
              $pdo->prepare("DELETE FROM `$t` WHERE user_id = ?")->execute([$uid]);
            }
          }

          $pdo->commit();
        } catch (Throwable $e) {
          if ($pdo->inTransaction()) $pdo->rollBack();
          throw $e;
        }
        echo json_encode(['ok'=>true]); exit;
      }


      case 'user_reset_daily_spot': {
  $uid = (int)$_POST['user_id'];
  try { $pdo->exec("SET time_zone = '+00:00'"); } catch (Throwable $e) {}

  $pdo->beginTransaction();
  try {
    // 1) Kill any still-open attempts for this user
    if (tbl_exists($pdo, 'spot_attempts')) {
      $pdo->prepare("
        DELETE FROM spot_attempts
         WHERE user_id = ?
           AND submitted_at IS NULL
      ")->execute([$uid]);
    }

    // 2) Remove attempts inside the 24h lock window (so overlay unlocks)
    if (tbl_exists($pdo, 'spot_attempts')) {
      $pdo->prepare("
        DELETE FROM spot_attempts
         WHERE user_id = ?
           AND attempted_at >= UTC_TIMESTAMP() - INTERVAL 1 DAY
      ")->execute([$uid]);
    }

    // 3) Optional: if you keep a sessions table, clear today’s rows too
    if (tbl_exists($pdo, 'user_spot_sessions')) {
      $pdo->prepare("
        DELETE FROM user_spot_sessions
         WHERE user_id = ?
           AND COALESCE(submitted_at, started_at) >= UTC_TIMESTAMP() - INTERVAL 1 DAY
      ")->execute([$uid]);
    }

    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }

  echo json_encode(['ok'=>true]); exit;
}



      case 'user_delete': {
        $uid=(int)$_POST['user_id'];
        $pdo->beginTransaction();
        try {
          foreach (['user_xp','user_task_runs','user_spot_sessions','user_points','user_streaks'] as $t) {
            if (tbl_exists($pdo,$t)) $pdo->prepare("DELETE FROM $t WHERE user_id=?")->execute([$uid]);
          }
          // also unbind any devices tied to this user BEFORE deleting the user row
release_devices($pdo, $uid, null);

          $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$uid]);
          $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        echo json_encode(['ok'=>true]); exit;
      }

      case 'user_release_devices': {
  // Unbind ALL devices for a single user (admin action button calls this)
  $uid = (int)$_POST['user_id'];
  $affected = release_devices($pdo, $uid, null);
  echo json_encode(['ok'=>true,'affected'=>$affected]); exit;
}


      case 'export_users_csv': {
        header_remove('Content-Type');
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="users.csv"');
        $out=fopen('php://output','w');
        fputcsv($out,['id','username','email','role','locked','total_xp','last_seen','created_at']);
        $sql="SELECT u.id,u.username,u.email,u.role,u.is_locked,
                     COALESCE(x.total_xp,0) AS total_xp,
                     ".(tbl_exists($pdo,'user_spot_sessions')?"DATE_FORMAT(s.last_seen,'%Y-%m-%d %H:%i')":"NULL")." AS last_seen,
                     DATE_FORMAT(u.created_at,'%Y-%m-%d %H:%i') AS created_at
              FROM users u
              LEFT JOIN (SELECT user_id, COALESCE(SUM(points),0) AS total_xp FROM user_xp GROUP BY user_id) x ON x.user_id=u.id
              ".(tbl_exists($pdo,'user_spot_sessions')?"LEFT JOIN (SELECT user_id, MAX(COALESCE(submitted_at,started_at)) AS last_seen FROM user_spot_sessions GROUP BY user_id) s ON s.user_id=u.id":"")."
              ORDER BY u.id";
        foreach($pdo->query($sql) as $r) fputcsv($out,$r);
        fclose($out); exit;
      }

      default:
        echo json_encode(['ok'=>false,'message'=>'Unknown action']); exit;
    }
  } catch (Throwable $e) {
    echo json_encode(['ok'=>false,'message'=>'Users action failed','debug'=>$e->getMessage()]); exit;
  }
}

$csrf = admin_csrf_token();
$adminName = htmlspecialchars($_SESSION['admin']['username'] ?? 'admin');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Users · PhishGuard Admin</title>
<link rel="icon" type="image/svg+xml"
        href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='12' fill='%236d28d9'/%3E%3Cpath d='M18 38l14-20 14 20-14 8z' fill='white'/%3E%3C/svg%3E">

<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
/* ===== Light theme (matches index.php) ===== */
:root{
  --bg:#f8fafc;        /* page backdrop */
  --panel:#ffffff;     /* cards */
  --ring:#e5e7eb;      /* borders */
  --text:#0f172a;      /* headings/body */
  --muted:#64748b;     /* secondary text */
  --accent:#6366f1;    /* indigo */
  --accent-soft:#eef2ff;
  --danger:#ef4444;
  --danger-soft:#fee2e2;
  --ok:#10b981;
}
.lnk{color:#1f2937;text-decoration:none;border-bottom:1px dotted #94a3b8}
.lnk:hover{color:#111827;border-bottom-color:#111827}

*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font:14px/1.45 system-ui,Segoe UI,Roboto,Arial}
a{color:inherit}

/* layout */
.wrap{display:grid;grid-template-columns:240px 1fr;min-height:100vh}
.sidebar{background:#0f172a;color:#e5e7eb;padding:16px 12px}
.brand{display:flex;align-items:center;gap:10px;font-weight:700;margin-bottom:8px}
.brand .dot{width:24px;height:24px;border-radius:8px;background:linear-gradient(135deg,#4f46e5,#06b6d4)}
.nav a{display:block;color:#cbd5e1;padding:10px 12px;border-radius:8px;text-decoration:none}
.nav a.active,.nav a:hover{background:#111827;color:#fff}
.header{display:flex;align-items:center;gap:12px;justify-content:space-between;padding:14px 18px;border-bottom:1px solid var(--ring);background:var(--panel)}
.header .badge{padding:6px 10px;border:1px solid var(--ring);border-radius:999px;background:#fff;color:#111827}
.main{padding:18px;}

/* card & controls */
.card{background:var(--panel);border:1px solid var(--ring);border-radius:12px;padding:14px}
.controls{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px}
input,select,button{border:1px solid var(--ring);background:#fff;color:var(--text);padding:8px 10px;border-radius:8px}
button{cursor:pointer}
.btn{background:#fff}
.btn.primary{background:var(--accent);border-color:var(--accent);color:#fff}
.btn.soft{background:var(--accent-soft);border-color:var(--accent-soft);color:#3730a3}
.btn.danger{background:var(--danger);border-color:var(--danger);color:#fff}
.btn.small{padding:6px 10px;font-size:12px;border-radius:7px}

/* table */
table{width:100%;border-collapse:collapse;margin-top:8px}
th,td{padding:10px 8px;border-bottom:1px solid var(--ring);text-align:left;vertical-align:middle}
th{font-weight:600;color:#334155}
.badge-role{display:inline-block;padding:2px 8px;border-radius:999px;border:1px solid var(--ring);background:#fff}
.badge-role.admin{background:var(--accent-soft);border-color:var(--accent-soft);color:#3730a3}
.badge-pill{padding:2px 8px;border-radius:999px;border:1px solid var(--ring);color:#475569}
.badge-lock{background:#fff}
.muted{color:var(--muted)}
/* Actions cell – keep everything on one line */
.actions{
  display:flex;
  align-items:center;
  gap:8px;            /* even spacing between buttons */
  white-space:nowrap; /* prevent wrapping to a new line */
}
.actions .btn{
  margin:0;           /* gap handles spacing */
  white-space:nowrap; /* keep button labels on one line */
}

/* (optional) slightly smaller buttons to fit more across */
.btn.small{padding:6px 10px;font-size:12px;border-radius:7px}

/* Responsive fallback: allow wrap on very narrow screens */
@media (max-width: 900px){
  .actions{flex-wrap:wrap}
}


/* pagination */
.pager{display:flex;gap:8px;align-items:center;justify-content:flex-end;margin-top:10px;color:var(--muted)}

/* bulk row */
.bulk{display:flex;gap:8px;align-items:center;margin-top:6px}
</style>
</head>
<body data-base="<?= htmlspecialchars($base) ?>">
<div class="wrap">
  <!-- sidebar -->
  <aside class="sidebar">
    <div class="brand"><div class="dot"></div> PhishGuard</div>
    <div class="nav">
      <a href="<?= htmlspecialchars($base) ?>/index.php">Dashboard</a>
      <a href="<?= htmlspecialchars($base) ?>/user.php" class="active">Users</a>
      <a href="<?= htmlspecialchars($base) ?>/campaigns.php">Modules</a>
      <!-- <a href="<?= htmlspecialchars($base) ?>/index.php#templates">Templates</a> -->
      <a href="<?= htmlspecialchars($base) ?>/reports.php">Reports</a>
      <!-- <a href="<?= htmlspecialchars($base) ?>/index.php#settings">Settings</a> -->
       <a href="<?= htmlspecialchars($base) ?>/paired.php">Paired Evaluation</a>
    </div>
  </aside>

  <section>
    <!-- header -->
    <div class="header">
      <h2 style="margin:0">Users</h2>
      <div>
        <span class="badge">Signed in as <?= $adminName ?></span>
        <a class="btn" href="<?= htmlspecialchars($base) ?>/logout.php">Logout</a>
      </div>
    </div>

    <!-- main -->
    <div class="main">
      <div class="card">

        <div class="controls">
          <input id="q" placeholder="Search username or email…">
          <select id="role">
            <option value="all">All roles</option>
            <option value="admin">Admins only</option>
            <option value="user">Users only</option>
          </select>
          <select id="locked">
            <option value="any">Lock status</option>
            <option value="1">Locked</option>
            <option value="0">Not locked</option>
          </select>
          <select id="order">
            <option value="newest">Newest</option>
            <option value="oldest">Oldest</option>
            <option value="xp">Most XP</option>
            <option value="name">Name A→Z</option>
          </select>
          <select id="limit">
            <option>25</option><option>50</option><option>100</option>
          </select>
          <button class="btn" id="reload">Reload</button>

          <form method="post" action="" id="csvForm" style="margin-left:auto">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="export_users_csv">
            <button class="btn">Export CSV</button>
          </form>
        </div>

        <div class="bulk">
          <label class="muted">Bulk action…</label>
          <select id="bulkAction">
            <option value="">Select…</option>
            <option value="promote">Promote to admin</option>
            <option value="demote">Demote to user</option>
            <option value="lock">Lock accounts</option>
            <option value="unlock">Unlock accounts</option>
            <option value="reset">Reset XP</option>
            <option value="resetmods">Reset module progress</option>
            <option value="resetdaily">Unlock daily spot</option>
            <option value="delete">Delete accounts</option>
            <option value="devrelease">Release device bindings</option>

          </select>
          <button class="btn small" id="bulkApply">Apply</button>
        </div>

        <table id="tbl">
          <thead>
            <tr>
              <th><input type="checkbox" id="chkAll"></th>
              <th>ID</th>
              <th>User</th>
              <th>Email</th>
              <th>Role</th>
              <th>Locked</th>
              <th>Total XP</th>
              <th>7-day accuracy</th>
              <th>Last seen</th>
              <!-- <th>Joined</th> -->
              <th>Actions</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>

        <div class="pager">
          <span id="pgInfo">—</span>
          <button class="btn" id="prev">Prev</button>
          <button class="btn" id="next">Next</button>
        </div>

      </div>
    </div>
  </section>
</div>

<form id="hidden" style="display:none">
  <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
</form>

<script>
const $=s=>document.querySelector(s), $$=s=>Array.from(document.querySelectorAll(s));
function api(action, payload={}) {
  const fd = new FormData($('#hidden'));
  fd.append('action', action);
  for (const [k,v] of Object.entries(payload)) fd.append(k, v);
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

/* state */
let S = {page:1,total:0,limit:25};

function fmtAcc(a,c){
  a=+a||0; c=+c||0; if(!a) return '—';
  return Math.round((c*100)/a) + '%';
}
function fmtLock(x){return +x===1 ? '<span class="badge-pill badge-lock">Yes</span>' : 'No';}

async function load(){
  S.limit = +$('#limit').value;
  const res = await api('users_list',{
    q:$('#q').value.trim(),
    role:$('#role').value,
    locked:$('#locked').value,
    order:$('#order').value,
    limit:S.limit,
    page:S.page
  });
  const tb = $('#tbl tbody'); tb.innerHTML='';
  if(!res.ok){ tb.innerHTML = `<tr><td colspan="11">Failed to load</td></tr>`; return; }

  S.total = res.total;
  const rows = res.rows || [];
  for (const u of rows){
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td><input type="checkbox" class="chk" data-id="${u.id}"></td>
      <td>${u.id}</td>
      <td><a class="lnk" href="user_profile.php?uid=${u.id}">${u.username ?? ''}</a></td>
      <td><a class="lnk" href="user_profile.php?uid=${u.id}">${u.email ?? ''}</a></td>
      <td><span class="badge-role ${u.role==='admin'?'admin':''}">${u.role}</span></td>
      <td>${fmtLock(u.is_locked)}</td>
      <td>${u.total_xp ?? 0}</td>
      <td>${fmtAcc(u.attempts7,u.correct7)}</td>
      <td>${u.last_seen ?? '—'}</td>
      
      <td class="actions">
        ${u.role==='admin'
          ? `<button class="btn small" data-a="promote" data-id="${u.id}" data-role="user">Demote</button>`
          : `<button class="btn small" data-a="promote" data-id="${u.id}" data-role="admin">Promote</button>`}
        <button class="btn small" data-a="lock" data-id="${u.id}" data-locked="${u.is_locked?0:1}">${u.is_locked?'Unlock':'Lock'}</button>
        <button class="btn small" data-a="reset" data-id="${u.id}">Reset XP</button>
        <button class="btn small" data-a="resetmod" data-id="${u.id}">Reset progress</button>
          <!-- NEW: unlock daily spot -->
  <button class="btn small" data-a="resetdaily" data-id="${u.id}">Reset daily</button>
  <button class="btn small" data-a="devrelease" data-id="${u.id}">Release devices</button>

        <button class="btn small danger" data-a="delete" data-id="${u.id}">Delete</button>
      </td>
    `;
    tb.appendChild(tr);
  }

  // actions
  tb.querySelectorAll('button[data-a="promote"]').forEach(b=>b.onclick=async()=>{
    await api('user_promote',{user_id:b.dataset.id,role:b.dataset.role}); load();
  });
  tb.querySelectorAll('button[data-a="lock"]').forEach(b=>b.onclick=async()=>{
    await api('user_lock',{user_id:b.dataset.id,locked:b.dataset.locked}); load();
  });
  tb.querySelectorAll('button[data-a="reset"]').forEach(b=>b.onclick=async()=>{
    if(!confirm('Reset XP for this user?')) return;
    await api('user_reset_xp',{user_id:b.dataset.id}); load();
  });

  tb.querySelectorAll('button[data-a="resetmod"]').forEach(b=>b.onclick=async()=>{
    if(!confirm('Reset ALL module progress for this user? This marks all cases incomplete.')) return;
    await api('user_reset_modules',{user_id:b.dataset.id});
    load();
    });

  tb.querySelectorAll('button[data-a="delete"]').forEach(b=>b.onclick=async()=>{
    if(!confirm('Delete this user and their data?')) return;
    await api('user_delete',{user_id:b.dataset.id}); load();
  });

  tb.querySelectorAll('button[data-a="resetdaily"]').forEach(b => b.onclick = async () => {
  if (!confirm("Unlock the user’s daily Spot-the-Phish set now?")) return;
  const res = await api('user_reset_daily_spot', { user_id: b.dataset.id });
  if (!res.ok) { alert('Reset failed'); return; }
  alert('Daily set unlocked.');
  // no need to reload the table, but you can:
  // load();
});


// NEW: per-user release devices (unbind all devices for that user)
tb.querySelectorAll('button[data-a="devrelease"]').forEach(b => b.onclick = async () => {
  if (!confirm('Release all device bindings for this user? They can register again on the same device(s).')) return;

  // Optional: give quick UI feedback
  const prev = b.textContent;
  b.disabled = true; b.textContent = 'Releasing…';

  const res = await api('user_release_devices', { user_id: b.dataset.id });

  b.disabled = false; b.textContent = prev;

  if (!res.ok) {
    alert(res.message || 'Failed to release devices');
    return;
  }
  alert(`Released (unbound) ${res.affected || 0} device(s).`);
  // No need to reload, but do it if you want to refresh last_seen etc.
  // load();
});



  // pager
  const pages = Math.max(1, Math.ceil(S.total / S.limit));
  $('#pgInfo').textContent = `Page ${S.page} of ${pages} · ${S.total} users`;
  $('#prev').disabled = (S.page<=1);
  $('#next').disabled = (S.page>=pages);
}

$('#reload').onclick = ()=>{ S.page=1; load(); };
$('#q').addEventListener('keydown', e=>{ if(e.key==='Enter'){ e.preventDefault(); S.page=1; load(); }});
$('#limit').onchange = ()=>{ S.page=1; load(); };
$('#role').onchange = ()=>{ S.page=1; load(); };
$('#locked').onchange = ()=>{ S.page=1; load(); };
$('#order').onchange = ()=>{ S.page=1; load(); };

$('#prev').onclick = ()=>{ if(S.page>1){ S.page--; load(); } };
$('#next').onclick = ()=>{ S.page++; load(); };

// bulk helpers
$('#chkAll').onclick = ()=> $$('.chk').forEach(c=> c.checked=$('#chkAll').checked);
$('#bulkApply').onclick = async ()=>{
  const ids = $$('.chk:checked').map(c=>c.dataset.id);
  const act = $('#bulkAction').value;
  if (!act || ids.length===0) return;

  if (act==='delete' && !confirm(`Delete ${ids.length} account(s)?`)) return;
  if (act==='reset'  && !confirm(`Reset XP for ${ids.length} account(s)?`)) return;
  if (act==='resetmods' && !confirm(`Reset module progress for ${ids.length} account(s)?`)) return;

  for (const id of ids) {
    if (act==='promote') await api('user_promote',{user_id:id,role:'admin'});
    if (act==='demote')  await api('user_promote',{user_id:id,role:'user'});
    if (act==='lock')    await api('user_lock',{user_id:id,locked:1});
    if (act==='unlock')  await api('user_lock',{user_id:id,locked:0});
    if (act==='reset')   await api('user_reset_xp',{user_id:id});
    if (act==='resetmods') await api('user_reset_modules',{user_id:id});
    if (act === 'resetdaily') await api('user_reset_daily_spot', { user_id: id });
    if (act === 'devrelease') await api('user_release_devices', { user_id: id });
    if (act==='delete')  await api('user_delete',{user_id:id});
  }
  $('#chkAll').checked = false;
  load();
};

// init
load();
</script>
</body>
</html>

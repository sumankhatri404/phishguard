<?php
// admin.php
declare(strict_types=1);
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php';

if (empty($_SESSION['user_id'])) { header('Location: index.php?msg='.urlencode('Please login first.')); exit; }
require_admin($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json');
  if (!verify_csrf($_POST['csrf'] ?? '')) { echo json_encode(['ok'=>false,'message'=>'Bad CSRF']); exit; }
  try { $pdo->exec("SET time_zone = '+00:00'"); } catch (Throwable $e) {}
  $act = $_POST['action'] ?? '';

  try {
    switch ($act) {
      case 'users_list': {
        $q = trim((string)($_POST['q'] ?? ''));
        $st = $pdo->prepare("
          SELECT u.id, u.username, u.email, u.role, u.is_locked,
                 COALESCE(up.points,0) AS legacy_points,
                 COALESCE(SUM(x.points),0) AS total_xp
          FROM users u
          LEFT JOIN user_points up ON up.user_id=u.id
          LEFT JOIN user_xp x ON x.user_id=u.id
          WHERE u.username LIKE :q OR u.email LIKE :q
          GROUP BY u.id, u.username, u.email, u.role, u.is_locked, up.points
          ORDER BY u.id DESC LIMIT 500
        ");
        $st->execute([':q'=>"%$q%"]);
        echo json_encode(['ok'=>true,'rows'=>$st->fetchAll(PDO::FETCH_ASSOC)]); exit;
      }
      case 'user_promote': {
        $uid=(int)$_POST['user_id']; $role=($_POST['role']??'user')==='admin'?'admin':'user';
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
          $pdo->prepare("DELETE FROM user_xp WHERE user_id=?")->execute([$uid]);
          $pdo->prepare("DELETE FROM user_task_runs WHERE user_id=?")->execute([$uid]);
          $pdo->prepare("UPDATE user_points SET points=0 WHERE user_id=?")->execute([$uid]);
          $pdo->prepare("UPDATE user_streaks SET streak_current=0 WHERE user_id=?")->execute([$uid]);
          $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        echo json_encode(['ok'=>true]); exit;
      }
      case 'export_users_csv': {
        header_remove('Content-Type');
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="users_xp.csv"');
        $out=fopen('php://output','w');
        fputcsv($out,['id','username','email','role','locked','legacy_points','total_xp']);
        $rows=$pdo->query("
          SELECT u.id, u.username, u.email, u.role, u.is_locked,
                 COALESCE(up.points,0) AS legacy_points,
                 COALESCE(SUM(x.points),0) AS total_xp
          FROM users u
          LEFT JOIN user_points up ON up.user_id=u.id
          LEFT JOIN user_xp x ON x.user_id=u.id
          GROUP BY u.id
          ORDER BY u.id
        ");
        foreach ($rows as $r) fputcsv($out,$r);
        fclose($out); exit;
      }
      case 'round_bump': {
        if (!function_exists('pg_bump_round')) { echo json_encode(['ok'=>false,'message'=>'Round bump not available']); exit; }
        $rid=pg_bump_round($pdo);
        echo json_encode(['ok'=>true,'round_id'=>$rid]); exit;
      }
      case 'clear_today_sessions': {
        $uid=(int)$_POST['user_id'];
        $st=$pdo->prepare("DELETE FROM user_spot_sessions WHERE user_id=? AND DATE(started_at)=DATE(NOW())");
        $st->execute([$uid]);
        echo json_encode(['ok'=>true,'deleted'=>$st->rowCount()]); exit;
      }
      case 'tasks_list': {
        $rows=$pdo->query("SELECT id,channel,title,COALESCE(time_limit_sec,180) AS time_limit_sec FROM spot_tasks ORDER BY id DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok'=>true,'rows'=>$rows]); exit;
      }
      case 'task_save': {
        $id=(int)($_POST['id']??0);
        $chan=in_array($_POST['channel']??'email',['email','sms','web'],true)?$_POST['channel']:'email';
        $ttl=max(10,(int)($_POST['time_limit_sec']??180));
        $title=trim((string)($_POST['title']??''));
        if ($id>0){
          $pdo->prepare("UPDATE spot_tasks SET channel=?, title=?, time_limit_sec=? WHERE id=?")->execute([$chan,$title,$ttl,$id]);
        } else {
          $pdo->prepare("INSERT INTO spot_tasks (channel,title,time_limit_sec) VALUES (?,?,?)")->execute([$chan,$title,$ttl]);
          $id=(int)$pdo->lastInsertId();
        }
        echo json_encode(['ok'=>true,'id'=>$id]); exit;
      }
      case 'task_delete': {
        $id=(int)$_POST['id'];
        $pdo->prepare("DELETE FROM spot_tasks WHERE id=?")->execute([$id]);
        echo json_encode(['ok'=>true]); exit;
      }
    }
    echo json_encode(['ok'=>false,'message'=>'Unknown action']);
  } catch (Throwable $e) {
    echo json_encode(['ok'=>false,'message'=>'Admin action failed','debug'=>$e->getMessage()]);
  }
  exit;
}

$csrf = csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin · PhishGuard</title>
<style>
:root{--bg:#0a0f1f;--panel:#0d1630;--ring:#1f2a44;--text:#e5e7eb;--muted:#94a3b8;--accent:#8b5cf6}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:14px/1.45 system-ui,Segoe UI,Roboto,Arial}
header{display:flex;align-items:center;gap:12px;padding:16px 20px;border-bottom:1px solid var(--ring)}
.tabs{display:flex;gap:8px;margin-left:auto}.tab{padding:8px 12px;border:1px solid var(--ring);border-radius:10px;background:#0b1327;cursor:pointer}
.tab.active{outline:2px solid var(--accent)}main{max-width:1100px;margin:20px auto;padding:0 16px;display:grid;gap:16px}
.card{background:var(--panel);border:1px solid var(--ring);border-radius:14px;padding:16px}
.row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
input,select,button{background:#0b1327;border:1px solid var(--ring);color:var(--text);padding:8px 10px;border-radius:8px}
button{cursor:pointer}table{width:100%;border-collapse:collapse;margin-top:12px}th,td{padding:8px;border-bottom:1px solid var(--ring);text-align:left}
.muted{color:var(--muted)}
</style>
</head>
<body>
<header>
  <div><strong>PhishGuard · Admin</strong></div>
  <div class="tabs">
    <button class="tab active" data-tab="users">Users</button>
    <button class="tab" data-tab="daily">Daily</button>
    <button class="tab" data-tab="tasks">Tasks</button>
    <form method="post" action="admin.php" id="exportForm">
      <input type="hidden" name="action" value="export_users_csv">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
      <button class="tab">Export CSV</button>
    </form>
  </div>
</header>

<main>
  <section id="panel-users" class="card">
    <div class="row">
      <input id="q" placeholder="Search username/email…">
      <button id="btnUsersReload">Reload</button>
    </div>
    <table id="tblUsers">
      <thead><tr><th>ID</th><th>User</th><th>Email</th><th>Role</th><th>Locked</th><th>Legacy XP</th><th>Total XP</th><th>Actions</th></tr></thead>
      <tbody></tbody>
    </table>
  </section>

  <section id="panel-daily" class="card" style="display:none">
    <div class="row">
      <button id="btnRoundBump">Bump Round (unlock new cycle)</button>
      <span class="muted">Use after the daily countdown completes.</span>
    </div>
    <div class="row" style="margin-top:10px">
      <input id="clearUid" type="number" placeholder="User ID to clear today’s sessions">
      <button id="btnClearToday">Clear today sessions</button>
    </div>
    <div id="dailyMsg" class="muted" style="margin-top:10px"></div>
  </section>

  <section id="panel-tasks" class="card" style="display:none">
    <div class="row">
      <input id="tTitle" placeholder="Title">
      <select id="tChan"><option value="email">email</option><option value="sms">sms</option><option value="web">web</option></select>
      <input id="tTTL" type="number" min="10" value="180" title="Time limit sec">
      <button id="btnTaskNew">Add</button>
      <button id="btnTasksReload">Reload</button>
    </div>
    <table id="tblTasks">
      <thead><tr><th>ID</th><th>Channel</th><th>Title</th><th>TTL</th><th>Actions</th></tr></thead>
      <tbody></tbody>
    </table>
  </section>
</main>

<form id="hidden" style="display:none"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>"></form>

<script>
const $=s=>document.querySelector(s), $$=s=>Array.from(document.querySelectorAll(s));
function api(action,payload={}){const fd=new FormData($('#hidden'));fd.append('action',action);for(const[k,v]of Object.entries(payload))fd.append(k,v);return fetch('admin.php',{method:'POST',body:fd}).then(r=>{const ct=r.headers.get('content-type')||'';if(ct.includes('text/csv'))return r;return r.json();});}
function switchTab(name){$$('.tab').forEach(t=>t.classList.toggle('active',t.dataset.tab===name));$('#panel-users').style.display=name==='users'?'':'none';$('#panel-daily').style.display=name==='daily'?'':'none';$('#panel-tasks').style.display=name==='tasks'?'':'none';}
$$('.tab').forEach(btn=>{const tab=btn.dataset.tab;if(!tab)return;btn.addEventListener('click',e=>{e.preventDefault();switchTab(tab);});});

async function loadUsers(){const q=$('#q').value.trim();const out=await api('users_list',{q});const tb=$('#tblUsers tbody');tb.innerHTML='';if(!out.ok){tb.innerHTML=`<tr><td colspan="8">Failed</td></tr>`;return;}for(const r of out.rows){const tr=document.createElement('tr');tr.innerHTML=`<td>${r.id}</td><td>${r.username??''}</td><td>${r.email??''}</td><td>${r.role}</td><td>${r.is_locked==1?'Yes':'No'}</td><td>${r.legacy_points}</td><td>${r.total_xp}</td><td class="row"><button class="promote" data-id="${r.id}" data-role="${r.role==='admin'?'user':'admin'}">${r.role==='admin'?'Demote':'Promote'}</button><button class="lock" data-id="${r.id}" data-locked="${r.is_locked?0:1}">${r.is_locked?'Unlock':'Lock'}</button><button class="resetxp" data-id="${r.id}">Reset XP</button></td>`;tb.appendChild(tr);}tb.querySelectorAll('.promote').forEach(b=>b.onclick=async()=>{await api('user_promote',{user_id:b.dataset.id,role:b.dataset.role});loadUsers();});tb.querySelectorAll('.lock').forEach(b=>b.onclick=async()=>{await api('user_lock',{user_id:b.dataset.id,locked:b.dataset.locked});loadUsers();});tb.querySelectorAll('.resetxp').forEach(b=>b.onclick=async()=>{if(!confirm('Reset XP for this user?'))return;await api('user_reset_xp',{user_id:b.dataset.id});loadUsers();});}
$('#btnUsersReload').onclick=loadUsers;$('#q').addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();loadUsers();}});

$('#btnRoundBump').onclick=async()=>{const r=await api('round_bump');$('#dailyMsg').textContent=r.ok?`Round bumped to ${r.round_id}`:(r.message||'Failed');};
$('#btnClearToday').onclick=async()=>{const uid=$('#clearUid').value.trim();if(!uid)return;const r=await api('clear_today_sessions',{user_id:uid});$('#dailyMsg').textContent=r.ok?`Deleted ${r.deleted} sessions`:(r.message||'Failed');};

async function loadTasks(){const out=await api('tasks_list');const tb=$('#tblTasks tbody');tb.innerHTML='';if(!out.ok){tb.innerHTML=`<tr><td colspan="5">Failed</td></tr>`;return;}for(const r of out.rows){const tr=document.createElement('tr');tr.innerHTML=`<td>${r.id}</td><td>${r.channel}</td><td>${r.title}</td><td>${r.time_limit_sec}</td><td class="row"><button class="edit" data-id="${r.id}">Edit</button><button class="del" data-id="${r.id}">Delete</button></td>`;tb.appendChild(tr);}tb.querySelectorAll('.edit').forEach(b=>b.onclick=()=>{const id=b.dataset.id;const tr=b.closest('tr');$('#tTitle').value=tr.children[2].textContent;$('#tChan').value=tr.children[1].textContent;$('#tTTL').value=tr.children[3].textContent;$('#btnTaskNew').dataset.editing=id;$('#btnTaskNew').textContent='Save';});tb.querySelectorAll('.del').forEach(b=>b.onclick=async()=>{if(!confirm('Delete this task?'))return;await api('task_delete',{id:b.dataset.id});loadTasks();});}
$('#btnTasksReload').onclick=loadTasks;$('#btnTaskNew').onclick=async()=>{const title=$('#tTitle').value.trim();const channel=$('#tChan').value;const ttl=$('#tTTL').value;const editing=$('#btnTaskNew').dataset.editing||'';const payload={title,channel,time_limit_sec:ttl};if(editing)payload.id=editing;const r=await api('task_save',payload);if(r.ok){$('#btnTaskNew').textContent='Add';$('#btnTaskNew').dataset.editing='';$('#tTitle').value='';$('#tTTL').value='180';loadTasks();}};
switchTab('users');loadUsers();
</script>
</body>
</html>

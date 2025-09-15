<?php
declare(strict_types=1);
require_once __DIR__ . '/boot.php';

admin_require_login();
$base = admin_base();
$adminName = htmlspecialchars($_SESSION['admin']['username'] ?? 'admin', ENT_QUOTES, 'UTF-8');

// UI label mode (kept internal & stable)
$labelMode = 'auto'; // auto: last > first > username > #id

/* ---------- helpers ---------- */
function tbl_exists(PDO $pdo, string $table): bool {
  $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
  $q->execute([$table]);
  return (int)$q->fetchColumn() > 0;
}

function get_paired_rows(PDO $pdo): array {
  if (!tbl_exists($pdo,'test_attempts') || !tbl_exists($pdo,'users')) return [];
  $sql = "
    SELECT u.id AS user_id,
           u.username,
           u.first_name,
           u.last_name,
           COALESCE(tp.accuracy_pct, (tp.score*100.0)/NULLIF(tp.total,0)) AS pre_pct,
           COALESCE(ts.accuracy_pct, (ts.score*100.0)/NULLIF(ts.total,0)) AS post_pct,
           COALESCE(ts.accuracy_pct, (ts.score*100.0)/NULLIF(ts.total,0))
         - COALESCE(tp.accuracy_pct, (tp.score*100.0)/NULLIF(tp.total,0)) AS delta_pct
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
    ORDER BY delta_pct DESC
  ";
  return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function get_paired_ttest(PDO $pdo): array {
  $rows = get_paired_rows($pdo);
  $d = [];
  foreach ($rows as $r) if ($r['delta_pct'] !== null) $d[] = (float)$r['delta_pct'];
  $n = count($d);
  if ($n === 0) return ['n'=>0,'df'=>0,'mean_delta'=>null,'sd'=>null,'se'=>null,'t'=>null,'p_two'=>null];
  if ($n === 1) return ['n'=>1,'df'=>0,'mean_delta'=>round($d[0],3),'sd'=>null,'se'=>null,'t'=>null,'p_two'=>null];
  $mean = array_sum($d)/$n;
  $ss=0.0; foreach($d as $x){ $dx=$x-$mean; $ss += $dx*$dx; }
  $sd = sqrt($ss/($n-1));
  $se = $sd>0 ? $sd/sqrt($n) : 0.0;
  $t  = $se>0 ? $mean/$se : null;
  $df = $n-1;
  $p  = null;
  if ($t !== null && function_exists('stats_cdf_t')) {
    $cdf = stats_cdf_t(abs($t), $df, 1);
    $p   = 2 * (1 - $cdf);
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

$tt   = get_paired_ttest($pdo);
$list = get_paired_rows($pdo);

function pct($v){ return $v===null ? '-' : number_format((float)$v, 2).'%' ; }
function delta_badge($v){
  if ($v===null) return '<span class="muted">-</span>';
  $sign = $v >= 0 ? '+' : '';
  $cls  = $v >= 0 ? 'pos' : 'neg';
  // show percentage points difference (pp)
  return '<span class="delta '.$cls.'">'.$sign.number_format((float)$v,2).' pp</span>';
}

function label_from_row(array $r, string $mode): string {
  $u = trim((string)($r['username']   ?? ''));
  $f = trim((string)($r['first_name'] ?? ''));
  $l = trim((string)($r['last_name']  ?? ''));
  switch ($mode) {
    case 'auto':
      if ($l !== '') return $l;
      if ($f !== '') return $f;
      if ($u !== '') return $u;
      return 'User #'.(int)$r['user_id'];
    default:
      return $u !== '' ? $u : ('User #'.(int)$r['user_id']);
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>PhishGuard - Paired Evaluation</title>
  <style>
    :root{
      --bg:#f6f7fb; --text:#111827; --muted:#6b7280;
      --card:#ffffff; --line:#e5e7eb;
      --brand:#3b82f6;
      --good:#10b981; --bad:#ef4444; --chip:#f1f5f9;
    }
    *{box-sizing:border-box}
    body{margin:0;background:var(--bg);color:var(--text);font:14px/1.45 system-ui,Segoe UI,Roboto,Arial}
    a{color:inherit;text-decoration:none}
    .app{display:grid;grid-template-columns:240px 1fr;min-height:100vh}
    .side{background:#0f172a;color:#e2e8f0;display:flex;flex-direction:column}
    .brand{display:flex;align-items:center;gap:12px;padding:16px 18px;border-bottom:1px solid rgba(255,255,255,.08);font-weight:600}
    .nav{padding:10px}
    .nav a{display:block;padding:10px 12px;border-radius:8px;color:#e2e8f0;margin:4px 0}
    .nav a.active,.nav a:hover{background:rgba(255,255,255,.08)}
    header{display:flex;align-items:center;gap:12px;padding:16px 20px;border-bottom:1px solid var(--line);background:var(--card)}
    header .spacer{flex:1}
    .chip{background:var(--chip);border:1px solid var(--line);padding:6px 10px;border-radius:999px}
    .btn{padding:8px 12px;border:1px solid var(--line);border-radius:8px;background:#fff;cursor:pointer;display:inline-block}
    .btn.primary{background:var(--brand);border-color:var(--brand);color:#fff}
    main{padding:20px;display:grid;gap:16px}
    .card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:14px}
    .section-title{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}
    .muted{color:var(--muted)}
    .table{width:100%;border-collapse:collapse}
    .table th,.table td{padding:10px;border-bottom:1px solid var(--line);text-align:left}
    .delta{font-weight:700}
    .delta.pos{color:#065f46}
    .delta.neg{color:#991b1b}
  </style>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body>
<div class="app">
  <aside class="side">
    <div class="brand">PhishGuard</div>
    <nav class="nav">
      <a href="<?= htmlspecialchars($base) ?>/index.php">Dashboard</a>
      <a href="<?= htmlspecialchars($base) ?>/user.php">Users</a>
      <a href="<?= htmlspecialchars($base) ?>/campaigns.php">Modules</a>
      <a href="<?= htmlspecialchars($base) ?>/reports.php" class="active">Reports</a>
      <a href="<?= htmlspecialchars($base) ?>/paired.php">Paired Evaluation</a>
    </nav>
  </aside>

  <section>
    <header>
      <h2 style="margin:0">Paired Evaluation - Full List</h2>
      <div class="spacer"></div>
      <div class="chip">Signed in as <?= $adminName ?></div>
      <a class="btn primary" href="<?= htmlspecialchars($base) ?>/export_paired_scores.php" title="Anonymized: Participant IDs only">Export CSV</a>
      <a class="btn" href="<?= htmlspecialchars($base) ?>/export_paired_scores_ident.php" title="Identifiable: includes names (internal only)">Identifiable CSV</a>
      <a class="btn" href="<?= htmlspecialchars($base) ?>/index.php">Back to Dashboard</a>
    </header>

    <main>
      <div class="card">
        <div class="section-title">
          <strong>t-test Summary</strong>
          <span class="muted">Paired users n=<?= (int)($tt['n'] ?? 0) ?></span>
        </div>
      <div class="muted">
          <?php if (($tt['n'] ?? 0) < 2): ?>
            Not enough paired users to run a t-test.
          <?php else: ?>
            mean Δ=<?= $tt['mean_delta'] ?> pp · SD=<?= $tt['sd'] ?> · SE=<?= $tt['se'] ?> ·
            t=<?= ($tt['t']===null?'-':$tt['t']) ?> · df=<?= $tt['df'] ?> ·
            <?= ($tt['p_two']===null ? 'p=-' : 'p='.$tt['p_two']) ?>
          <?php endif; ?>
        </div>
        <div style="margin-top:10px; font-size: 12px;">
          <!-- Source citation removed as no direct reference is shown in the code -->
        </div>
      </div>
      <div class="card">
      <div class="section-title">
        <strong>Users with Pre & Post</strong>
        <span class="muted">Total: <?= count($list) ?></span>
      </div>
      <div style="overflow:auto">
      <canvas id="pairedChart" style="max-width:100%; height:250px; margin-bottom:10px;"></canvas>
      <div style="font-size: 13px; margin-bottom: 12px; color: #555;">
        <strong>Chart Explanation:</strong> This bar chart shows the <em>Pre</em> and <em>Post</em> scores for each participant (P1, P2, ...). Blue bars indicate Pre scores, orange bars indicate Post scores. The height of each bar represents the percentage score.
      </div>
      <table class="table" style="font-size: 13px;">
        <thead>
          <tr><th style="width:150px">Participant</th><th>Pre %</th><th>Post %</th><th>Delta (pp)</th></tr>
        </thead>
        <tbody>
          <?php if (!$list): ?>
            <tr><td colspan="4" class="muted">No paired users yet.</td></tr>
          <?php else: $i=0; foreach ($list as $r): $i++; ?>
            <tr>
              <td>Participant <?= str_pad((string)$i, 2, '0', STR_PAD_LEFT) ?></td>
              <td><?= pct($r['pre_pct']) ?></td>
              <td><?= pct($r['post_pct']) ?></td>
              <td><?= delta_badge($r['delta_pct']) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

      <div class="card" style="font-size: 13px; line-height: 1.4; color: #444;">
        <div class="section-title"><strong>How the t-test statistics are calculated</strong></div>
        <p>This section provides a brief explanation of the paired t-test statistics calculated in this dashboard. The calculations follow standard statistical methods for paired t-tests.</p>
        <p>For detailed explanations and formal derivations, please refer to authoritative statistical textbooks or online resources.</p>
        <ul>
          <li><a href="https://www.statisticshowto.com/probability-and-statistics/t-test/" target="_blank" rel="noopener noreferrer" title="Statisticshowto: T-Test">Statisticshowto: T-Test</a></li>
          <li><a href="https://www.ncbi.nlm.nih.gov/pmc/articles/PMC3900056/" target="_blank" rel="noopener noreferrer" title="NCBI: Paired t-test explanation">NCBI: Paired t-test explanation</a></li>
          <li><a href="https://www.statisticssolutions.com/what-is-a-paired-samples-t-test/" target="_blank" rel="noopener noreferrer" title="Statistics Solutions: Paired Samples t-Test">Statistics Solutions: Paired Samples t-Test</a></li>
          <li><em>Applied Linear Statistical Models</em>, Kutner, Nachtsheim, Neter, Li (2004)</li>
        </ul>
        <p>These statistics are calculated using the following formulas:</p>
        <ul>
          <li><strong>Mean Δ (mean delta):</strong> Average difference between post-test and pre-test scores across all paired participants.</li>
          <li><strong>Standard Deviation (SD):</strong> Measures variability of the delta values around the mean.</li>
          <li><strong>Standard Error (SE):</strong> Estimated standard deviation of the sample mean, calculated as SD divided by the square root of the number of paired participants.</li>
          <li><strong>t-statistic (t):</strong> Mean delta divided by the standard error, representing how many standard errors the mean is away from zero.</li>
          <li><strong>Degrees of Freedom (df):</strong> Number of paired participants minus one.</li>
          <li><strong>p-value (p):</strong> Probability of observing the data assuming the null hypothesis is true, calculated using the cumulative distribution function of the t-distribution.</li>
        </ul>
        <p>For more detailed analysis, consider exporting the raw paired data for evaluation in specialized statistical software.</p>
      </div>
    </main>
  </section>
</div>
<script>
  const list = <?= json_encode($list) ?>;
  if (list.length > 0) {
    const preScores = list.map(r => r.pre_pct);
    const postScores = list.map(r => r.post_pct);
    const labels = list.map((_, i) => `P${i+1}`);
    new Chart(document.getElementById('pairedChart'), {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [
          {
            label: 'Pre %',
            data: preScores,
            backgroundColor: '#3b82f6'
          },
          {
            label: 'Post %',
            data: postScores,
            backgroundColor: '#f97316'
          }
        ]
      },
      options: {
        responsive: true,
        animation: {
          duration: 1000,
          easing: 'easeOutBounce'
        },
        plugins: {
          legend: { position: 'top' },
          title: { display: true, text: 'Pre and Post Scores per Participant' },
          tooltip: {
            callbacks: {
              label: function(context) {
                return context.dataset.label + ': ' + context.parsed.y.toFixed(2) + '%';
              }
            }
          }
        },
        scales: {
          y: {
            beginAtZero: true,
            max: 100,
            title: { display: true, text: 'Percentage Score' }
          },
          x: {
            title: { display: true, text: 'Participants' }
          }
        }
      }
    });
  }
</script>
<script>
  // On page load, scroll to top so user sees main content first
  window.onload = function() {
    setTimeout(() => {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }, 100);
  };
</script>

<style>
  #scrollToggleBtn {
    position: fixed;
    right: 30px;
    bottom: 60px;
    background-color: #3b82f6;
    color: white;
    border: none;
    border-radius: 50%;
    width: 56px;
    height: 56px;
    font-size: 28px;
    cursor: pointer;
    box-shadow: 0 6px 12px rgba(0,0,0,0.3);
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background-color 0.3s ease, box-shadow 0.3s ease;
  }
  #scrollToggleBtn:hover {
    background-color: #2563eb;
    box-shadow: 0 8px 16px rgba(0,0,0,0.4);
  }
</style>

<button id="scrollToggleBtn" title="Go to Bottom">⬇️</button>
<script>
  const scrollBtn = document.getElementById('scrollToggleBtn');

  function updateScrollButton() {
    const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
    const windowHeight = window.innerHeight;
    const docHeight = document.documentElement.scrollHeight;

    if (scrollTop + windowHeight >= docHeight - 10) {
      // At bottom, show up arrow
      scrollBtn.textContent = '⬆️';
      scrollBtn.title = 'Go to Top';
    } else {
      // Not at bottom, show down arrow
      scrollBtn.textContent = '⬇️';
      scrollBtn.title = 'Go to Bottom';
    }
  }

  scrollBtn.addEventListener('click', () => {
    if (scrollBtn.textContent === '⬇️') {
      window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });
    } else {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }
  });

  window.addEventListener('scroll', updateScrollButton);
  window.addEventListener('load', () => {
    updateScrollButton();
    setTimeout(() => {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }, 100);
  });
</script>
</body>
</html>

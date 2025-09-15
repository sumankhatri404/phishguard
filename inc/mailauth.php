<?php
// inc/mailauth.php — SPF/DKIM/DMARC evaluator (deterministic, simplified)

if (!defined('XP_MODULE_MAILAUTH')) define('XP_MODULE_MAILAUTH', 201); // XP bucket for this lab

/* ---------------- DNS helpers ---------------- */

function ma_dns_get(PDO $pdo, string $host, array $overrides = []): ?string {
  $host = strtolower(trim($host));
  if ($host === '') return null;
  if (isset($overrides[$host])) return $overrides[$host];

  $q = $pdo->prepare("SELECT txt FROM mock_dns_txt WHERE host=? LIMIT 1");
  $q->execute([$host]);
  $txt = $q->fetchColumn();
  return $txt ? (string)$txt : null;
}

/* ---------------- Basic parsing ---------------- */

function ma_parse_headers(string $raw): array {
  // unfold folded headers and split
  $raw = preg_replace("/\r\n[ \t]+/", " ", str_replace("\r\n", "\n", $raw));
  $lines = preg_split("/\n/", $raw);
  $h = [];
  foreach ($lines as $ln) {
    if (strpos($ln, ':') === false) continue;
    [$k, $v] = explode(':', $ln, 2);
    $h[strtolower(trim($k))][] = trim($v);
  }
  return $h;
}

function ma_parse_addr_domain(?string $val): ?string {
  if (!$val) return null;
  // capture stuff@domain
  if (preg_match('/<[^>]*@([^>]+)>/i', $val, $m)) return strtolower(trim($m[1]));
  if (preg_match('/@([A-Za-z0-9\.\-\_]+\.[A-Za-z]{2,})/', $val, $m)) return strtolower(trim($m[1]));
  return null;
}

/* org-domain (very naive): take last two labels unless TLD is length 2 and previous is known SLD (co, com, net, org, gov) */
function ma_org_domain(string $d): string {
  $d = strtolower($d);
  $parts = explode('.', $d);
  if (count($parts) <= 2) return $d;
  $tld = end($parts);
  $sld = prev($parts);
  $known2 = ['co','com','net','org','gov','edu'];
  if (strlen($tld) == 2 && in_array($sld, $known2, true)) {
    return implode('.', array_slice($parts, -3));
  }
  return implode('.', array_slice($parts, -2));
}

function ma_aligned_relaxed(string $d1, string $d2): bool {
  // relaxed alignment: org-domain equal
  return ma_org_domain($d1) === ma_org_domain($d2);
}

/* ---------------- SPF ---------------- */

function ma_spf_parse_tokens(string $txt): array {
  // returns simple token list (mechanism, value)
  $out = [];
  $txt = trim($txt);
  if (stripos($txt, 'v=spf1') !== 0) return $out;
  $parts = preg_split('/\s+/', $txt);
  array_shift($parts);
  foreach ($parts as $p) {
    if ($p === '' ) continue;
    $mech = $p; $val = '';
    if (strpos($p, ':') !== false) { [$mech, $val] = explode(':', $p, 2); }
    $out[] = [strtolower($mech), $val];
  }
  return $out;
}

function ma_spf_ip_match(string $ip, array $tokens): bool {
  foreach ($tokens as [$mech, $val]) {
    if (strpos($mech, 'ip4') === 0) {
      // support CIDR
      if ($val === '') continue;
      if (strpos($val, '/') !== false) {
        [$net, $bits] = explode('/', $val, 2);
        if (filter_var($net, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && ctype_digit($bits)) {
          $mask = -1 << (32 - (int)$bits);
          $i1 = ip2long($ip);
          $i2 = ip2long($net);
          if ($i1 !== false && $i2 !== false && (($i1 & $mask) === ($i2 & $mask))) return true;
        }
      } else {
        if ($ip === $val) return true;
      }
    }
  }
  return false;
}

function ma_spf_collect_tokens(PDO $pdo, string $domain, array $overrides, int $depth = 0): array {
  if ($depth > 5) return [];
  $txt = ma_dns_get($pdo, $domain, $overrides);
  $tokens = $txt ? ma_spf_parse_tokens($txt) : [];
  $out = $tokens;
  // resolve include:
  foreach ($tokens as [$mech, $val]) {
    if ($mech === 'include' && $val) {
      $more = ma_spf_collect_tokens($pdo, strtolower($val), $overrides, $depth+1);
      $out = array_merge($out, $more);
    }
  }
  return $out;
}

/** Returns ['result'=>'pass|fail|softfail|neutral|none', 'domain_used'=>'', 'explain'=>[]] */
function ma_eval_spf(PDO $pdo, string $ip, string $mailfromDomain, array $overrides = []): array {
  $explain = [];
  $used = strtolower($mailfromDomain);
  $txt = ma_dns_get($pdo, $used, $overrides);
  if (!$txt) return ['result'=>'none', 'domain_used'=>$used, 'explain'=>["No SPF record for $used"]];

  $tokens = ma_spf_collect_tokens($pdo, $used, $overrides, 0);
  $match = ma_spf_ip_match($ip, $tokens);
  $explain[] = "SPF record for $used: ".htmlspecialchars($txt, ENT_QUOTES);

  if ($match) {
    $explain[] = "Sender IP $ip matched ip4 mechanism.";
    return ['result'=>'pass', 'domain_used'=>$used, 'explain'=>$explain];
  }

  // find all
  $qual = 'neutral';
  foreach ($tokens as [$mech, $val]) {
    if ($mech === '-all') { $qual = 'fail'; break; }
    if ($mech === '~all') { $qual = 'softfail'; break; }
    if ($mech === '?all') { $qual = 'neutral'; break; }
    if ($mech === '+all') { $qual = 'pass'; break; } // dangerous, but allowed
  }
  $explain[] = "Sender IP $ip did not match any ip4 include chain.";
  $explain[] = "Terminal qualifier: $qual";
  return ['result'=>$qual, 'domain_used'=>$used, 'explain'=>$explain];
}

/* ---------------- DKIM (simplified) ---------------- */

/**
 * We treat DKIM as PASS if:
 *  - DKIM-Signature exists with d= and s=
 *  - DNS has {s}._domainkey.{d} with v=DKIM1
 *  - OR Authentication-Results contains 'dkim=pass'
 * Returns ['result'=>'pass|fail|none', 'd'=>'', 's'=>'', 'explain'=>[]]
 */
function ma_eval_dkim(PDO $pdo, array $hdrs, array $overrides = []): array {
  $ex = [];
  $ar = strtolower(implode(' ', $hdrs['authentication-results'] ?? []));
  if (strpos($ar, 'dkim=pass') !== false) {
    $ex[] = "Authentication-Results reports dkim=pass.";
    // Try to extract d/s from signature if present
    $sig = $hdrs['dkim-signature'][0] ?? '';
    $d = ''; $s = '';
    if ($sig) {
      if (preg_match('/\bd=([^;\s]+)/i', $sig, $m)) $d = strtolower($m[1]);
      if (preg_match('/\bs=([^;\s]+)/i', $sig, $m)) $s = strtolower($m[1]);
    }
    return ['result'=>'pass','d'=>$d,'s'=>$s,'explain'=>$ex];
  }

  $sig = $hdrs['dkim-signature'][0] ?? '';
  if (!$sig) return ['result'=>'none','d'=>'','s'=>'','explain'=>["No DKIM-Signature header"]];

  $d=''; $s='';
  if (preg_match('/\bd=([^;\s]+)/i', $sig, $m)) $d = strtolower($m[1]);
  if (preg_match('/\bs=([^;\s]+)/i', $sig, $m)) $s = strtolower($m[1]);
  if (!$d || !$s) return ['result'=>'fail','d'=>$d,'s'=>$s,'explain'=>["DKIM-Signature missing d= or s="]];

  $host = "{$s}._domainkey.$d";
  $txt = ma_dns_get($pdo, $host, $overrides);
  if ($txt && stripos($txt, 'v=DKIM1') !== false) {
    $ex[] = "Found DKIM public key at $host.";
    return ['result'=>'pass','d'=>$d,'s'=>$s,'explain'=>$ex];
  }
  $ex[] = "No DKIM key TXT at $host.";
  return ['result'=>'fail','d'=>$d,'s'=>$s,'explain'=>$ex];
}

/* ---------------- DMARC ---------------- */

function ma_parse_dmarc(string $txt): array {
  $out = ['p'=>'none','aspf'=>'r','adkim'=>'r'];
  foreach (explode(';', $txt) as $kv) {
    $kv = trim($kv);
    if ($kv === '') continue;
    if (strpos($kv,'=')===false) continue;
    [$k,$v] = array_map('trim', explode('=',$kv,2));
    $k = strtolower($k); $v = strtolower($v);
    if ($k==='p') $out['p']=$v;
    if ($k==='aspf') $out['aspf']=$v;
    if ($k==='adkim') $out['adkim']=$v;
  }
  return $out;
}

/**
 * Returns:
 * [
 *   'result' => 'pass|fail',
 *   'policy' => 'none|quarantine|reject',
 *   'disposition' => 'pass|quarantine|reject',
 *   'align' => ['spf'=>bool,'dkim'=>bool],
 *   'explain' => []
 * ]
 */
function ma_eval_dmarc(PDO $pdo, string $fromDomain, array $spf, array $dkim, array $hdrs, array $overrides = []): array {
  $ex = [];
  $from = strtolower($fromDomain);
  $org  = ma_org_domain($from);

  $dmarcHost = "_dmarc.$org";
  $txt = ma_dns_get($pdo, $dmarcHost, $overrides);
  if (!$txt || stripos($txt,'v=dmarc1') === false) {
    $ex[] = "No DMARC record at $dmarcHost — treat as p=none (monitor).";
    $policy = 'none';
  } else {
    $policy = ma_parse_dmarc($txt)['p'] ?? 'none';
    $ex[] = "DMARC record: ".htmlspecialchars($txt, ENT_QUOTES);
  }

  // Alignment
  $spfAligned = false;
  if ($spf['result'] === 'pass') {
    $spfAligned = ma_aligned_relaxed($spf['domain_used'], $from);
  }
  $dkimAligned = false;
  if ($dkim['result'] === 'pass' && $dkim['d']) {
    $dkimAligned = ma_aligned_relaxed($dkim['d'], $from);
  }
  $ex[] = "Alignment — SPF: ".($spfAligned?'aligned':'not aligned')."; DKIM: ".($dkimAligned?'aligned':'not aligned');

  $pass = ($spfAligned || $dkimAligned);
  $result = $pass ? 'pass' : 'fail';

  $dispo = 'pass';
  if (!$pass) {
    if ($policy === 'reject') $dispo = 'reject';
    elseif ($policy === 'quarantine') $dispo = 'quarantine';
    else $dispo = 'pass'; // p=none => no enforcement
    $ex[] = "DMARC failed; policy p=$policy → disposition=$dispo.";
  } else {
    $ex[] = "DMARC pass due to aligned ".($spfAligned?'SPF':'DKIM').".";
  }

  return [
    'result'      => $result,
    'policy'      => $policy,
    'disposition' => $dispo,
    'align'       => ['spf'=>$spfAligned,'dkim'=>$dkimAligned],
    'explain'     => $ex
  ];
}

/* ---------------- Top-level: evaluate one scenario ---------------- */

function ma_evaluate(PDO $pdo, array $in): array {
  // Inputs:
  //   sender_ip, raw_header, spf_override (TXT), dmarc_override (TXT), dkim_force_present(bool), dkim_selector(host) optional
  $raw = (string)($in['raw_header'] ?? '');
  $ip  = (string)($in['sender_ip'] ?? '');
  $hdr = ma_parse_headers($raw);

  $fromHdr  = $hdr['from'][0] ?? '';
  $rpathHdr = $hdr['return-path'][0] ?? '';

  $fromDomain = ma_parse_addr_domain($fromHdr) ?: '';
  $mailFrom   = ma_parse_addr_domain($rpathHdr) ?: $fromDomain;
  $mailFromDomain = $mailFrom ?: $fromDomain;

  // overrides map (host => txt)
  $ov = [];
  if (!empty($in['spf_override']) && $fromDomain)        $ov[$fromDomain] = (string)$in['spf_override'];
  if (!empty($in['dmarc_override']) && $fromDomain)      $ov["_dmarc.".ma_org_domain($fromDomain)] = (string)$in['dmarc_override'];
  if (!empty($in['dkim_selector']) && !empty($in['dkim_force_present']) && !empty($fromDomain)) {
    $ov[strtolower($in['dkim_selector']).'._domainkey.'.$fromDomain] = 'v=DKIM1; p=FAKE';
  }

  $spf  = $fromDomain ? ma_eval_spf($pdo, $ip, $mailFromDomain, $ov) : ['result'=>'none','domain_used'=>'','explain'=>['No From domain']];
  $dkim = ma_eval_dkim($pdo, $hdr, $ov);
  $dmarc= $fromDomain ? ma_eval_dmarc($pdo, $fromDomain, $spf, $dkim, $hdr, $ov)
                      : ['result'=>'fail','policy'=>'none','disposition'=>'pass','align'=>['spf'=>false,'dkim'=>false],'explain'=>['Missing From domain']];

  return [
    'from_domain' => $fromDomain,
    'mailfrom_domain' => $mailFromDomain,
    'spf'   => $spf,
    'dkim'  => $dkim,
    'dmarc' => $dmarc,
    'final' => $dmarc['disposition'], // pass/quarantine/reject (deliverability)
  ];
}

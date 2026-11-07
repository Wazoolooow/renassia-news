<?php
// Compteur de visiteurs discret — sans cookie, IP hachée, visiteurs uniques par jour.
// GET ?hit=1&s=news|cae  → compte la visite (hors robots) et renvoie {"total":N,"today":N}
// GET ?s=news|cae        → renvoie seulement les chiffres
// GET ?stats=CODE        → tableau de bord privé (JSON)
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
date_default_timezone_set('Asia/Jerusalem');

$dir = __DIR__ . '/counter_data';
if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
    @file_put_contents($dir . '/.htaccess',
      "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
}
$SITES = ['news', 'cae'];

function load($f) {
    if (!file_exists($f)) return ['total' => 0, 'days' => [], 'seen_day' => '', 'seen' => []];
    $d = json_decode(file_get_contents($f), true);
    return is_array($d) ? $d : ['total' => 0, 'days' => [], 'seen_day' => '', 'seen' => []];
}

// ── Tableau de bord privé ──
if (isset($_GET['stats'])) {
    if ($_GET['stats'] !== 'hdr2012') { http_response_code(403); echo '{"error":"code"}'; exit; }
    $out = [];
    foreach ($SITES as $s) {
        $d = load("$dir/$s.json");
        $out[$s] = ['total_uniques' => $d['total'], 'days' => $d['days']];
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$s = $_GET['s'] ?? 'news';
if (!in_array($s, $SITES, true)) { http_response_code(400); echo '{"error":"site"}'; exit; }
$file = "$dir/$s.json";

$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$isBot = $ua === '' || preg_match('/bot|crawl|spider|slurp|curl|wget|python|java|php|headless|monitor|preview|facebookexternalhit|lighthouse|uptime|scan/i', $ua);

$lock = fopen("$dir/.lock", 'c');
flock($lock, LOCK_EX);
$d = load($file);
$day = date('Y-m-d');

if (isset($_GET['hit']) && !$isBot) {
    if (($d['seen_day'] ?? '') !== $day) { $d['seen_day'] = $day; $d['seen'] = []; }
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    $id = substr(sha1($ip . '|' . $ua . '|' . $day . '|cr85'), 0, 16);
    if (!isset($d['days'][$day])) $d['days'][$day] = ['u' => 0, 'p' => 0, 'c' => new stdClass()];
    $d['days'][$day]['p']++;
    if (!in_array($id, $d['seen'], true)) {
        $d['seen'][] = $id;
        $d['days'][$day]['u']++;
        $d['total']++;
        $cc = strtoupper(substr($_SERVER['HTTP_CF_IPCOUNTRY'] ?? 'XX', 0, 2));
        $c = (array)$d['days'][$day]['c'];
        $c[$cc] = ($c[$cc] ?? 0) + 1;
        $d['days'][$day]['c'] = $c;
    }
    file_put_contents($file, json_encode($d));
}
flock($lock, LOCK_UN);
fclose($lock);

echo json_encode(['total' => (int)$d['total'], 'today' => (int)($d['days'][$day]['u'] ?? 0)]);

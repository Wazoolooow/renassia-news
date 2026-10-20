<?php
// ════════════════════════════════════════════
// HAL — Proxy RSS (remplace rss2json, bloqué par CORS/Cloudflare)
// GET ?url=<flux RSS/Atom autorisé>  →  {"status":"ok","items":[{title,link,pubDate}]}
// ════════════════════════════════════════════

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');

$ALLOWED = [
    'deadline.com', 'feeds.bbci.co.uk', 'feeds.feedburner.com', 'feeds.nbcnews.com',
    'feeds.npr.org', 'moxie.foxnews.com', 'www.cbsnews.com', 'rss.nytimes.com',
    'sputnikglobe.com', 'timesofindia.indiatimes.com', 'variety.com', 'www.billboard.com',
    'www.haaretz.com', 'www.hollywoodreporter.com', 'www.i24news.tv', 'www.iranintl.com',
    'www.israelhayom.com', 'www.jpost.com', 'www.lefigaro.fr', 'www.lemonde.fr',
    'www.lesinrocks.com', 'www.liberation.fr', 'www.nme.com', 'www.rollingstone.com',
    'www.rt.com', 'www.scmp.com', 'www.theguardian.com', 'www.timesofisrael.com',
    'www.ynetnews.com',
    'www.israelnationalnews.com',
    'www.jns.org',
    'www.ynet.co.il',
    'www.maariv.co.il',
    'rss.walla.co.il',
    'www.haaretz.co.il',
    'www.now14.co.il',
    'www.infos-israel.news',
];
$CACHE_TTL = 300;

function fail($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $msg]);
    exit;
}

$url    = $_GET['url'] ?? '';
$scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
$host   = strtolower((string)parse_url($url, PHP_URL_HOST));
if (!$url || !in_array($scheme, ['http', 'https'], true) || !in_array($host, $ALLOWED, true)) {
    fail('url non autorisée');
}

$cache = sys_get_temp_dir() . '/hal_rss_' . md5($url) . '.json';
if (is_file($cache) && (time() - filemtime($cache)) < $CACHE_TTL) {
    header('Cache-Control: max-age=120');
    readfile($cache);
    exit;
}

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 3,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_ENCODING       => '',
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
    CURLOPT_HTTPHEADER     => ['Accept: application/rss+xml, application/atom+xml, application/xml, text/xml, */*', 'Accept-Language: en-US,en;q=0.9,fr;q=0.8'],
]);
$raw  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$stale = is_file($cache) ? file_get_contents($cache) : null;
if ($raw === false || $code >= 400 || !$raw) {
    if ($stale) { echo $stale; exit; }
    fail('flux injoignable', 502);
}

libxml_use_internal_errors(true);
$xml = simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NOCDATA);
if ($xml === false) {
    if ($stale) { echo $stale; exit; }
    fail('flux illisible', 502);
}

$items = [];
$nodes = isset($xml->channel->item) ? $xml->channel->item : (isset($xml->entry) ? $xml->entry : []);
foreach ($nodes as $n) {
    $title = trim((string)$n->title);
    if ($title === '') continue;
    $link = trim((string)$n->link);
    if ($link === '' && isset($n->link['href'])) $link = trim((string)$n->link['href']);
    $dc   = $n->children('http://purl.org/dc/elements/1.1/');
    $date = trim((string)($n->pubDate ?? ''));
    if ($date === '') $date = trim((string)($n->published ?? ''));
    if ($date === '') $date = trim((string)($n->updated ?? ''));
    if ($date === '' && isset($dc->date)) $date = trim((string)$dc->date);
    $items[] = ['title' => $title, 'link' => $link, 'pubDate' => $date];
    if (count($items) >= 20) break;
}

if (!$items) {
    if ($stale) { echo $stale; exit; }
    fail('aucun article', 502);
}

$out = json_encode(['status' => 'ok', 'items' => $items], JSON_UNESCAPED_UNICODE);
@file_put_contents($cache, $out);
header('Cache-Control: max-age=120');
echo $out;

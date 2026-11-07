<?php
// ════════════════════════════════════════════
// HAL — Résumé de Bourse : indices mondiaux + valeurs vedettes Nasdaq (CNBC, différé ~15 min)
// Cache fichier 120 s pour ne pas solliciter la source à chaque visite.
// ════════════════════════════════════════════
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: max-age=60, must-revalidate');

$SYMS = ['.IXIC','.NDX','.SPX','.DJI','.RUT','.VIX','.FCHI','.GDAXI','.FTSE','.STOXX50E','.N225','.HSI','.SSEC','.KS11','.AXJO','.TA35',
         'NVDA','AAPL','MSFT','AMZN','GOOGL','META','TSLA','AVGO'];
$cache = __DIR__ . '/markets_cache.json';
if (file_exists($cache) && time() - filemtime($cache) < 120) { readfile($cache); exit; }

$url = 'https://quote.cnbc.com/quote-html-webservice/restQuote/symbolType/symbol?symbols=' . implode('|', $SYMS)
     . '&requestMethod=itv&noform=1&partnerId=2&fund=1&exthrs=1&output=json&events=1';
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6,
    CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false, CURLOPT_ENCODING => '',
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
]);
$raw = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$fail = function() use ($cache) {
    if (file_exists($cache)) { readfile($cache); exit; }   // dernier jeu de données connu
    http_response_code(502); echo json_encode(['error' => true]); exit;
};
if (!$raw || $code !== 200) $fail();
$rows = json_decode($raw, true)['FormattedQuoteResult']['FormattedQuote'] ?? null;
if (!is_array($rows)) $fail();

$num = function($s) { if ($s === null || $s === '') return null; return (float)str_replace([',','%','+',' '], '', $s); };
$out = [];
foreach ($rows as $r) {
    if (($r['code'] ?? 1) !== 0 || empty($r['last'])) continue;
    $out[$r['symbol']] = [
        'name' => $r['shortName'] ?? ($r['name'] ?? $r['symbol']),
        'last' => $num($r['last']), 'chg' => $num($r['change'] ?? null), 'pct' => $num($r['change_pct'] ?? null),
        'high' => $num($r['high'] ?? null), 'low' => $num($r['low'] ?? null),
        'date' => $r['last_time'] ?? '', 'cur' => $r['currencyCode'] ?? '', 'state' => $r['curmktstatus'] ?? '',
    ];
}
if (count($out) < 5) $fail();
$json = json_encode(['t' => time(), 'q' => $out], JSON_UNESCAPED_UNICODE);
@file_put_contents($cache, $json);
echo $json;

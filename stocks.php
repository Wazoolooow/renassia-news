<?php
// ════════════════════════════════════════════
// HAL — Proxy cours boursiers (Cboe delayed quotes)
// Remplace Stooq (endpoint supprimé) puis Yahoo (HTTP 429 sur l'hébergeur).
// ════════════════════════════════════════════

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: max-age=60, must-revalidate');

$TICKERS = [
    ['sym' => 'QQQ',   'cboe' => 'QQQ',  'label' => 'QQQ'],
    ['sym' => '^GSPC', 'cboe' => '_SPX', 'label' => 'S&P 500'],
    ['sym' => 'TSLA',  'cboe' => 'TSLA', 'label' => 'Tesla'],
    ['sym' => 'NVDA',  'cboe' => 'NVDA', 'label' => 'NVDA'],
];

$mh      = curl_multi_init();
$handles = [];

foreach ($TICKERS as $i => $t) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => "https://cdn.cboe.com/api/global/delayed_quotes/quotes/{$t['cboe']}.json",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => ['Accept: application/json, text/plain, */*'],
    ]);
    curl_multi_add_handle($mh, $ch);
    $handles[$i] = $ch;
}

$running = null;
do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh);
} while ($running > 0);

$results = [];
foreach ($TICKERS as $i => $t) {
    $raw  = curl_multi_getcontent($handles[$i]);
    $code = curl_getinfo($handles[$i], CURLINFO_HTTP_CODE);
    curl_multi_remove_handle($mh, $handles[$i]);

    $err = ['symbol' => $t['sym'], 'label' => $t['label'], 'error' => true];
    if (!$raw || $code !== 200) { $results[] = $err; continue; }

    $d     = json_decode($raw, true)['data'] ?? null;
    $price = (float)($d['current_price'] ?? 0);
    if (!$d || $price <= 0) { $results[] = $err; continue; }

    $results[] = [
        'symbol'                     => $t['sym'],
        'label'                      => $t['label'],
        'regularMarketPrice'         => $price,
        'regularMarketChangePercent' => round((float)($d['price_change_percent'] ?? 0), 2),
        'date'                       => substr((string)($d['last_trade_time'] ?? ''), 0, 10),
    ];
}

curl_multi_close($mh);

echo json_encode(['quoteResponse' => ['result' => $results, 'error' => null]]);

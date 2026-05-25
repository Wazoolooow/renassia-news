<?php
// ════════════════════════════════════════════
// HAL — Proxy cours boursiers (Stooq.com)
// ════════════════════════════════════════════

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: max-age=60, must-revalidate');

$TICKERS = [
    ['sym' => 'QQQ',    'stooq' => 'qqq.us',  'label' => 'QQQ'],
    ['sym' => '^GSPC',  'stooq' => '%5espx',  'label' => 'S&P 500'],
    ['sym' => 'TSLA',   'stooq' => 'tsla.us', 'label' => 'Tesla'],
    ['sym' => 'NVDA',   'stooq' => 'nvda.us', 'label' => 'NVDA'],
];

function fetchStooq($stooqSym) {
    $url = "https://stooq.com/q/l/?s={$stooqSym}&f=sd2t2ohlcv&h&e=csv";
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible)',
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $code !== 200) return null;

    // Parse CSV: Symbol,Date,Time,Open,High,Low,Close,Volume
    $lines = array_filter(explode("\n", trim($raw)));
    if (count($lines) < 2) return null;
    $headers = str_getcsv(array_shift($lines));
    $row     = str_getcsv(reset($lines));
    if (count($row) < count($headers)) return null;
    $data = array_combine($headers, $row);

    $close = (float)($data['Close'] ?? 0);
    $open  = (float)($data['Open']  ?? 0);
    $chgPct = ($open > 0) ? (($close - $open) / $open * 100) : 0;

    return [
        'close'  => $close,
        'open'   => $open,
        'chgPct' => round($chgPct, 2),
        'date'   => $data['Date'] ?? '',
        'time'   => $data['Time'] ?? '',
    ];
}

// Curl multi pour requêtes parallèles
$mh      = curl_multi_init();
$handles = [];

foreach ($TICKERS as $i => $t) {
    $url = "https://stooq.com/q/l/?s={$t['stooq']}&f=sd2t2ohlcv&h&e=csv";
    $ch  = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible)',
    ]);
    curl_multi_add_handle($mh, $ch);
    $handles[$i] = $ch;
}

// Exécution parallèle
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

    if (!$raw || $code !== 200) {
        $results[] = ['symbol' => $t['sym'], 'label' => $t['label'], 'error' => true];
        continue;
    }

    $lines = array_filter(explode("\n", trim($raw)));
    if (count($lines) < 2) {
        $results[] = ['symbol' => $t['sym'], 'label' => $t['label'], 'error' => true];
        continue;
    }
    $headers = str_getcsv(array_shift($lines));
    $row     = str_getcsv(reset($lines));
    if (count($row) < count($headers)) {
        $results[] = ['symbol' => $t['sym'], 'label' => $t['label'], 'error' => true];
        continue;
    }
    $data  = array_combine($headers, $row);
    $close = (float)($data['Close'] ?? 0);
    $open  = (float)($data['Open']  ?? 0);
    $chg   = ($open > 0) ? round(($close - $open) / $open * 100, 2) : 0;

    $results[] = [
        'symbol'                     => $t['sym'],
        'label'                      => $t['label'],
        'regularMarketPrice'         => $close,
        'regularMarketChangePercent' => $chg,
        'date'                       => $data['Date'] ?? '',
    ];
}

curl_multi_close($mh);

echo json_encode(['quoteResponse' => ['result' => $results, 'error' => null]]);
?>

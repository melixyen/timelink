<?php
/**
 * 本機與線上的輸出比對：同一組查詢分別打兩邊的 /txapi/，去掉 meta 與計時欄位後比較 data
 *
 *   TDX_BASE=<線上站址> php agent/claude/compare/livecmp.php [本機站址] [查詢清單.json]
 *
 * 本機站址預設 http://127.0.0.1:8099（dev_sync.php serve）。查詢清單是 JSON 字串陣列（/txapi/ 之後的部分），
 * 省略時用下面的預設清單（涵蓋 journeys、direct、route_trains、routes、各種時刻表、舊參數）。
 * 全部一致時結束代碼 0；有差異時印出第一個不同的位置，結束代碼 1。
 */

$live = rtrim((string) getenv('TDX_BASE'), '/');
if ($live === '') {
    fwrite(STDERR, "請用環境變數 TDX_BASE 帶線上站址\n");
    exit(2);
}
$local = rtrim($argv[1] ?? 'http://127.0.0.1:8099', '/');
$queries = isset($argv[2]) ? json_decode((string) file_get_contents($argv[2]), true) : [
    'app_trip/journeys?from=TRA-1000&to=TRA-4400&week=1&start=08:00',
    'app_trip/journeys?from=TRA-1000&to=TRA-4400&week=1&start=12:00&end=13:00&by=arr',
    'app_trip/journeys?from=TRTC-G02&to=TRA-1040&week=1&start=08:00&end=10:00',
    'app_trip/journeys?from=TRTC-R12&to=TRTC-O07&week=1&start=08:00',
    'app_trip/journeys?from=TRTC-G04&to=TRTC-G19&week=1&depart=08:00&end=09:00',
    'app_trip/journeys?from=NTMC-Y07&to=TRTC-BL23&week=1&depart=08:00&end=10:00',
    'app_trip/journeys?from=TRA-5160&to=TRA-5110&week=4&depart=07:00&end=09:00',
    'app_trip/journeys?from=KLRT-C1&to=KRTC-R11&week=1&depart=08:00&end=10:00',
    'app_trip/journeys?from=TRA-0950&to=TRA-1210&week=1&arrive=10:00',
    'app_trip/journeys?from=TYMC-A1&to=TYMC-A13&week=6&first=1',
    'app_trip/routes?from=TRA-0950&to=TRA-3300&limit=30',
    'app_trip/route_trains?route=TRA-1000.TRA.TRA-4400&week=1&start=12:00&end=14:30&by=arr',
    'app_trip/direct?from=TRA-1000&to=TRA-4400&week=1&start=06:00&end=12:00',
    'app_trip/direct?from=TRA-1000&to=TRA-4400&week=1&start=07:00&end=10:00&direction=0',
    'app_trip/direct?from=KLRT-C1&to=KLRT-C20&week=1&start=08:00&end=08:30&by=arr',
    'app_line/timetable?line=TRA-WL&from=TRA-1000&to=TRA-1210&week=1&start=07:00&end=09:00',
    'app_line/timetable?line=TRA-WL&from=TRA-1000&to=TRA-1210&week=1&start=09:00&end=10:00&by=arr',
    'app_station/timetable?station=TRA-1000&week=1&start=23:00',
    'app_station/departures?station=TRTC-BL12&week=1&start=08:00',
    'app_train/stops?train=TRA-410&week=1',
    'public_line/detail?line=TRTC-G',
];
if (!is_array($queries)) {
    fwrite(STDERR, "查詢清單要是 JSON 字串陣列\n");
    exit(2);
}

function lcGet($url)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120]);
    if (preg_match('#^https?://(127\.0\.0\.1|localhost)[:/]#', $url)) {
        curl_setopt($ch, CURLOPT_PROXY, '');   // 本機不走代理
    }
    $r = curl_exec($ch);
    curl_close($ch);
    $j = is_string($r) ? json_decode($r, true) : null;
    return is_array($j) ? $j : ['data' => ['_error' => is_string($r) ? substr($r, 0, 200) : 'curl']];
}

function lcStrip($d)
{
    if (!is_array($d)) {
        return $d;
    }
    $out = [];
    foreach ($d as $k => $v) {
        if (in_array($k, ['meta', 'elapsed', 'ms', 'took', 'time', 'generatedAt', 'cache'], true)) {
            continue;
        }
        $out[$k] = lcStrip($v);
    }
    return $out;
}

$diff = 0;
foreach ($queries as $q) {
    $a = json_encode(lcStrip(lcGet("$live/txapi/$q")['data'] ?? null), JSON_UNESCAPED_UNICODE);
    $b = json_encode(lcStrip(lcGet("$local/txapi/$q")['data'] ?? null), JSON_UNESCAPED_UNICODE);
    if ($a === $b) {
        echo "SAME $q\n";
        continue;
    }
    $diff++;
    echo "DIFF $q\n";
    $n = min(strlen($a), strlen($b));
    for ($i = 0; $i < $n && $a[$i] === $b[$i]; $i++);
    $s = max(0, $i - 80);
    echo '   線上 ' . mb_strcut($a, $s, 200) . "\n";
    echo '   本機 ' . mb_strcut($b, $s, 200) . "\n";
}
echo $diff ? "$diff 筆不同\n" : "全部一致\n";
exit($diff ? 1 : 0);

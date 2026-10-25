<?php
// 比對工具共用：載入站上程式（與 route_regression.php 相同的載入方式）
$_SERVER['REQUEST_URI'] = '/';
$root = dirname(__DIR__, 3);
// 參數裡的相對路徑先換成絕對路徑（下面會切到站台根目錄）
foreach ($argv as $i => $a) {
    if ($i > 0 && $a !== '' && $a[0] !== '-' && file_exists($a)) {
        $argv[$i] = realpath($a);
    }
}
chdir($root);
foreach (['config.php', 'http.php', 'sqlite.php', 'tdx.php', 'proxy.php', 'ratelimit.php', 'railcache.php', 'txapi/txapi.php'] as $l) {
    require $root . '/libs/' . $l;
}
const CMP_DATA = __DIR__ . '/../data';

function cmpJson($file)
{
    $j = json_decode((string) @file_get_contents($file), true);
    if (!is_array($j)) {
        fwrite(STDERR, "讀不到 $file\n");
        exit(2);
    }
    return $j;
}

function cmpSave($file, array $data)
{
    file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
}

/** 基準資料的標頭：離線資料版本、找路程式與規則檔的雜湊（看得出基準是用哪一版算的） */
function cmpHeader()
{
    $h = [];
    foreach (['libs/txapi/app/routing.php', 'libs/txapi/app/supplement.php', 'libs/txapi/app/journey.php',
              'libs/txapi/transfer/data/transfer_route_rules.json', 'libs/txapi/transfer/data/transfer_route_supplements.json'] as $f) {
        $h[$f] = substr((string) @hash_file('sha256', dirname(__DIR__, 3) . '/' . $f), 0, 12);
    }
    return ['DataUpdatedAt' => appDataUpdatedAt(), 'CreatedAt' => date('c'), 'Files' => $h];
}

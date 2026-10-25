<?php
/**
 * 產生比對用的起訖組（固定亂數種子，結果存檔；之後都用存好的檔案，資料更新也不會換題目）：
 *   php make_pairs.php [--force]   （先跑 station_map.php；車站清單用 ../data/railtime_stations.json）
 *   ../data/pairs_nnkt.json  北北基桃 400 組（railtime 有的車站：北捷、新北捷運、機捷、基隆臺北桃園區的臺鐵），帶 railtime 代碼
 *   ../data/pairs_kh.json    高雄 120 組（高雄輕軌、高捷、臺鐵 TRA-42xx～TRA-5xxx、高鐵左營）
 */
require __DIR__ . '/boot.php';
$force = in_array('--force', $argv, true);
$m = appModel();
$rtMap = cmpJson(CMP_DATA . '/railtime_map.json')['map'];
$rtSt = [];
foreach (cmpJson(CMP_DATA . '/railtime_stations.json') as $s) {
    $rtSt[$s['id']] = $s;
}
$f = CMP_DATA . '/pairs_nnkt.json';
if ($force || !is_file($f)) {
    $pool = [];
    foreach ($rtSt as $id => $s) {
        if (!isset($rtMap[$id])) {
            continue;
        }
        if ($s['company'] !== 'tra' || in_array($s['sect'], ['keelung', 'taipei', 'taoyuan'], true)) {
            $pool[] = $id;
        }
    }
    sort($pool);
    mt_srand(2026);
    $pairs = [];
    $seen = [];
    while (count($pairs) < 400) {
        $a = $pool[mt_rand(0, count($pool) - 1)];
        $b = $pool[mt_rand(0, count($pool) - 1)];
        if ($a === $b || isset($seen["$a>$b"]) || $rtMap[$a][0] === $rtMap[$b][0]) {
            continue;
        }
        $seen["$a>$b"] = true;
        $pairs[] = ['rt' => [$a, $b], 'from' => $rtMap[$a][0], 'to' => $rtMap[$b][0]];
    }
    cmpSave($f, ['Description' => '北北基桃比對起訖（make_pairs.php，種子 2026）', 'pairs' => $pairs]);
    echo "寫入 $f\n";
}
$f = CMP_DATA . '/pairs_kh.json';
if ($force || !is_file($f)) {
    $pool = array_values(array_filter(array_keys($m['stationLines']), function ($u) {
        return preg_match('/^(KLRT|KRTC|TRA-4[2-5]|TRA-5|THSR-107)/', $u);
    }));
    sort($pool);
    mt_srand(7);
    $pairs = [];
    while (count($pairs) < 120) {
        $a = $pool[mt_rand(0, count($pool) - 1)];
        $b = $pool[mt_rand(0, count($pool) - 1)];
        if ($a !== $b) {
            $pairs[] = ['from' => $a, 'to' => $b];
        }
    }
    cmpSave($f, ['Description' => '高雄比對起訖（make_pairs.php，種子 7）', 'pairs' => $pairs]);
    echo "寫入 $f\n";
}

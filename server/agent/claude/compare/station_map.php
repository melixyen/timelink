<?php
/**
 * railtime 車站代碼 → 本站 StationUID（依站名、路線）：
 *   node railtime_dump.js <railtime> stations > /tmp/rt_stations.json
 *   php station_map.php /tmp/rt_stations.json          寫入 ../data/railtime_map.json
 * 輸出 {"map": {railtime 車站: [StationUID...]}, "lines": {railtime 路線: [{Line, StationRange}]}, "unmatched": [...]}
 * tra_xxxx 直接對 TRA-xxxx；捷運依站名比對（臺／台不分），同名多站（台北車站 BL12、R10）全部列出
 */
require __DIR__ . '/boot.php';
$rt = cmpJson($argv[1] ?? '');
$m = appModel();
$norm = function ($s) { return str_replace(['臺', ' '], ['台', ''], (string) $s); };
$sysOf = ['tra' => ['TRA'], 'trtc' => ['TRTC', 'NTMC'], 'tymetro' => ['TYMC']];
$byName = [];
foreach ($m['names'] as $u => $n) {
    $byName[substr($u, 0, strpos($u, '-'))][$norm($n['Zh_tw'] ?? '')][] = $u;
}
$map = [];
$unmatched = [];
foreach ($rt as $s) {
    if ($s['company'] === 'tra') {
        $u = 'TRA-' . substr($s['id'], 4);
        if (isset($m['names'][$u])) {
            $map[$s['id']] = [$u];
            continue;
        }
    }
    if ($s['company'] === 'tymetro' && preg_match('/^tymetro_a0*(\d+)([a-z]?)$/', $s['id'], $mm) && isset($m['names']['TYMC-A' . $mm[1] . $mm[2]])) {
        $map[$s['id']] = ['TYMC-A' . $mm[1] . $mm[2]];   // 機捷 tymetro_a02 → TYMC-A2
        continue;
    }
    $c = [];
    $systems = $sysOf[$s['company']];
    if ($s['company'] === 'trtc' && $s['lines']) {
        // railtime 的 trtc_6（環狀線）、trtc_7（萬大線）是新北捷運；其他北捷
        $ntmc = array_intersect($s['lines'], ['trtc_6', 'trtc_7']);
        $systems = count($ntmc) === count($s['lines']) ? ['NTMC'] : ($ntmc ? ['TRTC', 'NTMC'] : ['TRTC']);
    }
    foreach ($systems as $sys) {
        foreach ($byName[$sys][$norm(preg_replace('/（.*?）|\(.*?\)/u', '', $s['name']))] ?? [] as $u) {
            $c[] = $u;
        }
    }
    if ($c) {
        $map[$s['id']] = $c;
    } else {
        $unmatched[] = $s['id'] . ' ' . $s['name'];
    }
}
// railtime 路線 → 本站路線＋車站範圍：取對到最多站的路線，範圍是頭尾兩站
$lines = [];
$lineStations = [];
foreach ($rt as $s) {
    foreach ($s['lines'] as $l) {
        $lineStations[$l][] = $s['id'];
    }
}
foreach ($lineStations as $l => $ids) {
    $count = [];
    foreach ($ids as $id) {
        foreach ($map[$id] ?? [] as $u) {
            foreach (array_keys($m['stationLines'][$u] ?? []) as $lu) {
                $count[$lu][$id] = $u;
            }
        }
    }
    uasort($count, function ($a, $b) { return count($b) <=> count($a); });
    $best = array_key_first($count);
    if ($best === null) {
        continue;
    }
    $seq = $m['lineStations'][$best];
    $idx = array_filter(array_map(function ($u) use ($seq) { return array_search($u, $seq, true); }, array_values($count[$best])), 'is_int');
    $lines[$l] = [['Line' => $best, 'StationRange' => [$seq[min($idx)], $seq[max($idx)]]]];
}
cmpSave(CMP_DATA . '/railtime_map.json', ['Description' => 'railtime 車站、路線 → 本站 StationUID、路線範圍（station_map.php 產生）', 'map' => $map, 'lines' => $lines, 'unmatched' => $unmatched]);
printf("對到 %d 站，沒對到 %d 站，路線 %d 條\n", count($map), count($unmatched), count($lines));
foreach ($unmatched as $x) {
    echo "  沒對到：$x\n";
}

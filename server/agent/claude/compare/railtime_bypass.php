<?php
/**
 * railtime routeMap 的「依起訖站排除的轉乘走法」（bypassStationReg、bypassBothStationReg）改寫成本站的排除名單（RouteBans 的 Transfers 規則）：
 *   php railtime_bypass.php            只列出改寫結果
 *   php railtime_bypass.php --write    寫進 libs/txapi/transfer/data/transfer_route_rules.json（取代 Source=railtime 的舊項目，保留人工設的 Disabled）
 * 對應：fromToLine → Ends.Pair（起訖所在路線範圍，兩個方向都算）；bypassStationReg → Ends.Any（起站或迄站符合）；
 *       bypassBothStationReg → Ends.All（兩站都符合）；transStation → Transfers（依序的轉乘站，railtime 兩側車站都列）
 * 需要 ../data/railtime_bypass.json、railtime_map.json、railtime_stations.json（見 ../README.md）
 */
require __DIR__ . '/boot.php';
$write = in_array('--write', $argv, true);
$m = appModel();
$rt = cmpJson(CMP_DATA . '/railtime_map.json');
$ids = array_column(cmpJson(CMP_DATA . '/railtime_stations.json'), 'id');
$toUids = function (array $rtIds) use ($rt) {
    $out = [];
    foreach ($rtIds as $id) {
        foreach ($rt['map'][$id] ?? [] as $u) {
            $out[$u] = true;
        }
    }
    $out = array_keys($out);
    sort($out);
    return $out;
};
$byRegex = function ($re) use ($ids, $toUids) {
    $hit = array_values(array_filter($ids, function ($id) use ($re) { return preg_match('/' . $re . '/', $id); }));
    return $toUids($hit);
};
$names = function ($uids) use ($m) {
    return implode('、', array_map(function ($u) use ($m) { return $m['names'][$u]['Zh_tw'] ?? $u; }, array_slice($uids, 0, 6))) . (count($uids) > 6 ? '…共 ' . count($uids) . ' 站' : '');
};
$rules = [];
foreach (cmpJson(CMP_DATA . '/railtime_bypass.json') as $b) {
    $pair = [];
    foreach ($b['fromToLine'] as $l) {
        $pair[] = $rt['lines'][$l] ?? null;
    }
    $tr = [];
    $trText = [];
    foreach ($b['transStation'] as $t) {
        $u = $toUids($t['changeStation'] ?? []);
        $tr[] = [['Stations' => $u]];
        $trText[] = $t['name'] ?? $t['id'];
    }
    $ends = [];
    if ($pair[0] && $pair[1]) {
        $ends['Pair'] = $pair;
    }
    $cond = '';
    if (isset($b['bypassStationReg'])) {
        $ends['Any'] = [['Stations' => $byRegex($b['bypassStationReg'])]];
        $cond = '起站或迄站是 ' . $names($ends['Any'][0]['Stations']);
    }
    if (isset($b['bypassBothStationReg'])) {
        $ends['All'] = [['Stations' => $byRegex($b['bypassBothStationReg'])]];
        $cond = '起訖兩站都在 ' . $names($ends['All'][0]['Stations']);
    }
    $rules[] = [
        'Transfers' => $tr, 'Ends' => $ends, 'Source' => 'railtime',
        'Note' => implode('→', $b['line']) . ' 經 ' . implode('、', $trText) . " 轉乘：{$cond} 時不列",
        'Railtime' => ['map' => $b['map'], 'index' => $b['index']] + array_filter(['bypassStationReg' => $b['bypassStationReg'] ?? null,
                                    'bypassBothStationReg' => $b['bypassBothStationReg'] ?? null]),
    ];
}
$file = transferDataFile('RouteRules');
$j = json_decode((string) file_get_contents($file), true);
$old = [];
foreach ($j['RouteBans'] ?? [] as $x) {
    if (($x['Source'] ?? '') === 'railtime' && isset($x['Railtime']['map'])) {
        $old[$x['Railtime']['map'] . '#' . $x['Railtime']['index']] = $x;
    }
}
foreach ($rules as &$r) {
    $o = $old[$r['Railtime']['map'] . '#' . $r['Railtime']['index']] ?? null;
    if ($o && !empty($o['Disabled'])) {
        $r['Disabled'] = true;
        $r['DisabledReason'] = $o['DisabledReason'] ?? '';
    }
}
unset($r);
foreach ($rules as $r) {
    echo (!empty($r['Disabled']) ? '[停用] ' : '') . $r['Note'] . "\n";
}
printf("共 %d 條\n", count($rules));
if ($write) {
    $j['RouteBans'] = array_values(array_merge(array_filter($j['RouteBans'] ?? [], function ($x) { return ($x['Source'] ?? '') !== 'railtime'; }), $rules));
    file_put_contents($file, json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
    echo "已寫入 $file\n";
}

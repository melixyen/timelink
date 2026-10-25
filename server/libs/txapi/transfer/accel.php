<?php
/**
 * 可選的加速條件（func=route&accel=1）：依 data/transfer_accel.json 的加速清單少搜一些路。
 *
 * 參考 railtime 的人工規則（各線 protectStation 轉乘大站、routeSystem 跨線規則、bypassSectReg 繞路排除），
 * 改成兩種在逐輪搜尋（RAPTOR）裡套用的限制：
 *   1. 地區走廊：起訖站所在地區的組合決定可以經過哪些地區，其他地區的車站不展開（例如臺北往高雄不往花東、南迴找）
 *   2. 轉乘大站：臺鐵同站換車只在轉乘大站，或起訖站附近（最短行駛時間 LocalMinutes 分鐘內）的車站
 * 這是啟發式的：會少搜一些路，結果可能少幾個方案；不加速的完整搜尋（預設）不受影響。
 */

require_once __DIR__ . '/settings.php';

/** 讀 transfer_accel.json */
function transferAccelData()
{
    static $a = null;
    if ($a === null) {
        $a = json_decode((string) @file_get_contents(transferDataFile('Accel')), true);
        if (!is_array($a)) {
            throw new RuntimeException('transfer_accel.json is missing or invalid');
        }
    }
    return $a;
}

/** 車站 → 所屬地區（可能不只一個）；依 finder 資料庫的路線站序展開清單裡的區間 */
function transferAccelStationRegions(array &$d)
{
    if (isset($d['accelRegions'])) {
        return $d['accelRegions'];
    }
    $a = transferAccelData();
    $seq = [];   // LineUID => [StationUID, ...]（依站序）
    foreach ($d['db']->all('SELECT system, line_id, station_uid FROM line_station ORDER BY system, line_id, seq') as $r) {
        $seq[$r['system'] . '-' . $r['line_id']][] = $r['station_uid'];
    }
    $sysOf = [];
    foreach ($d['db']->all('SELECT uid, system FROM station') as $r) {
        $sysOf[$r['uid']] = $r['system'];
    }
    $map = [];
    foreach ($a['Regions'] as $code => $reg) {
        foreach ($reg['Lines'] ?? [] as $l) {
            $list = $seq[$l['LineUID']] ?? [];
            $i0 = isset($l['From']) ? array_search($l['From'], $list, true) : 0;
            $i1 = isset($l['To']) ? array_search($l['To'], $list, true) : count($list) - 1;
            if ($i0 === false || $i1 === false) {
                continue;
            }
            for ($i = min($i0, $i1); $i <= max($i0, $i1); $i++) {
                $map[$list[$i]][$code] = true;
            }
        }
        foreach ($reg['Stations'] ?? [] as $u) {
            $map[$u][$code] = true;
        }
        if (!empty($reg['Systems'])) {
            $systems = array_flip($reg['Systems']);
            foreach ($sysOf as $u => $sys) {
                if (isset($systems[$sys])) {
                    $map[$u][$code] = true;
                }
            }
        }
    }
    // 同一轉乘群組的車站（例如臺鐵臺北與高鐵台北）視為同地區
    foreach ($d['m']['groups'] as $g) {
        $codes = [];
        foreach ($g['Stations'] as $x) {
            $codes += $map[$x['StationUID']] ?? [];
        }
        if ($codes) {
            foreach ($g['Stations'] as $x) {
                $map[$x['StationUID']] = ($map[$x['StationUID']] ?? []) + $codes;
            }
        }
    }
    return $d['accelRegions'] = $map;
}

/**
 * 加速清單的輸入雜湊：轉乘群組檔、transfer_accel.json、transfer_seed.json 的排除記號（查詢時直接讀）任一改變，算出來的清單就過期
 */
function transferInputsHash()
{
    $seed = json_decode((string) @file_get_contents(transferDataFile('Seed')), true);
    return sha1((string) @sha1_file(transferDataFile('Groups')) . '|' . (string) @sha1_file(transferDataFile('Accel'))
                . '|' . sha1(json_encode($seed['Bypass'] ?? [])));
}

/** 由 func=learn 算出的加速清單（transfer_accel_learned.json）；沒有回 null。另帶 Fresh：資料庫建置時間與輸入雜湊（轉乘群組檔、transfer_accel.json）都沒變 */
function transferAccelLearned(array &$d)
{
    if (array_key_exists('accelLearned', $d)) {
        return $d['accelLearned'];
    }
    $f = transferDataFile('AccelLearned');
    $l = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    if (is_array($l)) {
        $built = json_decode((string) $d['db']->value("SELECT value FROM _meta WHERE key = 'built_at'"), true);
        $l['Fresh'] = ($l['DbBuiltAt'] ?? null) === $built
                   && ($l['InputsHash'] ?? null) === transferInputsHash();
    } else {
        $l = null;
    }
    return $d['accelLearned'] = $l;
}

/** 地區組合（"甲|乙"，兩個方向都有）→ 可經過的地區陣列或 '*'。$learned 有值時用算出來的 EffectiveCorridors */
function transferAccelCorridorMap($learned)
{
    $list = $learned ? $learned['EffectiveCorridors'] : transferAccelData()['Corridors'];
    $corr = [];
    foreach ($list as $c) {
        list($x, $y) = $c['Regions'];
        $corr[$x . '|' . $y] = $corr[$y . '|' . $x] = $c['Allow'];
    }
    return $corr;
}

/** 起訖站的地區組合可經過哪些地區：[是否不限, [地區 => true], [用到的組合 "甲|乙"]] */
function transferAccelAllowed(array $regions, array $corr, $from, $to)
{
    $rf = array_keys($regions[$from] ?? []);
    $rt = array_keys($regions[$to] ?? []);
    $allowAll = !$rf || !$rt;
    $allow = [];
    $pairs = [];
    foreach ($rf as $x) {
        foreach ($rt as $y) {
            $pairs[] = $x . '|' . $y;
            $al = $corr[$x . '|' . $y] ?? '*';
            if ($al === '*') {
                $allowAll = true;
            } else {
                foreach ($al as $code) {
                    $allow[$code] = true;
                }
            }
        }
    }
    return [$allowAll, $allow, $pairs];
}

/**
 * 這次查詢的加速限制：['blocked' => [不展開的車站 => true], 'noChange' => [不能同站換車的車站 => true], 'summary' => 說明]
 * 有算好的 transfer_accel_learned.json 就用它的推薦換車站與走廊，否則用手寫清單
 */
function transferAccelPlan(array &$d, $from, $to)
{
    $a = transferAccelData();
    $learned = transferAccelLearned($d);
    $regions = transferAccelStationRegions($d);
    list($allowAll, $allow) = transferAccelAllowed($regions, transferAccelCorridorMap($learned), $from, $to);
    $blocked = [];
    if (!$allowAll) {
        foreach ($regions as $u => $codes) {
            if (!array_intersect_key($codes, $allow) && $u !== $from && $u !== $to) {
                $blocked[$u] = true;
            }
        }
    }
    // 同站換車只在推薦換車站（算出來的，或手寫備用清單）或起訖站附近
    $hubs = [];
    foreach ($learned ? $learned['LearnedHubs'] : $a['TransferHubs'] as $h) {
        $hubs[$h['StationUID']] = true;
    }
    $local = (int) $a['Settings']['LocalMinutes'] * 60;
    $lbFrom = transferLowerBounds($d, $from, true);
    $lbTo = transferLowerBounds($d, $to);
    $restrict = array_flip($a['Settings']['RestrictChangeSystems']);
    $noChange = [];
    foreach ($d['m']['change'] as $u => $c) {
        $sys = strstr($u, '-', true);
        if (!isset($restrict[$sys]) || isset($hubs[$u])) {
            continue;
        }
        if (($lbFrom[$u] ?? PHP_INT_MAX) <= $local || ($lbTo[$u] ?? PHP_INT_MAX) <= $local) {
            continue;
        }
        $noChange[$u] = true;
    }
    return [
        'blocked'  => $blocked,
        'noChange' => $noChange,
        'summary'  => [
            'Source'           => $learned ? 'learned' : 'manual',
            'LearnedAt'        => $learned['GeneratedAt'] ?? null,
            'Fresh'            => $learned ? $learned['Fresh'] : null,
            'FromRegions'      => array_keys($regions[$from] ?? []),
            'ToRegions'        => array_keys($regions[$to] ?? []),
            'AllowedRegions'   => $allowAll ? '*' : array_keys($allow),
            'Hubs'             => count($hubs),
            'BlockedStations'  => count($blocked),
            'NoChangeStations' => count($noChange),
        ],
    ];
}

/** accel 參數：'1' 一律套用、'0' 不套用、省略＝有算好且未過期的 transfer_accel_learned.json 才套用 */
function transferAccelWanted(array &$d, $param)
{
    if ($param === '1') {
        return true;
    }
    if ($param === '0') {
        return false;
    }
    $l = transferAccelLearned($d);
    return $l !== null && $l['Fresh'];
}

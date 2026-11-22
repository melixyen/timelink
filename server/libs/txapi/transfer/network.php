<?php
/**
 * 路網模型（不看時刻表）：車站所屬路線、轉乘群組、群組間步行、路線站序，以及步行分鐘、轉乘實景影片、排除記號（Bypass）、
 * 一段搭乘的估計分鐘。找路（核心 A）在 app/routing.php、app/indexroute.php，用這裡的模型。
 */

/** 路網模型：車站所屬路線、群組、群組間步行、路線站序 */
function transferNetworkModel(SqliteDao $db)
{
    static $model = null;
    if ($model !== null) {
        return $model;
    }
    $g = transferLoadGroups();
    if (!$g) {
        throw new RuntimeException('transfer_groups.json is missing; run /txapi/admin_transfer/build_groups');
    }
    $m = ['groups' => [], 'groupOf' => [], 'walk' => [], 'bypass' => [], 'change' => [], 'lineStations' => [], 'stationLines' => [], 'links' => [],
          'names' => [], 'lineNames' => []];
    foreach ($g['TransferGroups'] as $grp) {
        $id = $grp['TransferGroupID'];
        $m['groups'][$id] = $grp;
        foreach ($grp['Stations'] as $x) {
            $m['groupOf'][$x['StationUID']][] = $id;
            if ($x['TrainChange']) {
                $m['change'][$x['StationUID']] = ['Minutes' => $x['TrainChange']['Minutes'], 'Source' => $x['TrainChange']['Source']];
            }
        }
        foreach ($grp['Transfers'] as $t) {
            $k = $t['FromStationUID'] . '>' . $t['ToStationUID'];
            if (!isset($m['walk'][$k]) || $t['Minutes'] < $m['walk'][$k]['Minutes']) {
                $m['walk'][$k] = ['Minutes' => $t['Minutes'], 'Source' => $t['Source'], 'TransferGroupID' => $id];
            }
            if (!empty($t['Bypass'])) {
                // 排除記號：不在這裡中途轉乘；AllowAtEnds 時起訖站本身仍可步行（例如從萬華出發走到龍山寺）
                $m['bypass'][$k] = !empty($t['BypassAllowAtEnds']);
            }
        }
    }
    // 排除記號也在查詢時直接讀 transfer_seed.json 的 Bypass：改了馬上生效，不必等 build_groups 把標記寫進群組檔
    foreach (transferSeedBypass() as $b) {
        $list = $b['Stations'];
        foreach ($list as $i => $x) {
            foreach ($list as $j => $y) {
                if ($i !== $j) {
                    $m['bypass'][$x . '>' . $y] = $b['AllowAtEnds'] ?? true;
                }
            }
        }
    }
    foreach ($g['Lines'] as $l) {
        $m['lineStations'][$l['LineUID']] = $l['Stations'];
        $m['lineNames'][$l['LineUID']] = $l['LineName'];
        foreach ($l['Stations'] as $i => $u) {
            $m['stationLines'][$u][$l['LineUID']] = $i;
        }
    }
    foreach ($g['LineLinks'] as $k) {
        $m['links'][$k['FromLineUID']][$k['ToLineUID']] = $k['TransferGroups'];
    }
    foreach ($db->all('SELECT uid, name FROM station') as $r) {
        $m['names'][$r['uid']] = json_decode($r['name'], true);
    }
    return $model = $m;
}

/**
 * transfer_seed.json 的轉乘影片（Videos）：'From>To' => [影片, ...]。查詢時直接讀；依 transfer_settings.json 的 Media 補上網址
 */
function transferSeedVideos()
{
    static $map = null;
    if ($map === null) {
        $map = [];
        $seed = json_decode((string) @file_get_contents(transferDataFile('Seed')), true);
        $media = transferSettings('Media');
        foreach (is_array($seed) ? ($seed['Videos'] ?? []) : [] as $v) {
            $out = ['Type' => $v['Type'], 'ID' => $v['ID'], 'Title' => $v['Title'] ?? null];
            foreach ($media[$v['Type']] ?? [] as $field => $tpl) {
                $out[$field] = str_replace('{ID}', rawurlencode($v['ID']), $tpl);
            }
            $out['Source'] = $v['Source'] ?? null;
            if (isset($v['Note'])) {
                $out['Note'] = $v['Note'];
            }
            $map[$v['From'] . '>' . $v['To']][] = $out;
        }
    }
    return $map;
}

/** 從 u 走到 v 的轉乘影片（沒有回 null） */
function transferVideos($u, $v)
{
    return $u === null || $v === null ? null : (transferSeedVideos()[$u . '>' . $v] ?? null);
}

/** transfer_seed.json 的排除記號（Bypass）清單 */
function transferSeedBypass()
{
    static $list = null;
    if ($list === null) {
        $seed = json_decode((string) @file_get_contents(transferDataFile('Seed')), true);
        $list = is_array($seed) ? ($seed['Bypass'] ?? []) : [];
    }
    return $list;
}

/**
 * 從車站 u 轉到 v（同群組）：步行或同站換車的分鐘與來源；不能轉回 null。
 * $atEnd：這次步行是起站出發或走到迄站（不是中途轉乘）；有排除記號（Bypass）的轉乘只有在 $atEnd 且 AllowAtEnds 時才能用。
 * $all：不管排除記號（算時間下界用）
 */
function transferWalk(array $m, $u, $v, $atEnd = false, $all = false)
{
    if ($u === $v) {
        return isset($m['change'][$u]) ? $m['change'][$u] + ['Type' => 'train_change'] : ['Minutes' => 0, 'Source' => 'none', 'Type' => 'none'];
    }
    $k = $u . '>' . $v;
    if (!$all && isset($m['bypass'][$k]) && !($atEnd && $m['bypass'][$k])) {
        return null;
    }
    $w = $m['walk'][$k] ?? null;
    return $w ? $w + ['Type' => 'walk'] : null;
}

/** 同一路線上兩站的估算行駛分鐘：捷運站間時間 → 臺鐵、高鐵最快一班 → 站數 × 2 */
function transferLegMinutes(SqliteDao $db, array $m, $line, $a, $b)
{
    static $cache = [];
    $k = $line . '|' . $a . '|' . $b;
    if (isset($cache[$k])) {
        return $cache[$k];
    }
    list($sys) = explode('-', $line, 2);
    $res = null;
    if ($sys === 'TRA' || $sys === 'THSR') {
        $v = $db->value('SELECT MIN(b.sort_min - a.sort_min) FROM train_stop a JOIN train_stop b ON b.train_key = a.train_key AND b.seq > a.seq
                         WHERE a.station_uid = ? AND b.station_uid = ?', [$a, $b]);
        if ($v !== null) {
            $res = ['Minutes' => (int) $v, 'Source' => 'timetable'];
        }
    } else {
        static $chainsBy = [];
        if (!isset($chainsBy[$sys])) {
            $chainsBy[$sys] = [];
            foreach ($db->all('SELECT route_id, direction, train_type, station_uid, arr, dep FROM metro_travel WHERE system = ? ORDER BY route_id, direction, train_type, seq', [$sys]) as $r) {
                $chainsBy[$sys][$r['route_id'] . '|' . $r['direction'] . '|' . $r['train_type']][] = $r;
            }
        }
        foreach ($chainsBy[$sys] as $rows) {
            $ia = null;
            foreach ($rows as $i => $r) {
                if ($r['station_uid'] === $a && $ia === null) {
                    $ia = $i;
                } elseif ($r['station_uid'] === $b && $ia !== null) {
                    $sec = (int) $r['arr'] - (int) $rows[$ia]['dep'];
                    if ($res === null || $sec / 60 < $res['Minutes']) {
                        $res = ['Minutes' => round($sec / 60, 1), 'Source' => 'metro_travel'];
                    }
                    break;
                }
            }
        }
        if ($res === null) {
            $v = $db->value('SELECT MIN(seconds) FROM metro_travel_od WHERE from_uid = ? AND to_uid = ?', [$a, $b]);
            if ($v !== null) {
                $res = ['Minutes' => round($v / 60, 1), 'Source' => 'metro_travel_od'];
            }
        }
    }
    if ($res === null) {
        $ia = $m['stationLines'][$a][$line] ?? null;
        $ib = $m['stationLines'][$b][$line] ?? null;
        $res = ['Minutes' => ($ia === null || $ib === null) ? null : abs($ib - $ia) * 2, 'Source' => 'stations×2'];
    }
    return $cache[$k] = $res;
}

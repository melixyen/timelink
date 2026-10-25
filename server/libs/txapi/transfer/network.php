<?php
/**
 * 路網結構搜尋（不看時刻表）：用轉乘群組建立「路線連結圖」，找出 A 站到 B 站可以走的路線串與每一段的轉乘站。
 *
 *   1. 節點＝路線（系統-LineID），兩條路線之間有共同的轉乘群組就相連（LineLinks；臺鐵路線交會站也是單站群組）
 *   2. 起點：A 站所在路線，以及 A 所屬轉乘群組其他成員的路線（先步行過去）；終點同理
 *   3. 在路線圖上找轉乘次數最少的路線串，再多找轉乘多一次的路線串作為替代（不重複經過同一路線）
 *   4. 每一對相鄰路線從共同的轉乘群組中選轉乘站，展開所有組合；同一路線上的上車站與下車站不可相同
 *   5. 每段估算時間：捷運用站間行駛時間（路線站距表或起訖矩陣），臺鐵、高鐵用時刻表中最快的一班，
 *      都沒有時以站數 × 2 分；轉乘用群組的步行分鐘或同站換車分鐘
 *   6. 依轉乘次數、估算時間排序
 * 基隆→東湖這類沒有共同轉乘站的情形：西部幹線 →（臺北／南港／松山…）→ 板南線 →（忠孝復興／南港展覽館）→ 文湖線
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

/**
 * 路網結構搜尋。回傳 [options（依轉乘次數、估算時間排序）, linePaths]
 * $extra：比最少轉乘多幾次的路線串也列入（預設 1）；$limit：最多回傳幾個方案
 */
function transferNetworkSearch(SqliteDao $db, $from, $to, $extra = 1, $limit = 20)
{
    $m = transferNetworkModel($db);
    // 起訖：車站本身的路線，以及同群組車站的路線（先步行）
    $ends = function ($u, $toEnd) use ($m) {
        $out = [[$u, 0, null]];
        foreach ($m['groupOf'][$u] ?? [] as $gid) {
            foreach ($m['groups'][$gid]['Stations'] as $x) {
                $w = $toEnd ? transferWalk($m, $x['StationUID'], $u, true) : transferWalk($m, $u, $x['StationUID'], true);
                if ($x['StationUID'] !== $u && $w) {
                    $out[] = [$x['StationUID'], $w['Minutes'], $gid];
                }
            }
        }
        return $out;
    };
    $starts = [];   // line => [[station, walkMinutes, groupId]]
    foreach ($ends($from, false) as $e) {
        foreach (array_keys($m['stationLines'][$e[0]] ?? []) as $l) {
            $starts[$l][] = $e;
        }
    }
    $goals = [];
    foreach ($ends($to, true) as $e) {
        foreach (array_keys($m['stationLines'][$e[0]] ?? []) as $l) {
            $goals[$l][] = $e;
        }
    }
    // BFS：最少換線次數，再多 $extra 次
    $paths = [];
    $queue = [];
    foreach (array_keys($starts) as $l) {
        $queue[] = [$l];
    }
    $best = null;
    while ($queue) {
        $p = array_shift($queue);
        $last = $p[count($p) - 1];
        if ($best !== null && count($p) > $best + $extra) {
            continue;
        }
        if (isset($goals[$last])) {
            $paths[] = $p;
            $best = $best ?? count($p);
        }
        if (count($p) >= 6 || ($best !== null && count($p) >= $best + $extra)) {
            continue;
        }
        foreach (array_keys($m['links'][$last] ?? []) as $n) {
            if (!in_array($n, $p, true)) {
                $queue[] = array_merge($p, [$n]);
            }
        }
        if (count($queue) > 20000) {
            break;
        }
    }

    // 展開每個路線串的轉乘站組合
    $options = [];
    foreach ($paths as $p) {
        $combos = [[]];   // 每個元素：[[groupId, exitStation(on p[i]), entryStation(on p[i+1])], ...]
        for ($i = 0; $i + 1 < count($p); $i++) {
            $next = [];
            foreach ($m['links'][$p[$i]][$p[$i + 1]] as $gid) {
                $xs = $ys = [];
                foreach ($m['groups'][$gid]['Stations'] as $x) {
                    if (in_array($p[$i], $x['Lines'], true)) {
                        $xs[] = $x['StationUID'];
                    }
                    if (in_array($p[$i + 1], $x['Lines'], true)) {
                        $ys[] = $x['StationUID'];
                    }
                }
                foreach ($xs as $x) {
                    foreach ($ys as $y) {
                        if (transferWalk($m, $x, $y) !== null) {
                            foreach ($combos as $c) {
                                $next[] = array_merge($c, [[$gid, $x, $y]]);
                            }
                        }
                    }
                }
            }
            $combos = array_slice($next, 0, 400);
        }
        foreach ($starts[$p[0]] as $s) {
            foreach ($goals[$p[count($p) - 1]] as $e) {
                foreach ($combos as $c) {
                    $opt = transferNetworkOption($db, $m, $p, $s, $e, $c);
                    if ($opt !== null) {
                        $options[] = $opt;
                    }
                }
            }
        }
    }
    // 同一組（路線、上下車站）只留一個；排序
    $uniq = [];
    foreach ($options as $o) {
        $k = json_encode(array_map(function ($l) { return [$l['LineUID'], $l['FromStationUID'], $l['ToStationUID']]; }, $o['Legs']));
        if (!isset($uniq[$k]) || $o['EstimatedMinutes'] < $uniq[$k]['EstimatedMinutes']) {
            $uniq[$k] = $o;
        }
    }
    $options = array_values($uniq);
    usort($options, function ($a, $b) {
        return [$a['TransferCount'], $a['EstimatedMinutes']] <=> [$b['TransferCount'], $b['EstimatedMinutes']];
    });
    return ['options' => array_slice($options, 0, $limit), 'linePaths' => $paths];
}

/** 組出一個路網方案；不合理（同路線上下車同站、估不出時間）回 null */
function transferNetworkOption(SqliteDao $db, array $m, array $p, array $start, array $goal, array $combo)
{
    $legs = [];
    $transfers = [];
    $total = 0;
    if ($start[2] !== null) {
        $transfers[] = ['Type' => 'walk', 'TransferGroupID' => $start[2], 'FromStationUID' => null, 'ToStationUID' => $start[0],
                        'Minutes' => $start[1], 'Note' => '起站步行到同群組車站'];
        $total += $start[1];
    }
    $cur = $start[0];
    for ($i = 0; $i < count($p); $i++) {
        $exit = $i < count($combo) ? $combo[$i][1] : $goal[0];
        if ($cur === $exit) {
            return null;
        }
        $ia = $m['stationLines'][$cur][$p[$i]] ?? null;
        $ib = $m['stationLines'][$exit][$p[$i]] ?? null;
        if ($ia === null || $ib === null) {
            return null;
        }
        $t = transferLegMinutes($db, $m, $p[$i], $cur, $exit);
        if ($t['Minutes'] === null) {
            return null;
        }
        $legs[] = ['LineUID' => $p[$i], 'LineName' => $m['lineNames'][$p[$i]] ?? null,
                   'FromStationUID' => $cur, 'FromStationName' => $m['names'][$cur] ?? null,
                   'ToStationUID' => $exit, 'ToStationName' => $m['names'][$exit] ?? null,
                   'Stops' => abs($ib - $ia), 'EstimatedMinutes' => $t['Minutes'], 'EstimateSource' => $t['Source']];
        $total += $t['Minutes'];
        if ($i < count($combo)) {
            list($gid, $x, $y) = $combo[$i];
            $w = transferWalk($m, $x, $y);
            $transfers[] = ['Type' => $w['Type'], 'TransferGroupID' => $gid, 'FromStationUID' => $x, 'ToStationUID' => $y,
                            'Minutes' => $w['Minutes'], 'Source' => $w['Source']] + (($vid = transferVideos($x, $y)) ? ['Videos' => $vid] : []);
            $total += $w['Minutes'];
            $cur = $y;
        }
    }
    if ($goal[2] !== null) {
        $transfers[] = ['Type' => 'walk', 'TransferGroupID' => $goal[2], 'FromStationUID' => $goal[0], 'ToStationUID' => null,
                        'Minutes' => $goal[1], 'Note' => '下車後步行到同群組的迄站'];
        $total += $goal[1];
    }
    return ['Lines' => $p, 'TransferCount' => count($p) - 1, 'EstimatedMinutes' => round($total, 1), 'Legs' => $legs, 'Transfers' => $transfers];
}

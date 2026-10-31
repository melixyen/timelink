<?php
/**
 * 時刻表轉乘搜尋（逐輪展開，RAPTOR 的做法）。
 *
 * 第 k 輪＝搭第 k 班車：從上一輪「可以上車」的車站出發，掃過所有經過這些車站的列車與捷運營運路線，
 * 更新各站最早到達時間；再依轉乘群組加上步行或同站換車時間，得到下一輪可以上車的車站與時間。
 * 每一輪都更新迄站時，就得到「搭 k 班車（轉乘 k-1 次）最早到達」的方案，這些方案互不支配（Pareto）。
 *
 *   臺鐵、高鐵：每一班車是一條 trip，用 train_stop 的真實到離站時刻
 *   捷運、輕軌：營運路線（metro_travel 路線站距表；機捷、淡海、安坑用起訖矩陣）＋各站站別時刻表：
 *               在 x 站上車＝x 站下一班（往經過迄站方向、終點在迄站之後）離站時刻，到 y 站＝上車時刻＋站間時間
 *   轉乘：同一轉乘群組內步行（Transfers 分鐘）；同站換車只在有 TrainChange 的車站（臺鐵大站、交會站、機捷 A8、捷運分岔站）
 *
 * 時間一律以營運日秒數表示（凌晨 00:00～03:59 為 86400 以後，與 sort_min × 60 相同）。
 */

require_once __DIR__ . '/accel.php';
require_once __DIR__ . '/runs.php';
require_once __DIR__ . '/settings.php';   // TRANSFER_MAX_TRIPS 等參數與方案標籤 TRANSFER_TAGS 都來自 data/transfer_settings.json

/** 秒 → HH:MM（00～23 時） */
function transferHM($sec)
{
    $m = intdiv((int) $sec, 60) % 1440;
    return sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
}

/** 載入某星期的路網時刻資料（列車 trip、捷運營運路線、轉乘） */
function transferRouterData(SqliteDao $db, $week)
{
    $mask = 1 << $week;
    $d = ['trips' => [], 'stationTrips' => [], 'patterns' => [], 'stationPatterns' => [], 'depCache' => [], 'groupInfo' => [],
          'mask' => $mask, 'm' => transferNetworkModel($db), 'db' => $db];

    // ---- 臺鐵、高鐵：每班車的停靠站（到站秒、離站秒）
    $hm = function ($t) { return (int) substr($t, 0, 2) * 60 + (int) substr($t, 3, 2); };
    $cur = null;
    foreach ($db->all('SELECT t.train_key, t.system, s.station_uid, s.arr, s.dep, s.sort_min, s.arr_src
                       FROM train t JOIN train_stop s ON s.train_key = t.train_key
                       WHERE (t.weekmask & ?) != 0 ORDER BY t.train_key, s.seq', [$mask]) as $r) {
        if ($cur === null || $d['trips'][$cur]['key'] !== $r['train_key']) {
            $cur = count($d['trips']);
            $d['trips'][$cur] = ['key' => $r['train_key'], 'system' => $r['system'], 'st' => [], 'arr' => [], 'dep' => []];
        }
        $depMin = (int) $r['sort_min'];
        $arrMin = $depMin - (($hm($r['dep']) - $hm($r['arr']) + 1440) % 1440);
        $i = count($d['trips'][$cur]['st']);
        $d['trips'][$cur]['st'][] = $r['station_uid'];
        $d['trips'][$cur]['arr'][] = $arrMin * 60;
        $d['trips'][$cur]['dep'][] = $depMin * 60;
        $d['stationTrips'][$r['station_uid']][] = [$cur, $i];
    }

    // ---- 捷運、輕軌：營運路線（站序與到離站偏移秒）
    foreach ($db->all('SELECT system, line_id, route_id, direction, train_type, station_uid, arr, dep, source FROM metro_travel
                       ORDER BY system, line_id, route_id, direction, train_type, seq') as $r) {
        $k = $r['system'] . '|' . $r['line_id'] . '|' . $r['route_id'] . '|' . $r['direction'] . '|' . $r['train_type'];
        if (!isset($d['patterns'][$k])) {
            $d['patterns'][$k] = ['key' => $k, 'system' => $r['system'], 'line' => $r['line_id'], 'route' => $r['route_id'],
                                  'dir' => (string) $r['direction'], 'tt' => (int) $r['train_type'], 'st' => [], 'arr' => [], 'dep' => [], 'src' => []];
        }
        $d['patterns'][$k]['st'][] = $r['station_uid'];
        $d['patterns'][$k]['arr'][] = (int) $r['arr'];
        $d['patterns'][$k]['dep'][] = (int) $r['dep'];
        $d['patterns'][$k]['src'][] = $r['source'];
    }
    // 起訖矩陣的系統（機場捷運、淡海、安坑輕軌）：依營運路線站序，以首站到各站的矩陣秒數為偏移
    $od = [];
    foreach ($db->all('SELECT system, line_id, train_type, from_uid, to_uid, MIN(seconds) AS s, source FROM metro_travel_od GROUP BY 1, 2, 3, 4, 5') as $r) {
        $od[$r['system'] . '|' . $r['line_id'] . '|' . $r['train_type']][$r['from_uid'] . '>' . $r['to_uid']] = [(int) $r['s'], $r['source']];
    }
    $d['od'] = $od;   // 兩站間直接的行駛秒數（起訖矩陣），transferPatternTravel() 優先用
    foreach ($od as $k => $pairs) {
        list($sys, $line, $tt) = explode('|', $k);
        foreach (finderDbRouteSeqs($sys) as $r) {
            if ($r['line'] !== $line) {
                continue;
            }
            $seq = array_map(function ($x) use ($sys) { return finderDbUID($sys, $x); }, $r['seq']);
            $first = null;
            $p = ['key' => $sys . '|' . $line . '|' . $r['route'] . '|' . $r['dir'] . '|' . $tt, 'system' => $sys, 'line' => $line,
                  'route' => $r['route'], 'dir' => (string) $r['dir'], 'tt' => (int) $tt, 'st' => [], 'arr' => [], 'dep' => [], 'src' => [], 'od' => $k];
            foreach ($seq as $u) {
                if ($first === null) {
                    // 首站：這個車種在矩陣裡有從它出發的值才算
                    foreach ($pairs as $pk => $v) {
                        if (strpos($pk, $u . '>') === 0) {
                            $first = $u;
                            break;
                        }
                    }
                    if ($first !== null) {
                        $p['st'][] = $u;
                        $p['arr'][] = 0;
                        $p['dep'][] = 0;
                        $p['src'][] = $pairs[array_key_first($pairs)][1];
                    }
                    continue;
                }
                if (isset($pairs[$first . '>' . $u])) {
                    $p['st'][] = $u;
                    $p['arr'][] = $pairs[$first . '>' . $u][0];
                    $p['dep'][] = $pairs[$first . '>' . $u][0];
                    $p['src'][] = $pairs[$first . '>' . $u][1];
                }
            }
            if (count($p['st']) >= 2) {
                $d['patterns'][$p['key']] = $p;
            }
        }
    }
    // 有真實站別時刻表的捷運、輕軌路線改用整列車（runs.php：每班車每一站的時刻都對到時刻表，列車在終點站結束）；
    // 只有模擬時刻的路線（文湖線、臺中捷運）仍用營運路線（發車＋行駛時間推算）
    transferBuildRuns($d);
    foreach ($d['patterns'] as $k => $p) {
        if (isset($d['runLines'][$p['system'] . '|' . $p['line']])) {
            continue;
        }
        foreach ($p['st'] as $i => $u) {
            $d['stationPatterns'][$u][] = [$k, $i];
        }
    }
    $d['stationMRoutes'] = [];
    foreach ($d['mroutes'] as $rk => $mr) {
        foreach ($mr['st'] as $i => $u) {
            $d['stationMRoutes'][$u][] = [$rk, $i];
        }
    }
    return $d;
}

/** 整列車路線第 j 站的發車（或 $field＝'arr' 到站）欄（各班依發車排序） */
function transferMRouteColumn(array &$d, $rk, $j, $field = 'dep')
{
    $ck = $field . $j;
    if (!isset($d['mroutes'][$rk]['col'][$ck])) {
        $d['mroutes'][$rk]['col'][$ck] = array_map(function ($t) use ($field, $j) { return $t[$field][$j]; }, $d['mroutes'][$rk]['trips']);
    }
    return $d['mroutes'][$rk]['col'][$ck];
}

/** 整列車路線在第 j 站最早可搭（發車 ≥ t）的一班；沒有回 null */
function transferMRouteNext(array &$d, $rk, $j, $t)
{
    $col = transferMRouteColumn($d, $rk, $j);
    if ($d['mroutes'][$rk]['fifo']) {
        $lo = 0;
        $hi = count($col);
        while ($lo < $hi) {
            $mid = ($lo + $hi) >> 1;
            if ($col[$mid] < $t) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }
        return $lo < count($col) ? $lo : null;
    }
    $best = null;
    foreach ($col as $i => $x) {
        if ($x >= $t && ($best === null || $x < $col[$best])) {
            $best = $i;
        }
    }
    return $best;
}

/** 整列車路線在第 j 站到站 ≤ t 的最晚一班；沒有回 null */
function transferMRoutePrev(array &$d, $rk, $j, $t)
{
    $col = transferMRouteColumn($d, $rk, $j, 'arr');
    if ($d['mroutes'][$rk]['fifo']) {
        $lo = 0;
        $hi = count($col);
        while ($lo < $hi) {
            $mid = ($lo + $hi) >> 1;
            if ($col[$mid] <= $t) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }
        return $lo > 0 ? $lo - 1 : null;
    }
    $best = null;
    foreach ($col as $i => $x) {
        if ($x <= $t && ($best === null || $x > $col[$best])) {
            $best = $i;
        }
    }
    return $best;
}

/**
 * 營運路線上從第 i 站離站到第 j 站到站的行駛秒數與來源：兩站間有直接的資料（起訖矩陣：機場捷運、淡海、安坑輕軌）就用它，
 * 沒有才用路線站距表一站一站加起來（臺北捷運等 S2STravelTime 只有相鄰兩站，相鄰兩站本身就是直接資料）
 */
function transferPatternTravel(array &$d, array $p, $i, $j)
{
    if (isset($p['od']) && ($x = $d['od'][$p['od']][$p['st'][$i] . '>' . $p['st'][$j]] ?? null)) {
        return $x;
    }
    return [$p['arr'][$j] - $p['dep'][$i], implode('+', array_values(array_unique(array_slice($p['src'], $i + 1, $j - $i))))];
}

/**
 * 某營運路線在第 j 站可搭的發車（營運日秒，已排序）：同路線、車種相符，且列車終點在本營運路線 j 站之後
 * （終點不在本營運路線上：若在同路線其他營運路線上就不是這條的車，否則看方向；環狀線看方向）
 */
function transferPatternDepartures(array &$d, $patternKey, $j)
{
    $p = $d['patterns'][$patternKey];
    $u = $p['st'][$j];
    $ck = $patternKey . '#' . $j;
    if (isset($d['depCache'][$ck])) {
        return $d['depCache'][$ck];
    }
    static $lineStations = null;
    if ($lineStations === null) {
        $lineStations = [];
        foreach ($d['patterns'] as $q) {
            foreach ($q['st'] as $x) {
                $lineStations[$q['system'] . '|' . $q['line']][$x] = true;
            }
        }
    }
    transferStationDep($d, $u);
    $after = array_flip(array_slice($p['st'], $j + 1));
    $onPattern = array_flip($p['st']);
    $loop = $p['st'][0] === $p['st'][count($p['st']) - 1];
    $out = [];
    $groups = 0;
    foreach ($d['stationDep'][$u] as $gid => $list) {
        // 這個時刻表群組是不是這條營運路線的車：終點在本營運路線上就要在 j 站之後；終點在同路線其他營運路線上就不是；否則看方向
        $g = $d['groupInfo'][$gid];
        $e = $g['ending'];
        if ($g['line'] !== $p['line']) {
            continue;
        } elseif (!$loop && $e !== '' && isset($onPattern[$e])) {
            $ok = isset($after[$e]);
        } elseif (!$loop && $e !== '' && isset($lineStations[$p['system'] . '|' . $p['line']][$e])) {
            $ok = false;
        } else {
            $ok = $g['dir'] === '' || $g['dir'] === $p['dir'];
        }
        if (!$ok) {
            continue;
        }
        $groups++;
        foreach ($list as list($sec, $tt)) {
            // 車種只在時刻表有標（機場捷運普通車 1、直達車 2）時比對；其他系統 S2S 的 TrainType 與時刻表無關
            if ($p['tt'] > 0 && $tt !== '' && $tt !== (string) $p['tt']) {
                continue;
            }
            $out[] = [$sec, $gid];
        }
    }
    if ($groups > 1) {
        usort($out, function ($a, $b) { return $a[0] <=> $b[0]; });
    }
    return $d['depCache'][$ck] = $out;
}

/**
 * 某站的捷運站別時刻表（同一站只查一次），依時刻表群組（終點＋方向）分好：[群組 => [[發車秒, 車種代碼, 到站秒或 null], ...]]；
 * 到站秒只在 TDX 有真實到站時間（arr_src＝timetable）時才有。群組資訊（路線、終點、方向、來源）存在 $d['groupInfo']
 */
function transferStationDep(array &$d, $u)
{
    if (isset($d['stationDep'][$u])) {
        return $d['stationDep'][$u];
    }
    $byGroup = [];
    foreach ($d['db']->all('SELECT g.group_id, g.direction, g.info, g.source, d.sort_min, d.train_type_code, d.arr, d.arr_src
                  FROM metro_dep d JOIN metro_group g ON g.group_id = d.group_id
                  WHERE g.station_uid = ? AND (g.weekmask & ?) != 0 ORDER BY d.sort_min', [$u, $d['mask']]) as $r) {
        $gid = $r['group_id'];
        if (!isset($d['groupInfo'][$gid])) {
            $info = json_decode($r['info'], true);
            $d['groupInfo'][$gid] = ['line' => $info['LineIDs'][0] ?? '', 'ending' => $info['EndingStationUID'], 'dir' => (string) $r['direction'],
                                     'source' => $r['source'], 'endingName' => null];
        }
        $arr = null;
        if ($r['arr_src'] === 'timetable' && ($am = finderDbMinutes((string) $r['arr'])) !== null) {
            $arr = ($am < 240 ? $am + 1440 : $am) * 60;
            // 到站與離站跨過 04:00 分界時，以離站為準調整到同一營運日
            $dep = (int) $r['sort_min'] * 60;
            if ($arr > $dep + 3600) {
                $arr -= 86400;
            } elseif ($arr < $dep - 82800) {
                $arr += 86400;
            }
        }
        $byGroup[$gid][] = [(int) $r['sort_min'] * 60, (string) $r['train_type_code'], $arr];
    }
    return $d['stationDep'][$u] = $byGroup;
}

/** 第一個 ≥ t 的發車（二分搜尋）；沒有回 null */
function transferNextDep(array $list, $t)
{
    $lo = 0;
    $hi = count($list);
    while ($lo < $hi) {
        $mid = ($lo + $hi) >> 1;
        if ($list[$mid][0] < $t) {
            $lo = $mid + 1;
        } else {
            $hi = $mid;
        }
    }
    return $list[$lo] ?? null;
}

/**
 * 各站到 $to 最少要花幾秒（下界）：把每兩個相鄰停靠站之間「全部班次中最短的行駛時間」與群組內步行當成邊，
 * 從迄站反向跑 Dijkstra。不算等車、不算同站換車，所以一定不會高估，用來剪掉一定比較慢的路（A* 的下界）。
 * 到不了迄站的車站不在結果裡（＝無限大）。$fromSide = true 時反過來算「從 $to 這站出發到各站最少要多久」（反向搜尋用）。
 */
function transferLowerBounds(array &$d, $to, $fromSide = false)
{
    $ck = ($fromSide ? '>' : '<') . $to;
    if (isset($d['lb'][$ck])) {
        return $d['lb'][$ck];
    }
    if (!isset($d['revEdges'])) {
        $min = [];
        foreach ($d['trips'] as $tr) {
            for ($i = 0, $n = count($tr['st']) - 1; $i < $n; $i++) {
                $k = $tr['st'][$i + 1] . '<' . $tr['st'][$i];
                $w = max(0, $tr['arr'][$i + 1] - $tr['dep'][$i]);
                if ($w < ($min[$k] ?? PHP_INT_MAX)) {
                    $min[$k] = $w;
                }
            }
        }
        foreach ($d['patterns'] as $p) {
            for ($j = 0, $n = count($p['st']) - 1; $j < $n; $j++) {
                $k = $p['st'][$j + 1] . '<' . $p['st'][$j];
                $w = max(0, $p['arr'][$j + 1] - $p['dep'][$j]);
                if ($w < ($min[$k] ?? PHP_INT_MAX)) {
                    $min[$k] = $w;
                }
            }
        }
        foreach ($d['mroutes'] ?? [] as $mr) {
            for ($j = 0, $n = count($mr['st']) - 1; $j < $n; $j++) {
                $k = $mr['st'][$j + 1] . '<' . $mr['st'][$j];
                foreach ($mr['trips'] as $t) {
                    $w = max(0, $t['arr'][$j + 1] - $t['dep'][$j]);
                    if ($w < ($min[$k] ?? PHP_INT_MAX)) {
                        $min[$k] = $w;
                    }
                }
            }
        }
        // 兩站間直接的行駛時間（起訖矩陣）可能比相鄰各段加起來短，也當成邊，下界才不會高估
        foreach ($d['od'] ?? [] as $pairs) {
            foreach ($pairs as $pk => $x) {
                list($a, $b) = explode('>', $pk, 2);
                $k = $b . '<' . $a;
                if ($x[0] >= 0 && $x[0] < ($min[$k] ?? PHP_INT_MAX)) {
                    $min[$k] = $x[0];
                }
            }
        }
        $m = $d['m'];
        foreach ($m['groups'] as $g) {
            foreach ($g['Stations'] as $a) {
                foreach ($g['Stations'] as $b) {
                    $u = $a['StationUID'];
                    $v = $b['StationUID'];
                    if ($u !== $v && ($w = transferWalk($m, $u, $v, true, true))) {
                        $k = $v . '<' . $u;
                        $min[$k] = min($min[$k] ?? PHP_INT_MAX, (int) round($w['Minutes'] * 60));
                    }
                }
            }
        }
        $rev = [];
        $fwd = [];
        foreach ($min as $k => $w) {
            list($v, $u) = explode('<', $k, 2);
            $rev[$v][] = [$u, $w];
            $fwd[$u][] = [$v, $w];
        }
        $d['revEdges'] = $rev;
        $d['fwdEdges'] = $fwd;
    }
    $edges = $fromSide ? $d['fwdEdges'] : $d['revEdges'];
    $dist = [$to => 0];
    $pq = new SplPriorityQueue();
    $pq->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
    $pq->insert($to, 0);
    $done = [];
    while (!$pq->isEmpty()) {
        $x = $pq->extract();
        $v = $x['data'];
        if (isset($done[$v])) {
            continue;
        }
        $done[$v] = true;
        foreach ($edges[$v] ?? [] as list($u, $w)) {
            $nd = $dist[$v] + $w;
            if ($nd < ($dist[$u] ?? PHP_INT_MAX)) {
                $dist[$u] = $nd;
                $pq->insert($u, -$nd);
            }
        }
    }
    return $d['lb'][$ck] = $dist;
}

/**
 * 一次搜尋：t0 從 $from 出發到 $to，回傳每一輪改善迄站時的方案（搭 k 班車最早到達）
 */
function transferRaptor(array &$d, $from, $to, $t0, $maxTrips = TRANSFER_MAX_TRIPS, array $bounds = [], &$labels = null)
{
    // $to = null：一對多（不設迄站、不剪枝），各輪標籤由 $labels 帶回，給離線分析（learn.php）用
    $m = $d['m'];
    $INF = PHP_INT_MAX;
    $ready = [0 => [$from => $t0]];
    $readyPar = [0 => [$from => ['origin']]];
    $readyBest = [$from => $t0];
    // 起站步行到同群組其他車站
    foreach ($m['groupOf'][$from] ?? [] as $gid) {
        foreach ($m['groups'][$gid]['Stations'] as $x) {
            $v = $x['StationUID'];
            if ($v !== $from && ($w = transferWalk($m, $from, $v, true))) {
                $t = $t0 + $w['Minutes'] * 60;
                if ($t < ($readyBest[$v] ?? $INF)) {
                    $ready[0][$v] = $readyBest[$v] = $t;
                    $readyPar[0][$v] = ['walk', $from, $w];
                }
            }
        }
    }
    // 迄站：直接到達，或到同群組車站再步行
    $destVia = $to === null ? [] : [$to => ['Minutes' => 0]];
    foreach ($to === null ? [] : ($m['groupOf'][$to] ?? []) as $gid) {
        foreach ($m['groups'][$gid]['Stations'] as $x) {
            $v = $x['StationUID'];
            if ($v !== $to && ($w = transferWalk($m, $v, $to, true))) {
                $destVia[$v] = $w;
            }
        }
    }
    $arrBest = [];
    $arr = [];
    $arrPar = [];
    $bestDest = $t0 + TRANSFER_HORIZON;
    $lb = $to === null ? null : transferLowerBounds($d, $to);
    // 可選的加速限制（accel=1）：不展開的車站、不能同站換車的車站
    $blocked = $d['accel']['blocked'] ?? [];
    $noChange = $d['accel']['noChange'] ?? [];
    $results = [];
    for ($k = 1; $k <= $maxTrips; $k++) {
        $marked = $ready[$k - 1] ?? [];
        if (!$marked) {
            break;
        }
        $arr[$k] = [];
        $arrPar[$k] = [];
        // 剪枝：在 u 的時刻 + u 到迄站最少要花的時間（下界）已經不早於「目前最早到達」或「已知最多搭 k 班的最早到達」，
        // 這條路一定比較慢（或轉乘更多卻沒有更早），不必往下展開
        $cap = min($bestDest, $bounds[$k] ?? $INF);
        $improve = function ($u, $t, $par) use (&$arr, &$arrPar, &$arrBest, $k, $cap, $INF, &$lb, &$blocked) {
            if ($t < ($arrBest[$u] ?? $INF) && ($lb === null || $t + ($lb[$u] ?? $INF) < $cap) && !isset($blocked[$u])) {
                $arr[$k][$u] = $arrBest[$u] = $t;
                $arrPar[$k][$u] = $par;
            }
        };
        // 列車：每班車從最早可上車的停靠站開始掃；途中若有別的停靠站也趕得上同一班車、而且更早就能在那一站候車，
        // 就改從那一站上車（到達時刻不變，但前段少坐、候車餘裕較多，例如汐科坐區間車到南港上車，
        // 而不是往回坐到松山再上同一班自強號；也不會為了同一班車把區間車多坐到瑞芳）
        $board = [];
        foreach ($marked as $u => $t) {
            foreach ($d['stationTrips'][$u] ?? [] as list($ti, $i)) {
                if ($d['trips'][$ti]['dep'][$i] >= $t) {
                    $board[$ti][$i] = $t;
                }
            }
        }
        foreach ($board as $ti => $set) {
            $tr = $d['trips'][$ti];
            $n = count($tr['st']);
            $bi = min(array_keys($set));
            for ($i = $bi + 1; $i < $n; $i++) {
                $improve($tr['st'][$i], $tr['arr'][$i], ['trip', $ti, $bi, $i]);
                if (isset($set[$i]) && $set[$i] < $set[$bi]) {
                    $bi = $i;
                }
            }
        }
        // 捷運營運路線：沿站序掃，隨時改搭更早的發車
        $startAt = [];
        foreach ($marked as $u => $t) {
            foreach ($d['stationPatterns'][$u] ?? [] as list($pk, $j)) {
                if (!isset($startAt[$pk]) || $j < $startAt[$pk]) {
                    $startAt[$pk] = $j;
                }
            }
        }
        foreach ($startAt as $pk => $j0) {
            $p = $d['patterns'][$pk];
            $n = count($p['st']);
            $base = $INF;   // 等同在首站的離站時刻（上車發車秒 − 上車站離站偏移）
            $bj = null;
            $bdep = null;
            for ($j = $j0; $j < $n; $j++) {
                $u = $p['st'][$j];
                if ($base !== $INF && $j > $bj) {
                    // 到站＝上車站發車＋兩站間行駛時間（有直接資料用直接資料，否則一站一站加）
                    $improve($u, isset($p['od']) ? $bdep[0] + transferPatternTravel($d, $p, $bj, $j)[0] : $base + $p['arr'][$j],
                             ['pattern', $pk, $bj, $j, $bdep]);
                }
                if (isset($marked[$u]) && $j < $n - 1) {
                    $dep = transferNextDep(transferPatternDepartures($d, $pk, $j), $marked[$u]);
                    if ($dep !== null && ($dep[0] - $p['dep'][$j] < $base
                            || ($dep[0] - $p['dep'][$j] === $base && $marked[$u] < $marked[$p['st'][$bj]]))) {   // 同一班車時改從更早能候車的站上車
                        $base = $dep[0] - $p['dep'][$j];
                        $bj = $j;
                        $bdep = $dep;
                    }
                }
            }
        }
        // 捷運、輕軌整列車：沿路線站序掃，每站可改搭更早的一班（RAPTOR 的路線掃描）
        $startAt = [];
        foreach ($marked as $u => $t) {
            foreach ($d['stationMRoutes'][$u] ?? [] as list($rk, $j)) {
                if (!isset($startAt[$rk]) || $j < $startAt[$rk]) {
                    $startAt[$rk] = $j;
                }
            }
        }
        foreach ($startAt as $rk => $j0) {
            $mr = $d['mroutes'][$rk];
            $n = count($mr['st']);
            $ti = null;
            $bj = null;
            for ($j = $j0; $j < $n; $j++) {
                $u = $mr['st'][$j];
                if ($ti !== null && $j > $bj) {
                    $improve($u, $mr['trips'][$ti]['arr'][$j], ['mrun', $rk, $bj, $j, $ti]);
                }
                if (isset($marked[$u]) && $j < $n - 1) {
                    $x = transferMRouteNext($d, $rk, $j, $marked[$u]);
                    if ($x !== null && ($ti === null || $mr['trips'][$x]['dep'][$j] < $mr['trips'][$ti]['dep'][$j]
                            || ($x === $ti && $marked[$u] < $marked[$mr['st'][$bj]]))) {   // 同一班車時改從更早能候車的站上車
                        $ti = $x;
                        $bj = $j;
                    }
                }
            }
        }
        // 轉乘：同站換車、群組內步行
        $ready[$k] = [];
        $readyPar[$k] = [];
        foreach ($arr[$k] as $u => $t) {
            if (isset($m['change'][$u]) && !isset($noChange[$u])) {
                $r = $t + (int) round($m['change'][$u]['Minutes'] * 60);
                if ($r < ($readyBest[$u] ?? $INF) && ($lb === null || $r + ($lb[$u] ?? $INF) < $cap)) {
                    $ready[$k][$u] = $readyBest[$u] = $r;
                    $readyPar[$k][$u] = ['change', $u, $m['change'][$u]];
                }
            }
            foreach ($m['groupOf'][$u] ?? [] as $gid) {
                foreach ($m['groups'][$gid]['Stations'] as $x) {
                    $v = $x['StationUID'];
                    if ($v === $u || !($w = transferWalk($m, $u, $v))) {
                        continue;
                    }
                    $r = $t + (int) round($w['Minutes'] * 60);
                    if ($r < ($readyBest[$v] ?? $INF) && ($lb === null || $r + ($lb[$v] ?? $INF) < $cap) && !isset($blocked[$v])) {
                        $ready[$k][$v] = $readyBest[$v] = $r;
                        $readyPar[$k][$v] = ['walk', $u, $w + ['TransferGroupID' => $gid]];
                    }
                }
            }
        }
        // 迄站
        $bestHere = null;
        foreach ($destVia as $v => $w) {
            if (isset($arr[$k][$v])) {
                $t = $arr[$k][$v] + (int) round($w['Minutes'] * 60);
                if ($bestHere === null || $t < $bestHere[0]) {
                    $bestHere = [$t, $v, $w];
                }
            }
        }
        if ($bestHere !== null && $bestHere[0] < $bestDest) {
            $bestDest = $bestHere[0];
            $results[] = transferJourney($d, $k, $bestHere, $arrPar, $readyPar, $arr, $ready, $to, $t0);
        }
    }
    $labels = ['arr' => $arr, 'arrPar' => $arrPar, 'readyPar' => $readyPar];
    return $results;
}

/** 由各輪的來源指標組出一個方案 */
function transferJourney(array &$d, $k, array $end, array $arrPar, array $readyPar, array $arr, array $ready, $to, $t0)
{
    $m = $d['m'];
    $steps = [];
    list($tEnd, $v, $w) = $end;
    if ($v !== $to) {
        $steps[] = ['Type' => 'walk', 'FromStationUID' => $v, 'ToStationUID' => $to, 'Minutes' => $w['Minutes'], 'Source' => $w['Source'] ?? null,
                    'TransferGroupID' => $w['TransferGroupID'] ?? null];
    }
    $u = $v;
    for ($r = $k; $r >= 1; $r--) {
        $par = $arrPar[$r][$u];
        $ride = transferRideStep($d, $par, $arr[$r][$u]);
        $steps[] = $ride;
        $boardAt = $ride['FromStationUID'];
        $rp = $readyPar[$r - 1][$boardAt];
        if ($rp[0] === 'origin') {
            break;
        }
        if ($rp[0] === 'change') {
            $steps[] = ['Type' => 'train_change', 'FromStationUID' => $boardAt, 'ToStationUID' => $boardAt, 'Minutes' => $rp[2]['Minutes'],
                        'Source' => $rp[2]['Source'], 'TransferGroupID' => ($m['groupOf'][$boardAt][0] ?? null)];
            $u = $boardAt;
        } else {
            $steps[] = ['Type' => 'walk', 'FromStationUID' => $rp[1], 'ToStationUID' => $boardAt, 'Minutes' => $rp[2]['Minutes'],
                        'Source' => $rp[2]['Source'], 'TransferGroupID' => $rp[2]['TransferGroupID'] ?? null];
            $u = $rp[1];
            if ($r - 1 === 0) {
                break;   // 起站步行到同群組車站
            }
        }
    }
    $steps = array_reverse($steps);
    // 換乘等候時間
    $prevArr = null;
    $rides = 0;
    $walkMin = 0;
    $tiers = [];
    $km = 0.0;
    $kmSrc = 'tdx';
    foreach ($steps as &$s) {
        if ($s['Type'] === 'ride') {
            $rides++;
            $tiers[] = $s['DataTier'];
            $prevArr = $s['_arr'];
            if ($km !== null && $s['DistanceKm'] !== null) {
                $km += $s['DistanceKm'];
                $kmSrc = $s['DistanceSource'] === 'estimate' ? 'estimate' : $kmSrc;
            } else {
                $km = null;
            }
        } else {
            $walkMin += $s['Type'] === 'walk' ? $s['Minutes'] : 0;
        }
    }
    unset($s);
    $firstRide = null;
    $lastRide = null;
    foreach ($steps as $s) {
        if ($s['Type'] === 'ride') {
            $firstRide = $firstRide ?? $s;
            $lastRide = $s;
        }
    }
    // 出發時刻：第一班車發車（起站先步行時往前推步行時間）
    $depSec = $firstRide['_dep'];
    if ($steps[0]['Type'] === 'walk') {
        $depSec -= (int) round($steps[0]['Minutes'] * 60);
    }
    // 各段搭乘的上下車站與時刻（秒），給「長時間轉乘等候時把前段改搭更晚的車」用
    $rideTimes = [];
    foreach ($steps as $i => $s) {
        if ($s['Type'] === 'ride') {
            $rideTimes[] = [$s['FromStationUID'], $s['_dep'], $s['ToStationUID'], $s['_arr'], $i];
        }
    }
    $names = $m['names'];
    foreach ($steps as &$s) {
        foreach (['From', 'To'] as $p) {
            $s[$p . 'StationName'] = $names[$s[$p . 'StationUID']] ?? null;
        }
        unset($s['_arr'], $s['_dep']);
        // 轉乘實景影片（transfer_seed.json 的 Videos）
        if ($s['Type'] !== 'ride' && ($vid = transferVideos($s['FromStationUID'], $s['ToStationUID']))) {
            $s['Videos'] = $vid;
        }
    }
    unset($s);
    $tier = 'real';
    foreach ($tiers as $t) {
        $tier = finderDbWorstTier($tier, $t);
    }
    return [
        'DepartureTime'   => transferHM($depSec),
        'ArrivalTime'     => transferHM($tEnd),
        'DurationMinutes' => (int) round(($tEnd - $depSec) / 60),
        'WaitMinutes'     => (int) round(($depSec - $t0) / 60),
        'TransferCount'   => $rides - 1,
        'RideCount'       => $rides,
        'WalkMinutes'     => $walkMin,
        'DistanceKm'      => $km === null ? null : round($km, 1),
        'DistanceSource'  => $km === null ? null : $kmSrc,
        'DataTier'        => $tier,
        'Steps'           => $steps,
        '_dep'            => $depSec,
        '_arr'            => $tEnd,
        '_rides'          => $rideTimes,
        '_sig'            => implode('>', array_map(function ($s) { return $s['Type'] === 'ride' ? ($s['TrainNo'] ?? $s['RouteID']) . '@' . $s['DepartureTime'] : $s['Type']; }, $steps)),
    ];
}

/** 由 func=route 方案的 Steps 組出 Pattern（給前端送回 func=follow，見 follow.php） */
function transferPatternFromSteps($from, $to, array $steps)
{
    $p = [['Type' => 'Origin', 'StationUID' => $from]];
    foreach ($steps as $s) {
        if ($s['Type'] === 'ride') {
            $p[] = ['Type' => 'Line', 'LineUID' => $s['LineUID'], 'System' => $s['System'], 'Mode' => $s['Mode']];
        } else {
            $p[] = ['Type' => 'Transfer', 'From' => $s['FromStationUID'], 'To' => $s['ToStationUID'],
                    'Kind' => $s['Type'], 'DefaultMinutes' => $s['Minutes'], 'Minutes' => null] + (isset($s['Videos']) ? ['Videos' => $s['Videos']] : []);
        }
    }
    $p[] = ['Type' => 'Destination', 'StationUID' => $to];
    return $p;
}

/** 搭乘段（列車或捷運）的輸出 */
function transferRideStep(array &$d, array $par, $arrSec)
{
    static $trainInfo = [];
    if ($par[0] === 'trip') {
        list(, $ti, $i0, $i1) = $par;
        $tr = $d['trips'][$ti];
        if (!isset($trainInfo[$tr['key']])) {
            $trainInfo[$tr['key']] = json_decode((string) $d['db']->value('SELECT info FROM train WHERE train_key = ?', [$tr['key']]), true);
        }
        $info = $trainInfo[$tr['key']];
        list($km, $kmSrc) = transferRideDistance($tr['system'], array_slice($tr['st'], $i0, $i1 - $i0 + 1), null, (int) ($info['TripLine'] ?? 0));
        return [
            'Type' => 'ride', 'Mode' => 'train', 'System' => $tr['system'],
            'LineUID' => $tr['system'] . '-' . ($info['LineIDs'][0] ?? ($tr['system'] === 'THSR' ? 'HSRL' : '')),
            'DisplayNo' => $info['TrainNo'] ?? '', 'DisplayNoType' => 'TrainNo',
            'TrainKey' => $tr['key'], 'TrainNo' => $info['TrainNo'] ?? '', 'TrainTypeName' => $info['TrainTypeName'] ?? null,
            'Direction' => $info['Direction'] ?? null, 'EndingStationUID' => $info['EndingStationUID'] ?? null,
            'EndingStationName' => $info['EndingStationName'] ?? null,
            'FromStationUID' => $tr['st'][$i0], 'ToStationUID' => $tr['st'][$i1],
            'DepartureTime' => transferHM($tr['dep'][$i0]), 'ArrivalTime' => transferHM($tr['arr'][$i1]),
            'Minutes' => (int) round(($tr['arr'][$i1] - $tr['dep'][$i0]) / 60), 'Stops' => $i1 - $i0,
            'DepartureSource' => 'timetable', 'ArrivalSource' => 'timetable', 'DataTier' => 'real',
            'DistanceKm' => $km, 'DistanceSource' => $kmSrc,
            '_dep' => $tr['dep'][$i0], '_arr' => $tr['arr'][$i1], '_par' => $par,
        ];
    }
    if ($par[0] === 'mrun') {
        return transferRideStepMRun($d, $par);
    }
    list(, $pk, $j0, $j1, $dep) = $par;
    $p = $d['patterns'][$pk];
    $g = $d['groupInfo'][$dep[1]];
    $travelSrc = transferPatternTravel($d, $p, $j0, $j1)[1];
    $depTier = finderDbTimetableTier($g['source']);
    list($km, $kmSrc) = transferRideDistance($p['system'], array_slice($p['st'], $j0, $j1 - $j0 + 1), [$p['line']]);
    return [
        'Type' => 'ride', 'Mode' => 'metro', 'System' => $p['system'], 'LineUID' => $p['system'] . '-' . $p['line'],
        'DisplayNo' => $p['route'], 'DisplayNoType' => 'RouteID',
        'RouteID' => $p['route'], 'Direction' => (int) $p['dir'], 'TrainType' => $p['tt'] ?: null,
        'EndingStationUID' => $g['ending'], 'EndingStationName' => $d['m']['names'][$g['ending']] ?? null,
        'FromStationUID' => $p['st'][$j0], 'ToStationUID' => $p['st'][$j1],
        'DepartureTime' => transferHM($dep[0]), 'ArrivalTime' => transferHM($arrSec),
        'Minutes' => (int) round(($arrSec - $dep[0]) / 60), 'Stops' => $j1 - $j0,
        'DepartureSource' => $g['source'], 'TravelSource' => $travelSrc, 'ArrivalSource' => 'estimate',
        'DataTier' => finderDbWorstTier('mixed', $depTier, finderDbTravelTier($travelSrc)),
        'DistanceKm' => $km, 'DistanceSource' => $kmSrc,
        '_dep' => $dep[0], '_arr' => $arrSec, '_par' => $par,
    ];
}

/** 整列車（runs.php）的搭乘段輸出：上下車時刻都來自這班車對到的站別時刻表 */
function transferRideStepMRun(array &$d, array $par)
{
    list(, $rk, $j0, $j1, $ti) = $par;
    $mr = $d['mroutes'][$rk];
    $t = $mr['trips'][$ti];
    $stations = array_slice($mr['st'], $j0, $j1 - $j0 + 1);
    list($km, $kmSrc) = transferRideDistance($mr['system'], $stations, [$mr['line']]);
    $arrSrc = $t['src'][$j1];
    $depSrc = $t['src'][$j0];
    $tier = $depSrc === 'timetable' && $arrSrc === 'timetable' ? 'real' : ($arrSrc === 'interpolated' ? 'mixed' : finderDbWorstTier('mixed', $depSrc === 'timetable' ? 'real' : 'mixed'));
    return [
        'Type' => 'ride', 'Mode' => 'metro', 'System' => $mr['system'], 'LineUID' => $mr['system'] . '-' . $mr['line'],
        'DisplayNo' => $t['route'], 'DisplayNoType' => 'RouteID',
        'RouteID' => $t['route'], 'Direction' => (int) $t['dir'], 'TrainType' => $t['tt'] ?: null,
        'EndingStationUID' => $t['ending'], 'EndingStationName' => $d['m']['names'][$t['ending']] ?? null,
        'FromStationUID' => $mr['st'][$j0], 'ToStationUID' => $mr['st'][$j1],
        'DepartureTime' => transferHM($t['dep'][$j0]), 'ArrivalTime' => transferHM($t['arr'][$j1]),
        'Minutes' => (int) round(($t['arr'][$j1] - $t['dep'][$j0]) / 60), 'Stops' => $j1 - $j0,
        'DepartureSource' => $depSrc, 'TravelSource' => 'timetable-run', 'ArrivalSource' => $arrSrc,
        'DataTier' => $tier,
        'DistanceKm' => $km, 'DistanceSource' => $kmSrc,
        '_dep' => $t['dep'][$j0], '_arr' => $t['arr'][$j1], '_par' => $par,
    ];
}

/**
 * 搭乘段途中各站（含上下車站）的時刻：[['StationUID', 'StationName', 'ArrivalTime', 'DepartureTime', 'Source'], ...]
 *
 *   臺鐵、高鐵：列車時刻表的到離站時刻（Source＝timetable）
 *   捷運、輕軌：TDX 沒有整列車時刻表，只有上車站、下車站的時刻有依據——
 *     上車站＝上車站時刻表的發車；下車站＝這班車在下車站的時刻（整列車 runs.php 對到下車站時刻表的時刻；營運路線用 $arrSec，
 *     沒給時＝發車＋行駛時間推算）；中間各站不參考各站時刻表，依站間行駛時間（transferPatternTravel，含停站）的比例
 *     把上下車站之間的時間分配給各站（Source＝interpolated，只給離站時刻）。
 *     各站時刻表可能有錯（例如新北環狀線景安與中和同一分鐘），而且文湖線、臺中捷運的站別時刻表本身就是推算的，所以中間站一律推算
 */
function transferRideStops(array &$d, array $par, $arrSec = null)
{
    $names = $d['m']['names'];
    $hm = function ($s) { return $s === null ? null : transferHM($s); };
    $out = [];
    if ($par[0] === 'trip') {
        list(, $ti, $i0, $i1) = $par;
        $tr = $d['trips'][$ti];
        for ($i = $i0; $i <= $i1; $i++) {
            $out[] = ['StationUID' => $tr['st'][$i], 'StationName' => $names[$tr['st'][$i]] ?? null,
                      'ArrivalTime' => $i === $i0 ? null : $hm($tr['arr'][$i]), 'DepartureTime' => $i === $i1 ? null : $hm($tr['dep'][$i]),
                      'Source' => 'timetable'];
        }
        return $out;
    }
    if ($par[0] === 'mrun') {
        list(, $rk, $j0, $j1, $ti) = $par;
        $mr = $d['mroutes'][$rk];
        $t = $mr['trips'][$ti];
        $st = array_slice($mr['st'], $j0, $j1 - $j0 + 1);
        // 站間行駛時間用這班車所屬的營運路線（整列車的站序是營運路線的一段）
        $p = $d['patterns'][$t['pk'] ?? ''] ?? null;
        $k0 = null;
        if ($p !== null) {
            $n = count($mr['st']);
            for ($k = 0; $k + $n <= count($p['st']); $k++) {
                if (array_slice($p['st'], $k, $n) === $mr['st']) {
                    $k0 = $k + $j0;
                    break;
                }
            }
        }
        return transferRideStopsSpread($d, $st, $k0 === null ? null : $p, (int) $k0, $t['dep'][$j0], $t['arr'][$j1],
                                       $t['src'][$j0], $t['src'][$j1]);
    }
    list(, $pk, $j0, $j1, $dep) = $par;
    $p = $d['patterns'][$pk];
    $g0 = $d['groupInfo'][$dep[1]];
    $st = array_slice($p['st'], $j0, $j1 - $j0 + 1);
    return transferRideStopsSpread($d, $st, $p, $j0, $dep[0], $arrSec, $g0['source'] === 'timetable' ? 'timetable' : 'estimate', 'estimate');
}

/**
 * 捷運搭乘段各站時刻：上車站 $dep、下車站 $arr（null＝發車＋行駛時間推算）固定，中間各站依站間行駛時間比例分配（Source＝interpolated）。
 * $p＝營運路線、$k0＝$st[0] 在營運路線的索引（$p 為 null 時各站間隔視為相同）
 */
function transferRideStopsSpread(array &$d, array $st, $p, $k0, $dep, $arr, $depSrc, $arrSrc)
{
    $names = $d['m']['names'];
    $m = count($st);
    // 累計行駛時間（中間站含停站）
    $cum = [0];
    for ($i = 1; $i < $m; $i++) {
        $seg = 60;
        if ($p !== null) {
            $seg = max(1, (int) transferPatternTravel($d, $p, $k0 + $i - 1, $k0 + $i)[0]);
            if ($i < $m - 1) {
                $seg += max(0, $p['dep'][$k0 + $i] - $p['arr'][$k0 + $i]);
            }
        }
        $cum[$i] = $cum[$i - 1] + $seg;
    }
    $total = $cum[$m - 1];
    if ($arr === null) {
        $arr = $dep + $total;
        $arrSrc = 'estimate';
    }
    $arr = max($arr, $dep);
    $out = [];
    foreach ($st as $i => $u) {
        if ($i === 0) {
            $out[] = ['StationUID' => $u, 'StationName' => $names[$u] ?? null, 'ArrivalTime' => null, 'DepartureTime' => transferHM($dep), 'Source' => $depSrc];
        } elseif ($i === $m - 1) {
            $out[] = ['StationUID' => $u, 'StationName' => $names[$u] ?? null, 'ArrivalTime' => transferHM($arr), 'DepartureTime' => null, 'Source' => $arrSrc];
        } else {
            // 時刻表只到分鐘：兩端各取該分鐘的中間（＋30 秒）再按比例分配
            $tSec = (int) round($dep + 30 + ($arr - $dep) * ($total > 0 ? $cum[$i] / $total : $i / ($m - 1)));
            $out[] = ['StationUID' => $u, 'StationName' => $names[$u] ?? null, 'ArrivalTime' => null, 'DepartureTime' => transferHM($tSec), 'Source' => 'interpolated'];
        }
    }
    return $out;
}

/** 方案的搭乘段補上途中各站時刻（StopTimes），並移除內部欄位 _par；$withStops = false 時只移除 */
function transferAddStops(array &$d, array &$steps, $withStops = true)
{
    foreach ($steps as &$s) {
        if (isset($s['_par'])) {
            if ($withStops) {
                $s['StopTimes'] = transferRideStops($d, $s['_par'], $s['_arr'] ?? null);
            }
            unset($s['_par']);
        }
    }
    unset($s);
}

/** 最後一個 ≤ t 的發車（二分搜尋）；沒有回 null */
function transferPrevDep(array $list, $t)
{
    $lo = 0;
    $hi = count($list);
    while ($lo < $hi) {
        $mid = ($lo + $hi) >> 1;
        if ($list[$mid][0] <= $t) {
            $lo = $mid + 1;
        } else {
            $hi = $mid;
        }
    }
    return $lo > 0 ? $list[$lo - 1] : null;
}

/**
 * 反向搜尋：最晚幾點從 $from 出發、最多搭 $rides 班車，還能在 $arrive 之前到 $to（營運日秒）；沒有回 null。
 * 用來跳過重複的搜尋：例如高雄→東湖最快是「臺鐵或高雄捷運到新左營／左營轉高鐵 0112」，
 * 05:03、05:28 出發都趕上同一班高鐵同時到；反向一次算出最晚 05:28，就不必一班一班往後試。
 * 只看 [$floor, $arrive] 之間的時刻（比 $floor 早的上車時刻不可能讓出發變晚，直接剪掉；找到一個出發時刻後 $floor 提高到它）；
 * 已經不早於 $cap 就停（呼叫端只需要知道是否比 $cap 早）。
 */
function transferLatestDeparture(array &$d, $from, $to, $arrive, $rides, $floor, $cap = null)
{
    $m = $d['m'];
    $NEG = PHP_INT_MIN;
    // 下車期限：迄站本身，或步行到迄站的同群組車站
    $A = [$to => $arrive];
    foreach ($m['groupOf'][$to] ?? [] as $gid) {
        foreach ($m['groups'][$gid]['Stations'] as $x) {
            $v = $x['StationUID'];
            if ($v !== $to && ($w = transferWalk($m, $v, $to, true))) {
                $A[$v] = max($A[$v] ?? $NEG, $arrive - (int) round($w['Minutes'] * 60));
            }
        }
    }
    // 起站與步行可到的同群組車站（出發要往前推步行時間）
    $orig = [$from => 0];
    foreach ($m['groupOf'][$from] ?? [] as $gid) {
        foreach ($m['groups'][$gid]['Stations'] as $x) {
            $v = $x['StationUID'];
            if ($v !== $from && ($w = transferWalk($m, $from, $v, true))) {
                $orig[$v] = min($orig[$v] ?? PHP_INT_MAX, (int) round($w['Minutes'] * 60));
            }
        }
    }
    // 剪枝：在 u 最晚 B 上車，起站最晚也只能在 B −（起站到 u 最少要花的時間）出發；不比 $floor 晚就沒有用
    $lbFrom = transferLowerBounds($d, $from, true);
    $blocked = $d['accel']['blocked'] ?? [];
    $noChange = $d['accel']['noChange'] ?? [];
    $best = null;
    $Abest = $A;
    $Bbest = [];
    for ($k = 1; $k <= $rides && $A; $k++) {
        $B = [];   // 最晚上車時刻
        // 臺鐵、高鐵：每班車取最後一個趕得上期限的下車站，往前每站都是可上車站
        $tripMax = [];
        foreach ($A as $x => $dl) {
            foreach ($d['stationTrips'][$x] ?? [] as list($ti, $ix)) {
                if ($d['trips'][$ti]['arr'][$ix] <= $dl && $ix > ($tripMax[$ti] ?? -1)) {
                    $tripMax[$ti] = $ix;
                }
            }
        }
        foreach ($tripMax as $ti => $ix) {
            $tr = $d['trips'][$ti];
            for ($i = $ix - 1; $i >= 0; $i--) {
                $dep = $tr['dep'][$i];
                if ($dep < $floor) {
                    break;
                }
                $u = $tr['st'][$i];
                if ($dep > ($B[$u] ?? $NEG) && $dep > ($Bbest[$u] ?? $NEG) && $dep - ($lbFrom[$u] ?? PHP_INT_MAX >> 1) > $floor && !isset($blocked[$u])) {
                    $B[$u] = $dep;
                }
            }
        }
        // 捷運、輕軌：沿營運路線往前，每站找最後一班趕得上的發車
        foreach ($A as $x => $dl) {
            foreach ($d['stationPatterns'][$x] ?? [] as list($pk, $ix)) {
                $p = $d['patterns'][$pk];
                for ($j = $ix - 1; $j >= 0; $j--) {
                    $lim = $dl - transferPatternTravel($d, $p, $j, $ix)[0];
                    if ($lim < $floor) {
                        if (isset($p['od'])) {
                            continue;   // 直接資料不一定隨站數遞增，不能提早結束
                        }
                        break;
                    }
                    if ($lim - ($lbFrom[$p['st'][$j]] ?? PHP_INT_MAX >> 1) <= $floor) {
                        continue;   // 起站到這站太遠，從這站上車不可能讓出發變晚（不必載入這站的時刻表）
                    }
                    $dep = transferPrevDep(transferPatternDepartures($d, $pk, $j), $lim);
                    if ($dep === null || $dep[0] < $floor) {
                        continue;
                    }
                    $u = $p['st'][$j];
                    if ($dep[0] > ($B[$u] ?? $NEG) && $dep[0] > ($Bbest[$u] ?? $NEG) && $dep[0] - ($lbFrom[$u] ?? PHP_INT_MAX >> 1) > $floor && !isset($blocked[$u])) {
                        $B[$u] = $dep[0];
                    }
                }
            }
        }
        // 捷運、輕軌整列車：到站不晚於期限的最晚一班，往前各站都是可上車站
        foreach ($A as $x => $dl) {
            foreach ($d['stationMRoutes'][$x] ?? [] as list($rk, $ix)) {
                if ($ix === 0) {
                    continue;
                }
                $mr = $d['mroutes'][$rk];
                $cands = [];
                if ($mr['fifo']) {
                    $ti = transferMRoutePrev($d, $rk, $ix, $dl);
                    if ($ti !== null) {
                        $cands[] = $ti;
                    }
                } else {
                    foreach ($mr['trips'] as $ti => $t) {
                        if ($t['arr'][$ix] <= $dl) {
                            $cands[] = $ti;
                        }
                    }
                }
                foreach ($cands as $ti) {
                    $t = $mr['trips'][$ti];
                    for ($j = $ix - 1; $j >= 0; $j--) {
                        $dep = $t['dep'][$j];
                        if ($dep < $floor) {
                            break;
                        }
                        $u = $mr['st'][$j];
                        if ($dep > ($B[$u] ?? $NEG) && $dep > ($Bbest[$u] ?? $NEG) && $dep - ($lbFrom[$u] ?? PHP_INT_MAX >> 1) > $floor && !isset($blocked[$u])) {
                            $B[$u] = $dep;
                        }
                    }
                }
            }
        }
        foreach ($orig as $o => $wo) {
            if (isset($B[$o]) && ($best === null || $B[$o] - $wo > $best)) {
                $best = $B[$o] - $wo;
            }
        }
        if ($best !== null) {
            if ($cap !== null && $best >= $cap) {
                return $best;
            }
            $floor = max($floor, $best);   // 出發不會晚於途中任何一次上車，比目前最晚出發還早的標籤沒用
        }
        foreach ($B as $u => $t) {
            $Bbest[$u] = $t;
        }
        // 往前一段：同站換車、群組內步行，得到上一班車的下車期限
        $A = [];
        foreach ($B as $u => $t) {
            if (isset($m['change'][$u]) && !isset($noChange[$u])) {
                $a = $t - (int) round($m['change'][$u]['Minutes'] * 60);
                if ($a - ($lbFrom[$u] ?? PHP_INT_MAX >> 1) > $floor && $a > ($Abest[$u] ?? $NEG)) {
                    $A[$u] = $Abest[$u] = $a;
                }
            }
            foreach ($m['groupOf'][$u] ?? [] as $gid) {
                foreach ($m['groups'][$gid]['Stations'] as $x) {
                    $v = $x['StationUID'];
                    if ($v === $u || !($w = transferWalk($m, $v, $u))) {
                        continue;
                    }
                    $a = $t - (int) round($w['Minutes'] * 60);
                    if ($a - ($lbFrom[$v] ?? PHP_INT_MAX >> 1) > $floor && $a > ($Abest[$v] ?? $NEG) && !isset($blocked[$v])) {
                        $A[$v] = $Abest[$v] = $a;
                    }
                }
            }
        }
    }
    return $best;
}

/**
 * 方案中轉乘等候超過 TRANSFER_SHIFT_WAIT 時，把最後一次長等候之前的搭乘改成最晚還趕得上的班次：
 * 反向搜尋算出最晚出發，再從那裡正向搜一次，取同樣班數、不晚到的方案替換。到達與班數不變，只是晚點出發、少等
 */
function transferShiftLongWait(array &$d, $from, $to, array $j)
{
    $r = $j['_rides'];
    $k = null;
    for ($i = count($r) - 1; $i >= 1; $i--) {
        if ($r[$i][1] - $r[$i - 1][3] > TRANSFER_SHIFT_WAIT) {
            $k = $i;
            break;
        }
    }
    if ($k === null) {
        return $j;
    }
    // 第 k 段的上車站與期限（同站換車要扣換車時間）
    $s = $r[$k][0];
    $m = $d['m'];
    $deadline = $r[$k][1] - (isset($m['change'][$s]) ? (int) round($m['change'][$s]['Minutes'] * 60) : 0);
    $L = transferLatestDeparture($d, $from, $s, $deadline, $k, $j['_dep']);
    if ($L === null || $L <= $j['_dep']) {
        return $j;
    }
    foreach (transferRaptor($d, $from, $to, $L) as $x) {
        if ($x['RideCount'] <= $j['RideCount'] && $x['_arr'] <= $j['_arr'] && $x['_dep'] > $j['_dep']) {
            return $x;
        }
    }
    return $j;
}

/**
 * 轉乘查詢：從 start 起多次搜尋（每次從上一批方案的最早發車之後再找），收集不互相支配的方案，
 * 再挑出直通解、最少轉乘、最快、最佳。$runs：最多搜尋幾次；$end：最晚出發（營運日秒，null 不限）
 */
function transferRoute(SqliteDao $db, $from, $to, $week, $t0, $runs = 6, $end = null, $budget = TRANSFER_ROUTE_BUDGET, $jump = true, $prune = true, $accel = null, $stops = true)
{
    $d = transferRouterData($db, $week);
    // 加速清單：$accel = true／false，或 null（有算好、未過期的 transfer_accel_learned.json 才套用）
    $d['accel'] = transferAccelWanted($d, $accel === null ? null : ($accel ? '1' : '0')) ? transferAccelPlan($d, $from, $to) : null;
    $all = [];
    $t = $t0;
    $clock = microtime(true);
    $done = 0;
    $jumps = 0;
    $backward = 0;
    $prevSeen = [];
    $prevSeen2 = [];
    $stop = 'runs';             // 為什麼停止：runs（次數用完）、end（超過最晚出發）、none（之後沒有方案）、budget（時間用完）
    for ($i = 0; $i < $runs; $i++) {
        if ($end !== null && $t > $end) {
            $stop = 'end';
            break;
        }
        if (microtime(true) - $clock > $budget) {
            $stop = 'budget';
            break;
        }
        // 已找到、而且出發不早於 t 的方案仍然有效：依搭車班數取「最多搭 k 班最早到達」當上界，這次搜尋從第一班車就能剪枝
        $bounds = [];
        $carryDep = null;
        foreach ($all as $j) {
            if ($j['_dep'] >= $t) {
                $bounds[$j['RideCount']] = min($bounds[$j['RideCount']] ?? PHP_INT_MAX, $j['_arr']);
                $carryDep = $carryDep === null ? $j['_dep'] : min($carryDep, $j['_dep']);
            }
        }
        $cur = PHP_INT_MAX;
        for ($k = 1; $k <= TRANSFER_MAX_TRIPS; $k++) {
            $cur = min($cur, $bounds[$k] ?? PHP_INT_MAX);
            $bounds[$k] = $cur;
        }
        $res = transferRaptor($d, $from, $to, $t, TRANSFER_MAX_TRIPS, $prune ? $bounds : []);
        $done++;
        if (!$res && $carryDep === null) {
            $stop = 'none';
            break;
        }
        $minDep = $prune ? $carryDep : null;
        $keepRes = [];
        foreach ($res as $j) {
            if ($end !== null && $j['_dep'] > $end) {
                continue;
            }
            $all[$j['_sig']] = $j;
            $keepRes[] = $j;
            $minDep = $minDep === null ? $j['_dep'] : min($minDep, $j['_dep']);
        }
        if ($minDep === null) {
            $stop = 'end';
            break;
        }
        // 反向算出每個方案「同樣搭 k 班車、同樣時刻到達，最晚幾點出發」（L）。在 [出發, L] 之間出發的方案不會更早到、
        // 轉乘也不會更少，下一次可以直接從 min(L) 搜。依出發先後算，L 不會比自己的出發早，所以出發已不早於目前 min(L) 的不用算。
        // 只有跟前兩次搜尋到達時刻、搭車班數都一樣（＝往後一班一班試只換到更晚的接駁車，已經連續發生）的方案才值得反向算；
        // 只重複一次就反向算常常白算（反向搜尋第一次要載入沿線捷運時刻表，約 0.1 秒）
        usort($keepRes, function ($a, $b) { return $a['_dep'] <=> $b['_dep']; });
        // 沿用的已知方案不反向算，它的出發就是上限（不能跳過它之後、可能更晚出發同樣到達的方案）
        $latest = $prune ? $carryDep : null;
        $seen = [];
        foreach ($keepRes as $j) {
            $seen[$j['_arr'] . '|' . $j['RideCount']] = true;
        }
        foreach ($keepRes as $j) {
            if ($latest !== null && $j['_dep'] >= $latest) {
                break;
            }
            $L = $j['_dep'];
            if ($jump && isset($prevSeen[$j['_arr'] . '|' . $j['RideCount']], $prevSeen2[$j['_arr'] . '|' . $j['RideCount']])) {
                $backward++;
                $f = transferLatestDeparture($d, $from, $to, $j['_arr'], $j['RideCount'], $j['_dep'], $latest);
                if ($f !== null && $f > $L) {
                    $L = $f;
                }
            }
            $latest = $latest === null ? $L : min($latest, $L);
            if ($latest === $minDep) {
                break;     // 最早出發的方案沒辦法再晚，不會跳
            }
        }
        $prevSeen2 = $prevSeen;
        $prevSeen = $seen;
        if ($jump && $latest !== null && $latest > $minDep) {
            $t = $latest;      // 下一次從最晚可出發的時刻搜，直接得到「晚出發、同樣早到」的方案
            $jumps++;
        } else {
            $t = max($t + 60, $minDep + 60);
        }
    }
    $all = array_values($all);
    // 不互相支配：較晚出發、較早到達、轉乘較少（全部不差、至少一項較好才算支配）。里程不列入：
    // 時間都一樣只差里程的方案（例如高雄到左營坐捷運或臺鐵）不多列，最短距離從留下的方案中挑
    $kmOf = function ($j) { return $j['DistanceKm'] ?? INF; };
    $keep = [];
    foreach ($all as $a) {
        $dominated = false;
        foreach ($all as $b) {
            if ($a === $b) {
                continue;
            }
            if ($b['_dep'] >= $a['_dep'] && (
                    ($b['_arr'] <= $a['_arr'] && $b['TransferCount'] <= $a['TransferCount']
                     && ($b['_dep'] > $a['_dep'] || $b['_arr'] < $a['_arr'] || $b['TransferCount'] < $a['TransferCount']
                         || ($b['_arr'] === $a['_arr'] && $b['TransferCount'] === $a['TransferCount'] && $kmOf($b) < $kmOf($a))))
                    // 多轉一次只早到不到 TRANSFER_MIN_GAIN（例如捷運蘆洲支線在大橋頭多換一次車早 1 分鐘）不算比較好
                    || ($b['TransferCount'] < $a['TransferCount'] && $b['_arr'] <= $a['_arr'] + TRANSFER_MIN_GAIN * ($a['TransferCount'] - $b['TransferCount']))
                )) {
                $dominated = true;
                break;
            }
        }
        if (!$dominated) {
            $keep[] = $a;
        }
    }
    // 轉乘要等很久（超過 TRANSFER_SHIFT_WAIT）的方案：等候之前的那幾段改搭最晚還趕得上的車（同樣到達、同樣班數），
    // 例如東湖→漢本「南港坐區間車 4046 直達」不該寫成 11:07 從東湖出發、在南港等 8 小時
    $shifted = [];
    foreach ($keep as $j) {
        $j = transferShiftLongWait($d, $from, $to, $j);
        $shifted[$j['_sig']] = $j;
    }
    $keep = array_values($shifted);
    usort($keep, function ($a, $b) { return [$a['_dep'], $a['_arr']] <=> [$b['_dep'], $b['_arr']]; });
    // picks：各類別挑一個代表方案（options 的索引）
    $pick = function (callable $key, callable $filter = null) use ($keep) {
        $best = null;
        foreach ($keep as $i => $j) {
            if ($filter && !$filter($j)) {
                continue;
            }
            if ($best === null || $key($j) < $key($keep[$best])) {
                $best = $i;
            }
        }
        return $best;
    };
    $dur = function ($j) { return $j['_arr'] - $j['_dep']; };
    $cats = [
        'Direct'           => $pick(function ($j) { return [$j['_arr'], -$j['_dep']]; }, function ($j) { return $j['TransferCount'] === 0; }),
        'Fastest'          => $pick(function ($j) { return [$j['_arr'], $j['TransferCount'], -$j['_dep']]; }),
        'ShortestTime'     => $pick(function ($j) use ($dur) { return [$dur($j), $j['TransferCount'], $j['_arr']]; }),
        'ShortestDistance' => $pick(function ($j) use ($kmOf) { return [round($kmOf($j), 1), $j['TransferCount'], $j['_arr']]; }),
        'FewestTransfers'  => $pick(function ($j) { return [$j['TransferCount'], $j['_arr'], -$j['_dep']]; }),
        'Best'             => $pick(function ($j) use ($t0) {
            return [($j['_arr'] - $t0) + $j['TransferCount'] * TRANSFER_TRANSFER_PENALTY + $j['WalkMinutes'] * 60 * (TRANSFER_WALK_WEIGHT - 1), $j['_arr']];
        }),
    ];
    // Tags：每個方案是否具有該屬性（並列的都算，例如兩個方案都只轉 1 次就都有 FewestTransfers）；Best 只有一個
    $min = ['arr' => INF, 'dur' => INF, 'km' => INF, 'tc' => INF];
    foreach ($keep as $j) {
        $min['arr'] = min($min['arr'], $j['_arr']);
        $min['dur'] = min($min['dur'], $dur($j));
        $min['km'] = min($min['km'], $kmOf($j));
        $min['tc'] = min($min['tc'], $j['TransferCount']);
    }
    foreach ($keep as $i => &$j) {
        $has = [
            'Direct'           => $j['TransferCount'] === 0,
            'Fastest'          => $j['_arr'] === $min['arr'],
            'ShortestTime'     => $dur($j) === $min['dur'],
            'ShortestDistance' => $j['DistanceKm'] !== null && $kmOf($j) <= $min['km'] + 0.05,
            'FewestTransfers'  => $j['TransferCount'] === $min['tc'],
            'Best'             => $cats['Best'] === $i,
        ];
        $j['Tags'] = $has;
        $j['TagBits'] = 0;
        $j['Labels'] = [];
        foreach ($has as $tag => $on) {
            if ($on) {
                $j['TagBits'] |= TRANSFER_TAGS[$tag]['bit'];
                $j['Labels'][] = $tag;
            }
        }
        // 路由組合（起站、路線、轉乘站、終點站）：原樣送回 func=follow 就能查同一組合的前後班次
        $j['Pattern'] = transferPatternFromSteps($from, $to, $j['Steps']);
        transferAddStops($d, $j['Steps'], $stops);
        unset($j['_dep'], $j['_arr'], $j['_sig'], $j['_rides']);
    }
    unset($j);
    $served = function ($u) use ($d) {
        return !empty($d['stationTrips'][$u]) || !empty($d['stationPatterns'][$u]) || !empty($d['stationMRoutes'][$u]);
    };
    return ['options' => $keep, 'picks' => $cats, 'runs' => $done, 'jumps' => $jumps, 'backward' => $backward, 'stop' => $stop, 'accel' => $d['accel']['summary'] ?? null, 'served' => ['from' => $served($from), 'to' => $served($to)]];
}

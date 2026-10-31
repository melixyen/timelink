<?php
/**
 * 捷運、輕軌的整列車（run）：依路線資料的種類用不同做法，目標是上下車站的時刻越接近正確值越好。
 *
 *   有真實站別時刻表的路線（北捷文湖線以外、高捷、新北捷運、機捷、各輕軌）：
 *     從每一班發車起，沿營運路線逐站「前一站的時刻＋兩站間行駛時間（有直接資料用直接資料，否則一站一站加）」，
 *     到下一站真實時刻表找同路線、同方向、同終點、同車種、相差 ±1.5 分鐘內最接近、還沒被別班車用掉的一班（有真實到站時間的比到站；
 *     至少要比前一站晚最短行車時間 transferRunMinDep，TDX 站別時刻表有相鄰兩站同一分鐘的錯誤資料時不採用；
 *     不採用的那一班視為這班車的錯誤複本標成已用掉 transferRunConsumeEarly，第二輪不會從它串出不存在的車），
 *     對到就用時刻表的時刻並從那裡接著推，一直串到這班車的終點站。每一站的時刻：
 *       timetable     對到時刻表
 *       interpolated  沒對到，但前後都有對到：按兩站間行駛時間的比例分配前後兩個時刻表時刻之間的時間（兩端都是真實時刻，誤差不累積）
 *       estimate      沒對到、後面也沒有對到的站（含終點站：時刻表沒有終點站的到站）：最後一個對到的時刻＋行駛時間，
 *                     行駛時間依這班車已對到的區段「時刻表實際時間 ÷ 行駛時間資料」的比例調整（例如高捷 S2S 比時刻表長約 4 成）
 *     列車在終點站結束：短程車（例如象山→北投）不會被當成開到淡水
 *   只有模擬時刻表的路線（文湖線、臺中捷運）：沒有真實時刻可對，仍用營運路線（pattern）＝發車＋行駛時間推算
 *
 * 整列車依停靠站序分組成「路線」（同一組車站的列車），組內依發車排序，給逐輪搜尋（RAPTOR）用：
 *   $d['mroutes'][路線代碼] = ['key', 'system', 'line', 'st' => [車站…], 'trips' => [['arr' => [], 'dep' => [], 'src' => [], …], …]]
 */

require_once __DIR__ . '/settings.php';

/** 這個系統＋路線是否有真實站別時刻表（有任何一個群組是 timetable） */
function transferRunsRealLines(SqliteDao $db)
{
    $out = [];
    foreach ($db->all("SELECT DISTINCT system, json_extract(info, '$.LineIDs[0]') AS line FROM metro_group WHERE source = 'timetable'") as $r) {
        $out[$r['system'] . '|' . $r['line']] = true;
    }
    return $out;
}

/**
 * 建立整列車與路線（同一個請求內只算一次；結果另存快取檔，資料庫重建或程式版本改了就重算）
 */
function transferBuildRuns(array &$d)
{
    if (isset($d['mroutes'])) {
        return;
    }
    $built = (string) $d['db']->value("SELECT value FROM _meta WHERE key = 'built_at'");
    $cacheFile = SQLITE_DIR . '/transfer_runs_' . $d['mask'] . '.ser';   // sqlite/ 禁止網址存取、不備份
    $ver = sha1_file(__FILE__) . '|' . $built;
    if (is_file($cacheFile)) {
        $c = @unserialize((string) file_get_contents($cacheFile));
        if (is_array($c) && ($c['ver'] ?? null) === $ver) {
            $d['mroutes'] = $c['mroutes'];
            $d['runLines'] = $c['runLines'];
            $d['groupInfo'] = $c['groupInfo'] + ($d['groupInfo'] ?? []);
            return;
        }
    }
    $real = transferRunsRealLines($d['db']);
    // 全部站別時刻一次讀進來（與 transferStationDep() 相同的格式，之後查詢不必再逐站讀）
    $tt = [];   // 車站 => 群組 => [[發車秒, 車種, 到站秒或 null], ...]
    $stationOf = [];
    foreach ($d['db']->all('SELECT group_id, station_uid, direction, info, source FROM metro_group WHERE (weekmask & ?) != 0', [$d['mask']]) as $r) {
        $gid = $r['group_id'];
        $stationOf[$gid] = $r['station_uid'];
        if (!isset($d['groupInfo'][$gid])) {
            $info = json_decode($r['info'], true);
            $d['groupInfo'][$gid] = ['line' => $info['LineIDs'][0] ?? '', 'ending' => $info['EndingStationUID'], 'dir' => (string) $r['direction'],
                                     'source' => $r['source'], 'endingName' => null];
        }
    }
    // 時刻一列一列讀（不一次全部載入記憶體）
    $stmt = $d['db']->pdo()->prepare('SELECT group_id, sort_min, train_type_code, arr, arr_src FROM metro_dep ORDER BY sort_min');
    $stmt->execute();
    while ($r = $stmt->fetch(PDO::FETCH_NUM)) {
        list($gid, $sm, $code, $arrS, $arrSrc) = $r;
        if (!isset($stationOf[$gid])) {
            continue;
        }
        $dep = (int) $sm * 60;
        $arr = null;
        if ($arrSrc === 'timetable' && ($am = finderDbMinutes((string) $arrS)) !== null) {
            $arr = ($am < 240 ? $am + 1440 : $am) * 60;
            if ($arr > $dep + 3600) {
                $arr -= 86400;
            } elseif ($arr < $dep - 82800) {
                $arr += 86400;
            }
        }
        $tt[$stationOf[$gid]][$gid][] = [$dep, (string) $code, $arr];
    }
    $d['stationDep'] = $tt + ($d['stationDep'] ?? []);
    $used = [];       // 車站|群組|發車秒 => true
    $runs = [];
    // 依路線處理；營運路線長的先（完整路線先串，短程路線上的車多半已被串到）
    $plist = [];
    foreach ($d['patterns'] as $pk => $p) {
        if (isset($real[$p['system'] . '|' . $p['line']])) {
            $plist[$pk] = count($p['st']);
        }
    }
    arsort($plist);
    // 兩輪：先只從每條營運路線的首站發車串（列車多半從首站出發，例如蘆洲支線的車要從蘆洲串，不能在大橋頭被當成新的一班），
    // 再從各站還沒被串到的班次串（從中途站出發的車）
    foreach ([true, false] as $firstOnly) {
    foreach (array_keys($plist) as $pk) {
        $p = $d['patterns'][$pk];
        $n = count($p['st']);
        for ($j = 0; $j < ($firstOnly ? 1 : $n - 1); $j++) {
            $u = $p['st'][$j];
            foreach (transferPatternDepartures($d, $pk, $j) as $dep) {
                $k = $u . '|' . $dep[1] . '|' . $dep[0];
                if (isset($used[$k]) || $d['groupInfo'][$dep[1]]['source'] !== 'timetable') {
                    continue;
                }
                $used[$k] = true;
                $run = transferChainRun($d, $p, $j, $dep, $used);
                if ($run !== null) {
                    $runs[] = $run;
                }
            }
        }
    }
    }
    // 依停靠站序分組成路線，組內依發車排序
    $mroutes = [];
    foreach ($runs as $r) {
        $rk = $r['system'] . '|' . $r['line'] . '|' . implode(',', $r['st']);
        if (!isset($mroutes[$rk])) {
            $mroutes[$rk] = ['key' => $rk, 'system' => $r['system'], 'line' => $r['line'], 'st' => $r['st'], 'trips' => []];
        }
        unset($r['st'], $r['system'], $r['line']);
        $mroutes[$rk]['trips'][] = $r;
    }
    foreach ($mroutes as &$mr) {
        usort($mr['trips'], function ($a, $b) { return $a['dep'][0] <=> $b['dep'][0]; });
        // 各站的發車、到站是否隨班次遞增（沒有超車）：遞增才能用二分搜尋
        $mono = true;
        $cnt = count($mr['trips']);
        for ($t = 1; $t < $cnt && $mono; $t++) {
            foreach ($mr['trips'][$t]['dep'] as $j => $x) {
                if ($x < $mr['trips'][$t - 1]['dep'][$j] || $mr['trips'][$t]['arr'][$j] < $mr['trips'][$t - 1]['arr'][$j]) {
                    $mono = false;
                    break;
                }
            }
        }
        $mr['fifo'] = $mono;
    }
    unset($mr);
    $d['mroutes'] = $mroutes;
    $d['runLines'] = $real;
    // 建置用的全部站別時刻不留在記憶體（模擬時刻路線查詢時再逐站讀）
    $d['stationDep'] = [];
    $d['depCache'] = [];
    unset($tt, $used, $runs);
    $gi = [];
    foreach ($d['groupInfo'] as $gid => $g) {
        $gi[$gid] = $g;
    }
    @file_put_contents($cacheFile, serialize(['ver' => $ver, 'mroutes' => $mroutes, 'runLines' => $real, 'groupInfo' => $gi]), LOCK_EX);
}

/**
 * 從營運路線 $p 第 $j0 站的發車 $dep（[秒, 群組]）串出整列車，到這班車的終點站（或營運路線終點）為止
 */
function transferChainRun(array &$d, array $p, $j0, array $dep, array &$used)
{
    $g0 = $d['groupInfo'][$dep[1]];
    $n = count($p['st']);
    $end = $n - 1;
    if ($g0['ending'] !== '') {
        $e = array_search($g0['ending'], array_slice($p['st'], $j0 + 1), true);
        if ($e !== false) {
            $end = $j0 + 1 + $e;
        }
    }
    if ($end <= $j0) {
        return null;
    }
    // 車種
    $code = '';
    foreach ($d['stationDep'][$p['st'][$j0]][$dep[1]] ?? [] as $x) {
        if ($x[0] === $dep[0]) {
            $code = $x[1];
            break;
        }
    }
    $tol = (int) (FINDER_TRIP_TOLERANCE[$p['system']] ?? 0);
    $st = [$p['st'][$j0]];
    $arr = [$dep[0]];
    $dp = [$dep[0]];
    $src = ['timetable'];
    $gids = [$dep[1]];
    $cur = $dep[0];
    $curReal = true;
    $lastReal = $dep[0];   // 最後一個對到時刻表的站的時刻
    $sinceReal = 0;        // 從那一站起累計的行駛時間（含停站）
    for ($j = $j0 + 1; $j <= $end; $j++) {
        $u = $p['st'][$j];
        $seg = transferPatternTravel($d, $p, $j - 1, $j)[0];
        $dwell = max(0, $p['dep'][$j] - $p['arr'][$j]);
        $from = $cur + ($curReal ? 30 : 0);
        $estArr = $from + $seg;
        $estDep = $estArr + $dwell;
        $hit = null;
        $sinceReal += $seg + $dwell;
        $minDep = transferRunMinDep($cur, $seg + $dwell);
        if ($j < $end || $g0['ending'] !== $u) {
            $hit = transferRunMatch($d, $u, $p, $g0, $code, $tol, $cur, $estArr, $estDep, $used, 90, false, $minDep);
            // 對不到：這一段的行駛時間資料可能和時刻表不一致（例如高捷 O12→O13 資料 4.3 分、時刻表 2 分），
            // 改用兩站時刻表本身的發車時間差（眾數）再對一次
            if ($hit === null && $curReal) {
                $mode = transferRunSegmentMode($d, $p['st'][$j - 1], $u, $p, $g0, $code, $seg + $dwell);
                if ($mode !== null && abs($mode * 60 - ($seg + $dwell)) >= 60) {
                    $hit = transferRunMatch($d, $u, $p, $g0, $code, $tol, $cur, null, $cur + 30 + $mode * 60, $used, 90, false, $minDep);
                }
            }
            // 還是對不到（例如深夜班距拉長、時刻表的行駛時間和白天不同）：班距夠大、最接近的一班明顯比前後班都近時（相差不到前後班距的 1/3，且 10 分鐘內）才採用
            if ($hit === null && $curReal) {
                $hit = transferRunMatch($d, $u, $p, $g0, $code, $tol, $cur, $estArr, $estDep, $used, 600, true, $minDep);
            }
        }
        // 對不到時：這一站時刻表裡比最後對到的站還不可能早到的那一班（站別時刻表的錯誤複本，例如中和＝景安、十四張＝秀朗橋＝大坪林同一分鐘），
        // 視為這班車的時刻標成已用掉，免得第二輪從它串出一班不存在的車（例如從十四張出發、其實是大坪林那班的重複）
        if ($hit === null && ($j < $end || $g0['ending'] !== $u)) {
            transferRunConsumeEarly($d, $u, $p, $g0, $code, $lastReal, transferRunMinDep($lastReal, $sinceReal), $used);
        }
        $st[] = $u;
        if ($hit !== null) {
            list($x, $gid) = $hit;
            $used[$u . '|' . $gid . '|' . $x[0]] = true;
            $arr[] = $x[2] ?? $x[0];
            $dp[] = $x[0];
            $src[] = 'timetable';
            $gids[] = $gid;
            $cur = $x[0];
            $curReal = true;
            $lastReal = $x[0];
            $sinceReal = 0;
        } else {
            $arr[] = $estArr;
            $dp[] = $estDep;
            $src[] = 'estimate';
            $gids[] = null;
            $cur = $estDep;
            $curReal = false;
        }
    }
    // 沒對到的站：前後都有對到 → 按行駛時間比例內插；之後都沒有對到 → 依這班車已對到區段的時刻表÷行駛時間比例推算
    $m = count($st);
    $cum = [0];
    for ($i = 1; $i < $m; $i++) {
        $cum[$i] = $cum[$i - 1] + transferPatternTravel($d, $p, $j0 + $i - 1, $j0 + $i)[0] + ($i < $m - 1 ? max(0, $p['dep'][$j0 + $i] - $p['arr'][$j0 + $i]) : 0);
    }
    $real = array_keys(array_filter($src, function ($s) { return $s === 'timetable'; }));
    $lastReal = end($real);
    $scale = 1.0;
    if ($lastReal > 0 && $cum[$lastReal] > 0) {
        $scale = max(0.5, min(1.5, ($dp[$lastReal] - $dp[0]) / $cum[$lastReal]));
    }
    $prevReal = 0;
    for ($i = 1; $i < $m; $i++) {
        if ($src[$i] === 'timetable') {
            $prevReal = $i;
            continue;
        }
        $next = null;
        foreach ($real as $r) {
            if ($r > $i) {
                $next = $r;
                break;
            }
        }
        $base = $dp[$prevReal] + 30;
        if ($next !== null && $cum[$next] > $cum[$prevReal]) {
            $f = ($cum[$i] - $cum[$prevReal]) / ($cum[$next] - $cum[$prevReal]);
            $t = (int) round($base + ($arr[$next] + 30 - $base) * $f);
            $src[$i] = 'interpolated';
        } else {
            $t = (int) round($base + ($cum[$i] - $cum[$prevReal]) * $scale);
        }
        $arr[$i] = $t;
        $dp[$i] = $t;
    }
    return ['system' => $p['system'], 'line' => $p['line'], 'st' => $st, 'arr' => $arr, 'dep' => $dp, 'src' => $src, 'gid' => $gids,
            'route' => $p['route'], 'dir' => $g0['dir'], 'ending' => $g0['ending'], 'code' => $code, 'tt' => $p['tt'], 'pk' => $p['key']];
}

/**
 * 相鄰兩站間最短行車時間：行駛時間資料（含停站）的這個比例；比它還快的時刻表時刻視為對到別班車或資料錯誤。
 * 0.6：行駛時間 100 秒以上的區間不接受同一分鐘發車；下一分鐘發車可接受到 198 秒、隔兩分鐘到 298 秒
 * （行駛時間資料比時刻表長的區間，例如高捷 O12→O13 資料 4.3 分、時刻表 2 分，仍對得到）
 */
const TRANSFER_RUN_MIN_TRAVEL_RATIO = 0.6;

/**
 * 下一站時刻表時刻（分鐘的開始，秒）的下限：前一站時刻 $cur 加上最短行車時間（行駛時間 $travel 秒 × TRANSFER_RUN_MIN_TRAVEL_RATIO），
 * 時刻表只到分鐘，再寬限 59 秒。例如 TDX 新北環狀線往新北產業園區的景安、中和站別時刻表完全相同（同一分鐘發車），
 * 景安→中和行駛約 2 分鐘，中和的同一分鐘不採用、改由前後對到的時刻內插；行駛時間不到 100 秒的短區間仍可同一分鐘
 */
function transferRunMinDep($cur, $travel)
{
    return (int) ceil($cur + TRANSFER_RUN_MIN_TRAVEL_RATIO * $travel) - 59;
}

/**
 * $u 站時刻表（同路線、同方向、同終點或同方向、同車種）裡還沒被用掉、時刻落在 [$lastReal 那一分鐘, $minDep) 的一班：
 * 比最後對到的站加上最短行車時間還早、不可能是別班車，是站別時刻表的錯誤複本；最接近 $lastReal 的一班標成已用掉
 */
function transferRunConsumeEarly(array &$d, $u, array $p, array $g0, $code, $lastReal, $minDep, array &$used)
{
    $best = null;
    $floor = intdiv($lastReal, 60) * 60;
    foreach ($d['stationDep'][$u] ?? [] as $gid => $list) {
        $g = $d['groupInfo'][$gid];
        if ($g['source'] !== 'timetable' || $g['line'] !== $p['line'] || ($g['dir'] !== '' && $g0['dir'] !== '' && $g['dir'] !== $g0['dir'])
            || ($g0['ending'] !== '' && $g['ending'] !== '' && $g['ending'] !== $g0['ending'])) {
            continue;
        }
        foreach ($list as $x) {
            if ($x[0] < $floor) {
                continue;
            }
            if ($x[0] >= $minDep) {
                break;
            }
            if (($code !== '' && $x[1] !== '' && $x[1] !== $code) || isset($used[$u . '|' . $gid . '|' . $x[0]])) {
                continue;
            }
            if ($best === null || $x[0] < $best[0]) {
                $best = [$x[0], $gid];
            }
        }
    }
    if ($best !== null) {
        $used[$u . '|' . $best[1] . '|' . $best[0]] = true;
    }
}

/**
 * 在 $u 站的真實時刻表找這班車：同路線、同方向（雙方都有標時）、同終點（沒有就同方向其他終點）、同車種、還沒被用掉、不早於前一站（且不早於 $minDep）；
 * 有真實到站時間且有給 $estArr 時比到站，否則比離站；時刻表只到分鐘，以該分鐘的中間（＋30 秒）比較，相差 $win 秒內（早的一側再多容許 $tol 分鐘）取最接近的。
 * $unambiguous：最接近的一班與推算的差要小於它前後班距的 1/3 才算（放寬 $win 時避免對到別班車）。
 * $minDep：發車不能早於這個時刻（transferRunMinDep：前一站＋最短行車時間）。
 * 回傳 [[發車秒, 車種, 到站秒], 群組] 或 null
 */
function transferRunMatch(array &$d, $u, array $p, array $g0, $code, $tol, $cur, $estArr, $estDep, array &$used, $win = 90, $unambiguous = false, $minDep = null)
{
    $hit = null;
    $bestD = null;
    $bestGap = null;
    foreach ([true, false] as $strict) {
        foreach ($d['stationDep'][$u] ?? [] as $gid => $list) {
            $g = $d['groupInfo'][$gid];
            if ($g['source'] !== 'timetable' || $g['line'] !== $p['line'] || ($g['dir'] !== '' && $g0['dir'] !== '' && $g['dir'] !== $g0['dir'])) {
                continue;
            }
            if ($strict ? ($g0['ending'] === '' || $g['ending'] !== $g0['ending']) : ($g0['ending'] !== '' && $g['ending'] === $g0['ending'])) {
                continue;
            }
            foreach ($list as $xi => $x) {
                $diff = $x[2] !== null && $estArr !== null ? $x[2] + 30 - $estArr : $x[0] + 30 - $estDep;
                if ($diff < -$win - $tol * 60 || $x[0] < intdiv($cur, 60) * 60 || ($minDep !== null && $x[0] < $minDep)) {
                    continue;
                }
                if ($diff > $win) {
                    break;
                }
                if ($code !== '' && $x[1] !== '' && $x[1] !== $code) {
                    continue;
                }
                if (isset($used[$u . '|' . $gid . '|' . $x[0]])) {
                    continue;
                }
                if ($bestD === null || abs($diff) < $bestD) {
                    $hit = [$x, $gid];
                    $bestD = abs($diff);
                    // 前後班距（同一個時刻表群組）
                    $bestGap = min(isset($list[$xi - 1]) ? $x[0] - $list[$xi - 1][0] : PHP_INT_MAX, isset($list[$xi + 1]) ? $list[$xi + 1][0] - $x[0] : PHP_INT_MAX);
                }
            }
        }
        if ($hit !== null) {
            if ($unambiguous && $bestD * 3 > $bestGap) {
                return null;
            }
            return $hit;
        }
    }
    return null;
}

/**
 * 兩相鄰停靠站在時刻表上的標準發車時間差（分鐘）：前一站每一班與下一站之後 20 分鐘內每一班的發車時間差計次，
 * 次數達最多者 60% 以上的時間差中取最接近行駛時間資料 $travel（秒）的。沒有資料回 null
 */
function transferRunSegmentMode(array &$d, $a, $b, array $p, array $g0, $code, $travel)
{
    $ck = $a . '>' . $b . '|' . $p['line'] . '|' . $g0['ending'] . '|' . $g0['dir'] . '|' . $code;
    if (array_key_exists($ck, $d['runMode'] ?? [])) {
        return $d['runMode'][$ck];
    }
    $col = function ($u) use (&$d, $p, $g0, $code) {
        $strict = [];
        $byDir = [];
        foreach ($d['stationDep'][$u] ?? [] as $gid => $list) {
            $g = $d['groupInfo'][$gid];
            if ($g['source'] !== 'timetable' || $g['line'] !== $p['line'] || ($g['dir'] !== '' && $g0['dir'] !== '' && $g['dir'] !== $g0['dir'])) {
                continue;
            }
            foreach ($list as $x) {
                if ($code === '' || $x[1] === '' || $x[1] === $code) {
                    if ($g0['ending'] !== '' && $g['ending'] === $g0['ending']) {
                        $strict[] = $x[0];
                    } else {
                        $byDir[] = $x[0];
                    }
                }
            }
        }
        $l = $strict ?: $byDir;
        sort($l);
        return $l;
    };
    $A = $col($a);
    $B = $col($b);
    $cnt = [];
    $k = 0;
    $n = count($B);
    foreach ($A as $x) {
        while ($k < $n && $B[$k] < $x) {
            $k++;
        }
        for ($y = $k; $y < $n && $B[$y] <= $x + 1200; $y++) {
            $diff = intdiv($B[$y] - $x, 60);
            $cnt[$diff] = ($cnt[$diff] ?? 0) + 1;
        }
    }
    $mode = null;
    if ($cnt) {
        $top = max($cnt);
        foreach ($cnt as $diff => $c) {
            if ($c >= 0.6 * $top && ($mode === null || abs($diff - $travel / 60) < abs($mode - $travel / 60))) {
                $mode = $diff;
            }
        }
    }
    return $d['runMode'][$ck] = $mode;
}

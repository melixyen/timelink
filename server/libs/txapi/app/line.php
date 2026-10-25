<?php
/**
 * 路線時刻表 app_line/timetable：一條路線、一個方向、一個時段內的所有班次。
 * 列是車站（依行進方向的站序），欄是班次；格子是到離時刻、通過，或 null（這班車不經過）。
 *
 * 資料來源與 app_train/stops 相同：
 *   臺鐵、高鐵  路網時刻資料的車次（只有停靠站；兩個停靠站之間都在這條路線上的站算「通過」）
 *   捷運、輕軌  有真實站別時刻表的路線用整列車（mroutes）；沒有的用營運路線的首站發車＋站間時間推算（Source＝estimate）
 * 臺鐵海線車不算經過山線（反之亦然）：TripLine 1＝山線、2＝海線，兩站之間沒有停靠時用它判斷。
 */

// APP_LINE_DEFAULT_LIMIT、APP_LINE_MAX_LIMIT（最多幾班）：設定項目（libs/config.json）

function actionAppLine()
{
    appDispatch('app_line', [
        'timetable' => ['appLineTimetable', '路線時刻表：一條路線、一個方向的所有班次，列是車站、欄是班次，格子是到離時刻或「通過」',
                        ['line' => '路線代碼（必填），例如 TRA-WL、TRTC-BL、THSR-HSRL',
                         'direction' => '0＝依路線站序（public_line/detail 的 Stations 順序）、1＝反向；有 from、to 時依兩站的先後決定',
                         'from' => '只取這一站以後（含）', 'to' => '只取到這一站（含）',
                         'date' => 'YYYY-MM-DD（或 week）', 'start' => '時段起 HH:MM（start、end 都省略為全日）', 'end' => '時段迄 HH:MM（只給 start 時＝start＋系統最長查詢時段）',
                         'by' => 'dep：比班次在第一個顯示車站的時刻（SortMinute，預設）；arr：比在最後一個顯示車站的時刻（ArrivalSortMinute），欄也依它排序（中途發車、提早結束的車用推算）',
                         'extra' => '排除位元：1＝不搭臺鐵對號、2＝不搭高鐵', 'class' => 'local／express：只看該等級的車',
                         'limit' => '最多幾班（預設 ' . APP_LINE_DEFAULT_LIMIT . '，上限 ' . APP_LINE_MAX_LIMIT . '）'],
                        txapiUrl('app_line', 'timetable', ['line' => 'TRA-WL', 'from' => 'TRA-1000', 'to' => 'TRA-1210', 'week' => '1', 'start' => '07:00', 'end' => '09:00'])],
    ]);
}

function appLineTimetable()
{
    appDb();
    $m = appModel();
    $lu = appLineParam('line');
    $sys = substr($lu, 0, strpos($lu, '-'));
    $seq = $m['lineStations'][$lu];
    list($week, $date) = appParamWeek();
    $extra = appParamExtra();
    $class = appParamClass();
    list($by, $start, $end) = appTimeWindow($date, true, false);
    $limit = appParamInt('limit', APP_LINE_DEFAULT_LIMIT, 1, APP_LINE_MAX_LIMIT);
    $from = appParam('from') !== null ? appStationParam('from') : null;
    $to = appParam('to') !== null ? appStationParam('to') : null;
    foreach (['from' => $from, 'to' => $to] as $k => $u) {
        if ($u !== null && !in_array($u, $seq, true)) {
            appFail(40020, ['param' => $k, 'detail' => '這一站不在 ' . $lu . ' 上']);
        }
    }
    if ($from !== null && $from === $to) {
        appFail(40020, ['param' => 'to', 'detail' => 'from 與 to 是同一站']);
    }
    if ($from !== null && $to !== null) {
        $dir = array_search($from, $seq, true) < array_search($to, $seq, true) ? 0 : 1;
    } else {
        $dir = (int) appParamChoice('direction', ['0', '1'], '0');
    }
    // 環狀線（有班次首末站相同，例如高雄輕軌）：首站接在最後，兩個方向都從首站繞一圈回首站
    $circle = appLineIsCircle($lu, $week);
    if ($circle) {
        $seq[] = $seq[0];
    }
    $rows = $dir === 0 ? $seq : array_reverse($seq);
    // 顯示範圍（列的索引）
    $lo = 0;
    $hi = count($rows) - 1;
    if ($from !== null) {
        $lo = array_search($from, $rows, true);
    }
    if ($to !== null) {
        $hi = $from !== null ? appLineIndexAfter($rows, $to, $lo) : array_search($to, $rows, true);
    }
    if ($hi === false || $hi <= $lo) {
        appFail(40020, ['param' => 'to', 'detail' => '這個方向 to 要在 from 之後']);
    }
    $t0 = microtime(true);
    $cols = appSystemAllowed($extra, $sys) ? appLineColumns($m, $lu, $rows, $week) : [];
    // 整天都沒有車停的列（例如臺北-環島這類另用的站碼）不顯示；from、to 一定顯示
    $stopAt = [];
    foreach ($cols as $c) {
        foreach ($c['cells'] as $r => $x) {
            if (is_array($x)) {
                $stopAt[$r] = true;
            }
        }
    }
    $show = [];
    for ($r = $lo; $r <= $hi; $r++) {
        if (isset($stopAt[$r]) || $r === $lo || $r === $hi) {
            $show[] = $r;
        }
    }
    // 篩選：車種等級、範圍內至少停兩站
    $kept = [];
    foreach ($cols as $c) {
        if (!appClassAllowed($extra, $sys, $c['class']) || ($class !== null && appClassHasSplit($sys) && $c['class'] !== $class)) {
            continue;
        }
        $first = null;
        $n = 0;
        foreach ($show as $r) {
            if (isset($c['cells'][$r]) && is_array($c['cells'][$r])) {
                $first = $first ?? $r;
                $n++;
            }
        }
        if ($n < 2) {
            continue;
        }
        $c['first'] = $first;
        foreach ($show as $r) {
            if (isset($c['cells'][$r]) && is_array($c['cells'][$r])) {
                $c['last'] = $r;
            }
        }
        $c['t'] = appLineCellTime($c['cells'][$first]);
        $c['tl'] = $c['cells'][$c['last']][0] ?? $c['cells'][$c['last']][1];   // 在範圍內最後一個停靠站的時刻（到站優先）
        $kept[] = $c;
    }
    // 排序與時段：換算成在第一個顯示車站的時刻（SortMinute；中途才出發的車，扣掉一般列車從第一站到該站的時間）；
    // by=arr 換算成在最後一個顯示車站的時刻（ArrivalSortMinute；提早結束的車，加上一般列車從該站到最後一站的時間）。
    // 時段 start／end 比這個時刻，欄也依它排序，用 start 往後翻頁才不會漏
    $off = appLineOffsets($kept, $show);
    $lastOff = $off[end($show)] ?? null;
    foreach ($kept as $i => &$c) {
        $c['key'] = $c['t'] - ($off[$c['first']] ?? 0);
        $c['keyArr'] = $lastOff !== null && isset($off[$c['last']]) ? $c['tl'] + $lastOff - $off[$c['last']] : $c['tl'];
        $c['k'] = $by === 'arr' ? $c['keyArr'] : $c['key'];
    }
    unset($c);
    $kept = array_values(array_filter($kept, function ($c) use ($start, $end) {
        return ($start === null || $c['k'] >= $start * 60) && ($end === null || $c['k'] <= $end * 60 + 59);
    }));
    usort($kept, function ($a, $b) { return [$a['k'], $a['t']] <=> [$b['k'], $b['t']]; });
    $more = count($kept) > $limit;
    $next = $more ? appHM(intdiv($kept[$limit]['k'], 60)) : null;   // 下一頁的 start（第一個沒列出的班次；同一分鐘的可能重複出現）
    $kept = array_slice($kept, 0, $limit);
    $st = function ($u) use ($m) {
        return $u ? ['StationUID' => $u, 'StationName' => $m['names'][$u] ?? null] : null;
    };
    $stations = [];
    foreach ($show as $r) {
        $stations[] = $st($rows[$r]);
    }
    $trains = [];
    foreach ($kept as $c) {
        $times = [];
        foreach ($show as $r) {
            $x = $c['cells'][$r] ?? null;
            if ($x === 'pass') {
                $times[] = ['Pass' => true];
            } elseif (is_array($x)) {
                $times[] = ['Arr' => $x[0] === null ? null : transferHM($x[0]), 'Dep' => $x[1] === null ? null : transferHM($x[1]),
                            'ArrMinute' => $x[0] === null ? null : intdiv($x[0], 60), 'DepMinute' => $x[1] === null ? null : intdiv($x[1], 60)];
            } else {
                $times[] = null;
            }
        }
        $trains[] = [
            'Train' => $c['code'], 'TrainNo' => $c['no'], 'TrainType' => $c['type'],
            'Class' => appClassHasSplit($sys) ? $c['class'] : null, 'ClassLabel' => appClassHasSplit($sys) ? appClassLabel($sys, $c['class']) : null,
            'Origin' => $st($c['origin']), 'Destination' => $st($c['dest']),
            'DepartureMinute' => intdiv($c['t'], 60), 'SortMinute' => intdiv($c['key'], 60), 'ArrivalSortMinute' => intdiv($c['keyArr'], 60),
            'Source' => $c['src'], 'Times' => $times,
        ];
    }
    $info = appLineInfo()[$lu] ?? ['LineUID' => $lu];
    appSend([
        'Line' => ['LineUID' => $lu, 'LineName' => $info['LineName'] ?? null, 'LineColor' => $info['LineColor'] ?? null, 'System' => $sys],
        'Direction' => $dir, 'Label' => '往' . ($m['names'][$rows[$hi]]['Zh_tw'] ?? $rows[$hi])
            . ($circle && $lo === 0 && $hi === count($rows) - 1 ? '（經' . ($m['names'][$rows[1]]['Zh_tw'] ?? $rows[1]) . '）' : ''),
        'count' => count($trains), 'more' => $more, 'Stations' => $stations, 'Trains' => $trains,
        'next' => $next,
        'note' => $trains ? ($more ? '班次超過 limit，只列前 ' . $limit . ' 班；下一頁用 start＝next' : null)
                          : (!appSystemAllowed($extra, $sys) ? 'extra 含 2（不搭高鐵）' : '這個時段沒有符合條件的班次'),
    ], ['week' => $week, 'weekday' => FINDER_WEEKDAYS[$week], 'date' => $date]
       + appTimeWindowMeta($by, $start, $end) + array_filter(['class' => $class])
       + appExtraInfo($extra) + ['limit' => $limit, 'seconds' => round(microtime(true) - $t0, 3)]);
}

/** 環狀線：有整列車或營運路線的首末站相同（三站以上） */
function appLineIsCircle($lu, $week)
{
    $sys = substr($lu, 0, strpos($lu, '-'));
    if (in_array($sys, FINDER_TRAIN_SYSTEMS, true)) {
        return false;
    }
    $line = substr($lu, strlen($sys) + 1);
    $d = &appRouterData($week);
    foreach (['mroutes', 'patterns'] as $k) {
        foreach ($d[$k] as $x) {
            if ($x['system'] === $sys && $x['line'] === $line && count($x['st']) > 2 && $x['st'][0] === end($x['st'])) {
                return true;
            }
        }
    }
    return false;
}

/** $u 在 $rows 第 $after 列之後第一次出現的索引（環狀線首末站相同時用） */
function appLineIndexAfter(array $rows, $u, $after)
{
    for ($i = $after + 1; $i < count($rows); $i++) {
        if ($rows[$i] === $u) {
            return $i;
        }
    }
    return false;
}

/** 格子的時刻（秒）：有離站用離站，否則到站 */
function appLineCellTime(array $x)
{
    return $x[1] ?? $x[0];
}

/** 各列相對於第一個顯示車站的一般時間差（秒）：從有停靠兩站的班次取中位數，沿列累加 */
function appLineOffsets(array $cols, array $show)
{
    $off = [];
    if (!$show) {
        return $off;
    }
    $off[$show[0]] = 0;
    for ($k = 1; $k < count($show); $k++) {
        $r = $show[$k];
        // 找已知時間差的前一列 p，取同時停 p 與 r 的班次的時間差中位數
        $best = null;
        for ($q = $k - 1; $q >= 0 && $best === null; $q--) {
            $p = $show[$q];
            if (!isset($off[$p])) {
                continue;
            }
            $diff = [];
            foreach ($cols as $c) {
                if (isset($c['cells'][$p], $c['cells'][$r]) && is_array($c['cells'][$p]) && is_array($c['cells'][$r])) {
                    $diff[] = appLineCellTime($c['cells'][$r]) - appLineCellTime($c['cells'][$p]);
                }
            }
            if ($diff) {
                sort($diff);
                $best = $off[$p] + $diff[intdiv(count($diff), 2)];
            }
        }
        if ($best !== null) {
            $off[$r] = $best;
        }
    }
    return $off;
}

/**
 * 一條路線、一個方向（$rows 是依行進方向的站序）的所有班次：
 * [['code', 'no', 'type', 'class', 'origin', 'dest', 'src', 'cells' => [列索引 => [到秒|null, 離秒|null] | 'pass']], ...]
 */
function appLineColumns(array $m, $lu, array $rows, $week)
{
    $sys = substr($lu, 0, strpos($lu, '-'));
    $line = substr($lu, strlen($sys) + 1);
    $pos = [];
    foreach ($rows as $i => $u) {
        $pos[$u][] = $i;
    }
    $d = &appRouterData($week);
    $cols = [];
    if (in_array($sys, FINDER_TRAIN_SYSTEMS, true)) {
        // 臺鐵山線、海線：TripLine 1＝山線、2＝海線；兩站之間沒停靠時，另一條線的區間不算通過
        $wlc = $m['lineStations']['TRA-WL-C'] ?? [];
        $keys = [];
        foreach ($d['trips'] as $tr) {
            if ($tr['system'] === $sys) {
                $keys[] = $tr['key'];
            }
        }
        $info = $sys === 'TRA' ? appTrainRows($keys) : [];
        foreach ($d['trips'] as $tr) {
            if ($tr['system'] !== $sys) {
                continue;
            }
            $row = $info[$tr['key']] ?? null;
            $ti = $row['info'] ?? [];
            $skip = null;
            if ($sys === 'TRA' && $wlc) {
                $tl = (string) ($ti['TripLine'] ?? '');
                if (($lu === 'TRA-WL' && $tl === '2') || ($lu === 'TRA-WL-C' && $tl === '1')) {
                    $skip = [$wlc[0], end($wlc)];
                }
            }
            $cells = appLineCells($pos, $tr['st'], $tr['arr'], $tr['dep'], $skip);
            if ($cells === null) {
                continue;
            }
            $n = count($tr['st']);
            $cols[] = [
                'code' => $tr['key'], 'no' => substr($tr['key'], strlen($sys) + 1),
                'type' => $sys === 'TRA' && ($row['type_code'] ?? '') !== '' ? ['Code' => $row['type_code'], 'Name' => $ti['TrainTypeName'] ?? null] : null,
                'class' => $sys === 'TRA' ? appTrainClassByType($row['type_code'] ?? '') : appTrainClassByStops(appLineStationsOf($sys, ''), $tr['st']),
                'origin' => $ti['StartingStationUID'] ?? $tr['st'][0], 'dest' => $ti['EndingStationUID'] ?? $tr['st'][$n - 1],
                'src' => 'timetable', 'cells' => $cells,
            ];
        }
        return $cols;
    }
    // 捷運、輕軌：有整列車用整列車
    $split = appClassHasSplit($sys);
    foreach ($d['mroutes'] as $rk => $mr) {
        if ($mr['system'] !== $sys || $mr['line'] !== $line) {
            continue;
        }
        $n = count($mr['st']);
        $cls = $split ? appTrainClassByStops(appLineStationsOf($sys, $line), $mr['st']) : APP_CLASS_LOCAL;
        foreach ($mr['trips'] as $ti => $trip) {
            $cells = appLineCells($pos, $mr['st'], $trip['arr'], $trip['dep'], null);
            if ($cells === null) {
                continue;
            }
            $cols[] = ['code' => appTrainCode($d, ['mrun', $rk, 0, $n - 1, $ti], $week), 'no' => null, 'type' => null, 'class' => $cls,
                       'origin' => $mr['st'][0], 'dest' => $mr['st'][$n - 1], 'src' => 'timetable', 'cells' => $cells];
        }
    }
    if ($cols) {
        return $cols;
    }
    // 沒有整列車：營運路線首站的發車＋站間時間推算，到列車終點為止
    foreach ($d['patterns'] as $pk => $p) {
        if ($p['system'] !== $sys || $p['line'] !== $line) {
            continue;
        }
        $n = count($p['st']);
        $cls = $split ? appTrainClassByStops(appLineStationsOf($sys, $line), $p['st']) : APP_CLASS_LOCAL;
        foreach (transferPatternDepartures($d, $pk, 0) as list($sec, $gid)) {
            $ending = $d['groupInfo'][$gid]['ending'] ?? '';
            $last = $n - 1;
            if ($ending !== '' && ($k = array_search($ending, array_slice($p['st'], 1), true)) !== false) {
                $last = $k + 1;
            }
            $st = array_slice($p['st'], 0, $last + 1);
            $arr = [];
            $dep = [];
            for ($j = 0; $j <= $last; $j++) {
                $arr[] = $sec - $p['dep'][0] + $p['arr'][$j];
                $dep[] = $sec - $p['dep'][0] + $p['dep'][$j];
            }
            $cells = appLineCells($pos, $st, $arr, $dep, null);
            if ($cells === null) {
                continue;
            }
            $cols[] = ['code' => appTrainCode($d, ['pattern', $pk, 0, $last, [$sec, $gid]], $week), 'no' => null, 'type' => null, 'class' => $cls,
                       'origin' => $st[0], 'dest' => $st[$last], 'src' => 'estimate', 'cells' => $cells];
        }
    }
    return $cols;
}

/**
 * 一班車在這個方向上的格子：停靠站依序對到列（只往後對；環狀線首末站相同時取下一個），
 * 相鄰兩個停靠站都在這條路線上時，中間的列是「通過」（$skip 的兩站之間例外：走另一條線）。
 * 只留「與前後停靠站相連、都在這條路線上」的停靠站（只是經過路線上的單一站、例如山線車在海線的竹南、彰化不算）；
 * 相鄰停靠站多數是反方向的車、或這個方向上沒有相連的兩站，回 null
 */
function appLineCells(array $pos, array $st, array $arr, array $dep, $skip)
{
    $n = count($st);
    // 方向：相鄰兩站都在路線上時，多數往前才算這個方向（環狀線反方向的車會被排除）
    $fwd = 0;
    $back = 0;
    for ($k = 1; $k < $n; $k++) {
        if (isset($pos[$st[$k - 1]], $pos[$st[$k]])) {
            $pos[$st[$k]][0] > $pos[$st[$k - 1]][0] ? $fwd++ : $back++;
        }
    }
    if ($fwd === 0 || $back > $fwd) {
        return null;
    }
    $skipLo = $skip ? ($pos[$skip[0]][0] ?? null) : null;
    $skipHi = $skip ? ($pos[$skip[1]][0] ?? null) : null;
    if ($skipLo !== null && $skipHi !== null && $skipLo > $skipHi) {
        list($skipLo, $skipHi) = [$skipHi, $skipLo];
    }
    $cells = [];
    $linked = [];
    $prev = null;      // 上一個對到的列
    $prevOn = false;   // 上一個停靠站是否在這條路線上（中間沒有離開路線）
    foreach ($st as $k => $u) {
        $idx = null;
        foreach ($pos[$u] ?? [] as $i) {
            if ($prev === null || $i > $prev) {
                $idx = $i;
                break;
            }
        }
        if ($idx === null) {
            $prevOn = false;
            continue;
        }
        if ($prevOn) {
            for ($i = $prev + 1; $i < $idx; $i++) {
                if ($skipLo === null || $i <= $skipLo || $i >= $skipHi) {
                    $cells[$i] = 'pass';
                }
            }
            $linked[$prev] = true;
            $linked[$idx] = true;
        }
        $cells[$idx] = [$k === 0 ? null : $arr[$k], $k === $n - 1 ? null : $dep[$k]];
        $prev = $idx;
        $prevOn = true;
    }
    foreach ($cells as $i => $x) {
        if (is_array($x) && !isset($linked[$i])) {
            unset($cells[$i]);
        }
    }
    return $linked ? $cells : null;
}

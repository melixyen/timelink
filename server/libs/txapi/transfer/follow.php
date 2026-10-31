<?php
/**
 * 固定路由組合的前後班次（func=follow）。
 *
 * 前端把一個路由組合（Pattern：起站、路線、轉乘站、…、終點站）送來，在這個組合裡找出全天所有串得起來的旅程：
 *   - 第一段的每一班車都試一次；之後每一段搭「趕得上、而且在下車站最早到」的那一班（不重新找別的路線或轉乘站）
 *   - 互相比較：較晚出發、不晚到達的旅程淘汰較早出發的（例如市政府→汐止，好幾班板南線都接同一班臺鐵時只留最晚那班板南線）
 *   - 結果每一班車只出現在一個旅程裡，而且是串起來全程時間最短的那一個
 *
 * Pattern 陣列（func=route 每個方案的 Pattern 可直接送回來）：
 *   {"Type":"Origin","StationUID":"TRTC-BL18"}
 *   {"Type":"Line","LineUID":"TRTC-BL"}                       捷運、輕軌：同一路線（含各營運路線、車種）經過上下車站的車
 *                                                              臺鐵、高鐵：該系統先停上車站、再停下車站的任何一班車
 *   {"Type":"Transfer","From":"TRTC-BL23","To":"TRA-0960","Minutes":null}
 *                                                              From＝To 是同站換車；Minutes 省略或 null 用資料的轉乘分鐘
 *   {"Type":"Destination","StationUID":"TRA-0920"}
 * 上車站：前一個元素的車站（起站或轉乘的 To）；下車站：下一個元素的車站（轉乘的 From 或終點站）
 */

require_once __DIR__ . '/router.php';

/**
 * 檢查並展開 Pattern：回傳 ['legs' => [[system, lineUID, board, alight], ...], 'walks' => [第 i 段之前的轉乘秒數…], 'pattern' => 補上分鐘的 Pattern]
 * walks[0]＝起站先步行（沒有為 0），walks[i]＝第 i-1 段與第 i 段之間，walks[n]＝最後一段下車後步行到終點站。錯誤丟 InvalidArgumentException
 */
function transferFollowParse(array &$d, array $pattern, $walkFactor = 1.0)
{
    $m = $d['m'];
    $n = count($pattern);
    if ($n < 3 || ($pattern[0]['Type'] ?? '') !== 'Origin' || ($pattern[$n - 1]['Type'] ?? '') !== 'Destination') {
        throw new InvalidArgumentException('pattern 必須以 Origin 開始、Destination 結束，中間至少一個 Line');
    }
    $known = function ($u) use ($m) {
        return is_string($u) && isset($m['names'][$u]);
    };
    $cur = $pattern[0]['StationUID'] ?? null;
    if (!$known($cur)) {
        throw new InvalidArgumentException('Origin.StationUID 不是已知的車站：' . json_encode($cur));
    }
    $legs = [];
    $walks = [0];
    $out = [['Type' => 'Origin', 'StationUID' => $cur, 'StationName' => $m['names'][$cur]]];
    $prevType = 'Origin';
    $pendingLine = null;
    for ($i = 1; $i < $n; $i++) {
        $e = $pattern[$i];
        $type = $e['Type'] ?? '';
        if ($type === 'Line') {
            if ($prevType === 'Line') {
                throw new InvalidArgumentException("pattern[$i]：兩個 Line 之間要有 Transfer");
            }
            $lu = (string) ($e['LineUID'] ?? '');
            if (!preg_match('/^([A-Z]+)-(.+)$/', $lu, $mm)) {
                throw new InvalidArgumentException("pattern[$i]：LineUID 格式為 系統-LineID，例如 TRTC-BL、TRA-WL、THSR-HSRL");
            }
            $pendingLine = [$mm[1], $lu, $cur, $i];
            $out[] = ['Type' => 'Line', 'LineUID' => $lu, 'System' => $mm[1]];
        } elseif ($type === 'Transfer' || $type === 'Destination') {
            $st = $type === 'Transfer' ? ($e['From'] ?? null) : ($e['StationUID'] ?? null);
            if (!$known($st)) {
                throw new InvalidArgumentException("pattern[$i]：" . ($type === 'Transfer' ? 'From' : 'StationUID') . ' 不是已知的車站：' . json_encode($st));
            }
            if ($prevType === 'Line') {
                list($sys, $lu, $board) = $pendingLine;
                $legs[] = [$sys, $lu, $board, $st];
                $cur = $st;
            } elseif ($st !== $cur) {
                throw new InvalidArgumentException("pattern[$i]：車站 $st 接不上前一個元素的車站 $cur（中間要有 Line 或 Transfer）");
            }
            if ($type === 'Destination') {
                if ($i !== $n - 1) {
                    throw new InvalidArgumentException('Destination 必須是最後一個元素');
                }
                $out[] = ['Type' => 'Destination', 'StationUID' => $st, 'StationName' => $m['names'][$st]];
                break;
            }
            if ($prevType === 'Transfer') {
                throw new InvalidArgumentException("pattern[$i]：兩個 Transfer 之間要有 Line");
            }
            $to = $e['To'] ?? null;
            if (!$known($to)) {
                throw new InvalidArgumentException("pattern[$i]：To 不是已知的車站：" . json_encode($to));
            }
            $user = isset($e['Minutes']) && $e['Minutes'] !== '' ? $e['Minutes'] : null;
            if ($user !== null && (!is_numeric($user) || $user < 0 || $user > 180)) {
                throw new InvalidArgumentException("pattern[$i]：Minutes 要是 0～180 的數字");
            }
            if ($st === $to) {
                $c = $m['change'][$st] ?? null;
                $sys = strstr($st, '-', true);
                $def = $c['Minutes'] ?? (transferSeedSettings()['TrainChangeDefault'][$sys] ?? transferSeedSettings()['TrainChangeDefault']['metro'] ?? 2);
                $kind = 'train_change';
                $src = $c['Source'] ?? 'default';
                $sec = $user !== null ? (int) round($user * 60) : (int) round($def * 60);
            } else {
                $w = transferWalk($m, $st, $to, true, true);
                if ($w === null && $user === null) {
                    throw new InvalidArgumentException("pattern[$i]：$st 與 $to 不在同一轉乘群組，請用 Minutes 指定步行分鐘");
                }
                $def = $w['Minutes'] ?? null;
                $kind = 'walk';
                $src = $w['Source'] ?? 'user';
                $sec = $user !== null ? (int) round($user * 60) : (int) round($def * 60 * $walkFactor);
            }
            $walks[count($legs)] = $sec;
            $out[] = ['Type' => 'Transfer', 'From' => $st, 'To' => $to, 'Kind' => $kind, 'DefaultMinutes' => $def, 'Minutes' => $user,
                      'UsedMinutes' => round($sec / 60, 1), 'Source' => $user !== null ? 'user' : $src,
                      'FromStationName' => $m['names'][$st], 'ToStationName' => $m['names'][$to]]
                   + (($vid = transferVideos($st, $to)) ? ['Videos' => $vid] : []);
            $cur = $to;
        } else {
            throw new InvalidArgumentException("pattern[$i]：Type 必須是 Origin、Line、Transfer、Destination 之一");
        }
        $prevType = $type;
    }
    if (!$legs) {
        throw new InvalidArgumentException('pattern 至少要有一個 Line');
    }
    for ($i = 0; $i <= count($legs); $i++) {
        $walks[$i] = $walks[$i] ?? 0;
    }
    ksort($walks);
    return ['legs' => $legs, 'walks' => $walks, 'pattern' => $out];
}

/** transfer_seed.json 的 Settings */
function transferSeedSettings()
{
    static $s = null;
    if ($s === null) {
        $s = json_decode((string) @file_get_contents(transferDataFile('Seed')), true)['Settings'] ?? [];
    }
    return $s;
}

/**
 * 某一段（系統、路線、上車站、下車站）全天可搭的車：[[上車發車秒, 下車到達秒, 來源指標, 車輛代碼], ...]，依發車排序；
 * 另附 'min'：從第 i 班往後（含）在下車站最早到達的那一班的索引（後綴最小值，同時到達取較早發車）
 */
function transferFollowLeg(array &$d, array $leg)
{
    list($sys, $lu, $board, $alight) = $leg;
    $list = [];
    if ($sys === 'TRA' || $sys === 'THSR') {
        foreach ($d['stationTrips'][$board] ?? [] as list($ti, $i)) {
            $tr = $d['trips'][$ti];
            if ($tr['system'] !== $sys) {
                continue;
            }
            for ($k = $i + 1, $n = count($tr['st']); $k < $n; $k++) {
                if ($tr['st'][$k] === $alight) {
                    $list[] = [$tr['dep'][$i], $tr['arr'][$k], ['trip', $ti, $i, $k], 'T:' . $tr['key']];
                    break;
                }
            }
        }
    } else {
        // 整列車（有真實站別時刻表的路線）：這班車有停上車站、之後也停下車站
        foreach ($d['stationMRoutes'][$board] ?? [] as list($rk, $j)) {
            $mr = $d['mroutes'][$rk];
            if ($mr['system'] . '-' . $mr['line'] !== $lu) {
                continue;
            }
            $k = array_search($alight, array_slice($mr['st'], $j + 1), true);
            if ($k === false) {
                continue;
            }
            $k += $j + 1;
            foreach ($mr['trips'] as $ti => $t) {
                $list[] = [$t['dep'][$j], $t['arr'][$k], ['mrun', $rk, $j, $k, $ti], 'R:' . $rk . '#' . $ti];
            }
        }
        $seen = [];
        foreach ($d['stationPatterns'][$board] ?? [] as list($pk, $j)) {
            $p = $d['patterns'][$pk];
            if ($p['system'] . '-' . $p['line'] !== $lu) {
                continue;
            }
            $k = null;
            for ($x = $j + 1, $n = count($p['st']); $x < $n; $x++) {
                if ($p['st'][$x] === $alight) {
                    $k = $x;
                    break;
                }
            }
            if ($k === null) {
                continue;
            }
            foreach (transferPatternDepartures($d, $pk, $j) as $dep) {
                $arr = $dep[0] + transferPatternTravel($d, $p, $j, $k)[0];
                // 同一班車（同一站的同一筆時刻）可能屬於幾條營運路線，留到得最早的
                $vid = 'M:' . $dep[1] . '@' . $dep[0];
                if (isset($seen[$vid]) && $list[$seen[$vid]][1] <= $arr) {
                    continue;
                }
                $row = [$dep[0], $arr, ['pattern', $pk, $j, $k, $dep], $vid];
                if (isset($seen[$vid])) {
                    $list[$seen[$vid]] = $row;
                } else {
                    $seen[$vid] = count($list);
                    $list[] = $row;
                }
            }
        }
    }
    usort($list, function ($a, $b) { return [$a[0], $a[1]] <=> [$b[0], $b[1]]; });
    $min = [];
    $best = null;
    for ($i = count($list) - 1; $i >= 0; $i--) {
        if ($best === null || $list[$i][1] <= $list[$best][1]) {
            $best = $i;
        }
        $min[$i] = $best;
    }
    return ['list' => $list, 'min' => $min];
}

/** 第一個發車 ≥ t 的索引（沒有回 count） */
function transferFollowFirst(array $list, $t)
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
    return $lo;
}

/**
 * 全天在這個路由組合裡串得起來、互不支配的旅程（依出發排序）。每個旅程：['dep' => 秒, 'arr' => 秒, 'rides' => [[來源指標, 到達秒, 車輛代碼], ...]]
 */
function transferFollowAll(array &$d, array $parsed)
{
    $legs = [];
    foreach ($parsed['legs'] as $i => $leg) {
        $legs[$i] = transferFollowLeg($d, $leg);
        if (!$legs[$i]['list']) {
            list($sys, $lu, $b, $a) = $leg;
            throw new InvalidArgumentException(sprintf('第 %d 段 %s：這一天沒有從 %s（%s）開往 %s（%s）的車',
                $i + 1, $lu, $d['m']['names'][$b]['Zh_tw'] ?? $b, $b, $d['m']['names'][$a]['Zh_tw'] ?? $a, $a));
        }
    }
    $walks = $parsed['walks'];
    $n = count($legs);
    $chains = [];
    foreach ($legs[0]['list'] as $first) {
        $rides = [[$first[2], $first[1], $first[3]]];
        $t = $first[1];
        $ok = true;
        for ($i = 1; $i < $n; $i++) {
            $ready = $t + $walks[$i];
            $x = transferFollowFirst($legs[$i]['list'], $ready);
            if ($x >= count($legs[$i]['list'])) {
                $ok = false;
                break;
            }
            $list = $legs[$i]['list'];
            $v = $list[$legs[$i]['min'][$x]];
            if ($v[3] === $rides[$i - 1][2]) {
                // 同站換車卻接到同一班車（例如原車繼續開）不算轉乘：改找其他班車中最早到的
                $v = null;
                for ($y = $x, $c = count($list); $y < $c; $y++) {
                    if ($list[$y][3] !== $rides[$i - 1][2] && ($v === null || $list[$y][1] < $v[1])) {
                        $v = $list[$y];
                    }
                }
                if ($v === null) {
                    $ok = false;
                    break;
                }
            }
            $rides[] = [$v[2], $v[1], $v[3]];
            $t = $v[1];
        }
        if ($ok) {
            $chains[] = ['dep' => $first[0] - $walks[0], 'arr' => $t + $walks[$n], 'rides' => $rides];
        }
    }
    // 互不支配：由晚到早出發掃，比之後出發的都更早到才留下（同時出發取早到）
    usort($chains, function ($a, $b) { return [$b['dep'], $a['arr']] <=> [$a['dep'], $b['arr']]; });
    $keep = [];
    $bestArr = PHP_INT_MAX;
    foreach ($chains as $c) {
        if ($c['arr'] < $bestArr) {
            $keep[] = $c;
            $bestArr = $c['arr'];
        }
    }
    // 保險：每一班車只出現一次（理論上上面已保證），依全程時間由短到長收
    usort($keep, function ($a, $b) { return [$a['arr'] - $a['dep'], $b['dep']] <=> [$b['arr'] - $b['dep'], $a['dep']]; });
    $used = [];
    $final = [];
    foreach ($keep as $c) {
        $ids = array_column($c['rides'], 2);
        $dup = false;
        foreach ($ids as $id) {
            if (isset($used[$id])) {
                $dup = true;
                break;
            }
        }
        if ($dup) {
            continue;
        }
        foreach ($ids as $id) {
            $used[$id] = true;
        }
        $final[] = $c;
    }
    usort($final, function ($a, $b) { return $a['dep'] <=> $b['dep']; });
    return $final;
}

/** 旅程輸出（與 func=route 的方案同樣的欄位） */
function transferFollowOutput(array &$d, array $parsed, array $c, $withStops = true)
{
    $steps = [];
    $p = $parsed['pattern'];
    $walks = $parsed['walks'];
    $tIdx = 0;   // 依序對到 pattern 的 Transfer
    $transfers = array_values(array_filter($p, function ($e) { return $e['Type'] === 'Transfer'; }));
    $legCount = count($c['rides']);
    $walkMin = 0;
    $km = 0.0;
    $kmSrc = 'tdx';
    $tier = 'real';
    $addTransfer = function ($i) use (&$steps, &$tIdx, $transfers, $walks, &$walkMin) {
        $e = $transfers[$tIdx++];
        $min = round($walks[$i] / 60, 1);
        $min = $min == (int) $min ? (int) $min : $min;
        $steps[] = ['Type' => $e['Kind'], 'FromStationUID' => $e['From'], 'ToStationUID' => $e['To'], 'Minutes' => $min,
                    'Source' => $e['Source'], 'FromStationName' => $e['FromStationName'], 'ToStationName' => $e['ToStationName']]
                   + (isset($e['Videos']) ? ['Videos' => $e['Videos']] : []);
        if ($e['Kind'] === 'walk') {
            $walkMin += $min;
        }
    };
    $firstIsWalk = $p[1]['Type'] === 'Transfer';
    if ($firstIsWalk) {
        $addTransfer(0);
    }
    foreach ($c['rides'] as $i => list($par, $arr)) {
        if ($i > 0) {
            $addTransfer($i);
        }
        $s = transferRideStep($d, $par, $arr);
        unset($s['_dep'], $s['_arr'], $s['_par']);
        if ($withStops) {
            $s['StopTimes'] = transferRideStops($d, $par, $arr);
        }
        $s['FromStationName'] = $d['m']['names'][$s['FromStationUID']] ?? null;
        $s['ToStationName'] = $d['m']['names'][$s['ToStationUID']] ?? null;
        $steps[] = $s;
        $tier = finderDbWorstTier($tier, $s['DataTier']);
        if ($km !== null && $s['DistanceKm'] !== null) {
            $km += $s['DistanceKm'];
            $kmSrc = $s['DistanceSource'] === 'estimate' ? 'estimate' : $kmSrc;
        } else {
            $km = null;
        }
    }
    if ($tIdx < count($transfers)) {
        $addTransfer($legCount);
    }
    return [
        'DepartureTime'   => transferHM($c['dep']),
        'ArrivalTime'     => transferHM($c['arr']),
        'DurationMinutes' => (int) round(($c['arr'] - $c['dep']) / 60),
        'TransferCount'   => $legCount - 1,
        'RideCount'       => $legCount,
        'WalkMinutes'     => $walkMin,
        'DistanceKm'      => $km === null ? null : round($km, 1),
        'DistanceSource'  => $km === null ? null : $kmSrc,
        'DataTier'        => $tier,
        'Steps'           => $steps,
    ];
}

/**
 * func=follow 主程式。$sel：['time' => 秒, 'before' => 分或 null, 'after' => 分或 null, 'prev' => 筆數, 'next' => 筆數, 'all' => bool]
 * 早於 time 的一側：有 before 用分鐘範圍，否則取 prev 筆；不早於 time 的一側：有 after 用分鐘範圍，否則取 next 筆
 */
function transferFollow(SqliteDao $db, $week, array $pattern, array $sel, $walkFactor = 1.0)
{
    $d = transferRouterData($db, $week);
    $parsed = transferFollowParse($d, $pattern, $walkFactor);
    $all = transferFollowAll($d, $parsed);
    $t = $sel['time'];
    $split = 0;
    while ($split < count($all) && $all[$split]['dep'] < $t) {
        $split++;
    }
    if (!empty($sel['all'])) {
        $from = 0;
        $to = count($all);
    } else {
        if ($sel['before'] !== null) {
            $from = $split;
            while ($from > 0 && $all[$from - 1]['dep'] >= $t - $sel['before'] * 60) {
                $from--;
            }
        } else {
            $from = max(0, $split - $sel['prev']);
        }
        if ($sel['after'] !== null) {
            $to = $split;
            while ($to < count($all) && $all[$to]['dep'] <= $t + $sel['after'] * 60) {
                $to++;
            }
        } else {
            $to = min(count($all), $split + $sel['next']);
        }
    }
    // 回傳的出發時間跨度不超過上限（用班數時）：從離基準時刻較遠的一端去掉
    if (!empty($sel['maxSpan'])) {
        while ($to - $from > 1 && $all[$to - 1]['dep'] - $all[$from]['dep'] > $sel['maxSpan']) {
            if ($t - $all[$from]['dep'] > $all[$to - 1]['dep'] - $t) {
                $from++;
            } else {
                $to--;
            }
        }
    }
    $options = [];
    for ($i = $from; $i < $to; $i++) {
        $options[] = transferFollowOutput($d, $parsed, $all[$i], $sel['stops'] ?? true);
    }
    return [
        'pattern'  => $parsed['pattern'],
        'total'    => count($all),
        'first'    => $all ? transferHM($all[0]['dep']) : null,
        'last'     => $all ? transferHM($all[count($all) - 1]['dep']) : null,
        'focus'    => $split < $to && $split >= $from ? $split - $from : null,
        'earlier'  => $from,
        'later'    => count($all) - $to,
        'prevTime' => $from > 0 ? transferHM($all[$from - 1]['dep']) : null,
        'nextTime' => $to < count($all) ? transferHM($all[$to]['dep']) : null,
        'options'  => $options,
    ];
}

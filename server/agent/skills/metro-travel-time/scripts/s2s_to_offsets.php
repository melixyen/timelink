<?php
/**
 * 把 TDX S2STravelTime 依 本站 的規則存成「路線站距表」或「原始起訖矩陣」（與 finderDbBuildMetroTravel() 相同）。
 * 獨立重寫的版本（不載入 libs/），用來交叉比對 API 的結果。
 *
 * 用法：
 *   php s2s_to_offsets.php <S2STravelTime/{SYS}.json> <StationOfRoute/{SYS}.json> > out.json
 *
 * 規則（只做優先順序的第 1、2 層：tdx → reversed；不讀車站時刻表，所以第 3、4 層 timetable／timetable-reversed 補的段落不會出現，
 * 該處站序會斷開，其餘應與 API 完全相同）：
 *   - 原始為相鄰站鏈：依 StationOfRoute 完整站序逐段取值：同方向 → 反方向倒用（source=reversed）；某段都沒有就斷開。
 *     停站用 TDX 的 StopTime（出發站的停站）：同方向取「本站→下一站」，倒用時取反方向「本站→前一站」
 *   - 原始為起訖矩陣：站序只留該車種有資料的車站，只用同方向相鄰值依站序累加（不補停站、不倒用），拿每一筆原始值比對；
 *     全部一致、且路線上沒有矩陣缺少的車站才存路線站距表，否則存原始矩陣
 * 路線站距表的兩站間時間 = 迄站 arr − 起站 dep（秒）。
 */

function s2sLoad($p)
{
    $j = json_decode((string) file_get_contents($p), true);
    if (!is_array($j)) {
        fwrite(STDERR, "無法讀取 $p\n");
        exit(1);
    }
    return $j;
}

/** a→b 依優先順序：tdx（同方向）→ reversed（反方向倒用）。回傳 [run, source, 本站停站或 null] 或 null */
function s2sSegment($a, $b, array $adj, $allowReverse)
{
    if (isset($adj["$a>$b"])) {
        return [$adj["$a>$b"][0], 'tdx', $adj["$a>$b"][1]];
    }
    if ($allowReverse && isset($adj["$b>$a"])) {
        return [$adj["$b>$a"][0], 'reversed', null];
    }
    return null;
}

/** 逐段取值，某段沒有資料就斷開；回傳多段 [[seq, rows]]，每段至少兩站 */
function s2sLadderChain(array $seq, array $adj, $useStop, $allowReverse)
{
    $n = count($seq);
    $segs = [];
    for ($k = 0; $k < $n - 1; $k++) {
        $segs[$k] = s2sSegment($seq[$k], $seq[$k + 1], $adj, $allowReverse);
    }
    $pieces = [];
    $start = 0;
    for ($k = 0; $k < $n; $k++) {
        if ($k < $n - 1 && $segs[$k] !== null) {
            continue;
        }
        if ($k > $start) {
            $rows = [];
            $dep = 0;
            for ($i = $start; $i <= $k; $i++) {
                $run = $i > $start ? $segs[$i - 1][0] : 0;
                $arr = $i > $start ? $dep + $run : 0;
                $dwell = 0;
                if ($start < $i && $i < $k && $useStop) {
                    $dwell = $segs[$i][2];
                    if ($dwell === null) {   // 倒用：本站在反方向的停站（反方向「本站→前一站」那筆）
                        $dwell = $allowReverse ? ($adj[$seq[$i] . '>' . $seq[$i - 1]][1] ?? 0) : 0;
                    }
                }
                $dep = $arr + $dwell;
                $src = $i > $start ? $segs[$i - 1][1] : $segs[$start][1];
                $rows[] = ['station' => $seq[$i], 'run' => $run, 'dwell' => $dwell, 'arr' => $arr, 'dep' => $dep, 'source' => $src];
            }
            $pieces[] = [array_slice($seq, $start, $k - $start + 1), $rows];
        }
        $start = $k + 1;
    }
    return $pieces;
}

function s2sReproduces(array $chains, $a, $b, $v)
{
    foreach ($chains as $c) {
        $seq = $c['seq'];
        $rows = $c['stations'];
        foreach ($seq as $i => $x) {
            if ($x !== $a) {
                continue;
            }
            for ($j = $i + 1; $j < count($seq); $j++) {
                if ($seq[$j] === $b && $rows[$j]['arr'] - $rows[$i]['dep'] === $v) {
                    return true;
                }
            }
        }
    }
    return false;
}

/** small 是否為 big 的連續片段 */
function s2sContains(array $small, array $big)
{
    $n = count($small);
    for ($i = 0; $i + $n <= count($big); $i++) {
        if (array_slice($big, $i, $n) === $small) {
            return true;
        }
    }
    return false;
}

function s2sConvert(array $s2s, array $sor)
{
    $groups = [];   // "line|tt" => [[a, b, run, stop]]
    $order = [];
    foreach ($s2s as $r) {
        $key = $r['LineID'] . '|' . (int) ($r['TrainType'] ?? 0);
        if (!isset($groups[$key])) {
            $groups[$key] = [];
            $order[] = [$r['LineID'], (int) ($r['TrainType'] ?? 0)];
        }
        foreach ($r['TravelTimes'] ?? [] as $t) {
            $groups[$key][] = [(string) $t['FromStationID'], (string) $t['ToStationID'], (int) $t['RunTime'], (int) ($t['StopTime'] ?? 0)];
        }
    }
    $out = [];
    foreach ($order as list($line, $tt)) {
        $entries = $groups[$line . '|' . $tt];
        $adj = [];
        $stations = [];
        foreach ($entries as list($a, $b, $run, $stop)) {
            $stations[$a] = true;
            $stations[$b] = true;
            if ($a !== $b && $run > 0 && (!isset($adj["$a>$b"]) || $run < $adj["$a>$b"][0])) {
                $adj["$a>$b"] = [$run, $stop];
            }
        }
        $isMatrix = count($adj) > 2 * count($stations);
        $lineStations = [];
        foreach ($groups as $gk => $e) {
            if (explode('|', $gk)[0] === $line) {
                foreach ($e as $x) {
                    $lineStations[$x[0]] = true;
                    $lineStations[$x[1]] = true;
                }
            }
        }
        $chains = [];
        $missing = [];
        foreach ($sor as $r) {
            if ($r['LineID'] !== $line) {
                continue;
            }
            $st = $r['Stations'];
            usort($st, function ($p, $q) { return $p['Sequence'] <=> $q['Sequence']; });
            $full = array_map(function ($x) { return (string) $x['StationID']; }, $st);
            foreach ($full as $x) {
                if (!isset($lineStations[$x])) {
                    $missing[$x] = true;
                }
            }
            $seq = $isMatrix ? array_values(array_filter($full, function ($x) use ($stations) { return isset($stations[$x]); })) : $full;
            foreach (s2sLadderChain($seq, $adj, !$isMatrix, !$isMatrix) as list($piece, $rows)) {
                $srcs = [];
                foreach (array_slice($rows, 1) as $x) {
                    $srcs[$x['source']] = true;
                }
                $chains[] = ['route' => $r['RouteID'], 'direction' => $r['Direction'], 'source' => implode('+', array_keys($srcs)),
                             'seq' => $piece, 'stations' => $rows];
            }
        }
        $checked = 0;
        $mismatch = 0;
        $storage = 'route';
        $pairSet = [];
        foreach ($entries as list($a, $b, $run)) {
            if ($run > 0) {
                $pairSet["$a>$b>$run"] = [$a, $b, $run];
            }
        }
        if ($isMatrix) {
            foreach ($pairSet as list($a, $b, $v)) {
                $checked++;
                if (!s2sReproduces($chains, $a, $b, $v)) {
                    $mismatch++;
                }
            }
            if ($mismatch || !$chains || $missing) {
                $storage = 'matrix';
            }
        }
        $item = ['line' => $line, 'train_type' => $tt, 'raw_shape' => $isMatrix ? 'matrix' : 'chain', 'storage' => $storage,
                 'raw_pairs' => count($entries), 'checked' => $checked, 'mismatch' => $mismatch];
        if ($storage === 'matrix') {
            $pairs = array_values($pairSet);
            usort($pairs, function ($p, $q) { return [strcmp($p[0], $q[0]), strcmp($p[1], $q[1]), $p[2] <=> $q[2]] <=> [0, 0, 0]; });
            $item['pairs'] = $pairs;
        } else {
            $keep = [];
            foreach ($chains as $i => $c) {
                $covered = false;
                foreach ($chains as $j => $o) {
                    if ($j !== $i && $o['direction'] === $c['direction']
                        && ((count($o['seq']) > count($c['seq']) && s2sContains($c['seq'], $o['seq'])) || ($o['seq'] === $c['seq'] && $j < $i))) {
                        $covered = true;
                        break;
                    }
                }
                if (!$covered) {
                    unset($c['seq']);
                    $keep[] = $c;
                }
            }
            $item['routes'] = $keep;
        }
        $out[] = $item;
    }
    return $out;
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    if (count($argv) < 3) {
        fwrite(STDERR, "用法：php s2s_to_offsets.php <S2STravelTime/{SYS}.json> <StationOfRoute/{SYS}.json>\n");
        exit(1);
    }
    $s2s = s2sLoad($argv[1]);
    $sor = s2sLoad($argv[2]);
    // TDX 檔案外層可能包一層物件
    $unwrap = function ($j) { return array_is_list($j) ? $j : (current(array_filter($j, function ($v) { return is_array($v) && array_is_list($v); })) ?: []); };
    echo json_encode(s2sConvert($unwrap($s2s), $unwrap($sor)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
}

<?php
/**
 * 由站別時刻表逐班串車，推算起訖矩陣（與 本站 的 finderDbBuildTimetableMatrix() 相同）。
 * 給 TDX 沒有 S2STravelTime、但有站別時刻表的系統（目前淡海、安坑輕軌）。獨立重寫的版本（不載入 libs/），用來交叉比對。
 *
 * 用法：
 *   php timetable_matrix.php <StationTimeTable/{SYS}.json> <StationOfRoute/{SYS}.json> > matrix.json
 * 輸出：[{line, from, to, minutes, samples, agree}]；「某站→終點站」沒有資料（終點站沒有往終點方向的發車時刻）。
 */

/** 營運日分鐘（04:00 起算，凌晨加 1440） */
function ttmMinutes($t)
{
    list($h, $m) = array_map('intval', explode(':', $t));
    $v = $h * 60 + $m;
    return $v < 240 ? $v + 1440 : $v;
}

/** 眾數：次數最多者，同次數取最先出現的（與 Python Counter.most_common 相同）；回傳 [值, 次數] */
function ttmMode(array $list)
{
    $cnt = [];
    foreach ($list as $x) {
        $cnt[(string) $x] = ($cnt[(string) $x] ?? 0) + 1;
    }
    $best = null;
    foreach ($cnt as $k => $n) {
        if ($best === null || $n > $best[1]) {
            $best = [(int) $k, $n];
        }
    }
    return $best;
}

function ttmBuild(array $stt, array $sor)
{
    $idx = [];
    $tags = [];
    foreach ($stt as $g) {
        $tag = $g['ServiceDay']['ServiceTag'] ?? '';
        $tags[$tag] = true;
        $dest = ($g['DestinationStaionID'] ?? '') ?: (($g['DestinationStationID'] ?? '') ?: '');
        foreach ($g['Timetables'] ?? [] as $t) {
            $s = ($t['DepartureTime'] ?? '') ?: ($t['ArrivalTime'] ?? '');
            if ($s) {
                $idx[$g['StationID'] . '|' . $dest . '|' . $tag][] = ttmMinutes($s);
            }
        }
    }
    foreach ($idx as &$l) {
        sort($l);
    }
    unset($l);
    $tagList = array_map('strval', array_keys($tags));
    sort($tagList, SORT_STRING);
    $od = [];   // "line|a|b" => [分鐘差...]
    foreach ($sor as $r) {
        $stArr = $r['Stations'];
        usort($stArr, function ($p, $q) { return $p['Sequence'] <=> $q['Sequence']; });
        $st = array_map(function ($x) { return (string) $x['StationID']; }, $stArr);
        $dest = end($st);
        foreach ($tagList as $tag) {
            $get = function ($s) use ($idx, $dest, $tag) { return $idx[$s . '|' . $dest . '|' . $tag] ?? []; };
            // 1. 相鄰兩站的發車時間差眾數（A 站每班之後 B 站同終點的第一班）
            $mode = [];
            for ($i = 0; $i < count($st) - 1; $i++) {
                $B = $get($st[$i + 1]);
                $d = [];
                foreach ($get($st[$i]) as $t) {
                    foreach ($B as $x) {
                        if ($x >= $t) {
                            $d[] = $x - $t;
                            break;
                        }
                    }
                }
                if ($d) {
                    $mode[$i] = ttmMode($d)[0];
                }
            }
            // 2. 從起點站每班出發，逐站找「前站時刻 + 眾數」±1 分內、未用過的班次
            $used = [];
            foreach ($get($st[0]) as $t0) {
                $times = [$t0];
                $cur = $t0;
                for ($i = 0; $i < count($st) - 1; $i++) {
                    if (!isset($mode[$i])) {
                        break;
                    }
                    $want = $cur + $mode[$i];
                    $best = null;
                    foreach ($get($st[$i + 1]) as $x) {
                        if (abs($x - $want) <= 1 && !isset($used[$i + 1][$x]) && ($best === null || abs($x - $want) < abs($best - $want))) {
                            $best = $x;
                        }
                    }
                    if ($best === null) {
                        break;
                    }
                    $used[$i + 1][$best] = true;
                    $times[] = $best;
                    $cur = $best;
                }
                // 3. 同一班車任兩站相減
                for ($i = 0; $i < count($times); $i++) {
                    for ($j = $i + 1; $j < count($times); $j++) {
                        $od[$r['LineID'] . '|' . $st[$i] . '|' . $st[$j]][] = $times[$j] - $times[$i];
                    }
                }
            }
        }
    }
    $keys = array_keys($od);
    usort($keys, function ($p, $q) {
        $a = explode('|', $p);
        $b = explode('|', $q);
        return [strcmp($a[0], $b[0]), strcmp($a[1], $b[1]), strcmp($a[2], $b[2])] <=> [0, 0, 0];
    });
    $out = [];
    foreach ($keys as $k) {
        list($line, $a, $b) = explode('|', $k);
        list($m, $n) = ttmMode($od[$k]);
        $out[] = ['line' => $line, 'from' => $a, 'to' => $b, 'minutes' => $m, 'samples' => count($od[$k]), 'agree' => $n];
    }
    return $out;
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    if (count($argv) < 3) {
        fwrite(STDERR, "用法：php timetable_matrix.php <StationTimeTable/{SYS}.json> <StationOfRoute/{SYS}.json>\n");
        exit(1);
    }
    $load = function ($p) {
        $j = json_decode((string) file_get_contents($p), true);
        return array_is_list($j) ? $j : (current(array_filter($j, function ($v) { return is_array($v) && array_is_list($v); })) ?: []);
    };
    echo json_encode(ttmBuild($load($argv[1]), $load($argv[2])), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
}

<?php
/**
 * 產生找路規則的候選（libs/txapi/transfer/data/transfer_route_rules.json）：用離線資料估算，人工確認後保留。
 *
 *   php agent/skills/rail-transfer/scripts/build_route_rules.php [--dry] [--only=lines|pairs|bans] [--line=TRTC-BL]
 *
 *   LineTransfers（加速清單：只這樣走）
 *     兩條路線之間有好幾個轉乘群組時，對兩條路線上所有「上車站 × 下車站」估算經過每個群組轉乘的分鐘（行駛＋步行＋行駛，臺鐵、高鐵另以慢車再算一次），
 *     任何一組起訖都不是最快（超過最快的 3 分或 10%）的群組就不列，其餘寫成這對路線的可用群組；全部都有用就不寫規則
 *   DirectPairs（排除名單：不這樣走）
 *     同一條路線上的兩個轉乘站：直接搭的估計分鐘（臺鐵用區間車），比任何「步行轉搭其他系統或路線」的走法都快至少 5 分（或 10%）時，寫成站對
 *
 *   RouteBans（經由排除）
 *     RouteBanRings 的環線（臺鐵環島）切成區段，兩個區段之間所有起訖都是同一邊比較遠、而且遠的一邊 ≥ 近的一邊 × MinRatio 時，
 *     產生「From 區段 A、To 區段 B、Via 遠的一邊中間的站」；同一區段來回（繞一圈）一律產生
 *
 * 規則檔裡 Source=manual 的項目一律保留；Source=generated 的項目重新產生，但人工加上的 Disabled 會沿用。
 * 估算時暫停既有規則（appRouteRulesEnabled(false)），避免規則影響自己的計算。
 */

$_SERVER['REQUEST_URI'] = '/';
$root = dirname(__DIR__, 4);
chdir($root);
foreach (['config.php', 'http.php', 'sqlite.php', 'tdx.php', 'proxy.php', 'ratelimit.php', 'railcache.php', 'txapi/txapi.php'] as $l) {
    require $root . '/libs/' . $l;
}

$opt = getopt('', ['dry', 'only:', 'line:']);
$dry = isset($opt['dry']);
$only = $opt['only'] ?? null;
$onlyLine = $opt['line'] ?? null;

appRouteRulesEnabled(false);
$m = appModel();
$file = transferDataFile('RouteRules');
$rules = json_decode((string) file_get_contents($file), true);
$t0 = microtime(true);

/** 同一路線上兩站的估計分鐘（同站 0；沒有回 INF） */
function brrT($line, $u, $v, $class = null)
{
    if ($u === $v) {
        return 0.0;
    }
    $x = appLegMinutes($line, $u, $v, $class);
    return $x === null ? INF : (float) $x;
}

// ---------------------------------------------------------------- LineTransfers
$lineRules = [];
if ($only === null || $only === 'lines') {
    $done = [];
    foreach ($m['links'] as $a => $tos) {
        foreach ($tos as $b => $gids) {
            $k = $a < $b ? "$a|$b" : "$b|$a";
            if (isset($done[$k]) || count($gids) < 2 || ($onlyLine && $a !== $onlyLine && $b !== $onlyLine)) {
                continue;
            }
            $done[$k] = true;
            // 每個群組取步行最短的一對轉乘站（a 線上的 x、b 線上的 y）
            $tr = [];
            foreach ($gids as $gid) {
                $best = null;
                foreach ($m['groups'][$gid]['Stations'] as $x) {
                    if (!in_array($a, $x['Lines'], true)) {
                        continue;
                    }
                    foreach ($m['groups'][$gid]['Stations'] as $y) {
                        if (!in_array($b, $y['Lines'], true)) {
                            continue;
                        }
                        $w1 = transferWalk($m, $x['StationUID'], $y['StationUID'], false, true);
                        $w2 = transferWalk($m, $y['StationUID'], $x['StationUID'], false, true);
                        if ($w1 === null || $w2 === null) {
                            continue;
                        }
                        $c = $w1['Minutes'] + $w2['Minutes'];
                        if ($best === null || $c < $best[3]) {
                            $best = [$x['StationUID'], $y['StationUID'], [$w1['Minutes'], $w2['Minutes']], $c];
                        }
                    }
                }
                if ($best) {
                    $tr[$gid] = $best;
                }
            }
            if (count($tr) < 2) {
                continue;
            }
            $useful = [];
            $sysA = substr($a, 0, strpos($a, '-'));
            $sysB = substr($b, 0, strpos($b, '-'));
            $classes = [null];
            if (in_array($sysA, FINDER_TRAIN_SYSTEMS, true) || in_array($sysB, FINDER_TRAIN_SYSTEMS, true)) {
                $classes[] = APP_CLASS_LOCAL;
            }
            foreach ($classes as $cls) {
                foreach ([[$a, $b, 0], [$b, $a, 1]] as list($L1, $L2, $dir)) {
                    $c1 = in_array(substr($L1, 0, strpos($L1, '-')), FINDER_TRAIN_SYSTEMS, true) ? $cls : null;
                    $c2 = in_array(substr($L2, 0, strpos($L2, '-')), FINDER_TRAIN_SYSTEMS, true) ? $cls : null;
                    // 先算每站到各群組、各群組到每站的分鐘
                    $to = [];
                    $from = [];
                    foreach ($tr as $gid => list($x, $y, $w)) {
                        $s1 = $dir === 0 ? $x : $y;   // L1 上的轉乘站
                        $s2 = $dir === 0 ? $y : $x;   // L2 上的轉乘站
                        $walk = $w[$dir];
                        foreach ($m['lineStations'][$L1] as $o) {
                            $to[$gid][$o] = brrT($L1, $o, $s1, $c1) + $walk;
                        }
                        foreach ($m['lineStations'][$L2] as $dd) {
                            $from[$gid][$dd] = brrT($L2, $s2, $dd, $c2);
                        }
                    }
                    foreach ($m['lineStations'][$L1] as $o) {
                        foreach ($m['lineStations'][$L2] as $dd) {
                            $cost = [];
                            foreach (array_keys($tr) as $gid) {
                                $cost[$gid] = $to[$gid][$o] + $from[$gid][$dd];
                            }
                            $min = min($cost);
                            if (!is_finite($min)) {
                                continue;
                            }
                            foreach ($cost as $gid => $c) {
                                if ($c <= $min + max(3, $min * 0.1)) {
                                    $useful[$gid] = true;
                                }
                            }
                        }
                    }
                }
            }
            $drop = array_values(array_diff(array_keys($tr), array_keys($useful)));
            if ($drop && $useful) {
                $names = function ($ids) use ($m) {
                    return array_map(function ($g) use ($m) { return $m['groups'][$g]['GroupName']['Zh_tw'] ?? $g; }, $ids);
                };
                $lineRules[$k] = ['Lines' => [explode('|', $k)[0], explode('|', $k)[1]], 'Groups' => array_values(array_keys($useful)),
                                  'Note' => ($m['lineNames'][$a]['Zh_tw'] ?? $a) . '—' . ($m['lineNames'][$b]['Zh_tw'] ?? $b) . '：只在'
                                            . implode('、', $names(array_keys($useful))) . '轉乘（' . implode('、', $names($drop)) . '轉乘不會比較快）',
                                  'Source' => 'generated',
                                  'Evidence' => ['Dropped' => $drop, 'Method' => '兩線所有上下車站組合估算經各群組轉乘的分鐘，都不是最快（容許 3 分或 10%）']];
            }
        }
    }
    fprintf(STDERR, "LineTransfers: %d 條（%.1f 秒）\n", count($lineRules), microtime(true) - $t0);
}

// ---------------------------------------------------------------- DirectPairs
$pairRules = [];
if ($only === null || $only === 'pairs') {
    $t1 = microtime(true);
    $isMulti = function ($u) use ($m) {
        foreach ($m['groupOf'][$u] ?? [] as $gid) {
            if (($m['groups'][$gid]['Type'] ?? '') === 'multi') {
                return true;
            }
        }
        return isset($m['change'][$u]) && count($m['stationLines'][$u] ?? []) > 1;
    };
    $n = 0;
    foreach ($m['lineStations'] as $line => $seq) {
        if ($onlyLine && $line !== $onlyLine) {
            continue;
        }
        $sys = substr($line, 0, strpos($line, '-'));
        $ts = array_values(array_filter($seq, $isMulti));
        for ($i = 0; $i < count($ts); $i++) {
            for ($j = $i + 1; $j < count($ts); $j++) {
                $a = $ts[$i];
                $b = $ts[$j];
                $k = $a < $b ? "$a|$b" : "$b|$a";
                if (isset($pairRules[$k])) {
                    continue;
                }
                // 直接搭的分鐘：臺鐵用區間車（沒有區間車才用最快的車），只搭區間車的人也不會因為規則錯過比較快的繞道
                $direct = min(brrT($line, $a, $b, $sys === 'TRA' ? APP_CLASS_LOCAL : null), brrT($line, $b, $a, $sys === 'TRA' ? APP_CLASS_LOCAL : null));
                if (!is_finite($direct) && $sys === 'TRA') {
                    $direct = min(brrT($line, $a, $b), brrT($line, $b, $a));
                }
                if (!is_finite($direct)) {
                    continue;
                }
                $n++;
                $alt = null;
                foreach ([[$a, $b], [$b, $a]] as list($f, $t)) {
                    foreach (appCoreRoutes($m, $f, $t, ['extra' => 0, 'limit' => 50, 'deeper' => 2]) as $r) {
                        // 繞道＝不是直接搭這條路線的走法：有步行、或搭了別的路線（換系統、同系統換線、繞環島都算；同站換快慢車不算）
                        $detour = false;
                        // 系統直達段（LineUID＝TRA）看經過的路線串（Path）：全程都在這條路線才算直接搭
                        foreach ($r['legs'] as $l) {
                            if ($l['Type'] === 'walk') {
                                $detour = true;
                                continue;
                            }
                            foreach (appLegPath($l) as $pl) {
                                if ($pl['LineUID'] !== $line) {
                                    $detour = true;
                                }
                            }
                        }
                        if ($detour && ($alt === null || $r['EstimatedMinutes'] < $alt[0])) {
                            $alt = [$r['EstimatedMinutes'], $r['route']];
                        }
                    }
                }
                if ($alt === null || $direct + max(5, $direct * 0.1) > $alt[0]) {
                    continue;
                }
                $pairRules[$k] = ['Stations' => [$a, $b],
                                  'Note' => ($m['names'][$a]['Zh_tw'] ?? $a) . '—' . ($m['names'][$b]['Zh_tw'] ?? $b) . '：直接搭' . ($m['lineNames'][$line]['Zh_tw'] ?? $line),
                                  'Source' => 'generated',
                                  'Evidence' => ['Line' => $line, 'DirectMinutes' => $direct, 'BestDetourMinutes' => $alt[0], 'BestDetour' => $alt[1]]];
            }
        }
    }
    fprintf(STDERR, "DirectPairs: 檢查 %d 對，%d 對寫入（%.1f 秒）\n", $n, count($pairRules), microtime(true) - $t1);
}

// ---------------------------------------------------------------- RouteBans（環線）
$banRules = [];
if ($only === null || $only === 'bans') {
    $t2 = microtime(true);
    foreach ($rules['RouteBanRings'] ?? [] as $ring) {
        if (!empty($ring['Disabled'])) {
            continue;
        }
        // 環上的車站（依環線方向）與累計里程
        $st = [];
        $lineOf = [];
        foreach ($ring['Path'] as $seg) {
            $seq = $m['lineStations'][$seg['Line']];
            $ia = array_search($seg['StationRange'][0], $seq, true);
            $ib = array_search($seg['StationRange'][1], $seq, true);
            $slice = $ia <= $ib ? array_slice($seq, $ia, $ib - $ia + 1) : array_reverse(array_slice($seq, $ib, $ia - $ib + 1));
            foreach ($slice as $u) {
                if ($st && end($st) === $u) {
                    continue;
                }
                $st[] = $u;
                $lineOf[$u] = $lineOf[$u] ?? $seg['Line'];
            }
        }
        if (end($st) === $st[0]) {
            array_pop($st);
        }
        $n = count($st);
        $pos = [0.0];
        for ($i = 1; $i <= $n; $i++) {
            $d = transferRideDistance($ring['System'], [$st[$i - 1], $st[$i % $n]])[0];
            $pos[$i] = $pos[$i - 1] + ($d ?? -1.0);
        }
        // 缺站距的用其他站距的中位數補
        $hops = [];
        for ($i = 1; $i <= $n; $i++) {
            if ($pos[$i] - $pos[$i - 1] >= 0) {
                $hops[] = $pos[$i] - $pos[$i - 1];
            }
        }
        sort($hops);
        $med = $hops ? $hops[intdiv(count($hops), 2)] : 1.0;
        for ($i = 1, $acc = 0.0; $i <= $n; $i++) {
            $d = transferRideDistance($ring['System'], [$st[$i - 1], $st[$i % $n]])[0];
            $acc += $d ?? $med;
            $pos[$i] = $acc;
        }
        $L = $pos[$n];
        $idx = array_flip($st);
        // 區段：分界站起到下一個分界站前一站
        $bIdx = array_map(function ($u) use ($idx) { return $idx[$u]; }, $ring['Boundaries']);
        $secs = [];
        foreach ($bIdx as $k => $b) {
            $e = $bIdx[($k + 1) % count($bIdx)];
            $list = [];
            for ($i = $b; $i !== $e; $i = ($i + 1) % $n) {
                $list[] = $i;
            }
            $secs[] = $list;
        }
        // 並行區間內部（不選作 Via）
        $avoid = [];
        foreach ($ring['Parallel'] ?? [] as $pr) {
            $a = $idx[$pr['StationRange'][0]];
            $b = $idx[$pr['StationRange'][1]];
            for ($i = min($a, $b) + 1; $i < max($a, $b); $i++) {
                $avoid[$i] = true;
            }
        }
        $boundSet = array_flip($bIdx);
        $scope = function ($sec) use ($st, $lineOf) {
            $a = $st[$sec[0]];
            $b = $st[end($sec)];
            return [['Line' => $lineOf[$a] === $lineOf[$b] ? $lineOf[$a] : $lineOf[$b], 'StationRange' => [$a, $b]]];
        };
        $name = function ($i) use ($st, $m) { return $m['names'][$st[$i]]['Zh_tw'] ?? $st[$i]; };
        foreach ($secs as $si => $A) {
            foreach ($secs as $sj => $B) {
                $minRatio = INF;
                $dir = null;   // 1＝順環線方向比較遠，-1＝逆向比較遠
                $ok = true;
                $short = 0;
                $long = 0;
                foreach ($A as $a) {
                    foreach ($B as $b) {
                        if ($a === $b) {
                            continue;
                        }
                        $cw = fmod($pos[$b] - $pos[$a] + $L, $L);
                        $ccw = $L - $cw;
                        $d = $cw > $ccw ? 1 : -1;
                        if ($si !== $sj) {
                            if ($dir !== null && $d !== $dir) {
                                $ok = false;
                                break 2;
                            }
                            $dir = $d;
                        }
                        $r = max($cw, $ccw) / max(0.1, min($cw, $ccw));
                        if ($r < $minRatio) {
                            $minRatio = $r;
                            $short = min($cw, $ccw);
                            $long = max($cw, $ccw);
                        }
                    }
                }
                if (!$ok || ($si !== $sj && $minRatio < $ring['MinRatio'])) {
                    continue;
                }
                // 遠的一邊（不含兩個區段）的車站；同一區段來回＝其他所有區段
                if ($si === $sj) {
                    $arc = [];
                    for ($i = (end($A) + 1) % $n; $i !== $A[0]; $i = ($i + 1) % $n) {
                        $arc[] = $i;
                    }
                } else {
                    $arc = [];
                    if ($dir === 1) {   // 順向遠：從 A 逆著走到 B 是近的，遠的是從 A 的尾巴順著走到 B 的頭
                        for ($i = (end($A) + 1) % $n; $i !== $B[0]; $i = ($i + 1) % $n) {
                            $arc[] = $i;
                        }
                    } else {
                        for ($i = ($A[0] - 1 + $n) % $n; $i !== end($B); $i = ($i - 1 + $n) % $n) {
                            $arc[] = $i;
                        }
                    }
                }
                $cand = array_values(array_filter($arc, function ($i) use ($avoid) { return !isset($avoid[$i]); }));
                if (!$cand) {
                    continue;
                }
                // Via：遠的一邊最中間的分界站（沒有就最中間的站）
                $mid = $cand[intdiv(count($cand), 2)];
                $bc = array_values(array_filter($cand, function ($i) use ($boundSet) { return isset($boundSet[$i]); }));
                if ($bc) {
                    $mid = $bc[intdiv(count($bc), 2)];
                }
                $from = $scope($A);
                $to = $scope($B);
                // 跨系統（AnySystem）：繞回的走法可能從遠的一邊任何一站進來（例如輕軌→捷運→輕軌），Via 放遠的一邊全部車站
                $any = !empty($ring['AnySystem']);
                $via = $any ? array_map(function ($i) use ($st) { return $st[$i]; }, $cand) : [$st[$mid]];
                $banRules[] = [
                    'Name' => $ring['Name'] . '：' . $name($A[0]) . '～' . $name(end($A)) . ' → ' . $name($B[0]) . '～' . $name(end($B)) . ($any ? ' 不經遠的一邊' : ' 不經' . $name($mid)),
                    'From' => $from, 'To' => $to, 'Via' => $via, 'Source' => 'generated',
                    'AnySystem' => $any,
                    'Evidence' => $si === $sj ? ['Ring' => $ring['Name'], 'Loop' => true]
                                              : ['Ring' => $ring['Name'], 'MinRatio' => round($minRatio, 2), 'ShortKm' => round($short, 1), 'LongKm' => round($long, 1)],
                ];
            }
        }
        fprintf(STDERR, "RouteBans（%s）：環長 %.0f 公里、%d 區段，產生 %d 條（%.1f 秒）\n", $ring['Name'], $L, count($secs), count($banRules), microtime(true) - $t2);
    }
}

// ---------------------------------------------------------------- 合併寫回
$merge = function (array $old, array $gen, $keyOf) {
    $out = [];
    $seen = [];
    foreach ($old as $x) {
        $k = $keyOf($x);
        if (($x['Source'] ?? 'manual') !== 'generated') {
            $out[] = $x;          // 人工的一律保留
            $seen[$k] = true;
        } elseif (isset($gen[$k]) && !empty($x['Disabled'])) {
            $gen[$k]['Disabled'] = true;   // 人工停用的產生項目沿用停用
        }
    }
    foreach ($gen as $k => $x) {
        if (!isset($seen[$k])) {
            $out[] = $x;
        }
    }
    return $out;
};
$pairKey = function ($x) { $s = $x['Stations']; sort($s); return implode('|', $s); };
$lineKey = function ($x) { $s = $x['Lines']; sort($s); return implode('|', $s); };
if ($only === null || $only === 'pairs') {
    $old = $rules['DirectPairs'] ?? [];
    if ($onlyLine) {   // 只重算一條路線：其他路線的產生項目保留
        foreach ($old as $x) {
            if (($x['Source'] ?? '') === 'generated' && ($x['Evidence']['Line'] ?? '') !== $onlyLine) {
                $pairRules[$pairKey($x)] = $pairRules[$pairKey($x)] ?? $x;
            }
        }
    }
    $rules['DirectPairs'] = $merge($old, $pairRules, $pairKey);
}
if ($only === null || $only === 'lines') {
    $old = $rules['LineTransfers'] ?? [];
    if ($onlyLine) {
        foreach ($old as $x) {
            if (($x['Source'] ?? '') === 'generated' && !in_array($onlyLine, $x['Lines'], true)) {
                $lineRules[$lineKey($x)] = $lineRules[$lineKey($x)] ?? $x;
            }
        }
    }
    $rules['LineTransfers'] = $merge($old, $lineRules, $lineKey);
}
if ($only === null || $only === 'bans') {
    $keep = array_values(array_filter($rules['RouteBans'] ?? [], function ($x) { return ($x['Source'] ?? '') !== 'generated'; }));
    $dis = [];
    foreach ($rules['RouteBans'] ?? [] as $x) {
        if (!empty($x['Disabled'])) {
            $dis[$x['Name']] = true;
        }
    }
    foreach ($banRules as &$x) {
        if (isset($dis[$x['Name']])) {
            $x['Disabled'] = true;
        }
    }
    unset($x);
    $rules['RouteBans'] = array_merge($keep, $banRules);
}
$rules['GeneratedAt'] = date('Y-m-d H:i:s');
$json = json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
if ($dry) {
    fprintf(STDERR, "（--dry：不寫檔）DirectPairs %d 筆、LineTransfers %d 筆\n", count($rules['DirectPairs']), count($rules['LineTransfers']));
} else {
    file_put_contents($file, $json . "\n");
    fprintf(STDERR, "已寫入 %s：DirectPairs %d 筆、LineTransfers %d 筆（共 %.1f 秒）\n", $file, count($rules['DirectPairs']), count($rules['LineTransfers']), microtime(true) - $t0);
}

<?php
/**
 * 中長途「生活圈＋幹線」分解（使用者 2026-11 設計，transfer/data/transfer_regions.json 的 Trunk）。
 *
 * 起訖站的生活圈不同而且不相鄰（appRegionRule 套用）時，不再從起點一路搜到終點，改成三段組合：
 *   1. 起點端：起點生活圈（與相鄰生活圈）的幹線站＝高鐵站、臺鐵對號大站（TraHubs），起站本身是臺鐵站或高鐵站也算。
 *      對每個幹線站做一般的短程找路（appCoreRoutes，不搭高鐵；各排除條件共用同一次找路，不搭對號時濾掉對號段），
 *      結果與單純查「起站→這個幹線站」一致。
 *   2. 終點端：對稱（幹線站→終站）。
 *   3. 幹線：起點端幹線站到終點端幹線站，只搭臺鐵、高鐵（appNetworkRoutes 只用鐵路系統）；東部往來西部可以在 Gateways 轉乘。
 * 起點端、終點端各依「到幹線站＋幹線＋另一端」的估計挑出前幾名幹線站（每個系統各 Keep 個），再與幹線組合。
 * 組合出的基本路由交回 appCoreRoutes 照一般流程估計、補充資料（只改寫幹線段）、排序。
 * 這樣小站、捷運站出發的中長途，前段與單純查往臺鐵大站、高鐵站的路由一致；要調整時可以分開看生活圈端還是幹線端。
 */

const APP_TRUNK_DEFAULTS = [
    'Enabled'          => true,
    'Candidates'       => ['TRA' => 4, 'THSR' => 3],   // 每端先依距離取最近的幾個幹線站做短程找路（起站本身另外算）
    'Keep'             => ['TRA' => 4, 'THSR' => 3],   // 每端依估計留下幾個幹線站（起站本身另外算）
    'AccessPerStation' => 2,                           // 每個幹線站留幾條到達路由（依估計＋轉乘分排序，在最好的 AccessSlack 分內）
    'AccessSlack'      => 10,
    'TrunkPerPair'     => 2,                           // 每一對幹線站留幾條幹線路由
    'MaxCombos'        => 48,                          // 組合後最多交出幾條基本路由（依估計，臺鐵幹線、高鐵幹線各至少一半）
    'TransferMinutes'  => 10,                          // 估計排序時每次轉乘加幾分
];

/** 分解設定（transfer_regions.json 的 Trunk；沒寫的用預設） */
function appTrunkConfig()
{
    static $c = null;
    if ($c === null) {
        $j = json_decode((string) @file_get_contents(TRANSFER_DATA_DIR . '/transfer_regions.json'), true);
        $c = (is_array($j) && is_array($j['Trunk'] ?? null) ? $j['Trunk'] : []) + APP_TRUNK_DEFAULTS;
    }
    return $c;
}

/** 最近一次分解的資訊（依 extra），routes、journeys 的 meta 用 */
function appTrunkMeta($set = null, $extra = null)
{
    static $meta = [];
    if ($set !== null) {
        $meta[$extra] = $set;
    }
    return $meta;
}

/** 車站的系統（StationUID 前綴） */
function appTrunkSystemOf($u)
{
    return strtok($u, '-');
}

/** 兩站直線距離（公里）；沒有座標回 null */
function appTrunkKm($a, $b)
{
    $pos = appStationPositions();
    if (!isset($pos[$a], $pos[$b])) {
        return null;
    }
    $la1 = deg2rad($pos[$a]['Lat']);
    $la2 = deg2rad($pos[$b]['Lat']);
    $dl = deg2rad($pos[$b]['Lon'] - $pos[$a]['Lon']);
    $x = sin(($la2 - $la1) / 2) ** 2 + cos($la1) * cos($la2) * sin($dl / 2) ** 2;
    return 6371 * 2 * asin(min(1, sqrt($x)));
}

/**
 * 一端的幹線站候選：生活圈 $regions 內的高鐵站（extra 沒排除高鐵時）與臺鐵大站，依與 $u 的距離每個系統取前 Candidates 個；
 * $u 本身是臺鐵站或高鐵站時也算（直接從起站上幹線）。回傳 [StationUID => 系統]
 */
function appTrunkCandidates(array $m, $u, array $regions, $extra)
{
    $cfg = appTrunkConfig();
    $R = appRegions();
    $by = ['TRA' => [], 'THSR' => []];
    foreach ($R['of'] as $x => $rg) {
        if (!isset($regions[$rg]) || $x === $u) {
            continue;
        }
        $sys = appTrunkSystemOf($x);
        if ($sys === 'THSR' && appSystemAllowed($extra, 'THSR')) {
            $by['THSR'][$x] = appTrunkKm($u, $x) ?? INF;
        } elseif ($sys === 'TRA' && isset($R['hubs'][$x])) {
            $by['TRA'][$x] = appTrunkKm($u, $x) ?? INF;
        }
    }
    $out = [];
    $self = appTrunkSystemOf($u);
    if ($self === 'TRA' || ($self === 'THSR' && appSystemAllowed($extra, 'THSR'))) {
        $out[$u] = $self;
    }
    // 起站本身是大站（臺鐵）或高鐵站：這個系統只從起站上，不另外找同系統的幹線站（臺北→高雄不必先到松山、板橋）
    if (($self === 'TRA' && isset($R['hubs'][$u])) || $self === 'THSR') {
        $by[$self] = [];
    }
    foreach ($by as $sys => $list) {
        asort($list);
        foreach (array_slice(array_keys($list), 0, $cfg['Candidates'][$sys] ?? 0) as $x) {
            $out[$x] = $sys;
        }
    }
    return $out;
}

/**
 * 生活圈端的路由：$u（起站或終站）與幹線站 $x 之間的一般短程找路（不搭高鐵），$toTrunk＝true 是 $u→$x，false 是 $x→$u。
 * 依「估計分鐘＋每次轉乘 TransferMinutes」排序，回傳在最好的 AccessSlack 分內的前 AccessPerStation 條 [['legs', 'est', 'tc', 'score'], ...]；
 * $u＝$x 時回一條空路由
 */
function appTrunkAccess(array $m, $u, $x, $toTrunk, array $opt)
{
    static $raw = [];
    static $cache = [];
    $cfg = appTrunkConfig();
    $extra = $opt['extra'] ?? 0;
    $k = $u . '|' . $x . '|' . (int) $toTrunk . '|' . $extra;
    if (isset($cache[$k])) {
        return $cache[$k];
    }
    if ($u === $x) {
        return $cache[$k] = [['legs' => [], 'est' => 0.0, 'tc' => -1, 'score' => 0.0]];
    }
    // 起訖站與幹線站在同一個轉乘群組（台北車站→臺鐵臺北）：直接步行。一般找路至少要搭一段車，找不到只步行的走法
    $same = false;
    foreach ($m['groupOf'][$u] ?? [] as $gid) {
        foreach ($m['groups'][$gid]['Stations'] as $y) {
            $same = $same || $y['StationUID'] === $x;
        }
    }
    $w = $same ? ($toTrunk ? transferWalk($m, $u, $x, true) : transferWalk($m, $x, $u, true)) : null;
    if ($w !== null) {
        $legs = [['Type' => 'walk', 'From' => $toTrunk ? $u : $x, 'To' => $toTrunk ? $x : $u, 'Minutes' => null, 'MinutesGiven' => false]];
        return $cache[$k] = [['legs' => $legs, 'est' => (float) $w['Minutes'], 'tc' => -1, 'score' => (float) $w['Minutes']]];
    }
    // 短程找路一律用「不搭高鐵」找一次，各排除條件共用：基本路由的臺鐵段不分車種（串接時才依 extra 選班次），
    // 不搭臺鐵對號時只濾掉補充路由切出的對號段
    $rk = $u . '|' . $x . '|' . (int) $toTrunk . '|' . ($opt['deeper'] ?? '');
    if (!isset($raw[$rk])) {
        $sub = ['extra' => APP_EXTRA_NO_HSR, 'deeper' => $opt['deeper'] ?? null, 'limit' => 20, 'noTrunk' => true];
        $raw[$rk] = $toTrunk ? appCoreRoutes($m, $u, $x, $sub) : appCoreRoutes($m, $x, $u, $sub);
    }
    $routes = array_values(array_filter($raw[$rk], function ($r) use ($extra) {
        foreach ($r['legs'] as $l) {
            if ($l['Type'] === 'ride' && ($l['Class'] ?? null) !== null && !appClassAllowed($extra, $l['System'], $l['Class'])) {
                return false;
            }
        }
        return true;
    }));
    // 依「估計分鐘＋每次轉乘 TransferMinutes」排序，不先篩最少轉乘（多轉一次但比較快到幹線站的走法也要比得到）
    $list = [];
    foreach ($routes as $r) {
        $list[] = ['legs' => $r['legs'], 'est' => (float) $r['EstimatedMinutes'], 'tc' => $r['TransferCount'],
                   'score' => (float) $r['EstimatedMinutes'] + $cfg['TransferMinutes'] * $r['TransferCount']];
    }
    usort($list, function ($a, $b) { return $a['score'] <=> $b['score']; });
    $out = [];
    foreach ($list as $i => $r) {
        if (count($out) < $cfg['AccessPerStation'] && $r['score'] <= $list[0]['score'] + $cfg['AccessSlack']) {
            $out[$i] = $r;
        }
    }
    // 不同交通系統組合各留最好一條（使用者規則 2026-11），不受 AccessSlack 限制：估計分鐘沒有算等車，班次少的臺鐵會比班次密的捷運
    // 估得快（高鐵左營→高雄：臺鐵 14 分、紅線 26 分，實際常是紅線先到）。只限轉乘次數與這站最少轉乘相同的，轉乘多的繞路不額外留
    $minTc = $list ? min(array_column($list, 'tc')) : null;
    $combo = [];
    foreach ($out as $r) {
        $combo[appTrunkSystemCombo($r['legs'])] = true;
    }
    foreach ($list as $i => $r) {
        $key = appTrunkSystemCombo($r['legs']);
        if (!isset($combo[$key]) && $r['tc'] === $minTc) {
            $combo[$key] = true;
            $out[$i] = $r;
        }
    }
    ksort($out);
    return $cache[$k] = array_values($out);
}

/** 生活圈端路由搭乘的交通系統組合（依序、連續相同的合併），例如 TRA、KRTC、TRTC>TRA */
function appTrunkSystemCombo(array $legs)
{
    $seq = [];
    foreach ($legs as $l) {
        if ($l['Type'] === 'ride' && end($seq) !== $l['System']) {
            $seq[] = $l['System'];
        }
    }
    return implode('>', $seq);
}

/** 幹線估計（篩選、排序用）：同系統直達的最短分鐘；東部往來西部跨系統時，經 Gateways 生活圈的同站轉乘估計；做不到回 null */
function appTrunkQuick(array $m, $s, $t, $extra, $eastWest)
{
    static $cache = [];
    $k = $s . '|' . $t . '|' . $extra . '|' . (int) $eastWest;
    if (array_key_exists($k, $cache)) {
        return $cache[$k];
    }
    $cls = ($extra & APP_EXTRA_NO_EXPRESS) ? APP_CLASS_LOCAL : null;
    $ss = appTrunkSystemOf($s);
    $ts = appTrunkSystemOf($t);
    // 不搭對號時區間車多半沒有長途直達（要接力）：用不分車種的分鐘 × 1.6 粗估，只用於篩選排序
    $direct = function ($sys, $a, $b) use ($cls) {
        $v = appLegMinutes($sys, $a, $b, $sys === 'TRA' ? $cls : null);
        if ($v === null && $sys === 'TRA' && $cls !== null && ($any = appLegMinutes('TRA', $a, $b, null)) !== null) {
            $v = $any * 1.6;
        }
        return $v;
    };
    $v = $ss === $ts ? $direct($ss, $s, $t) : null;
    if ($eastWest) {
        // 經轉乘生活圈（北北基、高雄）的車站換車：同系統兩段，或換系統（臺鐵↔高鐵，同轉乘群組）
        $R = appRegions();
        foreach ($R['of'] as $g => $rg) {
            if (!in_array($rg, $R['gate'], true) || !in_array(appTrunkSystemOf($g), ['TRA', 'THSR'], true)) {
                continue;
            }
            if (appTrunkSystemOf($g) !== $ss || ($a = $direct($ss, $s, $g)) === null) {
                continue;
            }
            $partners = [$g => true];
            foreach ($m['groupOf'][$g] ?? [] as $gid) {
                foreach ($m['groups'][$gid]['Stations'] as $y) {
                    $partners[$y['StationUID']] = true;
                }
            }
            foreach (array_keys($partners) as $y) {
                if (appTrunkSystemOf($y) === $ts && $y !== $t && ($b = $direct($ts, $y, $t)) !== null) {
                    $e = $a + $b + appTrunkConfig()['TransferMinutes'];
                    $v = $v === null ? $e : min($v, $e);
                }
            }
        }
    }
    return $cache[$k] = $v;
}

/**
 * 幹線路由：$s→$t 只搭臺鐵、高鐵（appNetworkRoutes 限鐵路系統）；第一段從 $s 上車、最後一段在 $t 下車，系統與兩站相同。
 * 同系統只取直達一段（臺鐵是系統直達段，沒有直達車時由補充資料在交會站換車）；東部往來西部可以多一次轉乘。依估計取前 TrunkPerPair 條
 */
function appTrunkLegs(array $m, $s, $t, $extra, $eastWest)
{
    static $cache = [];
    $k = $s . '|' . $t . '|' . $extra . '|' . (int) $eastWest;
    if (isset($cache[$k])) {
        return $cache[$k];
    }
    $ss = appTrunkSystemOf($s);
    $ts = appTrunkSystemOf($t);
    $systems = $ss === $ts && !$eastWest ? [$ss] : ['TRA', 'THSR'];
    $maxRides = $ss === $ts ? 1 : 2;
    $list = [];
    $seen = [];
    foreach (appNetworkRoutes($m, $s, $t, $extra, $ss === $ts ? 0 : 1, $systems) as $legs) {
        $rides = array_values(array_filter($legs, function ($l) { return $l['Type'] === 'ride'; }));
        if (!$rides || $legs[0]['Type'] !== 'ride' || end($legs)['Type'] !== 'ride' || count($rides) > $maxRides
            || $rides[0]['System'] !== $ss || end($rides)['System'] !== $ts) {
            continue;
        }
        $code = appRouteFormat(['Legs' => $legs]);
        if (isset($seen[$code])) {
            continue;
        }
        $seen[$code] = true;
        foreach ($legs as &$l) {
            $l['_part'] = 'trunk';
        }
        unset($l);
        $tmp = $legs;
        $est = appRouteEstimate($m, $tmp);
        $list[] = ['legs' => $legs, 'est' => $est ?? INF];
    }
    // 臺鐵跨幹線（兩端的 TraTrunks 沒有共同的，例如花蓮在東部幹線、臺中在西部幹線）：多一個在交會站（TraJunctions）換車的版本
    if ($ss === 'TRA' && $ts === 'TRA') {
        foreach (appTrunkJunctions($m, $s, $t) as $j) {
            $a = appTrunkLegs($m, $s, $j, $extra, false);
            $b = appTrunkLegs($m, $j, $t, $extra, false);
            if (!$a || !$b) {
                continue;
            }
            $legs = array_merge($a[0]['legs'], $b[0]['legs']);
            $code = appRouteFormat(['Legs' => $legs]);
            if (!isset($seen[$code])) {
                $seen[$code] = true;
                $list[] = ['legs' => $legs, 'est' => $a[0]['est'] + $b[0]['est'] + appTrunkConfig()['TransferMinutes']];
            }
        }
    }
    usort($list, function ($a, $b) { return $a['est'] <=> $b['est']; });
    return $cache[$k] = array_slice($list, 0, appTrunkConfig()['TrunkPerPair']);
}

/** 臺鐵兩站跨幹線時要換車的交會站（TraJunctions）：兩站所屬的幹線沒有共同的，每一種幹線組合對應一個交會站；不跨線、或交會站就是起訖站時不列 */
function appTrunkJunctions(array $m, $s, $t)
{
    $R = appRegions();
    $of = appTraTrunkOf($m);
    $a = $of[$s] ?? [];
    $b = $of[$t] ?? [];
    if (!$a || !$b || array_intersect_key($a, $b)) {
        return [];
    }
    $out = [];
    foreach (array_keys($a) as $x) {
        foreach (array_keys($b) as $y) {
            $k = [$x, $y];
            sort($k);
            $j = $R['junctions'][implode('', $k)] ?? null;
            if ($j !== null && $j !== $s && $j !== $t) {
                $out[$j] = $j;
            }
        }
    }
    return array_values($out);
}

/**
 * 組合後的路由是否符合高鐵站經過規則（與 appPrefixExtend 相同的判斷：高鐵下車後不再經過另一個生活圈的高鐵站、
 * 上車前不先經過另一個生活圈的高鐵站不搭、高鐵經過起點／終點生活圈有接其他鐵路的高鐵站時從那一站上下車）
 */
function appTrunkHsrOk(array $m, $from, $to, array $legs)
{
    $hsr = appHsrStationOf($m);
    $off = null;
    $pre = [];
    foreach ($legs as $l) {
        if ($l['Type'] !== 'ride') {
            continue;
        }
        foreach ($l['Path'] ?? [$l] as $p) {
            $st = appRideStations($m, $p);
            if ($st === null) {
                continue;
            }
            if ($l['System'] === 'THSR') {
                $on = $hsr[$p['From']] ?? $p['From'];
                $o = $hsr[$p['To']] ?? $p['To'];
                if (!appHsrBoardOk($pre, $on, $o, $st, appRegionOf($from), appRegionOf($to))) {
                    return false;
                }
                $off = $o;
            } elseif ($off !== null) {
                if (!appHsrRideOk($hsr, $off, $st)) {
                    return false;
                }
            } else {
                $pre = appHsrPassed($hsr, $pre, $st);
            }
        }
    }
    return true;
}

/**
 * 中長途分解的基本路由：不套用（同一或相鄰生活圈、表裡沒有、設定關掉）回 null；
 * 套用時回 ['routes' => [段落陣列, ...], 'meta' => 分解資訊]
 */
function appTrunkBaseRoutes(array $m, $from, $to, array $opt)
{
    $cfg = appTrunkConfig();
    $rule = appRegionRule($from, $to);
    if (empty($cfg['Enabled']) || $rule === null) {
        return null;
    }
    $t0 = microtime(true);
    $extra = $opt['extra'] ?? 0;
    $R = appRegions();
    $ra = appRegionOf($from);
    $rb = appRegionOf($to);
    $eastWest = isset($R['east'][$ra]) !== isset($R['east'][$rb]);
    $sideA = [$ra => true] + ($R['adj'][$ra] ?? []);
    $sideB = [$rb => true] + ($R['adj'][$rb] ?? []);
    $tc = $cfg['TransferMinutes'];

    // 1、2. 兩端的幹線站與生活圈端路由
    $ends = [];
    foreach (['A' => [$from, $sideA, true], 'B' => [$to, $sideB, false]] as $side => list($u, $regions, $toTrunk)) {
        $ends[$side] = [];
        foreach (appTrunkCandidates($m, $u, $regions, $extra) as $x => $sys) {
            $acc = appTrunkAccess($m, $u, $x, $toTrunk, $opt);
            if ($acc) {
                $ends[$side][$x] = ['sys' => $sys, 'acc' => $acc, 'best' => $acc[0]['est'] + ($acc[0]['tc'] >= 0 ? $tc * ($acc[0]['tc'] + 1) : 0)];
            }
        }
    }
    $pairOk = function ($s, $t) use ($eastWest) {
        return $s !== $t && (appTrunkSystemOf($s) === appTrunkSystemOf($t) || $eastWest);
    };
    // 3. 每端依「生活圈端＋幹線＋另一端最好」挑前 Keep 個（每個系統；起站／終站本身另外留）
    $keep = [];
    foreach (['A' => 'B', 'B' => 'A'] as $side => $other) {
        $score = [];
        foreach ($ends[$side] as $x => $e) {
            $bestPair = null;
            foreach ($ends[$other] as $y => $f) {
                list($s, $t) = $side === 'A' ? [$x, $y] : [$y, $x];
                if (!$pairOk($s, $t) || ($q = appTrunkQuick($m, $s, $t, $extra, $eastWest)) === null) {
                    continue;
                }
                $v = $q + $f['best'];
                $bestPair = $bestPair === null ? $v : min($bestPair, $v);
            }
            if ($bestPair !== null) {
                $score[$x] = $e['best'] + $bestPair;
            }
        }
        $self = $side === 'A' ? $from : $to;
        // 起站（終站）本身是臺鐵站時一定保留：沒有直達車（五堵往南）也要能從起站直接全程區間車接力，不必先到大站
        if (isset($ends[$side][$self]) && $ends[$side][$self]['sys'] === 'TRA' && !isset($score[$self])) {
            $score[$self] = INF;
        }
        asort($score);
        $count = [];
        $keep[$side] = [];
        foreach ($score as $x => $v) {
            $sys = $ends[$side][$x]['sys'];
            if ($x === $self || ($count[$sys] = ($count[$sys] ?? 0) + 1) <= ($cfg['Keep'][$sys] ?? 0)) {
                $keep[$side][$x] = $v;
            }
        }
    }
    // 4. 組合：生活圈端路由 × 幹線 × 生活圈端路由
    $combos = [];
    foreach ($keep['A'] as $s => $_) {
        foreach ($keep['B'] as $t => $__) {
            if (!$pairOk($s, $t)) {
                continue;
            }
            foreach (appTrunkLegs($m, $s, $t, $extra, $eastWest) as $tr) {
                foreach ($ends['A'][$s]['acc'] as $a) {
                    foreach ($ends['B'][$t]['acc'] as $b) {
                        $legs = array_merge(array_map(function ($l) { return $l + ['_part' => 'access']; }, $a['legs']), $tr['legs'],
                                            array_map(function ($l) { return $l + ['_part' => 'egress']; }, $b['legs']));
                        if (!appTrunkHsrOk($m, $from, $to, $legs)) {
                            continue;
                        }
                        // 折返：生活圈端搭臺鐵經過的大站，幹線又經過（東湖→南港→臺鐵往回到七堵再搭對號經過南港）
                        if (appTrunkBackHub($m, $a['legs'], $tr['legs'], $s) || appTrunkBackHub($m, $b['legs'], $tr['legs'], $t)) {
                            continue;
                        }
                        $rides = count(array_filter($legs, function ($l) { return $l['Type'] === 'ride' && empty($l['Through']); }));
                        $combos[] = ['legs' => $legs, 'score' => $a['est'] + $tr['est'] + $b['est'] + $tc * max(0, $rides - 1),
                                     'sys' => $tr['legs'][0]['System']];
                    }
                }
            }
        }
    }
    usort($combos, function ($a, $b) { return $a['score'] <=> $b['score']; });
    // 依估計取前 MaxCombos 條；臺鐵幹線、高鐵幹線各至少保留一半（高鐵很快時臺鐵幹線不會全被擠掉）
    $half = intdiv($cfg['MaxCombos'], 2);
    $pickA = [];
    $bySys = [];
    foreach ($combos as $i => $c) {
        if (($bySys[$c['sys']] = ($bySys[$c['sys']] ?? 0) + 1) <= $half) {
            $pickA[$i] = true;
        }
    }
    foreach ($combos as $i => $c) {
        if (count($pickA) >= $cfg['MaxCombos']) {
            break;
        }
        $pickA[$i] = true;
    }
    ksort($pickA);
    $routes = [];
    foreach (array_keys($pickA) as $i) {
        $routes[] = $combos[$i]['legs'];
    }
    // 全程區間車：不論起訖站，都不必先到大站——把幹線前後相連的臺鐵段合成一段（五堵→松山＋松山→高雄＝五堵→高雄、
    // 臺北→豐原＋豐原→松竹＝臺北→松竹），只用這段產生區間車接力（_relayOnly）；沒有可合併的組合本身就能接力
    $codes = [];
    foreach ($routes as $legs) {
        $codes[appRouteFormat(['Legs' => $legs])] = true;
    }
    $relay = 0;
    foreach ($routes as $legs) {
        $merged = appTrunkMergeTra($legs);
        if ($merged === null) {
            continue;
        }
        $code = appRouteFormat(['Legs' => $merged]);
        if (!isset($codes[$code])) {
            $codes[$code] = true;
            $routes[] = $merged;
            $relay++;
        }
    }
    $meta = [
        'From' => array_keys($keep['A']), 'To' => array_keys($keep['B']),
        'Candidates' => ['From' => count($ends['A']), 'To' => count($ends['B'])],
        'Combos' => count($combos), 'Routes' => count($routes), 'RelayRoutes' => $relay, 'EastWest' => $eastWest,
        'seconds' => round(microtime(true) - $t0, 3),
    ];
    appTrunkMeta($meta, $extra);
    return ['routes' => $routes, 'meta' => $meta];
}

/**
 * 折返經過大站：生活圈端的臺鐵段經過的大站（不含與幹線相接的 $x），幹線的臺鐵段又經過一次。
 * 例如東湖→南港→臺鐵往回到七堵再搭對號經過南港、內壢往北到板橋再搭對號經過桃園；五堵→七堵、汐科→南港往反方向一站上車不經過大站，不受影響
 */
function appTrunkBackHub(array $m, array $side, array $trunk, $x)
{
    $hubs = appTraHubs();
    $stations = function ($legs) use ($m) {
        $out = [];
        foreach ($legs as $l) {
            if ($l['Type'] === 'ride' && $l['System'] === 'TRA') {
                foreach ($l['Path'] ?? [$l] as $p) {
                    foreach (appRideStations($m, $p) ?? [] as $u) {
                        $out[$u] = true;
                    }
                }
            }
        }
        return $out;
    };
    $a = $stations($side);
    unset($a[$x]);
    foreach ($stations($trunk) as $u => $_) {
        if ($u !== $x && isset($a[$u], $hubs[$u])) {
            return true;
        }
    }
    return false;
}

/**
 * 全程區間車用：幹線臺鐵段（只有一段）前後直接相連的臺鐵段（生活圈端）合成一段系統直達段，標 _relayOnly（只套用區間車接力）。
 * 前後都沒有相連的臺鐵段時回 null（那個組合本身就能接力）
 */
function appTrunkMergeTra(array $legs)
{
    $ti = null;
    foreach ($legs as $i => $l) {
        if (($l['_part'] ?? null) === 'trunk') {
            if ($ti !== null || $l['Type'] !== 'ride' || $l['System'] !== 'TRA') {
                return null;   // 幹線不是單一一段臺鐵（高鐵、東部往來西部換車）
            }
            $ti = $i;
        }
    }
    if ($ti === null) {
        return null;
    }
    $a = $ti;
    while ($a > 0 && $legs[$a - 1]['Type'] === 'ride' && $legs[$a - 1]['System'] === 'TRA' && $legs[$a - 1]['To'] === $legs[$a]['From']) {
        $a--;
    }
    $b = $ti;
    while ($b + 1 < count($legs) && $legs[$b + 1]['Type'] === 'ride' && $legs[$b + 1]['System'] === 'TRA' && $legs[$b + 1]['From'] === $legs[$b]['To']) {
        $b++;
    }
    if ($a === $ti && $b === $ti) {
        return null;
    }
    $path = [];
    for ($i = $a; $i <= $b; $i++) {
        foreach ($legs[$i]['Path'] ?? [] as $p) {
            $path[] = $p;
        }
    }
    $one = ['Type' => 'ride', 'From' => $legs[$a]['From'], 'To' => $legs[$b]['To'], 'LineUID' => 'TRA', 'Class' => null,
            'System' => 'TRA', 'Through' => false, 'Path' => $path, '_part' => 'trunk', '_relayOnly' => true];
    return array_merge(array_slice($legs, 0, $a), [$one], array_slice($legs, $b + 1));
}

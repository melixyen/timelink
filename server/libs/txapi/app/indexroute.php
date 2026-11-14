<?php
/**
 * 綜合指數找路（使用者規則 2026-11）：生活圈端與短程查詢共用。
 *
 * 不再用 Deeper（轉乘次數比最少的多幾次）截斷列舉範圍：轉乘次數多、實際上比較快的走法（例如捷運內平行轉乘、班距短的轉乘）
 * 不會被先丟掉。改用綜合指數由小到大取前 K 條（Search.IndexK，預設 20）不繞圈的路徑，交給後面的補充資料、估計、串時刻表。
 *
 * 綜合指數（分鐘）＝ 各段搭乘（站間行駛分鐘加總）＋ 經過的中間站數 × 每站停靠（Search.IndexStopMinutes，預設 1 分）
 *                  ＋ 每次轉乘的步行分鐘＋要轉搭的那一站全天平均班距的一半（appHeadways；沒有時刻資料用 Search.IndexDefaultHeadway）。
 * 同一鐵路系統（臺鐵、高鐵）在路線交會站接續（西部幹線接海線）是同一段系統直達段，不算轉乘、不加候車；起站第一次上車也不加候車。
 *
 * 搜尋：車站層級的路網，A*（啟發值＝到迄站的最短行駛＋步行下界，反向 Dijkstra），每次從佇列取指數最小的部分路徑往下展開
 * （搭某條路線到該路線上的任一個「決策站」：轉乘站、支線分岔站、迄站與它同群組的車站），先到迄站的就是指數最小的路徑。
 * 排除規則與原本相同（appPrefixExtend：重複經過車站、排除名單、經由排除、生活圈轉乘規則、高鐵站經過規則；appRoutePatternHit）。
 * 回傳格式與 appNetworkRoutes 相同（同一鐵路系統的連續搭乘合成系統直達段）。
 */

/** 綜合指數找路的參數（transfer_route_rules.json 的 Search；沒寫用預設） */
function appIndexConfig()
{
    $s = appRouteSearch();
    return [
        'Enabled'        => (bool) ($s['IndexRoutes'] ?? true),
        'K'              => (int) ($s['IndexK'] ?? 20),
        'StopMinutes'    => (float) ($s['IndexStopMinutes'] ?? 1),
        'DefaultHeadway' => (float) ($s['IndexDefaultHeadway'] ?? 10),
        'MaxPops'        => (int) ($s['IndexMaxPops'] ?? 30000),
        'PerEnd'         => (int) ($s['IndexPerEnd'] ?? 3),
        'OneTransfer'    => (bool) ($s['IndexOneTransfer'] ?? true),
        'MinTransfer'    => (int) ($s['IndexMinTransfer'] ?? 3),
        'CutoffRatio'    => (float) ($s['IndexCutoffRatio'] ?? 2),
        'CutoffPlus'     => (float) ($s['IndexCutoffPlus'] ?? 30),
        'MaxLines'       => (int) ($s['MaxLines'] ?? 6),
        'MaxLineUses'    => (int) ($s['MaxLineUses'] ?? 2),
    ];
}

/**
 * 各車站全天平均班距（分鐘，StationUID => 分）：平日（星期一）每個方向「末班−首班」÷（班數−1），各方向平均。
 * 捷運、輕軌用車站時刻表（metro_group、metro_dep），臺鐵、高鐵用列車停靠（train_stop）。
 * 快取在 TDX_CACHE_DIR/app_headways.ser（離線資料重建後自動重算）
 */
function appHeadways()
{
    static $hw = null;
    if ($hw !== null) {
        return $hw;
    }
    $file = TDX_CACHE_DIR . '/app_headways.ser';
    $sig = appDataUpdatedAt() . '|1';
    if (is_file($file)) {
        $c = @unserialize((string) file_get_contents($file));
        if (is_array($c) && ($c['sig'] ?? '') === $sig) {
            return $hw = $c['hw'];
        }
    }
    $mask = 1 << 1;   // 星期一
    $dirs = [];
    foreach (appDb()->all('SELECT g.station_uid, g.direction, COUNT(*) n, MIN(d.sort_min) a, MAX(d.sort_min) b
                           FROM metro_group g JOIN metro_dep d ON d.group_id = g.group_id
                           WHERE (g.weekmask & ?) != 0 GROUP BY g.group_id', [$mask]) as $r) {
        if ((int) $r['n'] >= 2) {
            $dirs[$r['station_uid']][$r['direction']][] = ((int) $r['b'] - (int) $r['a']) / ((int) $r['n'] - 1);
        }
    }
    foreach (appDb()->all('SELECT s.station_uid, t.direction, COUNT(*) n, MIN(s.sort_min) a, MAX(s.sort_min) b
                           FROM train_stop s JOIN train t ON t.train_key = s.train_key
                           WHERE (t.weekmask & ?) != 0 AND s.sort_min IS NOT NULL GROUP BY s.station_uid, t.direction', [$mask]) as $r) {
        if ((int) $r['n'] >= 2) {
            $dirs[$r['station_uid']][$r['direction']][] = ((int) $r['b'] - (int) $r['a']) / ((int) $r['n'] - 1);
        }
    }
    $hw = [];
    foreach ($dirs as $u => $byDir) {
        $vals = [];
        foreach ($byDir as $list) {
            $vals[] = min($list);   // 同一方向有好幾個時刻表群組（不同目的地）時取最密的
        }
        $hw[$u] = round(array_sum($vals) / count($vals), 1);
    }
    if (!is_dir(TDX_CACHE_DIR)) {
        @mkdir(TDX_CACHE_DIR, 0755, true);
    }
    @file_put_contents($file . '.tmp', serialize(['sig' => $sig, 'hw' => $hw]), LOCK_EX);
    @rename($file . '.tmp', $file);
    return $hw;
}

/**
 * 在車站 $u 上車的平均候車：臺鐵用該生活圈的設定（transfer_regions.json 的 TraWaitMinutes：生活圈大站全日平均候車，手寫），
 * 其他系統（與沒有設定的生活圈）用該站全日平均班距的一半
 */
function appIndexWait($u)
{
    static $tra = null;
    if ($tra === null) {
        $j = json_decode((string) @file_get_contents(TRANSFER_DATA_DIR . '/transfer_regions.json'), true);
        $tra = is_array($j['TraWaitMinutes'] ?? null) ? $j['TraWaitMinutes'] : [];
    }
    if (strpos($u, 'TRA-') === 0 && isset($tra[appRegionOf($u) ?? ''])) {
        return (float) $tra[appRegionOf($u)];
    }
    $hw = appHeadways();
    return ($hw[$u] ?? appIndexConfig()['DefaultHeadway']) / 2;
}

/** 同一路線相鄰兩站的行駛分鐘（有快取；資料沒有時反方向，再沒有當 3 分） */
function appIndexSegMinutes($line, $a, $b)
{
    static $cache = [];
    $k = $line . '|' . $a . '|' . $b;
    if (!isset($cache[$k])) {
        $v = appLegMinutes($line, $a, $b) ?? appLegMinutes($line, $b, $a);
        $cache[$k] = $v === null ? 3.0 : (float) $v;
    }
    return $cache[$k];
}

/** 搭乘段的指數：沿途相鄰兩站行駛分鐘加總＋中間站數 × 每站停靠；不能直接搭（跨支線）回 null。有快取 */
function appIndexRide(array $m, $line, $a, $b)
{
    static $cache = [];
    $k = $line . '|' . $a . '|' . $b;
    if (array_key_exists($k, $cache)) {
        return $cache[$k];
    }
    $st = appRideStations($m, ['LineUID' => $line, 'From' => $a, 'To' => $b]);
    if ($st === null) {
        return $cache[$k] = null;
    }
    $t = 0.0;
    for ($i = 0, $n = count($st) - 1; $i < $n; $i++) {
        $t += appIndexSegMinutes($line, $st[$i], $st[$i + 1]);
    }
    return $cache[$k] = $t + max(0, count($st) - 2) * appIndexConfig()['StopMinutes'];
}

/** 路由（段落陣列，系統直達段用 Path）的綜合指數；算不出回 null */
function appRouteIndex(array $m, array $legs)
{
    $cfg = appIndexConfig();
    $total = 0.0;
    $prevRide = false;
    foreach ($legs as $l) {
        if ($l['Type'] === 'walk') {
            $w = transferWalk($m, $l['From'], $l['To'], true, true);
            if ($w === null) {
                return null;
            }
            $total += (float) $w['Minutes'];
            continue;
        }
        // 候車：轉乘上車；臺鐵第一次上車也算（班次少、要照時刻表趕車，終點端從幹線站出來的第一次上車其實是轉乘）
        if (($prevRide && empty($l['Through'])) || (!$prevRide && ($l['System'] ?? '') === 'TRA')) {
            $total += appIndexWait($l['From']);
        }
        $pieces = $l['Path'] ?? [['LineUID' => $l['LineUID'], 'From' => $l['From'], 'To' => $l['To']]];
        foreach ($pieces as $i => $p) {
            $r = appIndexRide($m, $p['LineUID'], $p['From'], $p['To']);
            if ($r === null) {
                return null;
            }
            $total += $r + ($i > 0 ? $cfg['StopMinutes'] : 0);   // 交會站本身也是途中停靠的一站
        }
        $prevRide = true;
    }
    return round($total, 1);
}

/** 決策站（搭乘可以在這裡下車的車站）：有兩條以上路線、轉乘群組裡有其他車站、支線分岔站。有快取 */
function appIndexDecisionStations(array $m)
{
    static $set = null;
    if ($set !== null) {
        return $set;
    }
    $set = [];
    foreach ($m['stationLines'] as $u => $lines) {
        if (count($lines) >= 2) {
            $set[$u] = true;
            continue;
        }
        foreach ($m['groupOf'][$u] ?? [] as $gid) {
            foreach ($m['groups'][$gid]['Stations'] as $x) {
                if ($x['StationUID'] !== $u && !empty($m['stationLines'][$x['StationUID']])) {
                    $set[$u] = true;
                }
            }
        }
    }
    foreach (array_keys($m['lineStations']) as $l) {
        foreach (appLineJunctions($m, $l) as $u) {
            $set[$u] = true;
        }
    }
    return $set;
}

/** 每條路線上的決策站（依路線站序；有快取） */
function appIndexLineDecisions(array $m, $line)
{
    static $cache = [];
    if (!isset($cache[$line])) {
        $dec = appIndexDecisionStations($m);
        $out = [];
        foreach (appLinePaths($m, $line) as $seq) {
            foreach ($seq as $u) {
                if (isset($dec[$u])) {
                    $out[$u] = true;
                }
            }
        }
        foreach ($m['lineStations'][$line] ?? [] as $u) {
            if (isset($dec[$u])) {
                $out[$u] = true;
            }
        }
        $cache[$line] = array_keys($out);
    }
    return $cache[$line];
}

/** 啟發值：各站到迄站（含同群組步行到迄站）的最短「行駛＋步行」下界（反向 Dijkstra，不含停靠與候車） */
function appIndexHeuristic(array $m, array $goals, callable $lineOk)
{
    static $edges = null;
    if ($edges === null) {
        // 反向圖：v ← u（u 到 v 的成本）；搭乘段相鄰兩站兩個方向都有
        $edges = [];
        foreach (array_keys($m['lineStations']) as $l) {
            foreach (appLinePaths($m, $l) as $seq) {
                for ($i = 0, $n = count($seq) - 1; $i < $n; $i++) {
                    $a = $seq[$i];
                    $b = $seq[$i + 1];
                    $edges[$b][] = [$a, appIndexSegMinutes($l, $a, $b), $l];
                    $edges[$a][] = [$b, appIndexSegMinutes($l, $b, $a), $l];
                }
            }
        }
        foreach ($m['groups'] as $g) {
            foreach ($g['Stations'] as $x) {
                foreach ($g['Stations'] as $y) {
                    if ($x['StationUID'] !== $y['StationUID'] && ($w = transferWalk($m, $x['StationUID'], $y['StationUID'], true)) !== null) {
                        $edges[$y['StationUID']][] = [$x['StationUID'], (float) $w['Minutes'], null];
                    }
                }
            }
        }
    }
    $dist = [];
    $pq = new SplPriorityQueue();
    foreach ($goals as $u => $c) {
        $dist[$u] = $c;
        $pq->insert($u, -$c);
    }
    while (!$pq->isEmpty()) {
        $u = $pq->extract();
        $du = $dist[$u];
        foreach ($edges[$u] ?? [] as list($v, $c, $l)) {
            if ($l !== null && !$lineOk($l)) {
                continue;
            }
            $nd = $du + $c;
            if (!isset($dist[$v]) || $nd < $dist[$v] - 1e-9) {
                $dist[$v] = $nd;
                $pq->insert($v, -$nd);
            }
        }
    }
    return $dist;
}

/**
 * 綜合指數最小的前 K 條基本路由（格式與 appNetworkRoutes 相同）。$systems：只用這些系統（null＝不限）。
 * 起訖兩端的同群組車站（臺鐵板橋＝捷運板橋＝環狀線板橋）各自至少保留 Search.IndexPerEnd 條（另外限定該站各找一次）：
 * 前 K 名常被同一種走法的轉乘組合佔滿（鶯歌經臺鐵到臺北再換各種捷運），步行到同群組車站改搭別的路線（鶯歌車站搭三鶯線）也要列。
 * 每條路由的指數記在 appIndexOf()（路由代碼 => 指數）
 */
function appIndexRoutes(array $m, $from, $to, $extra, $k = null, array $systems = null, $usePool = false)
{
    // 站間步行取代法（使用者規則 2026-11）：同一個轉乘群組的車站（臺鐵南港＝高鐵南港＝板南線南港）當迄站時，
    // 到達群組之前的路段都一樣，只差最後一段站內步行；已經找過同群組另一站的，換掉最後的步行就是這一站的路由，不必重新搜尋。
    // 起站同理（臺鐵新左營、高鐵左營都先步行到紅線左營）：共用從群組出發之後的路段，只換開頭的步行。
    // 組出來的路由照樣逐段檢查排除規則（appPrefixExtend、appRoutePatternHit）。快取只在同一次請求內
    static $pool = [];
    $cfg = appIndexConfig();
    $k = $k ?? $cfg['K'];
    appIndexStats(['pops' => 0, 'routes' => 0]);
    $starts = appIndexEnds($m, $from, false);
    $goals = appIndexEnds($m, $to, true);
    // 端點站沒有可搭的路線（不搭高鐵時的高鐵站）：一定要在那一站上下車的搜尋注定找不到，不做（否則找不到任何路由、截斷不生效，要探到展開上限）
    $rideable = function ($u) use ($m, $extra, $systems) {
        foreach (array_keys($m['stationLines'][$u] ?? []) as $l) {
            $sys = substr($l, 0, strpos($l, '-'));
            if (appSystemAllowed($extra, $sys) && ($systems === null || in_array($sys, $systems, true))) {
                return true;
            }
        }
        return false;
    };
    // 已經找到的路由裡，每個起站端、迄站端車站各有幾條（主搜尋已經替某一站找到 PerEnd 條，就不必再替它做保底搜尋：
    // 主搜尋依指數由小到大找，它找到的就是那一站最好的前幾條）
    $endCount = function (array $found, $atStart, $from, $to) {
        $n = [];
        foreach ($found as $x) {
            $raw = $x[2];
            if ($atStart) {
                $u = $raw[0]['Type'] === 'walk' && $raw[0]['From'] === $from ? $raw[0]['To'] : $raw[0]['From'];
            } else {
                $last = end($raw);
                $u = $last['Type'] === 'walk' && $last['To'] === $to ? $last['From'] : $last['To'];
            }
            $n[$u] = ($n[$u] ?? 0) + 1;
        }
        return $n;
    };
    $found = [];
    if ($usePool && (count($starts) > 1 || count($goals) > 1)) {
        // 起站或迄站在轉乘群組裡、而且同群組的其他站也會查（$usePool，中長途分解的生活圈端幹線站候選）：
        // 群組到群組之間的核心路段只找一次（同群組的其他站共用），再接上各自的站內步行。
        // 不會再查同群組其他站的查詢不用（核心路段不套迄站的排除名單提早丟棄，單查一次反而比較慢）
        $sigOf = function (array $ends) { $u = array_keys($ends); sort($u); return implode(',', $u); };
        $key = $sigOf($starts) . '>' . $sigOf($goals) . '|' . $extra . '|' . json_encode($systems) . '|' . $k;
        if (!isset($pool[$key])) {
            $s0 = array_map(function () { return 0.0; }, array_filter($starts, $rideable, ARRAY_FILTER_USE_KEY));
            $g0 = array_map(function () { return 0.0; }, array_filter($goals, $rideable, ARRAY_FILTER_USE_KEY));
            $ext = ($starts ? max($starts) : 0) + ($goals ? max($goals) : 0);
            $core = $s0 && $g0 ? appIndexSearch($m, $from, $to, $extra, 2 * $k, $systems, $s0, $g0, true, $ext) : [];
            $coreFull = $s0 && $g0 && !empty(appIndexStats()['full']);
            $hint = $core ? min(array_column($core, 1)) : null;
            if ($cfg['PerEnd'] > 0) {
                $nS = $endCount($core, true, null, null);
                $nE = $endCount($core, false, null, null);
                foreach (count($s0) > 1 ? $s0 : [] as $u => $_) {
                    if (($nS[$u] ?? 0) < $cfg['PerEnd']) {
                        $core += appIndexSearch($m, $from, $to, $extra, $cfg['PerEnd'], $systems, [$u => 0.0], $g0, true, $ext, $hint);
                    }
                }
                foreach (count($g0) > 1 ? $g0 : [] as $u => $_) {
                    if (($nE[$u] ?? 0) < $cfg['PerEnd']) {
                        $core += appIndexSearch($m, $from, $to, $extra, $cfg['PerEnd'], $systems, $s0, [$u => 0.0], true, $ext, $hint);
                    }
                }
            }
            $pool[$key] = ['full' => $coreFull, 'paths' => array_values(array_map(function ($x) { return [$x[2], $x[2][0]['From'], end($x[2])['To'], $x[1]]; }, $core))];
        } else {
            appIndexStats(['pops' => 0, 'routes' => 0, 'reused' => true]);
        }
        $found = appIndexCompose($m, $from, $to, $pool[$key]['paths'], $starts, $goals, $k);
        $direct = count($found) < $k && $pool[$key]['full'];
    } else {
        $direct = true;
    }
    // 起訖都不在轉乘群組；或核心路段是湊滿上限才停、組出來卻不滿 K 條（這一站的排除規則比較嚴，例如汐止→臺鐵臺北要直接搭臺鐵）：直接搜尋。
    // 核心路段已經探完（能找的都找了）時，組出來的就是全部，不必再找
    if ($direct) {
        $found += appIndexSearch($m, $from, $to, $extra, $k, $systems, $starts, $goals);
        $hint = $found ? min(array_column($found, 1)) : null;
        if ($cfg['PerEnd'] > 0) {
            $nS = $endCount($found, true, $from, $to);
            $nE = $endCount($found, false, $from, $to);
            foreach (count($starts) > 1 ? $starts : [] as $u => $w) {
                if ($rideable($u) && ($nS[$u] ?? 0) < $cfg['PerEnd']) {
                    $found += appIndexSearch($m, $from, $to, $extra, $cfg['PerEnd'], $systems, [$u => $w], $goals, false, 0.0, $hint);
                }
            }
            foreach (count($goals) > 1 ? $goals : [] as $u => $w) {
                if ($rideable($u) && ($nE[$u] ?? 0) < $cfg['PerEnd']) {
                    $found += appIndexSearch($m, $from, $to, $extra, $cfg['PerEnd'], $systems, $starts, [$u => $w], false, 0.0, $hint);
                }
            }
        }
    }
    // 直接轉乘演算法：直達與一次轉乘的走法全部列出（指數搜尋的截斷會丟掉轉乘少但比較慢的，最少轉乘一定要有）
    if ($cfg['OneTransfer']) {
        $found += appIndexOneTransfer($m, $from, $to, $extra, $systems, $starts, $goals);
    }
    // 最少轉乘保底：直達與一次轉乘都沒有（最少要轉 2 次以上）時，用原本的列舉只找最少轉乘那一層（deeper＝0，很快），依指數取前 Search.IndexMinTransfer 條。
    // 指數搜尋每站展開上限會被之後才違反規則的部分路徑用掉（興南→淡海新市鎮：高鐵走法佔滿紅樹林，機捷直達台北車站轉淡水線的轉 2 次走法排不進來），
    // 使用者選「轉乘最少」排序時第一筆要和原本一樣
    $minTc = INF;
    foreach ($found as $x) {
        $minTc = min($minTc, appRouteTransfers($x[0]));
    }
    if ($cfg['MinTransfer'] > 0 && $minTc > 1) {
        $layer = [];
        foreach (appNetworkRoutes($m, $from, $to, $extra, 0, $systems) as $r) {
            $sig = json_encode($r);
            if (!isset($found[$sig]) && appRouteTransfers($r) <= $minTc && ($g = appRouteIndex($m, $r)) !== null) {
                $layer[$sig] = [$r, $g, $r];
            }
        }
        uasort($layer, function ($a, $b) { return $a[1] <=> $b[1]; });
        $found += array_slice($layer, 0, $cfg['MinTransfer'], true);
    }
    uasort($found, function ($a, $b) { return $a[1] <=> $b[1]; });
    $index = [];
    foreach ($found as list($r, $g)) {
        $code = appRouteFormat(['Legs' => $r]);
        $index[$code] = min($index[$code] ?? INF, round($g, 1));
    }
    appIndexOf($index);
    return array_values(array_map(function ($x) { return $x[0]; }, $found));
}

/**
 * 站間步行取代法的組合：$pool 是群組到群組的核心路段 [[段落, 上車站 s, 下車站 e, 指數], ...]（同群組的起訖共用）。
 * s 不是起站時前面接上起站步行到 s，e 不是迄站時後面接上 e 步行到迄站（$starts、$goals：同群組車站 => 步行分鐘）。
 * 每條都重新檢查排除規則（含與起訖有關的排除名單、轉乘走法排除）；取指數最小的前 $k 條，起訖兩端每一站另外各保留 Search.IndexPerEnd 條
 */
function appIndexCompose(array $m, $from, $to, array $pool, array $starts, array $goals, $k)
{
    $st0 = appIndexState0($from, $to);
    $patterns = appRoutePatternsFor($m, $from, $to);
    $cand = [];
    foreach ($pool as list($legs, $s, $e, $g)) {
        if (!isset($starts[$s], $goals[$e])) {
            continue;
        }
        if ($s !== $from) {
            array_unshift($legs, ['Type' => 'walk', 'From' => $from, 'To' => $s, 'Minutes' => null, 'MinutesGiven' => false]);
            $g += $starts[$s];
        }
        if ($e !== $to) {
            $legs[] = ['Type' => 'walk', 'From' => $e, 'To' => $to, 'Minutes' => null, 'MinutesGiven' => false];
            $g += $goals[$e];
        }
        $st = $st0;
        foreach ($legs as $l) {
            $st = appPrefixExtend($m, $st, $l);
            if ($st === null) {
                continue 2;
            }
        }
        $r = appCollapseSystemLegs($legs);
        $sig = json_encode($r);
        if (isset($cand[$sig]) || appRoutePatternHit($m, $r, $patterns)) {
            continue;
        }
        $cand[$sig] = [$r, $g, $legs, $s, $e];
    }
    uasort($cand, function ($a, $b) { return $a[1] <=> $b[1]; });
    $out = [];
    $nS = [];
    $nE = [];
    $quota = appIndexConfig()['PerEnd'];
    foreach ($cand as $sig => $x) {
        if (count($out) < $k || ($nS[$x[3]] ?? 0) < $quota || ($nE[$x[4]] ?? 0) < $quota) {
            $out[$sig] = [$x[0], $x[1], $x[2]];
            $nS[$x[3]] = ($nS[$x[3]] ?? 0) + 1;
            $nE[$x[4]] = ($nE[$x[4]] ?? 0) + 1;
        }
    }
    return $out;
}

/** 排除規則的初始狀態（appPrefixExtend 用；與 appNetworkRoutes 相同） */
function appIndexState0($from, $to)
{
    return ['rg' => appRegionRule($from, $to), 'rgPrev' => null, 'rgWalk' => false, 'rgFrom' => appRegionOf($from), 'rgTo' => appRegionOf($to)] + appPrefixState();
}

/**
 * 直接轉乘演算法（使用者規則 2026-11）：起訖（含同群組車站）所在的每條路線兩兩配對——
 * 同一條路線能直接搭的列直達；不同路線時，起站路線上的每個轉乘站與迄站路線上的每個轉乘站，同一個轉乘群組（或同一站）的
 * 就是一次轉乘可到（起站 ─路線A→ x ～步行～ y ─路線B→ 迄站）。逐條檢查排除規則。回傳 [簽章 => [段落, 指數, 原始段落]]
 */
function appIndexOneTransfer(array $m, $from, $to, $extra, $systems, array $starts, array $goals)
{
    $lineOk = function ($l) use ($extra, $systems) {
        $sys = substr($l, 0, strpos($l, '-'));
        return appSystemAllowed($extra, $sys) && ($systems === null || in_array($sys, $systems, true));
    };
    $noTransfer = appNoTransferStations();
    $st0 = appIndexState0($from, $to);
    $patterns = appRoutePatternsFor($m, $from, $to);
    $walk = function ($a, $b) { return ['Type' => 'walk', 'From' => $a, 'To' => $b, 'Minutes' => null, 'MinutesGiven' => false]; };
    $ride = function ($l, $a, $b) { return ['Type' => 'ride', 'From' => $a, 'To' => $b, 'LineUID' => $l, 'Class' => null, 'System' => substr($l, 0, strpos($l, '-')), 'Through' => false]; };
    $out = [];
    $add = function (array $legs) use ($m, $st0, $patterns, &$out) {
        $st = $st0;
        foreach ($legs as $l) {
            $st = appPrefixExtend($m, $st, $l);
            if ($st === null) {
                return;
            }
        }
        $r = appCollapseSystemLegs($legs);
        $sig = json_encode($r);
        if (!isset($out[$sig]) && !appRoutePatternHit($m, $r, $patterns) && ($g = appRouteIndex($m, $r)) !== null) {
            $out[$sig] = [$r, $g, $legs];
        }
    };
    foreach ($starts as $s => $_) {
        $pre = $s !== $from ? [$walk($from, $s)] : [];
        foreach (array_keys($m['stationLines'][$s] ?? []) as $la) {
            if (!$lineOk($la)) {
                continue;
            }
            foreach ($goals as $g => $__) {
                $post = $g !== $to ? [$walk($g, $to)] : [];
                foreach (array_keys($m['stationLines'][$g] ?? []) as $lb) {
                    if (!$lineOk($lb)) {
                        continue;
                    }
                    // 直達：同一條路線
                    if ($la === $lb && $s !== $g && appRideStations($m, ['LineUID' => $la, 'From' => $s, 'To' => $g]) !== null) {
                        $add(array_merge($pre, [$ride($la, $s, $g)], $post));
                    }
                    // 一次轉乘：A 線上的轉乘站 x 與 B 線上的車站 y 同站或同群組
                    foreach (appIndexLineDecisions($m, $la) as $x) {
                        if ($x === $s || isset($noTransfer[$x]) || appRideStations($m, ['LineUID' => $la, 'From' => $s, 'To' => $x]) === null) {
                            continue;
                        }
                        $ys = isset($m['stationLines'][$x][$lb]) ? [$x] : [];
                        foreach ($m['groupOf'][$x] ?? [] as $gid) {
                            foreach ($m['groups'][$gid]['Stations'] as $yy) {
                                if ($yy['StationUID'] !== $x && isset($m['stationLines'][$yy['StationUID']][$lb])) {
                                    $ys[] = $yy['StationUID'];
                                }
                            }
                        }
                        foreach ($ys as $y) {
                            if ($y === $g || isset($noTransfer[$y]) || ($y === $x && $la === $lb)
                                || appRideStations($m, ['LineUID' => $lb, 'From' => $y, 'To' => $g]) === null) {
                                continue;
                            }
                            if ($y !== $x && transferWalk($m, $x, $y) === null) {
                                continue;
                            }
                            $mid = $y !== $x ? [$walk($x, $y)] : [];
                            $add(array_merge($pre, [$ride($la, $s, $x)], $mid, [$ride($lb, $y, $g)], $post));
                        }
                    }
                }
            }
        }
    }
    return $out;
}

/** 起訖站本身與同群組的車站（先步行／最後步行）＝> 步行分鐘，與 appNetworkRoutes 相同（不當轉乘站的別名站不列） */
function appIndexEnds(array $m, $u, $toEnd)
{
    $noTransfer = appNoTransferStations();
    $out = [$u => 0.0];
    foreach ($m['groupOf'][$u] ?? [] as $gid) {
        foreach ($m['groups'][$gid]['Stations'] as $x) {
            if (isset($noTransfer[$x['StationUID']]) || ($noTransfer[$u] ?? null) === $x['StationUID']) {
                continue;
            }
            $w = $toEnd ? transferWalk($m, $x['StationUID'], $u, true) : transferWalk($m, $u, $x['StationUID'], true);
            if ($x['StationUID'] !== $u && $w) {
                $out[$x['StationUID']] = (float) $w['Minutes'];
            }
        }
    }
    return $out;
}

/**
 * 綜合指數搜尋本體：從 $starts（車站 => 起站步行分鐘）出發、經 $goals（車站 => 到迄站步行分鐘）之一到迄站，指數最小的前 $k 條。
 * 回傳 [路由簽章 => [段落, 指數], ...]
 */
function appIndexSearch(array $m, $from, $to, $extra, $k, $systems, array $starts, array $goals, $core = false, $cutoffExtra = 0.0, $bestHint = null)
{
    // $bestHint：截斷的基準指數（保底搜尋用主搜尋的最佳：找不到任何路由時也能停，不必探到展開上限）
    // $cutoffExtra：截斷線多放的分鐘（核心路段不含兩端站內步行，加上最長的步行，才不會比直接搜尋的截斷線緊）
    // $core＝true（站間步行取代法的核心路段）：從 $starts 任一站上車、搭到 $goals 任一站下車為止，不含起訖的站內步行；
    // 只套與起訖站無關的排除規則（迄站的排除名單夥伴站、轉乘走法排除等，到組成實際起訖時才檢查）
    $cfg = appIndexConfig();
    $sysOf = function ($l) { return substr($l, 0, strpos($l, '-')); };
    $lineOk = function ($l) use ($extra, $systems, $sysOf) {
        $sys = $sysOf($l);
        return appSystemAllowed($extra, $sys) && ($systems === null || in_array($sys, $systems, true));
    };
    $isTrain = function ($sys) { return in_array($sys, FINDER_TRAIN_SYSTEMS, true); };
    $noTransfer = appNoTransferStations();
    $h = appIndexHeuristic($m, $goals, $lineOk);
    if (!isset($h[$from]) && !array_filter(array_keys($starts), function ($u) use ($h) { return isset($h[$u]); })) {
        return [];
    }
    $regionRule = appRegionRule($from, $to);
    $patterns = appRoutePatternsFor($m, $from, $to);
    // 排除名單（DirectPairs）裡迄站的夥伴站：迄站一定會經過，所以部分路徑已經在別段經過夥伴站，之後到迄站一定違反，可以提早丟掉
    $destPair = appPairStation($m, $to);
    $destPartners = array_flip(appDirectPartners()[$destPair] ?? []);
    $st0 = appIndexState0($from, $to);
    // 部分路徑存成節點（父節點＋最後一段），排除規則延後到從佇列取出時才檢查（appPrefixExtend 只算真的展開的那些）
    $nodes = [];   // id => [u, g, parent, leg, prev, walked, uses, st]
    $pq = new SplPriorityQueue();
    $pq->setExtractFlags(SplPriorityQueue::EXTR_DATA);
    $seq = PHP_INT_MAX;
    $push = function ($u, $g, $parent, $leg, $prev, $walked, $uses) use ($pq, &$seq, &$nodes, $h) {
        if (!isset($h[$u])) {
            return;
        }
        $nodes[] = [$u, $g, $parent, $leg, $prev, $walked, $uses, null];
        // 同樣指數時先進先出（priority 用 [−f, 序號]）
        $pq->insert(count($nodes) - 1, [-($g + $h[$u]), $seq--]);
    };
    $legsOf = function ($id) use (&$nodes) {
        $out = [];
        for (; $id !== null; $id = $nodes[$id][2]) {
            if ($nodes[$id][3] !== null) {
                $out[] = $nodes[$id][3];
            }
        }
        return array_reverse($out);
    };
    foreach ($starts as $s => $w) {
        $leg = $s !== $from && !$core ? ['Type' => 'walk', 'From' => $from, 'To' => $s, 'Minutes' => null, 'MinutesGiven' => false] : null;
        $push($s, $w, null, $leg, null, $leg !== null, []);
    }
    $routes = [];
    $pops = 0;
    $expanded = [];
    $best = $bestHint;
    while (!$pq->isEmpty() && count($routes) < $k && $pops++ < $cfg['MaxPops']) {
        $id = $pq->extract();
        list($u, $g, $parent, $leg, $prev, $walked, $uses) = $nodes[$id];
        // 截斷：部分路徑的下界（指數＋到迄站的下界）已超過「最好的 × CutoffRatio ＋ CutoffPlus」就停（符合規則的路由不到 K 條時不必探完整個路網）
        if ($best !== null && $g + $h[$u] > ($best + $cutoffExtra) * $cfg['CutoffRatio'] + $cfg['CutoffPlus']) {
            break;
        }
        // 排除規則（延後檢查）：父節點的狀態接上這一段
        $st = $parent === null ? $st0 : $nodes[$parent][7];
        if ($leg !== null) {
            $st = appPrefixExtend($m, $st, $leg);
            if ($st === null) {
                continue;
            }
        }
        // 已經過迄站的夥伴站（還沒到迄站）：之後到迄站一定是另一段，違反排除名單，不再展開。
        // 目前所在的站除外（它也是下一段的上車站，下一段可能同時經過它和迄站）
        // 目前所在的站就是迄站的同一站（臺北-環島＝臺北）時，這一段已包含迄站，不在此限
        if (!$core && $u !== $to && $destPartners && appPairStation($m, $u) !== $destPair && ($hit = array_intersect_key($st['segOf'] ?? [], $destPartners))) {
            unset($hit[appPairStation($m, $u)]);
            if ($hit) {
                continue;
            }
        }
        $nodes[$id][7] = $st;
        // 核心路段：搭到 $goals 任一站就是一條（不再往下展開）
        if ($core && isset($goals[$u]) && $leg !== null && $leg['Type'] === 'ride') {
            $raw = $legsOf($id);
            $r = appCollapseSystemLegs($raw);
            $sig = json_encode($r);
            if (!isset($routes[$sig])) {
                $routes[$sig] = [$r, $g, $raw];
                $best = $best ?? $g;
            }
            continue;
        }
        // 到迄站：搭乘直接到（迄站本身在 $goals），或從 $goals 的車站最後步行到
        if (!$core && $u === $to && $leg !== null) {
            if (!isset($goals[$leg['Type'] === 'walk' ? $leg['From'] : $to])) {
                continue;
            }
            $r = appCollapseSystemLegs($legsOf($id));
            $code = appRouteFormat(['Legs' => $r]);
            $sig = json_encode($r);   // 同一個路由代碼、經過不同路線串的（豐原→大甲經成追線、經彰化）分開算，appCoreRoutes 再合併成 Paths
            if (!isset($routes[$sig]) && !appRoutePatternHit($m, $r, $patterns)) {
                $routes[$sig] = [$r, $g, $legsOf($id)];
                $best = $best ?? $g;
            }
            continue;
        }
        // 同一個車站（依抵達的路線）最多展開 K 次（k 條最短路徑的標準剪枝：第 K+1 次以後的部分路徑不會進前 K 名）
        $nk = $u . '|' . $prev . '|' . (int) $walked;
        if (($expanded[$nk] = ($expanded[$nk] ?? 0) + 1) > $k) {
            continue;
        }
        // 步行到同群組的車站（剛搭完車才步行；起站已在 starts 處理）；往迄站的最後步行也在這裡
        if ($leg !== null && $leg['Type'] === 'ride') {
            foreach ($m['groupOf'][$u] ?? [] as $gid) {
                foreach ($m['groups'][$gid]['Stations'] as $x) {
                    $y = $x['StationUID'];
                    $isEnd = !$core && $y === $to;
                    if ($y === $u || isset($noTransfer[$y]) || (!$isEnd && empty($m['stationLines'][$y])) || ($isEnd && !isset($goals[$u])) || ($core && isset($goals[$y]))) {
                        continue;
                    }
                    $w = transferWalk($m, $u, $y, $isEnd);
                    if ($w !== null) {
                        $push($y, $g + (float) $w['Minutes'], $id, ['Type' => 'walk', 'From' => $u, 'To' => $y, 'Minutes' => null, 'MinutesGiven' => false],
                              $prev, true, $uses);
                    }
                }
            }
        }
        // 搭乘：車站上的每條路線，到該路線上的決策站或迄站（同一條路線接著搭只在支線分岔站）
        if (array_sum($uses) >= $cfg['MaxLines']) {
            continue;
        }
        foreach (array_keys($m['stationLines'][$u] ?? []) as $l) {
            if (!$lineOk($l) || ($uses[$l] ?? 0) >= $cfg['MaxLineUses']) {
                continue;
            }
            $sys = $sysOf($l);
            if ($prev !== null && $prev === $l && ($walked || !in_array($u, appLineJunctions($m, $l), true))) {
                continue;
            }
            // 候車：起站第一次上車不加；同一鐵路系統在交會站接續（同一段系統直達段）不加、算一站停靠；其他轉乘加平均班距的一半
            $through = $prev !== null && !$walked && $isTrain($sys) && $sysOf($prev) === $sys && $prev !== $l;
            $add = $prev === null ? ($sys === 'TRA' ? appIndexWait($u) : 0.0) : ($through ? $cfg['StopMinutes'] : appIndexWait($u));
            $targets = appIndexLineDecisions($m, $l);
            foreach ($goals as $gu => $_) {
                if (isset($m['stationLines'][$gu][$l])) {
                    $targets[] = $gu;
                }
            }
            $nu = $uses;
            $nu[$l] = ($nu[$l] ?? 0) + 1;
            foreach (array_unique($targets) as $v) {
                if ($v === $u || !isset($h[$v]) || (isset($noTransfer[$v]) && $v !== $to)) {
                    continue;   // 不當轉乘站的別名站（臺北-環島）不在那裡下車
                }
                $r = appIndexRide($m, $l, $u, $v);
                if ($r !== null) {
                    $push($v, $g + $add + $r, $id, ['Type' => 'ride', 'From' => $u, 'To' => $v, 'LineUID' => $l, 'Class' => null, 'System' => $sys, 'Through' => false],
                          $l, false, $nu);
                }
            }
        }
    }
    $st = appIndexStats();
    // full：因為湊滿 $k 條才停（還有沒找的）；否則是探完或截斷
    appIndexStats(['pops' => ($st['pops'] ?? 0) + $pops, 'routes' => ($st['routes'] ?? 0) + count($routes), 'full' => count($routes) >= $k] + $st);
    return $routes;
}

/** 最近一次 appIndexRoutes 的路由指數（路由代碼 => 指數；跨呼叫累積） */
function appIndexOf(array $add = null)
{
    static $all = [];
    if ($add !== null) {
        $all = $add + $all;
    }
    return $all;
}

/** 統計（測試用）：最近一次的展開次數 */
function appIndexStats(array $set = null)
{
    static $s = [];
    if ($set !== null) {
        $s = $set;
    }
    return $s;
}

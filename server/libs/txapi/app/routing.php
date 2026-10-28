<?php
/**
 * 核心 A：路由演算法（不看時刻）。起訖站 → 所有可能的路由，每段標上車種等級（local／express／不限）。
 *
 *   1. 路網層：路線連結圖上找路線串與跨線轉乘站（同 transfer/network 的做法，參考 railtime getTRA_linkMap）；extra 含 2 時高鐵路線不進圖
 *   1b. 鐵路（臺鐵、高鐵）連續搭乘合成系統直達段（LineUID＝業者代碼，經過的路線串在 Path／Paths）
 *   2. 補充資料（transfer_route_supplements.json，supplement.php）：每條符合的多一條路由。原本寫死在這裡的快慢車展開：
 *        臺鐵  不限車種的原路由；全程區間車在接力站分切（不能直達才切，參考 railtime getTRA_linkRouteV1 第一步）；
 *              沒排除對號時，區間車到第一個大站、大站之間搭對號、最後一個大站換區間車（railtime 第三步），另加在主要站換車的變化
 *        高鐵、機捷  起訖一端是快車很少停的車站時，加「在換車站（台中、A8）換快車／慢車」的走法
 *   3. 路由層排除：extra 含 1 時不產生臺鐵 express 段
 *   4. 去重、估計分鐘（依車種等級）、里程，依轉乘次數 → 里程 → 估計分鐘排序（繞遠的排後面，不排除）
 *
 * 路由的段落：['Type' => 'ride', 'From', 'To', 'LineUID', 'Class', 'System', 'Through'（與前一段在路線交會站銜接、同一班車可能直通，不算轉乘）]
 *             ['Type' => 'walk', 'From', 'To', 'Minutes' => null, 'MinutesGiven' => false]
 */

// 搜尋範圍的參數（深度、上限）放在 transfer_route_rules.json 的 Search，見 appRouteSearch()；這裡是檔案沒寫時的預設
const APP_ROUTE_SEARCH_DEFAULTS = [
    'Deeper'       => 2,      // 轉乘次數比最少的多幾次還要找（API 的 deeper 預設值）
    'MaxLines'     => 6,      // 一條路由最多幾條路線
    'MaxLineUses'  => 2,      // 同一條路線最多搭幾次
    'NetworkLimit' => 40,     // 估計後取前幾個基本路由展開快慢車（不轉乘的路由一律保留）
    'DefaultLimit' => 20,     // routes 預設回傳幾條
    'MaxQueue'     => 30000,  // 路線圖搜尋的佇列上限
    'MaxRoutes'    => 20000,  // 基本路由上限
];

/** 兩站間（同一系統）某等級的最短行駛分鐘；沒有這種車回 null。臺鐵、高鐵依列車時刻，捷運用 transfer 的估算 */
function appLegMinutes($line, $a, $b, $class = null)
{
    static $cache = [];
    $k = $line . '|' . $a . '|' . $b . '|' . $class;
    if (array_key_exists($k, $cache)) {
        return $cache[$k];
    }
    $db = appDb();
    $sys = strpos($line, '-') === false ? $line : substr($line, 0, strpos($line, '-'));   // 系統直達段的 LineUID 就是業者代碼（TRA）
    $v = null;
    if ($sys === 'TRA' || $sys === 'THSR') {
        $tab = appRailMinutesTable();
        $x = $tab[$a . '|' . $b] ?? null;
        $v = $x === null ? null : ($class === null ? $x[0] : ($class === APP_CLASS_LOCAL ? $x[1] : $x[2]));
    } else {
        $t = transferLegMinutes($db, appModel(), $line, $a, $b);
        $v = $t['Minutes'] === null ? null : (float) $t['Minutes'];
        if ($class === APP_CLASS_EXPRESS && appClassHasSplit($sys) && !(in_array($a, appExpressStations($sys), true) && in_array($b, appExpressStations($sys), true))) {
            $v = null;
        }
    }
    return $cache[$k] = $v;
}

/**
 * 臺鐵、高鐵兩站間的最短行駛分鐘表（'上車站|下車站' => [不限, 慢車, 快車]，沒有該等級的車為 null）。
 * 從列車時刻一次算好，快取在 TDX_CACHE_DIR/app_rail_minutes.ser（離線資料重建後自動重算），取代每次查詢資料庫
 */
function appRailMinutesTable()
{
    static $tab = null;
    if ($tab !== null) {
        return $tab;
    }
    $built = appDataUpdatedAt();
    $file = TDX_CACHE_DIR . '/app_rail_minutes.ser';
    $sig = $built . '|' . md5(json_encode(appClassConfig()));
    if (is_file($file)) {
        $c = @unserialize((string) file_get_contents($file));
        if (is_array($c) && ($c['sig'] ?? '') === $sig) {
            return $tab = $c['tab'];
        }
    }
    $tab = [];
    $trains = [];
    foreach (appDb()->all("SELECT t.train_key, t.system, t.train_type_code, s.station_uid, s.sort_min
                           FROM train t JOIN train_stop s ON s.train_key = t.train_key ORDER BY t.train_key, s.seq") as $r) {
        $trains[$r['train_key']]['sys'] = $r['system'];
        $trains[$r['train_key']]['code'] = $r['train_type_code'];
        $trains[$r['train_key']]['st'][] = [$r['station_uid'], (int) $r['sort_min']];
    }
    $thsrLine = appLineStationsOf('THSR', '');
    foreach ($trains as $t) {
        $st = $t['st'];
        $cls = $t['sys'] === 'TRA' ? appTrainClassByType($t['code']) : appTrainClassByStops($thsrLine, array_column($st, 0));
        $ci = $cls === APP_CLASS_LOCAL ? 1 : 2;
        for ($i = 0, $n = count($st); $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $k = $st[$i][0] . '|' . $st[$j][0];
                $mins = $st[$j][1] - $st[$i][1];
                if (!isset($tab[$k])) {
                    $tab[$k] = [$mins, null, null];
                } elseif ($mins < $tab[$k][0]) {
                    $tab[$k][0] = $mins;
                }
                if ($tab[$k][$ci] === null || $mins < $tab[$k][$ci]) {
                    $tab[$k][$ci] = $mins;
                }
            }
        }
    }
    if (!is_dir(TDX_CACHE_DIR)) {
        @mkdir(TDX_CACHE_DIR, 0755, true);
    }
    @file_put_contents($file . '.tmp', serialize(['sig' => $sig, 'tab' => $tab]), LOCK_EX);
    @rename($file . '.tmp', $file);
    return $tab;
}

/** 高鐵某等級的車次（依停靠站分類） */
function appThsrTrainsOfClass($class)
{
    static $by = null;
    if ($by === null) {
        $by = [APP_CLASS_LOCAL => [], APP_CLASS_EXPRESS => []];
        $stops = [];
        foreach (appDb()->all("SELECT s.train_key, s.station_uid FROM train_stop s JOIN train t ON t.train_key = s.train_key WHERE t.system = 'THSR' ORDER BY s.train_key, s.seq") as $r) {
            $stops[$r['train_key']][] = $r['station_uid'];
        }
        $line = appLineStationsOf('THSR', '');
        foreach ($stops as $k => $st) {
            $by[appTrainClassByStops($line, $st)][] = $k;
        }
    }
    return $by[$class] ?? [];
}

/**
 * 快車停靠的車站（每日快車停靠次數夠多的）：臺鐵依車種代碼統計、高鐵依跳站車次統計（不到最多那站的 1/4 視為快車很少停）、機捷是直達車的停靠站
 */
function appExpressStations($sys)
{
    static $cache = [];
    if (isset($cache[$sys])) {
        return $cache[$sys];
    }
    $db = appDb();
    $out = [];
    if ($sys === 'TRA') {
        $codes = appClassConfig()['TRA']['LocalTypeCodes'] ?? ['6', '7', '10'];
        $in = implode(',', array_fill(0, count($codes), '?'));
        foreach ($db->all("SELECT s.station_uid, COUNT(*) AS n FROM train_stop s JOIN train t ON t.train_key = s.train_key
                           WHERE t.system = 'TRA' AND t.train_type_code NOT IN ($in) GROUP BY s.station_uid", $codes) as $r) {
            $out[$r['station_uid']] = (int) $r['n'];
        }
        $out = array_filter($out, function ($n) { return $n >= 10; });
    } elseif ($sys === 'THSR') {
        $keys = appThsrTrainsOfClass(APP_CLASS_EXPRESS);
        $cnt = [];
        foreach (array_chunk($keys, 400) as $chunk) {
            foreach ($db->all('SELECT station_uid, COUNT(*) AS n FROM train_stop WHERE train_key IN (' . implode(',', array_fill(0, count($chunk), '?')) . ') GROUP BY station_uid', $chunk) as $r) {
                $cnt[$r['station_uid']] = ($cnt[$r['station_uid']] ?? 0) + (int) $r['n'];
            }
        }
        $max = $cnt ? max($cnt) : 0;
        $out = array_filter($cnt, function ($n) use ($max) { return $n >= $max / 4; });
    } elseif ($sys === 'TYMC') {
        foreach ($db->all("SELECT DISTINCT from_uid FROM metro_travel_od WHERE system = 'TYMC' AND train_type = 2") as $r) {
            $out[$r['from_uid']] = 1;
        }
    }
    return $cache[$sys] = array_keys($out);
}

/** 臺鐵每站每日快車停靠次數（選主要站用） */
function appTraExpressCounts()
{
    static $cnt = null;
    if ($cnt === null) {
        $cnt = [];
        $codes = appClassConfig()['TRA']['LocalTypeCodes'] ?? ['6', '7', '10'];
        $in = implode(',', array_fill(0, count($codes), '?'));
        foreach (appDb()->all("SELECT s.station_uid, COUNT(*) AS n FROM train_stop s JOIN train t ON t.train_key = s.train_key
                               WHERE t.system = 'TRA' AND t.train_type_code NOT IN ($in) GROUP BY s.station_uid", $codes) as $r) {
            $cnt[$r['station_uid']] = (int) $r['n'];
        }
    }
    return $cnt;
}

// ================================================================
// 1. 路網層

/**
 * 路線連結圖搜尋（同 transferNetworkSearch，另可排除路線）。回傳基本路由（段落陣列）的清單
 */
function appNetworkRoutes(array $m, $from, $to, $extra, $deeper = 1)
{
    $lineOk = function ($l) use ($extra) {
        return appSystemAllowed($extra, substr($l, 0, strpos($l, '-')));
    };
    // 起訖：車站本身的路線，以及同群組車站的路線（先步行）
    $noTransfer = appNoTransferStations();
    $ends = function ($u, $toEnd) use ($m, $noTransfer) {
        $out = [[$u, 0, null]];
        foreach ($m['groupOf'][$u] ?? [] as $gid) {
            foreach ($m['groups'][$gid]['Stations'] as $x) {
                if (isset($noTransfer[$x['StationUID']]) || ($noTransfer[$u] ?? null) === $x['StationUID']) {
                    continue;   // 不當轉乘站的車站（臺北-環島）：起訖本身是它才用；從它也不步行到它代表的車站（同一個車站）
                }
                $w = $toEnd ? transferWalk($m, $x['StationUID'], $u, true) : transferWalk($m, $u, $x['StationUID'], true);
                if ($x['StationUID'] !== $u && $w) {
                    $out[] = [$x['StationUID'], $w['Minutes'], $gid];
                }
            }
        }
        return $out;
    };
    $starts = [];
    foreach ($ends($from, false) as $e) {
        foreach (array_keys($m['stationLines'][$e[0]] ?? []) as $l) {
            if ($lineOk($l)) {
                $starts[$l][] = $e;
            }
        }
    }
    $goals = [];
    foreach ($ends($to, true) as $e) {
        foreach (array_keys($m['stationLines'][$e[0]] ?? []) as $l) {
            if ($lineOk($l)) {
                $goals[$l][] = $e;
            }
        }
    }
    // BFS：成本＝搭乘的系統段數（同一鐵路系統在交會站換線不加成本，例如臺鐵西部幹線接六家線、內灣線），
    // 最少成本再多 $deeper；同一條路線最多搭 Search.MaxLineUses 次（文湖線→板南線→文湖線、山線→海線→山線這類捷徑要能找到），
    // 最多 Search.MaxLines 條路線。不合理的走法交給組合時的通則（重複經過車站）與排除名單
    $cfg = appRouteSearch();
    $sysOf = function ($l) { return substr($l, 0, strpos($l, '-')); };
    $isTrain = function ($l) use ($sysOf) { return in_array($sysOf($l), FINDER_TRAIN_SYSTEMS, true); };
    // 剪枝下界：每條路線到終點路線至少還要多少成本（反向 0-1 BFS，成本算法與下面相同）。
    // 只剪掉「怎麼走都會超過 最少成本＋deeper」的展開，找到的路線串不變
    $h = [];
    $dq = [];
    foreach (array_keys($goals) as $l) {
        $h[$l] = 0;
        $dq[] = $l;
    }
    while ($dq) {
        $n = array_shift($dq);
        foreach (array_keys($m['links'][$n] ?? []) as $l) {
            if (!$lineOk($l)) {
                continue;
            }
            $c = $h[$n] + (($isTrain($n) && $sysOf($n) === $sysOf($l)) ? 0 : 1);   // 從 $l 換到 $n 的成本
            if (!isset($h[$l]) || $c < $h[$l]) {
                $h[$l] = $c;
                if ($c === $h[$n]) {
                    array_unshift($dq, $l);
                } else {
                    $dq[] = $l;
                }
            }
        }
    }
    $paths = [];
    $queue = [];
    foreach (array_keys($starts) as $l) {
        if (isset($h[$l])) {
            $queue[] = [[$l], 1];
        }
    }
    $best = null;
    $qi = 0;
    while ($qi < count($queue)) {
        list($p, $cost) = $queue[$qi++];
        $last = $p[count($p) - 1];
        if ($best !== null && $cost > $best + $deeper) {
            continue;
        }
        if (isset($goals[$last])) {
            $paths[] = $p;
            $best = $best === null ? $cost : min($best, $cost);
        }
        if (count($p) >= $cfg['MaxLines']) {
            continue;
        }
        $next = array_keys($m['links'][$last] ?? []);
        if (appLineJunctions($m, $last)) {
            $next[] = $last;   // 有支線的路線：在分岔站同站換車接另一條支線
        }
        foreach ($next as $n) {
            if (!$lineOk($n)) {
                continue;
            }
            if (count(array_keys($p, $n, true)) >= $cfg['MaxLineUses']) {
                continue;
            }
            $c = $cost + (($isTrain($n) && $sysOf($n) === $sysOf($last)) ? 0 : 1);
            if (!isset($h[$n]) || ($best !== null && $c + $h[$n] > $best + $deeper)) {
                continue;
            }
            $queue[] = [array_merge($p, [$n]), $c];
        }
        if (count($queue) > $cfg['MaxQueue']) {
            break;
        }
    }
    // 展開轉乘站組合：逐段往下選轉乘站（深度優先），每加一段就用排除規則檢查已走的部分（重複經過車站、排除名單等），
    // 違反的前綴整串不再往下展開，省下組合數；加速清單（LineTransfers）限制可用的轉乘群組
    $routes = [];
    $patterns = appRoutePatternsFor($m, $from, $to);
    $transfers = [];   // 第 i 條路線接第 i+1 條路線的轉乘站對 [x（第 i 條線上）, y（第 i+1 條線上）]
    foreach ($paths as $p) {
        for ($i = 0; $i + 1 < count($p); $i++) {
            $k = $p[$i] . '>' . $p[$i + 1];
            if (isset($transfers[$k])) {
                continue;
            }
            $transfers[$k] = [];
            if ($p[$i] === $p[$i + 1]) {
                foreach (appLineJunctions($m, $p[$i]) as $u) {
                    $transfers[$k][] = [$u, $u];   // 支線分岔站同站換車
                }
                continue;
            }
            $allow = appLineTransferGroups($p[$i], $p[$i + 1]);   // 加速清單：只用列出的轉乘群組
            foreach ($m['links'][$p[$i]][$p[$i + 1]] as $gid) {
                if ($allow !== null && !isset($allow[$gid])) {
                    continue;
                }
                foreach ($m['groups'][$gid]['Stations'] as $x) {
                    if (!in_array($p[$i], $x['Lines'], true) || isset($noTransfer[$x['StationUID']])) {
                        continue;
                    }
                    foreach ($m['groups'][$gid]['Stations'] as $y) {
                        if (!in_array($p[$i + 1], $y['Lines'], true) || isset($noTransfer[$y['StationUID']])) {
                            continue;
                        }
                        if (transferWalk($m, $x['StationUID'], $y['StationUID']) !== null) {
                            $transfers[$k][] = [$x['StationUID'], $y['StationUID']];
                        }
                    }
                }
            }
        }
        $last = count($p) - 1;
        foreach ($starts[$p[0]] as $s) {
            // 已走部分的段落（起站步行＋搭乘＋轉乘步行），用來檢查排除規則
            $st0 = appPrefixState();
            if ($s[0] !== $from) {
                $st0 = appPrefixExtend($m, $st0, ['Type' => 'walk', 'From' => $from, 'To' => $s[0]]);
            }
            $stack = [[0, $s[0], [], $st0]];
            while ($stack) {
                list($i, $cur, $combo, $state) = array_pop($stack);
                if ($i === $last) {
                    foreach ($goals[$p[$last]] as $g) {
                        // 最後一段（搭到下車站＋步行到終點）也要過排除規則，回傳的基本路由就都已檢查過
                        if ($g[0] === $cur || !isset($m['stationLines'][$cur][$p[$last]], $m['stationLines'][$g[0]][$p[$last]])) {
                            continue;
                        }
                        $ns = appPrefixExtend($m, $state, ['Type' => 'ride', 'From' => $cur, 'To' => $g[0], 'LineUID' => $p[$last], 'System' => substr($p[$last], 0, strpos($p[$last], '-'))]);
                        if ($ns !== null && $g[0] !== $to) {
                            $ns = appPrefixExtend($m, $ns, ['Type' => 'walk', 'From' => $g[0], 'To' => $to]);
                        }
                        if ($ns === null) {
                            continue;
                        }
                        $r = appNetworkRoute($m, $from, $to, $p, $s, $g, $combo);
                        if ($r !== null && appRoutePatternHit($m, $r, $patterns)) {
                            continue;   // 轉乘走法排除（起訖符合、轉乘站串相同）
                        }
                        if ($r !== null) {
                            $routes[] = $r;
                        }
                    }
                    continue;
                }
                $line = $p[$i];
                foreach ($transfers[$line . '>' . $p[$i + 1]] as list($x, $y)) {
                    if ($x === $cur || !isset($m['stationLines'][$cur][$line], $m['stationLines'][$x][$line])) {
                        continue;
                    }
                    // 已走的部分就違反規則（重複經過車站、鐵路改搭捷運又搭回、排除名單）：不必再往下組合
                    $ns = appPrefixExtend($m, $state, ['Type' => 'ride', 'From' => $cur, 'To' => $x, 'LineUID' => $line, 'System' => substr($line, 0, strpos($line, '-'))]);
                    if ($ns !== null && $x !== $y) {
                        $ns = appPrefixExtend($m, $ns, ['Type' => 'walk', 'From' => $x, 'To' => $y]);
                    }
                    if ($ns === null) {
                        continue;
                    }
                    $stack[] = [$i + 1, $y, array_merge($combo, [[$x, $y]]), $ns];
                }
                if (count($routes) > $cfg['MaxRoutes']) {
                    break 3;
                }
            }
        }
    }
    return $routes;
}

/** 組出一條基本路由（段落陣列）；不合理回 null */
function appNetworkRoute(array $m, $from, $to, array $p, array $s, array $g, array $combo)
{
    $legs = [];
    if ($s[0] !== $from) {
        $legs[] = ['Type' => 'walk', 'From' => $from, 'To' => $s[0], 'Minutes' => null, 'MinutesGiven' => false];
    }
    $cur = $s[0];
    foreach ($p as $i => $line) {
        $exit = $i < count($combo) ? $combo[$i][0] : $g[0];
        if ($cur === $exit || !isset($m['stationLines'][$cur][$line], $m['stationLines'][$exit][$line])) {
            return null;
        }
        $legs[] = ['Type' => 'ride', 'From' => $cur, 'To' => $exit, 'LineUID' => $line, 'Class' => null,
                   'System' => substr($line, 0, strpos($line, '-')), 'Through' => false];
        if ($i < count($combo)) {
            if ($combo[$i][0] !== $combo[$i][1]) {
                $legs[] = ['Type' => 'walk', 'From' => $combo[$i][0], 'To' => $combo[$i][1], 'Minutes' => null, 'MinutesGiven' => false];
            }
            $cur = $combo[$i][1];
        }
    }
    if ($g[0] !== $to) {
        $legs[] = ['Type' => 'walk', 'From' => $g[0], 'To' => $to, 'Minutes' => null, 'MinutesGiven' => false];
    }
    return appCollapseSystemLegs($legs);
}

/**
 * 同一鐵路系統（臺鐵、高鐵）的連續搭乘合成一段「系統直達段」：LineUID＝業者代碼（TRA），點對點查詢不分路線直接查站到站直達；
 * 經過的路線（TDX 路線串，例如西部幹線→宜蘭線）存在 Path（[[LineUID, From, To], ...] 的段落），前端需要時再拿來顯示
 */
function appCollapseSystemLegs(array $legs)
{
    $out = [];
    foreach ($legs as $l) {
        $k = count($out) - 1;
        if ($l['Type'] === 'ride' && in_array($l['System'], FINDER_TRAIN_SYSTEMS, true)) {
            $piece = ['Type' => 'ride', 'From' => $l['From'], 'To' => $l['To'], 'LineUID' => $l['LineUID'], 'System' => $l['System']];
            if ($k >= 0 && $out[$k]['Type'] === 'ride' && $out[$k]['LineUID'] === $l['System'] && $out[$k]['To'] === $l['From']) {
                $out[$k]['To'] = $l['To'];
                $out[$k]['Path'][] = $piece;
                continue;
            }
            $out[] = ['Type' => 'ride', 'From' => $l['From'], 'To' => $l['To'], 'LineUID' => $l['System'], 'Class' => $l['Class'] ?? null,
                      'System' => $l['System'], 'Through' => false, 'Path' => [$piece]];
            continue;
        }
        $out[] = $l;
    }
    return $out;
}

/** 路線串（Path）的里程（沿途站距；缺資料當很遠） */
function appPathKm(array $m, array $path)
{
    $km = 0.0;
    foreach ($path as $pl) {
        $st = appRideStations($m, $pl);
        $d = $st === null ? null : transferRideDistance($pl['System'], $st)[0];
        $km += $d ?? 9999.0;
    }
    return $km;
}

/** 搭乘段經過的路線段落：系統直達段用 Path，一般搭乘段就是自己 */
function appLegPath(array $l)
{
    return $l['Path'] ?? [['Type' => 'ride', 'From' => $l['From'], 'To' => $l['To'], 'LineUID' => $l['LineUID'], 'System' => $l['System']]];
}

// ================================================================
// 2. 同系統快慢車展開

/**
 * 把連續的同系統搭乘段（臺鐵、高鐵、機捷）視為一段旅程，取得沿途車站與每一站往下一站的路線
 * 回傳 ['stations' => [...], 'lines' => [第 i 站往第 i+1 站的路線, ...]]
 */
function appSegmentPath(array $m, array $legs)
{
    $st = [];
    $ln = [];
    foreach ($legs as $leg) {
        $seq = $m['lineStations'][$leg['LineUID']];
        $ia = array_search($leg['From'], $seq, true);
        $ib = array_search($leg['To'], $seq, true);
        $slice = $ia <= $ib ? array_slice($seq, $ia, $ib - $ia + 1) : array_reverse(array_slice($seq, $ib, $ia - $ib + 1));
        if ($st && end($st) === $slice[0]) {
            array_shift($slice);
        }
        foreach ($slice as $u) {
            if ($st) {
                $ln[] = $leg['LineUID'];
            }
            $st[] = $u;
        }
    }
    return ['stations' => $st, 'lines' => $ln];
}

// ================================================================
// 3、4. 排除、估計、排序

/** 路由的轉乘次數：搭乘段數 − 1，不算交會站直通 */
function appRouteTransfers(array $legs)
{
    $n = 0;
    foreach ($legs as $l) {
        if ($l['Type'] === 'ride' && empty($l['Through'])) {
            $n++;
        }
    }
    return max(0, $n - 1);
}

/** 估計分鐘：各段行駛（依等級）＋步行＋同站換車；估不出回 null */
function appRouteEstimate(array $m, array &$legs)
{
    $total = 0.0;
    $prev = null;
    foreach ($legs as &$l) {
        if ($l['Type'] === 'walk') {
            $w = transferWalk($m, $l['From'], $l['To'], true, true);
            if ($w === null) {
                return null;
            }
            $l['Minutes'] = $l['Minutes'] ?? $w['Minutes'];
            $l['DataMinutes'] = $w['Minutes'];
            $l['Source'] = $w['Source'];
            $total += $l['Minutes'];
        } else {
            $t = appLegMinutes($l['LineUID'], $l['From'], $l['To'], $l['Class']);
            if ($t === null && isset($l['Path']) && count($l['Path']) > 1) {
                // 系統直達段沒有直達車（基隆→臺東）：用經過的路線段加總估計，實際由補充資料（交會站換車）串出班次
                $t = 0.0;
                foreach ($l['Path'] as $pl) {
                    $x = appLegMinutes($l['LineUID'], $pl['From'], $pl['To'], $l['Class']);
                    if ($x === null) {
                        $t = null;
                        break;
                    }
                    $t += $x;
                }
            }
            if ($t === null) {
                return null;
            }
            $l['EstimatedMinutes'] = $t;
            $total += $t;
            if ($prev !== null && $prev['Type'] === 'ride' && empty($l['Through'])) {
                $total += transferWalk($m, $l['From'], $l['From'])['Minutes'] ?? 3;
            }
        }
        $prev = $l;
    }
    unset($l);
    return round($total, 1);
}

/**
 * 搭乘段沿途的車站（含上下車站，依行進順序；有快取）。依路線的營運路線站序（appLinePaths）找同時有兩站的那一條，
 * 取最短的一段；有支線的路線（中和新蘆線蘆洲支線、淡水信義線新北投支線、松山新店線小碧潭支線）跨支線不能直接搭，回 null
 */
function appRideStations(array $m, array $l)
{
    static $cache = [];
    $k = $l['LineUID'] . '|' . $l['From'] . '|' . $l['To'];
    if (array_key_exists($k, $cache)) {
        return $cache[$k];
    }
    $best = null;
    foreach (appLinePaths($m, $l['LineUID']) as $seq) {
        // 環狀（首末站相同）：兩個方向都可以跨過首站繞，取比較短的一邊
        $n = count($seq) - 1;
        if ($n > 2 && $seq[0] === $seq[$n]) {
            $ring = array_slice($seq, 0, $n);
            $ia = array_search($l['From'], $ring, true);
            $ib = array_search($l['To'], $ring, true);
            if ($ia !== false && $ib !== false && $ia !== $ib) {
                foreach ([1, -1] as $step) {
                    $arc = [];
                    for ($i = $ia; ; $i = ($i + $step + $n) % $n) {
                        $arc[] = $ring[$i];
                        if ($i === $ib) {
                            break;
                        }
                    }
                    if ($best === null || count($arc) < count($best)) {
                        $best = $arc;
                    }
                }
            }
            continue;
        }
        $ia = array_keys($seq, $l['From'], true);
        $ib = array_keys($seq, $l['To'], true);
        foreach ($ia as $i) {
            foreach ($ib as $j) {
                if ($i === $j) {
                    continue;
                }
                if ($best === null || abs($j - $i) + 1 < count($best)) {
                    $best = $i < $j ? array_slice($seq, $i, $j - $i + 1) : array_reverse(array_slice($seq, $j, $i - $j + 1));
                }
            }
        }
    }
    return $cache[$k] = $best;
}

/**
 * 路線可以一路搭下去的站序：捷運、輕軌用營運路線（metro_travel 的路線＋方向 0，去掉重複）；沒有資料的（臺鐵、高鐵、起訖矩陣的系統）用路線站序。有快取
 */
function appLinePaths(array $m, $lu)
{
    static $paths = null;
    if ($paths === null) {
        $paths = [];
        $tmp = [];
        foreach (appDb()->all('SELECT system, line_id, route_id, direction, train_type, station_uid FROM metro_travel ORDER BY system, line_id, route_id, direction, train_type, seq') as $r) {
            $tmp[$r['system'] . '-' . $r['line_id']][$r['route_id'] . '|' . $r['direction'] . '|' . $r['train_type']][] = $r['station_uid'];
        }
        foreach ($tmp as $line => $list) {
            $seen = [];
            foreach ($list as $seq) {
                $key = implode(',', $seq);
                $rev = implode(',', array_reverse($seq));
                if (count($seq) < 2 || isset($seen[$key]) || isset($seen[$rev])) {
                    continue;
                }
                $seen[$key] = true;
                $paths[$line][] = $seq;
            }
        }
    }
    return $paths[$lu] ?? [$m['lineStations'][$lu] ?? []];
}

/** 有支線的路線在分岔站同站換車：分岔站清單（兩條站序都有、而且其中一條的相鄰站不在另一條上）；有快取 */
function appLineJunctions(array $m, $lu)
{
    static $cache = [];
    if (isset($cache[$lu])) {
        return $cache[$lu];
    }
    $ps = appLinePaths($m, $lu);
    $out = [];
    foreach ($ps as $a => $p) {
        foreach ($ps as $b => $q) {
            if ($a === $b) {
                continue;
            }
            $inQ = array_flip($q);
            foreach ($p as $i => $u) {
                if (!isset($inQ[$u])) {
                    continue;
                }
                foreach ([$i - 1, $i + 1] as $n) {
                    if (isset($p[$n]) && !isset($inQ[$p[$n]])) {
                        $out[$u] = true;
                    }
                }
            }
        }
    }
    return $cache[$lu] = array_keys($out);
}

/** 逐段檢查排除規則的狀態（appPrefixExtend 用）；dp＝排除名單有沒有內容 */
function appPrefixState()
{
    return ['seen' => [], 'prevKey' => null, 'seg' => -1, 'segOf' => [], 'lastRide' => null, 'ban' => [],
            'dp' => (bool) appDirectPartners()];
}


/**
 * 在已走的部分後面加一段，檢查排除規則（與 appRouteRevisits 相同的規則，但只看新加的這一段）：
 * 重複經過車站（同轉乘群組視為同一站）、鐵路中途只改搭捷運又搭回同一個鐵路系統、排除名單（DirectPairs）。違反回 null，否則回新狀態
 */
function appPrefixExtend(array $m, array $st, array $l)
{
    if ($l['Type'] === 'ride') {
        // 不能直接搭（跨支線）
        if (appRideStations($m, $l) === null) {
            return null;
        }
        // 同一條路線同站換車（支線分岔站）：兩段合起來能直接搭到，就不必換
        $pr = $st['lastRide'] ?? null;
        if ($pr !== null && $pr['LineUID'] === $l['LineUID'] && $pr['To'] === $l['From']
            && appRideStations($m, ['LineUID' => $l['LineUID'], 'From' => $pr['From'], 'To' => $l['To']]) !== null) {
            return null;
        }
        $st['lastRide'] = ['LineUID' => $l['LineUID'], 'From' => $l['From'], 'To' => $l['To']];
    } else {
        $st['lastRide'] = null;
    }
    static $infoCache = [];
    // 重複經過
    foreach (appLegKeys($m, $l) as $g) {
        if ($g === $st['prevKey']) {
            continue;
        }
        if (isset($st['seen'][$g])) {
            return null;
        }
        $st['seen'][$g] = true;
        $st['prevKey'] = $g;
    }
    // 經由排除（RouteBans，設定）：同一系統連續搭乘，經過 From 範圍的站、再經過 Via 的站、又到 To 範圍的站 → 不列
    if (appRouteBans()) {
        $st['ban'] = appRouteBanStep($m, $l, $st['ban']);
        if ($st['ban'] === null) {
            return null;
        }
    }
    // 排除名單：每一段（基本路由的一條路線搭乘或一次步行）各算一段；名單上兩站都經過時，要有一段同時包含兩站
    // （也就是兩站之間直接搭同一條路線）。換線、換系統、步行、繞環島都算拆開。同站換快慢車是展開後的事，不受影響
    if ($st['dp']) {
        $st['seg']++;
        $k = $l['Type'] . '|' . ($l['LineUID'] ?? '') . '|' . $l['From'] . '|' . $l['To'];
        $info = $infoCache[$k] ?? ($infoCache[$k] = appLegPartnerInfo($m, $l) ?? false);
        if ($info !== false) {
            $c = $st['seg'];
            // segOf：站 => 出現的段落（最早 << 8 | 最晚）。已出現過的夥伴站若最晚的段落早於這一段，又是這一段某站的夥伴，
            // 就沒有任何一段同時包含兩站 → 違反（上車站 From 若之前出現過，以它最早出現的段落比較）；兩邊取比較少的逐一比對
            $seg = $st['segOf'];
            if ($seg && $info['n']) {
                $fromLo = isset($seg[$info['from']]) ? $seg[$info['from']] >> 8 : $c;
                if (count($seg) <= $info['n']) {
                    foreach ($seg as $b => $r) {
                        if ((($r & 255) < $c && isset($info['ext'][$b])) || (($r & 255) < $fromLo && isset($info['extFrom'][$b]))) {
                            return null;
                        }
                    }
                } else {
                    foreach ($info['ext'] as $b => $_) {
                        if (isset($seg[$b]) && ($seg[$b] & 255) < $c) {
                            return null;
                        }
                    }
                    foreach ($info['extFrom'] as $b => $_) {
                        if (isset($seg[$b]) && ($seg[$b] & 255) < $fromLo) {
                            return null;
                        }
                    }
                }
            }
            foreach ($info['stations'] as $u) {
                $seg[$u] = (isset($seg[$u]) ? $seg[$u] >> 8 : $c) << 8 | $c;
            }
            $st['segOf'] = $seg;
        }
    }
    return $st;
}

/**
 * 段落的排除名單資訊（appPrefixExtend 會快取）：stations＝沿途有在名單裡的車站；ext＝上車站以外各站在段落外的夥伴；extFrom＝上車站在段落外的夥伴；
 * from＝上車站；n＝ext＋extFrom 的數量。車站都換成 appPairStation（臺北-環島視同臺北）。段落沒有名單裡的車站回 null
 */
function appLegPartnerInfo(array $m, array $l)
{
    $partners = appDirectPartners();
    $all = [];
    foreach ($l['Type'] === 'ride' ? appRideStations($m, $l) : [$l['From'], $l['To']] as $u) {
        $all[appPairStation($m, $u)] = true;
    }
    $from = appPairStation($m, $l['From']);
    $out = ['stations' => [], 'ext' => [], 'extFrom' => [], 'n' => 0, 'from' => $from];
    foreach ($all as $u => $_) {
        if (!isset($partners[$u])) {
            continue;
        }
        $out['stations'][] = $u;
        foreach ($partners[$u] as $b) {
            if (!isset($all[$b])) {
                $out[$u === $from ? 'extFrom' : 'ext'][$b] = true;
            }
        }
    }
    $out['n'] = count($out['ext']) + count($out['extFrom']);
    return $out['stations'] ? $out : null;
}

/**
 * 排除名單比對用的站碼：同一轉乘群組裡同一個鐵路系統的車站視為同一站（臺鐵臺北-環島 TRA-1001 視同臺北 TRA-1000），取代碼最小的；有快取
 */
function appPairStation(array $m, $u)
{
    static $cache = [];
    if (isset($cache[$u])) {
        return $cache[$u];
    }
    $best = $u;
    $sys = substr($u, 0, strpos($u, '-'));
    if (in_array($sys, FINDER_TRAIN_SYSTEMS, true)) {
        foreach ($m['groupOf'][$u] ?? [] as $gid) {
            foreach ($m['groups'][$gid]['Stations'] as $x) {
                if ($x['System'] === $sys && strcmp($x['StationUID'], $best) < 0) {
                    $best = $x['StationUID'];
                }
            }
        }
    }
    return $cache[$u] = $best;
}

/** 排除名單的夥伴表：站 => [另一站, ...] */
function appDirectPartners()
{
    static $partners = null;
    if ($partners === null || !appRouteRulesEnabled()) {
        $p = [];
        $m = appModel();
        foreach (appDirectPairs() as list($a, $b)) {
            $a = appPairStation($m, $a);
            $b = appPairStation($m, $b);
            $p[$a][] = $b;
            $p[$b][] = $a;
        }
        if (!appRouteRulesEnabled()) {
            return $p;
        }
        $partners = $p;
    }
    return $partners;
}

/** 段落沿途車站的比較鍵（轉乘群組；連續相同的已合併；有快取） */
function appLegKeys(array $m, array $l)
{
    static $cache = [];
    $k = $l['Type'] . '|' . ($l['LineUID'] ?? '') . '|' . $l['From'] . '|' . $l['To'];
    if (isset($cache[$k])) {
        return $cache[$k];
    }
    $out = [];
    foreach ($l['Type'] === 'ride' ? appRideStations($m, $l) : [$l['From'], $l['To']] as $u) {
        $g = appStationKey($m, $u);
        if (!$out || end($out) !== $g) {
            $out[] = $g;
        }
    }
    return $cache[$k] = $out;
}

/** 車站的比較鍵：所屬轉乘群組（屬於多個群組時取代碼最小的），沒有群組用 StationUID */
function appStationKey(array $m, $u)
{
    static $cache = [];
    if (isset($cache[$u])) {
        return $cache[$u];
    }
    $g = $m['groupOf'][$u] ?? [];
    return $cache[$u] = !$g ? $u : (count($g) === 1 ? $g[0] : min($g));
}

/**
 * 不列的走法：重複經過同一個車站（同轉乘群組視為同一站）、鐵路中途只改搭捷運又搭回同一個鐵路系統
 * （臺鐵→高鐵→臺鐵不算：中間是另一個鐵路系統），以及違反人工維護的直達站對（DirectPairs）
 */
function appRouteRevisits(array $m, array $legs)
{
    $st = appPrefixState();
    foreach ($legs as $l) {
        $st = appPrefixExtend($m, $st, $l);
        if ($st === null) {
            return true;
        }
    }
    return false;
}

/**
 * 找路規則檔 transfer_route_rules.json（人工維護、受版控；查詢時直接讀）：DirectPairs（排除名單）、LineTransfers（加速清單）。
 * appRouteRulesEnabled(false) 可暫時關掉（產生器估算時用，避免規則影響規則本身的計算）
 */
function appRouteRules()
{
    static $rules = null;
    if ($rules === null) {
        $j = json_decode((string) @file_get_contents(transferDataFile('RouteRules')), true);
        $rules = ['DirectPairs' => [], 'LineTransfers' => [], 'NoTransferStations' => [], 'RouteBans' => []];
        $rules['RouteBans'] = appCompileRouteBans(is_array($j) ? ($j['RouteBans'] ?? []) : []);
        $rules['RoutePatterns'] = appCompileRoutePatterns(is_array($j) ? ($j['RouteBans'] ?? []) : []);
        foreach (is_array($j) ? ($j['NoTransferStations'] ?? []) : [] as $x) {
            if (empty($x['Disabled']) && !empty($x['Station'])) {
                $rules['NoTransferStations'][$x['Station']] = $x['AliasOf'] ?? true;   // AliasOf：代表的車站（同一個車站的另一個站碼）
            }
        }
        $rules['Search'] = (is_array($j) ? ($j['Search'] ?? []) : []) + APP_ROUTE_SEARCH_DEFAULTS;
        foreach (is_array($j) ? ($j['DirectPairs'] ?? []) : [] as $x) {
            if (empty($x['Disabled']) && isset($x['Stations'][0], $x['Stations'][1])) {
                $rules['DirectPairs'][] = [$x['Stations'][0], $x['Stations'][1]];
            }
        }
        foreach (is_array($j) ? ($j['LineTransfers'] ?? []) : [] as $x) {
            if (empty($x['Disabled']) && isset($x['Lines'][0], $x['Lines'][1]) && is_array($x['Groups'] ?? null)) {
                $k = $x['Lines'][0] < $x['Lines'][1] ? $x['Lines'][0] . '|' . $x['Lines'][1] : $x['Lines'][1] . '|' . $x['Lines'][0];
                $rules['LineTransfers'][$k] = array_flip($x['Groups']);
            }
        }
    }
    return appRouteRulesEnabled() ? $rules : ['DirectPairs' => [], 'LineTransfers' => [], 'RouteBans' => [], 'RoutePatterns' => [],
                                              'NoTransferStations' => $rules['NoTransferStations'], 'Search' => $rules['Search']];
}

/**
 * 經由排除（RouteBans）編譯：每條規則 From、To 是範圍清單 [{Line, StationRange?}, ...]（To 省略＝同 From），Via 是車站清單。
 * 回傳 ['rules' => [['System', ...], ...], 'index' => 站 => [[規則, 角色位元(1=From,2=Via,4=To)], ...]]
 */
function appCompileRouteBans(array $list)
{
    $m = appModel();
    $rules = [];
    $index = [];
    foreach ($list as $x) {
        if (!empty($x['Disabled']) || empty($x['From']) || empty($x['Via']) || !empty($x['Transfers'])) {
            continue;   // Transfers（轉乘走法）的規則另外編譯（appCompileRoutePatterns）
        }
        $from = appRouteBanScope($m, $x['From']);
        $to = appRouteBanScope($m, $x['To'] ?? $x['From']);
        if (!$from || !$to) {
            continue;
        }
        $first = $x['From'][0]['Line'] ?? '';
        $ri = count($rules);
        $rules[] = ['System' => substr($first, 0, strpos($first, '-')), 'Any' => !empty($x['AnySystem'])];
        $role = [];
        foreach ($from as $u => $_) {
            $role[$u] = ($role[$u] ?? 0) | 1;
        }
        foreach ($x['Via'] as $u) {
            $role[$u] = ($role[$u] ?? 0) | 2;
        }
        foreach ($to as $u => $_) {
            $role[$u] = ($role[$u] ?? 0) | 4;
        }
        foreach ($role as $u => $r) {
            $index[$u][] = [$ri, $r];
        }
    }
    return $rules ? ['rules' => $rules, 'index' => $index] : [];
}

/**
 * 範圍清單 → 車站集合。清單的每一項可以是（多種寫法混用，一起進同一個過濾）：
 *   "TRTC-G19"                        單一車站（StationUID）
 *   {"Stations": ["TRA-0990", ...]}   車站清單
 *   {"Regex": "^TRTC-G0[1-9]$"}       StationUID 符合正規表示式的車站（例如從 railtime 的 bypassStationReg 改寫）
 *   {"Line": "TRA-WL", "StationRange": [A, B]}  路線（必填）上 A 到 B 之間（含）的車站；StationRange 選填（省略＝整條路線）：
 *       一般路線取兩站之間（不分先後）；環狀線（營運路線首末站相同，例如高雄輕軌）依路線站序從 A 往後數到 B（跨過終點接回起點），
 *       所以兩站的先後決定是哪一邊的弧，要另一邊就把兩站對調
 */
function appRouteBanScope(array $m, array $items)
{
    $out = [];
    foreach ($items as $it) {
        if (is_string($it)) {
            $out[$it] = true;
            continue;
        }
        if (isset($it['Stations']) && is_array($it['Stations'])) {
            foreach ($it['Stations'] as $u) {
                $out[$u] = true;
            }
            continue;
        }
        if (isset($it['Regex'])) {
            $re = '/' . str_replace('/', '\/', (string) $it['Regex']) . '/';
            foreach (array_keys($m['names']) as $u) {
                if (@preg_match($re, $u)) {
                    $out[$u] = true;
                }
            }
            continue;
        }
        $seq = $m['lineStations'][$it['Line'] ?? ''] ?? null;
        if (!$seq) {
            continue;
        }
        $range = $it['StationRange'] ?? null;
        if (!is_array($range) || count($range) < 2) {
            foreach ($seq as $u) {
                $out[$u] = true;
            }
            continue;
        }
        $ia = array_search($range[0], $seq, true);
        $ib = array_search($range[count($range) - 1], $seq, true);
        if ($ia === false || $ib === false) {
            continue;
        }
        $circle = false;
        foreach (appLinePaths($m, $it['Line']) as $p) {
            $circle = $circle || (count($p) > 2 && $p[0] === $p[count($p) - 1]);
        }
        if ($circle) {
            $n = count($seq);
            for ($i = $ia; ; $i = ($i + 1) % $n) {
                $out[$seq[$i]] = true;
                if ($i === $ib) {
                    break;
                }
            }
        } else {
            for ($i = min($ia, $ib); $i <= max($ia, $ib); $i++) {
                $out[$seq[$i]] = true;
            }
        }
    }
    return $out;
}

/**
 * 轉乘走法排除（RouteBans 裡帶 Transfers 的規則，例如從 railtime 的 bypassStationReg 改寫）：
 *   Transfers  依序的轉乘站 [範圍清單, ...]（每一項是一個轉乘點，同轉乘群組視為同一站）；整條路由的轉乘站串（正向或反向）
 *              與它完全相同才排除
 *   Ends       （選填）只在起訖符合時生效：Pair [A, B]＝起站在 A、迄站在 B（或反過來）；Any＝起站或迄站在清單內；All＝兩站都在
 * 範圍清單的寫法同 appRouteBanScope。編譯成 [['Ends' => [...群組集合], 'Transfers' => [群組集合, ...]], ...]
 */
function appCompileRoutePatterns(array $list)
{
    $m = appModel();
    $keys = function (array $items) use ($m) {
        $out = [];
        foreach (array_keys(appRouteBanScope($m, $items)) as $u) {
            $out[appStationKey($m, $u)] = true;
        }
        return $out;
    };
    $out = [];
    foreach ($list as $x) {
        if (!empty($x['Disabled']) || empty($x['Transfers']) || !is_array($x['Transfers'])) {
            continue;
        }
        $tr = [];
        foreach ($x['Transfers'] as $t) {
            $tr[] = $keys(is_array($t) && !isset($t['Line']) && !isset($t['Regex']) && !isset($t['Stations']) ? $t : [$t]);
        }
        $ends = [];
        foreach (['Any', 'All'] as $k) {
            if (!empty($x['Ends'][$k])) {
                $ends[$k] = $keys($x['Ends'][$k]);
            }
        }
        if (!empty($x['Ends']['Pair'][0]) && !empty($x['Ends']['Pair'][1])) {
            $ends['Pair'] = [$keys($x['Ends']['Pair'][0]), $keys($x['Ends']['Pair'][1])];
        }
        $out[] = ['Ends' => $ends, 'Transfers' => $tr];
    }
    return $out;
}

/** 這組起訖要檢查的轉乘走法排除（Ends 符合的） */
function appRoutePatternsFor(array $m, $from, $to)
{
    $a = appStationKey($m, $from);
    $b = appStationKey($m, $to);
    $out = [];
    foreach (appRouteRules()['RoutePatterns'] ?? [] as $p) {
        $e = $p['Ends'];
        if ((isset($e['Any']) && !isset($e['Any'][$a]) && !isset($e['Any'][$b]))
            || (isset($e['All']) && !(isset($e['All'][$a]) && isset($e['All'][$b])))
            || (isset($e['Pair']) && !((isset($e['Pair'][0][$a]) && isset($e['Pair'][1][$b])) || (isset($e['Pair'][0][$b]) && isset($e['Pair'][1][$a]))))) {
            continue;
        }
        // 轉乘點就是起站或迄站本身（例如臺北站出發換捷運）：本站算步行、走法已經不是規則寫的那一種，這條規則不用
        foreach ($p['Transfers'] as $set) {
            if (isset($set[$a]) || isset($set[$b])) {
                continue 2;
            }
        }
        $out[] = $p['Transfers'];
    }
    return $out;
}

/** 完整路由是否符合某條轉乘走法排除：轉乘點（下車站、上車站的群組）串與規則相同（正向或反向） */
function appRoutePatternHit(array $m, array $legs, array $patterns)
{
    if (!$patterns) {
        return false;
    }
    $rides = array_values(array_filter($legs, function ($l) { return $l['Type'] === 'ride'; }));
    $pts = [];
    for ($i = 0; $i + 1 < count($rides); $i++) {
        if (empty($rides[$i + 1]['Through'])) {
            $pts[] = [appStationKey($m, $rides[$i]['To']), appStationKey($m, $rides[$i + 1]['From'])];
        }
    }
    if (!$pts) {
        return false;   // 不轉乘的路由（同一路線直達）一定列出，不受轉乘走法排除影響
    }
    foreach ($patterns as $pat) {
        if (count($pat) !== count($pts)) {
            continue;
        }
        foreach ([$pat, array_reverse($pat)] as $p) {
            $hit = true;
            foreach ($p as $i => $set) {
                if (!isset($set[$pts[$i][0]]) && !isset($set[$pts[$i][1]])) {
                    $hit = false;
                    break;
                }
            }
            if ($hit) {
                return true;
            }
        }
    }
    return false;
}

/** 經由排除規則（編譯後；沒有規則回空陣列） */
function appRouteBans()
{
    return appRouteRules()['RouteBans'];
}

/**
 * 經由排除的狀態推進（appPrefixExtend 用）。$ban＝各規則的階段（0 省略；1＝經過 From 範圍的站、2＝之後經過 Via 的站），
 * 在 2 又到 To 範圍的站回 null。步行或換系統（不是該規則系統的搭乘）時規則重來；AnySystem 的規則看整條路由（跨系統繞回同一條線），
 * 不重來、任何系統的搭乘都算。每段、每個起始狀態有快取
 */
function appRouteBanStep(array $m, array $l, array $ban)
{
    static $cache = [];
    $cfg = appRouteBans();
    $sys = $l['Type'] === 'ride' ? $l['System'] : null;
    // 不是搭乘、或換了系統：那些規則重來（AnySystem 的規則看整條路由，不重來）
    foreach ($ban as $ri => $p) {
        if (!$cfg['rules'][$ri]['Any'] && $cfg['rules'][$ri]['System'] !== $sys) {
            unset($ban[$ri]);
        }
    }
    if ($sys === null) {
        return $ban;
    }
    ksort($ban);
    $k = $l['LineUID'] . '|' . $l['From'] . '|' . $l['To'] . '|' . http_build_query($ban);
    if (array_key_exists($k, $cache)) {
        return $cache[$k];
    }
    $p = $ban;
    foreach (appRideStations($m, $l) ?? [] as $u) {
        foreach ($cfg['index'][$u] ?? [] as list($ri, $role)) {
            if (!$cfg['rules'][$ri]['Any'] && $cfg['rules'][$ri]['System'] !== $sys) {
                continue;
            }
            $cur = $p[$ri] ?? 0;
            if ($cur === 2 && ($role & 4)) {
                return $cache[$k] = null;
            }
            if ($cur === 1 && ($role & 2)) {
                $p[$ri] = 2;
            } elseif ($cur === 0 && ($role & 1)) {
                $p[$ri] = 1;
            }
        }
    }
    return $cache[$k] = $p;
}

/** 找路規則開關（預設開） */
function appRouteRulesEnabled($set = null)
{
    static $on = true;
    if ($set !== null) {
        $on = (bool) $set;
    }
    return $on;
}

/** 排除名單：[[站A, 站B], ...]（不分方向） */
function appDirectPairs()
{
    return appRouteRules()['DirectPairs'];
}

/** 搜尋範圍參數（transfer_route_rules.json 的 Search；沒寫的用 APP_ROUTE_SEARCH_DEFAULTS）。規則關掉時照樣適用 */
function appRouteSearch()
{
    return appRouteRules()['Search'];
}

/** 不當轉乘站的車站：StationUID => AliasOf 或 true（起訖本身是它時照用；規則關掉時仍生效，它是資料上的別名站，不是找路的取捨） */
function appNoTransferStations()
{
    return appRouteRules()['NoTransferStations'];
}

/** 加速清單：兩條路線之間可以用的轉乘群組；沒有規則回 null（不限制） */
function appLineTransferGroups($a, $b)
{
    $k = $a < $b ? $a . '|' . $b : $b . '|' . $a;
    return appRouteRules()['LineTransfers'][$k] ?? null;
}

/**
 * 路由里程：每個搭乘段沿路線上的車站，相鄰兩站取路網最短里程加總（transfer 的里程資料：臺鐵、捷運用 TDX 累計里程，高鐵用座標推算）；
 * 步行不算。各段的公里數寫回 $legs[i]['DistanceKm']；任何一段算不出來回 null
 */
function appRouteDistance(array $m, array &$legs)
{
    $total = 0.0;
    $src = 'tdx';
    foreach ($legs as &$l) {
        if ($l['Type'] !== 'ride') {
            continue;
        }
        $km = 0.0;
        $ks = 'tdx';
        foreach (appLegPath($l) as $pl) {
            $slice = appRideStations($m, $pl);
            if ($slice === null) {
                return null;
            }
            list($pk, $ps) = transferRideDistance($l['System'], $slice);
            if ($pk === null) {
                return null;
            }
            $km += $pk;
            $ks = $ps === 'estimate' ? 'estimate' : $ks;
        }
        $km = round($km, 1);
        $l['DistanceKm'] = $km;
        $total += $km;
        if ($ks === 'estimate') {
            $src = 'estimate';
        }
    }
    unset($l);
    return [round($total, 1), $src];
}

/** 路由有沒有經過某站（上下車站或沿途） */
function appRouteVia(array $m, array $legs, $via)
{
    foreach ($legs as $l) {
        if ($l['From'] === $via || $l['To'] === $via) {
            return true;
        }
        if ($l['Type'] === 'ride') {
            foreach (appLegPath($l) as $pl) {
                if (in_array($via, appRideStations($m, $pl) ?? [], true)) {
                    return true;
                }
            }
        }
    }
    return false;
}

/** 營運業者保底時，主幹再依車種等級分開保底的系統（臺鐵：對號、非對號各要一條） */
const APP_ROUTE_COVER_CLASS_SYSTEMS = ['TRA'];

/**
 * 路由的營運業者鍵（保底用）：
 *   op:<系統>      每個搭乘段的系統（TRTC、NTMC、TRA、THSR…）
 *   main:<系統>    主幹：行駛分鐘加總最多的系統（跨縣市時是臺鐵或高鐵）
 *   main:TRA.express／main:TRA.local  主幹是臺鐵時：可搭對號（有對號段，或不限車種的直達段：長途直達車就是對號列車）／
 *                  全程非對號（區間車接力）
 */
function appRouteCoverKeys(array $legs)
{
    $keys = [];
    $mins = [];
    $classes = [];
    foreach ($legs as $l) {
        if ($l['Type'] !== 'ride') {
            continue;
        }
        $sys = $l['System'] ?? strtok($l['LineUID'], '-');
        $keys['op:' . $sys] = true;
        $mins[$sys] = ($mins[$sys] ?? 0) + (float) ($l['EstimatedMinutes'] ?? 0);
        $classes[$sys][] = $l['Class'] ?? null;
    }
    if ($mins) {
        arsort($mins);
        $main = array_key_first($mins);
        $keys['main:' . $main] = true;
        if (in_array($main, APP_ROUTE_COVER_CLASS_SYSTEMS, true)) {
            $allLocal = array_unique($classes[$main]) === [APP_CLASS_LOCAL];
            $keys['main:' . $main . '.' . ($allLocal ? APP_CLASS_LOCAL : APP_CLASS_EXPRESS)] = true;
        }
    }
    return array_keys($keys);
}

/**
 * 可以拿來保底的路由：捷運、輕軌不能夾在兩段臺鐵／高鐵中間（例如臺鐵到臺中、換臺中捷運再搭高鐵南下），
 * 這種刻意下車換捷運再上車的走法不拿來補，它的業者也不算「應該要有」
 */
function appRouteCoverEligible(array $legs)
{
    $seenTrain = false;
    $metroAfterTrain = false;
    foreach ($legs as $l) {
        if ($l['Type'] !== 'ride') {
            continue;
        }
        $train = in_array($l['System'] ?? strtok($l['LineUID'], '-'), FINDER_TRAIN_SYSTEMS, true);
        if ($train && $metroAfterTrain) {
            return false;
        }
        if ($train) {
            $seenTrain = true;
        } elseif ($seenTrain) {
            $metroAfterTrain = true;
        }
    }
    return true;
}

/**
 * 營運業者保底：$items 是排好序的候選，先取前 $limit 個；所有可保底的候選（appRouteCoverEligible）出現過、
 * 前 $limit 個裡卻沒有的業者鍵（appRouteCoverKeys），各補一條該鍵最好的路由（$better 比較，小的好），
 * 擠掉清單最後一個「拿掉也不會讓任何鍵消失」的路由；重複到每個鍵都有為止。
 * $limit 為 null 時取全部已選的、只補不擠（展開前的挑選用，$picked 是已選的索引）。
 * $legsOf 從候選取出段落。回傳選中的索引（依原本順序）
 */
function appRouteCoverPick(array $items, $limit, callable $legsOf, callable $better, array $picked = null)
{
    $keys = [];
    $ok = [];
    foreach ($items as $i => $it) {
        $keys[$i] = appRouteCoverKeys($legsOf($it));
        $ok[$i] = appRouteCoverEligible($legsOf($it));
    }
    $need = [];
    foreach ($items as $i => $it) {
        if ($ok[$i]) {
            foreach ($keys[$i] as $k) {
                $need[$k] = true;
            }
        }
    }
    $sel = $picked ?? array_slice(array_keys($items), 0, $limit);
    $sel = array_fill_keys($sel, true);
    $count = function () use (&$sel, $keys) {
        $c = [];
        foreach (array_keys($sel) as $i) {
            foreach ($keys[$i] as $k) {
                $c[$k] = ($c[$k] ?? 0) + 1;
            }
        }
        return $c;
    };
    $fixed = [];   // 補進來的不再被擠掉
    for ($guard = count($need); $guard > 0; $guard--) {
        $have = $count();
        $missing = array_values(array_diff(array_keys($need), array_keys($have)));
        if (!$missing) {
            break;
        }
        sort($missing);
        $best = null;
        foreach ($items as $i => $it) {
            if (!$ok[$i] || isset($sel[$i]) || !in_array($missing[0], $keys[$i], true)) {
                continue;
            }
            if ($best === null || $better($it, $items[$best]) < 0) {
                $best = $i;
            }
        }
        if ($best === null) {
            unset($need[$missing[0]]);
            continue;
        }
        if ($limit !== null && count($sel) >= $limit) {
            // 從後面找一個拿掉後每個鍵都還有的（補進來的那條會補上它缺的鍵，所以先算進去）
            foreach (array_reverse(array_keys($sel)) as $i) {
                if (isset($fixed[$i])) {
                    continue;
                }
                $after = $have;
                foreach ($keys[$i] as $k) {
                    $after[$k]--;
                }
                foreach ($keys[$best] as $k) {
                    $after[$k] = ($after[$k] ?? 0) + 1;
                }
                $lost = false;
                foreach ($keys[$i] as $k) {
                    if (isset($need[$k]) && $after[$k] < 1) {
                        $lost = true;
                        break;
                    }
                }
                if (!$lost) {
                    unset($sel[$i]);
                    break;
                }
            }
        }
        $sel[$best] = true;
        $fixed[$best] = true;
    }
    $out = array_keys($sel);
    sort($out);
    return $out;
}

/**
 * 核心 A。$opt：extra、max_transfers（null 不限）、via（StationUID）、limit。
 * 回傳路由清單：[['legs' => 段落, 'route' => 代碼, 'TransferCount', 'EstimatedMinutes'], ...]，依轉乘次數、估計分鐘排序
 */
function appCoreRoutes(array $m, $from, $to, array $opt)
{
    $extra = $opt['extra'] ?? 0;
    $cfg = appRouteSearch();
    // 起訖是別名站（臺北-環島）：直接當它代表的車站（臺北）查
    $alias = appNoTransferStations();
    foreach (['from', 'to'] as $v) {
        if (is_string($alias[$$v] ?? null)) {
            $$v = $alias[$$v];
        }
    }
    if ($from === $to) {
        return [];
    }
    // 基本路由：系統直達段不分路線，經不同路線的同一走法（臺鐵北迴、南迴）合成一條，各自的路線串放進 Paths
    $byCode = [];
    foreach (appNetworkRoutes($m, $from, $to, $extra, $opt['deeper'] ?? $cfg['Deeper']) as $legs) {
        $code = appRouteFormat(['Legs' => $legs]);
        if (!isset($byCode[$code])) {
            foreach ($legs as &$l) {
                if (isset($l['Path'])) {
                    $l['Paths'] = [$l['Path']];
                }
            }
            unset($l);
            $byCode[$code] = $legs;
            continue;
        }
        foreach ($legs as $i => $l) {
            if (isset($l['Path']) && !in_array($l['Path'], $byCode[$code][$i]['Paths'], true)) {
                $byCode[$code][$i]['Paths'][] = $l['Path'];
            }
        }
    }
    // 有好幾種路線串時，主要的（Path，補充資料與里程用）取里程最短的，排在 Paths 第一個
    foreach ($byCode as &$legs) {
        foreach ($legs as &$l) {
            if (count($l['Paths'] ?? []) > 1) {
                usort($l['Paths'], function ($a, $b) use ($m) { return appPathKm($m, $a) <=> appPathKm($m, $b); });
                $l['Path'] = $l['Paths'][0];
            }
        }
        unset($l);
    }
    unset($legs);
    $base = array_values($byCode);
    // 先依基本估計取前幾個再展開
    $scored = [];
    $noEst = [];
    foreach ($base as $legs) {
        // 基本路由在 appNetworkRoutes 逐段組合時已經過排除規則（重複經過、排除名單），這裡不必再檢查
        $est = appRouteEstimate($m, $legs);
        if ($est !== null) {
            $scored[] = [appRouteTransfers($legs), $est, $legs];
        } else {
            $noEst[] = $legs;   // 沒有直達車（例如枋山→東海）：本身串不出班次，但補充資料（區間車接力）可能可以
        }
    }
    // 展開前的篩選用「估計分鐘＋每次轉乘 10 分」，避免轉乘少但繞遠的走法擠掉快的走法
    usort($scored, function ($a, $b) { return $a[1] + 10 * $a[0] <=> $b[1] + 10 * $b[0]; });
    $out = [];
    // 取前 NetworkLimit 個展開；不轉乘的（同一路線或同一鐵路系統直通）一律保留：最少轉乘的選項永遠要有。
    // 營運業者保底：前 NetworkLimit 個沒有、但其他基本路由有的業者（例如高鐵走法擠掉了臺鐵南下），各補最好的一條
    $pickIdx = [];
    foreach ($scored as $i => $x) {
        if ($i < $cfg['NetworkLimit'] || $x[0] === 0) {
            $pickIdx[] = $i;
        }
    }
    $pickIdx = appRouteCoverPick($scored, null, function ($x) { return $x[2]; }, function ($a, $b) {
        return [$a[0], $a[1]] <=> [$b[0], $b[1]];
    }, $pickIdx);
    $pick = [];
    foreach ($pickIdx as $i) {
        $pick[] = $scored[$i];
    }
    foreach ($noEst as $legs) {
        if (appRouteTransfers($legs) === 0) {
            $pick[] = [0, null, $legs];
        }
    }
    foreach ($pick as $x) {
        // 基本路由本身＋補充資料（transfer_route_supplements.json，人工經驗：區間車接力、轉對號、快慢車換車…）每條符合的多一條
        $baseCode = appRouteFormat(['Legs' => $x[2]]);
        foreach (array_merge([['Name' => null, 'legs' => $x[2]]], appApplySupplements($m, $x[2], $extra)) as $variant) {
            $legs = $variant['legs'];
            // 路由層排除：extra 含 1 時不搭臺鐵快車
            $bad = false;
            foreach ($legs as $l) {
                if ($l['Type'] === 'ride' && !appClassAllowed($extra, $l['System'], $l['Class'] ?? APP_CLASS_LOCAL) && $l['Class'] !== null) {
                    $bad = true;
                }
            }
            if ($bad || (!empty($opt['via']) && !appRouteVia($m, $legs, $opt['via']))) {
                continue;
            }
            // 補充資料只在同一串車站上分切（沿途車站不變），基本路由已過排除規則，這裡不必再檢查
            $est = appRouteEstimate($m, $legs);
            if ($est === null) {
                continue;
            }
            $tc = appRouteTransfers($legs);
            if (isset($opt['max_transfers']) && $tc > $opt['max_transfers']) {
                continue;
            }
            $code = appRouteFormat(['Legs' => $legs]);
            if (isset($out[$code])) {
                continue;
            }
            $dist = appRouteDistance($m, $legs);
            $out[$code] = ['route' => $code, 'legs' => $legs, 'TransferCount' => $tc, 'EstimatedMinutes' => $est,
                           'DistanceKm' => $dist[0] ?? null, 'DistanceSource' => $dist[1] ?? null,
                           'Supplement' => $variant['Name'], 'BaseRoute' => $baseCode];
        }
    }
    $out = array_values($out);
    // 排序：轉乘次數 → 里程（繞遠的排後面；算不出里程的排最後）→ 估計分鐘。路由不排除繞遠的走法，真的比較快的由 journeys 依時刻挑出
    usort($out, function ($a, $b) {
        return [$a['TransferCount'], $a['DistanceKm'] ?? INF, $a['EstimatedMinutes']] <=> [$b['TransferCount'], $b['DistanceKm'] ?? INF, $b['EstimatedMinutes']];
    });
    $minKm = null;
    foreach ($out as $r) {
        if ($r['DistanceKm'] !== null && ($minKm === null || $r['DistanceKm'] < $minKm)) {
            $minKm = $r['DistanceKm'];
        }
    }
    foreach ($out as &$r) {
        $r['DistanceRatio'] = $r['DistanceKm'] !== null && $minKm ? round($r['DistanceKm'] / $minKm, 2) : null;   // 跟最短里程的路由相比
    }
    unset($r);
    $limit = $opt['limit'] ?? $cfg['DefaultLimit'];
    // 取前 limit 條時做營運業者保底（appRouteCoverPick）：每個業者、主幹（臺鐵對號／非對號、高鐵）至少一條，擠掉排在最後的
    $legsOf = function ($r) { return $r['legs']; };
    $better = function ($a, $b) {
        return [$a['TransferCount'], $a['EstimatedMinutes']] <=> [$b['TransferCount'], $b['EstimatedMinutes']];
    };
    if (empty($opt['variants'])) {
        $sel = [];
        foreach (appRouteCoverPick($out, $limit, $legsOf, $better) as $i) {
            $sel[] = $out[$i];
        }
        return $sel;
    }
    // journeys 用：取前 limit 條基本路由（同樣做業者保底），它們的補充路由一律帶上（補充路由轉乘段數多，照排序會被擠出去）
    $baseList = array_values(array_filter($out, function ($r) { return $r['Supplement'] === null; }));
    $bases = [];
    $present = [];
    foreach ($baseList as $r) {
        $present[$r['route']] = true;
    }
    foreach (appRouteCoverPick($baseList, $limit, $legsOf, $better) as $i) {
        $bases[$baseList[$i]['route']] = true;
    }
    // 本身串不出班次的直達路由（不在 $out）：它的補充路由一律帶上，跟「直達一律保留」一致
    foreach ($out as $r) {
        if (!isset($present[$r['BaseRoute']])) {
            $bases[$r['BaseRoute']] = true;
        }
    }
    return array_values(array_filter($out, function ($r) use ($bases) { return isset($bases[$r['BaseRoute']]); }));
}

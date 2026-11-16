<?php
/**
 * 組合：核心 A（路由）＋核心 B（點對點時刻表）＋串接。
 *
 *   /txapi/app_trip/routes        起訖站間轉乘路由（只用核心 A，不看時刻）
 *   /txapi/app_trip/route_trains  帶入路由，取時段內所有列車組合（每段核心 B → 串接）
 *   /txapi/app_trip/journeys      起訖站間各種轉乘列車組合（核心 A → 每段核心 B → 串接 → 分類排序）
 *
 * 串接（參考 railtime 的 bestStart／bestEnd，與 transfer/follow 相同）：每一班接下一段「到站＋轉乘分鐘」後最早到的那班；
 * 同一班車繼續坐（路線交會站直通、接力站沒換車）不算轉乘、不加分鐘；同一個銜接只留最晚出發的（互不支配）。
 * 列車層排除（extra、車種等級）在核心 B 取時刻時就做，串接才不會漏掉慢車的組合。
 */

// APP_JOURNEY_ROUTES（journeys 最多串接幾條路由）與各 limit 的預設、上限：設定項目（libs/config.json）
const APP_JOURNEY_DEFAULT_WINDOW = 120; // 舊 arrive 模式往前的抵達時段（路由回歸測試沿用）；API 的預設時段改用 appTimeWindowSpan()
const APP_SERVICE_DAY_START = 240;      // 營運日 04:00～27:59（first、last 用）
const APP_SERVICE_DAY_END = 1679;

/** 一個搭乘段在這一天的班次（核心 B），依發車排序，附「從第 i 班起最早到達」的索引與車輛索引 */
function appLegTrains(array &$d, array $leg, $extra)
{
    static $cache = [];
    $k = $d['mask'] . '|' . $leg['From'] . '|' . $leg['To'] . '|' . $leg['LineUID'] . '|' . $leg['Class'] . '|' . $extra;
    if (isset($cache[$k])) {
        return $cache[$k];
    }
    $sys = $leg['System'] ?? substr($leg['LineUID'], 0, strpos($leg['LineUID'], '-'));
    $r = appCoreDirect($d, $leg['From'], $leg['To'], ['extra' => $extra, 'class' => $leg['Class'],
                       'line' => in_array($sys, FINDER_TRAIN_SYSTEMS, true) ? null : $leg['LineUID'], 'sort' => 'depart']);
    $list = $r['rows'];
    // 串接要依發車排序（核心 B 會把環狀線繞遠方向排在後面）
    usort($list, function ($a, $b) { return [$a[0], $a[1]] <=> [$b[0], $b[1]]; });
    $min = [];
    $best = null;
    for ($i = count($list) - 1; $i >= 0; $i--) {
        if ($best === null || $list[$i][1] <= $list[$best][1]) {
            $best = $i;
        }
        $min[$i] = $best;
    }
    $byVid = [];
    foreach ($list as $i => $x) {
        $byVid[$x[3]][] = $i;
    }
    // 反向搜尋用：依到達排序的索引、到達時刻，與「到達不晚於第 k 班者中最晚發車」的索引
    $byArr = array_keys($list);
    usort($byArr, function ($a, $b) use ($list) { return [$list[$a][1], $list[$a][0]] <=> [$list[$b][1], $list[$b][0]]; });
    $arrs = [];
    $late = [];
    $best = null;
    foreach ($byArr as $k2 => $i) {
        $arrs[$k2] = $list[$i][1];
        if ($best === null || $list[$i][0] >= $list[$best][0]) {
            $best = $i;
        }
        $late[$k2] = $best;
    }
    return $cache[$k] = ['list' => $list, 'min' => $min, 'vid' => $byVid, 'arrs' => $arrs, 'late' => $late];
}

/** 到達 ≤ t 的班次中最晚發車的那班；沒有回 null */
function appLatestBy(array $L, $t)
{
    $lo = 0;
    $hi = count($L['arrs']);
    while ($lo < $hi) {
        $mid = ($lo + $hi) >> 1;
        if ($L['arrs'][$mid] <= $t) {
            $lo = $mid + 1;
        } else {
            $hi = $mid;
        }
    }
    return $lo > 0 ? $L['list'][$L['late'][$lo - 1]] : null;
}

/** 第一個發車 ≥ t 的索引 */
function appFirstAt(array $list, $t)
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

/** 同站換車的分鐘（轉乘群組的 TrainChange；沒有用系統預設） */
function appChangeMinutes(array $m, $station)
{
    if (isset($m['change'][$station])) {
        return (int) $m['change'][$station]['Minutes'];
    }
    $sys = substr($station, 0, strpos($station, '-'));
    $def = transferSeedSettings()['TrainChangeDefault'] ?? [];
    return (int) ($def[$sys] ?? $def['metro'] ?? 2);
}

/**
 * 串接一條路由：第一段在出發時段內的每一班都試一次，之後每段接「最早到」的那班（同一班車繼續坐優先）。
 * 回傳互不支配的旅程：['dep', 'arr', 'rides' => [[段落索引, 班次列], ...]]（秒）
 */
function appChainRoute(array &$d, array $legs, $extra, $startMin, $endMin)
{
    $m = $d['m'];
    $lists = [];
    foreach ($legs as $i => $leg) {
        if ($leg['Type'] === 'ride') {
            $lists[$i] = appLegTrains($d, $leg, $extra);
            if (!$lists[$i]['list']) {
                return [];
            }
        }
    }
    $firstRide = null;
    $pre = 0;
    foreach ($legs as $i => $leg) {
        if ($leg['Type'] === 'ride') {
            $firstRide = $i;
            break;
        }
        $pre += (int) round($leg['Minutes'] * 60);
    }
    if ($firstRide === null) {
        return [];
    }
    $chains = [];
    foreach ($lists[$firstRide]['list'] as $row) {
        $dep = $row[0] - $pre;
        if ($dep < $startMin * 60 || $dep > $endMin * 60 + 59) {
            continue;
        }
        $t = $row[1];
        $rides = [[$firstRide, $row]];
        $prevVid = $row[3];
        $prevRide = true;
        $ok = true;
        for ($i = $firstRide + 1, $n = count($legs); $i < $n; $i++) {
            $leg = $legs[$i];
            if ($leg['Type'] === 'walk') {
                $t += (int) round($leg['Minutes'] * 60);
                $prevVid = null;
                $prevRide = false;
                continue;
            }
            $L = $lists[$i];
            $ready = $t + ($prevRide ? appChangeMinutes($m, $leg['From']) * 60 : 0);
            $x = appFirstAt($L['list'], $ready);
            $cand = $x < count($L['list']) ? $L['list'][$L['min'][$x]] : null;
            // 同一班車繼續坐：發車不早於到站即可，不必加換車分鐘
            if ($prevVid !== null && isset($L['vid'][$prevVid])) {
                foreach ($L['vid'][$prevVid] as $vi) {
                    $same = $L['list'][$vi];
                    if ($same[0] >= $t && ($cand === null || $same[1] <= $cand[1])) {
                        $cand = $same;
                        break;
                    }
                }
            }
            if ($cand === null) {
                $ok = false;
                break;
            }
            $rides[] = [$i, $cand];
            $t = $cand[1];
            $prevVid = $cand[3];
            $prevRide = true;
        }
        if (!$ok) {
            continue;
        }
        $chains[] = ['dep' => $dep, 'arr' => $t, 'rides' => $rides];   // 終點步行已在迴圈裡加上
    }
    return appChainPareto($chains);
}

/**
 * 反向串接（抵達時刻模式、末班）：最後一段在抵達時段內的每一班都試一次，往前每段接「趕得上下一段的最晚發車」那班
 * （同一班車繼續坐優先）。回傳格式與 appChainRoute 相同
 */
function appChainRouteBack(array &$d, array $legs, $extra, $startMin, $endMin)
{
    $m = $d['m'];
    $lists = [];
    foreach ($legs as $i => $leg) {
        if ($leg['Type'] === 'ride') {
            $lists[$i] = appLegTrains($d, $leg, $extra);
            if (!$lists[$i]['list']) {
                return [];
            }
        }
    }
    $lastRide = null;
    $post = 0;
    for ($i = count($legs) - 1; $i >= 0; $i--) {
        if ($legs[$i]['Type'] === 'ride') {
            $lastRide = $i;
            break;
        }
        $post += (int) round($legs[$i]['Minutes'] * 60);
    }
    if ($lastRide === null) {
        return [];
    }
    $chains = [];
    foreach ($lists[$lastRide]['list'] as $row) {
        $arr = $row[1] + $post;
        if ($arr < $startMin * 60 || $arr > $endMin * 60 + 59) {
            continue;
        }
        $t = $row[0];
        $rides = [[$lastRide, $row]];
        $nextVid = $row[3];
        $nextRide = true;
        $ok = true;
        for ($i = $lastRide - 1; $i >= 0; $i--) {
            $leg = $legs[$i];
            if ($leg['Type'] === 'walk') {
                $t -= (int) round($leg['Minutes'] * 60);
                $nextVid = null;
                $nextRide = false;
                continue;
            }
            $L = $lists[$i];
            $cand = appLatestBy($L, $t - ($nextRide ? appChangeMinutes($m, $legs[$i + 1]['From']) * 60 : 0));
            // 同一班車繼續坐：到站不晚於下一段發車即可，不必加換車分鐘
            if ($nextVid !== null && isset($L['vid'][$nextVid])) {
                foreach (array_reverse($L['vid'][$nextVid]) as $vi) {
                    $same = $L['list'][$vi];
                    if ($same[1] <= $t && ($cand === null || $same[0] >= $cand[0])) {
                        $cand = $same;
                        break;
                    }
                }
            }
            if ($cand === null) {
                $ok = false;
                break;
            }
            $rides[] = [$i, $cand];
            $t = $cand[0];
            $nextVid = $cand[3];
            $nextRide = true;
        }
        if (!$ok) {
            continue;
        }
        $chains[] = ['dep' => $t, 'arr' => $arr, 'rides' => array_reverse($rides)];   // 起站步行已在迴圈裡扣掉
    }
    return appChainPareto($chains);
}

/** 互不支配：較晚出發、不晚到達的淘汰較早出發的；依出發排序 */
function appChainPareto(array $chains)
{
    // 同出發、同抵達時，換車少（不同車輛少）的優先
    $veh = function ($c) { return count(array_unique(array_map(function ($r) { return $r[1][3]; }, $c['rides']))); };
    usort($chains, function ($a, $b) use ($veh) { return [$b['dep'], $a['arr'], $veh($a)] <=> [$a['dep'], $b['arr'], $veh($b)]; });
    $keep = [];
    $bestArr = PHP_INT_MAX;
    foreach ($chains as $c) {
        if ($c['arr'] < $bestArr) {
            $keep[] = $c;
            $bestArr = $c['arr'];
        }
    }
    usort($keep, function ($a, $b) { return $a['dep'] <=> $b['dep']; });
    return $keep;
}

/** 旅程的搭乘（同一班車連續的段合併）：[[par, arrSec, class, 第一段索引], ...] */
function appMergeRides(array $legs, array $rides)
{
    $out = [];
    foreach ($rides as list($li, $row)) {
        $k = count($out) - 1;
        if ($k >= 0 && $out[$k]['vid'] === $row[3] && $row[2][0] !== 'pattern' && $out[$k]['par'][1] === $row[2][1]) {
            // 同一班車：上車取前一段、下車取這一段
            $par = $out[$k]['par'];
            if ($par[0] === 'trip') {
                $par[3] = $row[2][3];
            } else {
                $par[3] = $row[2][3];
            }
            $out[$k]['par'] = $par;
            $out[$k]['arr'] = $row[1];
            $out[$k]['last'] = $li;
            continue;
        }
        $out[] = ['par' => $row[2], 'arr' => $row[1], 'class' => $row[4], 'vid' => $row[3], 'first' => $li, 'last' => $li];
    }
    return $out;
}

/** 旅程輸出 */
function appJourneyOut(array &$d, array $r, array $chain, $week)
{
    $m = $d['m'];
    $legs = $r['legs'];
    $merged = appMergeRides($legs, $chain['rides']);
    $out = [];
    $byFirst = [];
    foreach ($merged as $x) {
        $byFirst[$x['first']] = $x;
    }
    $skipUntil = -1;
    $pars = [];   // 搭乘段在 Legs 的索引 → [搭乘段參考, 下車站時刻]（appJourneyStops 補途中各站時刻用）
    foreach ($legs as $i => $leg) {
        if ($i <= $skipUntil) {
            continue;
        }
        if ($leg['Type'] === 'walk') {
            $out[] = ['Type' => 'walk', 'From' => ['StationUID' => $leg['From'], 'StationName' => $m['names'][$leg['From']] ?? null],
                      'To' => ['StationUID' => $leg['To'], 'StationName' => $m['names'][$leg['To']] ?? null],
                      'Minutes' => $leg['Minutes'], 'Videos' => transferVideos($leg['From'], $leg['To'])];
            continue;
        }
        if (isset($byFirst[$i])) {
            $x = $byFirst[$i];
            $pars[count($out)] = [$x['par'], $x['arr']];
            $out[] = ['Type' => 'ride'] + appRideOut($d, $x['par'], $x['arr'], $week, $x['class']);
            $skipUntil = $x['last'];
        }
    }
    $rides = count($merged);
    return [
        'route'           => $r['route'],
        'RouteText'       => appRouteText(['Legs' => $legs], $m),
        'DepartureTime'   => transferHM($chain['dep']),
        'ArrivalTime'     => transferHM($chain['arr']),
        'DepartureMinute' => intdiv($chain['dep'], 60),
        'ArrivalMinute'   => intdiv($chain['arr'], 60),
        'TravelMinutes'   => (int) round(($chain['arr'] - $chain['dep']) / 60),
        'TransferCount'   => max(0, $rides - 1),
        'Legs'            => $out,
        '_sig'            => implode('>', array_map(function ($x) { return $x['vid']; }, $merged)),
        '_pars'           => $pars,
    ];
}

/** 方案搭幹線的分鐘：臺鐵對號（Class＝express）與高鐵的搭乘段行駛分鐘加總（同分方案的取捨用） */
function appJourneyTrunkMinutes(array $j)
{
    $t = 0;
    foreach ($j['Legs'] as $l) {
        if ($l['Type'] === 'ride' && ($l['System'] === 'THSR' || ($l['System'] === 'TRA' && ($l['Class'] ?? null) === APP_CLASS_EXPRESS))) {
            $t += $l['ArrivalMinute'] - $l['DepartureMinute'];
        }
    }
    return $t;
}

/**
 * 要輸出的方案：搭乘段補上途中各站時刻 StopTimes（上車站到下車站，含通過站，見 appRideStopTimes），並移除內部欄位 _pars。
 * 串接時每個候選方案都會產生，所以只對最後要回傳的方案做；$withStops＝false 時只移除
 */
function appJourneyStops(array &$d, array $j, $withStops = true)
{
    if ($withStops) {
        foreach ($j['_pars'] ?? [] as $i => list($par, $arr)) {
            $j['Legs'][$i]['StopTimes'] = appRideStopTimes($d, $par, $arr);
        }
    }
    unset($j['_pars']);
    return $j;
}

/** 路由清單項目的輸出 */
function appRouteItemOut(array $m, array $r)
{
    $desc = appRouteDescribe(['Legs' => $r['legs']], $m);
    foreach ($r['legs'] as $i => $l) {
        if ($l['Type'] === 'ride') {
            $desc['Legs'][$i]['EstimatedMinutes'] = $l['EstimatedMinutes'] ?? null;
            $desc['Legs'][$i]['DistanceKm'] = $l['DistanceKm'] ?? null;
            $desc['Legs'][$i]['Through'] = !empty($l['Through']);
        }
    }
    $desc['TransferCount'] = $r['TransferCount'];
    $desc['EstimatedMinutes'] = $r['EstimatedMinutes'];
    $desc['DistanceKm'] = $r['DistanceKm'];
    $desc['DistanceSource'] = $r['DistanceSource'];
    $desc['DistanceRatio'] = $r['DistanceRatio'];
    $desc['Supplement'] = $r['Supplement'] ?? null;   // 補充資料（transfer_route_supplements.json）加的路由：補充項目名稱；自動找到的是 null
    $desc['BaseRoute'] = $r['BaseRoute'] ?? null;     // 補充路由是從哪條自動路由展開的
    return $desc;
}

// ================================================================
// API

/** 起訖站參數（不能同一站） */
function appFromTo()
{
    $from = appStationParam('from');
    $to = appStationParam('to');
    if ($from === $to) {
        appFail(40020, ['param' => 'to', 'detail' => '起訖是同一站']);
    }
    return [$from, $to];
}

/**
 * 時段參數（app_trip 的 direct、route_trains、journeys 共用）：start～end（營運日 HH:MM），
 * by＝dep（出發時刻在時段內，預設）或 arr（抵達時刻在時段內）。舊參數照收：depart＝start（by=dep）、arrive＝end（by=arr）。
 * 只給一端時另一端相差「系統最長可查詢時段」（appTimeWindowSpan：QUERY_MAX_RANGE_MINUTES，不限時到營運日頭尾）；
 * 都沒給時從現在（指定別天時 04:00）起算，$allDay＝true 時改為不限（回傳 null）。
 * 範圍上限沿用 QUERY_MAX_RANGE_MINUTES（後臺身分不限；$limitRange＝false 不檢查，direct 可以查整天）。回傳 [by, start, end]（分鐘）
 */
function appTimeWindow($date, $allDay = false, $limitRange = true)
{
    $byMap = ['dep' => 'dep', 'departure' => 'dep', 'arr' => 'arr', 'arrival' => 'arr'];
    $by = appParam('by');
    if ($by !== null && !isset($byMap[$by])) {
        appFail(40020, ['param' => 'by', 'detail' => 'dep（出發時刻在時段內）或 arr（抵達時刻在時段內）']);
    }
    $by = $by === null ? null : $byMap[$by];
    $depart = appParamTime('depart');
    $arrive = appParamTime('arrive');
    if (($arrive !== null && ($by === 'dep' || $depart !== null)) || ($depart !== null && $by === 'arr')) {
        appFail(40020, ['param' => 'by', 'detail' => 'depart 是出發時段、arrive 是抵達時段，不能混用；建議改用 start、end＋by']);
    }
    $by = $by ?? ($arrive !== null ? 'arr' : 'dep');
    $start = appParamTime('start') ?? ($by === 'dep' ? $depart : null);
    $end = appParamTime('end') ?? ($by === 'arr' ? $arrive : null);
    if ($start === null && $end === null) {
        if ($allDay) {
            return [$by, null, null];
        }
        list(, $nowDate, $nowMin) = appServiceNow();
        $start = ($date === null || $date === $nowDate) && appParam('week') === null ? $nowMin : APP_SERVICE_DAY_START;
    }
    $span = appTimeWindowSpan();
    $start = $start ?? ($span === null ? APP_SERVICE_DAY_START : max(0, $end - $span));
    $end = $end ?? ($span === null ? APP_SERVICE_DAY_END : $start + $span);
    if ($end < $start) {
        appFail(40020, ['param' => 'end', 'detail' => '要晚於 start']);
    }
    $max = queryLimitSettings()['range'];
    if ($limitRange && $max > 0 && $end - $start > $max && appIdentity()['tier'] !== 'admin') {
        appError(40001, ['max' => $max, 'range' => $end - $start]);
    }
    return [$by, $start, $end];
}

/** 系統最長可查詢時段（分鐘）：QUERY_MAX_RANGE_MINUTES；不限（0）回 null（只給一端時延伸到營運日頭尾） */
function appTimeWindowSpan()
{
    $max = queryLimitSettings()['range'];
    return $max > 0 ? $max : null;
}

/** 回應 meta 的時段欄位 */
function appTimeWindowMeta($by, $start, $end)
{
    return ['by' => $by] + ($start === null ? [] : ['start' => appHM($start), 'end' => appHM($end)]);
}

/**
 * journeys 的時刻模式：start～end＋by（dep 出發時段 → depart 模式；arr 抵達時段 → arrive 模式，反向串接；見 appTimeWindow），
 * 或 first=1（當天最早能到：整個營運日出發）、last=1（當天最晚還能到：整個營運日抵達）。回傳 [mode, 時段起, 時段迄]（分鐘）
 */
function appJourneyMode($date)
{
    $timed = array_values(array_filter(['start', 'end', 'by', 'depart', 'arrive'], function ($p) { return appParam($p) !== null; }));
    $day = array_values(array_filter(['first', 'last'], function ($p) { return appParam($p) !== null; }));
    if (count($day) > 1 || ($day && $timed)) {
        appFail(40020, ['param' => implode(',', array_merge($day, $timed)), 'detail' => 'first、last 是整天，不能再給時段（start、end、by）或另一個']);
    }
    if ($day) {
        $mode = $day[0];
        if (appParam($mode) !== '1') {
            appFail(40020, ['param' => $mode, 'detail' => '帶 1']);
        }
        return [$mode, APP_SERVICE_DAY_START, APP_SERVICE_DAY_END];
    }
    list($by, $start, $end) = appTimeWindow($date);
    return [$by === 'arr' ? 'arrive' : 'depart', $start, $end];
}

function appTripRoutes()
{
    appDb();
    list($from, $to) = appFromTo();
    $m = appModel();
    $opt = ['extra' => appParamExtra(), 'max_transfers' => appParam('max_transfers') !== null ? appParamInt('max_transfers', null, 0, APP_MAX_TRANSFERS_MAX) : null,
            'via' => appParam('via') !== null ? appStationParam('via') : null, 'limit' => appParamInt('limit', min(appRouteSearch()['DefaultLimit'], APP_ROUTES_LIMIT_MAX), 1, APP_ROUTES_LIMIT_MAX), 'deeper' => appParamInt('deeper', min(appRouteSearch()['Deeper'], APP_DEEPER_MAX), 0, APP_DEEPER_MAX)];
    $t0 = microtime(true);
    $routes = appCoreRoutes($m, $from, $to, $opt);
    appSend([
        'From' => ['StationUID' => $from, 'StationName' => $m['names'][$from] ?? null],
        'To' => ['StationUID' => $to, 'StationName' => $m['names'][$to] ?? null],
        'count' => count($routes), 'routes' => array_map(function ($r) use ($m) { return appRouteItemOut($m, $r); }, $routes),
        'note' => $routes ? null : '找不到可走的路由',
    ], appExtraInfo($opt['extra']) + array_filter(['max_transfers' => $opt['max_transfers'], 'via' => $opt['via'], 'trunk' => appTrunkMeta() ?: null], function ($v) { return $v !== null; })
       + ['seconds' => round(microtime(true) - $t0, 3)]);
}

function appTripRouteTrains()
{
    $db = appDb();
    $m = appModel();
    $code = appParamRequired('route', '路由代碼，例如 TRA-0950.TRA-WL.local.TRA-1000.TRA-WL.express.TRA-3300');
    try {
        $r = appRouteValidate(appRouteParse($code), $db, $m);
    } catch (InvalidArgumentException $e) {
        appFail(40023, ['detail' => $e->getMessage()], ['route' => $code]);
    }
    $extra = appParamExtra();
    $conf = appRouteExtraConflicts($r, $extra);
    if ($conf) {
        appFail(40023, ['detail' => implode('；', $conf)], ['route' => $code]);
    }
    list($week, $date) = appParamWeek();
    list($by, $start, $end) = appTimeWindow($date);
    $t0 = microtime(true);
    $d = &appRouterData($week);
    $item = ['route' => appRouteFormat($r), 'legs' => $r['Legs']];
    // by=arr：抵達時刻在時段內（反向串接，每個抵達取最晚出發的組合）
    $chains = $by === 'arr' ? appChainRouteBack($d, $r['Legs'], $extra, $start, $end) : appChainRoute($d, $r['Legs'], $extra, $start, $end);
    $out = [];
    $withStops = appParamInt('stops', 1, 0, 1) === 1;
    foreach ($chains as $c) {
        $j = appJourneyStops($d, appJourneyOut($d, $item, $c, $week), $withStops);
        unset($j['_sig']);
        $out[] = $j;
    }
    usort($out, function ($a, $b) { return [$a['DepartureMinute'], $a['ArrivalMinute']] <=> [$b['DepartureMinute'], $b['ArrivalMinute']]; });
    appSend(['route' => $item['route'], 'RouteText' => appRouteText($r, $m), 'count' => count($out), 'journeys' => $out,
             'note' => $out ? null : '這個時段沒有串得起來的班次'],
            ['week' => $week, 'weekday' => FINDER_WEEKDAYS[$week], 'date' => $date] + appTimeWindowMeta($by, $start, $end)
            + appExtraInfo($extra) + ['seconds' => round(microtime(true) - $t0, 3)]);
}

const APP_JOURNEY_TAGS = [
    'Fastest'          => '最早到達',
    'ShortestTime'     => '最短行車時間',
    'FewestTransfers'  => '最少轉乘',
    'Direct'           => '直達',
    'FastestNoHSR'     => '不搭高鐵最快',
    'FastestNoExpress' => '不搭臺鐵對號最快',
    'FastestLocal'     => '只搭區間車最快',
];

/** search_time 模式：各排除位元（使用者的再加上這些）最快的方案的標籤；使用者自己的模式標 Fastest */
const APP_JOURNEY_MODE_TAGS = [
    1 => 'FastestNoExpress',
    2 => 'FastestNoHSR',
    3 => 'FastestLocal',
];

/** full_search 模式（研究工具）：最多算幾秒，超過就停下來回傳已找到的（meta.complete＝false） */
const APP_JOURNEY_FULL_SECONDS = 12;

/** full_search 的排除條件名稱（meta.modes） */
const APP_JOURNEY_FULL_MODES = [0 => '全搭', 1 => '不搭臺鐵對號', 2 => '不搭高鐵', 3 => '只搭區間車（不搭高鐵與臺鐵對號）'];

/** search_time 模式：串接只看這個時刻起（抵達模式：往前）幾分鐘內的班次；某條路由在這段時間一班都沒有時，那條路由放寬到系統最長查詢區間 */
const APP_JOURNEY_AT_HORIZON = 60;

/**
 * 城際多樣性保底（使用者規則 2026-11）：路由會經過臺鐵或高鐵時，結果一定附上各模式最快（最早到達；抵達時刻模式為最晚出發）的方案。
 *   模式＝使用者的排除位元再加上這裡的位元（1＝不搭臺鐵對號、2＝不搭高鐵，與 APP_EXTRA_NO_EXPRESS、APP_EXTRA_NO_HSR 相同）。
 * 保底方案從原本的演算法已串出的方案裡挑（不另外找路由）。RAPTOR（appJourneyRaptor）只跑使用者目前的模式一次，
 * 原本的演算法沒找到同樣好的就補進方案；使用者勾選該模式時，RAPTOR 就會找該模式最快的一班。
 * 要多一種模式就加一行，並在前端語言檔加 journey.tag.<標籤> 的翻譯
 */
const APP_JOURNEY_DIVERSITY = [
    'FastestNoHSR' => 2,
    'FastestLocal' => 3,
];

/**
 * 路由清單快取的版本戳：離線資料建置時間＋找路程式（app/、transfer/）、轉乘規則與產生檔、設定檔的修改時間。
 * 任何一個改了，舊的快取就不再命中（不必手動清除）
 */
function appRouteCacheStamp()
{
    static $v = null;
    if ($v === null) {
        $files = array_merge(glob(__DIR__ . '/*.php') ?: [], glob(__DIR__ . '/../transfer/*.php') ?: [], glob(TRANSFER_DATA_DIR . '/*.json') ?: [],
                             glob(TRANSFER_GEN_DIR . '/*.json') ?: [], [__DIR__ . '/../../config.json', appEnvPath('custom/libs/config.json')]);
        $mt = [];
        foreach ($files as $f) {
            $mt[] = basename($f) . ':' . (int) @filemtime($f);
        }
        $v = md5(json_encode([appDataUpdatedAt(), $mt]));
    }
    return $v;
}

/**
 * appCoreRoutes 加上檔案快取（journeys、search_time 用）：路由清單只跟起訖、找路參數與資料版本有關，跟時刻無關，
 * 同一組起訖再查時直接讀（找路通常佔一次查詢一半以上的時間）。中長途分解的 meta（appTrunkMeta）一起存、一起還原。
 * 存在 TDX_CACHE_DIR/app_routes/，最多 APP_ROUTE_CACHE_MAX 組（0＝不快取），超過時刪掉最久沒用的
 */
function appCoreRoutesCached(array $m, $from, $to, array $opt)
{
    $max = defined('APP_ROUTE_CACHE_MAX') ? (int) APP_ROUTE_CACHE_MAX : 0;
    if ($max <= 0) {
        return appCoreRoutes($m, $from, $to, $opt);
    }
    $dir = TDX_CACHE_DIR . '/app_routes';
    $extra = $opt['extra'] ?? 0;
    $file = $dir . '/' . md5(json_encode([$from, $to, $opt, appRouteCacheStamp()])) . '.ser';
    if (is_file($file)) {
        $c = @unserialize((string) @file_get_contents($file));
        if (is_array($c) && isset($c['routes'])) {
            if ($c['trunk'] !== null) {
                appTrunkMeta($c['trunk'], $extra);
            }
            @touch($file);   // 最近用過（容量滿時依修改時間刪最舊的）
            return $c['routes'];
        }
    }
    $routes = appCoreRoutes($m, $from, $to, $opt);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $tmp = $file . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, serialize(['routes' => $routes, 'trunk' => appTrunkMeta()[$extra] ?? null])) !== false) {
        @rename($tmp, $file);
    }
    if (mt_rand(1, 50) === 1) {
        $list = glob($dir . '/*.ser') ?: [];
        if (count($list) > $max) {
            usort($list, function ($a, $b) { return @filemtime($a) <=> @filemtime($b); });
            foreach (array_slice($list, 0, count($list) - (int) ($max * 0.9)) as $f) {
                @unlink($f);
            }
        }
    }
    return $routes;
}

/** 方案清單裡是否已有和 $j 同樣好的（符合排除位元 $mask、轉乘不多、同時或較晚出發、同時或較早到達；同一組班次也算） */
function appJourneyCovered(array $list, array $j, $mask)
{
    foreach ($list as $a) {
        if ($a['_sig'] === $j['_sig'] || ($a['TransferCount'] <= $j['TransferCount'] && $a['DepartureMinute'] >= $j['DepartureMinute']
            && $a['ArrivalMinute'] <= $j['ArrivalMinute'] && appJourneyAllowed($a, $mask))) {
            return true;
        }
    }
    return false;
}

/** RAPTOR 補充依排除位元 $mask 要略過的臺鐵、高鐵班次（trips 的索引 => true；每種位元算一次）。搜尋時直接略過，不複製路網資料 */
function appRaptorBan(array &$d, $week, $mask)
{
    static $cache = [];
    $k = $week . '|' . $mask;
    if (!isset($cache[$k])) {
        $ban = [];
        if ($mask) {
            static $type = null;
            if ($type === null) {
                $type = [];
                foreach ($d['db']->all('SELECT train_key, system, train_type_code FROM train') as $r) {
                    $type[$r['train_key']] = [$r['system'], $r['train_type_code']];
                }
            }
            foreach ($d['trips'] as $ti => $tr) {
                list($sys, $code) = $type[$tr['key']] ?? [null, ''];
                if (!appClassAllowed($mask, $sys, $sys === 'TRA' ? appTrainClassByType($code) : APP_CLASS_LOCAL)) {
                    $ban[$ti] = true;
                }
            }
        }
        $cache[$k] = $ban;
    }
    return $cache[$k];
}

/**
 * RAPTOR 補充（使用者規則 2026-11）：排除位元 $mask 下，出發時刻（$start）之後最早到達、或抵達時刻（$end）之前最晚出發的一班，轉成方案格式。
 * 出發時刻模式給 $bound（原本的演算法已有的最早到達秒數）時只找同時（同一分鐘）或更早到的；抵達時刻模式的 $bound 是已有的最晚出發，只找不早於它出發的；沒有就回 null。
 * 出發時刻模式往後算到的方案，再用固定路由倒回檢查（appRaptorFixedLater：同一條路由逐段改搭最晚趕得上的班次）。找不到回 null
 */
function appJourneyRaptor(array &$d, $from, $to, $week, $back, $start, $end, $mask, $bound = null)
{
    // 下界（到迄站、從起站）用完整路網算，對排除班次的模式仍然成立；排除的班次在搜尋時直接略過（tripBan），不複製路網資料
    transferLowerBounds($d, $to);
    transferLowerBounds($d, $from, true);
    $saved = [$d['tripBan'] ?? null, $d['accel'] ?? null];
    $d['tripBan'] = appRaptorBan($d, $week, $mask) ?: null;
    $d['accel'] = null;
    try {
        $j = appJourneyRaptorSearch($d, $from, $to, $back, $start, $end, $bound);
    } finally {
        list($d['tripBan'], $d['accel']) = $saved;
    }
    if ($j === null) {
        return null;
    }
    // 出發時刻模式往後算到的最快一班：固定它的路由，從最後一段往前逐段改搭最晚還趕得上的班次（同時到達、晚一點出發）
    // 出發時刻模式往後延時不超過時段結束（$end）；RAPTOR 的班次本身就在時段結束之後出發時不列（不在使用者查的時段裡）
    if (!$back && intdiv($j['_dep'], 60) > $end) {
        return null;
    }
    return (!$back ? appRaptorFixedLater($d, $j, $week, $mask, $end) : null) ?? appRaptorJourneyOut($d, $j, $week);
}

/**
 * RAPTOR 方案的倒回檢查（使用者規則 2026-11：固定路由回查法）：路由（各段起訖站、路線、步行）固定，以它的抵達分鐘當期限做反向串接
 * （appChainRouteBack：從最後一段往前取趕得上下一段的最晚班次），取最晚出發的一組轉成方案格式。不換路由（換路由才能更晚出發的，
 * 原本的演算法多半已有那個走法，最後一起排序篩選）；比反向 RAPTOR＋重算快約 30 倍。串不起來回 null
 */
function appRaptorFixedLater(array &$d, array $j, $week, $mask, $endMin = null)
{
    $legs = [];
    foreach ($j['Steps'] as $s) {
        if ($s['Type'] === 'walk') {
            $legs[] = ['Type' => 'walk', 'From' => $s['FromStationUID'], 'To' => $s['ToStationUID'], 'Minutes' => $s['Minutes'], 'MinutesGiven' => false];
        } elseif ($s['Type'] === 'ride') {
            $sys = $s['System'];
            $legs[] = ['Type' => 'ride', 'From' => $s['FromStationUID'], 'To' => $s['ToStationUID'],
                       'LineUID' => in_array($sys, FINDER_TRAIN_SYSTEMS, true) ? $sys : $s['LineUID'], 'System' => $sys, 'Class' => null, 'Through' => false];
        }
    }
    if (!$legs) {
        return null;
    }
    $am = intdiv($j['_arr'], 60);
    $pick = null;
    foreach (appChainRouteBack($d, $legs, $mask, $am, $am) as $c) {
        if ($endMin !== null && intdiv($c['dep'], 60) > $endMin) {
            continue;   // 往後延到時段結束之後的不取
        }
        if ($c['arr'] <= $j['_arr'] && ($pick === null || $c['dep'] > $pick['dep'] || ($c['dep'] === $pick['dep'] && count($c['rides']) < count($pick['rides'])))) {
            $pick = $c;
        }
    }
    if ($pick === null) {
        return null;
    }
    return appJourneyOut($d, ['route' => appRouteFormat(['Legs' => $legs]), 'legs' => $legs], $pick, $week) + ['_raptor' => true];
}

/** appJourneyRaptor 的搜尋本體（$dd 已設好排除的班次）：回傳 RAPTOR 的方案或 null */
function appJourneyRaptorSearch(array &$dd, $from, $to, $back, $start, $end, $bound)
{
    $best = function ($res, $deadline = null) {
        $pick = null;
        foreach ($res as $j) {
            if ($deadline !== null && $j['_arr'] > $deadline) {
                continue;
            }
            if ($pick === null || [$j['_arr'], $j['RideCount'], -$j['_dep']] < [$pick['_arr'], $pick['RideCount'], -$pick['_dep']]) {
                $pick = $j;
            }
        }
        return $pick;
    };
    if (!$back) {
        $t0 = $start * 60;
        // $bound：原本的演算法在這個模式已找到的最早到達（秒，分鐘取整）。RAPTOR 只找同一分鐘或更早到的（剪枝）：
        // 同時到的也放行，轉乘較少、出發較晚的可以補進來（是否比原本的好由 appJourneyCovered 判斷）；找不到就表示原本的已是最佳
        $bounds = $bound === null ? [] : array_fill(1, TRANSFER_MAX_TRIPS, $bound + 60);
        $j = $best(transferRaptor($dd, $from, $to, $t0, TRANSFER_MAX_TRIPS, $bounds));
        if ($j === null) {
            return null;
        }
        // 倒回檢查改在呼叫端用固定路由做（appRaptorFixedLater），不再反向 RAPTOR＋重算
    } else {
        // 抵達時刻模式：期限前到達、最晚出發的一班（各搭車班數取最晚出發，再從那個時刻往後算出班次）
        $deadline = $end * 60 + 59;
        // 下限：$bound（原本的演算法已有的最晚出發）時只找不早於它的；各搭車班數依序找，找到後下限提高到它（只找更晚的，同時刻留班數少的）
        $floor = max(0, $start * 60 - 6 * 3600, $bound ?? 0);
        $L = null;
        for ($k = 1; $k <= TRANSFER_MAX_TRIPS; $k++) {
            $x = transferLatestDeparture($dd, $from, $to, $deadline, $k, $L === null ? $floor : $L + 1);
            if ($x !== null && ($L === null || $x > $L)) {
                $L = $x;
            }
        }
        if ($L === null) {
            return null;
        }
        $j = $best(transferRaptor($dd, $from, $to, $L, TRANSFER_MAX_TRIPS, array_fill(1, TRANSFER_MAX_TRIPS, $deadline + 1)), $deadline);
    }
    return $j;
}

/** RAPTOR 的方案（transferRaptor）轉成 journeys 的方案格式（Legs 用 appRideOut；_sig 與原本的演算法同一種班次代碼，可以比對是不是同一組班次） */
function appRaptorJourneyOut(array &$d, array $j, $week)
{
    $m = $d['m'];
    $legs = [];
    $route = [];
    $pars = [];
    $vids = [];
    $pi = 0;
    $dep = null;
    $walk0 = 0;
    foreach ($j['Steps'] as $s) {
        if ($s['Type'] === 'walk') {
            if ($dep === null) {
                $walk0 += (int) round($s['Minutes'] * 60);
            }
            $legs[] = ['Type' => 'walk', 'From' => ['StationUID' => $s['FromStationUID'], 'StationName' => $m['names'][$s['FromStationUID']] ?? null],
                       'To' => ['StationUID' => $s['ToStationUID'], 'StationName' => $m['names'][$s['ToStationUID']] ?? null],
                       'Minutes' => $s['Minutes'], 'Videos' => transferVideos($s['FromStationUID'], $s['ToStationUID'])];
            $route[] = ['Type' => 'walk', 'From' => $s['FromStationUID'], 'To' => $s['ToStationUID'], 'Minutes' => $s['Minutes'], 'MinutesGiven' => false];
            continue;
        }
        if ($s['Type'] !== 'ride') {
            continue;   // 同站換車
        }
        list($par, $arr) = $j['_pars'][$pi++];
        $cls = appRideClass($d, $par);
        $ro = appRideOut($d, $par, $arr, $week, $cls);
        $dep = $dep ?? $ro['DepartureMinute'] * 60;
        $pars[count($legs)] = [$par, $arr];
        $legs[] = ['Type' => 'ride'] + $ro;
        $sys = $ro['System'];
        $route[] = ['Type' => 'ride', 'From' => $ro['From']['StationUID'], 'To' => $ro['To']['StationUID'],
                    'LineUID' => in_array($sys, FINDER_TRAIN_SYSTEMS, true) ? $sys : $ro['LineUID'], 'System' => $sys, 'Class' => null, 'Through' => false];
        $vids[] = $par[0] === 'trip' ? 'T:' . $d['trips'][$par[1]]['key'] : ($par[0] === 'pattern' ? 'M:' . $par[4][1] . '@' . $par[4][0] : 'R:' . $par[1] . '#' . ($par[4] ?? ''));
    }
    if (!$route || $dep === null) {
        return null;
    }
    $dep -= $walk0;
    $arr = $j['_arr'];
    $rides = count($vids);
    return [
        'route'           => appRouteFormat(['Legs' => $route]),
        'RouteText'       => appRouteText(['Legs' => $route], $m),
        'DepartureTime'   => transferHM($dep),
        'ArrivalTime'     => transferHM($arr),
        'DepartureMinute' => intdiv($dep, 60),
        'ArrivalMinute'   => intdiv($arr, 60),
        'TravelMinutes'   => (int) round(($arr - $dep) / 60),
        'TransferCount'   => max(0, $rides - 1),
        'Legs'            => $legs,
        '_sig'            => implode('>', $vids),
        '_pars'           => $pars,
        '_raptor'         => true,
    ];
}

/** 路由清單裡是否有經過臺鐵或高鐵的（城際幹線） */
function appRoutesIntercity(array $routes)
{
    foreach ($routes as $r) {
        foreach ($r['legs'] as $l) {
            if ($l['Type'] === 'ride' && in_array($l['System'] ?? strtok($l['LineUID'], '-'), FINDER_TRAIN_SYSTEMS, true)) {
                return true;
            }
        }
    }
    return false;
}

/** 方案的每一段搭乘都符合排除位元 $mask（臺鐵依實際列車的等級、高鐵整段） */
function appJourneyAllowed(array $j, $mask)
{
    foreach ($j['Legs'] as $l) {
        if ($l['Type'] === 'ride' && !appClassAllowed($mask, $l['System'], $l['Class'] ?? APP_CLASS_LOCAL)) {
            return false;
        }
    }
    return true;
}

function appTripJourneys()
{
    appDb();
    list($from, $to) = appFromTo();
    $m = appModel();
    $extra = appParamExtra();
    list($week, $date) = appParamWeek();
    if (appParam('search_time') !== null) {
        appTripJourneysAt($from, $to, $extra, $week, $date);
    }
    list($mode, $start, $end) = appJourneyMode($date);
    if (appParam('full_search') !== null) {
        appTripJourneysFull($from, $to, $extra, $week, $date, $mode, $start, $end);
    }
    $back = $mode === 'arrive' || $mode === 'last';
    $sort = appParamChoice('sort', ['arrival', 'transfers', 'duration'], $back ? 'late' : 'arrival');
    $limit = appParamInt('limit', APP_JOURNEYS_LIMIT_DEFAULT, 1, APP_JOURNEYS_LIMIT_MAX);
    $opt = ['extra' => $extra, 'max_transfers' => appParam('max_transfers') !== null ? appParamInt('max_transfers', null, 0, APP_MAX_TRANSFERS_MAX) : null,
            'via' => appParam('via') !== null ? appStationParam('via') : null, 'limit' => APP_JOURNEY_ROUTES, 'variants' => true, 'deeper' => appParamInt('deeper', min(appRouteSearch()['Deeper'], APP_DEEPER_MAX), 0, APP_DEEPER_MAX)];
    $fixed = appParam('routes') !== null ? appJourneyFixedRoutes(appParam('routes'), $extra) : null;
    $stops = appParamInt('stops', 1, 0, 1) === 1;
    list($data, $meta) = appJourneysCompute(compact('from', 'to', 'extra', 'week', 'date', 'mode', 'start', 'end', 'sort', 'limit', 'opt', 'fixed', 'stops'));
    appSend($data, $meta);
}

/**
 * journeys 的 routes 參數：逗號分隔的路由代碼（最多 APP_JOURNEY_ROUTES 條），只串接這幾條路由。
 * 每條都要從 from 到 to；回傳 appJourneysCompute 的 fixed：[['route', 'legs'], ...]
 */
function appJourneyFixedRoutes($param, $extra)
{
    $db = appDb();
    $m = appModel();
    list($from, $to) = appFromTo();
    $codes = array_values(array_unique(array_filter(array_map('trim', explode(',', $param)), 'strlen')));
    if (!$codes || count($codes) > APP_JOURNEY_ROUTES) {
        appFail(40023, ['detail' => 'routes 要給 1～' . APP_JOURNEY_ROUTES . ' 條路由代碼，以逗號分隔'], ['routes' => $param]);
    }
    // 起訖是別名站（臺北-環島）時，路由寫它代表的車站也算
    $alias = appNoTransferStations();
    $same = function ($a, $b) use ($alias) {
        return $a === $b || (is_string($alias[$a] ?? null) && $alias[$a] === $b) || (is_string($alias[$b] ?? null) && $alias[$b] === $a);
    };
    $out = [];
    foreach ($codes as $code) {
        try {
            $r = appRouteValidate(appRouteParse($code), $db, $m);
        } catch (InvalidArgumentException $e) {
            appFail(40023, ['detail' => $e->getMessage()], ['route' => $code]);
        }
        $conf = appRouteExtraConflicts($r, $extra);
        if ($conf) {
            appFail(40023, ['detail' => implode('；', $conf)], ['route' => $code]);
        }
        if (!$same($r['Stations'][0], $from) || !$same(end($r['Stations']), $to)) {
            appFail(40023, ['detail' => '路由的起訖要與 from、to 相同'], ['route' => $code]);
        }
        $out[] = ['route' => appRouteFormat($r), 'legs' => $r['Legs']];
    }
    return $out;
}

/**
 * journeys 的計算（API 與路由回歸測試共用）。$q：from、to、extra、week、date、mode（depart／arrive／first／last）、
 * start、end（營運日分鐘）、sort、limit、opt（appCoreRoutes 的選項）、fixed（選填，指定的路由：不找路，
 * 也不做跨路由的互不支配與繞遠排除，每條路由串得起來的班次都列）、stops（選填，true＝搭乘段帶途中各站時刻 StopTimes）。回傳 [data, meta]
 */
function appJourneysCompute(array $q)
{
    extract($q);
    $fixed = $q['fixed'] ?? null;
    $withStops = !empty($q['stops']);
    $m = appModel();
    $back = $mode === 'arrive' || $mode === 'last';
    $t0 = microtime(true);
    $routes = $fixed ?? appCoreRoutesCached($m, $from, $to, $opt);
    // 城際多樣性保底（APP_JOURNEY_DIVERSITY）：另外用各個排除條件找路由，用該條件取班次。
    // 一般找路依「估計時間」先挑路由，高鐵很快時臺鐵直達會被擠掉（例如汐止→愛河之心），要另外找才串得到
    $jobs = [];
    foreach ($routes as $r) {
        $jobs[$r['route'] . '|' . $extra] = [$r, $extra];
    }
    $raptorOn = $fixed === null && appRoutesIntercity($routes);
    $d = &appRouterData($week);
    // 每條路由串接，收集所有旅程（同一組班次只留一個）
    $all = [];
    foreach ($jobs as list($r, $jx)) {
        $chains = $back ? appChainRouteBack($d, $r['legs'], $jx, $start, $end) : appChainRoute($d, $r['legs'], $jx, $start, $end);
        foreach ($chains as $c) {
            $j = appJourneyOut($d, $r, $c, $week);
            if (!isset($all[$j['_sig']]) || $all[$j['_sig']]['TransferCount'] > $j['TransferCount']) {
                $all[$j['_sig']] = $j;
            }
        }
    }
    $all = array_values($all);
    // RAPTOR 補充：使用者條件下的最佳班次，原本的演算法沒找到同樣好的就加進來（一起做互不支配）
    $tr0 = microtime(true);
    $diversity = [];
    $raptor = [];
    if ($raptorOn) {
        // 原本的演算法在使用者模式已找到的最早到達：RAPTOR 只找比它更早到的
        // 抵達時刻模式：原本的演算法已找到的最晚出發，RAPTOR 的反向搜尋只找不早於它的
        $boundOf = function ($mx) use ($all, $back) {
            $b = null;
            foreach ($all as $j) {
                if (!appJourneyAllowed($j, $mx)) {
                    continue;
                }
                $v = $back ? $j['DepartureMinute'] * 60 : $j['ArrivalMinute'] * 60;
                if ($b === null || ($back ? $v > $b : $v < $b)) {
                    $b = $v;
                }
            }
            return $b;
        };
        // RAPTOR 只跑使用者目前的模式一次（沒排除＝全部最快、排除高鐵＝不含高鐵最快、排除對號＝不含對號最快、都排除＝都不含最快）；
        // 保底模式只從原本的演算法已串出的方案挑
        $raptor[$extra] = appJourneyRaptor($d, $from, $to, $week, $back, $start, $end, $extra, $boundOf($extra));
        foreach (APP_JOURNEY_DIVERSITY as $tag => $bits) {
            $mx = $extra | $bits;
            if ($mx !== $extra) {
                $diversity[$tag] = $mx;
            }
        }
        $rj = $raptor[$extra];
        if ($rj !== null && !appJourneyCovered($all, $rj, 0)) {
            $all[] = $rj;
        }
    }
    $raptorSec = round(microtime(true) - $tr0, 3);
    // 互不支配：較晚（或同時）出發、較早（或同時）到達、轉乘不多於，且至少一項較好的方案淘汰其他
    // 指定路由（fixed）時全部保留
    // 出發、到達、轉乘都相同時：幹線（臺鐵對號、高鐵）坐得比較久的優先，也就是對號在最靠近目的地的大站下車
    // （愛河之心→汐止：對號坐到松山再換區間車，不在板橋下）、最靠近起點的大站上車；再相同才依路由順序
    $trunk = [];
    foreach ($all as $i => $a) {
        $trunk[$i] = appJourneyTrunkMinutes($a);
    }
    $keep = $fixed !== null ? $all : [];
    foreach ($fixed !== null ? [] : $all as $i => $a) {
        $dominated = false;
        foreach ($all as $k => $b) {
            if ($k === $i) {
                continue;
            }
            $ge = $b['DepartureMinute'] >= $a['DepartureMinute'] && $b['ArrivalMinute'] <= $a['ArrivalMinute'] && $b['TransferCount'] <= $a['TransferCount'];
            $gt = $b['DepartureMinute'] > $a['DepartureMinute'] || $b['ArrivalMinute'] < $a['ArrivalMinute'] || $b['TransferCount'] < $a['TransferCount'];
            if ($ge && ($gt || $trunk[$k] > $trunk[$i] || ($trunk[$k] === $trunk[$i] && $k < $i))) {
                $dominated = true;
                break;
            }
        }
        if (!$dominated) {
            $keep[] = $a;
        }
    }
    // 慢的方案：行車時間超過最短那個的 1.5 倍再多 30 分。轉乘又不比它少的明顯繞遠，不列（指定路由時照列）；
    // 轉乘比較少而留下的（例如高鐵為主時的臺鐵直達），依抵達、行車時間、出發排序時一律排在其他方案後面
    $slowOver = INF;
    if ($keep) {
        $short = $keep[0];
        foreach ($keep as $j) {
            if ([$j['TravelMinutes'], $j['TransferCount']] < [$short['TravelMinutes'], $short['TransferCount']]) {
                $short = $j;
            }
        }
        $slowOver = $short['TravelMinutes'] * 1.5 + 30;
        if ($fixed === null) {
            $keep = array_values(array_filter($keep, function ($j) use ($short, $slowOver) {
                return $j['TravelMinutes'] <= $slowOver || $j['TransferCount'] < $short['TransferCount'];
            }));
        }
    }
    foreach ($keep as &$j) {
        $j['_slow'] = $j['TravelMinutes'] > $slowOver;
    }
    unset($j);
    // 排序與標籤（最少轉乘以外，慢的方案排在後面，也不會拿到最早到達、最短行車時間）
    $cmp = [
        'arrival'   => function ($a, $b) { return [$a['_slow'], $a['ArrivalMinute'], $a['TransferCount'], -$a['DepartureMinute']] <=> [$b['_slow'], $b['ArrivalMinute'], $b['TransferCount'], -$b['DepartureMinute']]; },
        'transfers' => function ($a, $b) { return [$a['TransferCount'], $a['ArrivalMinute']] <=> [$b['TransferCount'], $b['ArrivalMinute']]; },
        'duration'  => function ($a, $b) { return [$a['_slow'], $a['TravelMinutes'], $a['TransferCount'], $a['DepartureMinute']] <=> [$b['_slow'], $b['TravelMinutes'], $b['TransferCount'], $b['DepartureMinute']]; },
        // 抵達時刻模式、末班沒給 sort 時：出發由晚到早（轉乘少、到達早的優先）
        'late'      => function ($a, $b) { return [$a['_slow'], -$a['DepartureMinute'], $a['TransferCount'], $a['ArrivalMinute']] <=> [$b['_slow'], -$b['DepartureMinute'], $b['TransferCount'], $b['ArrivalMinute']]; },
    ];
    // 城際多樣性保底：各排除條件下最快的方案（從全部方案挑，不受互不支配與繞遠篩選影響）一定列入
    $diverse = [];
    $repCmp = $back ? function ($a, $b) { return [-$a['DepartureMinute'], $a['TransferCount'], $a['ArrivalMinute'], -appJourneyTrunkMinutes($a)] <=> [-$b['DepartureMinute'], $b['TransferCount'], $b['ArrivalMinute'], -appJourneyTrunkMinutes($b)]; }
                    : function ($a, $b) { return [$a['ArrivalMinute'], $a['TransferCount'], -$a['DepartureMinute'], -appJourneyTrunkMinutes($a)] <=> [$b['ArrivalMinute'], $b['TransferCount'], -$b['DepartureMinute'], -appJourneyTrunkMinutes($b)]; };
    foreach ($diversity as $tag => $mx) {
        $cands = array_values(array_filter($all, function ($j) use ($mx) { return appJourneyAllowed($j, $mx); }));
        if (($raptor[$mx] ?? null) !== null && !appJourneyCovered($cands, $raptor[$mx], $mx)) {
            $cands[] = $raptor[$mx];   // 原本的演算法沒有同樣好的：用 RAPTOR 的班次
        }
        usort($cands, $repCmp);
        if ($cands) {
            $diverse[$tag] = $cands[0];
        }
    }
    $kept = array_flip(array_column($keep, '_sig'));
    foreach ($diverse as $j) {
        if (!isset($kept[$j['_sig']])) {
            $j['_slow'] = $j['TravelMinutes'] > $slowOver;
            $keep[] = $j;
            $kept[$j['_sig']] = true;
        }
    }
    $best = [];
    foreach (['Fastest' => 'arrival', 'ShortestTime' => 'duration', 'FewestTransfers' => 'transfers'] as $tag => $s) {
        $tmp = $keep;
        usort($tmp, $cmp[$s]);
        if ($tmp) {
            $best[$tag] = $tmp[0]['_sig'];
        }
    }
    // 保底方案的標籤：跟整體的代表方案是同一個方案時不另外標（例如使用者已勾不搭高鐵時，不搭高鐵最快的常常就是它）。
    // 整體的代表方案與保底用同一種挑法：出發時刻模式＝最早到達（Fastest）；抵達時刻模式＝最晚出發（全部方案依 $repCmp 排第一的）
    $refSig = $best['Fastest'] ?? null;
    if ($back && $all) {
        $tmp = $all;
        usort($tmp, $repCmp);
        $refSig = $tmp[0]['_sig'];
    }
    foreach ($diverse as $tag => $j) {
        if ($j['_sig'] !== $refSig) {
            $best[$tag] = $j['_sig'];
        }
    }
    usort($keep, $cmp[$sort]);
    // 前 limit 個以外，各標籤（最早到達、最短行車時間、最少轉乘）的代表方案也一定附上，接在後面
    $shown = array_slice($keep, 0, $limit);
    $sigs = array_flip(array_column($shown, '_sig'));
    foreach ($keep as $j) {
        if (in_array($j['_sig'], $best, true) && !isset($sigs[$j['_sig']])) {
            $shown[] = $j;
            $sigs[$j['_sig']] = true;
        }
    }
    $keep = $shown;
    $raptorAdded = array_values(array_map(function ($j) { return $j['DepartureTime'] . '→' . $j['ArrivalTime']; },
        array_filter($keep, function ($j) { return !empty($j['_raptor']); })));
    $picks = [];
    foreach ($keep as $i => &$j) {
        $tags = [];
        foreach ($best as $tag => $sig) {
            if ($sig === $j['_sig']) {
                $tags[] = $tag;
                $picks[$tag] = $i;
            }
        }
        if ($j['TransferCount'] === 0) {
            $tags[] = 'Direct';
        }
        $j['Tags'] = $tags;
        unset($j['_sig'], $j['_slow'], $j['_raptor']);
        $j = appJourneyStops($d, $j, $withStops);
    }
    unset($j);
    return [[
        'From' => ['StationUID' => $from, 'StationName' => $m['names'][$from] ?? null],
        'To' => ['StationUID' => $to, 'StationName' => $m['names'][$to] ?? null],
        'count' => count($keep), 'picks' => (object) $picks, 'tags' => APP_JOURNEY_TAGS, 'journeys' => $keep,
        'note' => $keep ? null : ($mode === 'first' || $mode === 'last' ? '這一天找不到可到達的方案' : '這個時段找不到可到達的方案'),
    ], ['week' => $week, 'weekday' => FINDER_WEEKDAYS[$week], 'date' => $date, 'mode' => $mode]
       + appTimeWindowMeta($back ? 'arr' : 'dep', $start, $end)
       + ($back ? ['arrive_from' => appHM($start), 'arrive' => appHM($end)] : ['depart' => appHM($start)])
       + ['sort' => $sort]
       + appExtraInfo($extra) + ['routes' => count($jobs), 'routesUnique' => count(array_unique(array_map(function ($j) { return $j[0]['route'] . '|' . ($j[0]['Supplement'] ?? ''); }, $jobs)))]
       + (appTrunkMeta() ? ['trunk' => appTrunkMeta()] : []) + ($fixed !== null ? ['fixed' => true] : [])
       + ($diversity ? ['diversity' => array_keys($diverse)] : [])
       + ($raptorOn ? ['raptor' => ['seconds' => $raptorSec, 'added' => $raptorAdded]] : [])
       + ['seconds' => round(microtime(true) - $t0, 3)]];
}

// ================================================================
// search_time：一個時刻、最快的各種走法（使用者規則 2026-11 需求二）

/**
 * journeys?search_time=HH:MM[&by=dep|arr]：只給一個出發（by=dep，預設）或抵達（by=arr）時刻，列出最快的各種不同走法。
 * 不同走法＝走法類別（appJourneyCategory：主要運具的營運單位，臺鐵再分對號、區間與路線），每一類取最快的一班；
 * 各排除條件（使用者的 extra 再加上 1、2、3：不搭對號、不搭高鐵、只搭區間車）最快的方案一定列（原本的演算法＋RAPTOR 保底）。
 * 不能和 start、end、depart、arrive、first、last、routes 一起用。回應格式與 journeys 相同，方案多帶 Category、CategoryName
 */
function appTripJourneysAt($from, $to, $extra, $week, $date)
{
    $conflict = array_values(array_filter(['start', 'end', 'depart', 'arrive', 'first', 'last', 'routes'], function ($p) { return appParam($p) !== null; }));
    if ($conflict) {
        appFail(40020, ['param' => implode(',', $conflict), 'detail' => 'search_time 只給一個時刻，不能再給時段（start、end）、first、last 或 routes']);
    }
    $by = appParamChoice('by', ['dep', 'arr', 'departure', 'arrival'], 'dep');
    $back = $by === 'arr' || $by === 'arrival';
    $time = appParamTime('search_time');
    $sort = appParamChoice('sort', ['arrival', 'transfers', 'duration'], $back ? 'late' : 'arrival');
    $limit = appParamInt('limit', APP_JOURNEYS_LIMIT_DEFAULT, 1, APP_JOURNEYS_LIMIT_MAX);
    $opt = ['extra' => $extra, 'max_transfers' => appParam('max_transfers') !== null ? appParamInt('max_transfers', null, 0, APP_MAX_TRANSFERS_MAX) : null,
            'via' => appParam('via') !== null ? appStationParam('via') : null, 'limit' => APP_JOURNEY_ROUTES, 'variants' => true,
            'deeper' => appParamInt('deeper', min(appRouteSearch()['Deeper'], APP_DEEPER_MAX), 0, APP_DEEPER_MAX)];   // 與 journeys 相同（共用路由快取）
    $stops = appParamInt('stops', 1, 0, 1) === 1;
    list($data, $meta) = appJourneysAtCompute(compact('from', 'to', 'extra', 'week', 'date', 'back', 'time', 'sort', 'limit', 'opt', 'stops'));
    appSend($data, $meta);
}

/**
 * 走法類別（使用者規則 2026-11：路線、車種多元化）：依方案的主要運具（高鐵 > 臺鐵 > 行車時間最長的捷運）分類，
 * 臺鐵再分對號／區間與經過的路線。前後怎麼轉乘（捷運換哪條線到高鐵站）不另外分類。
 * 回傳 [代碼, 名稱]，例如 ['TRA:express:TRA-WL', '臺鐵對號（西部幹線）']、['THSR', '高鐵']、['TYMC', '桃園捷運']
 */
function appJourneyCategory(array $j)
{
    // 主要運具的優先順序：有搭高鐵＝高鐵；否則有搭臺鐵＝臺鐵（看臺鐵裡行車時間最長的一段）；都沒有＝行車時間最長的那段捷運。
    // 不能單看行車時間最長的一段：轉好幾次捷運到高鐵站時，捷運段可能比 20 分的高鐵段還長（台電大樓→興南）
    $main = null;
    $rank = function ($l) { return $l['System'] === 'THSR' ? 2 : ($l['System'] === 'TRA' ? 1 : 0); };
    foreach ($j['Legs'] as $l) {
        if ($l['Type'] === 'ride' && ($main === null || [$rank($l), $l['TravelMinutes'] ?? 0] > [$rank($main), $main['TravelMinutes'] ?? 0])) {
            $main = $l;
        }
    }
    if ($main === null) {
        return ['walk', '步行'];
    }
    $op = appSystemOperator($main['System']);
    if ($main['System'] !== 'TRA') {
        return [$op['Operator'], $op['OperatorName']];
    }
    $express = ($main['Class'] ?? APP_CLASS_LOCAL) === APP_CLASS_EXPRESS;
    $lines = [];
    $names = [];
    foreach ($main['Path'] ?? [] as $p) {
        $u = $p['LineUID'] ?? null;
        if ($u !== null && !isset($lines[$u])) {
            $lines[$u] = true;
            $names[] = $p['LineName']['Zh_tw'] ?? $u;
        }
    }
    return ['TRA:' . ($express ? 'express' : 'local') . ($lines ? ':' . implode('+', array_keys($lines)) : ''),
            $op['OperatorName'] . ($express ? '對號' : '區間') . ($names ? '（' . implode('、', $names) . '）' : '')];
}

/**
 * search_time 的計算。$q：from、to、extra、week、date、back（true＝抵達時刻）、time（營運日分鐘）、sort、limit、opt、stops。回傳 [data, meta]
 *  1. 找路由（與 journeys 相同，appCoreRoutes）
 *  2. 每條路由只串這個時刻起 APP_JOURNEY_AT_HORIZON 分鐘內的班次（抵達模式：之前），這段時間一班都沒有的路由放寬到系統最長查詢區間
 *  3. 各排除條件（使用者的 extra 再加 0～3 的位元）：串到的方案裡最快的，再用 RAPTOR 找有沒有更快的（上限＝已有的最快，同時到也接受）
 *  4. 每個走法類別取最快的一班；太慢的類別（行車時間超過最短的 2 倍再加 60 分、轉乘又不比它少）不列，各排除條件的最快一律列
 */
function appJourneysAtCompute(array $q)
{
    extract($q);
    $m = appModel();
    $t0 = microtime(true);
    $routes = appCoreRoutesCached($m, $from, $to, $opt);
    $tRoutes = microtime(true) - $t0;
    $d = &appRouterData($week);
    $span = appTimeWindowSpan() ?? (APP_SERVICE_DAY_END - APP_SERVICE_DAY_START);
    $collect = function ($h) use (&$d, $routes, $extra, $back, $time, $week, $span) {
        $all = [];
        $win = function ($h) use ($back, $time) {
            return $back ? [max(APP_SERVICE_DAY_START, $time - $h), $time] : [$time, min(APP_SERVICE_DAY_END, $time + $h)];
        };
        list($start, $end) = $win($h);
        list($wideStart, $wideEnd) = $win(max($h, $span));
        foreach ($routes as $r) {
            $chains = $back ? appChainRouteBack($d, $r['legs'], $extra, $start, $end) : appChainRoute($d, $r['legs'], $extra, $start, $end);
            // 這條路由在 $h 分鐘內一班都沒有（班次少的路線）：只有它放寬到系統最長查詢區間，走法類別才不會因為時間範圍短而少掉
            if (!$chains && $h < $span) {
                $chains = $back ? appChainRouteBack($d, $r['legs'], $extra, $wideStart, $wideEnd) : appChainRoute($d, $r['legs'], $extra, $wideStart, $wideEnd);
            }
            // 同一條路由、同一種車種組合（走法類別與排除條件只看這些）只要最快的一組，其他不必轉成輸出格式（輸出最花時間）
            $pick = [];
            foreach ($chains as $c) {
                $cs = implode(',', array_map(function ($x) { return (string) ($x[1][4] ?? ''); }, $c['rides']));
                $p = $pick[$cs] ?? null;
                if ($p === null || ($back ? [-$c['dep'], $c['arr']] < [-$p['dep'], $p['arr']] : [$c['arr'], -$c['dep']] < [$p['arr'], -$p['dep']])) {
                    $pick[$cs] = $c;
                }
            }
            foreach ($pick as $c) {
                $j = appJourneyOut($d, $r, $c, $week);
                if (!isset($all[$j['_sig']]) || $all[$j['_sig']]['TransferCount'] > $j['TransferCount']) {
                    $all[$j['_sig']] = $j;
                }
            }
        }
        return [array_values($all), $wideStart, $wideEnd];   // RAPTOR 用寬的時間範圍（從這個時刻起最早到達，不管多晚出發）
    };
    $horizon = APP_JOURNEY_AT_HORIZON;
    $tc0 = microtime(true);
    list($all, $start, $end) = $collect($horizon);
    if (!$all && $span > $horizon) {
        $horizon = $span;
        list($all, $start, $end) = $collect($horizon);
    }
    // 最快的定義：出發時刻模式＝最早到達（再看轉乘少、晚出發、幹線坐得久）；抵達時刻模式＝最晚出發
    $better = $back ? function ($a, $b) { return [-$a['DepartureMinute'], $a['TransferCount'], $a['ArrivalMinute'], -appJourneyTrunkMinutes($a)] <=> [-$b['DepartureMinute'], $b['TransferCount'], $b['ArrivalMinute'], -appJourneyTrunkMinutes($b)]; }
                    : function ($a, $b) { return [$a['ArrivalMinute'], $a['TransferCount'], -$a['DepartureMinute'], -appJourneyTrunkMinutes($a)] <=> [$b['ArrivalMinute'], $b['TransferCount'], -$b['DepartureMinute'], -appJourneyTrunkMinutes($b)]; };
    $bestOf = function (array $list, $mask) use ($better) {
        $b = null;
        foreach ($list as $j) {
            if (appJourneyAllowed($j, $mask) && ($b === null || $better($j, $b) < 0)) {
                $b = $j;
            }
        }
        return $b;
    };
    $tChain = microtime(true) - $tc0;
    // 3. 各排除條件最快（原本的演算法＋RAPTOR）
    $tr0 = microtime(true);
    $modeBest = [];
    $raptorAdded = [];
    $raptorOn = appRoutesIntercity($routes) || !$all;
    $proven = [];   // 已由 RAPTOR 證明是該條件下最快的方案（排除位元 => 方案）
    $raptorRuns = 0;
    foreach ([0, 1, 2, 3] as $bits) {
        $mask = $extra | $bits;
        if (isset($modeBest[$mask])) {
            continue;
        }
        $b = $bestOf($all, $mask);
        // 限制比較寬的條件下已證明最快的方案，如果也符合這個條件（例如全搭最快的剛好沒搭高鐵），它就是這個條件下最快的，不必再跑 RAPTOR
        foreach ($proven as $pm => $pj) {
            if (($pm & $mask) === $pm && appJourneyAllowed($pj, $mask)) {
                $modeBest[$mask] = $proven[$mask] = ($b !== null && $better($b, $pj) <= 0) ? $b : $pj;
                continue 2;
            }
        }
        if ($raptorOn) {
            $raptorRuns++;
            $bound = $b === null ? null : ($back ? $b['DepartureMinute'] * 60 : $b['ArrivalMinute'] * 60);
            $rj = appJourneyRaptor($d, $from, $to, $week, $back, $start, $end, $mask, $bound);
            if ($rj !== null && ($b === null || $better($rj, $b) < 0) && appJourneyAllowed($rj, $mask)) {
                $b = $rj;
                $all[] = $rj;
                $raptorAdded[] = $rj['DepartureTime'] . '→' . $rj['ArrivalTime'];
            }
            if ($b !== null) {
                $proven[$mask] = $b;
            }
        }
        if ($b !== null) {
            $modeBest[$mask] = $b;
        }
    }
    $raptorSec = round(microtime(true) - $tr0, 3);
    // 4. 每個走法類別最快的一班
    $byCat = [];
    foreach ($all as $j) {
        list($k) = appJourneyCategory($j);
        if (!isset($byCat[$k]) || $better($j, $byCat[$k]) < 0) {
            $byCat[$k] = $j;
        }
    }
    $short = null;
    foreach (array_merge(array_values($byCat), array_values($modeBest)) as $j) {
        if ($short === null || [$j['TravelMinutes'], $j['TransferCount']] < [$short['TravelMinutes'], $short['TransferCount']]) {
            $short = $j;
        }
    }
    // 走法類別是給使用者參考的各種可能性（臺北→中壢的機捷到老街溪走過去），門檻比時段查詢寬：行車時間不超過最短的 2 倍再加 60 分，
    // 或轉乘比它少；超過的（例如臺北→高雄全程區間車）不列，但各排除條件的最快一律列
    $slowOver = $short ? $short['TravelMinutes'] * 1.5 + 30 : INF;
    $catOver = $short ? $short['TravelMinutes'] * 2 + 60 : INF;
    $keep = [];
    foreach ($modeBest as $j) {
        $keep[$j['_sig']] = $j;
    }
    foreach ($byCat as $j) {
        if ($j['TravelMinutes'] <= $catOver || $j['TransferCount'] < $short['TransferCount']) {
            $keep[$j['_sig']] = $keep[$j['_sig']] ?? $j;
        }
    }
    $keep = array_values($keep);
    foreach ($keep as &$j) {
        $j['_slow'] = $j['TravelMinutes'] > $slowOver;
    }
    unset($j);
    $cmp = [
        'arrival'   => function ($a, $b) { return [$a['ArrivalMinute'], $a['TransferCount'], -$a['DepartureMinute']] <=> [$b['ArrivalMinute'], $b['TransferCount'], -$b['DepartureMinute']]; },
        'transfers' => function ($a, $b) { return [$a['TransferCount'], $a['ArrivalMinute']] <=> [$b['TransferCount'], $b['ArrivalMinute']]; },
        'duration'  => function ($a, $b) { return [$a['TravelMinutes'], $a['TransferCount'], $a['DepartureMinute']] <=> [$b['TravelMinutes'], $b['TransferCount'], $b['DepartureMinute']]; },
        'late'      => function ($a, $b) { return [-$a['DepartureMinute'], $a['TransferCount'], $a['ArrivalMinute']] <=> [-$b['DepartureMinute'], $b['TransferCount'], $b['ArrivalMinute']]; },
    ];
    // 標籤：使用者模式最快＝Fastest；其他排除條件最快（跟 Fastest 是同一個方案時不另外標）；最短行車時間、最少轉乘、直達
    $best = [];
    if (isset($modeBest[$extra])) {
        $best['Fastest'] = $modeBest[$extra]['_sig'];
    }
    foreach ($modeBest as $mask => $j) {
        $tag = APP_JOURNEY_MODE_TAGS[$mask & 3] ?? null;
        if ($mask !== $extra && $tag !== null && $j['_sig'] !== ($best['Fastest'] ?? null)) {
            $best[$tag] = $j['_sig'];
        }
    }
    foreach (['ShortestTime' => 'duration', 'FewestTransfers' => 'transfers'] as $tag => $s) {
        $tmp = $keep;
        usort($tmp, $cmp[$s]);
        if ($tmp) {
            $best[$tag] = $tmp[0]['_sig'];
        }
    }
    usort($keep, $cmp[$sort]);
    $shown = array_slice($keep, 0, $limit);
    $sigs = array_flip(array_column($shown, '_sig'));
    foreach ($keep as $j) {
        if (in_array($j['_sig'], $best, true) && !isset($sigs[$j['_sig']])) {
            $shown[] = $j;
            $sigs[$j['_sig']] = true;
        }
    }
    $keep = $shown;
    $picks = [];
    foreach ($keep as $i => &$j) {
        $tags = [];
        foreach ($best as $tag => $sig) {
            if ($sig === $j['_sig']) {
                $tags[] = $tag;
                $picks[$tag] = $i;
            }
        }
        if ($j['TransferCount'] === 0) {
            $tags[] = 'Direct';
        }
        $j['Tags'] = $tags;
        list($j['Category'], $j['CategoryName']) = appJourneyCategory($j);
        unset($j['_sig'], $j['_slow'], $j['_raptor']);
        $j = appJourneyStops($d, $j, $stops);
    }
    unset($j);
    $mode = $back ? 'arrive' : 'depart';
    return [[
        'From' => ['StationUID' => $from, 'StationName' => $m['names'][$from] ?? null],
        'To' => ['StationUID' => $to, 'StationName' => $m['names'][$to] ?? null],
        'count' => count($keep), 'picks' => (object) $picks, 'tags' => APP_JOURNEY_TAGS, 'journeys' => $keep,
        'note' => $keep ? null : ($back ? '這個時刻之前找不到可到達的方案' : '這個時刻之後找不到可到達的方案'),
    ], ['week' => $week, 'weekday' => FINDER_WEEKDAYS[$week], 'date' => $date, 'mode' => $mode, 'search_time' => appHM($time)]
       + appTimeWindowMeta($back ? 'arr' : 'dep', $start, $end)
       + ['sort' => $sort, 'horizon' => $horizon]
       + appExtraInfo($extra) + ['routes' => count($routes), 'categories' => count($byCat)]
       + (appTrunkMeta() ? ['trunk' => appTrunkMeta()] : [])
       + ($raptorOn ? ['raptor' => ['seconds' => $raptorSec, 'runs' => $raptorRuns, 'added' => $raptorAdded]] : [])
       + ['timing' => ['routes' => round($tRoutes, 3), 'chain' => round($tChain, 3), 'raptor' => $raptorSec]]
       + ['seconds' => round(microtime(true) - $t0, 3)]];
}

// ================================================================
// full_search=1：時段內完全不漏（使用者規則 2026-11 需求一，研究工具）

/**
 * journeys?full_search=1（時段參數同 journeys：start、end、by，或 first、last）：把 extra 再加上 0～3 每個排除條件（全搭、不搭對號、
 * 不搭高鐵、只搭區間車）在時段內所有互不支配的方案都找出來，合成一份清單依 sort 排序。研究工具：公開，同一 IP
 * QUERY_FULL_SEARCH_IP_SECONDS（30）秒內只能執行一次（執行標記 REQUEST_JOURNEYS__FULL_SEARCH_LIMIT_IP，queryLimitMark），最多算 APP_JOURNEY_FULL_SECONDS 秒。每個方案帶 Modes（在哪些排除條件下是互不支配的方案）與 Source（routes／raptor／both）
 */
function appTripJourneysFull($from, $to, $extra, $week, $date, $mode, $start, $end)
{
    if (appParam('full_search') !== '1') {
        appFail(40020, ['param' => 'full_search', 'detail' => '帶 1']);
    }
    if (appParam('routes') !== null || appParam('search_time') !== null) {
        appFail(40020, ['param' => 'full_search', 'detail' => 'full_search 不能和 routes、search_time 一起用']);
    }
    $back = $mode === 'arrive' || $mode === 'last';
    $sort = appParamChoice('sort', ['arrival', 'transfers', 'duration'], $back ? 'late' : 'arrival');
    $limit = appParamInt('limit', 500, 1, 500);
    $opt = ['extra' => $extra, 'max_transfers' => appParam('max_transfers') !== null ? appParamInt('max_transfers', null, 0, APP_MAX_TRANSFERS_MAX) : null,
            'via' => appParam('via') !== null ? appStationParam('via') : null, 'limit' => APP_JOURNEY_ROUTES, 'variants' => true,
            'deeper' => appParamInt('deeper', min(appRouteSearch()['Deeper'], APP_DEEPER_MAX), 0, APP_DEEPER_MAX)];
    $stops = appParamInt('stops', 1, 0, 1) === 1;
    // 公開，用 IP 限制頻率（使用者規則 2026-11）：參數都檢查過才記執行標記（參數錯的不佔用次數），同一 IP 30 秒內只能執行一次
    queryLimitMark('REQUEST_JOURNEYS__FULL_SEARCH_LIMIT_IP', defined('QUERY_FULL_SEARCH_IP_SECONDS') ? (int) QUERY_FULL_SEARCH_IP_SECONDS : 30, 'full_search');
    list($data, $meta) = appJourneysFullCompute(compact('from', 'to', 'extra', 'week', 'date', 'mode', 'start', 'end', 'sort', 'limit', 'opt', 'stops'));
    appSend($data, $meta);
}

/**
 * full_search 的計算。$q 同 appJourneysCompute（mode、start、end 是時段，分鐘）。回傳 [data, meta]
 *  1. 每個排除條件：原本的演算法（appCoreRoutes 依該條件找路由、串時段內所有班次）
 *  2. 每個排除條件：RAPTOR 掃整個時段——出發時刻模式從時段開始，每次從上一批最早出發的下一分鐘再找，直到時段結束；
 *     抵達時刻模式從「時段開始前就到得了的最晚出發」掃到時段結束（更早出發的一定被它淘汰）。每班再用固定路由倒回檢查，同時到達時取最晚出發
 *  3. 合在一起去重；每個排除條件各自在時段內做互不支配（出發不早、到達不晚、轉乘不多），留下的方案標上 Modes
 */
function appJourneysFullCompute(array $q)
{
    extract($q);
    @set_time_limit(APP_JOURNEY_FULL_SECONDS + 20);
    $m = appModel();
    $t0 = microtime(true);
    $deadline = $t0 + APP_JOURNEY_FULL_SECONDS;
    $back = $mode === 'arrive' || $mode === 'last';
    $d = &appRouterData($week);
    $masks = [];
    foreach ([0, 1, 2, 3] as $bits) {
        $masks[$extra | $bits] = true;
    }
    $masks = array_keys($masks);
    $pool = [];      // _sig => 方案
    $source = [];    // _sig => ['routes' => true, 'raptor' => true]
    $add = function (array $j, $src) use (&$pool, &$source) {
        $k = $j['_sig'];
        if (!isset($pool[$k]) || $pool[$k]['TransferCount'] > $j['TransferCount']) {
            $pool[$k] = $j;
        }
        $source[$k][$src] = true;
    };
    $stopped = null;
    $stat = ['routes' => 0, 'routeJourneys' => 0, 'raptorRuns' => 0, 'raptorJourneys' => 0];
    // 1. 原本的演算法
    foreach ($masks as $mask) {
        $routes = appCoreRoutesCached($m, $from, $to, ['extra' => $mask] + $opt);
        $stat['routes'] += count($routes);
        foreach ($routes as $r) {
            $chains = $back ? appChainRouteBack($d, $r['legs'], $mask, $start, $end) : appChainRoute($d, $r['legs'], $mask, $start, $end);
            foreach ($chains as $c) {
                $add(appJourneyOut($d, $r, $c, $week), 'routes');
                $stat['routeJourneys']++;
            }
        }
    }
    $tRoutes = round(microtime(true) - $t0, 3);
    // 2. RAPTOR 掃整個時段
    transferLowerBounds($d, $to);
    transferLowerBounds($d, $from, true);
    $saved = [$d['tripBan'] ?? null, $d['accel'] ?? null];
    $d['accel'] = null;
    try {
        foreach ($masks as $mask) {
            if ($stopped !== null) {
                break;
            }
            $d['tripBan'] = appRaptorBan($d, $week, $mask) ?: null;
            $scanEnd = $end * 60 + 59;
            if ($back) {
                // 時段開始前就到得了的最晚出發（各搭車班數取最早的那個）：比它早出發、班數不多的方案一定被它淘汰
                $floor = null;
                for ($k = 1; $k <= TRANSFER_MAX_TRIPS; $k++) {
                    $x = transferLatestDeparture($d, $from, $to, $start * 60 - 1, $k, max(0, $start * 60 - 8 * 3600));
                    if ($x !== null) {
                        $floor = $floor === null ? $x : min($floor, $x);
                    }
                }
                $t = $floor ?? max(0, $start * 60 - 8 * 3600);
            } else {
                $t = $start * 60;
            }
            $t = max($t, APP_SERVICE_DAY_START * 60);   // 營運日 04:00 以前出發搜尋不到任何方案（末班、抵達時刻模式往前推時）
            while ($t <= $scanEnd) {
                if (microtime(true) > $deadline) {
                    $stopped = 'time limit (' . APP_JOURNEY_FULL_SECONDS . ' s) at mode ' . $mask . ' ' . transferHM($t);
                    break;
                }
                $res = transferRaptor($d, $from, $to, $t);
                $stat['raptorRuns']++;
                $minDep = null;
                foreach ($res as $rj) {
                    if ($rj['_dep'] > $scanEnd) {
                        continue;
                    }
                    $minDep = $minDep === null ? $rj['_dep'] : min($minDep, $rj['_dep']);
                    $saveBan = $d['tripBan'];
                    $d['tripBan'] = null;   // 倒回檢查用串接（依 $mask 選班次），不用 RAPTOR 的排除
                    $j = appRaptorFixedLater($d, $rj, $week, $mask, $back ? null : $end);
                    $d['tripBan'] = $saveBan;
                    $j = $j ?? appRaptorJourneyOut($d, $rj, $week);
                    if (appJourneyAllowed($j, $mask)) {
                        $add($j, 'raptor');
                        $stat['raptorJourneys']++;
                    }
                }
                if ($minDep === null) {
                    break;   // 之後出發的都超過時段（或已經沒有車）
                }
                $t = max($t + 60, $minDep + 60);
            }
        }
    } finally {
        list($d['tripBan'], $d['accel']) = $saved;
    }
    $tRaptor = round(microtime(true) - $t0 - $tRoutes, 3);
    // 3. 每個排除條件各自在時段內做互不支配
    $inWindow = function ($j) use ($back, $start, $end) {
        $v = $back ? $j['ArrivalMinute'] : $j['DepartureMinute'];
        return $v >= $start && $v <= $end;
    };
    $all = array_values(array_filter($pool, $inWindow));
    $modesOf = [];
    foreach ($masks as $mask) {
        $cand = array_values(array_filter($all, function ($j) use ($mask) { return appJourneyAllowed($j, $mask); }));
        foreach ($cand as $i => $a) {
            $dominated = false;
            foreach ($cand as $k => $b) {
                if ($k === $i) {
                    continue;
                }
                $ge = $b['DepartureMinute'] >= $a['DepartureMinute'] && $b['ArrivalMinute'] <= $a['ArrivalMinute'] && $b['TransferCount'] <= $a['TransferCount'];
                $gt = $b['DepartureMinute'] > $a['DepartureMinute'] || $b['ArrivalMinute'] < $a['ArrivalMinute'] || $b['TransferCount'] < $a['TransferCount'];
                if ($ge && ($gt || $k < $i)) {
                    $dominated = true;
                    break;
                }
            }
            if (!$dominated) {
                $modesOf[$a['_sig']][] = $mask;
            }
        }
    }
    $keep = array_values(array_filter($all, function ($j) use ($modesOf) { return isset($modesOf[$j['_sig']]); }));
    $cmp = [
        'arrival'   => function ($a, $b) { return [$a['ArrivalMinute'], $a['TransferCount'], -$a['DepartureMinute']] <=> [$b['ArrivalMinute'], $b['TransferCount'], -$b['DepartureMinute']]; },
        'transfers' => function ($a, $b) { return [$a['TransferCount'], $a['ArrivalMinute']] <=> [$b['TransferCount'], $b['ArrivalMinute']]; },
        'duration'  => function ($a, $b) { return [$a['TravelMinutes'], $a['TransferCount'], $a['DepartureMinute']] <=> [$b['TravelMinutes'], $b['TransferCount'], $b['DepartureMinute']]; },
        'late'      => function ($a, $b) { return [-$a['DepartureMinute'], $a['TransferCount'], $a['ArrivalMinute']] <=> [-$b['DepartureMinute'], $b['TransferCount'], $b['ArrivalMinute']]; },
    ];
    // 標籤：各排除條件最快（出發時刻模式＝最早到達，抵達時刻模式＝最晚出發）、最短行車時間、最少轉乘、直達
    $rep = $back ? $cmp['late'] : $cmp['arrival'];
    $best = [];
    foreach ($masks as $mask) {
        $c = array_values(array_filter($keep, function ($j) use ($mask, $modesOf) { return in_array($mask, $modesOf[$j['_sig']], true); }));
        usort($c, $rep);
        if (!$c) {
            continue;
        }
        $tag = $mask === $extra ? 'Fastest' : (APP_JOURNEY_MODE_TAGS[$mask & 3] ?? null);
        if ($tag !== null && ($tag === 'Fastest' || $c[0]['_sig'] !== ($best['Fastest'] ?? null))) {
            $best[$tag] = $c[0]['_sig'];
        }
    }
    foreach (['ShortestTime' => 'duration', 'FewestTransfers' => 'transfers'] as $tag => $s) {
        $tmp = array_values(array_filter($keep, function ($j) use ($extra, $modesOf) { return in_array($extra, $modesOf[$j['_sig']], true); }));
        usort($tmp, $cmp[$s]);
        if ($tmp) {
            $best[$tag] = $tmp[0]['_sig'];
        }
    }
    usort($keep, $cmp[$sort]);
    $total = count($keep);
    $keep = array_slice($keep, 0, $limit);
    $picks = [];
    foreach ($keep as $i => &$j) {
        $tags = [];
        foreach ($best as $tag => $sig) {
            if ($sig === $j['_sig']) {
                $tags[] = $tag;
                $picks[$tag] = $i;
            }
        }
        if ($j['TransferCount'] === 0) {
            $tags[] = 'Direct';
        }
        $j['Tags'] = $tags;
        $j['Modes'] = $modesOf[$j['_sig']];
        $src = $source[$j['_sig']] ?? [];
        $j['Source'] = isset($src['routes'], $src['raptor']) ? 'both' : (isset($src['raptor']) ? 'raptor' : 'routes');
        list($j['Category'], $j['CategoryName']) = appJourneyCategory($j);
        unset($j['_sig'], $j['_slow'], $j['_raptor']);
        $j = appJourneyStops($d, $j, $stops);
    }
    unset($j);
    $bySource = array_count_values(array_column($keep, 'Source'));
    return [[
        'From' => ['StationUID' => $from, 'StationName' => $m['names'][$from] ?? null],
        'To' => ['StationUID' => $to, 'StationName' => $m['names'][$to] ?? null],
        'count' => count($keep), 'picks' => (object) $picks, 'tags' => APP_JOURNEY_TAGS, 'journeys' => $keep,
        'note' => $keep ? null : ($mode === 'first' || $mode === 'last' ? '這一天找不到可到達的方案' : '這個時段找不到可到達的方案'),
    ], ['week' => $week, 'weekday' => FINDER_WEEKDAYS[$week], 'date' => $date, 'mode' => $mode, 'full_search' => true]
       + appTimeWindowMeta($back ? 'arr' : 'dep', $start, $end)
       + ['sort' => $sort] + appExtraInfo($extra)
       + ['modes' => array_intersect_key(APP_JOURNEY_FULL_MODES, array_flip(array_map(function ($x) { return $x & 3; }, $masks))),
          'complete' => $stopped === null] + ($stopped !== null ? ['stopped' => $stopped] : [])
       + ['found' => $total, 'sources' => $bySource, 'stat' => $stat, 'timing' => ['routes' => $tRoutes, 'raptor' => $tRaptor]]
       + ['seconds' => round(microtime(true) - $t0, 3)]];
}


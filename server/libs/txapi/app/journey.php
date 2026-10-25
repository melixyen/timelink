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
    ];
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
    ], appExtraInfo($opt['extra']) + array_filter(['max_transfers' => $opt['max_transfers'], 'via' => $opt['via']], function ($v) { return $v !== null; })
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
    foreach ($chains as $c) {
        $j = appJourneyOut($d, $item, $c, $week);
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
    'Fastest'         => '最早到達',
    'ShortestTime'    => '最短行車時間',
    'FewestTransfers' => '最少轉乘',
    'Direct'          => '直達',
];

function appTripJourneys()
{
    appDb();
    list($from, $to) = appFromTo();
    $m = appModel();
    $extra = appParamExtra();
    list($week, $date) = appParamWeek();
    list($mode, $start, $end) = appJourneyMode($date);
    $back = $mode === 'arrive' || $mode === 'last';
    $sort = appParamChoice('sort', ['arrival', 'transfers', 'duration'], $back ? 'late' : 'arrival');
    $limit = appParamInt('limit', APP_JOURNEYS_LIMIT_DEFAULT, 1, APP_JOURNEYS_LIMIT_MAX);
    $opt = ['extra' => $extra, 'max_transfers' => appParam('max_transfers') !== null ? appParamInt('max_transfers', null, 0, APP_MAX_TRANSFERS_MAX) : null,
            'via' => appParam('via') !== null ? appStationParam('via') : null, 'limit' => APP_JOURNEY_ROUTES, 'variants' => true, 'deeper' => appParamInt('deeper', min(appRouteSearch()['Deeper'], APP_DEEPER_MAX), 0, APP_DEEPER_MAX)];
    list($data, $meta) = appJourneysCompute(compact('from', 'to', 'extra', 'week', 'date', 'mode', 'start', 'end', 'sort', 'limit', 'opt'));
    appSend($data, $meta);
}

/**
 * journeys 的計算（API 與路由回歸測試共用）。$q：from、to、extra、week、date、mode（depart／arrive／first／last）、
 * start、end（營運日分鐘）、sort、limit、opt（appCoreRoutes 的選項）。回傳 [data, meta]
 */
function appJourneysCompute(array $q)
{
    extract($q);
    $m = appModel();
    $back = $mode === 'arrive' || $mode === 'last';
    $t0 = microtime(true);
    $routes = appCoreRoutes($m, $from, $to, $opt);
    $d = &appRouterData($week);
    // 每條路由串接，收集所有旅程（同一組班次只留一個）
    $all = [];
    foreach ($routes as $r) {
        $chains = $back ? appChainRouteBack($d, $r['legs'], $extra, $start, $end) : appChainRoute($d, $r['legs'], $extra, $start, $end);
        foreach ($chains as $c) {
            $j = appJourneyOut($d, $r, $c, $week);
            if (!isset($all[$j['_sig']]) || $all[$j['_sig']]['TransferCount'] > $j['TransferCount']) {
                $all[$j['_sig']] = $j;
            }
        }
    }
    $all = array_values($all);
    // 互不支配：較晚（或同時）出發、較早（或同時）到達、轉乘不多於，且至少一項較好的方案淘汰其他
    $keep = [];
    foreach ($all as $i => $a) {
        $dominated = false;
        foreach ($all as $k => $b) {
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
            $keep[] = $a;
        }
    }
    // 明顯繞遠的不列：行車時間超過最短那個的 1.5 倍再多 30 分、轉乘又不比它少
    if ($keep) {
        $short = $keep[0];
        foreach ($keep as $j) {
            if ([$j['TravelMinutes'], $j['TransferCount']] < [$short['TravelMinutes'], $short['TransferCount']]) {
                $short = $j;
            }
        }
        $keep = array_values(array_filter($keep, function ($j) use ($short) {
            return $j['TravelMinutes'] <= $short['TravelMinutes'] * 1.5 + 30 || $j['TransferCount'] < $short['TransferCount'];
        }));
    }
    // 排序與標籤
    $cmp = [
        'arrival'   => function ($a, $b) { return [$a['ArrivalMinute'], $a['TransferCount'], -$a['DepartureMinute']] <=> [$b['ArrivalMinute'], $b['TransferCount'], -$b['DepartureMinute']]; },
        'transfers' => function ($a, $b) { return [$a['TransferCount'], $a['ArrivalMinute']] <=> [$b['TransferCount'], $b['ArrivalMinute']]; },
        'duration'  => function ($a, $b) { return [$a['TravelMinutes'], $a['TransferCount'], $a['DepartureMinute']] <=> [$b['TravelMinutes'], $b['TransferCount'], $b['DepartureMinute']]; },
        // 抵達時刻模式、末班沒給 sort 時：出發由晚到早（轉乘少、到達早的優先）
        'late'      => function ($a, $b) { return [-$a['DepartureMinute'], $a['TransferCount'], $a['ArrivalMinute']] <=> [-$b['DepartureMinute'], $b['TransferCount'], $b['ArrivalMinute']]; },
    ];
    $best = [];
    foreach (['Fastest' => 'arrival', 'ShortestTime' => 'duration', 'FewestTransfers' => 'transfers'] as $tag => $s) {
        $tmp = $keep;
        usort($tmp, $cmp[$s]);
        if ($tmp) {
            $best[$tag] = $tmp[0]['_sig'];
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
        unset($j['_sig']);
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
       + appExtraInfo($extra) + ['routes' => count($routes), 'seconds' => round(microtime(true) - $t0, 3)]];
}

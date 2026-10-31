<?php
/**
 * 核心 B：點對點時刻表，以及時刻類的 app_ API。
 *
 *   核心 B  appCoreDirect()：兩站間在時段內「同一班車停兩站」的所有班次（臺鐵、高鐵、捷運、輕軌共用），
 *           依 extra 與車種等級在取資料時就排除（列車層排除），可依最早出發、最早抵達、最短時間排序。
 *           資料用 transfer 的路網時刻（transferRouterData：臺鐵高鐵車次、捷運整列車與營運路線），與轉乘查詢一致。
 *
 *   /txapi/app_trip/direct        起訖站間行車時刻表（只用核心 B）
 *   /txapi/app_station/timetable  單站時刻表（看資料，不受 extra 影響）
 *   /txapi/app_station/departures 即將發車（各方向前 N 班）
 *   /txapi/app_train/stops        列車各站時刻（臺鐵、高鐵用車次代碼；捷運用 direct 回傳的班次代碼）
 *
 * 時間：查詢參數用營運日（04:00～27:59，午夜後寫 24 時以後）；回應的 *Time 為 HH:MM（00～23），另附 *Minute＝營運日的分鐘數（可超過 1440）以便排序。
 * 沒給 week、date 時用「現在」的營運日：凌晨 00:00～03:59 算前一天。
 */

/** 班次代碼的雜湊長度（捷運整列車、營運路線的代碼用） */
const APP_RUN_HASH_LEN = 8;

// ================================================================
// 營運日、參數

/** 現在的營運日：[week, date, minute]（凌晨 00:00～03:59 算前一天，minute 加 1440） */
function appServiceNow()
{
    $now = new DateTime('now', new DateTimeZone('Asia/Taipei'));
    $min = (int) $now->format('G') * 60 + (int) $now->format('i');
    if ($min < 240) {
        $now->modify('-1 day');
        $min += 1440;
    }
    return [(int) $now->format('w'), $now->format('Y-m-d'), $min];
}

/** week（0～6）或 date（YYYY-MM-DD）；都沒給用現在的營運日。回傳 [week, date|null] */
function appParamWeek()
{
    $date = appParam('date');
    if ($date !== null) {
        $d = DateTime::createFromFormat('!Y-m-d', $date, new DateTimeZone('Asia/Taipei'));
        if (!$d || $d->format('Y-m-d') !== $date) {
            appFail(40020, ['param' => 'date', 'detail' => '格式是 YYYY-MM-DD']);
        }
        return [(int) $d->format('w'), $date];
    }
    $w = appParam('week');
    if ($w !== null) {
        if (!preg_match('/^[0-6]$/', $w)) {
            appFail(40020, ['param' => 'week', 'detail' => '要是 0～6（0＝星期日）']);
        }
        return [(int) $w, null];
    }
    list($week, $date) = appServiceNow();
    return [$week, $date];
}

/** 營運日時間 HH:MM（00:00～27:59）→ 分鐘；沒給回 $default */
function appParamTime($name, $default = null)
{
    $v = appParam($name);
    if ($v === null) {
        return $default;
    }
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', $v, $m) || (int) $m[1] > 27 || (int) $m[2] > 59) {
        appFail(40020, ['param' => $name, 'detail' => '格式是 HH:MM，營運日 00:00～27:59（午夜後寫 24 時以後，例如 24:30）']);
    }
    return (int) $m[1] * 60 + (int) $m[2];
}

/** 選項參數（不分大小寫） */
function appParamChoice($name, array $allowed, $default)
{
    $v = appParam($name);
    if ($v === null) {
        return $default;
    }
    $v = strtolower($v);
    if (!in_array($v, $allowed, true)) {
        appFail(40020, ['param' => $name, 'detail' => '可用：' . implode('、', $allowed)]);
    }
    return $v;
}

/** 車種等級參數 class（local／express）；沒給回 null */
function appParamClass()
{
    return appParamChoice('class', [APP_CLASS_LOCAL, APP_CLASS_EXPRESS], null);
}

/** 營運日分鐘 → HH:MM（午夜後 24～27 時） */
function appHM($min)
{
    $min = max(0, (int) $min);   // 營運日時刻：午夜後寫 24 時以後，不折回 00 時
    return sprintf('%02d:%02d', intdiv($min, 60), $min % 60);
}

/**
 * HH:MM（00～23 時）換成營運日分鐘：取不早於 $ref（$after）或不晚於 $ref（!$after）、最接近 $ref 的那一個。
 * 回應的 *Time 一律 00～23 時，另附 *Minute（營運日分鐘，可超過 1440），前端用分鐘數判斷跨日
 */
function appMinuteNear($hm, $ref, $after = true)
{
    if ($hm === null || $hm === '' || $ref === null) {
        return null;
    }
    $m = ((int) substr($hm, 0, 2) * 60 + (int) substr($hm, 3, 2)) % 1440;
    $r = (int) $ref;
    $diff = (($m - $r % 1440) % 1440 + 1440) % 1440;   // 從 ref 往後幾分
    return $after ? $r + $diff : $r - ($diff === 0 ? 0 : 1440 - $diff);
}

/** 停靠站清單（ArrivalTime／DepartureTime）補上 ArrivalMinute／DepartureMinute：從第一站的營運日分鐘依序往後推（已有的不動） */
function appStopMinutes(array $list, $startMin)
{
    $ref = $startMin;
    foreach ($list as &$s) {
        if (!array_key_exists('ArrivalMinute', $s)) {
            $s['ArrivalMinute'] = appMinuteNear($s['ArrivalTime'] ?? null, $ref);
        }
        $ref = $s['ArrivalMinute'] ?? $ref;
        if (!isset($s['DepartureMinute'])) {
            $s['DepartureMinute'] = appMinuteNear($s['DepartureTime'] ?? null, $ref);
        }
        $ref = $s['DepartureMinute'] ?? $ref;
    }
    unset($s);
    return $list;
}

// ================================================================
// 路網時刻資料與列車分類

/** 某星期的路網時刻資料（每次請求每個星期載入一次） */
function &appRouterData($week)
{
    static $cache = [];
    if (!isset($cache[$week])) {
        $cache[$week] = transferRouterData(appDb(), $week);
    }
    return $cache[$week];
}

/** 臺鐵、高鐵的車次資訊：train_key → [type_code, info] */
function appTrainRows(array $keys)
{
    static $rows = [];
    $need = array_values(array_diff(array_unique($keys), array_keys($rows)));
    foreach (array_chunk($need, 400) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        foreach (appDb()->all("SELECT train_key, train_type_code, info FROM train WHERE train_key IN ($in)", $chunk) as $r) {
            $rows[$r['train_key']] = ['type_code' => $r['train_type_code'], 'info' => json_decode($r['info'], true)];
        }
    }
    return array_intersect_key($rows, array_flip($keys));
}

/** 路線站序（依停靠站分類用）：系統、路線代碼 → 站序 */
function appLineStationsOf($system, $line)
{
    $m = appModel();
    $lu = $system === 'THSR' ? 'THSR-HSRL' : $system . '-' . $line;
    return $m['lineStations'][$lu] ?? [];
}

/** 一個搭乘段參考（transferFollowLeg 的 ['trip'|'mrun'|'pattern', …]）的車種等級 */
function appRideClass(array &$d, array $par)
{
    if ($par[0] === 'trip') {
        $tr = $d['trips'][$par[1]];
        if ($tr['system'] === 'TRA') {
            return appTrainClassByType(appTrainRows([$tr['key']])[$tr['key']]['type_code'] ?? '');
        }
        return appClassHasSplit($tr['system']) ? appTrainClassByStops(appLineStationsOf($tr['system'], ''), $tr['st']) : APP_CLASS_LOCAL;
    }
    if ($par[0] === 'mrun') {
        $mr = $d['mroutes'][$par[1]];
        return appClassHasSplit($mr['system']) ? appTrainClassByStops(appLineStationsOf($mr['system'], $mr['line']), $mr['st']) : APP_CLASS_LOCAL;
    }
    $p = $d['patterns'][$par[1]];
    return appClassHasSplit($p['system']) ? appTrainClassByStops(appLineStationsOf($p['system'], $p['line']), $p['st']) : APP_CLASS_LOCAL;
}

/** 班次代碼：臺鐵、高鐵是車次代碼（TRA-1132）；捷運整列車 SYS-LINE-R<雜湊>-<班>-w<星期>；捷運營運路線 SYS-LINE-P<雜湊>-<站序>-<時刻群組>-<秒>-w<星期> */
function appTrainCode(array &$d, array $par, $week)
{
    if ($par[0] === 'trip') {
        return $d['trips'][$par[1]]['key'];
    }
    if ($par[0] === 'mrun') {
        $mr = $d['mroutes'][$par[1]];
        return $mr['system'] . '-' . $mr['line'] . '-R' . substr(sha1($par[1]), 0, APP_RUN_HASH_LEN) . '-' . $par[4] . '-w' . $week;
    }
    $p = $d['patterns'][$par[1]];
    return $p['system'] . '-' . $p['line'] . '-P' . substr(sha1($par[1]), 0, APP_RUN_HASH_LEN) . '-' . $par[2] . '-' . $par[4][1] . '-' . $par[4][0] . '-w' . $week;
}

/** 搭乘段的輸出（核心 B、轉乘串接共用） */
function appRideOut(array &$d, array $par, $arrSec, $week, $class = null)
{
    $r = transferRideStep($d, $par, $arrSec);
    $m = $d['m'];
    $class = $class ?? appRideClass($d, $par);
    $sys = $r['System'];
    $code = appTrainCode($d, $par, $week);
    $out = [
        // Train：真實的車次代碼（臺鐵、高鐵）；捷運沒有車次，給 null（系統自動編的班次代碼不是車次，不給前端當車次顯示）
        // RunCode：查 app_train/stops 用的班次代碼（臺鐵、高鐵＝車次代碼；捷運＝系統編的代碼，只在同一份離線資料內有效）
        'Train'         => $par[0] === 'trip' ? $code : null,
        'RunCode'       => $code,
        'System'        => $sys,
        'LineUID'       => $r['LineUID'],
        'TrainNo'       => $r['TrainNo'] ?? null,
        'TrainType'     => null,
        'Class'         => appClassHasSplit($sys) ? $class : null,
        'ClassLabel'    => appClassHasSplit($sys) ? appClassLabel($sys, $class) : null,
        'Direction'     => $r['Direction'],
        'RouteID'       => $r['RouteID'] ?? null,
        'From'          => ['StationUID' => $r['FromStationUID'], 'StationName' => $m['names'][$r['FromStationUID']] ?? null],
        'To'            => ['StationUID' => $r['ToStationUID'], 'StationName' => $m['names'][$r['ToStationUID']] ?? null],
        'Destination'   => $r['EndingStationUID'] ? ['StationUID' => $r['EndingStationUID'], 'StationName' => $r['EndingStationName']] : null,
        'DepartureTime' => transferHM($r['_dep']),
        'ArrivalTime'   => transferHM($r['_arr']),
        'DepartureMinute' => intdiv($r['_dep'], 60),
        'ArrivalMinute' => intdiv($r['_arr'], 60),
        'TravelMinutes' => $r['Minutes'],
        'Stops'         => $r['Stops'],
        'DepartureSource' => $r['DepartureSource'],
        'ArrivalSource' => $r['ArrivalSource'],
        'DataTier'      => $r['DataTier'],
        'DistanceKm'    => $r['DistanceKm'],
    ];
    if ($par[0] === 'trip') {
        $row = appTrainRows([$r['TrainKey']])[$r['TrainKey']] ?? null;
        $out['TrainType'] = ($row['type_code'] ?? '') === '' ? null : ['Code' => $row['type_code'], 'Name' => $r['TrainTypeName']];
        $info = $row['info'] ?? [];
        if (!empty($info['StartingStationUID'])) {
            $out['Origin'] = ['StationUID' => $info['StartingStationUID'], 'StationName' => $info['StartingStationName'] ?? null];
        }
        $out['HeadSign'] = $info['TripHeadSign'] ?? '';
        $out['TripLine'] = $info['TripLine'] ?? '';
        $tr = $d['trips'][$par[1]];
        $stops = array_slice($tr['st'], $par[2], $par[3] - $par[2] + 1);
        $lineIds = array_map(function ($l) use ($sys) { return $sys . '-' . $l; }, $info['LineIDs'] ?? []);
        $out['Path'] = appTrainPath($m, $stops, $lineIds, (int) ($info['TripLine'] ?? 0));
    } else {
        if (!empty($r['TrainType']) && appClassHasSplit($sys)) {
            $out['TrainType'] = ['Code' => (string) $r['TrainType'], 'Name' => ['Zh_tw' => FINDER_TRAIN_TYPES[$r['TrainType']] ?? '', 'En' => '']];
        }
        $out['Path'] = [['LineUID' => $r['LineUID'], 'LineName' => $m['lineNames'][$r['LineUID']] ?? null, 'From' => $out['From'], 'To' => $out['To']]];
    }
    return $out;
}

/**
 * 列車（臺鐵、高鐵）一段搭乘經過的路線：沿停靠站逐段找兩站共同的路線（優先用列車 LineIDs 裡目前的路線、TripLine 1 山線 2 海線），
 * 相鄰停靠站沒有共同路線時（不停交會站，例如不停八堵）在兩條路線的交會站切開。回傳 [{LineUID, From, To}, ...]（同一路線連續的合併）
 */
function appTrainPath(array $m, array $stops, array $lineIds, $tripLine = 0)
{
    $onLine = function ($lu, $u) use ($m) {
        return isset($m['stationLines'][$u][$lu]);
    };
    $prefer = function (array $cands, $cur) use ($lineIds, $tripLine) {
        if ($cur !== null && in_array($cur, $cands, true)) {
            return $cur;
        }
        if ($tripLine === 2) {
            foreach ($cands as $c) {
                if (substr($c, -2) === '-C') {
                    return $c;   // 海線
                }
            }
        }
        foreach ($lineIds as $l) {
            if (in_array($l, $cands, true)) {
                return $l;
            }
        }
        return $cands[0];
    };
    $pieces = [];
    $cur = null;
    for ($i = 0; $i + 1 < count($stops); $i++) {
        list($a, $b) = [$stops[$i], $stops[$i + 1]];
        $common = array_values(array_intersect(array_keys($m['stationLines'][$a] ?? []), array_keys($m['stationLines'][$b] ?? [])));
        if ($common) {
            $cur = $prefer($common, $cur);
            $pieces[] = [$cur, $a, $b];
            continue;
        }
        // 不停交會站：找 a 的路線與 b 的路線的交會站
        $done = false;
        foreach (array_keys($m['stationLines'][$a] ?? []) as $la) {
            foreach (array_keys($m['stationLines'][$b] ?? []) as $lb) {
                foreach ($m['lineStations'][$la] ?? [] as $j) {
                    if ($j !== $a && $j !== $b && $onLine($lb, $j)) {
                        $pieces[] = [$la, $a, $j];
                        $pieces[] = [$lb, $j, $b];
                        $cur = $lb;
                        $done = true;
                        break 3;
                    }
                }
            }
        }
        if (!$done) {
            $pieces[] = [$cur ?? ($lineIds[0] ?? null), $a, $b];
        }
    }
    $out = [];
    foreach ($pieces as list($lu, $a, $b)) {
        $k = count($out) - 1;
        if ($k >= 0 && $out[$k]['LineUID'] === $lu) {
            $out[$k]['To'] = $b;
            continue;
        }
        $out[] = ['LineUID' => $lu, 'From' => $a, 'To' => $b];
    }
    return array_map(function ($p) use ($m) {
        return ['LineUID' => $p['LineUID'], 'LineName' => $m['lineNames'][$p['LineUID']] ?? null,
                'From' => ['StationUID' => $p['From'], 'StationName' => $m['names'][$p['From']] ?? null],
                'To' => ['StationUID' => $p['To'], 'StationName' => $m['names'][$p['To']] ?? null]];
    }, $out);
}

// ================================================================
// 通過站

/** 相鄰兩個停靠站之間最多補幾個通過站；超過的視為資料對不上（例如環狀線繞回首站），不補 */
const APP_PASS_MAX = 40;

/**
 * 相鄰兩個停靠站之間「通過」（不停）的車站：[k => [StationUID, ...]]，k 是前一個停靠站在 $stops 的索引，車站依行進方向排列。
 * 兩站之間的路線用 appTrainPath 找（與 Path 相同；臺鐵 TripLine 1 山線、2 海線），同一條路線上兩站之間的站都算通過；
 * 不停路線交會站時（例如不停八堵），交會站也算通過。
 * 只算有快慢車之分的系統（臺鐵、高鐵、機捷等，appClassHasSplit）：其他捷運、輕軌每站都停，路線站序也可能含支線，不補。
 * 資料上的別名站（NoTransferStations 有 AliasOf 的，例如臺北-環島 TRA-1001＝臺北）是同一個車站的另一個站碼，不算通過
 */
function appPassStations(array $m, $sys, array $stops, array $lineIds, $tripLine = 0)
{
    $out = [];
    if (!in_array($sys, FINDER_TRAIN_SYSTEMS, true) && !appClassHasSplit($sys)) {
        return $out;
    }
    $alias = appNoTransferStations();
    $isAlias = function ($u) use ($alias) {
        return is_string($alias[$u] ?? null);
    };
    $n = count($stops);
    for ($k = 0; $k + 1 < $n; $k++) {
        $pass = [];
        $ok = true;
        foreach (appTrainPath($m, [$stops[$k], $stops[$k + 1]], $lineIds, $tripLine) as $i => $p) {
            $lu = $p['LineUID'];
            $a = $m['stationLines'][$p['From']['StationUID']][$lu] ?? null;
            $b = $m['stationLines'][$p['To']['StationUID']][$lu] ?? null;
            if ($lu === null || $a === null || $b === null) {
                $ok = false;
                break;
            }
            if ($i > 0) {
                $pass[] = $p['From']['StationUID'];   // 不停的路線交會站
            }
            $seq = $m['lineStations'][$lu];
            if ($a < $b) {
                for ($j = $a + 1; $j < $b; $j++) {
                    $pass[] = $seq[$j];
                }
            } else {
                for ($j = $a - 1; $j > $b; $j--) {
                    $pass[] = $seq[$j];
                }
            }
        }
        $pass = array_values(array_filter($pass, function ($u) use ($isAlias) { return !$isAlias($u); }));
        if ($ok && $pass && count($pass) <= APP_PASS_MAX) {
            $out[$k] = $pass;
        }
    }
    return $out;
}

/**
 * 停靠站清單（每項有 StationUID）插入通過站（appPassStations 的結果）：停靠站加 Pass＝false，
 * 通過站是 {StationUID, StationName, ArrivalTime: null, DepartureTime: null, Pass: true}（沒有時刻）
 */
function appStopsWithPass(array $m, array $list, array $pass)
{
    $out = [];
    foreach (array_values($list) as $k => $s) {
        $out[] = $s + ['Pass' => false];
        foreach ($pass[$k] ?? [] as $u) {
            $out[] = ['StationUID' => $u, 'StationName' => $m['names'][$u] ?? null, 'ArrivalTime' => null, 'DepartureTime' => null, 'Pass' => true];
        }
    }
    return $out;
}

/** 一個搭乘段（['trip'|'mrun'|'pattern', …]）上下車站之間的各站時刻（transferRideStops；$arrSec＝下車站時刻）並插入通過站 */
function appRideStopTimes(array &$d, array $par, $arrSec = null)
{
    $list = transferRideStops($d, $par, $arrSec);
    if ($par[0] === 'trip') {
        $tr = $d['trips'][$par[1]];
        $sys = $tr['system'];
        $info = appTrainRows([$tr['key']])[$tr['key']]['info'] ?? [];
        $lineIds = array_map(function ($l) use ($sys) { return $sys . '-' . $l; }, $info['LineIDs'] ?? []);
        $tripLine = (int) ($info['TripLine'] ?? 0);
    } else {
        $x = $par[0] === 'mrun' ? $d['mroutes'][$par[1]] : $d['patterns'][$par[1]];
        $sys = $x['system'];
        $lineIds = [$sys . '-' . $x['line']];
        $tripLine = 0;
    }
    return appStopsWithPass($d['m'], $list, appPassStations($d['m'], $sys, array_column($list, 'StationUID'), $lineIds, $tripLine));
}

// ================================================================
// 核心 B：點對點時刻表

/**
 * 兩站間的直達班次。$opt：
 *   extra    排除位元（appParamExtra）
 *   class    local／express／null（只要該等級的車）
 *   line     捷運只看這條路線（LineUID，null＝兩站共同的所有路線）
 *   start、end  時段（營運日分鐘，null＝不限）；by＝dep 比出發時刻（預設）、arr 比抵達時刻
 *   sort     depart（最早出發）、arrival（最早抵達）、duration（最短時間）
 * 回傳 ['rows' => [[dep秒, arr秒, par, 車輛代碼, class, 環狀線繞遠方向?], ...], 'note' => 說明或 null]
 */
function appCoreDirect(array &$d, $from, $to, array $opt)
{
    $m = $d['m'];
    $sysA = substr($from, 0, strpos($from, '-'));
    $sysB = substr($to, 0, strpos($to, '-'));
    if ($sysA !== $sysB) {
        return ['rows' => [], 'note' => '兩站不在同一個系統，沒有直達班次（要轉乘）'];
    }
    if (!appSystemAllowed($opt['extra'] ?? 0, $sysA)) {
        return ['rows' => [], 'note' => 'extra 含 2（不搭高鐵）'];
    }
    $legs = [];
    if (in_array($sysA, FINDER_TRAIN_SYSTEMS, true)) {
        $legs[] = [$sysA, null, $from, $to];
    } else {
        $common = array_intersect(array_keys($m['stationLines'][$from] ?? []), array_keys($m['stationLines'][$to] ?? []));
        foreach ($common as $lu) {
            if (empty($opt['line']) || $opt['line'] === $lu) {
                $legs[] = [$sysA, $lu, $from, $to];
            }
        }
        if (!$legs) {
            return ['rows' => [], 'note' => '兩站不在同一條路線上，沒有直達班次（要轉乘）'];
        }
    }
    $start = isset($opt['start']) ? $opt['start'] * 60 : null;
    $end = isset($opt['end']) ? $opt['end'] * 60 + 59 : null;
    $byArr = ($opt['by'] ?? 'dep') === 'arr';
    $rows = [];
    $seen = [];
    foreach ($legs as $leg) {
        foreach (transferFollowLeg($d, $leg)['list'] as $x) {
            list($dep, $arr, $par, $vid) = $x;
            $t = $byArr ? $arr : $dep;
            if (($start !== null && $t < $start) || ($end !== null && $t > $end) || isset($seen[$vid])) {
                continue;
            }
            $class = appRideClass($d, $par);
            if (!appClassAllowed($opt['extra'] ?? 0, $sysA, $class) || (!empty($opt['class']) && appClassHasSplit($sysA) && $class !== $opt['class'])) {
                continue;
            }
            $seen[$vid] = true;
            $rows[] = [$dep, $arr, $par, $vid, $class];
        }
    }
    // 環狀線兩個方向都到得了：繞遠路的方向（行車時間中位數超過最短方向的 1.5 倍）照列（同路線直達一定要有），標成繞遠、排在後面
    $byDir = [];
    foreach ($rows as $i => $x) {
        if ($x[2][0] !== 'trip') {
            $dirKey = $x[2][0] === 'mrun' ? $d['mroutes'][$x[2][1]]['line'] . '|' . $d['mroutes'][$x[2][1]]['trips'][$x[2][4]]['dir']
                                          : $d['patterns'][$x[2][1]]['line'] . '|' . $d['patterns'][$x[2][1]]['dir'];
            $byDir[$dirKey][$i] = $x[1] - $x[0];
        }
    }
    if (count($byDir) > 1) {
        $med = [];
        foreach ($byDir as $k => $v) {
            sort($v);
            $med[$k] = $v[intdiv(count($v), 2)];
        }
        $best = min($med);
        foreach ($byDir as $k => $v) {
            if ($med[$k] > $best * 1.5) {
                foreach (array_keys($v) as $i) {
                    $rows[$i][5] = true;
                }
            }
        }
    }
    $sort = $opt['sort'] ?? 'depart';
    usort($rows, function ($a, $b) use ($sort) {
        if (!empty($a[5]) !== !empty($b[5])) {
            return empty($a[5]) ? -1 : 1;   // 環狀線繞遠方向排在後面
        }
        if ($sort === 'arrival') {
            return [$a[1], -$a[0]] <=> [$b[1], -$b[0]];
        }
        if ($sort === 'duration') {
            return [$a[1] - $a[0], $a[0]] <=> [$b[1] - $b[0], $b[0]];
        }
        return [$a[0], $a[1]] <=> [$b[0], $b[1]];
    });
    $note = null;
    if (!$rows) {
        $note = '這個時段沒有符合條件的直達班次';
    }
    return ['rows' => $rows, 'note' => $note];
}

// ================================================================
// app_trip

function actionAppTrip()
{
    appDispatch('app_trip', [
        'direct' => ['appTripDirect', '起訖站間行車時刻表：不轉乘、同一班車停兩站的所有班次（核心 B）',
                     ['from' => '起站 StationUID（必填）', 'to' => '迄站 StationUID（必填）', 'date' => 'YYYY-MM-DD（或 week＝0～6；省略為現在的營運日）',
                      'start' => '時段起 HH:MM（營運日；start、end 都省略為全日）', 'end' => '時段迄 HH:MM（只給 start 時＝start＋系統最長查詢時段）', 'by' => 'dep（出發時刻在時段內，預設）／arr（抵達時刻在時段內）',
                      'extra' => '排除位元 0～3（1 不搭臺鐵對號、2 不搭高鐵）', 'class' => 'local／express（只要慢車或快車）', 'line' => '捷運只看這條路線',
                      'direction' => 'TDX 的行駛方向 0／1（每班的 Direction；臺鐵 0 順行＝順時針、1 逆行＝逆時針；舊名 dir 照收）', 'sort' => 'depart（預設）／arrival／duration', 'limit' => '最多幾班（1～' . APP_DIRECT_LIMIT_MAX . '，預設 ' . APP_DIRECT_LIMIT_MAX . '）'],
                     txapiUrl('app_trip', 'direct', ['from' => 'TRA-1000', 'to' => 'TRA-1210', 'week' => 1, 'start' => '07:00', 'end' => '09:00'])],
        'routes' => ['appTripRoutes', '起訖站間轉乘路由：不看時刻的走法（搭哪些路線、在哪裡轉乘、車種等級、估計分鐘），每個路由帶 route（核心 A）',
                     ['from' => '起站（必填）', 'to' => '迄站（必填）', 'extra' => '排除位元 0～3', 'max_transfers' => '最多轉乘次數 0～' . APP_MAX_TRANSFERS_MAX, 'via' => '經由車站', 'limit' => '最多幾個（1～' . APP_ROUTES_LIMIT_MAX . '，預設依找路規則 Search.DefaultLimit）'],
                     txapiUrl('app_trip', 'routes', ['from' => 'TRA-0950', 'to' => 'TRA-3300'])],
        'route_trains' => ['appTripRouteTrains', '帶入路由取列車組合：同一個路由在出發時段內所有串得起來的班次（每段核心 B → 串接）',
                     ['route' => '路由代碼（必填）', 'date' => 'YYYY-MM-DD（或 week）', 'start' => '時段起 HH:MM（省略為現在）', 'end' => '時段迄（預設 start＋系統最長查詢時段）',
                      'by' => 'dep（出發時刻在時段內，預設）／arr（抵達時刻在時段內）', 'extra' => '排除位元 0～3',
                      'stops' => '1＝搭乘段帶途中各站時刻 StopTimes（預設）、0＝不帶'],
                     txapiUrl('app_trip', 'route_trains', ['route' => 'TRA-0950.TRA.local.TRA-1000.TRA.express.TRA-3300', 'week' => 1, 'start' => '08:00', 'end' => '10:00'])],
        'journeys' => ['appTripJourneys', '起訖站間各種轉乘列車組合：找出路由、每段取時刻、串接，依最早到達／最少轉乘／最短行車時間排序並加標籤（核心 A＋B）',
                     ['from' => '起站（必填）', 'to' => '迄站（必填）', 'date' => 'YYYY-MM-DD（或 week）', 'start' => '時段起 HH:MM（省略為現在）', 'end' => '時段迄（預設 start＋系統最長查詢時段；只給 end 時往前同樣長度）',
                      'by' => 'dep（出發時刻在時段內，預設）／arr（抵達時刻在時段內，反向串接）', 'first' => '1＝當天最早能到（不能與時段併用）', 'last' => '1＝當天最晚還能到',
                      'extra' => '排除位元 0～3', 'sort' => 'arrival（預設）／transfers／duration', 'max_transfers' => '最多轉乘次數', 'via' => '經由車站', 'limit' => '最多幾個（1～' . APP_JOURNEYS_LIMIT_MAX . '，預設 ' . APP_JOURNEYS_LIMIT_DEFAULT . '）',
                      'stops' => '1＝搭乘段帶途中各站時刻 StopTimes（預設）、0＝不帶'],
                     txapiUrl('app_trip', 'journeys', ['from' => 'TRA-0950', 'to' => 'TRA-1210', 'week' => 1, 'start' => '08:00', 'end' => '10:00'])],
    ]);
}

function appTripDirect()
{
    appDb();
    $from = appStationParam('from');
    $to = appStationParam('to');
    if ($from === $to) {
        appFail(40020, ['param' => 'to', 'detail' => '起訖是同一站']);
    }
    list($week, $date) = appParamWeek();
    list($by, $start, $end) = appTimeWindow($date, true, false);
    $opt = [
        'extra' => appParamExtra(),
        'class' => appParamClass(),
        'line'  => appParam('line') !== null ? appLineParam('line') : null,
        'start' => $start,
        'end'   => $end,
        'by'    => $by,
        'sort'  => appParamChoice('sort', ['depart', 'arrival', 'duration'], 'depart'),
    ];
    $dirName = appParam('direction') !== null ? 'direction' : 'dir';
    $dir = appParam($dirName) !== null ? appParamChoice($dirName, ['0', '1'], null) : null;   // TDX 的 Direction（0／1）
    $limit = appParamInt('limit', APP_DIRECT_LIMIT_MAX, 1, APP_DIRECT_LIMIT_MAX);
    $t0 = microtime(true);
    if (substr($from, 0, strpos($from, '-')) !== substr($to, 0, strpos($to, '-'))) {
        $d = ['m' => appModel()];   // 不同系統：不必載入時刻資料
    } else {
        $d = &appRouterData($week);
    }
    $r = appCoreDirect($d, $from, $to, $opt);
    // 每班帶 Path（經過的路線與每段起訖站）、Direction（TDX 行駛方向）；Train 可再查 app_train/stops 取沿途停靠站與時刻
    $all = [];
    foreach ($r['rows'] as $x) {
        $o = appRideOut($d, $x[2], $x[1], $week, $x[4]) + ['LongWay' => !empty($x[5])];
        if ($dir === null || (string) $o['Direction'] === $dir) {
            $all[] = $o;
        }
    }
    $out = array_slice($all, 0, $limit);
    $m = $d['m'];
    appSend([
        'From'  => ['StationUID' => $from, 'StationName' => $m['names'][$from] ?? null],
        'To'    => ['StationUID' => $to, 'StationName' => $m['names'][$to] ?? null],
        'count' => count($out), 'total' => count($all), 'note' => $all ? $r['note'] : ($r['note'] ?? '這個時段沒有符合條件的直達班次'), 'trains' => $out,
    ], ['week' => $week, 'weekday' => FINDER_WEEKDAYS[$week], 'date' => $date] + appExtraInfo($opt['extra'])
       + array_filter(['class' => $opt['class'], 'line' => $opt['line'], 'direction' => $dir], function ($v) { return $v !== null; }) + appTimeWindowMeta($by, $start, $end)
       + ['sort' => $opt['sort'], 'seconds' => round(microtime(true) - $t0, 3)]);
}

// ================================================================
// app_station：單站時刻表、即將發車

function actionAppStation()
{
    $common = ['station' => '車站 StationUID（必填）', 'date' => 'YYYY-MM-DD（或 week＝0～6；省略為現在的營運日）',
               'direction' => '0／1', 'line' => '路線 LineUID', 'destination' => '終點站 StationUID（只看往這站的車）', 'class' => 'local／express'];
    appDispatch('app_station', [
        'timetable'  => ['appStationTimetable', '單站時刻表：依路線與方向分組的發車，每班有發車時刻、車次、車種、終點站、資料性質（不受 extra 影響）',
                         $common + ['start' => '時段起 HH:MM（省略為全日）', 'end' => '時段迄 HH:MM'],
                         txapiUrl('app_station', 'timetable', ['station' => 'TRA-1000', 'week' => 1])],
        'departures' => ['appStationDepartures', '即將發車：指定時刻（預設現在）以後各方向的前 N 班',
                         $common + ['start' => '時刻 HH:MM（營運日，省略為現在；舊名 time 照收）', 'count' => '每個方向幾班（1～' . APP_DEPARTURES_COUNT_MAX . '，預設 ' . APP_DEPARTURES_COUNT_DEFAULT . '）'],
                         txapiUrl('app_station', 'departures', ['station' => 'TRTC-BL12'])],
    ]);
}

/**
 * 某站的發車（臺鐵、高鐵：列車時刻；捷運、輕軌：站別時刻表，沒有真實時刻表的車站用推算）。
 * $f：week、start、end（分鐘）、direction、line、destination、class。回傳依時刻排序的班次
 */
function appStationDepartureRows($uid, array $f)
{
    $db = appDb();
    $m = appModel();
    $sys = substr($uid, 0, strpos($uid, '-'));
    $isTrain = in_array($sys, FINDER_TRAIN_SYSTEMS, true);
    $where = '';
    $params = [$uid, 1 << $f['week']];
    $col = $isTrain ? 's.sort_min' : 'd.sort_min';
    if ($f['start'] !== null) {
        $where .= " AND $col >= ?";
        $params[] = $f['start'];
    }
    if ($f['end'] !== null) {
        $where .= " AND $col <= ?";
        $params[] = $f['end'];
    }
    if ($f['direction'] !== null) {
        $where .= $isTrain ? ' AND t.direction = ?' : ' AND g.direction = ?';
        $params[] = $f['direction'];
    }
    $out = [];
    $names = $m['names'];
    $st = function ($u) use ($names) {
        return $u === '' || $u === null ? null : ['StationUID' => $u, 'StationName' => $names[$u] ?? null];
    };
    if ($isTrain) {
        $rows = $db->all("SELECT t.train_key, t.train_type_code, t.info, s.seq, s.arr, s.dep, s.sort_min, s.arr_src, s.dep_src,
                                 (SELECT MAX(seq) FROM train_stop x WHERE x.train_key = s.train_key) AS last_seq,
                                 (SELECT GROUP_CONCAT(station_uid) FROM (SELECT station_uid FROM train_stop y WHERE y.train_key = s.train_key ORDER BY y.seq)) AS stops
                          FROM train_stop s JOIN train t ON t.train_key = s.train_key
                          WHERE s.station_uid = ? AND (t.weekmask & ?) != 0 {$where}
                          ORDER BY s.sort_min, t.direction, t.train_key", $params);
        foreach ($rows as $r) {
            if ((int) $r['seq'] === (int) $r['last_seq']) {
                continue;   // 終點站：不發車
            }
            $info = json_decode($r['info'], true);
            $class = $sys === 'TRA' ? appTrainClassByType($r['train_type_code'])
                   : appTrainClassByStops(appLineStationsOf($sys, ''), explode(',', (string) $r['stops']));
            $lines = array_map(function ($l) use ($sys) { return $sys . '-' . $l; }, $info['LineIDs'] ?? []);
            $out[] = [
                'Train' => $r['train_key'], 'RunCode' => $r['train_key'], 'System' => $sys, 'LineUIDs' => $lines, 'TrainNo' => $info['TrainNo'] ?? '',
                'TrainType' => $r['train_type_code'] === '' ? null : ['Code' => $r['train_type_code'], 'Name' => $info['TrainTypeName'] ?? null],
                'Class' => $class, 'ClassLabel' => appClassLabel($sys, $class),
                'Direction' => (string) ($info['Direction'] ?? ''), 'HeadSign' => $info['TripHeadSign'] ?? '',
                'Origin' => $st($info['StartingStationUID'] ?? ''), 'Destination' => $st($info['EndingStationUID'] ?? ''),
                'TripLine' => $info['TripLine'] ?? '',
                'DepartureTime' => $r['dep'], 'DepartureMinute' => (int) $r['sort_min'], 'ArrivalTime' => $r['arr'],
                'ArrivalMinute' => appMinuteNear($r['arr'], (int) $r['sort_min'], false),
                'Source' => $r['dep_src'] ?: 'timetable', 'DataTier' => 'real', 'Note' => $info['Note'] ?? '',
                '_group' => $sys . '|' . (string) ($info['Direction'] ?? ''),
            ];
        }
    } else {
        $rows = $db->all("SELECT g.group_id, g.info, g.direction, g.source, g.simulated, d.arr, d.dep, d.sort_min, d.arr_src, d.dep_src, d.train_type_code, d.pattern_id
                          FROM metro_dep d JOIN metro_group g ON g.group_id = d.group_id
                          WHERE g.station_uid = ? AND (g.weekmask & ?) != 0 {$where}
                          ORDER BY d.sort_min, g.direction, g.group_id", $params);
        $patterns = [];
        foreach ($db->all('SELECT pattern_id, name FROM pattern WHERE system = ?', [$sys]) as $p) {
            $patterns[$p['pattern_id']] = json_decode($p['name'], true);
        }
        foreach ($rows as $r) {
            $info = json_decode($r['info'], true);
            $line = $sys . '-' . ($info['LineIDs'][0] ?? '');
            if (($info['EndingStationUID'] ?? '') === $uid && ($info['StartingStationUID'] ?? '') !== $uid) {
                continue;   // 往本站終止的班次不算發車（環狀線起訖同一站的除外）
            }
            $tt = (string) $r['train_type_code'];
            $class = appClassHasSplit($sys) ? ($tt === '2' ? APP_CLASS_EXPRESS : APP_CLASS_LOCAL) : APP_CLASS_LOCAL;
            $out[] = [
                'Train' => null, 'RunCode' => null, 'System' => $sys, 'LineUIDs' => [$line], 'TrainNo' => null,
                'TrainType' => $tt !== '' ? ['Code' => $tt, 'Name' => $patterns[$r['pattern_id']] ?? ['Zh_tw' => FINDER_TRAIN_TYPES[(int) $tt] ?? '', 'En' => '']] : null,
                'Class' => appClassHasSplit($sys) ? $class : null, 'ClassLabel' => appClassHasSplit($sys) ? appClassLabel($sys, $class) : null,
                'Direction' => (string) $r['direction'], 'HeadSign' => $info['TripHeadSign'] ?? '', 'RouteID' => $info['RouteID'] ?? '',
                'Origin' => $st($info['StartingStationUID'] ?? ''), 'Destination' => $st($info['EndingStationUID'] ?? ''),
                'DepartureTime' => $r['dep'], 'DepartureMinute' => (int) $r['sort_min'], 'ArrivalTime' => $r['arr'],
                'ArrivalMinute' => appMinuteNear($r['arr'], (int) $r['sort_min'], false),
                'Source' => $r['source'], 'DataTier' => finderDbTimetableTier($r['source']), 'Note' => $info['Note'] ?? '',
                '_group' => $line . '|' . $r['direction'], '_gid' => (int) $r['group_id'], '_min' => (int) $r['sort_min'],
            ];
        }
        // 班次代碼 RunCode：對到路網時刻資料的整列車或營運路線（同一個時刻群組、同一分鐘發車），可再查 app_train/stops；
        // 捷運沒有車次，Train 維持 null
        if ($out) {
            $codes = appStationRunCodes($uid, $f['week']);
            $names = $m['names'];
            foreach ($out as &$e) {
                $c = $codes[$e['_gid'] . '|' . $e['_min']] ?? null;
                $e['RunCode'] = $c[0] ?? null;
                $e['NextStation'] = $c ? ['StationUID' => $c[1], 'StationName' => $names[$c[1]] ?? null] : null;
                unset($e['_gid'], $e['_min']);
            }
            unset($e);
        }
    }
    // 篩選：路線、終點站、車種等級
    $out = array_values(array_filter($out, function ($e) use ($f) {
        if ($f['line'] !== null && !in_array($f['line'], $e['LineUIDs'], true)) {
            return false;
        }
        if ($f['destination'] !== null && ($e['Destination']['StationUID'] ?? null) !== $f['destination']) {
            return false;
        }
        if ($f['class'] !== null && $e['Class'] !== null && $e['Class'] !== $f['class']) {
            return false;
        }
        return true;
    }));
    return $out;
}

/** 某站（捷運、輕軌）各班發車對應的班次代碼與下一站：'時刻群組|營運日分鐘' → [代碼, 下一站] */
function appStationRunCodes($uid, $week)
{
    $d = &appRouterData($week);
    $map = [];
    foreach ($d['stationMRoutes'][$uid] ?? [] as list($rk, $j)) {
        foreach ($d['mroutes'][$rk]['trips'] as $ti => $t) {
            if ($j < count($t['dep']) - 1 && isset($t['gid'][$j])) {
                $map[$t['gid'][$j] . '|' . intdiv($t['dep'][$j], 60)] = [appTrainCode($d, ['mrun', $rk, $j, $j, $ti], $week), $d['mroutes'][$rk]['st'][$j + 1]];
            }
        }
    }
    foreach ($d['stationPatterns'][$uid] ?? [] as list($pk, $j)) {
        if ($j >= count($d['patterns'][$pk]['st']) - 1) {
            continue;
        }
        foreach (transferPatternDepartures($d, $pk, $j) as $dep) {
            $k = $dep[1] . '|' . intdiv($dep[0], 60);
            if (!isset($map[$k])) {
                $map[$k] = [appTrainCode($d, ['pattern', $pk, $j, $j, $dep], $week), $d['patterns'][$pk]['st'][$j + 1]];
            }
        }
    }
    return $map;
}

/** 時刻表的分組（路線＋方向）：各組的班次數與主要終點站 */
function appStationGroupsOf(array $rows)
{
    $groups = [];
    foreach ($rows as $e) {
        $k = $e['_group'];
        if (!isset($groups[$k])) {
            list($a, $dir) = explode('|', $k);
            $groups[$k] = ['Group' => $k, 'System' => $e['System'], 'LineUID' => strpos($a, '-') !== false ? $a : null,
                           'Direction' => $dir, 'Count' => 0, 'Destinations' => []];
        }
        $groups[$k]['Count']++;
        $dest = $e['Destination']['StationUID'] ?? '';
        if ($dest !== '') {
            if (!isset($groups[$k]['Destinations'][$dest])) {
                $groups[$k]['Destinations'][$dest] = ['StationUID' => $dest, 'StationName' => $e['Destination']['StationName'], 'Count' => 0];
            }
            $groups[$k]['Destinations'][$dest]['Count']++;
        }
    }
    $via = [];   // 各組最常見的下一站（環狀線起訖同一站時用來分辨方向）
    foreach ($rows as $e) {
        if (!empty($e['NextStation']['StationUID'])) {
            $via[$e['_group']][$e['NextStation']['StationUID']] = ($via[$e['_group']][$e['NextStation']['StationUID']] ?? 0) + 1;
        }
    }
    $labels = [];
    foreach ($groups as &$g) {
        $list = array_values($g['Destinations']);
        usort($list, function ($a, $b) { return $b['Count'] <=> $a['Count']; });
        $g['Destinations'] = $list;
        $g['Label'] = $list ? '往' . implode('、', array_map(function ($x) { return $x['StationName']['Zh_tw'] ?? $x['StationUID']; }, array_slice($list, 0, 3))) : '';
        $labels[$g['Label']][] = $g['Group'];
    }
    unset($g);
    // 不同方向的標籤一樣（環狀線）：加上下一站
    $m = appModel();
    foreach ($groups as &$g) {
        if (count($labels[$g['Label']] ?? []) > 1 && !empty($via[$g['Group']])) {
            arsort($via[$g['Group']]);
            $next = array_key_first($via[$g['Group']]);
            $g['Label'] .= '（經' . ($m['names'][$next]['Zh_tw'] ?? $next) . '）';
        }
    }
    unset($g);
    return array_values($groups);
}

/** timetable、departures 共用的篩選參數 */
function appStationFilters()
{
    list($week, $date) = appParamWeek();
    $dir = appParam('direction');
    if ($dir !== null && !in_array($dir, ['0', '1'], true)) {
        appFail(40020, ['param' => 'direction', 'detail' => '要是 0 或 1']);
    }
    return [
        'week' => $week, 'date' => $date, 'direction' => $dir,
        'line' => appParam('line') !== null ? appLineParam('line') : null,
        'destination' => appParam('destination') !== null ? appStationParam('destination') : null,
        'class' => appParamClass(),
    ];
}

/** 車站標頭 */
function appStationHead($uid)
{
    $m = appModel();
    return ['StationUID' => $uid, 'System' => substr($uid, 0, strpos($uid, '-')), 'StationName' => $m['names'][$uid] ?? null,
            'Lines' => array_keys($m['stationLines'][$uid] ?? [])];
}

/** 去掉內部欄位 */
function appStripInternal(array $rows)
{
    return array_map(function ($e) {
        $e['Group'] = $e['_group'];
        unset($e['_group']);
        return $e;
    }, $rows);
}

function appStationTimetable()
{
    $uid = appStationParam('station');
    $f = appStationFilters() + ['start' => appParamTime('start'), 'end' => appParamTime('end')];
    $rows = appStationDepartureRows($uid, $f);
    $note = null;
    if (!$rows) {
        $note = '這個時段沒有符合條件的發車班次';
    }
    appSend(['Station' => appStationHead($uid), 'groups' => appStationGroupsOf($rows), 'count' => count($rows), 'note' => $note,
             'departures' => appStripInternal($rows)],
            ['week' => $f['week'], 'weekday' => FINDER_WEEKDAYS[$f['week']], 'date' => $f['date']]
            + array_filter(['start' => $f['start'] === null ? null : appHM($f['start']), 'end' => $f['end'] === null ? null : appHM($f['end'])]));
}

function appStationDepartures()
{
    $uid = appStationParam('station');
    $f = appStationFilters();
    $time = appParamTime('start') ?? appParamTime('time');   // start（統一的時段參數）；time 是舊名，照收
    if ($time === null) {
        list($nowWeek, , $nowMin) = appServiceNow();
        $time = ($f['date'] === null && appParam('week') !== null && (int) appParam('week') !== $nowWeek) ? 240 : $nowMin;
    }
    $count = appParamInt('count', APP_DEPARTURES_COUNT_DEFAULT, 1, APP_DEPARTURES_COUNT_MAX);
    $rows = appStationDepartureRows($uid, $f + ['start' => $time, 'end' => null]);
    $byGroup = [];
    foreach ($rows as $e) {
        if (count($byGroup[$e['_group']] ?? []) < $count) {
            $byGroup[$e['_group']][] = $e;
        }
    }
    $groups = appStationGroupsOf($rows);
    foreach ($groups as &$g) {
        $g['Departures'] = appStripInternal($byGroup[$g['Group']] ?? []);
    }
    unset($g);
    appSend(['Station' => appStationHead($uid), 'time' => appHM($time), 'groups' => $groups,
             'note' => $groups ? null : '之後沒有發車班次（可能已過末班車）'],
            ['week' => $f['week'], 'weekday' => FINDER_WEEKDAYS[$f['week']], 'date' => $f['date'], 'count' => $count]);
}

// ================================================================
// app_train：列車各站時刻

function actionAppTrain()
{
    appDispatch('app_train', [
        'stops' => ['appTrainStops', '列車各站時刻：停靠站與到離時刻、車種、起訖、行駛日；臺鐵、高鐵用車次代碼，捷運用其他 API 回傳的班次代碼',
                    ['train' => '班次代碼（必填），例如 TRA-1132、THSR-1305，或其他 API 回傳的 RunCode（捷運）', 'date' => 'YYYY-MM-DD（或 week；臺鐵、高鐵用來判斷當天有沒有行駛）',
                     'pass' => '1＝在停靠站之間插入通過（不停）的車站（Pass＝true，沒有時刻）；只有臺鐵、高鐵等有快慢車之分的系統'],
                    txapiUrl('app_train', 'stops', ['train' => 'TRA-1132'])],
    ]);
}

/** weekmask → 行駛的星期（0＝星期日） */
function appWeekdaysOf($mask)
{
    $out = [];
    for ($w = 0; $w < 7; $w++) {
        if ((int) $mask & (1 << $w)) {
            $out[] = $w;
        }
    }
    return $out;
}

function appTrainStops()
{
    $db = appDb();
    $m = appModel();
    $code = appParamRequired('train', '班次代碼，例如 TRA-1132');
    // 捷運：SYS-LINE-R<雜湊>-<班>-w<星期> 或 SYS-LINE-P<雜湊>-<站序>-<時刻群組>-<秒>-w<星期>
    if (preg_match('/^([A-Z]+)-([A-Za-z0-9]+)-([RP])([0-9a-f]{' . APP_RUN_HASH_LEN . '})-([0-9-]+)-w([0-6])$/', $code, $mm)) {
        appTrainStopsMetro($code, $mm);
    }
    $row = $db->row('SELECT * FROM train WHERE train_key = ?', [$code])
        ?? $db->row('SELECT * FROM train WHERE train_key = ? COLLATE NOCASE', [$code]);
    if (!$row) {
        appFail(40420, ['what' => '班次', 'id' => $code]);
    }
    $sys = $row['system'];
    $info = json_decode($row['info'], true);
    $stops = $db->all('SELECT seq, station_uid, arr, dep, sort_min, arr_src, dep_src FROM train_stop WHERE train_key = ? ORDER BY seq', [$row['train_key']]);
    $class = $sys === 'TRA' ? appTrainClassByType($row['train_type_code']) : appTrainClassByStops(appLineStationsOf($sys, ''), array_column($stops, 'station_uid'));
    $week = appParam('date') !== null || appParam('week') !== null ? appParamWeek()[0] : null;
    $withPass = appParamInt('pass', 0, 0, 1) === 1;
    $n = count($stops);
    $list = [];
    foreach ($stops as $i => $s) {
        $list[] = ['Sequence' => (int) $s['seq'], 'StationUID' => $s['station_uid'], 'StationName' => $m['names'][$s['station_uid']] ?? null,
                   'ArrivalTime' => $i === 0 ? null : $s['arr'], 'DepartureTime' => $i === $n - 1 ? null : $s['dep'],
                   'DepartureMinute' => $s['sort_min'] === null ? null : (int) $s['sort_min'], 'Source' => $s['dep_src'] ?: 'timetable'];
    }
    $st = function ($u) use ($m) {
        return $u ? ['StationUID' => $u, 'StationName' => $m['names'][$u] ?? null] : null;
    };
    $lineUids = array_map(function ($l) use ($sys) { return $sys . '-' . $l; }, json_decode($row['line_ids'], true) ?: []);
    $list = appStopMinutes($list, $stops ? (int) $stops[0]['sort_min'] : null);
    if ($withPass) {
        $list = appStopsWithPass($m, $list, appPassStations($m, $sys, array_column($list, 'StationUID'), $lineUids, (int) ($info['TripLine'] ?? 0)));
    }
    appSend([
        'Train' => $row['train_key'], 'RunCode' => $row['train_key'], 'System' => $sys, 'TrainNo' => $row['train_no'],
        'TrainType' => $row['train_type_code'] === '' ? null : ['Code' => $row['train_type_code'], 'Name' => $info['TrainTypeName'] ?? null],
        'Class' => $class, 'ClassLabel' => appClassLabel($sys, $class),
        'Direction' => (string) $row['direction'], 'HeadSign' => $info['TripHeadSign'] ?? '', 'TripLine' => $info['TripLine'] ?? '',
        'LineUIDs' => $lineUids,
        'Origin' => $st($info['StartingStationUID'] ?? ''), 'Destination' => $st($info['EndingStationUID'] ?? ''),
        'Weekdays' => appWeekdaysOf($row['weekmask']), 'ServiceDay' => json_decode($row['service_day'], true),
        'RunsOnWeek' => $week === null ? null : (((int) $row['weekmask'] & (1 << $week)) !== 0),
        'Note' => $info['Note'] ?? '', 'count' => $n, 'Stops' => $list,
    ], $week === null ? [] : ['week' => $week, 'weekday' => FINDER_WEEKDAYS[$week]]);
}

/** 捷運班次：從整列車或營運路線找回這一班，列出各站時刻（到站多為推算，見 transferRideStops） */
function appTrainStopsMetro($code, array $mm)
{
    list(, $sys, $line, $kind, $hash, $rest, $week) = $mm;
    $week = (int) $week;
    $d = &appRouterData($week);
    $parts = explode('-', $rest);
    $par = null;
    if ($kind === 'R' && count($parts) === 1) {
        foreach ($d['mroutes'] as $rk => $mr) {
            if ($mr['system'] === $sys && $mr['line'] === $line && substr(sha1($rk), 0, APP_RUN_HASH_LEN) === $hash && isset($mr['trips'][(int) $parts[0]])) {
                $par = ['mrun', $rk, 0, count($mr['st']) - 1, (int) $parts[0]];
                break;
            }
        }
    } elseif ($kind === 'P' && count($parts) === 3) {
        foreach ($d['patterns'] as $pk => $p) {
            if ($p['system'] === $sys && $p['line'] === $line && substr(sha1($pk), 0, APP_RUN_HASH_LEN) === $hash && isset($p['st'][(int) $parts[0]])) {
                $par = ['pattern', $pk, (int) $parts[0], count($p['st']) - 1, [(int) $parts[2], (int) $parts[1]]];
                break;
            }
        }
    }
    if ($par === null) {
        appFail(40420, ['what' => '班次（捷運班次代碼只在同一份離線資料內有效，資料更新後請重新查詢）', 'id' => $code]);
    }
    // 營運路線的班次在終點站前就結束時，只算到終點站（中間各站依站間行駛時間推算，見 transferRideStops）
    $ride = transferRideStep($d, $par, $par[0] === 'mrun' ? $d['mroutes'][$par[1]]['trips'][$par[4]]['arr'][$par[3]] : $par[4][0]);
    if ($par[0] === 'pattern' && $ride['EndingStationUID']) {
        $e = array_search($ride['EndingStationUID'], array_slice($d['patterns'][$par[1]]['st'], $par[2] + 1), true);
        if ($e !== false) {
            $par[3] = $par[2] + 1 + $e;
        }
    }
    $class = appRideClass($d, $par);
    $list = [];
    foreach (transferRideStops($d, $par) as $i => $s) {
        $list[] = ['Sequence' => $i + 1] + $s;
    }
    $m = $d['m'];
    $list = appStopMinutes($list, intdiv($par[0] === 'mrun' ? $d['mroutes'][$par[1]]['trips'][$par[4]]['dep'][$par[2]] : $par[4][0], 60));
    if (appParamInt('pass', 0, 0, 1) === 1) {
        $list = appStopsWithPass($m, $list, appPassStations($m, $sys, array_column($list, 'StationUID'), [$sys . '-' . $line]));
    }
    appSend([
        // 捷運沒有車次：Train、TrainNo 都是 null，班次代碼在 RunCode
        'Train' => null, 'RunCode' => $code, 'System' => $sys, 'TrainNo' => null,
        'TrainType' => $ride['TrainType'] ? ['Code' => (string) $ride['TrainType'], 'Name' => ['Zh_tw' => FINDER_TRAIN_TYPES[$ride['TrainType']] ?? '', 'En' => '']] : null,
        'Class' => appClassHasSplit($sys) ? $class : null, 'ClassLabel' => appClassHasSplit($sys) ? appClassLabel($sys, $class) : null,
        'Direction' => (string) $ride['Direction'], 'RouteID' => $ride['RouteID'] ?? null, 'LineUIDs' => [$sys . '-' . $line],
        'Origin' => ['StationUID' => $list[0]['StationUID'], 'StationName' => $list[0]['StationName']],
        'Destination' => $ride['EndingStationUID'] ? ['StationUID' => $ride['EndingStationUID'], 'StationName' => $m['names'][$ride['EndingStationUID']] ?? null] : null,
        'Note' => $par[0] === 'pattern' ? '沒有真實站別時刻表的路線：從上車站起依發車與站間行駛時間推算'
                                       : ('TDX 沒有捷運整列車時刻表：' . (end($list)['Source'] === 'timetable'
                                          ? '首站與末站是車站時刻表的時刻，中間各站依站間行駛時間比例推算'
                                          : '首站是車站時刻表的時刻，中間各站依站間行駛時間比例推算，末站（車站時刻表沒有到站時刻）也是推算')),
        'count' => count(array_filter($list, function ($s) { return empty($s['Pass']); })), 'Stops' => $list,
    ], ['week' => $week, 'weekday' => FINDER_WEEKDAYS[$week]]);
}

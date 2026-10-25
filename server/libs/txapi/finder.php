<?php
/**
 * 靜態資料查詢：/txapi/finder
 *
 * /txapi/download 負責寫入（向 TDX 取得並存到 download/<service>/），
 * /txapi/finder 負責讀取：只從 SQLite（sqlite/finder.sqlite，由 libs/txapi/finderdb.php 從 download 建成）檢索，
 * 不讀 JSON、不在記憶體建大型索引，也不呼叫 TDX。
 *
 *   /txapi/finder                                         說明文件（Markdown），同 func=help
 *   /txapi/finder/list                               可用功能清單與各自依賴的資料表、來源檔、是否最新（JSON）
 *   /txapi/finder/rail_station_time?station=TRA-1000&week=1
 *   /txapi/finder/rail_train_time?key=TRA-123
 *
 * 每個 func 在 finderFuncs() 登記它查詢的資料表；執行前先確認這些資料表是最新（來源檔沒有更新），
 * 過期就先從 download 重建資料庫再查詢。下載資料不完整時回 503 與缺少的 path。
 *
 * 統一格式以臺鐵 v3 定期時刻表的欄位為基底：高鐵、捷運同義欄位改用臺鐵名稱，
 * 臺鐵沒有的資訊另加欄位；所有欄位一律存在，沒有值時為空字串。
 */

const FINDER_WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];   // 0＝星期日
const FINDER_TRAIN_SYSTEMS = ['TRA', 'THSR'];   // 有列車（車次）資料的系統

/**
 * 功能登記：每個 func 查詢哪些 SQLite 資料表（建置依據），以及說明與範例。
 * 資料表與來源 download 路徑的對應在 finderDbTables()（libs/txapi/finderdb.php）。
 */
function finderFuncs()
{
    return [
        'rail_station_time' => [
            'title'   => '車站時刻',
            'desc'    => '某站某星期幾的全日時刻（各方向），臺鐵、高鐵、捷運、輕軌統一格式',
            'tables'  => ['station', 'train', 'train_stop', 'metro_group', 'metro_dep', 'pattern'],
            'params'  => ['station（必填）', 'week／date', 'start', 'end', 'direction'],
            'example' => '/txapi/finder/rail_station_time?station=TRA-1000&week=1',
        ],
        'rail_train_time' => [
            'title'   => '列車時刻',
            'desc'    => '依車次、星期、車站、終點、時間範圍、車種、路線找臺鐵、高鐵列車（含完整停靠站）；捷運回空陣列',
            'tables'  => ['station', 'train', 'train_stop'],
            'params'  => ['key', 'system', 'train', 'week／date', 'station', 'dest', 'start', 'end', 'type', 'line', 'direction'],
            'example' => '/txapi/finder/rail_train_time?key=TRA-123',
        ],
        'rail_stations' => [
            'title'   => '車站清單',
            'desc'    => '依營運單位 → 路線 → 站序列出車站，含路線代表色（查詢介面的車站快查選單用）',
            'tables'  => ['station', 'line', 'line_station', 'line_section'],
            'params'  => ['operator', 'system'],
            'example' => '/txapi/finder/rail_stations?operator=TRTC',
        ],
        'metro_travel_time' => [
            'title'   => '捷運站間行駛時間',
            'desc'    => '兩站間的行駛秒數；資料依可否無損轉換存成路線站距表（從起站累加）或 TDX 原始起訖矩陣（直接查）',
            'tables'  => ['station', 'metro_travel', 'metro_travel_od', 'metro_travel_set'],
            'params'  => ['from＋to', 'system', 'line'],
            'example' => '/txapi/finder/metro_travel_time?from=TRTC-BL12&to=TRTC-BL01',
        ],
        'metro_trip_time' => [
            'title'   => '捷運起訖時刻',
            'desc'    => '捷運兩站間每一班的出發與抵達時刻：起站時刻表＋站間行駛時間，再對到迄站時刻表最近的一班',
            'tables'  => ['station', 'line_station', 'metro_group', 'metro_dep', 'pattern', 'metro_travel', 'metro_travel_od', 'metro_travel_set'],
            'params'  => ['from（必填）', 'to（必填）', 'week／date', 'start', 'end', 'tolerance'],
            'example' => '/txapi/finder/metro_trip_time?from=TRTC-BL12&to=TRTC-BL01&week=1&start=08:00&end=09:00',
        ],
    ];
}

// ================================================================
// 入口

function actionFinder()
{
    $func = strtolower(trim((string) ($_GET['func'] ?? '')));
    header('Cache-Control: no-store');
    if ($func === '' || $func === 'help') {
        finderSendHelp();
    }
    if ($func === 'list') {
        sendJson(200, ['ok' => true, 'action' => 'finder', 'func' => 'list'] + finderStatus());
    }
    $funcs = finderFuncs();
    if (!isset($funcs[$func])) {
        sendJson(400, ['ok' => false, 'error' => 'Unknown func: ' . $func,
                       'funcs' => array_merge(['help', 'list'], array_keys($funcs)), 'help' => '/txapi/finder']);
    }

    // 查詢前確認資料表是最新，過期就先從 download 重建
    $tables = $funcs[$func]['tables'];
    try {
        $ensure = finderDbEnsure($tables);
        $db = SqliteDao::open(FINDERDB_NAME, ['readonly' => true]);
    } catch (FinderDbIncomplete $e) {
        sendJson(503, ['ok' => false, 'error' => 'Download data is incomplete; finder database cannot be built',
                       'missing' => $e->missing, 'hint' => 'Run /txapi/admin_download/sync?service=tdx until done']);
    } catch (Throwable $e) {
        sendJson(500, ['ok' => false, 'error' => 'Finder database error: ' . $e->getMessage()]);
    }
    $sqlite = ['db' => FINDERDB_NAME, 'built_at' => $db->meta('built_at'), 'rebuilt' => $ensure['rebuilt'], 'tables' => $tables];
    if ($ensure['report']) {
        $sqlite['report'] = $ensure['report'];
    }
    try {
        $handler = ['rail_station_time' => 'finderRailStationTime', 'rail_train_time' => 'finderRailTrainTime',
                    'rail_stations' => 'finderRailStations', 'metro_travel_time' => 'finderMetroTravelTime',
                    'metro_trip_time' => 'finderMetroTripTime'][$func];
        $out = $handler($db, $tables);
    } catch (SqliteDaoException $e) {
        sendJson(500, ['ok' => false, 'error' => 'Finder query error: ' . $e->getMessage()]);
    }
    $db->close();
    // sqlite 資訊放在 timetable／trains 之前，方便閱讀
    $bodyKey = ['rail_station_time' => 'timetable', 'rail_train_time' => 'trains', 'rail_stations' => 'operators',
                'metro_travel_time' => isset($out['travel']) ? 'travel' : 'matrices', 'metro_trip_time' => 'trips'][$func];
    $body = $out[$bodyKey];
    unset($out[$bodyKey]);
    sendJson(200, $out + ['sqlite' => $sqlite, $bodyKey => $body]);
}

// ================================================================
// 共用

/** StationUID → [系統, StationID]。接受 TDX 寫法（NTMCC-Y07、KLRT-NETWORK-C1）與大小寫差異 */
function finderParseUID($uid)
{
    $uid = trim((string) $uid);
    if (stripos($uid, 'KLRT-NETWORK-') === 0) {
        return ['KLRT', substr($uid, 13)];
    }
    $p = strpos($uid, '-');
    if ($p === false) {
        return null;
    }
    $sys = strtoupper(substr($uid, 0, $p));
    $id = substr($uid, $p + 1);
    if ($sys === 'NTMCC') {
        $sys = 'NTMC';
    }
    if ($id === '' || !in_array($sys, array_merge(FINDER_TRAIN_SYSTEMS, FINDERDB_METRO_SYSTEMS), true)) {
        return null;
    }
    return [$sys, $id];
}

/** 在 station 表找車站：完全相同 → 不分大小寫 → 忽略前導 0（高鐵 990 = 0990）。回傳解碼後的列或 null */
function finderStation(SqliteDao $db, $sys, $id)
{
    $r = $db->row('SELECT * FROM station WHERE system = ? AND station_id = ?', [$sys, $id])
      ?? $db->row('SELECT * FROM station WHERE system = ? AND station_id = ? COLLATE NOCASE', [$sys, $id]);
    if ($r === null && ltrim($id, '0') !== '') {
        $r = $db->row("SELECT * FROM station WHERE system = ? AND ltrim(station_id, '0') = ?", [$sys, ltrim($id, '0')]);
    }
    if ($r === null) {
        return null;
    }
    $r['name'] = json_decode($r['name'], true);
    $r['line_ids'] = json_decode($r['line_ids'], true);
    return $r;
}

/** 多個 StationUID 的名稱 */
function finderStationNames(SqliteDao $db, array $uids)
{
    $uids = array_values(array_unique(array_filter($uids, 'strlen')));
    $out = [];
    foreach (array_chunk($uids, 400) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        foreach ($db->all("SELECT uid, station_id, name FROM station WHERE uid IN ($in)", $chunk) as $r) {
            $out[$r['uid']] = ['id' => $r['station_id'], 'name' => json_decode($r['name'], true)];
        }
    }
    return $out;
}

/** 星期：week=0～6（0＝星期日）或 date=YYYY-MM-DD；都沒給時 $required 為 true 則用今天（臺灣時間）。回傳 [week|null, date|null] */
function finderWeek($required = true)
{
    $tz = new DateTimeZone('Asia/Taipei');
    $date = trim((string) ($_GET['date'] ?? ''));
    if ($date !== '') {
        $d = DateTime::createFromFormat('!Y-m-d', $date, $tz);
        if (!$d || $d->format('Y-m-d') !== $date) {
            sendJson(400, ['ok' => false, 'error' => 'date must be YYYY-MM-DD']);
        }
        return [(int) $d->format('w'), $date];
    }
    $w = trim((string) ($_GET['week'] ?? ''));
    if ($w !== '') {
        if (!preg_match('/^[0-6]$/', $w)) {
            sendJson(400, ['ok' => false, 'error' => 'week must be 0-6 (0 = Sunday)']);
        }
        return [(int) $w, null];
    }
    return $required ? [(int) (new DateTime('now', $tz))->format('w'), null] : [null, null];
}

/** HH:MM 參數 → 分鐘；空值回 null */
function finderTimeParam($name)
{
    $v = trim((string) ($_GET[$name] ?? ''));
    if ($v === '') {
        return null;
    }
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', $v, $m) || (int) $m[1] > 47 || (int) $m[2] > 59) {
        sendJson(400, ['ok' => false, 'error' => $name . ' must be HH:MM']);
    }
    return (int) $m[1] * 60 + (int) $m[2];
}

/**
 * 起訖查詢的排序參數：sort＝欄位名稱（不分大小寫），order＝asc（預設）／desc。回傳 [欄位, 是否遞減]。
 * $allowed：欄位名稱 → 是否可用（false 時回 400 並說明原因）
 */
function finderSortParam(array $allowed, $default, array $why = [])
{
    $v = trim((string) ($_GET['sort'] ?? ''));
    $field = $default;
    if ($v !== '') {
        $field = null;
        foreach ($allowed as $name => $ok) {
            if (strcasecmp($name, $v) === 0) {
                $field = $name;
            }
        }
        if ($field === null || !$allowed[$field]) {
            sendJson(400, ['ok' => false, 'error' => 'sort must be one of: ' . implode(', ', array_keys(array_filter($allowed)))
                           . ($field !== null && isset($why[$field]) ? '（' . $field . '：' . $why[$field] . '）' : ''),
                           'sort' => array_keys(array_filter($allowed))]);
        }
    }
    $order = strtolower(trim((string) ($_GET['order'] ?? 'asc')));
    if (!in_array($order, ['asc', 'desc'], true)) {
        sendJson(400, ['ok' => false, 'error' => 'order must be asc or desc']);
    }
    return [$field, $order === 'desc'];
}

/**
 * 依排序鍵排序（鍵為跨午夜也連續的分鐘或秒數），同值時依出發、再依原順序；排序後移除內部鍵 _sort
 */
function finderSortRows(array $rows, $desc)
{
    foreach ($rows as $i => &$r) {
        $r['_i'] = $i;
    }
    unset($r);
    usort($rows, function ($a, $b) use ($desc) {
        $c = $a['_sort'][0] <=> $b['_sort'][0];
        if ($desc) {
            $c = -$c;
        }
        return $c !== 0 ? $c : [$a['_sort'][1], $a['_i']] <=> [$b['_sort'][1], $b['_i']];
    });
    foreach ($rows as &$r) {
        unset($r['_sort'], $r['_i']);
    }
    unset($r);
    return $rows;
}

/** 這次查詢用到的資料表的來源檔（路徑、存檔時間、時刻表適用期間） */
function finderSources(SqliteDao $db, array $tables)
{
    $in = implode(',', array_fill(0, count($tables), '?'));
    $out = [];
    foreach ($db->all("SELECT path, MAX(saved_at) AS saved_at, MAX(validity) AS validity FROM _sources
                       WHERE table_name IN ($in) GROUP BY path ORDER BY path", $tables) as $r) {
        $s = ['path' => $r['path'], 'saved_at' => $r['saved_at']];
        if ($r['validity']) {
            $s['validity'] = json_decode($r['validity'], true);
        }
        $out[] = $s;
    }
    return $out;
}

function finderSeq($v)
{
    return $v === '' || $v === null ? '' : (int) $v;
}

// ================================================================
// func=rail_station_time

function finderRailStationTime(SqliteDao $db, array $tables)
{
    $uid = (string) ($_GET['station'] ?? '');
    $parsed = finderParseUID($uid);
    if ($parsed === null) {
        sendJson(400, ['ok' => false, 'error' => 'station must be a StationUID, e.g. TRA-1000, THSR-0990, TRTC-BL12',
                       'systems' => array_merge(FINDER_TRAIN_SYSTEMS, FINDERDB_METRO_SYSTEMS)]);
    }
    list($sys, $sid) = $parsed;
    list($week, $date) = finderWeek(true);
    $start = finderTimeParam('start');
    $end = finderTimeParam('end');
    $dir = trim((string) ($_GET['direction'] ?? ''));
    $st = finderStation($db, $sys, $sid);
    if ($st === null) {
        sendJson(404, ['ok' => false, 'error' => 'Station not found: ' . $uid]);
    }
    $isTrain = in_array($sys, FINDER_TRAIN_SYSTEMS, true);
    $sortCol = $isTrain ? 's.sort_min' : 'd.sort_min';
    $dirCol = $isTrain ? 't.direction' : 'g.direction';
    $where = '';
    $params = [$st['uid'], 1 << $week];
    if ($start !== null) {
        $where .= " AND $sortCol >= ?";
        $params[] = $start;
    }
    if ($end !== null) {
        $where .= " AND $sortCol <= ?";
        $params[] = $end;
    }
    if ($dir !== '') {
        $where .= " AND $dirCol = ?";
        $params[] = $dir;
    }
    $head = ['StationUID' => $st['uid'], 'StationID' => $st['station_id'], 'StationName' => $st['name']];
    $entries = [];
    $note = null;

    if ($isTrain) {
        $rows = $db->all("SELECT t.info, s.seq, s.arr, s.dep, s.arr_src, s.dep_src FROM train_stop s JOIN train t ON t.train_key = s.train_key
                          WHERE s.station_uid = ? AND (t.weekmask & ?) != 0 {$where}
                          ORDER BY s.sort_min, t.direction, t.train_key", $params);
        foreach ($rows as $r) {
            $e = $head + json_decode($r['info'], true);
            $e['StopSequence'] = (int) $r['seq'];
            $e['ArrivalTime'] = $r['arr'];
            $e['DepartureTime'] = $r['dep'];
            $e['ArrivalTimeSource'] = $r['arr_src'];
            $e['DepartureTimeSource'] = $r['dep_src'];
            $entries[] = $e;
        }
    } else {
        $withSim = !empty($_GET['simulated']) ? 1 : 0;   // simulated=1：沒有真實站別時刻表的車站也列出推算時刻（平移、班距模擬）
        $rows = $db->all("SELECT g.info, g.stop_seq, g.simulated, g.source, d.arr, d.dep, d.arr_src, d.dep_src, d.train_type_code, d.pattern_id
                          FROM metro_dep d JOIN metro_group g ON g.group_id = d.group_id
                          WHERE g.station_uid = ? AND (g.weekmask & ?) != 0 AND g.simulated <= " . $withSim . " {$where}
                          ORDER BY d.sort_min, g.direction, g.group_id", $params);
        $infos = [];
        $uids = [];
        foreach ($rows as $i => $r) {
            $infos[$i] = json_decode($r['info'], true);
            $uids[] = $infos[$i]['StartingStationUID'];
            $uids[] = $infos[$i]['EndingStationUID'];
        }
        $names = finderStationNames($db, $uids);
        $patterns = [];
        foreach ($db->all('SELECT pattern_id, name FROM pattern WHERE system = ?', [$sys]) as $p) {
            $patterns[$p['pattern_id']] = json_decode($p['name'], true);
        }
        foreach ($rows as $i => $r) {
            $e = $head + $infos[$i];
            foreach (['Starting', 'Ending'] as $k) {
                $u = $e[$k . 'StationUID'];
                if ($u !== '' && isset($names[$u])) {
                    $e[$k . 'StationName'] = $names[$u]['name'];
                }
            }
            $e['TrainTypeCode'] = $r['train_type_code'];
            $e['StoppingPatternID'] = $r['pattern_id'];
            if ($r['pattern_id'] !== '' && isset($patterns[$r['pattern_id']])) {
                $e['TrainTypeName'] = $patterns[$r['pattern_id']];
            }
            $e['StopSequence'] = finderSeq($r['stop_seq']);
            $e['ArrivalTime'] = $r['arr'];
            $e['DepartureTime'] = $r['dep'];
            $e['ArrivalTimeSource'] = $r['arr_src'];
            $e['DepartureTimeSource'] = $r['dep_src'];
            if ($withSim) {
                $e['Simulated'] = (int) $r['simulated'];
                $e['Source'] = $r['source'];
                $e['DataTier'] = finderDbTimetableTier($r['source']);
            }
            $entries[] = $e;
        }
        if (!$entries && $db->value('SELECT 1 FROM metro_group WHERE station_uid = ? AND simulated = 0 LIMIT 1', [$st['uid']]) === null) {
            $note = $db->value('SELECT 1 FROM metro_group WHERE system = ? AND simulated = 0 LIMIT 1', [$sys]) !== null
                ? 'TDX 沒有此站的站別時刻表（例如臺北捷運文湖線）'
                : 'TDX 沒有此系統的站別時刻表（例如臺中捷運）';
            if (!$withSim && $db->value('SELECT 1 FROM metro_group WHERE station_uid = ? AND simulated = 1 LIMIT 1', [$st['uid']]) !== null) {
                $kinds = ['shifted' => '由同路線最近的真實時刻表車站平移（shifted）', 'simulated' => '依首末班車與班距排出（simulated）'];
                $have = array_column($db->all('SELECT DISTINCT source FROM metro_group WHERE station_uid = ? AND simulated = 1', [$st['uid']]), 'source');
                $note .= '；加 simulated=1 可列出推算時刻：' . implode('、', array_intersect_key($kinds, array_flip($have)));
            }
        }
    }
    $dirs = [];
    foreach ($entries as $e) {
        $d = (string) $e['Direction'];
        $dirs[$d] = ($dirs[$d] ?? 0) + 1;
    }
    ksort($dirs);

    $out = [
        'ok'           => true,
        'action'       => 'finder',
        'func'         => 'rail_station_time',
        'station'      => ['StationUID' => $st['uid'], 'System' => $sys, 'StationID' => $st['station_id'],
                           'StationName' => $st['name'], 'LineIDs' => $st['line_ids']],
        'week'         => $week,
        'weekday'      => FINDER_WEEKDAYS[$week],
        'date'         => $date,
        'count'        => count($entries),
        'directions'   => (object) $dirs,   // 各方向班次數
        'hasTrainData' => $isTrain,
        'source'       => finderSources($db, $tables),
    ];
    if ($note !== null) {
        $out['note'] = $note;
    }
    $out['timetable'] = $entries;
    return $out;
}

// ================================================================
// func=rail_train_time

function finderRailTrainTime(SqliteDao $db, array $tables)
{
    $key = strtoupper(trim((string) ($_GET['key'] ?? '')));
    $trainNo = trim((string) ($_GET['train'] ?? ''));
    $systems = array_values(array_filter(array_map('trim', explode(',', strtoupper((string) ($_GET['system'] ?? ''))))));
    if ($key !== '') {
        $p = strpos($key, '-');
        if ($p === false) {
            sendJson(400, ['ok' => false, 'error' => 'key must look like TRA-123 or THSR-0108']);
        }
        $systems = [substr($key, 0, $p)];
        $trainNo = substr($key, $p + 1);
    }
    $all = array_merge(FINDER_TRAIN_SYSTEMS, FINDERDB_METRO_SYSTEMS);
    if (!$systems) {
        $systems = FINDER_TRAIN_SYSTEMS;
    }
    foreach ($systems as $s) {
        if (!in_array($s, $all, true)) {
            sendJson(400, ['ok' => false, 'error' => 'Unknown system: ' . $s, 'systems' => $all]);
        }
    }
    list($week, $date) = finderWeek(false);
    $start = finderTimeParam('start');
    $end = finderTimeParam('end');
    $types = array_values(array_filter(array_map('trim', explode(',', (string) ($_GET['type'] ?? ''))), 'strlen'));
    $line = trim((string) ($_GET['line'] ?? ''));
    $dir = trim((string) ($_GET['direction'] ?? ''));
    $stations = [];
    foreach (['station', 'dest'] as $name) {
        $v = trim((string) ($_GET[$name] ?? ''));
        if ($v === '') {
            continue;
        }
        $p = finderParseUID($v);
        if ($p === null) {
            sendJson(400, ['ok' => false, 'error' => $name . ' must be a StationUID, e.g. TRA-1000']);
        }
        $row = finderStation($db, $p[0], $p[1]);
        // 找不到的車站保留原值，查詢自然沒有結果
        $stations[$name] = ['sys' => $p[0], 'uid' => $row ? $row['uid'] : $p[0] . '-' . $p[1]];
    }

    $notes = [];
    $trainSystems = [];
    foreach ($systems as $s) {
        if (!in_array($s, FINDER_TRAIN_SYSTEMS, true)) {
            $notes[] = $s . '：捷運、輕軌沒有列車（車次）資料，回空陣列';
        } elseif ((!isset($stations['station']) || $stations['station']['sys'] === $s)
               && (!isset($stations['dest']) || $stations['dest']['sys'] === $s)) {
            $trainSystems[] = $s;
        }
    }

    // 排序：DepartureTime（起站離站；沒有 station 時為列車起點發車）、ArrivalTime（dest 到站）、TripMinutes（station→dest 所需分鐘）
    $hasFrom = isset($stations['station']);
    $hasTo = isset($stations['dest']);
    list($sortField, $sortDesc) = finderSortParam(
        ['DepartureTime' => true, 'ArrivalTime' => $hasTo, 'TripMinutes' => $hasFrom && $hasTo],
        'DepartureTime', ['ArrivalTime' => '需要 dest', 'TripMinutes' => '需要 station 與 dest']);

    $trains = [];
    if ($trainSystems) {
        $join = '';
        $params = [];
        $ref = 't.start_min';
        $cols = 't.start_min AS from_min';
        if (isset($stations['station'])) {
            $join .= ' JOIN train_stop s ON s.train_key = t.train_key AND s.station_uid = ?';
            $params[] = $stations['station']['uid'];
            $ref = 's.sort_min';
            $cols = 's.sort_min AS from_min, s.dep AS from_dep';
        }
        if (isset($stations['dest'])) {
            $join .= ' JOIN train_stop d ON d.train_key = t.train_key AND d.station_uid = ?'
                   . (isset($stations['station']) ? ' AND d.seq > s.seq' : '');
            $params[] = $stations['dest']['uid'];
            // 到站的連續分鐘：dest 的 sort_min 以離站計，減去本站停站（離站 − 到站）
            $hm = function ($c) { return "(CAST(substr($c, 1, 2) AS INTEGER) * 60 + CAST(substr($c, 4, 2) AS INTEGER))"; };
            $cols .= ', d.arr AS to_arr, d.sort_min - ((' . $hm('d.dep') . ' - ' . $hm('d.arr') . ' + 1440) % 1440) AS to_min';
        }
        $where = ' WHERE t.system IN (' . implode(',', array_fill(0, count($trainSystems), '?')) . ')';
        $params = array_merge($params, $trainSystems);
        if ($trainNo !== '') {
            $where .= ' AND t.train_no_norm = ?';
            $params[] = ltrim($trainNo, '0');
        }
        if ($week !== null) {
            $where .= ' AND (t.weekmask & ?) != 0';
            $params[] = 1 << $week;
        }
        if ($types) {
            $in = implode(',', array_fill(0, count($types), '?'));
            $where .= " AND (t.train_type_code IN ($in) OR t.train_type_id IN ($in))";
            $params = array_merge($params, $types, $types);
        }
        if ($line !== '') {
            $where .= ' AND EXISTS (SELECT 1 FROM json_each(t.line_ids) WHERE json_each.value = ?)';
            $params[] = $line;
        }
        if ($dir !== '') {
            $where .= ' AND t.direction = ?';
            $params[] = $dir;
        }
        if ($start !== null) {
            $where .= " AND $ref >= ?";
            $params[] = $start;
        }
        if ($end !== null) {
            $where .= " AND $ref <= ?";
            $params[] = $end;
        }
        $rows = $db->all("SELECT t.train_key, t.info, t.service_day, {$cols} FROM train t{$join}{$where} ORDER BY $ref, t.train_key", $params);

        // 停靠站（分批取，避免 SQL 參數過多）
        $stops = [];
        foreach (array_chunk(array_column($rows, 'train_key'), 400) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            foreach ($db->all("SELECT ts.train_key, ts.seq, ts.station_uid, ts.arr, ts.dep, ts.arr_src, ts.dep_src, st.station_id, st.name
                               FROM train_stop ts LEFT JOIN station st ON st.uid = ts.station_uid
                               WHERE ts.train_key IN ($in) ORDER BY ts.train_key, ts.seq", $chunk) as $s) {
                $stops[$s['train_key']][] = [
                    'StopSequence'  => (int) $s['seq'],
                    'StationUID'    => $s['station_uid'],
                    'StationID'     => $s['station_id'] ?? substr($s['station_uid'], strpos($s['station_uid'], '-') + 1),
                    'StationName'   => $s['name'] ? json_decode($s['name'], true) : ['Zh_tw' => '', 'En' => ''],
                    'ArrivalTime'   => $s['arr'],
                    'DepartureTime' => $s['dep'],
                    'ArrivalTimeSource'   => $s['arr_src'],
                    'DepartureTimeSource' => $s['dep_src'],
                ];
            }
        }
        foreach ($rows as $r) {
            $tr = json_decode($r['info'], true);
            // 有 station／dest 時，把起站離站、迄站到站與所需分鐘放在列車層級，方便比較與排序
            if ($hasFrom) {
                $tr['DepartureTime'] = $r['from_dep'];
            }
            if ($hasTo) {
                $tr['ArrivalTime'] = $r['to_arr'];
            }
            if ($hasFrom && $hasTo) {
                $tr['TripMinutes'] = (int) $r['to_min'] - (int) $r['from_min'];
            }
            $tr['ServiceDay'] = json_decode($r['service_day'], true);
            $tr['StopTimes'] = $stops[$r['train_key']] ?? [];
            $key = ['DepartureTime' => (int) $r['from_min'], 'ArrivalTime' => (int) ($r['to_min'] ?? 0),
                    'TripMinutes' => (int) ($r['to_min'] ?? 0) - (int) $r['from_min']][$sortField];
            $tr['_sort'] = [$key, (int) $r['from_min']];
            $trains[] = $tr;
        }
        $trains = finderSortRows($trains, $sortDesc);
    }

    $out = [
        'ok'      => true,
        'action'  => 'finder',
        'func'    => 'rail_train_time',
        'query'   => array_filter([
            'key' => $key, 'system' => implode(',', $systems), 'train' => $trainNo, 'week' => $week, 'date' => $date,
            'station' => $_GET['station'] ?? '', 'dest' => $_GET['dest'] ?? '', 'start' => $_GET['start'] ?? '',
            'end' => $_GET['end'] ?? '', 'type' => implode(',', $types), 'line' => $line, 'direction' => $dir,
            'sort' => $sortField, 'order' => $sortDesc ? 'desc' : 'asc',
        ], function ($v) { return $v !== '' && $v !== null; }),
        'weekday' => $week === null ? null : FINDER_WEEKDAYS[$week],
        'count'   => count($trains),
        'source'  => finderSources($db, $tables),
    ];
    if ($notes) {
        $out['note'] = $notes;
    }
    $out['trains'] = $trains;
    return $out;
}

// ================================================================
// func=rail_stations

/** 營運單位 → 路線（依排序）→ 車站（依站序）。operator、system 可用逗號列多個 */
function finderRailStations(SqliteDao $db, array $tables)
{
    $pick = function ($name) {
        return array_values(array_filter(array_map('trim', explode(',', strtoupper((string) ($_GET[$name] ?? '')))), 'strlen'));
    };
    $ops = $pick('operator');
    $systems = $pick('system');
    foreach ($ops as $o) {
        if (!isset(FINDERDB_OPERATORS[$o])) {
            sendJson(400, ['ok' => false, 'error' => 'Unknown operator: ' . $o, 'operators' => array_keys(FINDERDB_OPERATORS)]);
        }
    }
    $sysOp = [];
    foreach (FINDERDB_OPERATORS as $id => $o) {
        foreach ($o['systems'] as $sys) {
            $sysOp[$sys] = $id;
        }
    }
    foreach ($systems as $sys) {
        if (!isset($sysOp[$sys])) {
            sendJson(400, ['ok' => false, 'error' => 'Unknown system: ' . $sys, 'systems' => array_keys($sysOp)]);
        }
    }

    $operators = [];
    foreach (FINDERDB_OPERATORS as $id => $o) {
        if (!$ops || in_array($id, $ops, true)) {
            $operators[$id] = ['Operator' => $id, 'OperatorName' => $o['name'], 'Systems' => $o['systems'],
                               'LineCount' => 0, 'StationCount' => 0, 'Lines' => []];
        }
    }
    $sections = [];
    foreach ($db->all('SELECT * FROM line_section ORDER BY system, line_id, seq') as $x) {
        $sections[$x['system'] . '-' . $x['line_id']][] = [
            'Name' => $x['name'], 'Short' => $x['short'], 'Color' => $x['color'], 'ColorSource' => $x['color_source'],
            'FromSequence' => (int) $x['from_seq'], 'ToSequence' => (int) $x['to_seq'],
        ];
    }
    $uids = [];
    $lines = [];
    $rows = $db->all('SELECT l.system, l.line_id, l.name AS line_name, l.color, l.color_source, l.is_branch,
                             ls.seq, ls.station_uid, s.station_id, s.name
                      FROM line l
                      JOIN line_station ls ON ls.system = l.system AND ls.line_id = l.line_id
                      LEFT JOIN station s ON s.uid = ls.station_uid
                      ORDER BY l.sort, ls.seq');
    foreach ($rows as $r) {
        $op = $sysOp[$r['system']] ?? null;
        if ($op === null || !isset($operators[$op]) || ($systems && !in_array($r['system'], $systems, true))) {
            continue;
        }
        $k = $r['system'] . '-' . $r['line_id'];
        if (!isset($lines[$k])) {
            $lines[$k] = ['op' => $op, 'line' => [
                'System'          => $r['system'],
                'LineID'          => $r['line_id'],
                'LineName'        => json_decode($r['line_name'], true),
                'LineColor'       => $r['color'],
                'LineColorSource' => $r['color_source'],
                'IsBranch'        => (bool) $r['is_branch'],
                'Sections'        => $sections[$k] ?? [],
                'Stations'        => [],
            ]];
        }
        $lines[$k]['line']['Stations'][] = [
            'Sequence'    => (int) $r['seq'],
            'StationUID'  => $r['station_uid'],
            'StationID'   => $r['station_id'] ?? substr($r['station_uid'], strpos($r['station_uid'], '-') + 1),
            'StationName' => $r['name'] ? json_decode($r['name'], true) : ['Zh_tw' => '', 'En' => ''],
        ];
        $uids[$op][$r['station_uid']] = true;
    }
    foreach ($lines as $l) {
        $operators[$l['op']]['Lines'][] = $l['line'];
        $operators[$l['op']]['LineCount']++;
    }
    foreach ($operators as $id => &$o) {
        $o['StationCount'] = count($uids[$id] ?? []);
    }
    unset($o);

    return [
        'ok'        => true,
        'action'    => 'finder',
        'func'      => 'rail_stations',
        'query'     => array_filter(['operator' => implode(',', $ops), 'system' => implode(',', $systems)], 'strlen'),
        'count'     => array_sum(array_column($operators, 'StationCount')),
        'note'      => 'LineColorSource／ColorSource：tdx＝TDX 的 LineColor；railtime＝TDX 沒有提供，取自 railtime 專案的路線選擇器',
        'source'    => finderSources($db, $tables),
        'operators' => array_values($operators),
    ];
}

// ================================================================
// func=metro_travel_time

const FINDER_TRAIN_TYPES = [0 => '', 1 => '普通車', 2 => '直達車'];

/** metro_trip_time 各系統預設的 tolerance（迄站時刻可比推算抵達提早幾分鐘）：環狀輕軌 TDX 站間時間比時刻表長 */
const FINDER_TRIP_TOLERANCE = ['KLRT' => 2];

/**
 * 捷運站間行駛時間（TDX S2STravelTime）。每個系統 × 路線 × 車種有一種儲存型態（metro_travel_set）：
 *   route   路線站距表：從起站累加經過的車站到迄站（迄站 arr − 起站 dep）
 *   matrix  起訖矩陣：直接查 TDX 原始的起站→迄站秒數
 *   from、to      兩站間的時間（同系統）
 *   system、line  列出整份資料：routes[]（路線站距表）與 matrices[]（起訖矩陣）
 */
function finderMetroTravelTime(SqliteDao $db, array $tables)
{
    $parse = function ($name) use ($db) {
        $v = trim((string) ($_GET[$name] ?? ''));
        if ($v === '') {
            return null;
        }
        $p = finderParseUID($v);
        if ($p === null) {
            sendJson(400, ['ok' => false, 'error' => $name . ' must be a StationUID, e.g. TRTC-BL12']);
        }
        $row = finderStation($db, $p[0], $p[1]);
        return $row ? $row['uid'] : $p[0] . '-' . $p[1];
    };
    $from = $parse('from');
    $to = $parse('to');
    $system = strtoupper(trim((string) ($_GET['system'] ?? '')));
    $line = trim((string) ($_GET['line'] ?? ''));
    if (($from === null) !== ($to === null)) {
        sendJson(400, ['ok' => false, 'error' => 'from and to must be given together']);
    }
    if ($from === null && $system === '') {
        sendJson(400, ['ok' => false, 'error' => 'Give from & to (StationUID), or system (and optional line)',
                       'systems' => FINDERDB_S2S_SYSTEMS]);
    }
    if ($from !== null) {
        $system = substr($from, 0, strpos($from, '-'));
    }
    $out = ['ok' => true, 'action' => 'finder', 'func' => 'metro_travel_time',
            'query' => array_filter(['from' => $from, 'to' => $to, 'system' => $system, 'line' => $line], 'strlen')];
    if (!in_array($system, FINDERDB_S2S_SYSTEMS, true)) {
        $out['note'] = $system . '：TDX 沒有提供站間行駛時間（有的系統：' . implode('、', FINDERDB_S2S_SYSTEMS) . '）';
    }

    // 儲存型態
    $sets = [];
    foreach ($db->all('SELECT * FROM metro_travel_set WHERE system = ?' . ($line !== '' ? ' AND line_id = ?' : '') . ' ORDER BY line_id, train_type',
                      $line !== '' ? [$system, $line] : [$system]) as $s) {
        $sets[] = [
            'System' => $s['system'], 'LineID' => $s['line_id'], 'TrainType' => (int) $s['train_type'],
            'TrainTypeName' => FINDER_TRAIN_TYPES[(int) $s['train_type']] ?? '',
            'RawShape' => $s['raw_shape'], 'Storage' => $s['storage'],
            'RawPairs' => (int) $s['raw_pairs'], 'Checked' => (int) $s['checked'], 'Mismatch' => (int) $s['mismatch'],
        ];
    }
    $out['storage'] = $sets;

    // 路線站距表
    $where = 'WHERE m.system = ?';
    $params = [$system];
    if ($line !== '') {
        $where .= ' AND m.line_id = ?';
        $params[] = $line;
    }
    if ($from !== null) {
        foreach ([$from, $to] as $uid) {
            $where .= ' AND EXISTS (SELECT 1 FROM metro_travel a WHERE a.system = m.system AND a.line_id = m.line_id AND a.route_id = m.route_id
                                    AND a.direction = m.direction AND a.train_type = m.train_type AND a.station_uid = ?)';
            $params[] = $uid;
        }
    }
    $chains = [];
    foreach ($db->all("SELECT m.*, s.station_id, s.name FROM metro_travel m LEFT JOIN station s ON s.uid = m.station_uid
                       $where ORDER BY m.line_id, m.train_type, m.route_id, m.direction, m.seq", $params) as $r) {
        $k = $r['line_id'] . '|' . $r['route_id'] . '|' . $r['direction'] . '|' . $r['train_type'];
        if (!isset($chains[$k])) {
            $chains[$k] = [
                'System' => $r['system'], 'LineID' => $r['line_id'], 'RouteID' => $r['route_id'],
                'Direction' => (int) $r['direction'], 'TrainType' => (int) $r['train_type'],
                'TrainTypeName' => FINDER_TRAIN_TYPES[(int) $r['train_type']] ?? '', 'Storage' => 'route', 'Source' => $r['source'], 'Stations' => [],
            ];
        }
        $chains[$k]['Stations'][] = [
            'Sequence' => (int) $r['seq'], 'StationUID' => $r['station_uid'],
            'StationID' => $r['station_id'] ?? substr($r['station_uid'], strpos($r['station_uid'], '-') + 1),
            'StationName' => $r['name'] ? json_decode($r['name'], true) : ['Zh_tw' => '', 'En' => ''],
            'RunTime' => (int) $r['run'], 'StopTime' => (int) $r['dwell'],
            'ArrivalOffset' => (int) $r['arr'], 'DepartureOffset' => (int) $r['dep'],
            'Source' => $r['source'],   // 本站與前一站之間的資料來源
        ];
    }

    // 整條鏈的來源：各站（第 2 站起）來源不重複列出，以 + 連接
    foreach ($chains as &$c) {
        $c['Source'] = implode('+', array_values(array_unique(array_column(array_slice($c['Stations'], 1), 'Source'))));
        $c['DataTier'] = finderDbTravelTier($c['Source']);
    }
    unset($c);
    if ($from === null) {
        // 起訖矩陣：原始值，依起站、迄站排序
        $matrices = [];
        $where2 = 'WHERE o.system = ?' . ($line !== '' ? ' AND o.line_id = ?' : '');
        foreach ($db->all("SELECT o.* FROM metro_travel_od o $where2 ORDER BY o.line_id, o.train_type, o.from_uid, o.to_uid, o.seconds",
                          $line !== '' ? [$system, $line] : [$system]) as $r) {
            $k = $r['line_id'] . '|' . $r['train_type'];
            if (!isset($matrices[$k])) {
                $matrices[$k] = ['System' => $r['system'], 'LineID' => $r['line_id'], 'TrainType' => (int) $r['train_type'],
                                 'TrainTypeName' => FINDER_TRAIN_TYPES[(int) $r['train_type']] ?? '', 'Storage' => 'matrix', 'Pairs' => []];
            }
            $pair = ['FromStationUID' => $r['from_uid'], 'ToStationUID' => $r['to_uid'], 'Seconds' => (int) $r['seconds']];
            if ($r['source'] !== 'tdx') {
                $pair += ['Source' => $r['source'], 'Samples' => (int) $r['samples'], 'Agree' => (int) $r['agree']];
            }
            $matrices[$k]['Pairs'][] = $pair;
            $matrices[$k]['Sources'][$r['source']] = true;
        }
        foreach ($matrices as &$m) {
            $m['Source'] = implode('+', array_keys($m['Sources']));
            $m['DataTier'] = finderDbTravelTier($m['Source']);
            unset($m['Sources']);
        }
        unset($m);
        $out['count'] = count($chains) + count($matrices);
        $out['source'] = finderSources($db, $tables);
        $out['routes'] = array_values($chains);
        $out['matrices'] = array_values($matrices);
        return $out;
    }

    // 兩站間
    $travel = [];
    foreach ($chains as $c) {
        $best = null;
        foreach ($c['Stations'] as $i => $a) {
            if ($a['StationUID'] !== $from) {
                continue;
            }
            for ($j = $i + 1; $j < count($c['Stations']); $j++) {
                if ($c['Stations'][$j]['StationUID'] === $to) {
                    $sec = $c['Stations'][$j]['ArrivalOffset'] - $a['DepartureOffset'];
                    if ($best === null || $sec < $best['Seconds']) {
                        $best = ['Seconds' => $sec, 'Stops' => $j - $i,
                                 'Source' => implode('+', array_values(array_unique(array_column(array_slice($c['Stations'], $i + 1, $j - $i), 'Source'))))];
                    }
                    break;
                }
            }
        }
        if ($best !== null) {
            $travel[] = ['System' => $c['System'], 'LineID' => $c['LineID'], 'RouteID' => $c['RouteID'], 'Direction' => $c['Direction'],
                         'TrainType' => $c['TrainType'], 'TrainTypeName' => $c['TrainTypeName'], 'Storage' => 'route', 'Source' => $best['Source'],
                         'DataTier' => finderDbTravelTier($best['Source']), 'FromStationUID' => $from, 'ToStationUID' => $to,
                         'Seconds' => $best['Seconds'], 'Minutes' => round($best['Seconds'] / 60, 1), 'Stops' => $best['Stops']];
        }
    }
    foreach ($db->all('SELECT * FROM metro_travel_od WHERE system = ? AND from_uid = ? AND to_uid = ?' . ($line !== '' ? ' AND line_id = ?' : '')
                      . ' ORDER BY seconds', $line !== '' ? [$system, $from, $to, $line] : [$system, $from, $to]) as $r) {
        $travel[] = ['System' => $r['system'], 'LineID' => $r['line_id'], 'RouteID' => '', 'Direction' => null,
                     'TrainType' => (int) $r['train_type'], 'TrainTypeName' => FINDER_TRAIN_TYPES[(int) $r['train_type']] ?? '',
                     'Storage' => 'matrix', 'Source' => $r['source'], 'DataTier' => finderDbTravelTier($r['source']),
                     'FromStationUID' => $from, 'ToStationUID' => $to,
                     'Seconds' => (int) $r['seconds'], 'Minutes' => round($r['seconds'] / 60, 1), 'Stops' => null];
    }
    // 來源優先順序（真實 → 真實加推算）在前，同一層依秒數
    usort($travel, function ($a, $b) {
        return [finderDbTravelRank($a['Source']), $a['Seconds']] <=> [finderDbTravelRank($b['Source']), $b['Seconds']];
    });
    $out['count'] = count($travel);
    if (!$travel && !isset($out['note'])) {
        $out['note'] = '兩站不在同一條路線上（或 from 在 to 之後），需轉乘時請分段查詢';
    }
    $out['source'] = finderSources($db, $tables);
    $out['travel'] = $travel;
    return $out;
}

// ================================================================
// func=metro_trip_time

/** 秒（自 00:00 起，可超過 24 時）→ HH:MM:SS（超過 24 時以 0 時起算顯示） */
function finderSecToTime($sec, $withSec = true)
{
    $sec = (int) $sec % 86400;
    return $withSec ? sprintf('%02d:%02d:%02d', intdiv($sec, 3600), intdiv($sec % 3600, 60), $sec % 60)
                    : sprintf('%02d:%02d', intdiv($sec, 3600), intdiv($sec % 3600, 60));
}

/**
 * 捷運起訖時刻：
 *   出發：起站的站別時刻表（沒有站別時刻表的文湖線、臺中捷運用模擬時刻表）
 *   抵達：出發時間 + 站間行駛時間（metro_travel_time 的結果）＝ 推算抵達；
 *         再到迄站的站別時刻表找「推算抵達（含）之後最近的一班」同一列車（同終點、同車種；找不到再放寬為同方向）作為抵達時間；
 *         迄站沒有對應的班次（例如迄站是該列車的終點站）就用推算抵達
 */
function finderMetroTripTime(SqliteDao $db, array $tables)
{
    $uid = function ($name) use ($db) {
        $v = trim((string) ($_GET[$name] ?? ''));
        $p = $v === '' ? null : finderParseUID($v);
        if ($p === null) {
            sendJson(400, ['ok' => false, 'error' => $name . ' is required and must be a StationUID, e.g. TRTC-BL12']);
        }
        $row = finderStation($db, $p[0], $p[1]);
        if (!$row) {
            sendJson(404, ['ok' => false, 'error' => 'Station not found: ' . $v]);
        }
        return $row;
    };
    $fromSt = $uid('from');
    $toSt = $uid('to');
    $from = $fromSt['uid'];
    $to = $toSt['uid'];
    $sys = $fromSt['system'];
    if ($toSt['system'] !== $sys) {
        sendJson(400, ['ok' => false, 'error' => 'from and to must be in the same system (transfers are not handled)']);
    }
    if (in_array($sys, FINDER_TRAIN_SYSTEMS, true)) {
        sendJson(400, ['ok' => false, 'error' => 'For TRA/THSR use func=rail_train_time&station=…&dest=… (trains have exact timetables)']);
    }
    list($week, $date) = finderWeek(true);
    $start = finderTimeParam('start');
    $end = finderTimeParam('end');
    $mask = 1 << $week;
    list($sortField, $sortDesc) = finderSortParam(['DepartureTime' => true, 'ArrivalTime' => true, 'TripMinutes' => true], 'DepartureTime');

    // 站間行駛時間：路線站距表與起訖矩陣
    $chains = [];
    foreach ($db->all('SELECT * FROM metro_travel WHERE system = ? ORDER BY line_id, route_id, direction, train_type, seq', [$sys]) as $r) {
        $k = $r['line_id'] . '|' . $r['route_id'] . '|' . $r['direction'] . '|' . $r['train_type'];
        $chains[$k]['line'] = $r['line_id'];
        $chains[$k]['route'] = $r['route_id'];
        $chains[$k]['dir'] = (string) $r['direction'];
        $chains[$k]['tt'] = (int) $r['train_type'];
        $chains[$k]['st'][] = [$r['station_uid'], (int) $r['arr'], (int) $r['dep'], $r['source']];
    }
    $od = [];
    $odSource = [];
    foreach ($db->all('SELECT line_id, train_type, seconds, source FROM metro_travel_od WHERE system = ? AND from_uid = ? AND to_uid = ?', [$sys, $from, $to]) as $r) {
        $od[$r['line_id']][(int) $r['train_type']] = (int) $r['seconds'];
        $odSource[$r['line_id']] = $r['source'];
    }
    $order = [];   // 起訖矩陣的路線站序（判斷方向與列車終點是否在迄站之後）
    foreach (array_keys($od) as $line) {
        foreach ($db->all('SELECT station_uid, seq FROM line_station WHERE system = ? AND line_id = ? ORDER BY seq', [$sys, $line]) as $r) {
            $order[$line][$r['station_uid']] = (int) $r['seq'];
        }
    }
    $lineStations = [];   // 各路線所有站序出現過的車站
    foreach ($chains as $c) {
        foreach ($c['st'] as $x) {
            $lineStations[$c['line']][$x[0]] = true;
        }
    }
    // 某班車（路線、方向、終點、車種）從 from 到 to 的行駛秒數；這班車到不了 to 回 null。
    // 有多個可用的站距表／矩陣時，依來源優先順序取最好的（FINDERDB_TRAVEL_RANK：tdx → reversed → timetable → timetable-reversed）
    $travel = function ($line, $dir, $ending, $code) use ($chains, $od, $odSource, $order, $from, $to, $lineStations) {
        $cands = [];
        foreach ($chains as $c) {
            if ($c['line'] !== $line) {
                continue;
            }
            $st = array_column($c['st'], 0);
            $i = array_search($from, $st, true);
            if ($i === false) {
                continue;
            }
            for ($j = $i + 1; $j < count($st); $j++) {
                if ($st[$j] !== $to) {
                    continue;
                }
                // 列車終點在這條站序上：必須在迄站（含）之後。
                // 終點在同路線的其他站序上（例如往松山的車不進小碧潭支線）：不是這條站序的車。
                // 環狀線（站序頭尾同站）或終點不在任何站序上（例如 TDX 標 R01）：改看方向。
                // TDX 站別時刻表與 StationOfRoute 的方向編號不一定一致（例如小碧潭支線），所以能用終點判斷時不看方向
                $loop = $st[0] === $st[count($st) - 1];
                if (!$loop && $ending !== '' && in_array($ending, $st, true)) {
                    $ok = in_array($ending, array_slice($st, $j), true);
                } elseif (!$loop && $ending !== '' && isset($lineStations[$line][$ending])) {
                    $ok = false;
                } else {
                    $ok = $dir === '' || $c['dir'] === '' || $dir === $c['dir'];
                }
                if ($ok) {
                    $cands[] = ['Storage' => 'route', 'RouteID' => $c['route'], 'TrainType' => $c['tt'],
                                'Source' => implode('+', array_values(array_unique(array_column(array_slice($c['st'], $i + 1, $j - $i), 3)))),
                                'Seconds' => $c['st'][$j][1] - $c['st'][$i][2]];
                }
                break;
            }
        }
        if (isset($od[$line], $order[$line][$from], $order[$line][$to])) {
            $tt = (int) $code;
            $sec = $od[$line][$tt] ?? ($code === '' ? ($od[$line][0] ?? $od[$line][1] ?? null) : null);
            $ok = $sec !== null;
            $up = $order[$line][$to] > $order[$line][$from];
            if ($ok && $ending !== '' && isset($order[$line][$ending])) {
                $e = $order[$line][$ending];
                $ok = !($up ? $e < $order[$line][$to] : $e > $order[$line][$to]);
            }
            if ($ok) {
                $cands[] = ['Storage' => 'matrix', 'Source' => $odSource[$line] ?? 'tdx', 'RouteID' => '', 'TrainType' => $tt, 'Seconds' => $sec];
            }
        }
        $best = null;
        foreach ($cands as $x) {
            if ($best === null || finderDbTravelRank($x['Source']) < finderDbTravelRank($best['Source'])) {
                $best = $x;
            }
        }
        return $best;
    };

    // 迄站的班次（同星期），依分組整理
    $toGroups = [];
    foreach ($db->all('SELECT g.group_id, g.direction, g.info, g.simulated, d.arr, d.dep, d.arr_src, d.sort_min, d.train_type_code
                       FROM metro_dep d JOIN metro_group g ON g.group_id = d.group_id
                       WHERE g.station_uid = ? AND (g.weekmask & ?) != 0 ORDER BY d.sort_min', [$to, $mask]) as $r) {
        $gid = $r['group_id'];
        if (!isset($toGroups[$gid])) {
            $i = json_decode($r['info'], true);
            $toGroups[$gid] = ['line' => $i['LineIDs'][0] ?? '', 'dir' => (string) $r['direction'], 'ending' => $i['EndingStationUID'],
                               'sim' => (int) $r['simulated'], 'times' => []];
        }
        // 有 TDX 的到站時間就用到站時間比對；到站是推算值（臺北捷運）時用離站時間（與到站同一分鐘或晚 1 分）
        $real = $r['arr_src'] === 'timetable';
        $m = $real ? finderDbMinutes($r['arr']) : null;
        $m = $m === null ? (int) $r['sort_min'] : $m + ($m < 240 ? 1440 : 0);
        $toGroups[$gid]['times'][] = [$m * 60, $r['train_type_code'], $real ? $r['arr'] : $r['dep']];
    }
    // 時刻表只到分鐘：推算抵達取到分鐘（捨去秒）後比對；tolerance 允許提早幾分鐘（預設 0）
    $tolerance = max(0, min(5, (int) ($_GET['tolerance'] ?? (FINDER_TRIP_TOLERANCE[$sys] ?? 0))));
    $snap = function ($line, $dir, $ending, $code, $est) use ($toGroups, $to, $tolerance) {
        if ($ending === $to) {
            return null;   // 列車在迄站終止：迄站時刻表沒有這班車，用推算抵達
        }
        $est = (intdiv($est, 60) - $tolerance) * 60;
        foreach ([true, false] as $strict) {
            $best = null;
            foreach ($toGroups as $g) {
                // 只和真實的站別時刻表比對；推算時刻（平移 shifted、班距模擬 simulated，例如文湖線、臺中捷運）比對沒有意義，直接用推算抵達
                if ($g['sim'] || $g['line'] !== $line || ($dir !== '' && $g['dir'] !== '' && $g['dir'] !== $dir)) {
                    continue;
                }
                if ($strict ? ($ending === '' || $g['ending'] !== $ending) : $dir === '') {
                    continue;
                }
                foreach ($g['times'] as $t) {
                    if ($t[1] === $code && $t[0] >= $est) {
                        if ($best === null || $t[0] < $best[0]) {
                            $best = [$t[0], $t[2], $g['sim']];
                        }
                        break;
                    }
                }
            }
            if ($best !== null) {
                return $best;
            }
        }
        return null;
    };

    // 起站的班次
    $where = '';
    $params = [$from, $mask];
    if ($start !== null) {
        $where .= ' AND d.sort_min >= ?';
        $params[] = $start;
    }
    if ($end !== null) {
        $where .= ' AND d.sort_min <= ?';
        $params[] = $end;
    }
    $rows = $db->all("SELECT g.direction, g.info, g.simulated, g.source, d.arr, d.dep, d.sort_min, d.train_type_code, d.pattern_id
                      FROM metro_dep d JOIN metro_group g ON g.group_id = d.group_id
                      WHERE g.station_uid = ? AND (g.weekmask & ?) != 0 {$where}
                      ORDER BY d.sort_min, g.direction, g.group_id", $params);
    $patterns = [];
    foreach ($db->all('SELECT pattern_id, name FROM pattern WHERE system = ?', [$sys]) as $p) {
        $patterns[$p['pattern_id']] = json_decode($p['name'], true);
    }
    $trips = [];
    $skipped = 0;
    $endings = [];
    foreach ($rows as $r) {
        $info = json_decode($r['info'], true);
        $line = $info['LineIDs'][0] ?? '';
        $dir = (string) $r['direction'];
        $ending = (string) $info['EndingStationUID'];
        $code = (string) $r['train_type_code'];
        $tv = $travel($line, $dir, $ending, $code);
        if ($tv === null) {
            $skipped++;
            continue;
        }
        $depSec = (int) $r['sort_min'] * 60;
        $est = $depSec + $tv['Seconds'];
        $hit = $snap($line, $dir, $ending, $code, $est);
        $arrSec = $hit ? $hit[0] : $est;
        $endings[] = $ending;
        // 資料層級：real 真實 → mixed 真實加推算 → derived 完全推算。
        // 抵達對到真實時刻表時為 real；用推算抵達時至少是 mixed（出發＋站間時間是推算），出發或站間時間更差就取更差的
        $depTier = finderDbTimetableTier($r['source']);
        $travelTier = finderDbTravelTier($tv['Source']);
        $arrTier = $hit ? 'real' : finderDbWorstTier('mixed', $depTier, $travelTier);
        $trips[] = [
            'DepartureTime'       => $r['dep'] !== '' ? $r['dep'] : $r['arr'],
            'DepartureSource'     => $r['source'],   // timetable 真實／shifted 平移／simulated 班距模擬
            'DepartureTier'       => $depTier,
            'TravelSeconds'       => $tv['Seconds'],
            'TravelStorage'       => $tv['Storage'],
            'TravelSource'        => $tv['Source'],   // tdx／reversed／timetable／timetable-reversed，經過多種來源以 + 連接
            'TravelTier'          => $travelTier,
            'EstimatedArrival'    => finderSecToTime($est),
            'ArrivalTime'         => $hit ? $hit[1] : finderSecToTime($est, false),
            'ArrivalSource'       => $hit ? 'timetable' : 'estimate',
            'ArrivalTier'         => $arrTier,
            'DataTier'            => finderDbWorstTier($depTier, $arrTier),
            'WaitSeconds'         => $hit ? $hit[0] - $est : 0,   // 負值：時刻表比推算早（tolerance 或取到分鐘）
            'Tolerance'           => $tolerance,
            'TripMinutes'         => round(($arrSec - $depSec) / 60, 1),
            'LineID'              => $line,
            'RouteID'             => $info['RouteID'] !== '' ? $info['RouteID'] : $tv['RouteID'],
            'Direction'           => $info['Direction'],
            'EndingStationUID'    => $ending,
            'EndingStationName'   => ['Zh_tw' => '', 'En' => ''],
            'TrainTypeCode'       => $code,
            'TrainTypeName'       => isset($patterns[$r['pattern_id']]) ? $patterns[$r['pattern_id']] : ['Zh_tw' => FINDER_TRAIN_TYPES[$tv['TrainType']] ?? '', 'En' => ''],
            'ServiceTag'          => $info['ServiceTag'],
            '_sort'               => [['DepartureTime' => $depSec, 'ArrivalTime' => $arrSec, 'TripMinutes' => $arrSec - $depSec][$sortField], $depSec],
        ];
    }
    $trips = finderSortRows($trips, $sortDesc);
    $names = finderStationNames($db, array_merge($endings, [$from, $to]));
    foreach ($trips as &$t) {
        if (isset($names[$t['EndingStationUID']])) {
            $t['EndingStationName'] = $names[$t['EndingStationUID']]['name'];
        }
    }
    unset($t);

    $out = [
        'ok' => true, 'action' => 'finder', 'func' => 'metro_trip_time',
        'from' => ['StationUID' => $from, 'StationName' => is_array($fromSt['name']) ? $fromSt['name'] : json_decode($fromSt['name'], true)],
        'to'   => ['StationUID' => $to, 'StationName' => is_array($toSt['name']) ? $toSt['name'] : json_decode($toSt['name'], true)],
        'week' => $week, 'weekday' => FINDER_WEEKDAYS[$week], 'date' => $date,
        'count' => count($trips),
        'skipped' => $skipped,
        'method' => '出發＝起站站別時刻（依優先順序：真實 timetable → 由鄰站平移 shifted → 班距模擬 simulated）；推算抵達＝出發＋站間行駛時間'
                  . '（依優先順序：tdx → reversed → timetable → timetable-reversed）；'
                  . '抵達＝迄站真實站別時刻表中推算抵達（含）之後最近的同一列車（同方向、同終點、同車種，找不到則同方向、同車種；有到站時間用到站時間），'
                  . '列車在迄站終止、迄站只有推算時刻（平移或模擬，例如文湖線、臺中捷運）或沒有對應班次時用推算抵達。時刻表只到分鐘，推算抵達先捨去秒再比對；'
                  . 'tolerance＝迄站時刻可比推算提早的分鐘數（環狀輕軌預設 2，其餘 0）。DataTier：real 真實／mixed 真實加推算／derived 完全推算',
    ];
    $out['tolerance'] = $tolerance;
    $out['sort'] = $sortField;
    $out['order'] = $sortDesc ? 'desc' : 'asc';
    if (!$trips) {
        $out['note'] = $rows ? '起站的班次都到不了迄站（方向或終點不符），或兩站間需要轉乘'
                             : '起站在這個星期與時間範圍沒有班次';
        if (!$chains && !$od) {
            $out['note'] = $db->value("SELECT 1 FROM metro_travel_set WHERE system = ? AND raw_shape = 'timetable' LIMIT 1", [$sys]) !== null
                ? $sys . '：站間時間由站別時刻表串車推算，迄站為終點站時沒有資料（終點站沒有往終點方向的發車時刻）'
                : $sys . '：沒有站間行駛時間資料，無法推算';
        }
    }
    $out['source'] = finderSources($db, $tables);
    $out['trips'] = $trips;
    return $out;
}

// ================================================================
// func=list（也給 /panel/ 用）：可用功能、依賴的資料表與來源、是否最新、下載資料是否完整

function finderStatus()
{
    $defs = finderDbTables();
    $status = finderDbStatus(null, true);
    $missing = finderDbMissing();
    $funcs = [];
    foreach (finderFuncs() as $name => $f) {
        $stale = array_intersect_key($status['stale'], array_flip($f['tables']));
        $funcs[] = [
            'func'    => $name,
            'title'   => $f['title'],
            'desc'    => $f['desc'],
            'params'  => $f['params'],
            'example' => $f['example'],
            'tables'  => $f['tables'],
            'fresh'   => !$stale,
            'stale'   => (object) $stale,
        ];
    }
    $tables = [];
    foreach ($defs as $t => $d) {
        $sources = [];
        foreach ($d['sources'] as $p) {
            $f = finderDbFile($p);
            $sources[] = ['path' => $p, 'downloaded' => is_file($f),
                          'saved_at' => is_file($f) ? downloadFmtTime(filemtime($f)) : null];
        }
        $tables[] = [
            'table'   => $t,
            'desc'    => $d['desc'],
            'rows'    => $status['counts'][$t] ?? null,
            'fresh'   => !isset($status['stale'][$t]),
            'stale'   => $status['stale'][$t] ?? null,
            'sources' => $sources,
        ];
    }
    return [
        'sqlite'   => ['db' => FINDERDB_NAME, 'exists' => $status['exists'], 'fresh' => $status['fresh'],
                       'schema' => $status['schema'], 'built_at' => $status['built_at'], 'size' => $status['size']],
        'download' => ['complete' => !$missing, 'missing' => $missing],
        'funcs'    => $funcs,
        'tables'   => $tables,
    ];
}

// ================================================================
// 說明文件

function finderSendHelp()
{
    $host = $_SERVER['HTTP_HOST'] ?? SITE_HOST;
    $systems = implode('、', array_map(function ($s) { return '`' . $s . '`'; }, array_merge(FINDER_TRAIN_SYSTEMS, FINDERDB_METRO_SYSTEMS)));
    $funcRows = '';
    foreach (finderFuncs() as $name => $f) {
        $funcRows .= "| `{$name}` | {$f['desc']} | " . implode('、', array_map(function ($t) { return '`' . $t . '`'; }, $f['tables'])) . " |\n";
    }
    $tableRows = '';
    foreach (finderDbTables() as $t => $d) {
        $tableRows .= "| `{$t}` | {$d['desc']} | " . count($d['sources']) . " 個檔案 |\n";
    }
    $md = <<<MD
# /txapi/finder：靜態資料查詢

`/txapi/download` 負責**寫入**（向 TDX 取得、存到 `download/tdx/`），`/txapi/finder` 負責**讀取**：
只從 SQLite 資料庫 `sqlite/finder.sqlite` 檢索，不讀 JSON、不呼叫 TDX、不消耗額度。

| func | 用途 | 查詢的資料表 |
| --- | --- | --- |
| `help` | 本說明（省略 func 時相同） | — |
| `list` | 可用功能、各自依賴的資料表與來源 download 檔案、是否最新、下載資料是否完整（JSON） | — |
{$funcRows}
## 資料庫的建置與更新

資料庫由 download 的檔案轉換而成，每個資料表記錄自己由哪些檔案建成（路徑、mtime、大小）：

| 資料表 | 內容 | 來源 |
| --- | --- | --- |
{$tableRows}
- **查詢前自動檢查**：每個 func 執行前確認它用到的資料表是否最新；來源檔有更新（或還沒建過）就先整份重建再查詢，
  回應的 `sqlite.rebuilt` 為 `true`
- **同步完自動更新**：`/txapi/admin_download` 的 `func=sync` 全部完成（`done: true`）時，資料有變就重建，結果在回應的 `sqlite`
- **手動重建**：`/txapi/admin_download_to_sqlite/build`（後臺寫入權限），整份重新轉換（先寫暫存檔，完成後一次取代舊檔）
- **下載資料不完整時不寫入**：下載清單任何一項沒有檔案或不是合法 JSON，就回錯誤與缺少的 `missing[].path`
  （finder 回 503、admin_download_to_sqlite 回 409），現有資料庫保持不變
- 狀態可在 `/txapi/finder/list` 查看

## 共用參數

| 參數 | 說明 |
| --- | --- |
| `week` | **營運日**的星期，`0`～`6`，**`0` 為星期日**（與接軌時刻 `w0.json`～`w6.json` 相同）。省略時見下方「時間的定義」第 3 點 |
| `date` | **營運日**的日期 `YYYY-MM-DD`，換算成星期後查詢（優先於 `week`）。資料是定期時刻表，不含該日期的加開、停駛 |
| `start`、`end` | 該營運日內的時間範圍 `HH:MM`（含兩端），`00:00`～`47:59`。**過午夜的時刻要寫 24 時以後**，例如 `24:30`；各自獨立，省略的一邊不限制 |
| `direction` | 只要該方向（`0`、`1`，依各系統定義） |

## 時間的定義（營運日）

時刻表是依**營運日**分的，不是依日曆日：一個營運日從清晨首班車到深夜末班車，**過了午夜（00:00～03:59）開出的班次仍屬於前一個營運日**，
跟著前一天的平日／假日時刻表。API 不會自動換算日曆時間，串接時請依下列規則：

1. **查詢參數**（`week`、`date`、`start`、`end`）一律以營運日表示。
   營運日的時間軸為 `04:00` 起到隔天 `03:59` 止，寫成 `04:00`～`27:59`；午夜後的時間要寫 24 時以後（`00:30` → `24:30`）
2. **使用者以日曆時間查詢時由前端換算**：選的時間在 `00:00`～`03:59` 時，星期（或日期）往前一天、時間加 24 小時。
   例：週二（`week=2`）凌晨 00:30 想搭車 → 查 `week=1&start=24:30`，會得到週一晚上 00:31、00:49 的末班車；
   若直接查 `week=2&start=00:30`，`00:30` 是週二營運日最早的時間，結果等於整個週二（第一班是 06:00 的首班車）
3. `week`、`date` 都省略時，`rail_station_time`、`metro_trip_time` 取「今天」＝臺灣時間的**日曆日**，凌晨 00:00～03:59 呼叫時
   並不會自動改成前一個營運日，深夜時段請由前端帶入正確的 `week` 或 `date`；`rail_train_time` 省略時不篩星期
4. **回應的時刻**（`ArrivalTime`、`DepartureTime`、`StopTimes[]` 等）一律為 `HH:MM`、時為 `00`～`23`（與臺鐵相同；
   TDX 高雄捷運、環狀輕軌原始資料寫的 `24:xx` 已統一改成 `00:xx`）。同一營運日內 `00:xx` 的班次排在 `23:xx` 之後；
   比較先後請依回應的排序，或自行把 `00:00`～`03:59` 加 24 小時
5. 火車（臺鐵、高鐵）的營運日是**列車從起點發車的那一天**：23:50 從起點開出、00:20 才到本站的車次，屬於前一天的營運日，
   在本站的時刻顯示為 `00:20`、排在當天最後。`ServiceDay` 的星期與 `week` 篩選都以這個營運日為準
6. 跨午夜的所需時間（`TripMinutes`、`TravelSeconds`）已正確計算，例如臺鐵臺北 22:40 開、新竹 00:16 到為 96 分

**StationUID** 一律為「系統代碼-StationID」，系統代碼：{$systems}。
也接受 TDX 的寫法：環狀線、三鶯線 `NTMCC-Y07` 等同 `NTMC-Y07`，高雄輕軌 `KLRT-NETWORK-C1` 等同 `KLRT-C1`；
StationID 不分大小寫，高鐵前導 0 可省略（`THSR-990`）。

## 資料來源的優先順序

捷運、輕軌的時刻與站間時間**一律真實資料優先，其次是真實資料加推算，最後才是完全推算**。
建置資料庫時每一站、每一段都由上往下找第一個有資料的方法，所以新增車站（S2STravelTime 或站別時刻表還沒有它）也會自動取到可用的最佳值。
回應的 `DataTier`：`real` 真實／`mixed` 真實加推算／`derived` 完全推算。

| 資料 | 順序 | 來源 | 層級 | 說明 |
| --- | --- | --- | --- | --- |
| 站別時刻表 | 1 | `timetable` | real | TDX StationTimeTable |
| | 2 | `shifted` | mixed | 同營運路線、同方向最近一個有真實時刻表的車站（上游優先），把會經過本站的班次加減站間行駛時間平移 |
| | 3 | `simulated` | derived | 首末班車起依時段平均班距排出（FirstLastTimetable＋Frequency；文湖線、臺中捷運） |
| 站間行駛時間（每一段） | 1 | `tdx` | real | TDX S2STravelTime 同方向 |
| | 2 | `reversed` | mixed | S2STravelTime 反方向同一區間倒用 |
| | 3 | `timetable` | mixed | 同方向站別時刻表的離站時間差（或逐班串車的起訖矩陣） |
| | 4 | `timetable-reversed` | mixed | 反方向站別時刻表的離站時間差（到終點站的區間） |
| 抵達時刻 | 1 | `timetable` | real | 迄站**真實**站別時刻表中推算抵達之後最近的同一列車 |
| | 2 | `estimate` | mixed（出發或站間時間更差時取更差的） | 出發＋站間行駛時間 |

- 一站一方向只會有一種時刻表來源；站間時間的一條路線站距表可以混合多種來源（`Source` 以 `+` 連接，層級取最差的）
- 起訖矩陣（機場捷運）只在路線站距表能**完全重現**時才轉存站距表，矩陣缺少的車站才由站別時刻表串車補上含該站的起訖對
- 票價不推算（沒有公式就不能推導）

## func=rail_station_time

```
GET https://{$host}/txapi/finder/rail_station_time?station=TRA-1000&week=1
GET https://{$host}/txapi/finder/rail_station_time?station=TRTC-BL12&date=2026-10-05&start=07:00&end=09:00
```

| 參數 | 必填 | 說明 |
| --- | --- | --- |
| `station` | 是 | StationUID |
| `week`／`date` | 否 | 省略時為今天（臺灣時間）的星期 |
| `start`、`end`、`direction` | 否 | 篩選 |
| `simulated` | 否 | `1`：沒有真實站別時刻表的車站也列出推算時刻（每筆多 `Simulated` 0／1、`Source` timetable／shifted／simulated、`DataTier`） |

回應：`station`（車站資訊與所屬路線）、`week`、`count`、`directions`（各方向班次數）、`source`（來源檔與存檔時間、
時刻表適用期間）、`sqlite`（資料庫建置時間、這次是否重建、查詢的資料表）、`timetable`（依時刻排序，
每筆為下方「列車欄位」加上本站的 `StopSequence`、`ArrivalTime`、`DepartureTime`）。

- 臺鐵、高鐵：每筆有 `TrainKey`，可用 `rail_train_time&key=…` 查該班列車的完整停靠站
- 捷運、輕軌：只有各站發車時刻，沒有車次，`TrainNo`、`TrainKey` 為空字串；起點由營運路線站序推得，終點為該班目的地
  （TDX 標示的營運路線與站序對不上時，只能確定方向與站序，起點為空字串，例如臺北捷運部分 `R-2` 班次）
- 臺北捷運**文湖線**、**臺中捷運**沒有站別時刻表，`timetable` 為空陣列並附 `note`；加 `simulated=1` 可看模擬時刻
  （比照接軌時刻：從 `FirstLastTimetable` 的首班車起，依 `Frequency` 該時段班距的平均值 (最小+最大)÷2 往後排到末班車）
- 路線上其他車站有真實時刻表、只有本站沒有時（例如新站），`simulated=1` 列出的是由鄰站平移的時刻（`Source: shifted`），優先於班距模擬

## func=rail_train_time

```
GET https://{$host}/txapi/finder/rail_train_time?key=TRA-123
GET https://{$host}/txapi/finder/rail_train_time?system=THSR&train=108&week=5
GET https://{$host}/txapi/finder/rail_train_time?system=TRA&week=1&station=TRA-1000&dest=TRA-4400&start=08:00&end=10:00
```

| 參數 | 說明 |
| --- | --- |
| `key` | 列車 key（`rail_station_time` 回應的 `TrainKey`），例如 `TRA-123`、`THSR-0108`；結果最多一筆 |
| `system` | `TRA`、`THSR`，可用逗號分隔；省略為兩者。給捷運、輕軌系統時回空陣列 |
| `train` | 車次，前導 0 可省略（`108` 等同 `0108`） |
| `week`／`date` | 只要該星期行駛的列車；**省略時不篩星期**（每筆都有 `ServiceDay`） |
| `station` | 只要停靠此站的列車；`start`、`end` 以此站的時刻比對，否則以起點發車時刻比對 |
| `dest` | 只要在 `station` 之後（或全程）停靠此站的列車 |
| `type` | 車種，`TrainTypeCode` 或 `TrainTypeID`，可用逗號分隔 |
| `line` | 行經路線 `LineID`（在 `LineIDs` 內） |
| `sort` | 排序欄位（不分大小寫）：`DepartureTime`（預設；`station` 的離站，沒有 `station` 時為起點發車）、`ArrivalTime`（`dest` 的到站，需要 `dest`）、`TripMinutes`（`station`→`dest` 所需分鐘，需要兩者） |
| `order` | `asc`（預設）／`desc`；同值時依離站時間 |

回應：`count`、`source`、`sqlite`、`query`（含實際使用的 `sort`、`order`）、`trains`，每筆為「列車欄位」加上 `ServiceDay` 與 `StopTimes`。
有 `station` 時每筆另有 `DepartureTime`（本站離站），有 `dest` 時有 `ArrivalTime`（迄站到站），兩者都有時有 `TripMinutes`。

**找最快到達而不是最早出發**：`sort=ArrivalTime`，排在前面但 `DepartureTime` 較晚的就是後發先至的車次。
例：週一臺北→新竹 08:00～09:30 出發，`sort=ArrivalTime` 第一班是 08:13 自強 109（09:23 到），08:03 的區間車 1131 排第三（09:55 到）。
`start`、`end` 篩的是出發時間，範圍外更晚出發、但更早到的車不會列入，需要時把 `end` 放寬。排序以連續分鐘計，過午夜到站（例如 23:xx 開、00:16 到）也排得正確

## func=metro_trip_time

捷運兩站間每一班的出發與抵達時刻（同一系統、不轉乘）。火車有車次時刻表，請用 `rail_train_time&station=…&dest=…`。

```
GET https://{$host}/txapi/finder/metro_trip_time?from=TRTC-BL12&to=TRTC-BL05&week=1&start=08:00&end=09:00
GET https://{$host}/txapi/finder/metro_trip_time?from=TRTC-BR10&to=TRTC-BR24&date=2026-10-05&start=17:00&end=18:00
```

| 參數 | 必填 | 說明 |
| --- | --- | --- |
| `from`、`to` | 是 | StationUID（同一系統） |
| `week`／`date` | 否 | 省略為今天 |
| `start`、`end` | 否 | 起站出發時間範圍 `HH:MM`（過午夜寫 24 時以後） |
| `tolerance` | 否 | `0`～`5`，迄站時刻表可比推算抵達提早幾分鐘（預設：環狀輕軌 `2`，其餘 `0`） |
| `sort` | 否 | 排序欄位（不分大小寫）：`DepartureTime`（預設）、`ArrivalTime`（找最快到達、後發先至）、`TripMinutes` |
| `order` | 否 | `asc`（預設）／`desc`；同值時依出發時間 |

例：機捷 A1→A12 週一 08:00～09:00 出發，`sort=ArrivalTime` 時 08:15 直達車（08:51 到）排在 08:08 普通車（08:56 到）前面。
回應頂層有實際使用的 `sort`、`order`；`start`、`end` 篩的是出發時間

演算法：

1. **出發**：起站的站別時刻表，依優先順序：真實（`timetable`）→ 由鄰站平移（`shifted`）→ 班距模擬（`simulated`，文湖線、臺中捷運）
2. **篩選列車**：這班車要經過迄站——列車終點在迄站（含）之後；終點在同路線其他站序上（例如往松山的車不進小碧潭支線）就排除；
   環狀線或終點不明時看方向。不經過的計入 `skipped`
3. **推算抵達** = 出發 + 站間行駛時間（`metro_travel_time`：路線站距表從起站累加到迄站，或起訖矩陣直接查；機捷依車種；
   有多個可用時取來源優先順序最好的）
4. **抵達**：在迄站的站別時刻表找**推算抵達（含）之後最近的一班**同一列車（同方向、同終點、同車種；找不到再放寬為同方向、同車種），
   有到站時間就用到站時間。時刻表只到分鐘，推算抵達先捨去秒再比對。
   **只和真實的站別時刻表比對**：迄站只有推算時刻（平移或模擬，例如文湖線、臺中捷運）、列車在迄站終止（迄站時刻表沒有這班車）或找不到時，用推算抵達

回應 `trips[]`：`DepartureTime`、`DepartureSource`（`timetable`／`shifted`／`simulated`）、`DepartureTier`、`TravelSeconds`、`TravelStorage`（`route`／`matrix`）、
`TravelSource`（`tdx`／`reversed`／`timetable`／`timetable-reversed`，多種以 `+` 連接）、`TravelTier`、`EstimatedArrival`（HH:MM:SS）、`ArrivalTime`、
`ArrivalSource`（`timetable`／`estimate`）、`ArrivalTier`、`DataTier`（整班最差的層級）、`WaitSeconds`
（迄站時刻減推算抵達）、`TripMinutes`、`LineID`、`RouteID`、`Direction`、`EndingStationUID`、`EndingStationName`、`TrainTypeCode`、`TrainTypeName`。

- TDX 的站間行駛時間比時刻表長時，會對到下一班（`WaitSeconds` 接近班距）。高雄環狀輕軌 C1→C5 站間時間 10 分、時刻表 9 分，
  所以環狀輕軌預設 `tolerance=2`
- 淡海、安坑輕軌用站別時刻表串車推算的起訖矩陣（`TravelSource: timetable`）；到終點站的區間以反方向時間推估（`timetable-reversed`）。需要轉乘的兩站回空陣列

## func=metro_travel_time

```
GET https://{$host}/txapi/finder/metro_travel_time?from=TRTC-BL12&to=TRTC-BL01
GET https://{$host}/txapi/finder/metro_travel_time?from=TYMC-A1&to=TYMC-A12
GET https://{$host}/txapi/finder/metro_travel_time?system=KLRT
```

| 參數 | 說明 |
| --- | --- |
| `from`、`to` | 兩個 StationUID（同一系統）。回 `travel[]`，依來源優先順序、再依秒數排序 |
| `system`、`line` | 不給 from、to 時列出整份資料：`routes[]`（路線站距表）與 `matrices[]`（起訖矩陣） |

- 資料來自 TDX `S2STravelTime`（`TRTC`、`NTMC`、`KRTC`、`KLRT`、`TYMC`、`TMRT`）；淡海、安坑輕軌 TDX 沒有，
  改由站別時刻表逐班串車推算起訖矩陣（`Source: timetable`，每組附 `Samples` 班次數與 `Agree` 與眾數相同的班次數；
  「某站→終點站」串不出來，以反方向同一區間的時間推估，`Source: timetable-reversed`）
- 每個系統 × 路線 × 車種只有一種儲存型態，回應的 `storage[]` 列出：`RawShape`（TDX 原始形狀 chain／matrix）、
  `Storage`（`route` 路線站距表／`matrix` 起訖矩陣）、`Checked`、`Mismatch`（起訖矩陣的比對結果）
- **路線站距表**（`routes[]`）：一條營運路線 × 一個方向 × 一種車種一份，`Stations[]` 每站有 `RunTime`、`StopTime`、
  `ArrivalOffset`、`DepartureOffset`（從起站離站起算的秒數）。兩站間時間 = 迄站 `ArrivalOffset` − 起站 `DepartureOffset`
  （與接軌時刻 `offset_time` 相減的用法相同，單位為秒）
- **起訖矩陣**（`matrices[]`）：`Pairs[]` 為 TDX 原始的 `FromStationUID`、`ToStationUID`、`Seconds`，直接查；目前只有機場捷運
  （它的矩陣值含中間停站，相鄰兩站相加無法重現，所以不轉成路線站距表）
- `travel[]`：`Storage`、`Source`、`DataTier`、`Seconds`、`Minutes`、`Stops`（路線站距表才有），機場捷運會同時列出直達車與普通車；
  `routes[]`、`matrices[]` 也有 `DataTier`，矩陣中非 TDX 的起訖對另附 `Source`、`Samples`、`Agree`
- `Source: reversed`：TDX 只給了反方向（臺北捷運、新北捷運），以反方向的區間時間倒過來計算
- 路線站距表的 `Source` 取起站與迄站之間各站的來源；S2STravelTime 沒有的段落（例如淡水信義線 R01 廣慈/奉天宮，或路線中間新增的車站）
  依優先順序由站別時刻表的離站時間差補上，`Source: timetable`（往 R01 方向為 `timetable-reversed`）。
  時間差取眾數 ±1 分鐘內的平均換成秒；時間差已含下一站停站，所以該站 `StopTime` 記 0
- 兩站不在同一條路線上（需要轉乘）時回空陣列，請分段查詢
- 儲存型態的判斷規則見 `/txapi/download` 說明的「站間行駛時間」

## func=rail_stations

```
GET https://{$host}/txapi/finder/rail_stations
GET https://{$host}/txapi/finder/rail_stations?operator=TRTC,NTMC
```

| 參數 | 說明 |
| --- | --- |
| `operator` | 營運單位：`TRA` 臺鐵、`THSR` 高鐵、`TRTC` 臺北捷運、`NTMC` 新北捷運（含淡海、安坑輕軌）、`TYMC` 桃園捷運、`TMRT` 臺中捷運、`KRTC` 高雄捷運（含環狀輕軌）；逗號分隔，省略為全部 |
| `system` | 只要這些系統（例如 `NTDLRT`），逗號分隔 |

回應：`operators[]`，每個營運單位有 `OperatorName`、`Systems`、`LineCount`、`StationCount` 與 `Lines[]`；
每條路線有 `System`、`LineID`、`LineName`、`LineColor`（`#rrggbb`）、`LineColorSource`、`IsBranch`、`Sections[]`、`Stations[]`
（`Sequence`、`StationUID`、`StationID`、`StationName`，依站序）。轉乘站會出現在每一條所屬路線。
`Sections[]` 是顯示區段（`Name`、`Short`、`Color`、`ColorSource`、`FromSequence`～`ToSequence` 對應 `Stations[].Sequence`，含兩端）：
臺鐵依 railtime 的路線選擇器分段（例如西部幹線分成基隆-竹南、山線、彰化-嘉義、嘉義-高雄、屏東線），其他系統整條一段。

- 站序：臺鐵依 `StationOfLine`；高鐵南港→左營；捷運以最長的營運路線為主幹，支線、區間多出的車站接在後面
- 代表色：`tdx` 取自 TDX 的 `LineColor`（臺北、新北、桃園、臺中捷運）；TDX 沒有提供的臺鐵、高鐵、高雄捷運與環狀輕軌為 `railtime`，
  取自 railtime 專案（melixyen.github.io/railtime）路線選擇器與路網圖的顏色；臺鐵路線的 `LineColor` 為第一個區段的顏色

## 統一格式

以**臺鐵 v3 定期時刻表**的欄位名稱為基底；高鐵、捷運同義的欄位改用臺鐵名稱，臺鐵沒有的資訊另加欄位。
**所有欄位一律存在**，沒有值時為空字串（`LineIDs` 為空陣列）。

### 列車欄位

| 欄位 | 說明 | 臺鐵 | 高鐵 | 捷運、輕軌 |
| --- | --- | --- | --- | --- |
| `System` ＊ | 系統代碼 | `TRA` | `THSR` | `TRTC` 等 |
| `TrainKey` ＊ | 追蹤列車的 key，給 `rail_train_time&key=` 用 | `TRA-車次` | `THSR-車次` | 空字串 |
| `TrainNo` | 車次 | ✓ | ✓ | 空字串 |
| `Direction` | 行駛方向 | 0 順行、1 逆行 | 0 南下、1 北上 | 0／1（依各系統；缺值時由營運路線推得） |
| `TrainTypeID`、`TrainTypeCode`、`TrainTypeName` | 車種（車的等級） | ✓ | 空字串 | 機場捷運：`TrainTypeCode` 1 普通、2 直達，`TrainTypeName` 取自停站模式名稱 |
| `TripHeadSign` | 行駛方向說明 | ✓ | 空字串 | 空字串 |
| `StartingStationUID`＊、`StartingStationID`、`StartingStationName` | 起點 | ✓ | ✓ | 營運路線的起點 |
| `EndingStationUID`＊、`EndingStationID`、`EndingStationName` | 終點 | ✓ | ✓ | 該班目的地 |
| `LineIDs` ＊ | 行經路線（陣列） | 依停靠站在路線圖上的最短路徑推得，例如 `["WL","EL"]` | 取站間里程的 LineID | 該筆時刻所屬路線 |
| `RouteID`、`TripLine`、`OverNightStationID` | 臺鐵原欄位 | ✓ | 空字串 | `RouteID` 為營運路線 |
| `Overnight` ＊ | 是否跨日（1／0） | 由 `OverNightStationID` 推得 | ✓ | 空字串 |
| `StoppingPatternID` ＊ | 停站模式 | 空字串 | 空字串 | 機場捷運 |
| `ServiceTag` ＊ | 營運日類別（平日、假日…） | 空字串 | 空字串 | ✓ |
| `WheelChairFlag`、`PackageServiceFlag`、`DiningFlag`、`BreastFeedFlag`、`BikeFlag`、`CarFlag`、`DailyFlag`、`ExtraTrainFlag` | 臺鐵服務旗標 | ✓ | 空字串 | 空字串 |
| `Note` | 附註 | ✓ | ✓（取中文） | 空字串 |

＊ 為臺鐵沒有、另外加上的欄位。

### 停靠站欄位（`StopTimes[]`，以及 `rail_station_time` 每筆的本站時刻）

| 欄位 | 說明 |
| --- | --- |
| `StopSequence` | 停靠順序；捷運為本站在營運路線的站序 |
| `StationUID`＊、`StationID`、`StationName` | 車站 |
| `ArrivalTime`、`DepartureTime` | 到站、離站時間 `HH:MM`，**每一站、每一筆都有值**，時為 00～23（過午夜看排序，不寫 24:xx） |
| `ArrivalTimeSource`、`DepartureTimeSource` | 時間來源：`timetable` TDX 原始值；`origin` 起站沒有到站時間，以離站時間填（高鐵）；`terminal` 終點站沒有離站時間，以到站時間填（高鐵）；`estimate` 到站 ＝ 離站 − 本站停站秒數（臺北捷運、平移與模擬時刻只有離站時間） |

各系統的到站時間：臺鐵、高鐵、**機場捷運**、新北捷運（環狀線、三鶯線部分站）為 TDX 獨立的到站時間，可看出停站時間（例如機捷長庚醫院 A8
普通車 08:04 到、08:08 開，直達車 08:06 到、08:07 開，直達車在此追越）；高雄捷運、環狀輕軌、淡海、安坑輕軌 TDX 的到站與離站相同；
臺北捷運只有離站，到站為推算（`estimate`）。

### `ServiceDay`（`rail_train_time`）

`Monday`～`Sunday`、`NationalHolidays`、`DayBeforeHoliday`、`DayAfterHoliday`、`TyphoonDay`，1／0；高鐵沒有的假日欄位為空字串。

## 注意

- 資料來源為定期時刻表，反映 TDX 目前生效的版本；`source[].validity` 為適用期間（臺鐵連假前後會出現只適用幾天的臨時版本）
MD;
    http_response_code(200);
    header('Content-Type: text/markdown; charset=utf-8');
    echo $md;
    exit;
}

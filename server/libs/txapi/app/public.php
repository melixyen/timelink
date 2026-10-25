<?php
/**
 * public_ API：不分身分的基礎資料（車站、路線、系統與日曆、路由代碼解析）。結果只隨離線資料更新而變，可被快取。
 *
 *   /txapi/public_station/list|search|detail
 *   /txapi/public_line/list|detail
 *   /txapi/public_info/systems|calendar|route
 */

/** 車站座標快取檔（從 download 的車站資料整理；download 檔案更新時重建） */
function appStationPositionsFile()
{
    return TDX_CACHE_DIR . '/app_station_positions.json';
}

/** 車站座標：StationUID → ['Lat', 'Lon']（TDX 車站資料的 StationPosition） */
function appStationPositions()
{
    static $pos = null;
    if ($pos !== null) {
        return $pos;
    }
    $files = ['TRA' => '/v2/Rail/TRA/Station', 'THSR' => '/v2/Rail/THSR/Station'];
    foreach (FINDERDB_METRO_SYSTEMS as $s) {
        $files[$s] = '/v2/Rail/Metro/Station/' . $s;
    }
    $sig = [];
    foreach ($files as $path) {
        $f = finderDbFile($path);
        $sig[] = $path . '@' . (is_file($f) ? filemtime($f) . ':' . filesize($f) : '-');
    }
    $sig = sha1(implode('|', $sig));
    $cache = appStationPositionsFile();
    $j = is_file($cache) ? json_decode((string) @file_get_contents($cache), true) : null;
    if (is_array($j) && ($j['sig'] ?? '') === $sig) {
        return $pos = $j['pos'];
    }
    $pos = [];
    foreach ($files as $sys => $path) {
        try {
            $list = finderDbLoad($path);
        } catch (Throwable $e) {
            continue;
        }
        foreach ($list as $x) {
            if (isset($x['StationID'], $x['StationPosition']['PositionLat'])) {
                $pos[finderDbUID($sys, $x['StationID'])] = ['Lat' => (float) $x['StationPosition']['PositionLat'],
                                                             'Lon' => (float) $x['StationPosition']['PositionLon']];
            }
        }
    }
    if (!is_dir(TDX_CACHE_DIR)) {
        @mkdir(TDX_CACHE_DIR, 0755, true);
    }
    @file_put_contents($cache, json_encode(['sig' => $sig, 'pos' => $pos]), LOCK_EX);
    return $pos;
}

/** 車站所屬的多站轉乘群組（不含只有一站的群組） */
function appStationGroups(array $m, $uid)
{
    $out = [];
    foreach ($m['groupOf'][$uid] ?? [] as $gid) {
        if (($m['groups'][$gid]['Type'] ?? '') === 'multi') {
            $out[] = $gid;
        }
    }
    return $out;
}

/** 系統 → 營運單位代碼與名稱 */
function appSystemOperator($sys)
{
    foreach (FINDERDB_OPERATORS as $id => $o) {
        if (in_array($sys, $o['systems'], true)) {
            return ['Operator' => $id, 'OperatorName' => $o['name']];
        }
    }
    return ['Operator' => $sys, 'OperatorName' => $sys];
}

/** 一個車站的基本欄位 */
function appStationBasic(array $m, array $row, array $pos)
{
    return [
        'StationUID'     => $row['uid'],
        'System'         => $row['system'],
        'StationID'      => $row['station_id'],
        'StationName'    => is_array($row['name']) ? $row['name'] : json_decode($row['name'], true),
        'Lines'          => array_keys($m['stationLines'][$row['uid']] ?? []),
        'Position'       => $pos[$row['uid']] ?? null,
        'TransferGroups' => appStationGroups($m, $row['uid']),
    ];
}

// ================================================================
// public_station

function actionPublicStation()
{
    appDispatch('public_station', [
        'list'   => ['appPublicStationList', '車站清單：StationUID、中英站名、系統、所屬路線、座標、轉乘群組；帶 line 時依站序並附 Sequence',
                     ['system' => '系統代碼，可多個（TRA,TRTC）', 'line' => '路線代碼 LineUID（依站序）'],
                     txapiUrl('public_station', 'list', ['line' => 'TRTC-BL'])],
        'search' => ['appPublicStationSearch', '站名搜尋：台／臺、簡繁、英文、拼音、拼音首字母、站碼的模糊比對',
                     ['q' => '關鍵字（必填）', 'system' => '系統代碼，可多個', 'limit' => '筆數 1～' . APP_STATION_SEARCH_LIMIT_MAX . '（預設 ' . APP_STATION_SEARCH_LIMIT_DEFAULT . '）'],
                     txapiUrl('public_station', 'search', ['q' => 'taibei'])],
        'detail' => ['appPublicStationDetail', '單一車站：所屬路線與前後站、同站換車、快慢車換車站、轉乘群組（可轉乘的車站、步行分鐘、轉乘影片）',
                     ['station' => '車站代碼 StationUID（必填）'],
                     txapiUrl('public_station', 'detail', ['station' => 'TRA-1000'])],
    ]);
}

function appPublicStationList()
{
    $db = appDb();
    $m = appModel();
    $systems = appParamSystems();
    $line = appParam('line') !== null ? appLineParam('line') : null;
    $pos = appStationPositions();
    $rows = [];
    foreach ($db->all('SELECT uid, system, station_id, name, line_ids FROM station') as $r) {
        $rows[$r['uid']] = $r;
    }
    $out = [];
    if ($line !== null) {
        foreach ($m['lineStations'][$line] as $i => $u) {
            if (isset($rows[$u]) && (!$systems || in_array($rows[$u]['system'], $systems, true))) {
                $out[] = ['Sequence' => $i + 1] + appStationBasic($m, $rows[$u], $pos);
            }
        }
    } else {
        $prio = array_flip(array_merge(FINDER_TRAIN_SYSTEMS, FINDERDB_METRO_SYSTEMS));
        uasort($rows, function ($a, $b) use ($prio) {
            return [$prio[$a['system']] ?? 99, $a['station_id']] <=> [$prio[$b['system']] ?? 99, $b['station_id']];
        });
        foreach ($rows as $r) {
            if (!$systems || in_array($r['system'], $systems, true)) {
                $out[] = appStationBasic($m, $r, $pos);
            }
        }
    }
    appSend(['count' => count($out), 'stations' => $out], array_filter(['system' => $systems ? implode(',', $systems) : null, 'line' => $line]));
}

function appPublicStationSearch()
{
    $db = appDb();
    $q = appParamRequired('q', '搜尋關鍵字，例如 台北、taibei、tb、BL12');
    $limit = appParamInt('limit', APP_STATION_SEARCH_LIMIT_DEFAULT, 1, APP_STATION_SEARCH_LIMIT_MAX);
    $systems = appParamSystems();
    $r = transferSearchStations($db, $q, $limit, $systems);
    $pos = appStationPositions();
    foreach ($r['results'] as &$x) {
        $x['Position'] = $pos[$x['StationUID']] ?? null;
    }
    unset($x);
    appSend(['query' => $r['query'], 'total' => $r['total'], 'count' => count($r['results']), 'stations' => $r['results'],
             'missingChars' => $r['missingChars'] ?? []]);
}

function appPublicStationDetail()
{
    $db = appDb();
    $m = appModel();
    $uid = appStationParam('station');
    $p = finderParseUID($uid);
    $row = finderStation($db, $p[0], $p[1]);
    $lines = appLineInfo();
    $pos = appStationPositions();
    $out = appStationBasic($m, $row, $pos);
    $st = function ($u) use ($m) {
        return $u === null ? null : ['StationUID' => $u, 'StationName' => $m['names'][$u] ?? null];
    };
    $out['Lines'] = [];
    foreach ($m['stationLines'][$uid] ?? [] as $lu => $i) {
        $seq = $m['lineStations'][$lu];
        $out['Lines'][] = ['LineUID' => $lu, 'LineName' => $lines[$lu]['LineName'] ?? null, 'LineColor' => $lines[$lu]['LineColor'] ?? null,
                           'Sequence' => $i + 1, 'Prev' => $st($seq[$i - 1] ?? null), 'Next' => $st($seq[$i + 1] ?? null)];
    }
    $out['TrainChange'] = $m['change'][$uid] ?? null;
    $out['ClassChange'] = [
        'Relay'       => in_array($uid, appRelayStations(), true),
        'ClassChange' => in_array($uid, appClassChangeStations($row['system']), true),
    ];
    $groups = [];
    foreach (appStationGroups($m, $uid) as $gid) {
        $g = $m['groups'][$gid];
        $members = [];
        foreach ($g['Stations'] as $x) {
            if ($x['StationUID'] === $uid) {
                continue;
            }
            $go = transferWalk($m, $uid, $x['StationUID'], true, true);
            $back = transferWalk($m, $x['StationUID'], $uid, true, true);
            $members[] = ['StationUID' => $x['StationUID'], 'System' => $x['System'], 'StationName' => $x['StationName'], 'Lines' => $x['Lines'],
                          'WalkMinutes' => $go['Minutes'] ?? null, 'WalkSource' => $go['Source'] ?? null,
                          'WalkBackMinutes' => $back['Minutes'] ?? null,
                          'Videos' => transferVideos($uid, $x['StationUID'])];
        }
        $groups[] = ['TransferGroupID' => $gid, 'GroupName' => $g['GroupName'], 'Lines' => $g['Lines'], 'Stations' => $members];
    }
    $out['TransferGroups'] = $groups;
    appSend($out);
}

// ================================================================
// public_line

function actionPublicLine()
{
    appDispatch('public_line', [
        'list'   => ['appPublicLineList', '路線清單：LineUID、名稱、代表色、系統、營運單位、兩端終點、車站數',
                     ['system' => '系統代碼，可多個'], txapiUrl('public_line', 'list', ['system' => 'TRA'])],
        'detail' => ['appPublicLineDetail', '路線站序（含顯示區段）、各站可轉乘的路線、可接續的路線',
                     ['line' => '路線代碼 LineUID（必填）'], txapiUrl('public_line', 'detail', ['line' => 'TRA-WL'])],
    ]);
}

/** 一條路線的基本欄位 */
function appLineBasic(array $m, array $l)
{
    $seq = $m['lineStations'][$l['LineUID']] ?? [];
    $st = function ($u) use ($m) {
        return $u === null ? null : ['StationUID' => $u, 'StationName' => $m['names'][$u] ?? null];
    };
    return $l + appSystemOperator($l['System']) + [
        'Termini'      => [$st($seq[0] ?? null), $st($seq[count($seq) - 1] ?? null)],
        'StationCount' => count($seq),
        'ClassSplit'   => appClassHasSplit($l['System']),
    ];
}

function appPublicLineList()
{
    appDb();
    $m = appModel();
    $systems = appParamSystems();
    $out = [];
    foreach (appLineInfo() as $l) {
        if (!$systems || in_array($l['System'], $systems, true)) {
            $out[] = appLineBasic($m, $l);
        }
    }
    appSend(['count' => count($out), 'lines' => $out], array_filter(['system' => $systems ? implode(',', $systems) : null]));
}

function appPublicLineDetail()
{
    $db = appDb();
    $m = appModel();
    $lu = appLineParam('line');
    $lines = appLineInfo();
    $l = $lines[$lu];
    $out = appLineBasic($m, $l);
    $out['Sections'] = [];
    foreach ($db->all('SELECT * FROM line_section WHERE system = ? AND line_id = ? ORDER BY seq', [$l['System'], $l['LineID']]) as $x) {
        $out['Sections'][] = ['Name' => $x['name'], 'Short' => $x['short'], 'Color' => $x['color'],
                              'FromSequence' => (int) $x['from_seq'], 'ToSequence' => (int) $x['to_seq']];
    }
    $out['Stations'] = [];
    foreach ($m['lineStations'][$lu] as $i => $u) {
        $other = [];
        foreach (appStationGroups($m, $u) as $gid) {
            foreach ($m['groups'][$gid]['Lines'] as $ol) {
                if ($ol !== $lu) {
                    $other[$ol] = true;
                }
            }
        }
        foreach (array_keys($m['stationLines'][$u] ?? []) as $ol) {
            if ($ol !== $lu) {
                $other[$ol] = true;
            }
        }
        $out['Stations'][] = ['Sequence' => $i + 1, 'StationUID' => $u, 'StationName' => $m['names'][$u] ?? null,
                              'TransferGroups' => appStationGroups($m, $u), 'TransferLines' => array_keys($other),
                              'TrainChange' => isset($m['change'][$u])];
    }
    $out['LinkedLines'] = [];
    foreach ($m['links'][$lu] ?? [] as $to => $gids) {
        $out['LinkedLines'][] = ['LineUID' => $to, 'LineName' => $lines[$to]['LineName'] ?? null, 'LineColor' => $lines[$to]['LineColor'] ?? null,
                                 'TransferGroups' => $gids];
    }
    appSend($out);
}

// ================================================================
// public_info

function actionPublicInfo()
{
    appDispatch('public_info', [
        'systems'  => ['appPublicInfoSystems', '系統清單：營運單位、路線與車站數、時刻資料的性質、慢車／快車分類與排除用的 extra 位元', [],
                       txapiUrl('public_info', 'systems')],
        'calendar' => ['appPublicInfoCalendar', '營運日的定義、可查詢的日期寫法、各項離線資料的更新時間', [],
                       txapiUrl('public_info', 'calendar')],
        'route'    => ['appPublicInfoRoute', '解析路由代碼 route：檢查寫法與資料、各段內容、顯示文字；帶 extra 時檢查等級是否允許',
                       ['route' => '路由代碼（必填）', 'extra' => '0～3（選填）'],
                       txapiUrl('public_info', 'route', ['route' => 'TRA-0950.TRA-WL.local.TRA-1000.TRA-WL.express.TRA-3300'])],
    ]);
}

/** 系統的時刻資料性質 */
function appSystemTimetableKind($sys)
{
    if (in_array($sys, FINDER_TRAIN_SYSTEMS, true)) {
        return ['TimetableKind' => 'train', 'TimetableNote' => '真實的列車時刻表（每班車有車次與各站時刻）'];
    }
    if (finderDbHasStationTimetable($sys)) {
        return ['TimetableKind' => 'station_timetable',
                'TimetableNote' => '站別時刻表（TDX StationTimeTable）；沒有時刻表的車站由鄰站平移' . (in_array($sys, FINDERDB_SIM_SYSTEMS, true) ? '或班距模擬' : '') . '，到站時刻由站間行駛時間推算'];
    }
    return ['TimetableKind' => 'simulated', 'TimetableNote' => '沒有站別時刻表：依首末班車與班距模擬，到站時刻由站間行駛時間推算'];
}

function appPublicInfoSystems()
{
    $db = appDb();
    $m = appModel();
    $cfg = appClassConfig();
    $count = [];
    foreach ($db->all('SELECT system, COUNT(*) AS n FROM station GROUP BY system') as $r) {
        $count[$r['system']] = (int) $r['n'];
    }
    $lineCount = [];
    foreach (appLineInfo() as $l) {
        $lineCount[$l['System']] = ($lineCount[$l['System']] ?? 0) + 1;
    }
    $out = [];
    foreach (array_merge(FINDER_TRAIN_SYSTEMS, FINDERDB_METRO_SYSTEMS) as $sys) {
        $split = null;
        if (isset($cfg[$sys])) {
            $split = ['Classify' => $cfg[$sys]['Classify'], 'Local' => appClassLabel($sys, APP_CLASS_LOCAL), 'Express' => appClassLabel($sys, APP_CLASS_EXPRESS),
                      'ChangeStations' => $cfg[$sys]['Stations'] ?? null, 'Relay' => $cfg[$sys]['Relay'] ?? null, 'Note' => $cfg[$sys]['Note'] ?? null];
        }
        $out[] = ['System' => $sys] + appSystemOperator($sys) + [
            'LineCount'    => $lineCount[$sys] ?? 0,
            'StationCount' => $count[$sys] ?? 0,
        ] + appSystemTimetableKind($sys) + [
            'ClassSplit'   => $split,
            'ExcludeBit'   => $sys === 'THSR' ? APP_EXTRA_NO_HSR : ($sys === 'TRA' ? ['express' => APP_EXTRA_NO_EXPRESS] : 0),
        ];
    }
    appSend(['count' => count($out), 'systems' => $out,
             'extra' => ['default' => 0, 'bits' => [
                 ['bit' => APP_EXTRA_NO_EXPRESS, 'name' => '不搭臺鐵對號', 'desc' => '排除臺鐵莒光、自強以上（快車），只搭區間車、區間快'],
                 ['bit' => APP_EXTRA_NO_HSR, 'name' => '不搭高鐵', 'desc' => '排除高鐵'],
             ], 'note' => 'extra 是排除位元：0＝都可搭（預設），1＝不搭臺鐵對號，2＝不搭高鐵，3＝兩者都不搭。找路與時刻組合的 app_ API 依此排除列車；捷運、輕軌與臺鐵區間車一律可搭。單站、路線、列車時刻表不受 extra 影響']]);
}

function appPublicInfoCalendar()
{
    $db = appDb();
    $g = transferLoadGroups();
    $d = ['db' => $db];
    $accel = transferAccelLearned($d);
    $missing = finderDbMissing();
    appSend([
        'ServiceDay' => ['Start' => '04:00', 'End' => '27:59',
                         'Note' => '時刻表依營運日：04:00 起到隔天 03:59 止，午夜後寫 24 時以後（00:30 → 24:30）；火車的營運日是列車從起點發車的那一天。使用者選 00:00～03:59 時，前端把星期（日期）往前一天、時間加 24 小時'],
        'Query'      => ['week' => '0～6（0＝星期日）', 'date' => 'YYYY-MM-DD，換算成星期',
                         'Note' => '目前用定期時刻表（依星期），還沒有接指定日期的時刻表（國定假日、連假加班車）'],
        'Data'       => ['DataUpdatedAt' => appDataUpdatedAt($db), 'TransferGroupsGeneratedAt' => $g['GeneratedAt'] ?? null,
                         'AccelGeneratedAt' => $accel['GeneratedAt'] ?? null, 'AccelFresh' => $accel['Fresh'] ?? false,
                         'DownloadComplete' => !$missing, 'DownloadMissing' => count($missing)],
    ]);
}

function appPublicInfoRoute()
{
    $db = appDb();
    $m = appModel();
    $code = appParamRequired('route', '路由代碼，例如 TRA-0950.TRA-WL.local.TRA-1000.TRA-WL.express.TRA-3300');
    $extra = appParam('extra') !== null ? appParamExtra() : null;
    try {
        $r = appRouteValidate(appRouteParse($code), $db, $m);
    } catch (InvalidArgumentException $e) {
        appFail(40023, ['detail' => $e->getMessage()], ['route' => $code]);
    }
    $out = appRouteDescribe($r, $m);
    $out['Input'] = $code;
    $out['Normalized'] = $out['route'] !== $code;
    if ($extra !== null) {
        $c = appRouteExtraConflicts($r, $extra);
        $out['Extra'] = appExtraInfo($extra) + ['Allowed' => !$c, 'Conflicts' => $c];
    }
    appSend($out);
}

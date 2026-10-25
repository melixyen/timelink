<?php
/**
 * finder 資料庫：把 download/tdx/ 的 JSON 轉成 SQLite（sqlite/finder.sqlite，經 libs/sqlite.php 的 SqliteDao 存取）。
 *
 * /txapi/finder 只從這個資料庫查詢，不讀 JSON、不在記憶體裡建大型索引。
 *
 *   /txapi/admin_download_to_sqlite/build   重建（後臺寫入權限）：先檢查下載資料是否完整，完整才整份重建（取代舊檔）
 *   /txapi/admin_download_to_sqlite[/status] 狀態（讀取權限）
 *
 * 其他觸發時機：
 *   - /txapi/finder 每個 func 執行前檢查它用到的資料表是否已是最新，不是就先重建再查詢
 *   - /txapi/admin_download/sync 全部完成時，資料有變就重建
 *
 * 每個資料表記錄「由哪些 download 檔案建成」（_sources：路徑、mtime、大小、存檔時間、時刻表適用期間），
 * 來源檔的 mtime 或大小改變就視為過期。下載資料不完整時一律不寫入，回報缺少的 path。
 */

const FINDERDB_NAME = 'finder';
const FINDERDB_SCHEMA_VERSION = 11;   // 資料表結構或轉換規則改變時 +1，強制重建

const FINDERDB_METRO_SYSTEMS = ['TRTC', 'NTMC', 'NTDLRT', 'NTALRT', 'KRTC', 'KLRT', 'TYMC', 'TMRT'];
const FINDERDB_WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];   // bit 0＝星期日

/** 營運單位 → 名稱與旗下系統（車站清單 rail_stations 的第一層） */
const FINDERDB_OPERATORS = [
    'TRA'  => ['name' => '臺鐵',     'systems' => ['TRA']],
    'THSR' => ['name' => '高鐵',     'systems' => ['THSR']],
    'TRTC' => ['name' => '臺北捷運', 'systems' => ['TRTC']],
    'NTMC' => ['name' => '新北捷運', 'systems' => ['NTMC', 'NTDLRT', 'NTALRT']],
    'TYMC' => ['name' => '桃園捷運', 'systems' => ['TYMC']],
    'TMRT' => ['name' => '臺中捷運', 'systems' => ['TMRT']],
    'KRTC' => ['name' => '高雄捷運', 'systems' => ['KRTC', 'KLRT']],
];

/**
 * TDX 沒有提供 LineColor 的路線，代表色取自 railtime 專案（melixyen.github.io/railtime）的路線選擇器與路網圖
 * （ttlib/data.js、canvasTaiwanRailwayMap.js），line.color_source＝railtime。鍵為「系統-路線」。
 */
const FINDERDB_LINE_COLORS = [
    'THSR-HSRL' => '#ef5210',   // 台灣高鐵
    'KRTC-R'    => '#f7005c',   // 高捷紅線
    'KRTC-O'    => '#ff9300',   // 高捷橘線
    'KLRT-C'    => '#61c851',   // 高捷環狀輕軌
];

/**
 * 臺鐵路線的顯示區段與代表色（依 railtime 路線選擇器的分段）。
 * 每段：[起站名, 迄站名, 名稱, 簡稱, 顏色]；起迄站為 null 表示整條路線。區段含兩端車站，交界站會出現在前後兩段。
 * 成追線 railtime 沒有，暫用海線色。
 */
const FINDERDB_TRA_SECTIONS = [
    'WL'   => [['基隆', '竹南', '西部幹線(基隆-竹南)', '基竹', '#000050'],
               ['竹南', '彰化', '山線(竹南-彰化)', '山線', '#104020'],
               ['彰化', '嘉義', '西部幹線(彰化-嘉義)', '彰嘉', '#707010'],
               ['嘉義', '高雄', '西部幹線(嘉義-高雄)', '嘉高', '#302040'],
               ['高雄', '屏東', '屏東線(高雄-屏東)', '高屏', '#7D3810']],
    'WL-C' => [[null, null, '海線(竹南-彰化)', '海線', '#2050C0']],
    'SL'   => [['屏東', '枋寮', '屏東線(屏東-枋寮)', '屏枋', '#7D3810'],
               ['枋寮', '臺東', '南迴線(枋寮-臺東)', '南迴', '#3040A0']],
    'EL'   => [['八堵', '蘇澳新', '宜蘭線(八堵-蘇澳新)', '宜蘭', '#500000'],
               ['蘇澳新', '花蓮', '北迴線(蘇澳新-花蓮)', '北迴', '#004060'],
               ['花蓮', '臺東', '台東線(花蓮-臺東)', '台東', '#605040']],
    'SU'   => [[null, null, '宜蘭線(蘇澳新-蘇澳)', '蘇澳', '#500000']],
    'PX'   => [[null, null, '平溪線', '平溪', '#003030']],
    'SA'   => [[null, null, '深澳線', '深澳', '#003030']],
    'NW'   => [[null, null, '內灣線', '內灣', '#403090']],
    'LJ'   => [[null, null, '六家線', '六家', '#403090']],
    'CZ'   => [[null, null, '成追線', '成追', '#2050C0']],
    'JJ'   => [[null, null, '集集線', '集集', '#22A050']],
    'SH'   => [[null, null, '沙崙線', '沙崙', '#124060']],
];

/** 有下載首末班車（FirstLastTimetable）與班距（Frequency）的系統：站別時刻表的最後一層（班距模擬）只能用在這些系統 */
const FINDERDB_SIM_SYSTEMS = ['TRTC', 'TMRT'];

/** TDX 有提供 S2STravelTime 的捷運系統（淡海、安坑輕軌沒有） */
const FINDERDB_S2S_SYSTEMS = ['TRTC', 'NTMC', 'KRTC', 'KLRT', 'TYMC', 'TMRT'];

/**
 * 資料來源的優先順序（數字小的優先）。每一站、每一段都由上往下找第一個有資料的方法，
 * 分三層：real 真實資料 → mixed 真實資料加推算 → derived 完全推算。新增車站資料不足時也依這個順序自動補。
 *
 * 站間行駛時間（metro_travel.source、metro_travel_od.source）：
 *   tdx                 1 real    TDX S2STravelTime 同方向的值
 *   reversed            2 mixed   S2STravelTime 反方向同一區間倒用
 *   timetable           3 mixed   同方向站別時刻表的發車時間差（眾數；或串車起訖矩陣）
 *   timetable-reversed  4 mixed   反方向站別時刻表的發車時間差（終點站沒有往終點方向的發車時刻）
 * 站別時刻表（metro_group.source）：
 *   timetable           1 real    TDX StationTimeTable
 *   shifted             2 mixed   同路線同方向最近一個有真實時刻表的車站，加減站間行駛時間平移
 *   simulated           3 derived 首末班車起依時段平均班距排出（FirstLastTimetable + Frequency）
 */
const FINDERDB_TRAVEL_RANK = ['tdx' => 1, 'reversed' => 2, 'timetable' => 3, 'timetable-reversed' => 4];
const FINDERDB_TIMETABLE_RANK = ['timetable' => 1, 'shifted' => 2, 'simulated' => 3];
const FINDERDB_TIERS = ['real', 'mixed', 'derived'];

/** 臺鐵路線的排列順序（幹線在前，由北往南、再到東部；支線大致由北往南）；不在清單內的排在最後 */
const FINDERDB_TRA_LINE_ORDER = ['WL', 'WL-C', 'SL', 'EL', 'SU', 'PX', 'SA', 'NW', 'LJ', 'CZ', 'JJ', 'SH'];

class FinderDbIncomplete extends RuntimeException
{
    public $missing = [];
}

// ================================================================
// 資料表與來源

/** 資料表 → 說明與來源 download 路徑（建置依據） */
function finderDbTables()
{
    $metroStation = [];
    $metroTime = [];
    $metroLine = [];
    $metroRoute = [];
    foreach (FINDERDB_METRO_SYSTEMS as $s) {
        $metroLine[] = '/v2/Rail/Metro/Line/' . $s;
        $metroRoute[] = '/v2/Rail/Metro/StationOfRoute/' . $s;
        $metroStation[] = '/v2/Rail/Metro/Station/' . $s;
        $metroStation[] = '/v2/Rail/Metro/StationOfRoute/' . $s;
        if (finderDbHasStationTimetable($s)) {
            $metroTime[] = '/v2/Rail/Metro/StationTimeTable/' . $s;
            $metroTime[] = '/v2/Rail/Metro/StationOfRoute/' . $s;
        }
        if (in_array($s, FINDERDB_SIM_SYSTEMS, true)) {
            $metroTime[] = '/v2/Rail/Metro/FirstLastTimetable/' . $s;
            $metroTime[] = '/v2/Rail/Metro/Frequency/' . $s;
            $metroTime[] = '/v2/Rail/Metro/StationOfRoute/' . $s;
        }
    }
    $tra = ['/v3/Rail/TRA/GeneralTrainTimetable', '/v3/Rail/TRA/StationOfLine'];
    $s2sSources = array_merge(
        array_map(function ($s) { return '/v2/Rail/Metro/S2STravelTime/' . $s; }, FINDERDB_S2S_SYSTEMS),
        array_map(function ($s) { return '/v2/Rail/Metro/StationOfRoute/' . $s; }, FINDERDB_S2S_SYSTEMS));
    foreach (FINDERDB_METRO_SYSTEMS as $s) {   // 站別時刻表：補缺站的段落、沒有 S2STravelTime 的路線串車推算起訖矩陣
        if (finderDbHasStationTimetable($s)) {
            $s2sSources[] = '/v2/Rail/Metro/StationTimeTable/' . $s;
            $s2sSources[] = '/v2/Rail/Metro/StationOfRoute/' . $s;
        }
    }
    $s2sSources = array_values(array_unique($s2sSources));
    $thsr = ['/v2/Rail/THSR/GeneralTimetable', '/v2/Rail/S2SDistance/THSR'];
    return [
        'station' => [
            'desc'    => '各系統車站：StationUID、名稱、所屬路線',
            'sources' => array_merge(['/v2/Rail/TRA/Station', '/v3/Rail/TRA/StationOfLine', '/v2/Rail/THSR/Station', '/v2/Rail/S2SDistance/THSR'], $metroStation),
        ],
        'train' => [
            'desc'    => '臺鐵、高鐵列車（統一格式、行駛星期、行經路線）',
            'sources' => array_merge($tra, $thsr),
        ],
        'train_stop' => [
            'desc'    => '臺鐵、高鐵列車的停靠站與到離站時刻',
            'sources' => array_merge($tra, $thsr),
        ],
        'metro_group' => [
            'desc'    => '捷運、輕軌站別時刻的分組（車站×營運路線×終點×營運日），含方向、起點、站序、來源（真實／平移／班距模擬）',
            'sources' => array_values(array_unique(array_merge($metroTime, $s2sSources))),
        ],
        'metro_dep' => [
            'desc'    => '捷運、輕軌各組的發車時刻',
            'sources' => array_values(array_unique(array_merge($metroTime, $s2sSources))),
        ],
        'line' => [
            'desc'    => '各系統路線：名稱、代表色、排序、是否支線',
            'sources' => array_merge(['/v3/Rail/TRA/Line', '/v2/Rail/S2SDistance/THSR'], $metroLine),
        ],
        'line_station' => [
            'desc'    => '各路線依站序排列的車站（車站清單、快查選單用）',
            'sources' => array_merge(['/v3/Rail/TRA/StationOfLine', '/v2/Rail/THSR/Station', '/v2/Rail/S2SDistance/THSR'], $metroLine, $metroRoute),
        ],
        'line_section' => [
            'desc'    => '路線的顯示區段與代表色（臺鐵依 railtime 分段，其他系統整條一段）',
            'sources' => array_merge(['/v3/Rail/TRA/Line', '/v3/Rail/TRA/StationOfLine', '/v2/Rail/THSR/Station', '/v2/Rail/S2SDistance/THSR'], $metroLine, $metroRoute),
        ],
        'metro_travel' => [
            'desc'    => '捷運站間行駛時間（路線站距表）：每條營運路線、方向、車種一份，從起站累加到迄站',
            'sources' => $s2sSources,
        ],
        'metro_travel_od' => [
            'desc'    => '捷運站間行駛時間（起訖矩陣）：路線站距表無法完全重現 TDX 原始矩陣時，原樣存任兩站秒數',
            'sources' => $s2sSources,
        ],
        'metro_travel_set' => [
            'desc'    => '各系統、路線、車種的站間行駛時間儲存型態（route 路線站距表／matrix 起訖矩陣）與比對結果',
            'sources' => $s2sSources,
        ],
        'pattern' => [
            'desc'    => '機場捷運停站模式名稱（普通車、直達車…）',
            'sources' => ['/v2/Rail/Metro/StoppingPattern/TYMC'],
        ],
    ];
}

/**
 * 資料庫瀏覽（/txapi/sqlite）用的說明：表格說明、欄位說明、關聯。
 *   relations：欄位 → [目標表, 目標欄位]；目標欄位為 null 表示欄位值本身是資料表名稱
 *   json：JSON 欄位內的鍵 → [目標表, 目標欄位]
 */
function finderDbBrowseDocs()
{
    $defs = finderDbTables();
    $t = function ($name) use ($defs) {
        return $defs[$name]['desc'] ?? '';
    };
    $week = '行駛星期位元（bit 0＝星期日 … bit 6＝星期六；127＝每天）';
    return [
        'station' => [
            'desc'    => $t('station'),
            'columns' => [
                'uid'        => 'StationUID（系統-站碼，例如 TRA-1000、TRTC-BL12）',
                'system'     => '系統代碼（TRA、THSR、TRTC、NTMC…）',
                'station_id' => '站碼',
                'name'       => '站名（JSON：Zh_tw、En）',
                'line_ids'   => '所屬路線代碼（JSON 陣列）',
            ],
        ],
        'train' => [
            'desc'    => $t('train'),
            'columns' => [
                'train_key'       => '列車 key（系統-車次，例如 TRA-123、THSR-0108）',
                'system'          => '系統代碼（TRA、THSR）',
                'train_no'        => '車次',
                'train_no_norm'   => '車次（去除前導 0，查詢用）',
                'direction'       => '行駛方向（臺鐵 0 順行 1 逆行；高鐵 0 南下 1 北上）',
                'train_type_id'   => '車種代碼 TrainTypeID',
                'train_type_code' => '車種簡碼 TrainTypeCode',
                'line_ids'        => '行經路線（JSON 陣列）',
                'weekmask'        => $week,
                'start_min'       => '始發時間（自 00:00 起的分鐘）',
                'info'            => '統一格式的列車資訊（JSON）',
                'service_day'     => 'TDX 原始的營運日（JSON）',
            ],
            'json' => ['info' => ['StartingStationUID' => ['station', 'uid'], 'EndingStationUID' => ['station', 'uid']]],
        ],
        'train_stop' => [
            'desc'    => $t('train_stop'),
            'columns' => [
                'train_key'   => '列車 key',
                'seq'         => '停靠順序（從 1 起）',
                'station_uid' => '停靠站 StationUID',
                'arr'         => '到站時間 HH:MM（00～23 時，每一站都有值）',
                'dep'         => '離站時間 HH:MM（00～23 時，每一站都有值）',
                'arr_src'     => '到站時間來源：timetable＝TDX；origin＝起站沒有到站時間（高鐵），以離站時間填',
                'dep_src'     => '離站時間來源：timetable＝TDX；terminal＝終點站沒有離站時間（高鐵），以到站時間填',
                'sort_min'    => '排序用分鐘（跨午夜 +1440）',
            ],
            'relations' => ['train_key' => ['train', 'train_key'], 'station_uid' => ['station', 'uid']],
        ],
        'metro_group' => [
            'desc'    => $t('metro_group'),
            'columns' => [
                'group_id'    => '分組編號',
                'system'      => '系統代碼',
                'station_uid' => '車站 StationUID',
                'direction'   => '行駛方向（0 去程 1 回程；不明為空字串）',
                'weekmask'    => '營運星期位元（bit 0＝星期日 … bit 6＝星期六）',
                'stop_seq'    => '本站在營運路線中的站序',
                'simulated'   => '0＝TDX 站別時刻表（真實）；1＝推算（source 為 shifted 或 simulated）。比對抵達時刻只用 0',
                'source'      => '來源（優先順序由上而下）：timetable 真實站別時刻表 → shifted 由同路線同方向最近的真實時刻表車站加減站間時間平移 → simulated 首末班車起依平均班距排出',
                'info'        => '統一格式的列車資訊（JSON，含起訖站、路線、營運路線）',
            ],
            'relations' => ['station_uid' => ['station', 'uid']],
            'json' => ['info' => ['StartingStationUID' => ['station', 'uid'], 'EndingStationUID' => ['station', 'uid']]],
        ],
        'metro_dep' => [
            'desc'    => $t('metro_dep'),
            'columns' => [
                'group_id'        => '分組編號',
                'arr'             => '到站時間 HH:MM（00～23 時，每一筆都有值）',
                'dep'             => '離站時間 HH:MM（00～23 時）',
                'arr_src'         => '到站時間來源：timetable＝TDX（機捷、新北捷運有獨立的到站時間；高雄捷運、輕軌與離站相同）；estimate＝離站 − 本站停站秒數（臺北捷運、平移與模擬時刻只有離站時間）',
                'dep_src'         => '離站時間來源：timetable＝該組時刻表（真實、平移或模擬見 metro_group.source）',
                'sort_min'        => '排序用分鐘（04:00 前 +1440）',
                'train_type_code' => '車種（機場捷運：普通、直達）',
                'pattern_id'      => '停站模式代碼（機場捷運）',
            ],
            'relations' => ['group_id' => ['metro_group', 'group_id'], 'pattern_id' => ['pattern', 'pattern_id']],
        ],
        'line' => [
            'desc'    => $t('line'),
            'columns' => [
                'system'       => '系統代碼',
                'line_id'      => '路線代碼',
                'name'         => '路線名稱（JSON：Zh_tw、En）',
                'color'        => '代表色 #rrggbb',
                'color_source' => '代表色來源：tdx＝TDX 的 LineColor；railtime＝TDX 沒有，取自 railtime 專案；none＝都沒有（灰色）',
                'sort'         => '排序（臺鐵幹線在前、支線在後；捷運依 TDX 路線順序）',
                'is_branch'    => '是否支線（1／0）',
            ],
        ],
        'line_station' => [
            'desc'    => $t('line_station'),
            'columns' => [
                'system'      => '系統代碼',
                'line_id'     => '路線代碼',
                'seq'         => '站序（從 1 起）',
                'station_uid' => '車站 StationUID',
            ],
            'relations' => ['line_id' => ['line', 'line_id', ['system' => 'system']], 'station_uid' => ['station', 'uid']],
        ],
        'line_section' => [
            'desc'    => $t('line_section'),
            'columns' => [
                'system'       => '系統代碼',
                'line_id'      => '路線代碼',
                'seq'          => '區段順序',
                'name'         => '區段名稱',
                'short'        => '簡稱（選單色塊用）',
                'color'        => '代表色 #rrggbb',
                'color_source' => '代表色來源（tdx、railtime、none）',
                'from_seq'     => '起站在 line_station 的站序',
                'to_seq'       => '迄站在 line_station 的站序（含）',
            ],
            'relations' => ['line_id' => ['line', 'line_id', ['system' => 'system']]],
        ],
        'metro_travel' => [
            'desc'    => $t('metro_travel'),
            'columns' => [
                'system'      => '系統代碼',
                'line_id'     => '路線代碼',
                'route_id'    => '營運路線（取自 StationOfRoute）',
                'direction'   => '方向（StationOfRoute 的 Direction）',
                'train_type'  => '車種（TDX TrainType：0 不分、1 普通車、2 直達車）',
                'seq'         => '站序（從 1 起）',
                'station_uid' => '車站 StationUID',
                'run'         => '從前一站行駛到本站的秒數',
                'dwell'       => '在本站的停站秒數（起訖站為 0）',
                'arr'         => '從起站離站起算，抵達本站的秒數',
                'dep'         => '從起站離站起算，從本站離站的秒數；兩站間時間＝迄站 arr − 起站 dep',
                'source'      => '本站（與前一站之間）的資料來源，逐段依優先順序取第一個有資料的：1 tdx＝TDX（real）；2 reversed＝這個方向沒有，用反方向的區間時間；'
                               . '3 timetable＝S2STravelTime 沒有這段（例如新站 R01），由同方向站別時刻表離站時間差推算；4 timetable-reversed＝同上但用反方向（2～4 為 mixed）',
            ],
            'relations' => ['station_uid' => ['station', 'uid']],
        ],
        'metro_travel_od' => [
            'desc'    => $t('metro_travel_od'),
            'columns' => [
                'system'     => '系統代碼',
                'line_id'    => '路線代碼',
                'train_type' => '車種（0 不分、1 普通車、2 直達車）',
                'from_uid'   => '起站 StationUID',
                'to_uid'     => '迄站 StationUID',
                'seconds'    => '秒數（source=tdx：TDX 原始值，已含中間停站；source=timetable：同一班車在兩站的時刻相減的眾數）',
                'source'     => 'tdx＝TDX S2STravelTime 原始矩陣（real）；timetable＝由站別時刻表串車推算（淡海、安坑輕軌，或矩陣缺少的車站；mixed）；timetable-reversed＝到終點站的區間，最後一段以反方向時間推估（mixed）',
                'samples'    => 'timetable：參與計算的班次數',
                'agree'      => 'timetable：與眾數相同的班次數',
            ],
            'relations' => ['from_uid' => ['station', 'uid'], 'to_uid' => ['station', 'uid']],
        ],
        'metro_travel_set' => [
            'desc'    => $t('metro_travel_set'),
            'columns' => [
                'system'     => '系統代碼',
                'line_id'    => '路線代碼',
                'train_type' => '車種（0 不分、1 普通車、2 直達車）',
                'raw_shape'  => '資料來源形狀：chain 相鄰站鏈、matrix 起訖矩陣（TDX S2STravelTime）；timetable 由站別時刻表串車推算',
                'storage'    => '儲存型態：route 路線站距表（metro_travel）、matrix 起訖矩陣（metro_travel_od）',
                'raw_pairs'  => 'TDX 原始筆數；timetable 為串出的班次數',
                'checked'    => '起訖矩陣：比對的原始值筆數（去除重複）；timetable 為起訖組數',
                'mismatch'   => '起訖矩陣：路線站距表算不出來的筆數（大於 0 就存 matrix）；timetable 為班次間時間不一致的起訖組數',
            ],
        ],
        'pattern' => [
            'desc'    => $t('pattern'),
            'columns' => [
                'system'     => '系統代碼',
                'pattern_id' => '停站模式代碼',
                'name'       => '名稱（JSON：Zh_tw、En）',
            ],
        ],
        '_sources' => [
            'desc'    => '各資料表的建置依據：來源 download 檔案的 mtime、大小、存檔時間、時刻表適用期間',
            'columns' => [
                'table_name' => '資料表',
                'path'       => '來源 download 路徑',
                'mtime'      => '建置時來源檔的修改時間（Unix 秒）',
                'size'       => '建置時來源檔的大小（位元組）',
                'saved_at'   => '來源檔的存檔時間',
                'validity'   => '時刻表適用期間（JSON）',
            ],
            'relations' => ['table_name' => ['', null]],
        ],
        '_func_tables' => [
            'desc'    => '/txapi/finder 各 func 查詢的資料表',
            'columns' => ['func' => 'finder 的 func 名稱', 'table_name' => '資料表'],
            'relations' => ['table_name' => ['', null]],
        ],
        '_meta' => [
            'desc'    => '資料庫資訊（結構版本、建置時間）',
            'columns' => ['key' => '名稱', 'value' => '值（JSON）'],
        ],
    ];
}

/** 下載清單內有該系統的站別時刻表才建（臺中捷運沒有） */
function finderDbHasStationTimetable($sys)
{
    return function_exists('downloadFind') && downloadFind('tdx', '/v2/Rail/Metro/StationTimeTable/' . $sys) !== null;
}

function finderDbFile($path)
{
    return DOWNLOAD_DIR . '/tdx' . $path . '.json';
}

/** download 資料是否完整：下載清單的每一項都要有檔案且看起來是 JSON。回傳缺少／損壞的清單 */
function finderDbMissing()
{
    $missing = [];
    foreach (downloadCatalog('tdx') as $path => $cfg) {
        $f = finderDbFile($path);
        if (!is_file($f)) {
            $missing[] = ['path' => $path, 'reason' => 'not downloaded'];
        } elseif (!downloadLooksLikeJson($f, filesize($f))) {
            $missing[] = ['path' => $path, 'reason' => 'invalid JSON'];
        }
    }
    return $missing;
}

/** 目前來源檔的狀態（用來比對是否過期） */
function finderDbSourceStat($path)
{
    $f = finderDbFile($path);
    clearstatcache(true, $f);
    return is_file($f) ? ['mtime' => filemtime($f), 'size' => filesize($f)] : ['mtime' => 0, 'size' => 0];
}

// ================================================================
// 狀態與確保最新

/**
 * 資料庫狀態。$tables 為 null 時檢查全部資料表。
 * 回傳 exists、fresh、schema、built_at、size、stale（過期的資料表 => 原因）、counts（各表筆數）
 */
function finderDbStatus(array $tables = null, $withCounts = false)
{
    $defs = finderDbTables();
    $tables = $tables ?? array_keys($defs);
    $out = ['db' => FINDERDB_NAME, 'exists' => SqliteDao::exists(FINDERDB_NAME), 'fresh' => false, 'schema' => null,
            'built_at' => null, 'size' => null, 'stale' => []];
    if (!$out['exists']) {
        foreach ($tables as $t) {
            $out['stale'][$t] = 'database not built';
        }
        return $out;
    }
    try {
        $db = SqliteDao::open(FINDERDB_NAME, ['readonly' => true]);
        $out['schema'] = $db->meta('schema_version');
        $out['built_at'] = $db->meta('built_at');
        $out['size'] = filesize(SQLITE_DIR . '/' . FINDERDB_NAME . '.sqlite');
        $recorded = [];
        if ($db->tableExists('_sources')) {
            foreach ($db->all('SELECT table_name, path, mtime, size FROM _sources') as $r) {
                $recorded[$r['table_name']][$r['path']] = $r;
            }
        }
        foreach ($tables as $t) {
            if ((int) $out['schema'] !== FINDERDB_SCHEMA_VERSION) {
                $out['stale'][$t] = 'schema version changed';
                continue;
            }
            if (!isset($recorded[$t])) {
                $out['stale'][$t] = 'table not built';
                continue;
            }
            foreach ($defs[$t]['sources'] as $p) {
                $cur = finderDbSourceStat($p);
                $rec = $recorded[$t][$p] ?? null;
                if (!$rec || (int) $rec['mtime'] !== $cur['mtime'] || (int) $rec['size'] !== $cur['size']) {
                    $out['stale'][$t] = 'source changed: ' . $p;
                    break;
                }
            }
        }
        if ($withCounts) {
            $out['counts'] = [];
            foreach (array_keys($defs) as $t) {
                $out['counts'][$t] = $db->tableExists($t) ? (int) $db->value('SELECT COUNT(*) FROM "' . $t . '"') : null;
            }
        }
        $db->close();
    } catch (SqliteDaoException $e) {
        foreach ($tables as $t) {
            $out['stale'][$t] = 'cannot read database: ' . $e->getMessage();
        }
    }
    $out['fresh'] = !$out['stale'];
    return $out;
}

/**
 * 確保 $tables 是最新：過期就整份重建（資料不完整時丟 FinderDbIncomplete）。
 * 回傳 ['rebuilt' => bool, 'report' => 重建報告|null]
 */
function finderDbEnsure(array $tables = null)
{
    if (finderDbStatus($tables)['fresh']) {
        return ['rebuilt' => false, 'report' => null];
    }
    return SqliteDao::lock(FINDERDB_NAME, function () use ($tables) {
        // 等鎖期間可能已由其他請求重建完成
        if (finderDbStatus($tables)['fresh']) {
            return ['rebuilt' => false, 'report' => null];
        }
        return ['rebuilt' => true, 'report' => finderDbBuild()];
    });
}

// ================================================================
// 重建

/** 整份重建。資料不完整時丟 FinderDbIncomplete，不動現有資料庫。回傳報告 */
function finderDbBuild()
{
    $missing = finderDbMissing();
    if ($missing) {
        $e = new FinderDbIncomplete('Download data is incomplete; database not written');
        $e->missing = $missing;
        throw $e;
    }
    @set_time_limit(TIME_LIMIT_FINDERDB_BUILD);
    $t0 = microtime(true);
    $defs = finderDbTables();
    // 開始建置前先記下來源狀態：建置期間若有檔案被更新，下次檢查就會再重建
    $stats = [];
    foreach ($defs as $t => $d) {
        foreach ($d['sources'] as $p) {
            $stats[$p] = $stats[$p] ?? finderDbSourceStat($p);
        }
    }
    $counts = SqliteDao::rebuild(FINDERDB_NAME, function (SqliteDao $db) use ($defs, $stats) {
        finderDbCreateSchema($db);
        $db->transaction(function (SqliteDao $db) {
            finderDbBuildStations($db);
            finderDbBuildLines($db);
            finderDbBuildTRA($db);
            finderDbBuildTHSR($db);
            finderDbBuildMetro($db);            // 站別時刻表：真實
            finderDbBuildMetroTravel($db);      // 站間行駛時間：S2STravelTime，缺的段落依優先順序補
            finderDbBuildTimetableMatrix($db);  // 站間行駛時間：沒有 S2STravelTime 的路線由站別時刻表串車
            finderDbBuildMetroFill($db);        // 站別時刻表：缺站先平移（用上面的站間時間），最後才班距模擬
            finderDbNormalizeTimes($db);        // 統一格式：每一站都有到站與離站時間（HH:MM，00～23 時），並記錄來源
        });
        $db->transaction(function (SqliteDao $db) use ($defs, $stats) {
            foreach ($defs as $t => $d) {
                foreach ($d['sources'] as $p) {
                    $f = finderDbFile($p);
                    $validity = function_exists('downloadValidity') ? downloadValidity($f) : null;
                    $db->run('INSERT INTO _sources (table_name, path, mtime, size, saved_at, validity) VALUES (?, ?, ?, ?, ?, ?)', [
                        $t, $p, $stats[$p]['mtime'], $stats[$p]['size'], downloadFmtTime($stats[$p]['mtime']),
                        $validity ? json_encode($validity) : null,
                    ]);
                }
            }
            if (function_exists('finderFuncs')) {
                foreach (finderFuncs() as $func => $f) {
                    foreach ($f['tables'] as $t) {
                        $db->run('INSERT INTO _func_tables (func, table_name) VALUES (?, ?)', [$func, $t]);
                    }
                }
            }
            $db->meta('schema_version', FINDERDB_SCHEMA_VERSION);
            $db->meta('built_at', downloadFmtTime(time()));
        });
        $counts = [];
        foreach (array_keys($defs) as $t) {
            $counts[$t] = (int) $db->value('SELECT COUNT(*) FROM "' . $t . '"');
        }
        $db->exec('ANALYZE');
        return $counts;
    });
    clearstatcache();
    return [
        'filled'  => ['travel' => $GLOBALS['FINDERDB_TRAVEL_FILLED'] ?? [], 'timetable' => $GLOBALS['FINDERDB_TIMETABLE_FILLED'] ?? []],
        'db'      => FINDERDB_NAME,
        'counts'  => $counts,
        'size'    => filesize(SQLITE_DIR . '/' . FINDERDB_NAME . '.sqlite'),
        'seconds' => round(microtime(true) - $t0, 2),
        'peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
    ];
}

function finderDbCreateSchema(SqliteDao $db)
{
    $db->exec('
CREATE TABLE station (
  uid TEXT PRIMARY KEY, system TEXT NOT NULL, station_id TEXT NOT NULL,
  name TEXT NOT NULL, line_ids TEXT NOT NULL
);
CREATE INDEX station_sys ON station(system, station_id);

CREATE TABLE train (
  train_key TEXT PRIMARY KEY, system TEXT NOT NULL, train_no TEXT NOT NULL, train_no_norm TEXT NOT NULL,
  direction TEXT NOT NULL, train_type_id TEXT NOT NULL, train_type_code TEXT NOT NULL, line_ids TEXT NOT NULL,
  weekmask INTEGER NOT NULL, start_min INTEGER, info TEXT NOT NULL, service_day TEXT NOT NULL
);
CREATE INDEX train_no ON train(system, train_no_norm);

CREATE TABLE train_stop (
  train_key TEXT NOT NULL, seq INTEGER NOT NULL, station_uid TEXT NOT NULL,
  arr TEXT NOT NULL, dep TEXT NOT NULL, sort_min INTEGER, arr_src TEXT, dep_src TEXT,
  PRIMARY KEY (train_key, seq)
);
CREATE INDEX train_stop_station ON train_stop(station_uid, sort_min);

CREATE TABLE metro_group (
  group_id INTEGER PRIMARY KEY, system TEXT NOT NULL, station_uid TEXT NOT NULL,
  direction TEXT NOT NULL, weekmask INTEGER NOT NULL, stop_seq TEXT NOT NULL, info TEXT NOT NULL,
  simulated INTEGER NOT NULL DEFAULT 0, source TEXT NOT NULL
);
CREATE INDEX metro_group_station ON metro_group(station_uid);

CREATE TABLE metro_dep (
  group_id INTEGER NOT NULL, arr TEXT NOT NULL, dep TEXT NOT NULL, sort_min INTEGER,
  train_type_code TEXT NOT NULL, pattern_id TEXT NOT NULL, arr_src TEXT, dep_src TEXT
);
CREATE INDEX metro_dep_group ON metro_dep(group_id, sort_min);

CREATE TABLE line (
  system TEXT NOT NULL, line_id TEXT NOT NULL, name TEXT NOT NULL, color TEXT NOT NULL, color_source TEXT NOT NULL,
  sort INTEGER NOT NULL, is_branch INTEGER NOT NULL, PRIMARY KEY (system, line_id)
);

CREATE TABLE line_station (
  system TEXT NOT NULL, line_id TEXT NOT NULL, seq INTEGER NOT NULL, station_uid TEXT NOT NULL,
  PRIMARY KEY (system, line_id, seq)
);
CREATE INDEX line_station_uid ON line_station(station_uid);

CREATE TABLE line_section (
  system TEXT NOT NULL, line_id TEXT NOT NULL, seq INTEGER NOT NULL, name TEXT NOT NULL, short TEXT NOT NULL,
  color TEXT NOT NULL, color_source TEXT NOT NULL, from_seq INTEGER NOT NULL, to_seq INTEGER NOT NULL,
  PRIMARY KEY (system, line_id, seq)
);

CREATE TABLE metro_travel (
  system TEXT NOT NULL, line_id TEXT NOT NULL, route_id TEXT NOT NULL, direction INTEGER NOT NULL, train_type INTEGER NOT NULL,
  seq INTEGER NOT NULL, station_uid TEXT NOT NULL, run INTEGER NOT NULL, dwell INTEGER NOT NULL, arr INTEGER NOT NULL, dep INTEGER NOT NULL,
  source TEXT NOT NULL, PRIMARY KEY (system, line_id, route_id, direction, train_type, seq)
);
CREATE INDEX metro_travel_station ON metro_travel(station_uid);

CREATE TABLE metro_travel_od (
  system TEXT NOT NULL, line_id TEXT NOT NULL, train_type INTEGER NOT NULL, from_uid TEXT NOT NULL, to_uid TEXT NOT NULL,
  seconds INTEGER NOT NULL, source TEXT NOT NULL, samples INTEGER, agree INTEGER,
  PRIMARY KEY (system, line_id, train_type, from_uid, to_uid, seconds)
);
CREATE INDEX metro_travel_od_from ON metro_travel_od(from_uid, to_uid);

CREATE TABLE metro_travel_set (
  system TEXT NOT NULL, line_id TEXT NOT NULL, train_type INTEGER NOT NULL, raw_shape TEXT NOT NULL, storage TEXT NOT NULL,
  raw_pairs INTEGER NOT NULL, checked INTEGER NOT NULL, mismatch INTEGER NOT NULL, PRIMARY KEY (system, line_id, train_type)
);

CREATE TABLE pattern (
  system TEXT NOT NULL, pattern_id TEXT NOT NULL, name TEXT NOT NULL, PRIMARY KEY (system, pattern_id)
);

CREATE TABLE _sources (
  table_name TEXT NOT NULL, path TEXT NOT NULL, mtime INTEGER NOT NULL, size INTEGER NOT NULL,
  saved_at TEXT, validity TEXT, PRIMARY KEY (table_name, path)
);
CREATE TABLE _func_tables (func TEXT NOT NULL, table_name TEXT NOT NULL, PRIMARY KEY (func, table_name));
');
}

// ---- 共用 ----

function finderDbLoad($path)
{
    $j = json_decode((string) file_get_contents(finderDbFile($path)), true);
    if (!is_array($j)) {
        $e = new FinderDbIncomplete('Stored data is not valid JSON: ' . $path);
        $e->missing = [['path' => $path, 'reason' => 'invalid JSON']];
        throw $e;
    }
    return $j;
}

function finderDbName($n)
{
    if (!is_array($n)) {
        return ['Zh_tw' => is_string($n) ? $n : '', 'En' => ''];
    }
    return $n + ['Zh_tw' => '', 'En' => ''];
}

function finderDbJson($v)
{
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function finderDbUID($sys, $id)
{
    return $id === '' || $id === null ? '' : $sys . '-' . $id;
}

function finderDbStr($v)
{
    if ($v === null) {
        return '';
    }
    if (is_bool($v)) {
        return $v ? 1 : 0;
    }
    return $v;
}

function finderDbMinutes($t)
{
    if (!is_string($t) || !preg_match('/^(\d{1,2}):(\d{2})/', $t, $m)) {
        return null;
    }
    return (int) $m[1] * 60 + (int) $m[2];
}

function finderDbWeekmask($sd)
{
    $m = 0;
    foreach (FINDERDB_WEEKDAYS as $i => $w) {
        if (!empty($sd[$w])) {
            $m |= 1 << $i;
        }
    }
    return $m;
}

/**
 * 統一格式的列車欄位：以臺鐵 v3 GeneralTrainTimetable 的 TrainInfo 為基底；
 * System、TrainKey、StartingStationUID、EndingStationUID、LineIDs、Overnight、StoppingPatternID、ServiceTag 為另加欄位。
 * 所有欄位一律存在，沒有值為空字串。
 */
function finderDbTrainTemplate()
{
    return [
        'System' => '', 'TrainKey' => '', 'TrainNo' => '', 'Direction' => '',
        'TrainTypeID' => '', 'TrainTypeCode' => '', 'TrainTypeName' => ['Zh_tw' => '', 'En' => ''], 'TripHeadSign' => '',
        'StartingStationUID' => '', 'StartingStationID' => '', 'StartingStationName' => ['Zh_tw' => '', 'En' => ''],
        'EndingStationUID' => '', 'EndingStationID' => '', 'EndingStationName' => ['Zh_tw' => '', 'En' => ''],
        'LineIDs' => [], 'RouteID' => '', 'TripLine' => '', 'OverNightStationID' => '', 'Overnight' => '',
        'StoppingPatternID' => '', 'ServiceTag' => '',
        'WheelChairFlag' => '', 'PackageServiceFlag' => '', 'DiningFlag' => '', 'BreastFeedFlag' => '', 'BikeFlag' => '',
        'CarFlag' => '', 'DailyFlag' => '', 'ExtraTrainFlag' => '', 'Note' => '',
    ];
}

function finderDbServiceDay($sd)
{
    $out = ['Monday' => '', 'Tuesday' => '', 'Wednesday' => '', 'Thursday' => '', 'Friday' => '', 'Saturday' => '',
            'Sunday' => '', 'NationalHolidays' => '', 'DayBeforeHoliday' => '', 'DayAfterHoliday' => '', 'TyphoonDay' => ''];
    foreach ($out as $k => $v) {
        if (is_array($sd) && array_key_exists($k, $sd)) {
            $out[$k] = (int) (bool) $sd[$k];
        }
    }
    return $out;
}

// ---- 車站 ----

function finderDbBuildStations(SqliteDao $db)
{
    $rows = [];   // uid => [system, id, name, lines]
    $add = function ($sys, $id, $name, $line = null) use (&$rows) {
        $uid = finderDbUID($sys, $id);
        if (!isset($rows[$uid])) {
            $rows[$uid] = [$sys, $id, finderDbName($name), []];
        }
        if ($line !== null && $line !== '' && !in_array($line, $rows[$uid][3], true)) {
            $rows[$uid][3][] = $line;
        }
    };
    foreach (finderDbLoad('/v2/Rail/TRA/Station') as $s) {
        $add('TRA', $s['StationID'], $s['StationName'] ?? null);
    }
    foreach (finderDbLoad('/v3/Rail/TRA/StationOfLine')['StationOfLines'] ?? [] as $l) {
        foreach ($l['Stations'] as $s) {
            $add('TRA', $s['StationID'], $s['StationName'] ?? null, $l['LineID']);
        }
    }
    $thsrLine = '';
    foreach (finderDbLoad('/v2/Rail/S2SDistance/THSR') as $l) {
        $thsrLine = $l['LineID'] ?? '';
    }
    foreach (finderDbLoad('/v2/Rail/THSR/Station') as $s) {
        $add('THSR', $s['StationID'], $s['StationName'] ?? null, $thsrLine);
    }
    foreach (FINDERDB_METRO_SYSTEMS as $sys) {
        foreach (finderDbLoad('/v2/Rail/Metro/Station/' . $sys) as $s) {
            $add($sys, $s['StationID'], $s['StationName'] ?? null);
        }
        foreach (finderDbLoad('/v2/Rail/Metro/StationOfRoute/' . $sys) as $r) {
            foreach ($r['Stations'] as $s) {
                $add($sys, $s['StationID'], $s['StationName'] ?? null, $r['LineID']);
            }
        }
    }
    foreach ($rows as $uid => $r) {
        $db->run('INSERT INTO station (uid, system, station_id, name, line_ids) VALUES (?, ?, ?, ?, ?)',
                 [$uid, $r[0], $r[1], finderDbJson($r[2]), finderDbJson($r[3])]);
    }
}

// ---- 路線與站序 ----

/** 路線代表色：TDX 的 LineColor；沒有時用 railtime 的顏色（臺鐵取第一個區段的顏色）；都沒有為灰色 */
function finderDbLineColor($sys, $lineID, $tdx)
{
    $tdx = trim((string) $tdx);
    if (preg_match('/^#?[0-9A-Fa-f]{6}$/', $tdx)) {
        return ['#' . strtolower(ltrim($tdx, '#')), 'tdx'];
    }
    $c = FINDERDB_LINE_COLORS[$sys . '-' . $lineID] ?? ($sys === 'TRA' ? (FINDERDB_TRA_SECTIONS[$lineID][0][4] ?? null) : null);
    return $c ? [strtolower($c), 'railtime'] : ['#6b7385', 'none'];
}

/**
 * line、line_station：臺鐵依 StationOfLine 的 Sequence；高鐵依 StationID（南港→左營）；
 * 捷運取該路線最長的營運路線為主幹，其他營運路線（支線、區間）多出的車站依序接在後面，環狀線去掉重複的起點
 */
function finderDbBuildLines(SqliteDao $db)
{
    $sort = 0;
    $addLine = function ($sys, $id, $name, $color, $branch, array $uids, array $names = []) use ($db, &$sort) {
        list($c, $src) = finderDbLineColor($sys, $id, $color);
        $lineName = finderDbName($name);
        $db->run('INSERT INTO line (system, line_id, name, color, color_source, sort, is_branch) VALUES (?, ?, ?, ?, ?, ?, ?)',
                 [$sys, $id, finderDbJson($lineName), $c, $src, ++$sort, $branch ? 1 : 0]);
        $uids = array_values(array_unique($uids));
        foreach ($uids as $i => $uid) {
            $db->run('INSERT INTO line_station (system, line_id, seq, station_uid) VALUES (?, ?, ?, ?)', [$sys, $id, $i + 1, $uid]);
        }
        // 顯示區段：臺鐵依 FINDERDB_TRA_SECTIONS 以站名切段（找不到交界站時整條一段），其他系統整條一段
        $n = count($uids);
        $sections = [];
        foreach ($sys === 'TRA' ? (FINDERDB_TRA_SECTIONS[$id] ?? []) : [] as $d) {
            $from = $d[0] === null ? 1 : array_search($d[0], $names, true);
            $to = $d[1] === null ? $n : array_search($d[1], $names, true);
            if ($from === false || $to === false) {
                $sections = [];
                break;
            }
            $from = $d[0] === null ? 1 : $from + 1;
            $to = $d[1] === null ? $n : $to + 1;
            $sections[] = [$d[2], $d[3], $d[4], 'railtime', min($from, $to), max($from, $to)];
        }
        if (!$sections) {
            $sections[] = [$lineName['Zh_tw'] ?: $id, $id, $c, $src, 1, $n];
        }
        foreach ($sections as $k => $x) {
            $db->run('INSERT INTO line_section (system, line_id, seq, name, short, color, color_source, from_seq, to_seq)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$sys, $id, $k + 1, $x[0], $x[1], strtolower($x[2]), $x[3], $x[4], $x[5]]);
        }
    };

    // 臺鐵：依 FINDERDB_TRA_LINE_ORDER，幹線在前、支線在後
    $traStations = [];
    $traNames = [];
    foreach (finderDbLoad('/v3/Rail/TRA/StationOfLine')['StationOfLines'] ?? [] as $l) {
        $st = $l['Stations'];
        usort($st, function ($a, $b) { return $a['Sequence'] <=> $b['Sequence']; });
        $traStations[$l['LineID']] = array_map(function ($s) { return finderDbUID('TRA', $s['StationID']); }, $st);
        $traNames[$l['LineID']] = array_map(function ($s) { return finderDbName($s['StationName'] ?? null)['Zh_tw']; }, $st);
    }
    $traLines = finderDbLoad('/v3/Rail/TRA/Line')['Lines'] ?? [];
    $order = array_flip(FINDERDB_TRA_LINE_ORDER);
    usort($traLines, function ($a, $b) use ($order) {
        return [(int) !empty($a['IsBranch']), $order[$a['LineID']] ?? 99] <=> [(int) !empty($b['IsBranch']), $order[$b['LineID']] ?? 99];
    });
    foreach ($traLines as $l) {
        if (!empty($traStations[$l['LineID']])) {
            $addLine('TRA', $l['LineID'], $l['LineName'] ?? null, '', !empty($l['IsBranch']), $traStations[$l['LineID']], $traNames[$l['LineID']]);
        }
    }

    // 高鐵：單一路線
    $thsr = finderDbLoad('/v2/Rail/S2SDistance/THSR')[0] ?? [];
    $ids = array_column(finderDbLoad('/v2/Rail/THSR/Station'), 'StationID');
    sort($ids, SORT_STRING);
    $addLine('THSR', $thsr['LineID'] ?? 'THSR', $thsr['LineName'] ?? null, '', false,
             array_map(function ($id) { return finderDbUID('THSR', $id); }, $ids));

    // 捷運、輕軌
    foreach (FINDERDB_METRO_SYSTEMS as $sys) {
        $routes = [];
        foreach (finderDbLoad('/v2/Rail/Metro/StationOfRoute/' . $sys) as $r) {
            $st = $r['Stations'];
            usort($st, function ($a, $b) { return $a['Sequence'] <=> $b['Sequence']; });
            $uids = array_map(function ($s) use ($sys) { return finderDbUID($sys, $s['StationID']); }, $st);
            // 去程在前；同方向時長的在前
            $routes[$r['LineID']][] = [(int) ($r['Direction'] ?? 0), -count($uids), $uids];
        }
        foreach (finderDbLoad('/v2/Rail/Metro/Line/' . $sys) as $l) {
            $rs = $routes[$l['LineID']] ?? [];
            if (!$rs) {
                continue;
            }
            usort($rs, function ($a, $b) { return [$a[0], $a[1]] <=> [$b[0], $b[1]]; });
            $list = [];
            foreach ($rs as $r) {
                foreach ($r[2] as $uid) {
                    if (!in_array($uid, $list, true)) {
                        $list[] = $uid;
                    }
                }
            }
            $addLine($sys, $l['LineID'], $l['LineName'] ?? null, $l['LineColor'] ?? '', !empty($l['IsBranch']), $list);
        }
    }
}

// ---- 臺鐵、高鐵 ----

/** 臺鐵路線圖（相鄰站、里程、路線） */
function finderDbTRAEdges()
{
    $edges = [];
    foreach (finderDbLoad('/v3/Rail/TRA/StationOfLine')['StationOfLines'] ?? [] as $l) {
        $prev = null;
        foreach ($l['Stations'] as $s) {
            if ($prev !== null) {
                $w = abs((float) $s['CumulativeDistance'] - (float) $prev['CumulativeDistance']);
                $edges[$prev['StationID']][] = [$s['StationID'], $w, $l['LineID']];
                $edges[$s['StationID']][] = [$prev['StationID'], $w, $l['LineID']];
            }
            $prev = $s;
        }
    }
    return $edges;
}

/** 兩站間最短路徑經過的路線（依序、不重複相鄰） */
function finderDbPathLines(array $edges, $from, $to, array &$memo)
{
    $k = $from . '>' . $to;
    if (isset($memo[$k])) {
        return $memo[$k];
    }
    $dist = [$from => 0.0];
    $prev = [];
    $q = new SplPriorityQueue();
    $q->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
    $q->insert($from, 0);
    while (!$q->isEmpty()) {
        $top = $q->extract();
        $u = $top['data'];
        $d = -$top['priority'];
        if ($d > $dist[$u] + 1e-9) {
            continue;
        }
        if ($u === $to) {
            break;
        }
        foreach ($edges[$u] ?? [] as $e) {
            $nd = $d + $e[1];
            if (!isset($dist[$e[0]]) || $nd < $dist[$e[0]] - 1e-9) {
                $dist[$e[0]] = $nd;
                $prev[$e[0]] = [$u, $e[2]];
                $q->insert($e[0], -$nd);
            }
        }
    }
    $lines = [];
    $v = $to;
    while ($v !== $from && isset($prev[$v])) {
        array_unshift($lines, $prev[$v][1]);
        $v = $prev[$v][0];
    }
    $out = [];
    foreach ($lines as $l) {
        if (!$out || end($out) !== $l) {
            $out[] = $l;
        }
    }
    return $memo[$k] = $out;
}

function finderDbInsertTrain(SqliteDao $db, array $tr, array $serviceDay, array $stops)
{
    $first = $stops[0] ?? null;
    $t0 = $first ? finderDbMinutes($first['dep'] !== '' ? $first['dep'] : $first['arr']) : null;
    $db->run('INSERT INTO train (train_key, system, train_no, train_no_norm, direction, train_type_id, train_type_code,
              line_ids, weekmask, start_min, info, service_day) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        $tr['TrainKey'], $tr['System'], (string) $tr['TrainNo'], ltrim((string) $tr['TrainNo'], '0'), (string) $tr['Direction'],
        (string) $tr['TrainTypeID'], (string) $tr['TrainTypeCode'], finderDbJson($tr['LineIDs']),
        finderDbWeekmask($serviceDay), $t0, finderDbJson($tr), finderDbJson(finderDbServiceDay($serviceDay)),
    ]);
    foreach ($stops as $s) {
        $m = finderDbMinutes($s['dep'] !== '' ? $s['dep'] : $s['arr']);
        if ($m !== null && $t0 !== null && $m < $t0) {
            $m += 1440;   // 過午夜
        }
        $db->run('INSERT INTO train_stop (train_key, seq, station_uid, arr, dep, sort_min) VALUES (?, ?, ?, ?, ?, ?)',
                 [$tr['TrainKey'], (int) $s['seq'], $s['uid'], $s['arr'], $s['dep'], $m]);
    }
}

function finderDbBuildTRA(SqliteDao $db)
{
    $edges = finderDbTRAEdges();
    $memo = [];
    $data = finderDbLoad('/v3/Rail/TRA/GeneralTrainTimetable')['TrainTimetables'] ?? [];
    foreach (array_keys($data) as $idx) {
        $t = $data[$idx];
        unset($data[$idx]);
        $i = $t['TrainInfo'];
        $tr = finderDbTrainTemplate();
        foreach ($tr as $k => $v) {
            if (array_key_exists($k, $i)) {
                $tr[$k] = is_array($v) ? finderDbName($i[$k]) : finderDbStr($i[$k]);
            }
        }
        $tr['System'] = 'TRA';
        $tr['TrainKey'] = 'TRA-' . $i['TrainNo'];
        $tr['StartingStationUID'] = finderDbUID('TRA', $i['StartingStationID'] ?? '');
        $tr['EndingStationUID'] = finderDbUID('TRA', $i['EndingStationID'] ?? '');
        $tr['Overnight'] = ($i['OverNightStationID'] ?? '') !== '' ? 1 : 0;
        $stops = [];
        $lines = [];
        $prevID = null;
        foreach ($t['StopTimes'] as $s) {
            $stops[] = ['seq' => $s['StopSequence'], 'uid' => finderDbUID('TRA', $s['StationID']),
                        'arr' => (string) finderDbStr($s['ArrivalTime'] ?? ''), 'dep' => (string) finderDbStr($s['DepartureTime'] ?? '')];
            if ($prevID !== null) {
                foreach (finderDbPathLines($edges, $prevID, $s['StationID'], $memo) as $l) {
                    if (!$lines || end($lines) !== $l) {
                        $lines[] = $l;
                    }
                }
            }
            $prevID = $s['StationID'];
        }
        $tr['LineIDs'] = $lines;
        finderDbInsertTrain($db, $tr, $t['ServiceDay'] ?? [], $stops);
    }
}

function finderDbBuildTHSR(SqliteDao $db)
{
    $line = '';
    foreach (finderDbLoad('/v2/Rail/S2SDistance/THSR') as $l) {
        $line = $l['LineID'] ?? '';
    }
    foreach (finderDbLoad('/v2/Rail/THSR/GeneralTimetable') as $row) {
        $g = $row['GeneralTimetable'] ?? $row;
        $i = $g['GeneralTrainInfo'];
        $tr = finderDbTrainTemplate();
        $tr['System'] = 'THSR';
        $tr['TrainKey'] = 'THSR-' . $i['TrainNo'];
        $tr['TrainNo'] = $i['TrainNo'];
        $tr['Direction'] = finderDbStr($i['Direction'] ?? '');
        $tr['StartingStationID'] = $i['StartingStationID'] ?? '';
        $tr['StartingStationUID'] = finderDbUID('THSR', $tr['StartingStationID']);
        $tr['StartingStationName'] = finderDbName($i['StartingStationName'] ?? null);
        $tr['EndingStationID'] = $i['EndingStationID'] ?? '';
        $tr['EndingStationUID'] = finderDbUID('THSR', $tr['EndingStationID']);
        $tr['EndingStationName'] = finderDbName($i['EndingStationName'] ?? null);
        $tr['LineIDs'] = $line === '' ? [] : [$line];
        $tr['Overnight'] = isset($i['Overnight']) ? (int) (bool) $i['Overnight'] : '';
        $note = $i['Note'] ?? '';
        $tr['Note'] = is_array($note) ? (string) ($note['Zh_tw'] ?? '') : (string) $note;
        $stops = [];
        foreach ($g['StopTimes'] as $s) {
            $stops[] = ['seq' => $s['StopSequence'], 'uid' => finderDbUID('THSR', $s['StationID']),
                        'arr' => (string) finderDbStr($s['ArrivalTime'] ?? ''), 'dep' => (string) finderDbStr($s['DepartureTime'] ?? '')];
        }
        finderDbInsertTrain($db, $tr, $g['ServiceDay'] ?? [], $stops);
    }
}

// ---- 捷運、輕軌 ----

/**
 * 找站別時刻所屬的營運路線站序，用來補方向、起點、站序。依序嘗試：
 *   1. RouteID 相同（RouteID 為空時比 LineID）且方向相同  2. RouteID 相同、不看方向（TDX 部分資料方向與站序相反）
 *   3. 同一路線（LineID）、方向相同              4. 同一路線、不看方向
 * 條件都是「本站在站序中、終點在本站之後」；環狀路線（高雄輕軌 C1→…→C1）取較後面的終點位置，
 * 終點就是本站（到站不再發車）時本站須為站序最後一站。
 * 回傳 [營運路線, 本站索引, 是否為同一 RouteID]；第 3、4 步找到的只能確定方向與站序，起點不確定。
 */
function finderDbFindRoute(array $routes, $routeID, $lineID, $dir, $station, $dest)
{
    foreach ([[true, true], [true, false], [false, true], [false, false]] as $t) {
        list($byRoute, $byDir) = $t;
        foreach ($routes as $r) {
            if ($byRoute && ($routeID !== '' ? $r['RouteID'] !== $routeID : $r['LineID'] !== $lineID)) {
                continue;
            }
            if (!$byRoute && $r['LineID'] !== $lineID) {
                continue;
            }
            if ($byDir && $dir !== '' && (string) $r['Direction'] !== (string) $dir) {
                continue;
            }
            $a = array_search($station, $r['Stations'], true);
            if ($a === false) {
                continue;
            }
            $last = count($r['Stations']) - 1;
            foreach (array_keys($r['Stations'], $dest, true) as $b) {
                if ($b > $a || ($b === $a && $a === $last)) {
                    return [$r, $a, $byRoute];
                }
            }
        }
    }
    return null;
}

// ---- 資料來源優先順序（見 FINDERDB_TRAVEL_RANK、FINDERDB_TIMETABLE_RANK） ----

/** 站間行駛時間來源的等級；多種來源以 + 連接時取最差的一個 */
function finderDbTravelRank($source)
{
    $rank = 0;
    foreach (explode('+', (string) $source) as $s) {
        $rank = max($rank, FINDERDB_TRAVEL_RANK[$s] ?? 9);
    }
    return $rank;
}

/** 站間行駛時間來源 → real／mixed／derived */
function finderDbTravelTier($source)
{
    $r = finderDbTravelRank($source);
    return $r === 1 ? 'real' : ($r <= 4 ? 'mixed' : 'derived');
}

/** 站別時刻表來源 → real／mixed／derived */
function finderDbTimetableTier($source)
{
    return FINDERDB_TIERS[(FINDERDB_TIMETABLE_RANK[$source] ?? 3) - 1];
}

/** 幾個層級中最差的一個（real < mixed < derived） */
function finderDbWorstTier(...$tiers)
{
    $i = 0;
    foreach ($tiers as $t) {
        $i = max($i, (int) array_search($t, FINDERDB_TIERS, true));
    }
    return FINDERDB_TIERS[$i];
}

// ---- 站別時刻表索引（推算站間時間、平移時刻用） ----

/** 站別時刻表依「車站|終點|營運日」整理成排序後的發車分鐘（04:00 前 +1440）；沒有站別時刻表回空陣列 */
function finderDbTimetableIndex($sys)
{
    static $cache = [];
    if (isset($cache[$sys])) {
        return $cache[$sys];
    }
    $idx = [];
    if (finderDbHasStationTimetable($sys)) {
        foreach (finderDbLoad('/v2/Rail/Metro/StationTimeTable/' . $sys) as $g) {
            $dest = (string) ($g['DestinationStaionID'] ?? ($g['DestinationStationID'] ?? ''));
            $k = $g['StationID'] . '|' . $dest . '|' . ($g['ServiceDay']['ServiceTag'] ?? '');
            foreach ($g['Timetables'] ?? [] as $t) {
                $m = finderDbMinutes((string) (($t['DepartureTime'] ?? '') !== '' ? $t['DepartureTime'] : ($t['ArrivalTime'] ?? '')));
                if ($m !== null) {
                    $idx[$k][] = $m < 240 ? $m + 1440 : $m;
                }
            }
        }
        foreach ($idx as &$l) {
            sort($l);
        }
        unset($l);
    }
    return $cache[$sys] = $idx;
}

/**
 * 站別時刻表推算 a→b 一段（同終點 dest 的列車）：a 站每一班之後 b 站同終點的第一班，取發車時間差（15 分鐘內），
 * 以眾數 ±1 分鐘內的時間差平均後換成秒（時刻表只到分鐘，平均比眾數更接近實際秒數；
 * 用 S2STravelTime 驗證：臺北捷運誤差平均 17 秒 → 9 秒）。沒有資料回 null。
 * 時間差＝行駛＋b 站停站（兩站都是離站時刻）
 */
function finderDbTimetableSegment(array $idx, $a, $b, $dest)
{
    $cnt = [];
    foreach ($idx as $k => $A) {
        list($s, $d, $tag) = explode('|', $k);
        if ($s !== $a || $d !== $dest) {
            continue;
        }
        $B = $idx[$b . '|' . $dest . '|' . $tag] ?? [];
        foreach ($A as $t) {
            foreach ($B as $x) {
                if ($x >= $t) {
                    if ($x - $t <= 15) {
                        $cnt[$x - $t] = ($cnt[$x - $t] ?? 0) + 1;
                    }
                    break;
                }
            }
        }
    }
    if (!$cnt) {
        return null;
    }
    arsort($cnt);
    $mode = (int) array_key_first($cnt);
    $sum = 0;
    $n = 0;
    foreach ($cnt as $d => $c) {
        if (abs($d - $mode) <= 1) {
            $sum += $d * $c;
            $n += $c;
        }
    }
    return (int) round($sum / $n * 60);
}

/** StationOfRoute → [ "RouteID|Direction" => ['route', 'line', 'dir', 'seq' => 依 Sequence 排序的站碼] ] */
function finderDbRouteSeqs($sys)
{
    $out = [];
    foreach (finderDbLoad('/v2/Rail/Metro/StationOfRoute/' . $sys) as $r) {
        $st = $r['Stations'];
        usort($st, function ($a, $b) { return $a['Sequence'] <=> $b['Sequence']; });
        $out[$r['RouteID'] . '|' . (int) ($r['Direction'] ?? 0)] = [
            'route' => (string) $r['RouteID'], 'line' => (string) $r['LineID'], 'dir' => (int) ($r['Direction'] ?? 0),
            'seq' => array_map(function ($x) { return (string) $x['StationID']; }, $st),
        ];
    }
    return $out;
}

// ---- 捷運站間行駛時間 ----

/**
 * 捷運站間行駛時間。每個「系統 × 路線 × 車種」選一種儲存型態，記在 metro_travel_set：
 *
 *   route   路線站距表（metro_travel）：每條營運路線 × 方向一份，依站序存相鄰兩站的行駛秒數與累計秒數；
 *           兩站間時間 = 從 A 累加經過的車站到 B（迄站 arr − 起站 dep）
 *   matrix  起訖矩陣（metro_travel_od）：原樣存 TDX 的任兩站秒數，A→B 直接查
 *
 * 每一段依優先順序取值（finderDbTravelSegment）：tdx → reversed → timetable → timetable-reversed，
 * 所以 S2STravelTime 沒有的車站（新站、延伸段、中間新增的站）會自動由站別時刻表補上，有 TDX 值的段落一律用 TDX 值。
 *
 *   - TDX 原始資料是「相鄰站鏈」（臺北、新北、高雄捷運、臺中捷運）：依 StationOfRoute 完整站序逐段取值，存 route
 *   - TDX 原始資料是「起訖矩陣」（機場捷運、高雄環狀輕軌）：只用矩陣中相鄰兩站的值（不補停站、不倒用）依站序累加，
 *     再拿矩陣每一筆原始值比對；全部一致、且路線上沒有矩陣缺少的車站才存 route，否則存 matrix（原始值），
 *     矩陣缺少的車站再由站別時刻表串車推算補上（finderDbTimetableOD，只補含缺站的起訖對）
 *   - 整條路線都沒有 S2STravelTime（淡海、安坑輕軌，或新路線）：由站別時刻表串車推算起訖矩陣（finderDbBuildTimetableMatrix）
 */
function finderDbBuildMetroTravel(SqliteDao $db)
{
    foreach (FINDERDB_S2S_SYSTEMS as $sys) {
        $groups = [];   // "line|tt" => [[from, to, run, stop], ...]
        foreach (finderDbLoad('/v2/Rail/Metro/S2STravelTime/' . $sys) as $r) {
            $k = $r['LineID'] . '|' . (int) ($r['TrainType'] ?? 0);
            foreach ($r['TravelTimes'] ?? [] as $t) {
                $groups[$k][] = [(string) $t['FromStationID'], (string) $t['ToStationID'], (int) $t['RunTime'], (int) ($t['StopTime'] ?? 0)];
            }
        }
        $routes = finderDbRouteSeqs($sys);
        $idx = finderDbTimetableIndex($sys);
        // 各路線在 S2STravelTime 出現過的車站（所有車種合併）：判斷矩陣缺哪些站
        $lineS2S = [];
        foreach ($groups as $k => $entries) {
            $line = explode('|', $k)[0];
            foreach ($entries as $e) {
                $lineS2S[$line][$e[0]] = $lineS2S[$line][$e[1]] = true;
            }
        }
        $tdxOD = null;   // 站別時刻表串車的起訖矩陣（需要時才算）
        foreach ($groups as $k => $entries) {
            list($line, $tt) = explode('|', $k);
            $tt = (int) $tt;
            // 相鄰查找表：同一對取最小正值（環狀線的矩陣同一對會有順向與繞遠路兩個值）
            $adj = [];
            $stations = [];
            foreach ($entries as $e) {
                $stations[$e[0]] = $stations[$e[1]] = true;
                if ($e[0] === $e[1] || $e[2] <= 0) {
                    continue;
                }
                $p = $e[0] . '>' . $e[1];
                if (!isset($adj[$p]) || $e[2] < $adj[$p][0]) {
                    $adj[$p] = [$e[2], $e[3]];
                }
            }
            $isMatrix = count($adj) > 2 * count($stations);

            $chains = [];
            $missing = [];   // 路線上 S2STravelTime 沒有的車站
            foreach ($routes as $r) {
                if ($r['line'] !== $line) {
                    continue;
                }
                foreach ($r['seq'] as $x) {
                    if (!isset($lineS2S[$line][$x])) {
                        $missing[$x] = true;
                    }
                }
                if ($isMatrix) {
                    // 起訖矩陣：只用這一組（車種）有資料的車站，只取矩陣原值（直達車只停部分站）
                    $seq = array_values(array_filter($r['seq'], function ($x) use ($stations) { return isset($stations[$x]); }));
                    $opt = ['reverse' => false, 'stop' => false, 'timetable' => false];
                } else {
                    // 相鄰站鏈：完整站序，缺的段落依優先順序補
                    $seq = $r['seq'];
                    $opt = ['reverse' => true, 'stop' => true, 'timetable' => true];
                }
                $rev = $routes[$r['route'] . '|' . (1 - $r['dir'])]['seq'] ?? [];
                $ctx = ['adj' => $adj, 'idx' => $idx, 'dest' => $r['seq'][count($r['seq']) - 1],
                        'revDest' => $rev ? $rev[count($rev) - 1] : ''] + $opt;
                foreach (finderDbTravelLadderChain($seq, $ctx) as $piece) {
                    $chains[] = ['route' => $r['route'], 'dir' => $r['dir']] + $piece;
                    $filled = array_diff(array_unique($piece['src']), ['tdx']);
                    if ($filled) {
                        $GLOBALS['FINDERDB_TRAVEL_FILLED'][] = $sys . ' ' . $r['route'] . ' 方向' . $r['dir'] . '：' . implode('、', $filled);
                    }
                }
            }

            // 起訖矩陣：每一筆原始值都要能由路線站距表算出，且路線上沒有缺站，否則存原始矩陣
            $storage = 'route';
            $checked = 0;
            $mismatch = 0;
            if ($isMatrix) {
                $seen = [];
                foreach ($entries as $e) {
                    $key = $e[0] . '>' . $e[1] . '>' . $e[2];
                    if (isset($seen[$key]) || $e[2] <= 0) {
                        continue;
                    }
                    $seen[$key] = true;
                    $checked++;
                    if (!finderDbTravelReproduces($chains, $e[0], $e[1], $e[2])) {
                        $mismatch++;
                    }
                }
                if ($mismatch > 0 || !$chains || $missing) {
                    $storage = 'matrix';
                }
            }

            if ($storage === 'matrix') {
                foreach ($entries as $e) {
                    if ($e[2] > 0) {
                        $db->run('INSERT OR IGNORE INTO metro_travel_od (system, line_id, train_type, from_uid, to_uid, seconds, source) VALUES (?, ?, ?, ?, ?, ?, ?)',
                                 [$sys, $line, $tt, finderDbUID($sys, $e[0]), finderDbUID($sys, $e[1]), $e[2], 'tdx']);
                    }
                }
                // 矩陣缺少的車站：由站別時刻表串車推算，只補含缺站的起訖對，記在停最多站的車種
                $most = true;
                foreach ($groups as $k2 => $e2) {
                    if (explode('|', $k2)[0] === $line && count($e2) > count($entries)) {
                        $most = false;
                    }
                }
                if ($missing && $most && $idx) {
                    $tdxOD = $tdxOD ?? finderDbTimetableOD($sys, $routes, null);
                    foreach ($tdxOD as $key => $f) {
                        list($l, $a, $b) = explode('|', $key);
                        if ($l === $line && (isset($missing[$a]) || isset($missing[$b]))) {
                            $db->run('INSERT OR IGNORE INTO metro_travel_od (system, line_id, train_type, from_uid, to_uid, seconds, source, samples, agree)
                                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                                     [$sys, $line, $tt, finderDbUID($sys, $a), finderDbUID($sys, $b), $f[0], $f[3], $f[1], $f[2]]);
                        }
                    }
                }
            } else {
                finderDbInsertTravelChains($db, $sys, $line, $tt, $chains);
            }
            $db->run('INSERT INTO metro_travel_set (system, line_id, train_type, raw_shape, storage, raw_pairs, checked, mismatch) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                     [$sys, $line, $tt, $isMatrix ? 'matrix' : 'chain', $storage, count($entries), $checked, $mismatch]);
        }
    }
}

/**
 * 站間行駛時間的逐段優先順序：a→b 這一段依序找第一個有資料的
 *   1 tdx                 S2STravelTime 同方向（停站用 a 站的 StopTime）
 *   2 reversed            S2STravelTime 反方向 b→a 倒用（$ctx['reverse']）
 *   3 timetable           同方向站別時刻表發車時間差（已含 b 站停站）（$ctx['timetable']）
 *   4 timetable-reversed  反方向站別時刻表 b→a 發車時間差（已含 a 站停站）
 * 回傳 ['run', 'src', 'stop'（a 站停站；這段沒有就是 null）, 'incl'（時間差已含哪一站的停站：to／from／''）] 或 null（這段沒有任何資料）
 */
function finderDbTravelSegment($a, $b, array $ctx)
{
    if (isset($ctx['adj'][$a . '>' . $b])) {
        $x = $ctx['adj'][$a . '>' . $b];
        return ['run' => $x[0], 'src' => 'tdx', 'stop' => $ctx['stop'] ? $x[1] : 0, 'incl' => ''];
    }
    if ($ctx['reverse'] && isset($ctx['adj'][$b . '>' . $a])) {
        return ['run' => $ctx['adj'][$b . '>' . $a][0], 'src' => 'reversed', 'stop' => null, 'incl' => ''];
    }
    if ($ctx['timetable'] && $ctx['idx']) {
        $t = finderDbTimetableSegment($ctx['idx'], $a, $b, $ctx['dest']);
        if ($t !== null) {
            return ['run' => $t, 'src' => 'timetable', 'stop' => null, 'incl' => 'to'];
        }
        if ($ctx['revDest'] !== '') {
            $t = finderDbTimetableSegment($ctx['idx'], $b, $a, $ctx['revDest']);
            if ($t !== null) {
                return ['run' => $t, 'src' => 'timetable-reversed', 'stop' => null, 'incl' => 'from'];
            }
        }
    }
    return null;
}

/**
 * 依站序逐段（finderDbTravelSegment）累加成路線站距表。某段完全沒有資料時從那裡斷開，回傳多段（每段至少兩站）。
 * 每段：['seq' => 站碼, 'rows' => [[站碼, 行駛, 停站, 抵達秒, 離站秒], ...]（從起站離站起算）, 'src' => 每站來源（本站與前一站之間；起站同第一段）]
 * 中間站的停站也依優先順序：時刻表推算的段落已含停站 → 0（避免重複計算）；S2STravelTime 同方向「本站→下一站」的 StopTime；
 * 反方向「本站→前一站」的 StopTime（TDX 的 StopTime 是出發站的停站秒數）；都沒有記 0。起訖矩陣一律不補停站（0）
 */
function finderDbTravelLadderChain(array $seq, array $ctx)
{
    $n = count($seq);
    $segs = [];
    for ($k = 0; $k + 1 < $n; $k++) {
        $segs[$k] = finderDbTravelSegment($seq[$k], $seq[$k + 1], $ctx);
    }
    $pieces = [];
    $start = 0;
    for ($k = 0; $k <= $n - 1; $k++) {
        if ($k < $n - 1 && $segs[$k] !== null) {
            continue;
        }
        // seq[start..k] 是一段連續有資料的站
        if ($k > $start) {
            $rows = [];
            $src = [];
            $dep = 0;
            for ($i = $start; $i <= $k; $i++) {
                $run = $i > $start ? $segs[$i - 1]['run'] : 0;
                $arr = $i > $start ? $dep + $run : 0;
                $dwell = 0;
                if ($i > $start && $i < $k && $ctx['stop'] && $segs[$i - 1]['incl'] !== 'to' && $segs[$i]['incl'] !== 'from') {
                    $dwell = $segs[$i]['stop'] ?? ($ctx['reverse'] ? ($ctx['adj'][$seq[$i] . '>' . $seq[$i - 1]][1] ?? 0) : 0);
                }
                $dep = $arr + $dwell;
                $rows[] = [$seq[$i], $run, $dwell, $arr, $dep];
                $src[] = $segs[$i > $start ? $i - 1 : $start]['src'];
            }
            $pieces[] = ['seq' => array_slice($seq, $start, $k - $start + 1), 'rows' => $rows, 'src' => $src];
        }
        $start = $k + 1;
    }
    return $pieces;
}

/** 某筆原始值 from→to = seconds 是否能由任一條路線站距表算出（環狀線同一站可能出現兩次，任一對都算） */
function finderDbTravelReproduces(array $chains, $from, $to, $seconds)
{
    foreach ($chains as $c) {
        foreach ($c['seq'] as $i => $a) {
            if ($a !== $from) {
                continue;
            }
            for ($j = $i + 1; $j < count($c['seq']); $j++) {
                if ($c['seq'][$j] === $to && $c['rows'][$j][3] - $c['rows'][$i][4] === $seconds) {
                    return true;
                }
            }
        }
    }
    return false;
}

/** 寫入路線站距表；被同方向較長鏈完整包含、或完全相同的站序（區間車）略過 */
function finderDbInsertTravelChains(SqliteDao $db, $sys, $line, $tt, array $chains)
{
    $contains = function (array $small, array $big) {
        $n = count($small);
        for ($i = 0; $i + $n <= count($big); $i++) {
            if (array_slice($big, $i, $n) === $small) {
                return true;
            }
        }
        return false;
    };
    foreach ($chains as $i => $c) {
        foreach ($chains as $j => $o) {
            if ($i === $j || $o['dir'] !== $c['dir']) {
                continue;
            }
            if ((count($o['seq']) > count($c['seq']) && $contains($c['seq'], $o['seq'])) || ($o['seq'] === $c['seq'] && $j < $i)) {
                continue 2;
            }
        }
        foreach ($c['rows'] as $n => $row) {
            $db->run('INSERT INTO metro_travel (system, line_id, route_id, direction, train_type, seq, station_uid, run, dwell, arr, dep, source)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                     [$sys, $line, $c['route'], $c['dir'], $tt, $n + 1, finderDbUID($sys, $row[0]),
                      $row[1], $row[2], $row[3], $row[4], $c['src'][$n]]);
        }
    }
}

// ---- 由站別時刻表串車推算的起訖矩陣（沒有 S2STravelTime 的路線） ----

/**
 * 站別時刻表串車推算起訖矩陣（比照機場捷運存起訖矩陣）：
 *   1. 每條營運路線（StationOfRoute）、每個營運日（ServiceTag），先算相鄰兩站的發車時間差眾數
 *      （A 站每班之後 B 站同終點的第一班）
 *   2. 從起點站每一班出發，逐站找同終點、與「前站時刻 + 相鄰眾數」相差 1 分鐘內、尚未用過的班次，串成一整班車
 *   3. 每兩站的時間差＝同一班車在兩站的時刻相減；同一對取所有班次的眾數（平日、假日合併）。source = timetable
 *   4. 終點站沒有往終點方向的發車時刻，「某站→終點站」串不出來：最後一段（前一站→終點站）以反方向（終點站→前一站）
 *      的時間推估，加上「某站→前一站」，source = timetable-reversed
 * $lineOk：只處理哪些路線（callable(line) → bool；null 為全部）。
 * 回傳 "line|a|b" => [秒, samples（參與的班次數）, agree（與眾數相同的班次數）, source]，另以 $trips 傳回串出的班次數
 */
function finderDbTimetableOD($sys, array $routes, $lineOk, &$trips = 0)
{
    $idx = finderDbTimetableIndex($sys);
    $tags = [];
    foreach (array_keys($idx) as $k) {
        $tags[explode('|', $k)[2]] = true;
    }
    $od = [];        // "line|a|b" => [diffs...]
    $trips = 0;
    foreach ($routes as $r) {
        if ($lineOk !== null && !$lineOk($r['line'])) {
            continue;
        }
        $st = $r['seq'];
        $dest = $st[count($st) - 1];
        foreach (array_keys($tags) as $tag) {
            $tag = (string) $tag;
            $mode = [];
            for ($i = 0; $i + 1 < count($st); $i++) {
                $A = $idx[$st[$i] . '|' . $dest . '|' . $tag] ?? [];
                $B = $idx[$st[$i + 1] . '|' . $dest . '|' . $tag] ?? [];
                $cnt = [];
                foreach ($A as $t) {
                    foreach ($B as $x) {
                        if ($x >= $t) {
                            $cnt[$x - $t] = ($cnt[$x - $t] ?? 0) + 1;
                            break;
                        }
                    }
                }
                if ($cnt) {
                    arsort($cnt);
                    $mode[$i] = (int) array_key_first($cnt);
                }
            }
            $used = [];
            foreach ($idx[$st[0] . '|' . $dest . '|' . $tag] ?? [] as $t0) {
                $times = [$t0];
                $cur = $t0;
                for ($i = 0; $i + 1 < count($st); $i++) {
                    if (!isset($mode[$i])) {
                        break;
                    }
                    $want = $cur + $mode[$i];
                    $best = null;
                    foreach ($idx[$st[$i + 1] . '|' . $dest . '|' . $tag] ?? [] as $x) {
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
                $trips++;
                for ($i = 0; $i < count($times); $i++) {
                    for ($j = $i + 1; $j < count($times); $j++) {
                        $od[$r['line'] . '|' . $st[$i] . '|' . $st[$j]][] = $times[$j] - $times[$i];
                    }
                }
            }
        }
    }
    $modeOf = function (array $d) {
        $cnt = array_count_values($d);
        arsort($cnt);
        $m = (int) array_key_first($cnt);
        return [$m, count($d), $cnt[$m]];
    };
    // 終點站：時間(A→終點) = 時間(A→前一站) + 時間(終點→前一站)；A 就是前一站時只取後者
    $filled = [];
    foreach ($routes as $r) {
        if ($lineOk !== null && !$lineOk($r['line'])) {
            continue;
        }
        $st = $r['seq'];
        $n = count($st);
        if ($n < 2) {
            continue;
        }
        $line = $r['line'];
        $end = $st[$n - 1];
        $prev = $st[$n - 2];
        $last = $od[$line . '|' . $end . '|' . $prev] ?? null;
        if ($last === null) {
            continue;
        }
        $lastM = $modeOf($last);
        for ($i = 0; $i < $n - 1; $i++) {
            $k = $line . '|' . $st[$i] . '|' . $end;
            if (isset($od[$k]) || isset($filled[$k])) {
                continue;
            }
            if ($st[$i] === $prev) {
                $filled[$k] = $lastM;
            } elseif (isset($od[$line . '|' . $st[$i] . '|' . $prev])) {
                $head = $modeOf($od[$line . '|' . $st[$i] . '|' . $prev]);
                $filled[$k] = [$head[0] + $lastM[0], min($head[1], $lastM[1]), min($head[2], $lastM[2])];
            }
        }
    }
    $out = [];
    foreach ($filled as $k => $f) {
        $out[$k] = [$f[0] * 60, $f[1], $f[2], 'timetable-reversed'];
    }
    foreach ($od as $k => $diffs) {
        $m = $modeOf($diffs);
        $out[$k] = [$m[0] * 60, $m[1], $m[2], 'timetable'];
    }
    return $out;
}

/**
 * 沒有 S2STravelTime 的路線（淡海、安坑輕軌；或 S2S 系統裡整條沒有資料的新路線）：由站別時刻表串車推算起訖矩陣，
 * 存 metro_travel_od（raw_shape = timetable、storage = matrix）。有 S2STravelTime 的路線不做（真實資料優先）
 */
function finderDbBuildTimetableMatrix(SqliteDao $db)
{
    foreach (FINDERDB_METRO_SYSTEMS as $sys) {
        if (!finderDbHasStationTimetable($sys)) {
            continue;
        }
        $covered = [];
        foreach ($db->all('SELECT DISTINCT line_id FROM metro_travel_set WHERE system = ?', [$sys]) as $r) {
            $covered[$r['line_id']] = true;
        }
        $trips = 0;
        $od = finderDbTimetableOD($sys, finderDbRouteSeqs($sys), function ($line) use ($covered) { return !isset($covered[$line]); }, $trips);
        if (!$od) {
            continue;
        }
        $byLine = [];
        // 先寫終點站推估（timetable-reversed），再寫串車結果；同一對以串車結果為準（與舊版寫入順序相同）
        foreach ([['timetable-reversed'], ['timetable']] as $pass) {
            foreach ($od as $k => $f) {
                if ($f[3] !== $pass[0]) {
                    continue;
                }
                list($line, $a, $b) = explode('|', $k);
                $db->run('INSERT OR REPLACE INTO metro_travel_od (system, line_id, train_type, from_uid, to_uid, seconds, source, samples, agree)
                          VALUES (?, ?, 0, ?, ?, ?, ?, ?, ?)',
                         [$sys, $line, finderDbUID($sys, $a), finderDbUID($sys, $b), $f[0], $f[3], $f[1], $f[2]]);
                if ($f[3] === 'timetable') {
                    $byLine[$line]['pairs'] = ($byLine[$line]['pairs'] ?? 0) + 1;
                    $byLine[$line]['mixed'] = ($byLine[$line]['mixed'] ?? 0) + ($f[2] < $f[1] ? 1 : 0);
                }
            }
        }
        foreach ($byLine as $line => $x) {
            $db->run('INSERT OR REPLACE INTO metro_travel_set (system, line_id, train_type, raw_shape, storage, raw_pairs, checked, mismatch) VALUES (?, ?, 0, ?, ?, ?, ?, ?)',
                     [$sys, $line, 'timetable', 'matrix', $trips, $x['pairs'], $x['mixed']]);
        }
    }
}

// ---- 統一時刻格式 ----

/**
 * 所有系統的停靠時刻統一成「每一站都有到站 arr 與離站 dep」，格式 HH:MM、時為 00～23（TDX 高雄捷運、輕軌過午夜寫 24:xx，改成 00:xx；
 * 排序與跨午夜一律看 sort_min）。原本缺的值依下列規則補，並記在 arr_src／dep_src：
 *   timetable  TDX 原始值（高雄捷運、環狀輕軌、淡海、安坑輕軌 TDX 的到站時間與離站時間相同）
 *   origin     起站沒有到站時間（高鐵），以離站時間填
 *   terminal   終點站沒有離站時間（高鐵），以到站時間填
 *   estimate   捷運只有離站時間（臺北捷運、平移與模擬時刻）：到站 ＝ 離站 − 本站停站秒數。離站只到分鐘，視為該分鐘的中間（+30 秒），
 *              減去停站後捨去到分鐘；停站取路線站距表（metro_travel）本站同方向、來源等級最好的一筆，沒有同方向用另一方向，都沒有記 0
 */
function finderDbNormalizeTimes(SqliteDao $db)
{
    foreach (['train_stop', 'metro_dep'] as $t) {
        foreach (['arr', 'dep'] as $c) {
            $db->run("UPDATE {$t} SET {$c} = printf('%02d', CAST(substr({$c}, 1, 2) AS INTEGER) - 24) || substr({$c}, 3)
                      WHERE length({$c}) >= 5 AND CAST(substr({$c}, 1, 2) AS INTEGER) >= 24");
        }
    }
    // 火車：高鐵起站沒有到站、終點沒有離站（右側運算式取的都是更新前的值）
    $db->run("UPDATE train_stop SET
                arr_src = CASE WHEN arr = '' THEN 'origin' ELSE 'timetable' END,
                dep_src = CASE WHEN dep = '' THEN 'terminal' ELSE 'timetable' END,
                arr = CASE WHEN arr = '' THEN dep ELSE arr END,
                dep = CASE WHEN dep = '' THEN arr ELSE dep END");

    // 捷運：各站停站秒數（車站|方向 → 秒；車站 → 秒）
    $dwell = [];
    $any = [];
    $rank = [];
    foreach ($db->all('SELECT m.system, m.station_uid, m.direction, m.dwell, m.source FROM metro_travel m
                       WHERE m.seq > 1 AND m.seq < (SELECT MAX(seq) FROM metro_travel x WHERE x.system = m.system AND x.line_id = m.line_id
                                                     AND x.route_id = m.route_id AND x.direction = m.direction AND x.train_type = m.train_type)') as $r) {
        $k = $r['station_uid'] . '|' . $r['direction'];
        $rk = finderDbTravelRank($r['source']);
        if (!isset($dwell[$k]) || $rk < $rank[$k] || ($rk === $rank[$k] && (int) $r['dwell'] > $dwell[$k])) {
            $dwell[$k] = (int) $r['dwell'];
            $rank[$k] = $rk;
        }
        $any[$r['station_uid']] = max($any[$r['station_uid']] ?? 0, (int) $r['dwell']);
    }
    $db->run("UPDATE metro_dep SET dep_src = 'timetable', arr_src = 'timetable' WHERE arr != ''");
    foreach ($db->all("SELECT DISTINCT g.group_id, g.station_uid, g.direction FROM metro_group g JOIN metro_dep d ON d.group_id = g.group_id WHERE d.arr = ''") as $g) {
        $dw = $dwell[$g['station_uid'] . '|' . $g['direction']] ?? ($any[$g['station_uid']] ?? 0);
        $db->run("UPDATE metro_dep SET
                    arr = printf('%02d:%02d', ((sort_min * 60 + 30 - ?) / 60 % 1440) / 60, (sort_min * 60 + 30 - ?) / 60 % 60),
                    arr_src = 'estimate', dep_src = 'timetable'
                  WHERE group_id = ? AND arr = ''", [$dw, $dw, $g['group_id']]);
    }
}

// ---- 站別時刻表的補站：平移（mixed）→ 班距模擬（derived） ----

/**
 * 某站某方向沒有真實站別時刻表時，依優先順序補：
 *   1 timetable  TDX 站別時刻表（finderDbBuildMetro 已寫入，不動）
 *   2 shifted    同營運路線、同方向最近一個有真實時刻表的車站，把會經過本站的班次加減站間行駛時間平移
 *                （上游車站優先：發車時刻 + 上游站→本站的時間；沒有上游才用下游：發車時刻 − 本站→下游站的時間）
 *   3 simulated  首末班車起依時段平均班距排（finderDbBuildMetroSim；文湖線、臺中捷運整條都沒有真實時刻表）
 * 一站一方向只會有一種來源。補上的組 simulated = 1（比對抵達時刻時不用）
 */
function finderDbBuildMetroFill(SqliteDao $db)
{
    finderDbBuildMetroShifted($db);
    finderDbBuildMetroSim($db);
}

/** 已有時刻表（任何來源）的「路線|車站|方向」與「路線|車站」→ 各組終點，用來判斷某站某方向是否已涵蓋 */
function finderDbMetroCoverage(SqliteDao $db, $sys, $sourceFilter = null)
{
    $cov = ['dir' => [], 'end' => []];
    $sql = "SELECT station_uid, direction, json_extract(info, '$.LineIDs[0]') AS line, json_extract(info, '$.EndingStationID') AS ending
            FROM metro_group WHERE system = ?" . ($sourceFilter !== null ? ' AND source = ?' : '');
    foreach ($db->all($sql, $sourceFilter !== null ? [$sys, $sourceFilter] : [$sys]) as $r) {
        $cov['dir'][$r['line'] . '|' . $r['station_uid'] . '|' . $r['direction']] = true;
        $cov['end'][$r['line'] . '|' . $r['station_uid']][(string) $r['ending']] = true;
    }
    return $cov;
}

/**
 * 站 x 在營運路線（站序 $seq、方向 $dir）上是否已有時刻表：同方向的組，或終點在 x 之後的組
 * （TDX 站別時刻表與 StationOfRoute 的方向編號不一定一致，所以兩者任一成立即可）
 */
function finderDbCovered(array $cov, $sys, $line, array $seq, $i, $dir)
{
    $uid = finderDbUID($sys, $seq[$i]);
    if (isset($cov['dir'][$line . '|' . $uid . '|' . $dir])) {
        return true;
    }
    if ($seq[0] === $seq[count($seq) - 1]) {
        return false;   // 環狀線：終點可能在兩個方向的後面，只看方向
    }
    foreach (array_keys($cov['end'][$line . '|' . $uid] ?? []) as $e) {
        if (in_array((string) $e, array_slice($seq, $i + 1), true)) {
            return true;
        }
    }
    return false;
}

function finderDbBuildMetroShifted(SqliteDao $db)
{
    $gid = (int) $db->value('SELECT COALESCE(MAX(group_id), 0) FROM metro_group');
    foreach (FINDERDB_METRO_SYSTEMS as $sys) {
        if (!finderDbHasStationTimetable($sys)) {
            continue;
        }
        $cov = finderDbMetroCoverage($db, $sys);
        $real = finderDbMetroCoverage($db, $sys, 'timetable');
        $routes = finderDbRouteSeqs($sys);
        // 站間行駛時間：路線站距表（同路線、同方向，含 y 與 x 的站序）與起訖矩陣
        $chains = [];
        foreach ($db->all('SELECT line_id, route_id, direction, train_type, station_uid, dep, arr, source FROM metro_travel WHERE system = ?
                           ORDER BY line_id, route_id, direction, train_type, seq', [$sys]) as $r) {
            $chains[$r['line_id'] . '|' . $r['route_id'] . '|' . $r['direction'] . '|' . $r['train_type']][] = $r;
        }
        $od = [];
        foreach ($db->all('SELECT line_id, from_uid, to_uid, MIN(seconds) AS s FROM metro_travel_od WHERE system = ? GROUP BY 1, 2, 3', [$sys]) as $r) {
            $od[$r['line_id'] . '|' . $r['from_uid'] . '|' . $r['to_uid']] = (int) $r['s'];
        }
        // a 站離站到 b 站離站（a 在 b 之前）的秒數；以來源等級最好的一條為準
        $offset = function ($line, $a, $b) use ($chains, $od) {
            $best = null;
            foreach ($chains as $k => $rows) {
                if (explode('|', $k)[0] !== $line) {
                    continue;
                }
                $ia = $ib = null;
                foreach ($rows as $i => $r) {
                    if ($r['station_uid'] === $a && $ia === null) {
                        $ia = $i;
                    } elseif ($r['station_uid'] === $b && $ia !== null) {
                        $ib = $i;
                        break;
                    }
                }
                if ($ib === null) {
                    continue;
                }
                $src = implode('+', array_unique(array_column(array_slice($rows, $ia + 1, $ib - $ia), 'source')));
                $cand = [(int) $rows[$ib]['dep'] - (int) $rows[$ia]['dep'], finderDbTravelRank($src)];
                if ($best === null || $cand[1] < $best[1]) {
                    $best = $cand;
                }
            }
            if ($best === null && isset($od[$line . '|' . $a . '|' . $b])) {
                $best = [$od[$line . '|' . $a . '|' . $b], 9];
            }
            return $best === null ? null : $best[0];
        };
        foreach ($routes as $r) {
            $seq = $r['seq'];
            $line = $r['line'];
            for ($i = 0; $i + 1 < count($seq); $i++) {   // 終點站沒有往終點方向的發車
                if (finderDbCovered($cov, $sys, $line, $seq, $i, $r['dir'])) {
                    continue;
                }
                $x = finderDbUID($sys, $seq[$i]);
                // 候選來源站：上游由近到遠，再下游由近到遠
                $cands = [];
                for ($j = $i - 1; $j >= 0; $j--) {
                    $cands[] = $j;
                }
                for ($j = $i + 1; $j + 1 < count($seq); $j++) {
                    $cands[] = $j;
                }
                foreach ($cands as $j) {
                    if (!finderDbCovered($real, $sys, $line, $seq, $j, $r['dir'])) {
                        continue;
                    }
                    $y = finderDbUID($sys, $seq[$j]);
                    $sec = $j < $i ? $offset($line, $y, $x) : $offset($line, $x, $y);
                    if ($sec === null) {
                        continue;
                    }
                    $shift = (int) round(($j < $i ? $sec : -$sec) / 60);
                    // 會經過 x 的班次：終點在本站序上時必須在 x（上游）／y（下游）之後；終點不在本站序上、或環狀線，看方向
                    // （TDX 站別時刻表與 StationOfRoute 的方向編號不一定一致，例如環狀線 LB，所以能用終點判斷時不看方向）
                    $after = array_slice($seq, max($i, $j) + 1);
                    $loop = $seq[0] === $seq[count($seq) - 1];
                    $groups = [];
                    foreach ($db->all("SELECT * FROM metro_group WHERE station_uid = ? AND source = 'timetable'", [$y]) as $g) {
                        $info = json_decode($g['info'], true);
                        if (($info['LineIDs'][0] ?? '') !== $line) {
                            continue;
                        }
                        $ending = (string) $info['EndingStationID'];
                        if (!$loop && in_array($ending, $seq, true) ? in_array($ending, $after, true) : (string) $g['direction'] === (string) $r['dir']) {
                            $groups[] = [$g, $info];
                        }
                    }
                    if (!$groups) {
                        continue;
                    }
                    foreach ($groups as list($g, $info)) {
                        $info['Note'] = sprintf('推算時刻：由 %s 站時刻表%s站間行駛時間 %d 秒平移 %+d 分',
                                                $seq[$j], $j < $i ? '加' : '減', $sec, $shift);
                        $info['ShiftedFromUID'] = $y;
                        $gid++;
                        $db->run('INSERT INTO metro_group (group_id, system, station_uid, direction, weekmask, stop_seq, info, simulated, source) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)',
                                 [$gid, $sys, $x, $g['direction'], $g['weekmask'], (string) ($i + 1), finderDbJson($info), 'shifted']);
                        foreach ($db->all('SELECT * FROM metro_dep WHERE group_id = ? ORDER BY sort_min', [$g['group_id']]) as $d) {
                            $m = (int) $d['sort_min'] + $shift;
                            $db->run('INSERT INTO metro_dep (group_id, arr, dep, sort_min, train_type_code, pattern_id) VALUES (?, ?, ?, ?, ?, ?)',
                                     [$gid, '', sprintf('%02d:%02d', intdiv($m % 1440, 60), $m % 60), $m, $d['train_type_code'], $d['pattern_id']]);
                        }
                        $cov['dir'][$line . '|' . $x . '|' . $g['direction']] = true;
                        $cov['end'][$line . '|' . $x][(string) $info['EndingStationID']] = true;
                    }
                    $GLOBALS['FINDERDB_TIMETABLE_FILLED'][] = $sys . ' ' . $seq[$i] . ' 方向' . $r['dir'] . '：shifted（' . $seq[$j] . ' ' . sprintf('%+d', $shift) . ' 分）';
                    break;
                }
            }
        }
    }
}

/**
 * 班距模擬（最後一層，derived）：某站某方向既沒有真實時刻表、也無法平移時，比照接軌時刻的做法排出模擬時刻表，
 * 存進 metro_group（simulated = 1、source = simulated）／metro_dep：
 *   每一站、每個終點從 FirstLastTimetable 的首班車開始，依 Frequency 該時段班距的平均值（(最小 + 最大) ÷ 2）
 *   往後排，排到末班車為止（最後一班固定為末班車時間）。依星期分別排，排出來相同的星期合併成一組。
 * 目前用在臺北捷運文湖線、臺中捷運（整條路線都沒有站別時刻表）。
 */
function finderDbBuildMetroSim(SqliteDao $db)
{
    $gid = (int) $db->value('SELECT COALESCE(MAX(group_id), 0) FROM metro_group');
    foreach (FINDERDB_SIM_SYSTEMS as $sys) {
        $cov = finderDbMetroCoverage($db, $sys);
        $routes = [];
        foreach (finderDbLoad('/v2/Rail/Metro/StationOfRoute/' . $sys) as $r) {
            $routes[] = ['RouteID' => $r['RouteID'], 'LineID' => $r['LineID'], 'Direction' => $r['Direction'] ?? '',
                         'Stations' => array_map(function ($s) { return $s['StationID']; }, $r['Stations'])];
        }
        $freqs = finderDbLoad('/v2/Rail/Metro/Frequency/' . $sys);
        foreach (finderDbLoad('/v2/Rail/Metro/FirstLastTimetable/' . $sys) as $x) {
            $line = (string) $x['LineID'];
            $sid = (string) $x['StationID'];
            $dest = (string) ($x['DestinationStaionID'] ?? ($x['DestinationStationID'] ?? ''));
            $found = finderDbFindRoute($routes, '', $line, '', $sid, $dest);
            // 已有真實或平移時刻：同方向，或同終點
            $uid = finderDbUID($sys, $sid);
            if (($found && isset($cov['dir'][$line . '|' . $uid . '|' . finderDbStr($found[0]['Direction'])]))
                || isset($cov['end'][$line . '|' . $uid][$dest])) {
                continue;
            }
            $routeID = $found ? (string) $found[0]['RouteID'] : '';
            $first = finderDbMinutes((string) $x['FirstTrainTime']);
            $last = finderDbMinutes((string) $x['LastTrainTime']);
            if ($first === null || $last === null) {
                continue;
            }
            $first += $first < 240 ? 1440 : 0;
            $last += $last < 240 ? 1440 : 0;

            // 依星期排時刻；相同的合併
            $byList = [];
            foreach (FINDERDB_WEEKDAYS as $w => $day) {
                if (empty($x['ServiceDay'][$day])) {
                    continue;
                }
                $freq = null;
                foreach ($freqs as $f) {
                    if ($f['LineID'] === $line && ($routeID === '' || $f['RouteID'] === $routeID) && !empty($f['ServiceDay'][$day])) {
                        $freq = $f;
                        break;
                    }
                }
                if ($freq === null) {
                    continue;
                }
                $times = finderDbSimTimes($first, $last, $freq['Headways'] ?? []);
                $k = implode(',', $times);
                if (!isset($byList[$k])) {
                    $byList[$k] = ['mask' => 0, 'times' => $times, 'tag' => $freq['ServiceDay']['ServiceTag'] ?? ''];
                }
                $byList[$k]['mask'] |= 1 << $w;
            }
            foreach ($byList as $g) {
                $info = finderDbTrainTemplate();
                $info['System'] = $sys;
                $info['Direction'] = $found ? finderDbStr($found[0]['Direction']) : '';
                $start = $found ? $found[0]['Stations'][0] : '';
                $info['StartingStationUID'] = finderDbUID($sys, $start);
                $info['StartingStationID'] = $start;
                $info['EndingStationUID'] = finderDbUID($sys, $dest);
                $info['EndingStationID'] = $dest;
                $info['LineIDs'] = [$line];
                $info['RouteID'] = $routeID;
                $info['TripHeadSign'] = (string) ($x['TripHeadSign'] ?? '');
                $info['ServiceTag'] = $g['tag'];
                $info['Note'] = '模擬時刻：首班車起依時段平均班距排出';
                $gid++;
                $db->run('INSERT INTO metro_group (group_id, system, station_uid, direction, weekmask, stop_seq, info, simulated, source) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)', [
                    $gid, $sys, $uid, (string) $info['Direction'], $g['mask'],
                    $found ? (string) ($found[1] + 1) : '', finderDbJson($info), 'simulated',
                ]);
                foreach ($g['times'] as $m) {
                    $db->run('INSERT INTO metro_dep (group_id, arr, dep, sort_min, train_type_code, pattern_id) VALUES (?, ?, ?, ?, ?, ?)',
                             [$gid, '', sprintf('%02d:%02d', intdiv($m % 1440, 60), $m % 60), $m, '', '']);
                }
            }
        }
    }
}

/** 從首班排到末班：每班加上該時刻所在時段的平均班距；最後一班固定為末班車。回傳分鐘（04:00 前 +1440）的陣列 */
function finderDbSimTimes($first, $last, array $headways)
{
    $slots = [];
    foreach ($headways as $h) {
        $s = finderDbMinutes((string) $h['StartTime']);
        $e = finderDbMinutes((string) $h['EndTime']);
        if ($s === null || $e === null) {
            continue;
        }
        $s += $s < 240 ? 1440 : 0;
        $e += $e <= 240 ? 1440 : 0;
        $slots[] = [$s, $e, ((float) $h['MinHeadwayMins'] + (float) $h['MaxHeadwayMins']) / 2];
    }
    usort($slots, function ($a, $b) { return $a[0] <=> $b[0]; });
    $avg = function ($t) use ($slots) {
        $prev = null;
        foreach ($slots as $s) {
            if ($t >= $s[0] && $t < $s[1]) {
                return $s[2];
            }
            if ($s[0] <= $t) {
                $prev = $s[2];
            }
        }
        return $prev ?? ($slots ? $slots[0][2] : 10);
    };
    $times = [];
    $t = (float) $first;
    while ($t <= $last + 0.001) {
        $m = (int) round($t);
        if (!$times || $m > end($times)) {
            $times[] = $m;
        }
        $h = $avg($t);
        if ($h <= 0) {
            break;
        }
        $t += $h;
    }
    if (end($times) < $last) {
        $times[] = $last;
    }
    return $times;
}

function finderDbBuildMetro(SqliteDao $db)
{
    foreach (finderDbLoad('/v2/Rail/Metro/StoppingPattern/TYMC')['StoppingPatterns'] ?? [] as $p) {
        $db->run('INSERT OR REPLACE INTO pattern (system, pattern_id, name) VALUES (?, ?, ?)',
                 ['TYMC', $p['StoppingPatternID'], finderDbJson(finderDbName($p['StoppingPatternName'] ?? null))]);
    }
    $gid = 0;
    foreach (FINDERDB_METRO_SYSTEMS as $sys) {
        if (!finderDbHasStationTimetable($sys)) {
            continue;
        }
        $routes = [];
        foreach (finderDbLoad('/v2/Rail/Metro/StationOfRoute/' . $sys) as $r) {
            $routes[] = ['RouteID' => $r['RouteID'], 'LineID' => $r['LineID'], 'Direction' => $r['Direction'] ?? '',
                         'Stations' => array_map(function ($s) { return $s['StationID']; }, $r['Stations'])];
        }
        $data = finderDbLoad('/v2/Rail/Metro/StationTimeTable/' . $sys);
        // 逐筆取出後立刻從原陣列移除，降低尖峰記憶體
        foreach (array_keys($data) as $idx) {
            $x = $data[$idx];
            unset($data[$idx]);
            $sid = $x['StationID'];
            $dest = $x['DestinationStaionID'] ?? ($x['DestinationStationID'] ?? '');
            $dir = array_key_exists('Direction', $x) ? $x['Direction'] : '';
            $found = finderDbFindRoute($routes, $x['RouteID'] ?? '', $x['LineID'] ?? '', $dir, $sid, $dest);
            if ($dir === '' && $found) {
                $dir = $found[0]['Direction'];
            }
            $start = ($found && $found[2]) ? $found[0]['Stations'][0] : '';
            $info = finderDbTrainTemplate();
            $info['System'] = $sys;
            $info['Direction'] = finderDbStr($dir);
            $info['StartingStationUID'] = finderDbUID($sys, $start);
            $info['StartingStationID'] = $start;
            $info['EndingStationUID'] = finderDbUID($sys, $dest);
            $info['EndingStationID'] = $dest;
            $info['LineIDs'] = [$x['LineID']];
            $info['RouteID'] = ($x['RouteID'] ?? '') !== '' ? $x['RouteID'] : (($found && $found[2]) ? $found[0]['RouteID'] : '');
            $info['ServiceTag'] = $x['ServiceDay']['ServiceTag'] ?? '';
            $gid++;
            $db->run('INSERT INTO metro_group (group_id, system, station_uid, direction, weekmask, stop_seq, info, simulated, source) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)', [
                $gid, $sys, finderDbUID($sys, $sid), (string) $info['Direction'], finderDbWeekmask($x['ServiceDay'] ?? []),
                $found ? (string) ($found[1] + 1) : '', finderDbJson($info), 'timetable',
            ]);
            foreach ($x['Timetables'] as $t) {
                $arr = (string) ($t['ArrivalTime'] ?? '');
                $dep = (string) ($t['DepartureTime'] ?? '');
                $m = finderDbMinutes($dep !== '' ? $dep : $arr);
                if ($m !== null && $m < 240) {
                    $m += 1440;   // 營運日從 04:00 起算
                }
                $tt = (int) ($t['TrainType'] ?? 0);
                $db->run('INSERT INTO metro_dep (group_id, arr, dep, sort_min, train_type_code, pattern_id) VALUES (?, ?, ?, ?, ?, ?)',
                         [$gid, $arr, $dep, $m, $tt === 0 ? '' : (string) $tt, (string) ($t['StoppingPatternID'] ?? '')]);
            }
        }
        unset($data);
    }
}

// ================================================================
// /txapi/admin_download_to_sqlite（路由權限 admin_download_to_sqlite）

function actionAdminDownloadToSqlite()
{
    header('Cache-Control: no-store');
    $func = strtolower(trim((string) ($_GET['func'] ?? 'status')));
    if ($func === 'status' || $func === '') {
        adminRequire('admin_download_to_sqlite', 'read');
        sendJson(200, ['ok' => true, 'action' => 'admin_download_to_sqlite', 'func' => 'status', 'status' => finderDbStatus(null, true)]);
    }
    if ($func !== 'build') {
        sendJson(400, ['ok' => false, 'error' => 'Unknown func: ' . $func, 'funcs' => ['status', 'build']]);
    }
    adminRequire('admin_download_to_sqlite', 'write');
    try {
        $report = SqliteDao::lock(FINDERDB_NAME, function () {
            return finderDbBuild();
        });
    } catch (FinderDbIncomplete $e) {
        sendJson(409, ['ok' => false, 'action' => 'admin_download_to_sqlite', 'error' => $e->getMessage(),
                       'missing' => $e->missing, 'hint' => 'Run /txapi/admin_download/sync?service=tdx until done, then retry']);
    } catch (Throwable $e) {
        sendJson(500, ['ok' => false, 'action' => 'admin_download_to_sqlite', 'error' => $e->getMessage()]);
    }
    sendJson(200, ['ok' => true, 'action' => 'admin_download_to_sqlite', 'func' => 'build', 'rebuilt' => $report, 'status' => finderDbStatus(null, true),
                   'next' => '資料庫重建後轉乘加速清單會過期（預設改為不套用）：轉乘資料有變時先跑 /txapi/admin_transfer/build_groups，再重複呼叫 /txapi/admin_transfer/learn?reset=1（之後不帶 reset）直到 done=true，見 agent/skills/rail-transfer-learn']);
}

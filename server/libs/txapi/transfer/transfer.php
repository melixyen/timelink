<?php
/**
 * /txapi/transfer：軌道轉乘（轉乘群組、路網結構搜尋、時刻表轉乘查詢）。
 *
 *   func=help（預設）  說明文件（Markdown）
 *   func=groups        轉乘群組（完整 JSON；可用 id、station 篩選，raw=1 直接下載檔案）
 *   func=lines         路線（站序與經過的轉乘群組）與路線連結
 *   func=network       路網結構搜尋：A→B 可走的路線串與轉乘站（不看時刻表）
 *   func=route         時刻表轉乘查詢：直通解、最短解、最快解、最佳解、最少轉乘
 *   func=follow        固定路由組合（route 方案的 Pattern）的前後班次
 *   func=stations      車站搜尋（站名、英文、拼音、站碼模糊比對）
 *   （管理）/txapi/admin_transfer：build_groups、learn、history、restore（後臺權限）
 *
 * 程式：groups.php（產生器、讀取）、network.php（路網結構）、router.php（時刻表搜尋）；
 * 資料：libs/txapi/transfer/data/（計算核心，受版控：settings、seed、accel、漢字字典，以及產生的群組、連結紀錄、里程、加速清單）；
 *       加速清單的分析進度暫存在 download/tdx_proxy_files/transfer/。
 */

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/groups.php';
require_once __DIR__ . '/network.php';
require_once __DIR__ . '/router.php';
require_once __DIR__ . '/distance.php';
require_once __DIR__ . '/learn.php';
require_once __DIR__ . '/follow.php';
require_once __DIR__ . '/search.php';

const TRANSFER_TABLES = ['station', 'line', 'line_station', 'train', 'train_stop', 'metro_group', 'metro_dep', 'metro_travel', 'metro_travel_od'];

function actionTransfer()
{
    $func = strtolower(trim((string) ($_GET['func'] ?? '')));
    header('Cache-Control: no-store');
    if ($func === '' || $func === 'help') {
        transferSendHelp();
    }
    if ($func === 'groups') {
        transferFuncGroups();
    }
    if ($func === 'lines') {
        $g = transferLoadGroups() ?: transferMissingGroups();
        sendJson(200, ['ok' => true, 'action' => 'transfer', 'func' => 'lines', 'GeneratedAt' => $g['GeneratedAt'],
                       'count' => count($g['Lines']), 'Lines' => $g['Lines'], 'LineLinks' => $g['LineLinks']]);
    }
    if ($func === 'accel') {
        transferFuncAccel();
    }
    if ($func === 'follow') {
        transferFuncFollow();
    }
    if ($func === 'stations') {
        transferFuncStations();
    }
    if (!in_array($func, ['network', 'route'], true)) {
        sendJson(400, ['ok' => false, 'error' => 'Unknown func: ' . $func,
                       'funcs' => ['help', 'groups', 'lines', 'network', 'route', 'follow', 'stations', 'accel'],
                       'admin' => '/txapi/admin_transfer（build_groups、learn、history、restore）', 'help' => '/txapi/transfer']);
    }
    transferLoadGroups() ?: transferMissingGroups();
    try {
        $ensure = finderDbEnsure(TRANSFER_TABLES);
        $db = SqliteDao::open(FINDERDB_NAME, ['readonly' => true]);
    } catch (FinderDbIncomplete $e) {
        sendJson(503, ['ok' => false, 'error' => 'Download data is incomplete; finder database cannot be built', 'missing' => $e->missing]);
    } catch (Throwable $e) {
        sendJson(500, ['ok' => false, 'error' => 'Finder database error: ' . $e->getMessage()]);
    }
    $station = function ($name) use ($db) {
        $v = trim((string) ($_GET[$name] ?? ''));
        $p = $v === '' ? null : finderParseUID($v);
        if ($p === null) {
            sendJson(400, ['ok' => false, 'error' => $name . ' is required and must be a StationUID, e.g. TRA-0900']);
        }
        $row = finderStation($db, $p[0], $p[1]);
        if (!$row) {
            sendJson(404, ['ok' => false, 'error' => 'Station not found: ' . $v]);
        }
        $m = transferNetworkModel($db);
        return ['StationUID' => $row['uid'], 'StationName' => is_array($row['name']) ? $row['name'] : json_decode($row['name'], true),
                'TransferGroups' => $m['groupOf'][$row['uid']] ?? []];
    };
    $from = $station('from');
    $to = $station('to');
    if ($from['StationUID'] === $to['StationUID']) {
        sendJson(400, ['ok' => false, 'error' => 'from and to are the same station']);
    }
    $t0 = microtime(true);
    $out = ['ok' => true, 'action' => 'transfer', 'func' => $func, 'from' => $from, 'to' => $to];
    if ($func === 'network') {
        $extra = max(0, min(2, (int) ($_GET['extra'] ?? 1)));
        $limit = max(1, min(50, (int) ($_GET['limit'] ?? 20)));
        $r = transferNetworkSearch($db, $from['StationUID'], $to['StationUID'], $extra, $limit);
        $out += ['method' => transferNetworkMethod(), 'count' => count($r['options']), 'linePaths' => $r['linePaths'],
                 'seconds' => round(microtime(true) - $t0, 3), 'options' => $r['options']];
        $db->close();
        sendJson(200, $out);
    }
    // func=route
    list($week, $date) = finderWeek(true);
    $start = finderTimeParam('start');
    if ($start === null) {
        sendJson(400, ['ok' => false, 'error' => 'start is required (HH:MM on the service day; after midnight write 24:30, see 時間的定義 in /txapi/finder)']);
    }
    $end = finderTimeParam('end');
    // 出發時間範圍最長 QUERY_MAX_RANGE_MINUTES（3 小時）：end − start 超過回 40001；沒有 end 時最多搜到 start＋上限。帶管理密碼不限
    $maxRange = transferMaxRangeMinutes();
    if ($maxRange !== null && $end !== null && $end - $start > $maxRange) {
        appError(40001, ['max' => $maxRange, 'range' => $end - $start]);
    }
    $endEff = $end ?? ($maxRange !== null ? $start + $maxRange : null);
    // 有 end 時一路搜尋到 end 為止；沒有 end 時最多 12 次（也不超過 start＋上限）
    $runs = $end === null ? max(1, min(12, (int) ($_GET['runs'] ?? 6))) : max(1, min(600, (int) ($_GET['runs'] ?? 600)));
    $r = transferRoute($db, $from['StationUID'], $to['StationUID'], $week, $start * 60, $runs, $endEff === null ? null : $endEff * 60,
                       TRANSFER_ROUTE_BUDGET, ($_GET['jump'] ?? '1') !== '0', ($_GET['prune'] ?? '1') !== '0',   // jump=0、prune=0：關掉優化（比對用）
                       isset($_GET['accel']) ? $_GET['accel'] === '1' : null,    // accel=1 套用、accel=0 不套用、省略＝有算好未過期的清單才套用
                       ($_GET['stops'] ?? '1') !== '0');                         // stops=0：不附途中各站時刻
    $net = transferNetworkSearch($db, $from['StationUID'], $to['StationUID'], 0, 3);
    $picks = [];
    foreach ($r['picks'] as $k => $i) {
        $picks[$k] = $i;
    }
    $out += ['week' => $week, 'weekday' => FINDER_WEEKDAYS[$week], 'date' => $date, 'start' => $_GET['start'], 'end' => $_GET['end'] ?? null,
             'searchUntil' => $endEff === null ? null : transferHM($endEff * 60), 'maxRangeMinutes' => $maxRange,
             'runs' => $r['runs'], 'jumps' => $r['jumps'], 'backward' => $r['backward'], 'accel' => $r['accel'], 'stop' => $r['stop'], 'method' => transferRouteMethod(), 'seconds' => round(microtime(true) - $t0, 3),
             'picks' => $picks, 'tags' => TRANSFER_TAGS, 'count' => count($r['options'])];
    if (!$r['options']) {
        $out['note'] = '出發後 ' . (TRANSFER_HORIZON / 3600) . ' 小時內找不到可到達的方案（或最晚出發 end 之前沒有班次）';
        foreach (['from' => '起站', 'to' => '迄站'] as $k => $label) {
            if (!$r['served'][$k]) {
                $out['note'] = $label . '這一天沒有任何班次停靠（時刻表內沒有這一站）';
            }
        }
    }
    $out['network'] = ['Shortest' => $net['options'][0] ?? null, 'options' => $net['options']];
    $out['options'] = $r['options'];
    $db->close();
    sendJson(200, $out);
}

/** 開啟 finder 資料庫（轉乘用的資料表）；失敗直接回錯誤 */
function transferOpenDb()
{
    try {
        finderDbEnsure(TRANSFER_TABLES);
        return SqliteDao::open(FINDERDB_NAME, ['readonly' => true]);
    } catch (FinderDbIncomplete $e) {
        sendJson(503, ['ok' => false, 'error' => 'Download data is incomplete; finder database cannot be built', 'missing' => $e->missing]);
    } catch (Throwable $e) {
        sendJson(500, ['ok' => false, 'error' => 'Finder database error: ' . $e->getMessage()]);
    }
}

/**
 * func=follow：固定路由組合的前後班次（follow.php）。pattern 用 GET 參數（JSON 字串）或 POST 本文（{"pattern": [...], ...} 或直接陣列）
 */
function transferFuncFollow()
{
    $body = $_SERVER['REQUEST_METHOD'] === 'POST' ? json_decode((string) file_get_contents('php://input'), true) : null;
    $param = function ($k) use ($body) {
        if (is_array($body) && array_key_exists($k, $body) && !array_is_list($body)) {
            return $body[$k];
        }
        return $_GET[$k] ?? null;
    };
    $pattern = is_array($body) && array_is_list($body) ? $body : $param('pattern');
    if (is_string($pattern)) {
        $pattern = json_decode($pattern, true);
    }
    if (!is_array($pattern) || !array_is_list($pattern)) {
        sendJson(400, ['ok' => false, 'error' => 'pattern is required: a JSON array of {Type: Origin|Line|Transfer|Destination, ...} (func=route options[].Pattern)']);
    }
    // 其他參數 POST 本文有就用本文的（time、week…），同時讓 finderWeek／finderTimeParam 讀得到
    foreach (['week', 'date', 'time', 'start', 'end', 'before', 'after', 'prev', 'next', 'all', 'walk_factor', 'stops'] as $k) {
        $v = $param($k);
        if ($v !== null && !is_array($v)) {
            $_GET[$k] = (string) $v;
        }
    }
    list($week, $date) = finderWeek(true);
    $time = finderTimeParam('time') ?? finderTimeParam('start');
    if ($time === null) {
        sendJson(400, ['ok' => false, 'error' => 'time is required (HH:MM on the service day; after midnight write 24:30)']);
    }
    $end = finderTimeParam('end');
    $num = function ($k, $max) {
        $v = $_GET[$k] ?? '';
        if ($v === '') {
            return null;
        }
        if (!preg_match('/^\d+$/', (string) $v)) {
            sendJson(400, ['ok' => false, 'error' => $k . ' must be a non-negative integer']);
        }
        return min($max, (int) $v);
    };
    $sel = ['time' => $time * 60, 'before' => $num('before', 1440), 'after' => $num('after', 1440),
            'prev' => $num('prev', 100) ?? 0, 'next' => $num('next', 100), 'all' => !empty($_GET['all']), 'stops' => ($_GET['stops'] ?? '1') !== '0'];
    if ($end !== null && $sel['after'] === null) {
        $sel['after'] = max(0, $end - $time);   // start＋end＝出發時間範圍
    }
    // 時間範圍最長 QUERY_MAX_RANGE_MINUTES（3 小時）；有後臺身分（登入、PAT）不限
    $maxRange = transferMaxRangeMinutes();
    if ($maxRange !== null) {
        if ($sel['all']) {
            appError(40003, ['max' => $maxRange]);
        }
        if ($end !== null && $end - $time > $maxRange) {
            appError(40001, ['max' => $maxRange, 'range' => $end - $time]);
        }
        if (($sel['before'] ?? 0) + ($sel['after'] ?? 0) > $maxRange) {
            appError(40002, ['max' => $maxRange, 'range' => ($sel['before'] ?? 0) + ($sel['after'] ?? 0)]);
        }
        $sel['maxSpan'] = $maxRange * 60;   // 用班數（prev／next）時，回傳的出發時間跨度也不超過上限
    }
    if ($sel['next'] === null) {
        $sel['next'] = $sel['before'] === null && $sel['prev'] === 0 && $sel['after'] === null ? 5 : 0;
    }
    $wf = (float) ($_GET['walk_factor'] ?? 1);
    if ($wf < 0.3 || $wf > 5) {
        sendJson(400, ['ok' => false, 'error' => 'walk_factor must be between 0.3 and 5']);
    }
    transferLoadGroups() ?: transferMissingGroups();
    $db = transferOpenDb();
    $t0 = microtime(true);
    try {
        $r = transferFollow($db, $week, $pattern, $sel, $wf);
    } catch (InvalidArgumentException $e) {
        sendJson(400, ['ok' => false, 'error' => $e->getMessage()]);
    }
    $db->close();
    sendJson(200, ['ok' => true, 'action' => 'transfer', 'func' => 'follow', 'week' => $week, 'weekday' => FINDER_WEEKDAYS[$week], 'date' => $date,
                   'time' => transferHM($time * 60), 'walk_factor' => $wf, 'seconds' => round(microtime(true) - $t0, 3),
                   'count' => count($r['options'])] + $r);
}

/** 出發時間範圍上限（分鐘）；有後臺身分（面板登入或 PAT）時不限（null） */
function transferMaxRangeMinutes()
{
    if (downloadAdminOk()) {
        return null;
    }
    $m = queryLimitSettings()['range'];
    return $m > 0 ? $m : null;
}

function transferMissingGroups()
{
    sendJson(503, ['ok' => false, 'error' => 'transfer_groups.json is missing; run /txapi/admin_transfer/build_groups']);
}

function transferFuncGroups()
{
    if (!empty($_GET['raw'])) {
        if (!is_file(TRANSFER_GROUPS_FILE)) {
            transferMissingGroups();
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: inline; filename="transfer_groups.json"');
        readfile(TRANSFER_GROUPS_FILE);
        exit;
    }
    $g = transferLoadGroups() ?: transferMissingGroups();
    $list = $g['TransferGroups'];
    $id = trim((string) ($_GET['id'] ?? ''));
    $st = strtoupper(trim((string) ($_GET['station'] ?? '')));
    $type = trim((string) ($_GET['type'] ?? ''));
    if ($id !== '' || $st !== '' || $type !== '') {
        $list = array_values(array_filter($list, function ($x) use ($id, $st, $type) {
            if ($id !== '' && strcasecmp($x['TransferGroupID'], $id) !== 0) {
                return false;
            }
            if ($type !== '' && $x['Type'] !== $type) {
                return false;
            }
            if ($st !== '' && !in_array($st, array_column($x['Stations'], 'StationUID'), true)) {
                return false;
            }
            return true;
        }));
    }
    // 轉乘實景影片（transfer_seed.json 的 Videos，查詢時加上；raw=1 的檔案沒有）
    foreach ($list as &$grp) {
        foreach ($grp['Transfers'] as &$t) {
            if ($vid = transferVideos($t['FromStationUID'], $t['ToStationUID'])) {
                $t['Videos'] = $vid;
            }
        }
        unset($t);
    }
    unset($grp);
    $out = ['ok' => true, 'action' => 'transfer', 'func' => 'groups', 'Version' => $g['Version'], 'GeneratedAt' => $g['GeneratedAt'],
            'Description' => $g['Description'], 'Counts' => $g['Counts'], 'count' => count($list), 'download' => '/txapi/transfer/groups?raw=1',
            'TransferGroups' => $list];
    sendJson(200, $out);
}

function transferFuncBuild()
{
    try {
        finderDbEnsure(['station', 'line', 'line_station', 'train', 'train_stop']);
        $g = transferBuildGroups();
    } catch (FinderDbIncomplete $e) {
        sendJson(503, ['ok' => false, 'error' => 'Download data is incomplete', 'missing' => $e->missing]);
    } catch (Throwable $e) {
        sendJson(500, ['ok' => false, 'error' => $e->getMessage()]);
    }
    $old = transferLoadGroups();
    $diff = ['added' => [], 'removed' => [], 'changed' => []];
    $oldMap = [];
    foreach ($old['TransferGroups'] ?? [] as $x) {
        $oldMap[$x['TransferGroupID']] = $x;
    }
    foreach ($g['TransferGroups'] as $x) {
        $id = $x['TransferGroupID'];
        if (!isset($oldMap[$id])) {
            $diff['added'][] = $id;
        } elseif (json_encode([$x['Stations'], $x['Transfers']]) !== json_encode([$oldMap[$id]['Stations'], $oldMap[$id]['Transfers']])) {
            $diff['changed'][] = $id;
        }
        unset($oldMap[$id]);
    }
    $diff['removed'] = array_keys($oldMap);
    // 內容沒變就不重寫檔案（產生時間不同不算變），加速清單的輸入雜湊才不會無故改變而觸發重算
    $same = function ($a, $b) {
        unset($a['GeneratedAt'], $b['GeneratedAt']);
        return json_encode($a) === json_encode($b);
    };
    $links = $g['_links'];
    unset($g['_links']);
    $saved = ['groups' => false, 'distance' => false, 'links' => false];
    if (empty($_GET['preview'])) {
        // 連結紀錄檔：有新增或排除才重寫（LastSeenAt／Detected 的變化不必重寫）
        if ($links['Diff']['added'] || $links['Diff']['excluded'] || !is_file(transferDataFile('Links'))) {
            $saved['links'] = transferWriteGenerated('Links', json_encode([
                'Version' => 1, 'UpdatedAt' => downloadFmtTime(time()),
                'Description' => '自動偵測到的轉乘連結（TDX 轉乘資料、座標距離），由 func=build_groups 維護：只會新增，不會自動移除。'
                               . 'Kind=group 兩站併入同一轉乘群組；Kind=pair 兩站不在同一群組時另成兩站的群組。Detected=false 表示最近一次沒再偵測到（供人工檢查）。'
                               . '要移除：寫進 transfer_seed.json 的 ExcludePairs，下次 build_groups 會刪掉並不再加入。',
                'Links' => $links['Links'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        }
        if (!$old || !$same($g, $old)) {
            $saved['groups'] = transferWriteGenerated('Groups', json_encode($g, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        }
        // 里程資料一起重新產生
        $dist = transferBuildDistance();
        $oldDist = is_file(TRANSFER_DISTANCE_FILE) ? json_decode((string) file_get_contents(TRANSFER_DISTANCE_FILE), true) : null;
        if (!$oldDist || !$same($dist, $oldDist)) {
            $saved['distance'] = transferWriteGenerated('Distance', json_encode($dist, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }
    sendJson(200, ['ok' => true, 'action' => 'admin_transfer', 'func' => 'build_groups', 'saved' => $saved,
                   'unchanged' => !$saved['groups'] && !$saved['distance'] && !$saved['links'] && empty($_GET['preview']), 'Counts' => $g['Counts'],
                   'links' => ['total' => count($links['Links']), 'added' => $links['Diff']['added'], 'excluded' => $links['Diff']['excluded'],
                               'notDetected' => $links['Diff']['notDetected']],
                   'Unresolved' => $g['Unresolved'], 'diff' => $diff] + (empty($_GET['preview']) ? [] : ['groups' => $g]));
}

/**
 * /txapi/admin_transfer/learn：離線再演算，從時刻表算出推薦換車站與實際使用的走廊（learn.php）。
 * 每次最多算 Learn.ChunkSeconds 秒並存進度，重複呼叫直到 done=true；reset=1 從頭算。
 * 清單未過期（資料庫與輸入都沒變）時直接回 done=true、skipped=true；force=1 照樣重算
 */
function transferFuncLearn()
{
    @set_time_limit(0);
    transferLoadGroups() ?: transferMissingGroups();
    $db = transferOpenDb();
    $sec = (float) transferAccelData()['Learn']['ChunkSeconds'];
    $r = transferLearnRun($db, $sec, !empty($_GET['reset']), !empty($_GET['force']));
    sendJson(200, ['ok' => true, 'action' => 'admin_transfer', 'func' => 'learn'] + $r
                  + ($r['done'] ? ['file' => 'custom/libs/txapi/transfer/data/' . transferSettings('Files.AccelLearned')] : ['next' => '再呼叫一次 func=learn 繼續']));
}

/**
 * /txapi/admin_transfer：轉乘資料的管理動作（路由權限 admin_transfer）
 *   func=build_groups  重新產生轉乘群組、連結紀錄、里程（寫入；preview=1 只回傳不存檔，讀取）
 *   func=learn         加速清單演算，重複呼叫直到 done（寫入）
 *   func=history       產生檔與舊版清單（讀取）
 *   func=restore       把產生檔換回舊版：file=Groups|Links|Distance|AccelLearned、version=history 裡的檔名（寫入）
 */
function actionAdminTransfer()
{
    header('Cache-Control: no-store');
    $func = strtolower(trim((string) ($_GET['func'] ?? '')));
    switch ($func) {
        case 'build_groups':
            adminRequire('admin_transfer', empty($_GET['preview']) ? 'write' : 'read');
            transferFuncBuild();
        case 'learn':
            adminRequire('admin_transfer', 'write');
            transferFuncLearn();
        case 'history':
            adminRequire('admin_transfer', 'read');
            $files = [];
            foreach (transferSettings('GeneratedFiles') as $k) {
                $f = transferDataFile($k);
                $files[$k] = ['file' => 'custom/libs/txapi/transfer/data/' . basename($f), 'exists' => is_file($f),
                              'bytes' => is_file($f) ? filesize($f) : null, 'saved_at' => is_file($f) ? (new DateTime('@' . filemtime($f)))->setTimezone(new DateTimeZone('Asia/Taipei'))->format('Y-m-d H:i:s') : null,
                              'history' => transferHistory($k)];
            }
            sendJson(200, ['ok' => true, 'action' => 'admin_transfer', 'func' => 'history', 'keep' => (int) TRANSFER_HISTORY_KEEP, 'files' => $files]);
        case 'restore':
            adminRequire('admin_transfer', 'write');
            $key = (string) adminParam('file', '');
            $ver = (string) adminParam('version', '');
            if (!in_array($key, transferSettings('GeneratedFiles'), true)) {
                appError(40010, ['detail' => 'file 要是 ' . implode('、', transferSettings('GeneratedFiles'))]);
            }
            if (!transferRestoreGenerated($key, $ver)) {
                appError(40401, ['what' => '舊版（' . $ver . '）']);
            }
            sendJson(200, ['ok' => true, 'action' => 'admin_transfer', 'func' => 'restore', 'file' => $key, 'version' => $ver,
                           'note' => '換回群組或加速清單後，加速清單會依輸入雜湊自動判斷是否過期', 'history' => transferHistory($key)]);
        default:
            adminRequire('admin_transfer', 'read');
            sendJson(400, ['ok' => false, 'error' => $func === '' ? 'Missing func' : 'Unknown func: ' . $func,
                           'funcs' => ['build_groups', 'learn', 'history', 'restore']]);
    }
}

/** func=accel：目前的加速清單（手寫輸入、算出來的結果、是否過期） */
function transferFuncAccel()
{
    $db = transferOpenDb();
    $d = ['db' => $db];
    $l = transferAccelLearned($d);
    $state = is_file(TRANSFER_LEARN_STATE_FILE) ? json_decode((string) file_get_contents(TRANSFER_LEARN_STATE_FILE), true) : null;
    sendJson(200, ['ok' => true, 'action' => 'transfer', 'func' => 'accel',
                   'default' => $l && $l['Fresh'] ? 'on（已算好且未過期）' : 'off（' . ($l ? '已過期（資料庫重建過，或轉乘群組、transfer_accel.json 改過），請重跑 func=learn' : '還沒算過，請跑 func=learn') . '）',
                   'learned' => $l, 'learning' => $state ? ['progress' => $state['Next'] . '/' . count($state['Tasks']), 'seconds' => round($state['Seconds'], 1)] : null,
                   'input' => transferAccelData(), 'generatedFiles' => transferGeneratedFiles()]);
}

/**
 * 產生檔（custom/libs/txapi/transfer/data/）：路徑、sha256、修改時間。給 agent 的 pipeline 比對前後差異（舊版在 history/）
 */
function transferGeneratedFiles()
{
    $out = [];
    foreach (transferSettings('GeneratedFiles') as $k) {
        $f = transferDataFile($k);
        $out[$k] = ['file' => 'custom/libs/txapi/transfer/data/' . basename($f), 'sha256' => is_file($f) ? hash_file('sha256', $f) : null,
                    'mtime' => is_file($f) ? date('c', filemtime($f)) : null];
    }
    return $out;
}

function transferNetworkMethod()
{
    return '路線連結圖（轉乘群組連起來的路線）上找轉乘次數最少的路線串（extra＝再多找幾次轉乘），展開每對相鄰路線的轉乘群組組合；'
         . '每段以捷運站間時間、臺鐵與高鐵最快一班、或站數×2 分估算，加上步行或同站換車分鐘；依轉乘次數、估算時間排序。不看時刻表';
}

function transferRouteMethod()
{
    return '逐輪展開（RAPTOR）：第 k 輪＝搭第 k 班車。臺鐵、高鐵用每班車的真實到離站時刻；有真實站別時刻表的捷運、輕軌先把各站時刻表串成整列車（每站對到時刻表，列車在終點站結束），'
         . '只有模擬時刻的路線（文湖線、臺中捷運）用上車站時刻加站間行駛時間（兩站間有直接資料用直接資料，否則一站一站加起來）；'
         . '轉乘只在同一轉乘群組內步行，或在可換車的車站（TrainChange）同站換車。每輪迄站變早就得到一個方案；從 start 起多次搜尋（每次從上一批最早發車之後），'
         . '留下互不支配（較晚出發、較早到達、轉乘較少）的方案；搜尋時用「到迄站的最短時間下界」與「已找到方案的到達時刻」剪掉一定比較慢的路，並用反向搜尋跳過只換到更晚接駁車的重複搜尋。每個方案的 Tags／TagBits 標出它具有的屬性（定義見 tags），picks 為各標籤的代表方案在 options 的索引';
}

function transferSendHelp()
{
    $host = $_SERVER['HTTP_HOST'] ?? SITE_HOST;
    $g = transferLoadGroups();
    $counts = $g ? sprintf('目前 %d 個群組（多站 %d、單站 %d），%d 個成員車站、%d 筆有向轉乘；產生時間 %s',
                           $g['Counts']['TransferGroups'], $g['Counts']['Multi'], $g['Counts']['Single'], $g['Counts']['Stations'],
                           $g['Counts']['Transfers'], $g['GeneratedAt']) : '尚未產生';
    $src = '';
    if ($g) {
        foreach ($g['Counts']['TransferSources'] as $k => $n) {
            $src .= "| `{$k}` | {$n} |\n";
        }
    }
    $penalty = TRANSFER_TRANSFER_PENALTY / 60;
    $walkW = TRANSFER_WALK_WEIGHT;
    $maxTrips = TRANSFER_MAX_TRIPS;
    $horizon = TRANSFER_HORIZON / 3600;
    $budget = TRANSFER_ROUTE_BUDGET;
    // 查詢限制：數值一律讀設定（custom/libs/config.json，沒寫的用 libs/config.php 預設值；queryLimitSettings），說明文件不寫死
    $ql = queryLimitSettings();
    $qMin = queryLimitText($ql['minute']);
    $qHour = queryLimitText($ql['hour']);
    $qRange = queryLimitText($ql['range'], 'minutes');
    $md = <<<MD
# /txapi/transfer：軌道轉乘

臺鐵、高鐵、各捷運與輕軌的轉乘查詢。資料全部來自離線的 `sqlite/finder.sqlite`（見 `/txapi/finder`）與轉乘群組檔，不呼叫 TDX。

| func | 用途 |
| --- | --- |
| `help` | 本說明（省略 func 時相同） |
| `groups` | 轉乘群組（TransferGroup）；`id=TRANSGRP_taipei`、`station=TRA-1000`、`type=multi／single` 篩選；`raw=1` 直接下載完整 JSON 檔 |
| `lines` | 路線（站序、經過的轉乘群組）與路線連結（LineLinks） |
| `network` | 路網結構搜尋：A→B 可走的路線串與轉乘站，不看時刻表 |
| `route` | 時刻表轉乘查詢：直通解、最短解、最快解、最佳解、最少轉乘 |
| `follow` | 固定路由組合的前後班次：把 route 方案的 `Pattern` 送回來，提早或延後任意分鐘、或前後幾班 |
| `stations` | 車站搜尋：`q=台北`（台／臺、簡繁、英文、漢語拼音、拼音首字母、站碼都可以），模糊比對前 10 筆 |
| `build_groups` | 重新產生轉乘群組（需密碼 `key`；`preview=1` 只回傳不存檔）；回應 `links.added`／`links.notDetected` 為新加入與沒再偵測到的連結 |
| `accel` | 目前的加速清單與是否過期 |
| `learn` | 由離線時刻表重算加速清單（需密碼 `key`，分段執行） |

## 轉乘群組（TransferGroup）

可以互相步行轉乘的一組車站（同營運單位或跨營運單位），以及可以在同一站換車的車站。{$counts}。

- **代碼**：`TRANSGRP_` ＋ 代表車站的英文站名（小寫、去掉 station），代表車站依 臺鐵 ＞ 高鐵 ＞ 捷運 ＞ 輕軌；
  英文站名是意譯時改用漢語拼音（`transfer_seed.json` 的 `IdNames`）；名稱重複時依同一順序加 `1`、`2`（例如臺鐵桃園 `TRANSGRP_taoyuan1`、高鐵桃園 `TRANSGRP_taoyuan2`）。
  例：臺北車站 `TRANSGRP_taipei`；高鐵臺中、臺鐵新烏日、臺中捷運高鐵臺中站 `TRANSGRP_xinwuri`
- **成員**一律為 TDX StationUID，**路線**為「系統-LineID」（`TRA-WL`、`TRTC-BL`、`THSR-HSRL`，等於 finder `line` 表的主鍵；
  拆成 System＋LineID 即可打 TDX，例如 `/v2/Rail/Metro/StationOfRoute/TRTC?\$filter=LineID eq 'BL'`）
- `Type`：`multi`＝兩站以上；`single`＝只有一站（同站換車：臺鐵快車停靠或列車起訖多的站、路線交會站、高鐵各站、機捷 A8、捷運支線分岔站）

| 欄位 | 說明 |
| --- | --- |
| `TransferGroupID`、`GroupName`、`Type` | 代碼、名稱（代表車站的站名）、multi／single |
| `Stations[]` | `StationUID`、`System`、`StationID`、`StationName`、`Lines`、`Position`（Lat、Lon）、`TrainChange`（同站換車：`Minutes`、`Source`、`Note`；不能換車為 null） |
| `Lines` | 群組內車站的所有路線 |
| `Bypass` | 排除記號：群組內的轉乘全部標記時為 true（查詢時不在這裡中途轉乘） |
| `Transfers[]` | 群組內每一對車站的**有向**步行：`FromStationUID`、`ToStationUID`、`Minutes`、`Source`、`IsOnSiteTransfer`、`DistanceMeters`、`Note`；有排除記號時另有 `Bypass`、`BypassAllowAtEnds`（起站或迄站就是其中一站時仍可步行）、`BypassNote` |
| `Sources` | 這個群組用到的資料來源 |

檔案最上層另有 `Lines[]`（`LineUID`、`System`、`LineID`、`LineName`、`LineColor`、`IsBranch`、`Stations` 站序、`TransferGroups` 依站序經過的群組）
與 `LineLinks[]`（`FromLineUID`、`ToLineUID`、`TransferGroups`：兩條路線可在哪些群組轉乘），以及 `Settings`、`Counts`、`Unresolved`。

**資料來源優先順序**（真實 → 推算）：

1. 成員：自動偵測（TDX 捷運 `LineTransfer`、`TransferStations`、`StationTransfer`、臺鐵 `LineTransfer`，以及座標距離：不同系統相距 300 公尺內、
   同名相距 450 公尺內）記在 `transfer_links.json`，**只增不減**（每次只比對有沒有新的可以加入）；加上 `transfer_seed.json` 的人工群組 `Groups`、
   人工兩站 `Pairs`（例如北門↔機捷台北車站）；要移除寫進 seed 的 `ExcludePairs`。兩站連結不在同一群組時另成兩站的群組。
   seed 的 `Bypass` 排除記號：保留群組資料，但查詢時不在這裡中途轉乘（例如萬華↔龍山寺，前後已有臺北、板橋可轉）；
   查詢時直接讀 seed，改了馬上生效（加速清單會自動停用到重跑 `func=learn`），群組檔裡的標記在下次 `build_groups` 更新
2. 步行分鐘：TDX `LineTransfer.TransferTime`／`MinTransferTime` → TDX `StationTransfer.MinTransferTime`（只有單向時反向沿用，標 `(反向)`）
   → railtime／rocptx 的 walkMinute → 座標距離推算 `round(4 + 公尺 ÷ 70)`，限 3～15 分（`estimate`）
3. 同站換車：TDX 臺鐵 `LineTransfer`（交會站 3 分）、捷運 `LineTransfer` 自己轉自己（七張、大橋頭、北投）→ railtime／rocptx 的臺鐵大站與機捷 A8、淡海 V09
   → 預設（臺鐵、高鐵 3 分、捷運 2 分）。臺鐵另依時刻表自動選站：每日快車（太魯閣、普悠瑪、自強、莒光）停靠 10 班以上，或列車起訖 10 班以上

目前轉乘分鐘的來源統計：

| 來源 | 筆數 |
| --- | --- |
{$src}
## func=network（路網結構）

```
GET https://{$host}/txapi/transfer/network?from=TRA-0900&to=TRTC-BR22
```

| 參數 | 說明 |
| --- | --- |
| `from`、`to` | StationUID |
| `extra` | 0～2，比最少轉乘多幾次的路線串也列入（預設 1） |
| `limit` | 最多幾個方案（預設 20） |

做法：路線為節點、兩條路線有共同轉乘群組就相連；起訖站本身的路線與同群組車站的路線（先步行）都可當起點、終點；
找轉乘次數最少的路線串，展開每對相鄰路線的轉乘站組合。例：基隆→東湖沒有共同轉乘站，路線串為
西部幹線 →（南港／臺北／松山…）→ 板南線 →（南港展覽館／忠孝復興）→ 文湖線。

回應 `options[]`：`Lines`、`TransferCount`、`EstimatedMinutes`、`Legs[]`（`LineUID`、上下車站、`Stops`、`EstimatedMinutes`、`EstimateSource`）、
`Transfers[]`（`Type` walk／train_change、`TransferGroupID`、`Minutes`、`Source`）；`linePaths` 為所有路線串。

## func=route（時刻表轉乘）

```
GET https://{$host}/txapi/transfer/route?from=TRA-0900&to=TRTC-BR22&week=1&start=08:00
GET https://{$host}/txapi/transfer/route?from=TRA-7000&to=TRA-3300&date=2026-10-19&start=08:00&end=10:00
```

| 參數 | 必填 | 說明 |
| --- | --- | --- |
| `from`、`to` | 是 | StationUID |
| `week`／`date` | 否 | 營運日（省略為今天，見 `/txapi/finder` 的「時間的定義」） |
| `start` | 是 | 最早出發 `HH:MM`（營運日時間，午夜後寫 24 時以後，例如 `24:30`） |
| `end` | 否 | 最晚出發；有帶時會一路搜尋到 end。**end − start 最長 {$qRange}**（`QUERY_MAX_RANGE_MINUTES`，超過回錯誤代碼 40001）；省略時只看搜尋次數，但最多搜到 start＋{$qRange}（回應的 `searchUntil`）。轉乘要等 30 分鐘以上的方案會改成最晚還趕得上的出發時間（到達、班數不變），所以少數方案的出發可能晚於 end |
| `runs` | 否 | 最多搜尋幾次（每次從上一批方案的最早發車之後再找）；沒帶 end 時 1～12、預設 6，有帶 end 時預設 600 |
| `accel` | 否 | 省略＝有算好且未過期的加速清單（`transfer_accel_learned.json`）就套用；`1`＝一律套用；`0`＝不套用（完整搜尋） |
| `stops` | 否 | `0`：不附途中各站時刻（`StopTimes`），回應較小 |

### 加速清單（由離線資料再演算產生）

參考 railtime 的轉乘大站與繞路排除，但不用手寫：離線資料更新後執行 `/txapi/admin_transfer/learn`，用全部時刻表跑完整搜尋，從最佳方案實際怎麼走算出：

- 推薦換車站（`LearnedHubs`）：最佳方案中實際用來同站換車的臺鐵車站。加速時臺鐵同站換車只在這些站，以及起訖站附近（最短行駛 40 分鐘內）的車站
- 實際使用的走廊（`EffectiveCorridors`）：車站分成北部 N、宜蘭與北迴 NE、中部 C、南部 S、南迴 SL、花東 E，人工定義起訖地區組合可經過的地區；
  最佳方案用到走廊外地區的比例達 `Learn.CorridorMinShare`（0.1%）就放寬，例如中部往南部，先北上新竹轉高鐵南下占 1.4%，所以 C↔S 放寬加入 N；
  低於門檻的維持排除，結果記下 `LossShare`（例如北部往南部繞花東只占 0.02%，維持排除）

資料庫重建後清單會過期（`Fresh=false`），預設改為不套用，直到重新執行 `/txapi/admin_transfer/learn`。驗證（2026-10）：73 組固定查詢、197 組隨機起訖，
套用與不套用的方案完全相同，伺服器時間少約 4～5%。

| action／func | 說明 |
| --- | --- |
| `/txapi/transfer/accel` | 目前的加速清單：手寫輸入、算出來的結果、是否過期、分析進度、產生檔的雜湊 |
| `/txapi/admin_transfer/build_groups` | 重新產生轉乘群組、連結紀錄、里程（後臺寫入權限；`preview=1` 只回傳不存檔，讀取權限） |
| `/txapi/admin_transfer/learn` | 重算加速清單（後臺寫入權限）。每次最多算 20 秒並存進度，重複呼叫直到 `done=true`；`reset=1` 從頭算。命令列可用 `php libs/txapi/transfer/learn.php --reset` |
| `/txapi/admin_transfer/history` | 產生檔與舊版清單（讀取權限） |
| `/txapi/admin_transfer/restore` | 把產生檔換回舊版：`file=Groups\|Links\|Distance\|AccelLearned`、`version=<history 的檔名>`（寫入權限） |

### 資料檔（PHP 與 Node.js 共用的 JSON）

人工維護的輸入在 `libs/txapi/transfer/data/`（隨程式佈署、受版控）。產生檔（群組、連結紀錄、里程、加速清單，`transfer_settings.json` 的 `GeneratedFiles`）
是用主機上的離線資料算的，放在 `custom/libs/txapi/transfer/data/`（不進版控、隨 custom 備份），每次改寫前舊版留在 `history/`
（保留 `TRANSFER_HISTORY_KEEP` 份），轉車出問題可用 `/txapi/admin_transfer/restore` 換回。
加速清單的分析進度 `transfer_learn_state.json`（`TempFiles`）暫存在 `download/tdx_proxy_files/transfer/`，完成後刪除。

| 檔案 | 內容 |
| --- | --- |
| `transfer_settings.json` | 演算法參數（最多班數、搜尋時限、推薦的轉乘與步行權重、長等候門檻）、方案標籤與 bit、里程規則（站碼別名、山線區段、TripLine）、轉乘群組讀哪些 TDX 檔案與站碼對應、車站搜尋設定（Search） |
| `transfer_seed.json` | 轉乘群組的人工補充：群組、步行分鐘、同站換車、ID 拼音、排除記號（Bypass）、轉乘實景影片（Videos） |
| `transfer_accel.json` | 加速清單的人工輸入：地區、地區間走廊、手寫備用換車站、加速與分析設定 |
| `transfer_accel_learned.json` | （custom）產生的加速清單（`/txapi/admin_transfer/learn`）：推薦換車站、實際使用的走廊、資料庫建置時間 |
| `transfer_groups.json` | （custom）產生的轉乘群組（`/txapi/admin_transfer/build_groups`） |
| `transfer_links.json` | （custom）自動偵測到的轉乘連結紀錄，只增不減（`build_groups`） |
| `transfer_distance.json` | （custom）產生的軌道里程（`build_groups`） |
| `hanzi_pinyin.json` | 車站搜尋的漢字字典：Big5 漢字拼音、異體與簡繁歸一（`agent/skills/rail-transfer/scripts/build_hanzi.php` 由 Unicode Unihan 產生） |

程式（`libs/txapi/transfer/*.php`）只讀這些檔，不寫死資料；改參數或清單只要改 JSON。

搜尋的優化（結果與不優化完全相同，只是少搜幾次、每次少展開）：

- 下界剪枝：先算出每一站到迄站「最少要花多久」（各站間全部班次中最短的行駛時間＋步行，不算等車），某站的時刻加上這個下界已經不比目前最早到達早，那條路一定比較慢，不展開
- 沿用上界：前幾次找到、出發不早於這次起點的方案仍然有效，用它們的到達時刻當上界，從第一班車就開始剪枝
- 反向跳過：同樣到達時刻、同樣班數的方案連續出現（例如高雄到新左營有很多班區間車與高雄捷運，都接同一班高鐵），就反向搜尋算出「最晚幾點出發還趕得上」，下一次直接從那裡搜
- 回應的 `runs` 是正向搜尋次數，`jumps` 是跳過的次數，`backward` 是反向搜尋次數；`jump=0`、`prune=0` 可關掉後兩項比對

回應的 `stop` 說明搜尋停在哪裡：`end`（已超過最晚出發，完整）、`none`（之後再也沒有可到達的方案）、`runs`（搜尋次數用完，後面可能還有）、`budget`（超過 {$budget} 秒，後面可能還有）。

做法（RAPTOR）：第 k 輪＝搭第 k 班車，最多 {$maxTrips} 班、出發後 {$horizon} 小時內。
臺鐵、高鐵用每班車的真實到離站時刻；捷運、輕軌用上車站的站別時刻表（真實、平移或模擬）加站間行駛時間（到站為推算）；
轉乘只能在同一轉乘群組內步行，或在有 `TrainChange` 的車站同站換車。臺鐵小站到小站會自動找出「區間車到大站轉快車」、
東西部互通（例如花蓮→臺中經臺北或南港轉高鐵）、捷運支線轉乘（新北投→小碧潭經北投、七張）。

回應：

- `options[]`：互不支配的方案（較晚出發、較早到達、轉乘較少，全部不差且至少一項較好才淘汰；時間與轉乘都一樣時留里程短的；多轉乘的方案每多轉一次至少要早到 3 分鐘才留下），依出發時間排序。每個方案有
  `DepartureTime`、`ArrivalTime`、`DurationMinutes`、`WaitMinutes`（從 start 到出發）、`TransferCount`、`RideCount`、`WalkMinutes`、
  `DistanceKm`（軌道搭乘里程，不含步行）、`DistanceSource`（tdx／estimate）、`DataTier`（real／mixed／derived，取各段最差）、
  `Tags`、`TagBits`、`Labels`、`Steps[]`
- `Steps[]`：`Type` = `ride`（`Mode` train／metro、`System`、`LineUID`、`DisplayNo`＋`DisplayNoType`（臺鐵、高鐵為車次 `TrainNo`，捷運沒有車次時為路線編號 `RouteID`）、
  `TrainNo`、`TrainTypeName` 或 `RouteID`、`EndingStationName`、
  上下車站、`DepartureTime`、`ArrivalTime`、`Minutes`、`Stops`、`DistanceKm`、`DistanceSource`、`DepartureSource`、`ArrivalSource`、`DataTier`、
  `StopTimes[]` 途中各站時刻，見下方）、
  `walk`（同群組步行）、`train_change`（同站換車），各有 `Minutes`、`Source`、`TransferGroupID`；有轉乘實景影片時另有 `Videos[]`（見下方「轉乘實景影片」）
- `Pattern`（每個方案）：這個方案的路由組合，原樣送給 `func=follow` 就能查同一組合的前後班次（見下方 func=follow）
- `tags`：標籤定義（每個標籤的 `bit`、中文名、說明），與下表相同
- `picks`：每個標籤挑一個代表方案，值為 `options` 的索引（沒有為 null）

### 方案標籤（給前端判斷屬性）

每個方案同時給兩種寫法，擇一使用：

- `Tags`：`{"Direct": false, "Fastest": true, "ShortestTime": false, "ShortestDistance": true, "FewestTransfers": true, "Best": true}`
- `TagBits`：各標籤 bit 相加的整數，前端用 `(TagBits & bit) !== 0` 判斷，例如 `26` = 2＋8＋16 = 最快＋最短距離＋最少轉乘
- `Labels`：值為 true 的標籤名稱陣列（方便直接顯示）

| 標籤 | bit | 中文 | 意義 | picks 的代表方案 |
| --- | --- | --- | --- | --- |
| `Direct` | 1 | 直達 | 不用轉乘（所有直達方案都有） | 直達中最早到達 |
| `Fastest` | 2 | 最快 | 最早到達迄站 | 同時到達取轉乘少、出發晚 |
| `ShortestTime` | 4 | 最短時間 | 全程時間（出發到抵達）最短 | 同時間取轉乘少 |
| `ShortestDistance` | 8 | 最短距離 | 軌道搭乘里程最短（不含步行） | 同里程取轉乘少、早到 |
| `FewestTransfers` | 16 | 最少轉乘 | 轉乘次數最少 | 同次數取最早到達 |
| `Best` | 32 | 推薦 | （到達 − start）＋ 每次轉乘 {$penalty} 分 ＋ 步行時間 ×{$walkW} 最小 | 只有一個 |

並列的方案都會帶該標籤（例如有 5 個方案都只轉 1 次，5 個都有 `FewestTransfers`），只有 `Best` 一定只有一個；要「唯一一個」時用 `picks`。

里程：臺鐵用 TDX `StationOfLine` 累計里程（依列車 `TripLine` 分山線、海線）、捷運輕軌用 `StationOfRoute` 累計里程，
沿列車實際停靠的站逐段加總；高鐵 TDX 沒有里程，用相鄰兩站座標直線距離推估（`DistanceSource: estimate`）。
里程資料檔 `custom/libs/txapi/transfer/data/transfer_distance.json`（`/txapi/admin_transfer/build_groups` 時一起重新產生）。
`ShortestDistance` 是在找到的方案中里程最短的；搜尋以時間為主，繞遠但很慢的路線不一定會被列出。

另附 `network`：同一對車站的路網結構最短解（`Shortest`）與前幾個路線串，方便說明路線怎麼走。

### 途中各站時刻（StopTimes）

每一段搭乘附 `StopTimes[]`（含上下車站）：`StationUID`、`StationName`、`ArrivalTime`、`DepartureTime`、`Source`（timetable／estimate）。`stops=0` 不附。

- 臺鐵、高鐵：列車時刻表的到離站時刻
- 有真實站別時刻表的捷運、輕軌（文湖線、臺中捷運以外）：查詢前先把各站時刻表串成整列車（`libs/txapi/transfer/runs.php`），搭乘段的上下車時刻與途中各站都取自這班車：
  從每一班發車起逐站「前一站時刻＋兩站間行駛時間（直接資料為主，沒有才一站一站加）」，到下一站時刻表找同路線、同方向、同終點、同車種、±1.5 分鐘內最接近、還沒被別班用掉的一班
  （有真實到站時間的系統比到站）；對不到時改用兩站時刻表本身的發車時間差再對，仍對不到但最接近的一班明顯（差距小於班距 1/3）也採用。
  `Source`：`timetable` 對到時刻表、`interpolated` 前後都有對到時按行駛時間比例內插、`estimate` 之後都沒對到（含終點站，時刻表沒有終點站的到站）時依這班車已對到區段的時間比例推算。
  列車在終點站結束，短程車（例如象山→北投）不會被當成開到淡水
- 只有模擬時刻的路線（文湖線、臺中捷運）：發車＋上下車兩站間的行駛時間（直接資料為主），途中各站同樣推算（`estimate`）

### 轉乘實景影片（Videos）

部分轉乘（臺北車站機捷↔臺鐵／高鐵／淡水信義線／板南線、北門↔機捷、南港、松山、板橋、新埔、頭前庄、景安、大坪林、新北產業園區）有實際走一次的影片，
資料在 `transfer_seed.json` 的 `Videos`（由 railtime 整理，**有方向**：從 From 走到 To）。查詢時直接讀，出現在：

- `func=route`／`func=follow` 的轉乘步驟（`Steps[]` 的 walk）與 `Pattern` 的 Transfer
- `func=groups` 的 `Transfers[]`（`raw=1` 下載的檔案沒有）、`func=network` 的中途轉乘

每支影片：`Type`（YouTube）、`ID`、`Title`、`Url`（觀看頁）、`EmbedUrl`（放進 iframe）、`ThumbnailUrl`（縮圖）、`Source`、`Note`。網址樣式在 `transfer_settings.json` 的 `Media`。

## func=follow（固定路由組合的前後班次）

```
GET  https://{$host}/txapi/transfer/follow?week=1&time=08:00&prev=3&next=5&pattern=[…]
POST https://{$host}/txapi/transfer/follow   本文：{"pattern": […], "week": 1, "time": "08:00", "before": 30, "after": 60}
```

只在指定的路由組合（路線、轉乘站）裡找車，不重新找別的路線。第一段的每一班車各試一次，之後每段搭「趕得上、在下車站最早到」的一班；
較晚出發、不晚到達的旅程淘汰較早出發的。**每一班車只出現在一個旅程裡，而且是串起來全程時間最短的那一個**
（例如市政府→汐止：好幾班板南線都接同一班臺鐵時，只留最晚出發的那班板南線）。

`pattern`：JSON 陣列，依序為起站、路線、轉乘站、…、終點站（func=route 每個方案的 `Pattern` 可直接送回來）：

| 元素 | 欄位 | 說明 |
| --- | --- | --- |
| `{"Type":"Origin"}` | `StationUID` | 起站 |
| `{"Type":"Line"}` | `LineUID` | 系統-LineID。捷運、輕軌：同一路線（各營運路線、車種）經過上下車站的車；臺鐵、高鐵：該系統先停上車站、再停下車站的任何一班 |
| `{"Type":"Transfer"}` | `From`、`To`、`Minutes` | 轉乘（From＝To 為同站換車）。`Minutes` 省略或 null 用資料的轉乘分鐘（route 給的 `DefaultMinutes`）；給數字就用它（使用者自訂步行時間）。不在同一轉乘群組的兩站必須給 `Minutes` |
| `{"Type":"Destination"}` | `StationUID` | 終點站 |

上車站是前一個元素的車站（起站或轉乘的 `To`），下車站是下一個元素的車站（轉乘的 `From` 或終點站）；起站後、終點站前可以直接接 Transfer（先步行、最後步行）。

| 參數 | 說明 |
| --- | --- |
| `week`／`date` | 營運日 |
| `time` | 基準出發時刻 `HH:MM`（必填；午夜後寫 24 時以後） |
| `before`、`after` | 提早、延後幾分鐘內出發的班次（分鐘範圍） |
| `prev`、`next` | 基準之前幾班、之後（含）幾班；早於 time 的一側有 `before` 時用分鐘範圍，否則用 `prev`（預設 0）；另一側同理（`after` 或 `next`，都沒給時 next＝5） |
| `start`、`end` | 出發時間範圍（等同 time＝start、after＝end−start） |
| `all` | 不提供（回錯誤代碼 40003）。時間範圍最長 {$qRange}：`before`＋`after`（或 end − start）超過回 40002／40001；用 `prev`／`next` 班數時，回傳的出發時間跨度也限 {$qRange}（從離基準較遠的一端去掉） |
| `walk_factor` | 步行分鐘倍數（0.3～5，預設 1；只乘資料的步行分鐘，不乘同站換車與自訂的 `Minutes`） |
| `stops` | `0`：不附途中各站時刻（StopTimes） |

回應：`pattern`（補上站名、`UsedMinutes`、`Source`）、`options[]`（與 route 方案相同的欄位，不含標籤）、`total`（全天幾個旅程）、`first`／`last`（全天第一、最後出發）、
`focus`（第一個不早於 time 的方案在 options 的索引）、`earlier`／`later`（範圍外還有幾個）、`prevTime`／`nextTime`（範圍外最近一個的出發時刻，給「更早／更晚」按鈕用）。

## 使用限制與錯誤代碼

- 同一 IP 查詢次數：每分鐘最多 {$qMin}、每小時最多 {$qHour}（`/txapi/transfer` 與 `/txapi/finder` 合計；說明文件 `func=help` 不計），超過回 HTTP 429。
  回應標頭 `X-Query-Limit-Minute`／`X-Query-Remaining-Minute`／`X-Query-Limit-Hour`／`X-Query-Remaining-Hour` 是上限與剩餘次數，429 另有 `Retry-After`（秒）。來源網域不限
- 出發時間範圍最長 {$qRange}（route、follow）
- 上限設定在 `custom/libs/config.json`（沒寫的用 `libs/config.json` 預設值）：`QUERY_RATE_LIMIT_PER_MINUTE`、`QUERY_RATE_LIMIT_PER_HOUR`、`QUERY_MAX_RANGE_MINUTES`；有後臺身分（登入或帶 PAT）的請求不受限制
- 錯誤回應：`{"ok": false, "errorCode": 42901, "errorName": "RATE_LIMIT_PER_MINUTE", "errorMsg": "…", "error": "…"}`；完整代碼表 `GET /txapi/errors`

| errorCode | errorName | HTTP | 說明 |
| --- | --- | --- | --- |
| 40001 | QUERY_RANGE_TOO_LONG | 400 | end − start 超過 {$qRange} |
| 40002 | FOLLOW_RANGE_TOO_LONG | 400 | follow 的 before＋after 超過 {$qRange} |
| 40003 | FOLLOW_ALL_DAY_DISABLED | 400 | follow 不提供全天（all=1） |
| 42901 | RATE_LIMIT_PER_MINUTE | 429 | 同一 IP 每分鐘超過 {$qMin} |
| 42902 | RATE_LIMIT_PER_HOUR | 429 | 同一 IP 每小時超過 {$qHour} |

車站搜尋（輸入框自動完成）請在前端做延遲送出（例如停止輸入 300 毫秒後才查），避免打字就用掉次數。

## func=stations（車站搜尋）

```
GET https://{$host}/txapi/transfer/stations?q=臺北
GET https://{$host}/txapi/transfer/stations?q=banqiao&limit=5&system=TRA,THSR
```

| 參數 | 說明 |
| --- | --- |
| `q` | 站名的一部分：中文（台／臺等異體字、簡體字都可以）、TDX 英文站名、漢語拼音（多音字全部組合，ü 可打 v）、拼音首字母（tb）、站碼（BL12、1000）、StationUID、別名（北車） |
| `limit` | 幾筆（預設 10，最多 50） |
| `system` | 只找這些系統（逗號分隔） |

比對方式依序：完全相同 ＞ 開頭 ＞ 包含 ＞ 依序包含（台車 → 台北車站）＞ 英文差一個字母（banciao → 板橋），同分依系統優先順序（臺鐵、高鐵、捷運、輕軌）。
回應 `results[]`：`StationUID`、`System`、`StationID`、`StationName`、`Lines[]`（`LineUID`、`LineName`、`LineColor`）、`TransferGroups`（多站轉乘群組）、`Match`（比對到的欄位、方式、分數）。
字典 `libs/txapi/transfer/data/hanzi_pinyin.json`（Unicode Unihan 產生）、設定 `transfer_settings.json` 的 `Search`（額外異體字、別名、權重）。

## 注意

- 捷運的到站時間是「上車站離站＋站間行駛時間」推算，沒有對到迄站時刻表（要精確的單段時刻用 `/txapi/finder/metro_trip_time`）
- 臺鐵 `臺北-環島`（TRA-1001）是環島列車另用的站碼，與臺北（TRA-1000）放在同一群組
- 轉乘群組是固定檔案（`custom/libs/txapi/transfer/data/transfer_groups.json`），TDX 轉乘資料或路線改變後執行 `/txapi/admin_transfer/build_groups` 重新產生；
  人工補充在 `libs/txapi/transfer/data/transfer_seed.json`
MD;
    http_response_code(200);
    header('Content-Type: text/markdown; charset=utf-8');
    echo $md;
    exit;
}

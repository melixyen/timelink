<?php
/**
 * /txapi/transfer：軌道轉乘的基礎資料（轉乘群組、路線、車站搜尋）。轉乘查詢在 /txapi/app_trip（app/）。
 *
 *   func=help（預設）  說明文件（Markdown）
 *   func=groups        轉乘群組（完整 JSON；可用 id、station 篩選，raw=1 直接下載檔案）
 *   func=lines         路線（站序與經過的轉乘群組）與路線連結
 *   func=stations      車站搜尋（站名、英文、拼音、站碼模糊比對）
 *   （管理）/txapi/admin_transfer：build_groups、history、restore（後臺權限）
 *
 * 程式：groups.php（產生器、讀取）、network.php（路網模型）、router.php（路網時刻資料與 RAPTOR，app_trip 用）；
 * 資料：libs/txapi/transfer/data/（計算核心，受版控：settings、seed、找路規則、漢字字典）；產生的群組、連結紀錄、里程在 custom/libs/txapi/transfer/data/。
 */

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/groups.php';
require_once __DIR__ . '/network.php';
require_once __DIR__ . '/router.php';
require_once __DIR__ . '/distance.php';
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
    if ($func === 'stations') {
        transferFuncStations();
    }
    sendJson(400, ['ok' => false, 'error' => 'Unknown func: ' . $func, 'funcs' => ['help', 'groups', 'lines', 'stations'],
                   'admin' => '/txapi/admin_transfer（build_groups、history、restore）', 'help' => '/txapi/transfer',
                   'note' => '轉乘查詢用 /txapi/app_trip（routes、journeys）']);
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

function transferMissingGroups()
{
    sendJson(503, ['ok' => false, 'error' => 'transfer_groups.json is missing; run /txapi/admin_transfer/build_groups']);
}

function transferFuncGroups()
{
    if (!empty($_GET['raw'])) {
        transferEnsureGroups();
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
        $r = transferRebuildGroups(!empty($_GET['preview']));
    } catch (FinderDbIncomplete $e) {
        sendJson(503, ['ok' => false, 'error' => 'Download data is incomplete', 'missing' => $e->missing]);
    } catch (Throwable $e) {
        sendJson(500, ['ok' => false, 'error' => $e->getMessage()]);
    }
    $g = $r['groups'];
    $links = $r['links'];
    sendJson(200, ['ok' => true, 'action' => 'admin_transfer', 'func' => 'build_groups', 'saved' => $r['saved'],
                   'unchanged' => !$r['saved']['groups'] && !$r['saved']['distance'] && !$r['saved']['links'] && empty($_GET['preview']), 'Counts' => $g['Counts'],
                   'links' => ['total' => count($links['Links']), 'added' => $links['Diff']['added'], 'excluded' => $links['Diff']['excluded'],
                               'notDetected' => $links['Diff']['notDetected']],
                   'Unresolved' => $g['Unresolved'], 'diff' => $r['diff']] + (empty($_GET['preview']) ? [] : ['groups' => $g]));
}

/**
 * 重建轉乘群組（func=build_groups 與查詢時的自動重建共用）：產生群組、與舊檔比對差異，$preview＝false 時寫入連結紀錄、群組與里程
 * （內容沒變就不重寫），並記下這次用的 transfer_seed.json 雜湊（transferGroupsStamp）。
 * 回傳 ['groups', 'links', 'diff', 'saved']；失敗丟例外（FinderDbIncomplete：下載資料不完整）
 */
function transferRebuildGroups($preview = false)
{
    $seedSha = (string) @sha1_file(TRANSFER_SEED_FILE);
    finderDbEnsure(['station', 'line', 'line_station', 'train', 'train_stop']);
    $g = transferBuildGroups();
    // 舊檔直接讀原始檔（transferLoadGroups 會補名稱、也會觸發自動重建）
    $old = is_file(TRANSFER_GROUPS_FILE) ? json_decode((string) file_get_contents(TRANSFER_GROUPS_FILE), true) : null;
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
    // 內容沒變就不重寫檔案（產生時間不同不算變），舊版清單才不會多出內容相同的版本
    $same = function ($a, $b) {
        unset($a['GeneratedAt'], $b['GeneratedAt']);
        return json_encode($a) === json_encode($b);
    };
    $links = $g['_links'];
    unset($g['_links']);
    $saved = ['groups' => false, 'distance' => false, 'links' => false];
    if (!$preview) {
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
        transferGroupsStamp(['SeedSha1' => $seedSha, 'BuiltAt' => downloadFmtTime(time())]);
    }
    return ['groups' => $g, 'links' => $links, 'diff' => $diff, 'saved' => $saved];
}

/** 自動重建的紀錄檔（custom/…/transfer_groups_stamp.json）：讀取，或給 $set 時寫入（取代整份） */
function transferGroupsStamp(array $set = null)
{
    $f = TRANSFER_GEN_DIR . '/transfer_groups_stamp.json';
    if ($set === null) {
        $j = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
        return is_array($j) ? $j : [];
    }
    if (!is_dir(TRANSFER_GEN_DIR)) {
        @mkdir(TRANSFER_GEN_DIR, 0755, true);
    }
    $tmp = $f . '.tmp' . getmypid();
    if (@file_put_contents($tmp, json_encode($set, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) !== false) {
        @rename($tmp, $f);
    }
    return $set;
}

/** 自動重建失敗後多久再試（秒） */
const TRANSFER_AUTO_REBUILD_RETRY = 600;

/**
 * 查詢時的自動重建（transferLoadGroups 讀群組前呼叫，每個請求只檢查一次）：transfer_seed.json 的雜湊與上次重建時不同、
 * 或還沒有群組檔時，自動跑一次 build_groups（transferRebuildGroups）。佈署新的 transfer_seed.json 後不必手動執行，第一次查詢就會重建。
 *   - 用雜湊比對，不看檔案時間（佈署方式保留或不保留修改時間都可以）
 *   - 檔案鎖：同時只有一個請求重建；其他請求有舊群組就直接用舊的，沒有群組檔才等它做完
 *   - 失敗時用舊群組、寫入錯誤紀錄（error_log），同一份 seed 在 TRANSFER_AUTO_REBUILD_RETRY 秒內不再重試
 */
function transferEnsureGroups()
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $sha = (string) @sha1_file(TRANSFER_SEED_FILE);
    $fresh = function () use ($sha) {
        $st = transferGroupsStamp();
        if (is_file(TRANSFER_GROUPS_FILE) && ($st['SeedSha1'] ?? null) === $sha) {
            return true;
        }
        // 剛失敗過：先用舊的（沒有群組檔時照樣重試）
        return is_file(TRANSFER_GROUPS_FILE) && ($st['FailedSeedSha1'] ?? null) === $sha
            && time() - (int) ($st['FailedAt'] ?? 0) < TRANSFER_AUTO_REBUILD_RETRY;
    };
    if ($sha === '' || $fresh()) {
        return;
    }
    if (!is_dir(TRANSFER_GEN_DIR)) {
        @mkdir(TRANSFER_GEN_DIR, 0755, true);
    }
    $lock = @fopen(TRANSFER_GEN_DIR . '/transfer_groups.lock', 'c');
    if (!$lock) {
        return;
    }
    // 有舊群組：別的請求正在重建就不等；沒有群組檔：等它做完
    if (!flock($lock, is_file(TRANSFER_GROUPS_FILE) ? LOCK_EX | LOCK_NB : LOCK_EX)) {
        fclose($lock);
        return;
    }
    try {
        if (!$fresh()) {
            @set_time_limit(300);
            transferRebuildGroups(false);
        }
    } catch (Throwable $e) {
        error_log('[transfer] 自動重建轉乘群組失敗：' . $e->getMessage());
        transferGroupsStamp(array_merge(transferGroupsStamp(), ['FailedSeedSha1' => $sha, 'FailedAt' => time(), 'Error' => $e->getMessage()]));
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * /txapi/admin_transfer：轉乘資料的管理動作（路由權限 admin_transfer）
 *   func=build_groups  重新產生轉乘群組、連結紀錄、里程（寫入；preview=1 只回傳不存檔，讀取）
 *   func=history       產生檔與舊版清單（讀取）
 *   func=restore       把產生檔換回舊版：file=Groups|Links|Distance、version=history 裡的檔名（寫入）
 */
function actionAdminTransfer()
{
    header('Cache-Control: no-store');
    $func = strtolower(trim((string) ($_GET['func'] ?? '')));
    switch ($func) {
        case 'build_groups':
            adminRequire('admin_transfer', empty($_GET['preview']) ? 'write' : 'read');
            transferFuncBuild();
        case 'history':
            adminRequire('admin_transfer', 'read');
            $files = [];
            foreach (transferSettings('GeneratedFiles') as $k) {
                $f = transferDataFile($k);
                $files[$k] = ['file' => 'custom/libs/txapi/transfer/data/' . basename($f), 'exists' => is_file($f),
                              'bytes' => is_file($f) ? filesize($f) : null, 'sha256' => is_file($f) ? hash_file('sha256', $f) : null, 'saved_at' => is_file($f) ? (new DateTime('@' . filemtime($f)))->setTimezone(new DateTimeZone('Asia/Taipei'))->format('Y-m-d H:i:s') : null,
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
                           'history' => transferHistory($key)]);
        default:
            adminRequire('admin_transfer', 'read');
            sendJson(400, ['ok' => false, 'error' => $func === '' ? 'Missing func' : 'Unknown func: ' . $func,
                           'funcs' => ['build_groups', 'history', 'restore']]);
    }
}

/**
 * 產生檔（custom/libs/txapi/transfer/data/）：路徑、sha256、修改時間（後臺系統狀態用；pipeline 用 admin_transfer/history 比對前後差異）
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
    // 查詢限制：數值一律讀設定（custom/libs/config.json，沒寫的用 libs/config.php 預設值；queryLimitSettings），說明文件不寫死
    $ql = queryLimitSettings();
    $qMin = queryLimitText($ql['minute']);
    $qHour = queryLimitText($ql['hour']);
    $md = <<<MD
# /txapi/transfer：軌道轉乘

臺鐵、高鐵、各捷運與輕軌轉乘的基礎資料：轉乘群組、路線與路線連結、車站搜尋。資料全部來自離線的 `sqlite/finder.sqlite`（見 `/txapi/finder`）與轉乘群組檔，不呼叫 TDX。
轉乘查詢（路由、時刻表串接、RAPTOR 補充）在 `/txapi/app_trip`（`routes`、`journeys`，見 `/txapi/readme`）。

| func | 用途 |
| --- | --- |
| `help` | 本說明（省略 func 時相同） |
| `groups` | 轉乘群組（TransferGroup）；`id=TRANSGRP_taipei`、`station=TRA-1000`、`type=multi／single` 篩選；`raw=1` 直接下載完整 JSON 檔 |
| `lines` | 路線（站序、經過的轉乘群組）與路線連結（LineLinks） |
| `stations` | 車站搜尋：`q=台北`（台／臺、簡繁、英文、漢語拼音、拼音首字母、站碼都可以），模糊比對前 10 筆 |
| `/txapi/admin_transfer/build_groups` | 重新產生轉乘群組、連結紀錄、里程（後臺寫入權限；`preview=1` 只回傳不存檔，讀取權限）；回應 `links.added`／`links.notDetected` 為新加入與沒再偵測到的連結 |
| `/txapi/admin_transfer/history` | 產生檔（含 sha256）與舊版清單（讀取權限） |
| `/txapi/admin_transfer/restore` | 把產生檔換回舊版：`file=Groups\|Links\|Distance`、`version=<history 的檔名>`（寫入權限） |

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
   查詢時直接讀 seed，改了馬上生效，群組檔裡的標記在下次 `build_groups` 更新
2. 步行分鐘：TDX `LineTransfer.TransferTime`／`MinTransferTime` → TDX `StationTransfer.MinTransferTime`（只有單向時反向沿用，標 `(反向)`）
   → railtime／rocptx 的 walkMinute → 座標距離推算 `round(4 + 公尺 ÷ 70)`，限 3～15 分（`estimate`）
3. 同站換車：TDX 臺鐵 `LineTransfer`（交會站 3 分）、捷運 `LineTransfer` 自己轉自己（七張、大橋頭、北投）→ railtime／rocptx 的臺鐵大站與機捷 A8、淡海 V09
   → 預設（臺鐵、高鐵 3 分、捷運 2 分）。臺鐵另依時刻表自動選站：每日快車（太魯閣、普悠瑪、自強、莒光）停靠 10 班以上，或列車起訖 10 班以上

目前轉乘分鐘的來源統計：

| 來源 | 筆數 |
| --- | --- |
{$src}
## 資料檔（PHP 與 Node.js 共用的 JSON）

人工維護的輸入在 `libs/txapi/transfer/data/`（隨程式佈署、受版控）。產生檔（群組、連結紀錄、里程，`transfer_settings.json` 的 `GeneratedFiles`）
是用主機上的離線資料算的，放在 `custom/libs/txapi/transfer/data/`（不進版控、隨 custom 備份），每次改寫前舊版留在 `history/`
（保留 `TRANSFER_HISTORY_KEEP` 份），轉車出問題可用 `/txapi/admin_transfer/restore` 換回。

| 檔案 | 內容 |
| --- | --- |
| `transfer_settings.json` | RAPTOR 參數（最多班數、出發後最多找多久）、里程規則（站碼別名、山線區段、TripLine）、轉乘群組讀哪些 TDX 檔案與站碼對應、車站搜尋設定（Search） |
| `transfer_seed.json` | 轉乘群組的人工補充：群組、步行分鐘、同站換車、ID 拼音、排除記號（Bypass）、轉乘實景影片（Videos） |
| `transfer_groups.json` | （custom）產生的轉乘群組（`/txapi/admin_transfer/build_groups`） |
| `transfer_links.json` | （custom）自動偵測到的轉乘連結紀錄，只增不減（`build_groups`） |
| `transfer_distance.json` | （custom）產生的軌道里程（`build_groups`） |
| `hanzi_pinyin.json` | 車站搜尋的漢字字典：Big5 漢字拼音、異體與簡繁歸一（`agent/skills/rail-transfer/scripts/build_hanzi.php` 由 Unicode Unihan 產生） |

程式（`libs/txapi/transfer/*.php`）只讀這些檔，不寫死資料；改參數或清單只要改 JSON。

## 轉乘實景影片（Videos）

部分轉乘（臺北車站機捷↔臺鐵／高鐵／淡水信義線／板南線、北門↔機捷、南港、松山、板橋、新埔、頭前庄、景安、大坪林、新北產業園區）有實際走一次的影片，
資料在 `transfer_seed.json` 的 `Videos`（由 railtime 整理，**有方向**：從 From 走到 To）。查詢時直接讀，出現在：

- `func=groups` 的 `Transfers[]`（`raw=1` 下載的檔案沒有）
- `/txapi/app_trip/journeys` 的轉乘步行段

每支影片：`Type`（YouTube）、`ID`、`Title`、`Url`（觀看頁）、`EmbedUrl`（放進 iframe）、`ThumbnailUrl`（縮圖）、`Source`、`Note`。網址樣式在 `transfer_settings.json` 的 `Media`。

## 使用限制與錯誤代碼

- 同一 IP 查詢次數：每分鐘最多 {$qMin}、每小時最多 {$qHour}（`/txapi/transfer` 與 `/txapi/finder` 合計；說明文件 `func=help` 不計），超過回 HTTP 429。
  回應標頭 `X-Query-Limit-Minute`／`X-Query-Remaining-Minute`／`X-Query-Limit-Hour`／`X-Query-Remaining-Hour` 是上限與剩餘次數，429 另有 `Retry-After`（秒）。來源網域不限
- 上限設定在 `custom/libs/config.json`（沒寫的用 `libs/config.json` 預設值）：`QUERY_RATE_LIMIT_PER_MINUTE`、`QUERY_RATE_LIMIT_PER_HOUR`；有後臺身分（登入或帶 PAT）的請求不受限制
- 錯誤回應：`{"ok": false, "errorCode": 42901, "errorName": "RATE_LIMIT_PER_MINUTE", "errorMsg": "…", "error": "…"}`；完整代碼表 `GET /txapi/errors`

| errorCode | errorName | HTTP | 說明 |
| --- | --- | --- | --- |
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

- 臺鐵 `臺北-環島`（TRA-1001）是環島列車另用的站碼，與臺北（TRA-1000）放在同一群組
- 轉乘群組是固定檔案（`custom/libs/txapi/transfer/data/transfer_groups.json`），TDX 轉乘資料或路線改變後執行 `/txapi/admin_transfer/build_groups` 重新產生；
  人工補充在 `libs/txapi/transfer/data/transfer_seed.json`
MD;
    http_response_code(200);
    header('Content-Type: text/markdown; charset=utf-8');
    echo $md;
    exit;
}

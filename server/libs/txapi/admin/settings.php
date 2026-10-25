<?php
/**
 * 設定管理（路由權限 admin_settings）：檢視、項目編輯器、整包下載 custom/。
 * 不提供上傳：要放回檔案請用 FTP 或主機的檔案管理工具。
 *
 *   GET  /txapi/admin_settings/checklist      初始化檢查清單（讀取）：新主機上線要完成的步驟與下一步
 *   GET  /txapi/admin_settings[/view]   所有設定（讀取）：目前的值、預設值、來源、型別、說明；金鑰只顯示已設定／未設定
 *   POST /txapi/admin_settings/save     改設定（寫入）：{"changes": {"名稱": {"mode": "default"} 或 {"mode": "custom", "value": …}}}
 *   POST /txapi/admin_settings/download 下載整包 custom/（寫入；zip，沒有 ZipArchive 時改 tar.gz）
 *
 * 項目、預設值與說明在 libs/config.json；存檔只改 custom/libs/config.json 裡被編輯的項目（改回預設＝刪掉那一項），
 * 其他內容（不認得的項目、底線開頭的註記）原樣保留；舊檔先存到 custom/libs/config_history/（保留 APP_CONFIG_HISTORY_KEEP 份）。
 * APP_SECRET_KEY 不能在後臺改（改了所有 PAT 與登入都會失效），要改請直接編輯檔案。
 */

const ADMIN_CONFIG_READONLY     = ['APP_SECRET_KEY'];

/** 項目的型別（依預設值）：bool、int、string、list（字串陣列） */
function adminConfigType($default)
{
    if (is_bool($default)) {
        return 'bool';
    }
    if (is_int($default)) {
        return 'int';
    }
    if (is_array($default)) {
        return 'list';
    }
    return 'string';
}

/** 檢查並轉換編輯器送來的值；錯誤回 [null, 訊息] */
function adminConfigCoerce($type, $v)
{
    switch ($type) {
        case 'bool':
            if (is_bool($v)) {
                return [$v, null];
            }
            $b = filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            return $b === null ? [null, '要是 true 或 false'] : [$b, null];
        case 'int':
            if (is_int($v) || (is_string($v) && preg_match('/^-?\d+$/', trim($v)))) {
                return [(int) $v, null];
            }
            return [null, '要是整數'];
        case 'list':
            if (is_string($v)) {
                $v = json_decode($v, true);
            }
            if (!is_array($v) || array_keys($v) !== range(0, count($v) - 1)) {
                return [null, '要是字串陣列，例如 ["localhost", "127.0.0.1"]'];
            }
            foreach ($v as $x) {
                if (!is_string($x)) {
                    return [null, '陣列裡只能放字串'];
                }
            }
            return [$v, null];
        default:
            return is_string($v) ? [$v, null] : [null, '要是字串'];
    }
}

/** 編輯器用的項目清單 */
function adminConfigEditorItems()
{
    $c = configCustom();
    $report = configReport(true);
    $out = [];
    foreach (configItems() as $name => $it) {
        $out[] = ['name' => $name, 'group' => $it['group'], 'type' => adminConfigType($it['default']), 'level' => $it['level'], 'desc' => $it['desc'],
                  'default' => $it['level'] === 'secret' ? null : $it['default'],
                  'value' => $report[$name]['value'], 'custom' => array_key_exists($name, $c),
                  'editable' => !in_array($name, ADMIN_CONFIG_READONLY, true)];
    }
    return $out;
}


function actionAdminSettings()
{
    header('Cache-Control: no-store');
    $func = strtolower(trim((string) ($_GET['func'] ?? 'view')));
    switch ($func) {
        case 'view':
        case '':
            adminRequire('admin_settings', 'read');
            adminSettingsView();
        case 'save':
            adminRequire('admin_settings', 'write');
            adminSettingsSave();
        case 'download':
            adminRequire('admin_settings', 'write');
            adminSettingsDownload();
        case 'checklist':
            adminRequire('admin_settings', 'read');
            sendJson(200, ['ok' => true, 'action' => 'admin_settings', 'func' => 'checklist'] + adminSetupChecklist());
        default:
            adminRequire('admin_settings', 'read');
            sendJson(400, ['ok' => false, 'error' => 'Unknown func: ' . $func, 'funcs' => ['view', 'save', 'download', 'checklist']]);
    }
}

/**
 * 初始化檢查清單（GET /txapi/admin_settings/checklist，admin_settings 讀取）：新主機從空的 repo 上線要完成的步驟，
 * 逐項回報是否完成與下一步。只回狀態，不含任何設定值。
 *   status：ok 完成、todo 待處理（服務需要）、warn 建議處理、wait 要先完成前面的步驟
 *   panel：後臺頁面（#/settings 等）；api：要呼叫的 API；cli：命令列做法
 */
function adminSetupChecklist()
{
    $items = [];
    $add = function ($key, $title, $status, $detail, $extra = []) use (&$items) {
        $items[] = ['key' => $key, 'title' => $title, 'status' => $status, 'detail' => $detail] + $extra;
    };
    $pipeline = 'TDX_BASE=https://<本站> TDX_PAT=<PAT> php agent/skills/data-pipeline/scripts/pipeline.php';

    // 1. 管理者帳號（第一個帳號由首次設定建立；舊版建立的 admin/admin 若還沒改密碼就提醒）
    $defaults = [];
    try {
        foreach (adminDb()->query('SELECT username FROM admin_users WHERE is_default_password = 1 AND disabled = 0') as $r) {
            $defaults[] = $r['username'];
        }
    } catch (Throwable $e) {
        $defaults = [];
    }
    $add('password', '管理者帳號', $defaults ? 'todo' : 'ok',
        $defaults ? '還在使用預設密碼的帳號：' . implode('、', $defaults) . '。預設密碼是公開的，請立刻更換'
                  : '已建立，沒有帳號使用預設密碼',
        $defaults ? ['panel' => ['label' => '我的帳號', 'href' => '#/account']] : []);

    // 2. 秘密字串（第一次執行時自動產生）
    $add('secret', '本站秘密字串 APP_SECRET_KEY', appSecretKey() !== '' ? 'ok' : 'todo',
        appSecretKey() !== '' ? '已產生（第一次執行時自動寫入 custom/libs/config.json）'
                              : '沒有秘密字串，PAT 與登入都無法使用：確認 custom/libs/ 可以寫入，或用 FTP 在 custom/libs/config.json 加上 APP_SECRET_KEY');

    // 2-1. 私密檔案不能從網址讀取（主機沒有套用 .htaccess 時，custom/ 的設定檔與帳號庫會被直接下載）
    $probe = adminPrivateFileProbe();
    $add('private_files', '私密檔案不能從網址下載', $probe['status'], $probe['detail']);

    // 3. TDX 金鑰
    $keys = isConfigured();
    $add('tdx_keys', 'TDX API 金鑰', $keys ? 'ok' : 'todo',
        $keys ? 'TDX_CLIENT_ID、TDX_CLIENT_SECRET 已設定'
              : '在「設定管理」填入 TDX_CLIENT_ID、TDX_CLIENT_SECRET（TDX 會員中心 → 資料服務 → API 金鑰）；沒有金鑰無法轉發 TDX，也無法下載離線資料',
        $keys ? [] : ['panel' => ['label' => '設定管理', 'href' => '#/settings']]);

    // 4. 站址、開放網域（建議）
    $hostSet = SITE_HOST !== '' && SITE_HOST !== 'localhost';
    $add('site_host', '本站主機名稱 SITE_HOST', $hostSet ? 'ok' : 'warn',
        $hostSet ? '已設定' : '還是預設的 localhost：命令列與排程產生的網址會不正確，建議在「設定管理」填入本站網域',
        $hostSet ? [] : ['panel' => ['label' => '設定管理', 'href' => '#/settings']]);
    $origins = configPublicOrigins()['customized'];
    $add('origins', '開放網域 ALLOWED_ORIGINS', $origins ? 'ok' : 'warn',
        $origins ? '已自訂' : '只有 localhost、127.0.0.1 能不帶金鑰使用 /api/ 轉發；要給自己的前端網站用，請在「設定管理」加入網域',
        $origins ? [] : ['panel' => ['label' => '設定管理', 'href' => '#/settings']]);

    // 5. 離線資料下載
    $fs = function_exists('finderStatus') ? finderStatus() : null;
    $missing = $fs ? count($fs['download']['missing']) : null;
    $dlDone = $fs && $fs['download']['complete'];
    $add('download', '下載離線資料', $dlDone ? 'ok' : ($keys ? 'todo' : 'wait'),
        $dlDone ? '查詢需要的 TDX 靜態資料都已下載'
                : ($keys ? '還缺 ' . $missing . ' 個檔案。重複呼叫同步直到 done=true（每分鐘最多 3 次 TDX 呼叫，全部約需 20 分鐘以上）；完成時自動建立查詢資料庫'
                         : '要先設定 TDX 金鑰'),
        $dlDone || !$keys ? [] : ['api' => '/txapi/admin_download/sync?service=tdx', 'cli' => $pipeline . ' download']);

    // 6. 查詢資料庫
    $db = $fs ? $fs['sqlite'] : ['exists' => false, 'fresh' => false];
    $dbOk = $db['exists'] && $db['fresh'];
    $add('finder_db', '查詢資料庫 sqlite/finder.sqlite', $dbOk ? 'ok' : ($dlDone ? 'todo' : 'wait'),
        $dbOk ? '已建立且是最新' . (!empty($db['built_at']) ? '（' . $db['built_at'] . '）' : '')
              : ($dlDone ? ($db['exists'] ? '資料庫比下載資料舊，重建一次' : '還沒建立，建立一次') : '下載完成時會自動建立'),
        $dbOk || !$dlDone ? [] : ['api' => '/txapi/admin_download_to_sqlite/build']);

    // 7. 轉乘群組、連結、里程
    $gen = function_exists('transferGeneratedFiles') ? transferGeneratedFiles() : [];
    $groupKeys = array_values(array_diff(array_keys($gen), ['AccelLearned']));
    $groupMissing = array_values(array_filter($groupKeys, function ($k) use ($gen) { return $gen[$k]['sha256'] === null; }));
    $groupsOk = $gen && !$groupMissing;
    $add('transfer_groups', '轉乘群組、連結、里程', $groupsOk ? 'ok' : ($dbOk ? 'todo' : 'wait'),
        $groupsOk ? '已產生（custom/libs/txapi/transfer/data/）'
                  : ($dbOk ? '還沒產生：' . implode('、', $groupMissing) : '要先建立查詢資料庫'),
        $groupsOk || !$dbOk ? [] : ['api' => '/txapi/admin_transfer/build_groups', 'cli' => $pipeline . ' learn']);

    // 8. 轉乘加速清單
    $learned = null;
    if ($dbOk && $groupsOk) {
        try {
            $d = ['db' => SqliteDao::open(FINDERDB_NAME, ['readonly' => true])];
            $learned = transferAccelLearned($d);
            $d['db']->close();
        } catch (Throwable $e) {
            $learned = null;
        }
    }
    $fresh = $learned && !empty($learned['Fresh']);
    $add('transfer_accel', '轉乘加速清單', $fresh ? 'ok' : ($groupsOk ? ($learned ? 'warn' : 'todo') : 'wait'),
        $fresh ? '已算好且是最新（' . ($learned['GeneratedAt'] ?? '') . '）'
               : ($groupsOk ? ($learned ? '已過期（資料庫或轉乘群組改過），轉乘查詢暫時不套用加速，請重算'
                                       : '還沒算過：重複呼叫直到 done=true（每次最多算 20 秒）；沒算好前轉乘查詢照常可用，只是比較慢')
                            : '要先產生轉乘群組'),
        $fresh || !$groupsOk ? [] : ['api' => '/txapi/admin_transfer/learn', 'cli' => $pipeline . ' learn']);

    $count = ['ok' => 0, 'todo' => 0, 'warn' => 0, 'wait' => 0];
    foreach ($items as $it) {
        $count[$it['status']]++;
    }
    return ['done' => $count['todo'] === 0 && $count['wait'] === 0, 'count' => $count, 'items' => $items,
            'note' => '離線資料之後也要定期更新：重跑 pipeline.php download learn（資料沒變會自動跳過）'];
}

/**
 * 從網址試讀 custom/ 裡的設定檔與帳號庫（打自己的網站，只讀前 64 bytes）：讀得到＝網頁伺服器沒有套用 .htaccess，
 * 金鑰與帳號會外洩。回傳 ['status' => ok|todo|warn, 'detail']
 */
function adminPrivateFileProbe()
{
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (!function_exists('curl_init') || !preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $host)) {
        return ['status' => 'warn', 'detail' => '無法自我檢查（沒有 curl 或 Host）；請手動確認打開 /custom/libs/config.json 會得到 403 或 404'];
    }
    $base = (adminIsHttps() ? 'https://' : 'http://') . $host;
    $exposed = [];
    $failed = 0;
    foreach (['/custom/libs/config.json' => '{', '/custom/libs/txapi/admin/admin.sqlite' => 'SQLite format'] as $path => $sig) {
        $ch = curl_init($base . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3,
                                CURLOPT_FOLLOWLOCATION => false, CURLOPT_RANGE => '0-63', CURLOPT_USERAGENT => 'timelink-selfcheck']);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code === 0) {
            $failed++;
        } elseif (($code === 200 || $code === 206) && strncmp(ltrim((string) $body), $sig, strlen($sig)) === 0) {
            $exposed[] = $path;
        }
    }
    if ($exposed) {
        return ['status' => 'todo', 'detail' => '可以直接從網址下載：' . implode('、', $exposed) . '。網頁伺服器沒有套用 .htaccess（例如 nginx），'
            . '金鑰與帳號會外洩！請在伺服器設定禁止存取 custom/、download/、sqlite/，或改用支援 .htaccess 的 Apache／LiteSpeed'];
    }
    if ($failed) {
        return ['status' => 'warn', 'detail' => '連不回自己的網站，無法自我檢查；請手動確認打開 /custom/libs/config.json 會得到 403 或 404'];
    }
    return ['status' => 'ok', 'detail' => 'custom/ 的設定檔與帳號庫從網址讀不到（.htaccess 有效）'];
}

function adminSettingsView($extra = [])
{
    $files = [];
    foreach (['custom/libs/config.json' => APP_CUSTOM_CONFIG, 'custom/libs/txapi/admin/admin.sqlite' => ADMIN_DB_FILE,
              'custom/agent/' => APP_CUSTOM_AGENT, 'custom/libs/txapi/transfer/data/' => APP_CUSTOM_LIBS . '/txapi/transfer/data',
              'custom/libs/config_history/' => APP_CONFIG_HISTORY_DIR] as $label => $path) {
        $files[$label] = file_exists($path);
    }
    $hist = array_map('basename', glob(APP_CONFIG_HISTORY_DIR . '/config.*') ?: []);
    rsort($hist);
    sendJson(200, ['ok' => true, 'action' => 'admin_settings', 'func' => 'view', 'file' => 'custom/libs/config.json', 'defaults' => 'libs/config.json',
                   'values' => configReport(true), 'items' => adminConfigEditorItems(), 'unknownKeys' => configUnknownKeys(),
                   'error' => configCustomError(), 'files' => $files, 'history' => $hist,
                   'archive' => class_exists('ZipArchive') ? 'zip' : 'tar.gz'] + $extra);
}

/** 依編輯器送來的 changes 改設定；secret 項目只能設新值（mode=custom，非空）或清除（mode=default） */
function adminSettingsSave()
{
    if (configCustomError()) {
        appError(40901, ['detail' => '目前的設定檔讀取失敗，請先用 FTP 或主機的檔案管理工具 修好再用後臺編輯']);
    }
    $changes = adminParam('changes');
    if (!is_array($changes) || !$changes) {
        adminBadInput('changes 必填：{"名稱": {"mode": "default"} 或 {"mode": "custom", "value": …}}');
    }
    $items = configItems();
    $lock = configLock();
    $custom = configCustom(true);   // 拿鎖之後重讀一次，別的請求剛寫入的值不會被蓋掉
    $errors = [];
    foreach ($changes as $name => $ch) {
        if (!isset($items[$name])) {
            $errors[$name] = '不認得的設定';
            continue;
        }
        if (in_array($name, ADMIN_CONFIG_READONLY, true)) {
            $errors[$name] = '不能在後臺修改';
            continue;
        }
        $mode = is_array($ch) ? ($ch['mode'] ?? '') : '';
        if ($mode === 'default') {
            unset($custom[$name]);
            continue;
        }
        if ($mode !== 'custom' || !array_key_exists('value', $ch)) {
            $errors[$name] = 'mode 要是 default 或 custom（custom 要帶 value）';
            continue;
        }
        list($v, $err) = adminConfigCoerce(adminConfigType($items[$name]['default']), $ch['value']);
        if ($err === null && $items[$name]['level'] === 'secret' && $v === '') {
            $err = '金鑰不能設成空字串（要清除請改回預設）';
        }
        if ($err !== null) {
            $errors[$name] = $err;
            continue;
        }
        $custom[$name] = $v;
    }
    if ($errors) {
        configUnlock($lock);
        appError(40010, ['detail' => implode('；', array_map(function ($k, $v) { return $k . '：' . $v; }, array_keys($errors), $errors))],
                 ['fields' => $errors]);
    }
    try {
        configWriteCustom($custom);   // 只改 custom/libs/config.json；舊版存進 config_history/
    } catch (Throwable $e) {
        configUnlock($lock);
        sendJson(500, ['ok' => false, 'error' => $e->getMessage()]);
    }
    configUnlock($lock);
    sendJson(200, ['ok' => true, 'action' => 'admin_settings', 'func' => 'save', 'saved' => array_keys($changes),
                   'message' => '已儲存，下一個請求開始生效']);
}

/** 整包下載 custom/（含金鑰與帳號資料庫，請妥善保管） */
function adminSettingsDownload()
{
    @set_time_limit(TIME_LIMIT_CUSTOM_DOWNLOAD);
    $root = realpath(APP_CUSTOM_DIR);
    if ($root === false) {
        sendJson(404, ['ok' => false, 'error' => 'custom/ does not exist']);
    }
    $ts = (new DateTime('now', new DateTimeZone('Asia/Taipei')))->format('Ymd-His');
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && !is_link($f->getPathname()) && !preg_match('/\.tmp\d+$/', $f->getFilename())) {
            $files[] = $f->getPathname();
        }
    }
    if (!is_dir(APP_TEMP_DIR)) {
        @mkdir(APP_TEMP_DIR, 0755, true);
    }
    if (class_exists('ZipArchive')) {
        $out = APP_TEMP_DIR . '/custom-' . $ts . '-' . getmypid() . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            sendJson(500, ['ok' => false, 'error' => 'cannot create zip']);
        }
        foreach ($files as $f) {
            $zip->addFile($f, 'custom/' . substr($f, strlen($root) + 1));
        }
        $zip->close();
        $name = 'custom-' . $ts . '.zip';
        $type = 'application/zip';
    } else {
        $tar = APP_TEMP_DIR . '/custom-' . $ts . '-' . getmypid() . '.tar';
        $p = new PharData($tar);
        foreach ($files as $f) {
            $p->addFile($f, 'custom/' . substr($f, strlen($root) + 1));
        }
        $p->compress(Phar::GZ);
        unset($p);
        @unlink($tar);
        $out = $tar . '.gz';
        $name = 'custom-' . $ts . '.tar.gz';
        $type = 'application/gzip';
    }
    http_response_code(200);
    header('Content-Type: ' . $type);
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($out));
    header('Cache-Control: no-store');
    header('Access-Control-Expose-Headers: content-disposition');
    readfile($out);
    @unlink($out);
    exit;
}

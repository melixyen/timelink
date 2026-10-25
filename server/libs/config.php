<?php
/**
 * 設定（由 index.php 第一個 require 進來）。
 *
 *   libs/config.json          所有設定項目：分組、預設值、公開程度、說明（進版控，佈署時整份覆蓋）
 *   custom/libs/config.json   只放要覆蓋的值與私密值（不進版控、要備份；後臺「設定管理」也只寫這個檔）
 *   libs/config.php（本檔）   共用的讀取程式：讀兩份 JSON，custom 有的名稱用 custom 的值，沒有用預設值，
 *                             每一項 define 成同名常數，其他程式照常用 QUERY_RATE_LIMIT_PER_MINUTE 這樣的名稱讀取
 *
 * 程式、通用資料、私密資料分開存放：
 *   libs/                 程式與人工維護的通用資料（進版控）
 *   custom/               主機上「不進版控、要備份」的唯一位置，禁止網址存取
 *     libs/               相對 libs/ 的動態與自訂資料起點（只放資料與設定，不放程式）
 *       config.json       覆蓋值與私密值；舊版改動存在 config_history/
 *       txapi/            轉乘產生檔、後臺帳號資料庫
 *     agent/              主機維護筆記等私密說明（給 AI 代理讀寫）
 *   download/tdx_proxy_files/  快取類暫存（token、TDX 快取、查詢次數、流量紀錄），丟了會自己重建
 *
 * custom/libs/config.json 的寫法：
 *   - 一個 JSON 物件，只放要覆蓋的項目，例如 {"QUERY_RATE_LIMIT_PER_MINUTE": 30}；說明都在 libs/config.json
 *   - 有這個名稱（值是 0、false、"" 也算）就用它；底線開頭的名稱（例如 "_說明"）是註記，程式不讀
 *   - 第一次執行時自動建立，內容只有一組隨機產生的 APP_SECRET_KEY
 *   - 舊格式 custom/libs/config.php（或更舊的 custom/config.php）存在、新檔還沒有時，自動轉成 JSON（只轉一次，舊檔移到 config_history/）
 *
 * 進版控的只有預設值：佔位字串、提示字串或本機網域。私人資訊（金鑰、開放網域、站名）一律只寫在 custom。
 */

const APP_ROOT_DIR           = __DIR__ . '/..';
const APP_CONFIG_DEFAULTS    = __DIR__ . '/config.json';
const APP_CUSTOM_DIR         = APP_ROOT_DIR . '/custom';
const APP_CUSTOM_LIBS        = APP_CUSTOM_DIR . '/libs';
const APP_CUSTOM_AGENT       = APP_CUSTOM_DIR . '/agent';
const APP_CUSTOM_CONFIG      = APP_CUSTOM_LIBS . '/config.json';
const APP_CUSTOM_CONFIG_PHP  = APP_CUSTOM_LIBS . '/config.php';   // 舊格式（自動轉成 JSON）
const APP_CUSTOM_CONFIG_OLD  = APP_CUSTOM_DIR . '/config.php';    // 更舊的位置
const APP_CONFIG_HISTORY_DIR = APP_CUSTOM_LIBS . '/config_history';
const APP_CONFIG_HISTORY_KEEP = 10;
const APP_TEMP_DIR           = APP_ROOT_DIR . '/download/tdx_proxy_files';

/** 記錄或讀取設定檔的讀取錯誤 */
function configCustomError($set = null)
{
    static $err = null;
    if ($set !== null) {
        $err = $set;
        error_log('[tdx-proxy] ' . $set);
    }
    return $err;
}

/**
 * 設定項目：名稱 => ['group', 'default', 'desc', 'level']（來自 libs/config.json；desc 多行時以換行連接）
 * level：public＝公開顯示目前的值；private＝公開時只顯示預設值與「custom 有自訂」，要 admin_settings 讀取權限才看得到；
 *        secret＝任何人都只看得到「已設定／未設定」
 */
function configItems()
{
    static $items = null;
    if ($items === null) {
        $d = json_decode((string) @file_get_contents(APP_CONFIG_DEFAULTS), true);
        if (!is_array($d) || !is_array($d['items'] ?? null)) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['Message' => 'libs/config.json is missing or not valid JSON']);
            exit;
        }
        $items = [];
        foreach ($d['items'] as $name => $it) {
            $desc = $it['desc'] ?? '';
            $items[$name] = ['group' => (string) ($it['group'] ?? ''), 'default' => $it['default'] ?? null,
                             'desc' => is_array($desc) ? implode("\n", $desc) : (string) $desc,
                             'level' => in_array($it['level'] ?? '', ['public', 'private', 'secret'], true) ? $it['level'] : 'public'];
        }
    }
    return $items;
}

/** 讀一個 custom 設定檔（JSON 或舊格式 PHP）；讀不到回 null，格式錯誤丟例外 */
function configReadFile($file)
{
    if (!is_file($file)) {
        return null;
    }
    if (substr($file, -5) === '.json') {
        $src = (string) file_get_contents($file);
        $c = trim($src) === '' ? [] : json_decode($src, true);
        if (!is_array($c)) {
            throw new RuntimeException(basename($file) . ' is not a valid JSON object: ' . json_last_error_msg());
        }
        return $c;
    }
    $c = include $file;
    if (!is_array($c)) {
        throw new RuntimeException(basename($file) . ' does not return an array');
    }
    return $c;
}

/**
 * custom/libs/config.json 的內容（原樣，含底線開頭的註記；只讀一次）。
 * 還沒有 JSON、只有舊格式 config.php 時：讀 PHP 並轉成 JSON（轉成功才把舊檔移到 config_history/）。
 * 檔案有錯時當作空物件，錯誤訊息見 configCustomError()
 */
function configCustom($reload = false)
{
    static $c = null;
    if ($c !== null && !$reload) {
        return $c;
    }
    $c = [];
    try {
        $json = configReadFile(APP_CUSTOM_CONFIG);
        if ($json !== null) {
            return $c = $json;
        }
        foreach ([APP_CUSTOM_CONFIG_PHP, APP_CUSTOM_CONFIG_OLD] as $php) {
            $old = configReadFile($php);
            if ($old !== null) {
                $c = $old;
                configMigrate($php, $old);
                break;
            }
        }
    } catch (Throwable $e) {
        configCustomError('custom/libs/config: ' . $e->getMessage());
        $c = [];
    }
    return $c;
}

/** 舊格式 PHP 設定檔轉成 custom/libs/config.json；成功後舊檔移進 config_history/（失敗就留著，下次再試） */
function configMigrate($phpFile, array $values)
{
    $lock = configLock();
    try {
        if (is_file(APP_CUSTOM_CONFIG)) {
            return;   // 別的請求剛轉好了
        }
        configWriteCustom(['_說明' => configCustomNote()] + $values, false);
        if (configReadFile(APP_CUSTOM_CONFIG) == ['_說明' => configCustomNote()] + $values) {
            configHistoryDir();
            @rename($phpFile, APP_CONFIG_HISTORY_DIR . '/config.' . configStamp() . '.migrated.php');
        }
    } catch (Throwable $e) {
        error_log('[tdx-proxy] config migrate: ' . $e->getMessage());
    } finally {
        configUnlock($lock);
    }
}

function configCustomNote()
{
    return '只放要覆蓋 libs/config.json 預設值的項目與私密值；每一項的說明見 libs/config.json。後臺「設定管理」會改寫這個檔案（舊版在 config_history/）。底線開頭的名稱是註記，程式不讀。';
}

function configStamp()
{
    return (new DateTime('now', new DateTimeZone('Asia/Taipei')))->format('Ymd-His');
}

function configHistoryDir()
{
    if (!is_dir(APP_CONFIG_HISTORY_DIR) && !@mkdir(APP_CONFIG_HISTORY_DIR, 0755, true) && !is_dir(APP_CONFIG_HISTORY_DIR)) {
        throw new RuntimeException('cannot create custom/libs/config_history');
    }
}

/**
 * 寫入 custom/libs/config.json（整份）：$backup 時舊檔先存進 config_history/（保留 APP_CONFIG_HISTORY_KEEP 份）；
 * 先寫暫存檔、讀回來比對一致才換上。呼叫端要自己處理互斥（configLock）
 */
function configWriteCustom(array $values, $backup = true)
{
    if (!is_dir(APP_CUSTOM_LIBS) && !@mkdir(APP_CUSTOM_LIBS, 0755, true) && !is_dir(APP_CUSTOM_LIBS)) {
        throw new RuntimeException('cannot create custom/libs');
    }
    $json = json_encode($values ?: new stdClass(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    if ($backup && is_file(APP_CUSTOM_CONFIG)) {
        configHistoryDir();
        if (!@copy(APP_CUSTOM_CONFIG, APP_CONFIG_HISTORY_DIR . '/config.' . configStamp() . '.json')) {
            throw new RuntimeException('cannot back up config.json');
        }
        $old = glob(APP_CONFIG_HISTORY_DIR . '/config.*.json') ?: [];
        rsort($old);
        foreach (array_slice($old, APP_CONFIG_HISTORY_KEEP) as $f) {
            @unlink($f);
        }
    }
    $tmp = APP_CUSTOM_CONFIG . '.tmp' . getmypid();
    if (@file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('cannot write config.json');
    }
    if (json_decode((string) file_get_contents($tmp), true) != $values) {
        @unlink($tmp);
        throw new RuntimeException('written config.json does not read back the same values');
    }
    if (!@rename($tmp, APP_CUSTOM_CONFIG)) {
        @unlink($tmp);
        throw new RuntimeException('cannot replace config.json');
    }
}

/** 寫設定檔前的互斥鎖（後臺存檔、補 APP_SECRET_KEY 同時發生時不會互相蓋掉）；回傳鎖的 handle，用完 configUnlock */
function configLock()
{
    if (!is_dir(APP_CUSTOM_LIBS)) {
        @mkdir(APP_CUSTOM_LIBS, 0755, true);
    }
    $h = @fopen(APP_CUSTOM_LIBS . '/.config.lock', 'c');
    if ($h) {
        flock($h, LOCK_EX);
    }
    return $h;
}

function configUnlock($h)
{
    if ($h) {
        flock($h, LOCK_UN);
        fclose($h);
    }
}

// 第一次執行：建立目錄、.htaccess 與 custom/libs/config.json（在讀設定之前，這次請求就能讀到新的 APP_SECRET_KEY）
if (!is_file(APP_CUSTOM_CONFIG) || !is_file(APP_CUSTOM_DIR . '/.htaccess') || !is_file(APP_TEMP_DIR . '/.htaccess')) {
    configEnsureFiles();
}

// 依 libs/config.json 的清單 define 每一個設定（custom 有的用 custom 的值）
foreach (configItems() as $configName => $configItem) {
    if (!defined($configName)) {
        define($configName, array_key_exists($configName, configCustom()) ? configCustom()[$configName] : $configItem['default']);
    }
}
unset($configName, $configItem);

/** 目前的秘密字串（APP_SECRET_KEY；本次請求剛補寫進設定檔時用新值） */
function appSecretKey($set = null)
{
    static $key = null;
    if ($set !== null) {
        $key = $set;
    }
    return $key ?? APP_SECRET_KEY;
}

/**
 * 確保 APP_SECRET_KEY 存在：沒有就產生一組寫進 custom/libs/config.json（只加這一項，其他內容不動）。
 * 需要秘密字串的功能（後臺初始化）在建立任何東西之前先呼叫；寫不進去回 false，呼叫端不得繼續
 */
function configEnsureSecret()
{
    if (appSecretKey() !== '') {
        return true;
    }
    if (configCustomError()) {
        return false;   // 設定檔壞掉時不要覆寫它
    }
    $lock = configLock();
    try {
        $now = configCustom(true);
        if (is_string($now['APP_SECRET_KEY'] ?? null) && $now['APP_SECRET_KEY'] !== '') {
            appSecretKey($now['APP_SECRET_KEY']);
            return true;
        }
        $key = bin2hex(random_bytes(32));
        $now['APP_SECRET_KEY'] = $key;
        configWriteCustom($now, is_file(APP_CUSTOM_CONFIG));
        appSecretKey($key);
        return true;
    } catch (Throwable $e) {
        error_log('[tdx-proxy] config secret: ' . $e->getMessage());
        return false;
    } finally {
        configUnlock($lock);
    }
}

/** custom 裡不認得的名稱（拼錯或已不使用，不會生效；底線開頭的註記不算） */
function configUnknownKeys()
{
    return array_values(array_filter(array_diff(array_keys(configCustom()), array_keys(configItems())),
        function ($k) { return $k[0] !== '_'; }));
}

/**
 * 所有設定與目前生效的值（給狀態頁、說明文件、admin_settings）。
 * $full＝true（有 admin_settings 讀取權限）顯示 private 的實際值；secret 一律只顯示「已設定／未設定」。
 * 公開時 private 項目的 value 是預設值，customized＝custom 有自訂。source：custom＝custom 有寫、default＝用預設值
 */
function configReport($full = false)
{
    $c = configCustom();
    $out = [];
    foreach (configItems() as $name => $it) {
        $v = $name === 'APP_SECRET_KEY' ? appSecretKey() : constant($name);
        $inCustom = array_key_exists($name, $c);
        if ($it['level'] === 'secret') {
            $out[$name] = ['value' => $v !== '' ? '已設定' : '未設定', 'source' => $inCustom ? 'custom' : 'default', 'level' => 'secret'];
        } elseif ($it['level'] === 'private' && !$full) {
            $out[$name] = ['value' => $it['default'], 'default' => $it['default'], 'customized' => $inCustom, 'level' => 'private'];
        } else {
            $out[$name] = ['value' => $v, 'source' => $inCustom ? 'custom' : 'default', 'default' => $it['default'], 'level' => $it['level']];
        }
    }
    return $out;
}

/**
 * 公開顯示的開放網域：只列預設值中目前仍有效的，custom 另有自訂的只提示（不列出）。
 * 回傳 ['shown' => [...], 'customized' => bool]
 */
function configPublicOrigins()
{
    $default = configItems()['ALLOWED_ORIGINS']['default'];
    return ['shown' => array_values(array_intersect($default, ALLOWED_ORIGINS)),
            'customized' => array_key_exists('ALLOWED_ORIGINS', configCustom())];
}

/** 建立缺少的目錄、.htaccess 與 custom/libs/config.json（只有一組新的 APP_SECRET_KEY）；已存在的檔案不動 */
function configEnsureFiles()
{
    $deny = "Require all denied\n";
    foreach ([APP_CUSTOM_DIR, APP_CUSTOM_LIBS, APP_TEMP_DIR] as $dir) {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            error_log('[tdx-proxy] cannot create ' . $dir);
        }
    }
    foreach ([APP_CUSTOM_DIR, APP_TEMP_DIR] as $dir) {
        if (is_dir($dir) && !is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', $deny);
        }
    }
    if (!is_file(APP_CUSTOM_CONFIG) && !is_file(APP_CUSTOM_CONFIG_PHP) && !is_file(APP_CUSTOM_CONFIG_OLD) && is_dir(APP_CUSTOM_LIBS)) {
        $lock = configLock();
        try {
            if (!is_file(APP_CUSTOM_CONFIG)) {
                configWriteCustom(['_說明' => configCustomNote(), 'APP_SECRET_KEY' => bin2hex(random_bytes(32))], false);
            }
        } catch (Throwable $e) {
            error_log('[tdx-proxy] config init: ' . $e->getMessage());
        } finally {
            configUnlock($lock);
        }
    }
}


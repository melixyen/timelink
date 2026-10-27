<?php
/**
 * 轉乘演算法的設定讀取：參數與靜態定義都放在 libs/txapi/transfer/data/*.json（PHP 與 Node.js 共用），
 * 程式只讀檔，不寫死資料。
 *
 *   transfer_settings.json  演算法參數（Router）、方案標籤（Tags）、里程規則（Distance）、轉乘群組資料來源（GroupSources）
 *   transfer_seed.json      轉乘群組的人工補充（群組、步行、同站換車、ID 拼音）
 *   transfer_accel.json     可選的加速清單（地區、地區間走廊、轉乘大站）
 *
 * 產生檔（transfer_settings.json 的 GeneratedFiles：群組、連結紀錄、里程、加速清單）是用主機上的離線資料算的，
 * 放在 custom/libs/txapi/transfer/data/（不進版控、隨 custom/ 備份），每次改寫前把舊版留在 history/（TRANSFER_HISTORY_KEEP 份）。
 */

const TRANSFER_DATA_DIR = __DIR__ . '/data';   // 人工維護的輸入（隨程式佈署，受版控）
require_once __DIR__ . '/../../env_path.php';   // 單獨載入（沒有 config.php）時也能用 appEnvPath()
define('TRANSFER_GEN_DIR', (defined('APP_CUSTOM_LIBS') ? APP_CUSTOM_LIBS : appEnvPath('custom/libs')) . '/txapi/transfer/data');
// 舊位置 custom/libs/transfer/（txapi 整理前）→ 搬到 custom/libs/txapi/transfer/（只搬一次）
if (!is_dir(dirname(TRANSFER_GEN_DIR)) && is_dir(dirname(TRANSFER_GEN_DIR, 3) . '/transfer')) {
    @mkdir(dirname(TRANSFER_GEN_DIR, 2), 0755, true);
    @rename(dirname(TRANSFER_GEN_DIR, 3) . '/transfer', dirname(TRANSFER_GEN_DIR));
}   // 產生檔（相對 libs/txapi/transfer/data）
const TRANSFER_HISTORY_DIR = TRANSFER_GEN_DIR . '/history';
define('TRANSFER_TEMP_DIR', (defined('APP_TEMP_DIR') ? APP_TEMP_DIR : appEnvPath('download/tdx_proxy_files')) . '/transfer');   // 暫存（不進版控，重新佈署不會掉）
const TRANSFER_SETTINGS_FILE = TRANSFER_DATA_DIR . '/transfer_settings.json';

/** 讀 transfer_settings.json；$path 用點分隔，例如 'Router.MaxTrips' */
function transferSettings($path = null)
{
    static $s = null;
    if ($s === null) {
        $s = json_decode((string) @file_get_contents(TRANSFER_SETTINGS_FILE), true);
        if (!is_array($s)) {
            throw new RuntimeException('transfer_settings.json is missing or invalid');
        }
    }
    if ($path === null) {
        return $s;
    }
    $v = $s;
    foreach (explode('.', $path) as $k) {
        if (!is_array($v) || !array_key_exists($k, $v)) {
            throw new RuntimeException('transfer_settings.json: missing ' . $path);
        }
        $v = $v[$k];
    }
    return $v;
}

/**
 * 資料檔路徑（$key 為 Files 裡的名稱，例如 'Accel'）：
 *   GeneratedFiles → custom/libs/txapi/transfer/data/（產生檔；新主機還沒有時跑 /txapi/admin_transfer/build_groups、learn 產生）
 *   TempFiles      → download/tdx_proxy_files/transfer/（暫存，加速清單的分析進度）
 *   其餘           → libs/txapi/transfer/data/（人工維護的輸入，受版控）
 */
function transferDataFile($key)
{
    $name = transferSettings('Files.' . $key);
    if (in_array($key, transferSettings('GeneratedFiles'), true)) {
        if (!is_dir(TRANSFER_GEN_DIR)) {
            @mkdir(TRANSFER_GEN_DIR, 0755, true);
        }
        return TRANSFER_GEN_DIR . '/' . $name;
    }
    if (in_array($key, transferSettings('TempFiles'), true)) {
        if (!is_dir(TRANSFER_TEMP_DIR)) {
            @mkdir(TRANSFER_TEMP_DIR, 0755, true);
        }
        return TRANSFER_TEMP_DIR . '/' . $name;
    }
    return TRANSFER_DATA_DIR . '/' . $name;
}

/**
 * 改寫產生檔：內容有變時先把舊版移到 history/<檔名>.<時間>.json（保留 TRANSFER_HISTORY_KEEP 份），再寫入（先寫暫存檔再改名）。
 * 回傳是否寫入成功
 */
function transferWriteGenerated($key, $content)
{
    $f = transferDataFile($key);
    if (is_file($f) && (string) @file_get_contents($f) === $content) {
        return true;
    }
    $keep = defined('TRANSFER_HISTORY_KEEP') ? (int) TRANSFER_HISTORY_KEEP : 5;
    if ($keep > 0 && is_file($f)) {
        if (!is_dir(TRANSFER_HISTORY_DIR)) {
            @mkdir(TRANSFER_HISTORY_DIR, 0755, true);
        }
        $ts = (new DateTime('@' . (int) filemtime($f)))->setTimezone(new DateTimeZone('Asia/Taipei'))->format('Ymd-His');   // 台北時間
        @copy($f, TRANSFER_HISTORY_DIR . '/' . basename($f, '.json') . '.' . $ts . '.json');
        $old = transferHistory($key);
        foreach (array_slice($old, $keep) as $h) {
            @unlink(TRANSFER_HISTORY_DIR . '/' . $h['version']);
        }
    }
    $tmp = $f . '.tmp' . getmypid();
    if (@file_put_contents($tmp, $content) === false || !@rename($tmp, $f)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/** 某個產生檔的舊版清單（新的在前）：[['version' => 檔名, 'saved_at', 'bytes']] */
function transferHistory($key)
{
    $base = basename(transferSettings('Files.' . $key), '.json');
    $out = [];
    foreach (glob(TRANSFER_HISTORY_DIR . '/' . $base . '.*.json') ?: [] as $f) {
        if (preg_match('/\.(\d{8}-\d{6})\.json$/', $f, $m)) {
            $out[] = ['version' => basename($f), 'saved_at' => DateTime::createFromFormat('Ymd-His', $m[1])->format('Y-m-d H:i:s'),
                      'bytes' => filesize($f)];
        }
    }
    usort($out, function ($a, $b) {
        return strcmp($b['version'], $a['version']);
    });
    return $out;
}

/** 把產生檔換回某個舊版（目前的一份也會先存進 history/）；成功回 true */
function transferRestoreGenerated($key, $version)
{
    foreach (transferHistory($key) as $h) {
        if ($h['version'] === $version) {
            return transferWriteGenerated($key, (string) file_get_contents(TRANSFER_HISTORY_DIR . '/' . $version));
        }
    }
    return false;
}

if (!defined('TRANSFER_MAX_TRIPS')) {
    define('TRANSFER_MAX_TRIPS', (int) transferSettings('Router.MaxTrips'));
    define('TRANSFER_HORIZON', (int) transferSettings('Router.HorizonSeconds'));
    define('TRANSFER_ROUTE_BUDGET', (float) transferSettings('Router.RouteBudgetSeconds'));
    define('TRANSFER_TRANSFER_PENALTY', (int) transferSettings('Router.TransferPenaltySeconds'));
    define('TRANSFER_WALK_WEIGHT', (float) transferSettings('Router.WalkWeight'));
    define('TRANSFER_SHIFT_WAIT', (int) transferSettings('Router.ShiftWaitSeconds'));
    define('TRANSFER_MIN_GAIN', (int) transferSettings('Router.MinGainSeconds'));
    define('TRANSFER_TAGS', transferSettings('Tags'));
}

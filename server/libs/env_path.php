<?php
/**
 * 資料目錄的位置（由 config.php、sqlite.php 第一個 require 進來）。
 *
 * 不進版控的資料都放在「資料根目錄」底下：
 *   custom/                      站台設定與私密值、轉乘產生檔、後臺帳號庫、私密維護筆記
 *   download/                    TDX 下載檔；download/tdx_proxy_files/ 是快取類暫存（token、TDX 快取、查詢次數）
 *   sqlite/                      finder 資料庫與轉乘快取
 * （後臺首次設定用的 admin-setup.txt 是證明能改網站的觸發檔，仍放在網站根目錄）
 *
 * 資料根目錄預設是 libs/ 的上一層（../），也就是網站根目錄。要把資料放到別處（例如網站根目錄以外、
 * 開發環境的 home 底下），在網站根目錄（libs/ 的上一層）建立 env_path.php，回傳資料根目錄的路徑：
 *
 *   <?php
 *   return '/home/me/timelink-data';   // 絕對路徑
 *   // return '../../timelink-data';   // 相對路徑：與預設值 ../ 一樣，相對於 libs/
 *
 * env_path.php 只能 return 路徑字串，不要輸出任何內容（網站根目錄的 .php 可以直接從網址執行）；
 * 它不進版控（.gitignore 有列），每個環境自己建立。內容不是非空字串時忽略、改用預設值。
 * 搬家時把上面列的目錄與檔案整個搬過去；目錄不存在時第一次執行會自動建立（要有寫入權限）。
 */

const APP_ENV_PATH_DEFAULT = '../';
const APP_ENV_PATH_FILE    = __DIR__ . '/../env_path.php';

/** 讀網站根目錄的 env_path.php（只讀一次）；沒有或內容無效回 null */
function appEnvPathOverride()
{
    static $v = false;
    if ($v === false) {
        $v = null;
        if (is_file(APP_ENV_PATH_FILE)) {
            $r = (static function () {
                return require APP_ENV_PATH_FILE;
            })();
            if (is_string($r) && trim($r) !== '') {
                $v = trim($r);
            } else {
                error_log('[tdx-proxy] env_path.php 沒有回傳路徑字串，改用預設的 ' . APP_ENV_PATH_DEFAULT);
            }
        }
    }
    return $v;
}

/** 絕對路徑（/、\、C:/ 開頭）原樣使用，其他相對於 libs/ */
function appEnvPathResolve($p)
{
    $p = rtrim(str_replace('\\', '/', $p), '/');
    if ($p === '') {
        return '/';
    }
    return preg_match('#^(/|[A-Za-z]:/)#', $p) ? $p : __DIR__ . '/' . $p;
}

/** 資料根目錄（結尾沒有 /） */
define('APP_ENV_PATH', appEnvPathResolve(appEnvPathOverride() ?? APP_ENV_PATH_DEFAULT));

/** 組合資料根目錄底下的路徑：appEnvPath('sqlite') → <資料根目錄>/sqlite；不帶參數回傳資料根目錄 */
function appEnvPath($rel = '')
{
    $rel = ltrim(str_replace('\\', '/', (string) $rel), '/');
    return $rel === '' ? APP_ENV_PATH : APP_ENV_PATH . '/' . $rel;
}

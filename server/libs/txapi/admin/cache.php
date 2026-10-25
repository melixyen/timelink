<?php
/**
 * 快取管理（路由權限 admin_cache）：快取本體在 libs/railcache.php（TDX 轉發用），這裡只有管理入口。
 */

/**
 * GET /txapi/admin_cache[/summary][&list=1]   快取統計與清單（admin_cache 讀取權限）
 *     /txapi/admin_cache/purge?purge=all|<路徑前綴>   清除（寫入權限）
 */
function actionAdminCache()
{
    header('Cache-Control: no-store');
    $func = strtolower(trim((string) ($_GET['func'] ?? '')));
    $purge = $_GET['purge'] ?? $_POST['purge'] ?? '';
    if ($func === 'purge' || (is_string($purge) && $purge !== '')) {
        adminRequire('admin_cache', 'write');
        if (!is_string($purge) || $purge === '') {
            appError(40010, ['detail' => 'purge 參數必填：all 或路徑前綴']);
        }
        $entries = railCacheEntries();
        $removed = [];
        foreach ($entries as $id => $meta) {
            if ($purge === 'all' || stripos($meta['path'], $purge) === 0) {
                railCacheDelete($meta['key'], $meta['mode']);
                $removed[] = $meta['path'];
            }
        }
        if ($purge === 'all') {
            foreach (array_keys(CACHE_RULES) as $mode) {
                @unlink(cacheDir($mode) . '/stats.json');
            }
        }
        sendJson(200, ['ok' => true, 'action' => 'admin_cache', 'func' => 'purge', 'purged' => count($removed), 'paths' => $removed]);
    }
    if ($func !== '' && $func !== 'summary') {
        sendJson(400, ['ok' => false, 'error' => 'Unknown func: ' . $func, 'funcs' => ['summary', 'purge']]);
    }
    adminRequire('admin_cache', 'read');
    sendJson(200, ['ok' => true, 'action' => 'admin_cache', 'func' => 'summary'] + railCacheSummary(!empty($_GET['list'])));
}

<?php
/**
 * 監控用公開資訊：GET /txapi/monitor
 *
 * 給 /panel/ 監控面板（libs/html/panel/index.html）使用，不需要密碼。
 * 只輸出服務狀態、TDX 流量紀錄、快取容量／設定／清單、下載狀態、finder 可用功能與 SQLite 狀態這類基本資訊，
 * 不含金鑰、密碼，也不能清除快取（清除請用後臺的 /txapi/admin_cache/purge）。
 */

function actionMonitor()
{
    header('Cache-Control: no-store');
    $tz = new DateTimeZone('Asia/Taipei');

    $out = [
        'ok'      => true,
        'action'  => 'monitor',
        'now'     => (new DateTime('now', $tz))->format('Y-m-d H:i:s'),
        'service' => [
            'name'           => PRODUCT_SERVICE_NAME,
            'product'        => PRODUCT_NAME,
            'configured'     => isConfigured(),
            // 開放網域只公開預設值，custom 的自訂網域只提示有沒有（實際名單要後臺 admin_settings 權限）
            'allowed_origins' => array_map(function ($o) {
                return $o === '' ? '(無 Origin)' : ($o === 'null' ? 'null（本機檔案）' : $o);
            }, configPublicOrigins()['shown']),
            'allowed_origins_custom' => configPublicOrigins()['customized'],
            'php'            => PHP_VERSION,
            'readme'         => '/txapi/readme',
        ],
        'site'      => function_exists('siteInfo') ? siteInfo() : null,
        'ratelimit' => null,
        'cache'     => null,
    ];

    if (function_exists('rateLimitReport')) {
        $r = rateLimitReport();
        unset($r['ok'], $r['action']);
        $out['ratelimit'] = $r;
    }
    if (function_exists('railCacheSummary')) {
        $out['cache'] = railCacheSummary(true);
    }
    // 下載狀態（/panel/#/download）
    if (function_exists('downloadListData')) {
        $d = downloadListData('tdx');
        unset($d['ok'], $d['action']);
        $out['download'] = $d;
    }
    // SQLite 與下載資料是否完整（/panel/#/download）；finder 各功能與資料表的明細改在後臺 /txapi/admin_finder
    if (function_exists('finderStatus')) {
        try {
            $fs = finderStatus();
            $out['finder'] = ['sqlite' => $fs['sqlite'] ?? null, 'download' => $fs['download'] ?? null];
        } catch (Throwable $e) {
            $out['finder'] = ['error' => $e->getMessage()];
        }
    }

    sendJson(200, $out);
}

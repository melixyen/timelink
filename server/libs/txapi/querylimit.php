<?php
/**
 * 自訂查詢 API（/txapi/transfer、/txapi/finder，以及客端的 public_、app_ 開頭的 action）的同一 IP 次數限制：每分鐘、每小時固定時間窗。
 * 上限在 custom/libs/config.json（沒寫的用 libs/config.json 預設值；QUERY_RATE_LIMIT_PER_MINUTE、QUERY_RATE_LIMIT_PER_HOUR；0＝不限制），超過回 429（errors.php 的 42901、42902）。
 *
 * - IP 用 REMOTE_ADDR（主機前面若有 CDN，要確認它已把 REMOTE_ADDR 換成使用者的真實 IP）
 * - 計數存在 download/tdx_proxy_files/cache/querylimit.sqlite（禁止網址存取；IP 只存雜湊，不在 /txapi/sqlite 的清單內）
 * - 說明文件類（func=help、finder func=list）與有後臺身分（面板登入或 PAT）的請求不計
 * - 計數要即時才擋得準，所以不像流量紀錄那樣累積後再寫；每次查詢一個交易（synchronous=NORMAL）
 * - 每個回應帶 X-Query-Limit-Minute／X-Query-Remaining-Minute／X-Query-Limit-Hour／X-Query-Remaining-Hour
 *
 * 執行標記（queryLimitMark）：特定的重功能另外限制「同一 IP 在一段時間內最多執行幾次」，每個標記的時間與次數在 QUERY_LIMIT_MARKS 設定
 * （秒數、次數都是 libs/config.json 的設定項目，可在 custom/libs/config.json 調整）。
 * 同一個資料庫的 marks 表：每個 IP、每個標記一列（主鍵 k＝IP 雜湊＋mark），start＝這個時間窗的開始、cnt＝時間窗內執行了幾次；一般 API 不寫。
 * 被呼叫時：還在時間窗內 → cnt 已到上限就回 429（42904），否則 cnt＋1；超過時間窗 → start＝現在、cnt＝1（同一 IP 都是更新，新的 IP 才新增）。
 * 查與寫在同一個交易，同時送來的請求不會一起超過上限
 */

const QUERY_LIMIT_ACTIONS = ['transfer', 'finder'];
const QUERY_LIMIT_PREFIXES = ['public_', 'app_'];   // 客端 API：Guest 先只有這一道 IP 次數防護（會員系統開工後依等級另設上限）

/**
 * 限制設定值的唯一來源：設定檔（custom/libs/config.json，沒寫的用 libs/config.json 的預設值）的 QUERY_RATE_LIMIT_PER_MINUTE、QUERY_RATE_LIMIT_PER_HOUR、QUERY_MAX_RANGE_MINUTES。
 * 程式與說明文件都從這裡讀；設定檔沒有定義（或設 0）＝不限制
 */
function queryLimitSettings()
{
    return [
        'minute' => defined('QUERY_RATE_LIMIT_PER_MINUTE') ? max(0, (int) QUERY_RATE_LIMIT_PER_MINUTE) : 0,
        'hour'   => defined('QUERY_RATE_LIMIT_PER_HOUR') ? max(0, (int) QUERY_RATE_LIMIT_PER_HOUR) : 0,
        'range'  => defined('QUERY_MAX_RANGE_MINUTES') ? max(0, (int) QUERY_MAX_RANGE_MINUTES) : 0,
    ];
}

/** 說明文件用的文字：分鐘數 → 「3 小時」「90 分鐘」，0 → 「不限」 */
function queryLimitText($v, $unit = '')
{
    if ($v <= 0) {
        return '不限';
    }
    if ($unit === 'minutes') {
        return $v % 60 === 0 ? ($v / 60) . ' 小時' : $v . ' 分鐘';
    }
    return $v . ' 次';
}
const QUERY_LIMIT_FREE_FUNCS = ['', 'help', 'list', 'errors'];

/** 這次請求要不要計數 */
function queryLimitApplies($action)
{
    $prefixed = false;
    foreach (QUERY_LIMIT_PREFIXES as $p) {
        $prefixed = $prefixed || strpos($action, $p) === 0;
    }
    if (!$prefixed && !in_array($action, QUERY_LIMIT_ACTIONS, true)) {
        return false;
    }
    // 說明文件類不計；客端 API 只有功能清單（沒帶 func）不計，list 等都是實際查詢
    $func = strtolower(trim((string) ($_GET['func'] ?? '')));
    if ($prefixed ? $func === '' : in_array($func, QUERY_LIMIT_FREE_FUNCS, true)) {
        return false;
    }
    return !(function_exists('downloadAdminOk') && downloadAdminOk());
}

/** 檢查並計數；超過上限直接回 429 結束 */
function queryLimitCheck($action)
{
    if (!queryLimitApplies($action)) {
        return;
    }
    $cfg = queryLimitSettings();
    $limits = ['m' => [$cfg['minute'], 60, 42901, 'Minute'],
               'h' => [$cfg['hour'], 3600, 42902, 'Hour']];
    if ($limits['m'][0] <= 0 && $limits['h'][0] <= 0) {
        return;
    }
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $key = substr(hash('sha256', 'querylimit|' . $ip . '|' . __DIR__), 0, 32);
    $now = time();
    try {
        if (!is_dir(TDX_CACHE_DIR)) {
            @mkdir(TDX_CACHE_DIR, 0755, true);
        }
        $pdo = new PDO('sqlite:' . TDX_CACHE_DIR . '/querylimit.sqlite', null, null,
                       [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');   // 一次查詢一個交易；WAL 模式下不必每次都強制寫進磁碟
        $pdo->exec('CREATE TABLE IF NOT EXISTS hits (k TEXT NOT NULL, win TEXT NOT NULL, start INTEGER NOT NULL, n INTEGER NOT NULL, PRIMARY KEY (k, win))');
        $pdo->exec('BEGIN IMMEDIATE');
        $sel = $pdo->prepare('SELECT start, n FROM hits WHERE k = ? AND win = ?');
        $state = [];
        foreach ($limits as $w => list($limit, $len, $code)) {
            $start = $now - $now % $len;
            $sel->execute([$key, $w]);
            $row = $sel->fetch(PDO::FETCH_NUM);
            $n = $row && (int) $row[0] === $start ? (int) $row[1] : 0;
            $state[$w] = [$start, $n];
            if ($limit > 0 && $n >= $limit) {
                $pdo->exec('COMMIT');
                $retry = $start + $len - $now;
                header('Retry-After: ' . $retry);
                appError($code, ['limit' => $limit, 'retry' => $retry], ['retryAfter' => $retry]);
            }
        }
        $up = $pdo->prepare('INSERT INTO hits (k, win, start, n) VALUES (?, ?, ?, ?) ON CONFLICT (k, win) DO UPDATE SET start = excluded.start, n = excluded.n');
        foreach ($limits as $w => list($limit, $len, , $label)) {
            list($start, $n) = $state[$w];
            $up->execute([$key, $w, $start, $n + 1]);
            if ($limit > 0) {
                header('X-Query-Limit-' . $label . ': ' . $limit);
                header('X-Query-Remaining-' . $label . ': ' . max(0, $limit - $n - 1));
            }
        }
        // 偶爾清掉過期的計數
        if (mt_rand(1, 200) === 1) {
            $pdo->prepare('DELETE FROM hits WHERE start < ?')->execute([$now - 7200]);
        }
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        // 計數失敗不擋查詢（例如磁碟或鎖定問題）
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}

/**
 * 執行標記的設定：標記 => [時間窗秒數的設定項目, 可執行次數的設定項目, 預設秒數, 預設次數, 功能名稱（錯誤訊息用）, 後臺身分是否不受限]。
 *   REQUEST_JOURNEYS__FULL_SEARCH_LIMIT_IP     journeys 的 full_search（研究工具，一次最多算 12 秒）：30 秒內 1 次；後臺身分不受限
 *   REQUEST_ADMIN_DOWNLOAD__MIRROR_LIMIT_IP    admin_download 的 mirror（本機同步線上的離線資料＝dump）：2 小時內 200 次（清單、每個檔案各算一次）；
 *                                              本來就要 PAT，所以後臺身分也受限
 */
const QUERY_LIMIT_MARKS = [
    'REQUEST_JOURNEYS__FULL_SEARCH_LIMIT_IP'  => ['QUERY_FULL_SEARCH_IP_SECONDS', 'QUERY_FULL_SEARCH_IP_TIMES', 30, 1, 'full_search', true],
    'REQUEST_ADMIN_DOWNLOAD__MIRROR_LIMIT_IP' => ['QUERY_MIRROR_IP_SECONDS', 'QUERY_MIRROR_IP_TIMES', 7200, 200, 'mirror', false],
];

/** 標記的 [時間窗秒數, 可執行次數, 功能名稱, 後臺身分是否不受限]（設定檔沒寫用預設） */
function queryLimitMarkSettings($mark)
{
    list($secKey, $timesKey, $sec, $times, $feature, $exempt) = QUERY_LIMIT_MARKS[$mark];
    return [defined($secKey) ? max(0, (int) constant($secKey)) : $sec, defined($timesKey) ? max(0, (int) constant($timesKey)) : $times, $feature, $exempt];
}

/**
 * 執行標記：同一 IP 在標記的時間窗內已經執行到可執行次數，就回 429（42904，附 Retry-After＝時間窗結束還有幾秒）結束；
 * 否則記一次（時間窗內 cnt＋1，超過時間窗重新開始 cnt＝1），呼叫端接著執行。秒數或次數設 0＝不限制。紀錄失敗不擋請求
 */
function queryLimitMark($mark)
{
    list($seconds, $times, $feature, $exemptAdmin) = queryLimitMarkSettings($mark);
    if ($seconds <= 0 || $times <= 0 || ($exemptAdmin && function_exists('downloadAdminOk') && downloadAdminOk())) {
        return;
    }
    $key = substr(hash('sha256', 'querylimit|' . (string) ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . __DIR__), 0, 32);
    $now = time();
    try {
        if (!is_dir(TDX_CACHE_DIR)) {
            @mkdir(TDX_CACHE_DIR, 0755, true);
        }
        $pdo = new PDO('sqlite:' . TDX_CACHE_DIR . '/querylimit.sqlite', null, null,
                       [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        // 舊版的 marks（沒有 cnt 欄位）直接重建：只是限制頻率用的紀錄
        $old = $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'marks'")->fetchColumn();
        if ($old !== false && stripos((string) $old, 'cnt') === false) {
            $pdo->exec('DROP TABLE marks');
        }
        $pdo->exec('CREATE TABLE IF NOT EXISTS marks (k TEXT NOT NULL, mark VARCHAR(80) NOT NULL, start INTEGER NOT NULL, cnt INTEGER NOT NULL,
                    PRIMARY KEY (k, mark)) WITHOUT ROWID');
        $pdo->exec('BEGIN IMMEDIATE');
        $sel = $pdo->prepare('SELECT start, cnt FROM marks WHERE k = ? AND mark = ?');
        $sel->execute([$key, $mark]);
        $row = $sel->fetch(PDO::FETCH_NUM);
        $inWindow = $row && (int) $row[0] > $now - $seconds;
        if ($inWindow && (int) $row[1] >= $times) {
            $pdo->exec('COMMIT');
            $retry = max(1, (int) $row[0] + $seconds - $now);
            header('Retry-After: ' . $retry);
            appError(42904, ['feature' => $feature, 'seconds' => $seconds, 'times' => $times, 'retry' => $retry], ['retryAfter' => $retry, 'mark' => $mark]);
        }
        // 時間窗內＋1；不在時間窗內（或第一次）從現在重新開始、cnt＝1。同一 IP 一律更新，新的 IP 才新增
        $pdo->prepare('INSERT INTO marks (k, mark, start, cnt) VALUES (?, ?, ?, ?) ON CONFLICT (k, mark) DO UPDATE SET start = excluded.start, cnt = excluded.cnt')
            ->execute([$key, $mark, $inWindow ? (int) $row[0] : $now, $inWindow ? (int) $row[1] + 1 : 1]);
        if (mt_rand(1, 200) === 1) {
            $pdo->prepare('DELETE FROM marks WHERE start < ?')->execute([$now - 86400]);   // 一天沒再來的 IP（時間窗都早已結束）
        }
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}

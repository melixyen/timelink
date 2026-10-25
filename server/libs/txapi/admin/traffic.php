<?php
/**
 * 流量管理：以 IP 為單位記錄請求次數，並可封鎖、解鎖 IP（路由權限 admin_traffic）。
 *
 *   紀錄  download/tdx_proxy_files/cache/traffic.sqlite（快取類，丟了只會少掉歷史；禁止網址存取）
 *         每個請求只追加到 traffic.buffer，滿 TRAFFIC_FLUSH_BYTES 或 TRAFFIC_FLUSH_SECONDS 秒才合併寫入（見 trafficRecord）
 *         traffic_minute  ip × 分鐘 × 種類（api＝TDX 轉發，其餘為 action 名稱）的次數，保留 TRAFFIC_LOG_RETENTION_DAYS 天
 *         traffic_ip      每個 IP 的第一次、最後一次請求時間、保留期內總量、最後的路徑與 User-Agent
 *   封鎖  custom/libs/txapi/admin/admin.sqlite 的 admin_ip_blocks（要備份的設定）；每次變更同時寫出
 *         custom/libs/txapi/admin/ip_blocks.php（ip => 到期時間，0＝永久），每個請求只 include 這個小檔，不開資料庫
 *
 * 被封鎖的 IP：所有請求回 403（錯誤代碼 40303）。例外：已登入後臺或帶有效 PAT 的請求、admin_auth（登入）——
 * 避免管理者把自己鎖在外面。
 */

const TRAFFIC_DB_FILE   = TDX_CACHE_DIR . '/traffic.sqlite';
const ADMIN_BLOCKS_FILE = ADMIN_DIR . '/ip_blocks.php';

function trafficDb()
{
    static $pdo = null;
    if ($pdo === null) {
        if (!is_dir(TDX_CACHE_DIR)) {
            @mkdir(TDX_CACHE_DIR, 0755, true);
        }
        $new = !is_file(TRAFFIC_DB_FILE);
        $pdo = new PDO('sqlite:' . TRAFFIC_DB_FILE, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                                                                 PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $pdo->exec('PRAGMA busy_timeout = 2000');
        if ($new) {
            $pdo->exec('PRAGMA journal_mode = WAL');
        }
        $pdo->exec('PRAGMA synchronous = NORMAL');   // WAL 模式下不必每次交易都強制寫進磁碟
        $pdo->exec('CREATE TABLE IF NOT EXISTS traffic_minute (ip TEXT NOT NULL, minute INTEGER NOT NULL, kind TEXT NOT NULL,
                    n INTEGER NOT NULL, PRIMARY KEY (ip, minute, kind)) WITHOUT ROWID');
        $pdo->exec('CREATE INDEX IF NOT EXISTS traffic_minute_time ON traffic_minute (minute)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS traffic_ip (ip TEXT PRIMARY KEY, first_seen INTEGER NOT NULL, last_seen INTEGER NOT NULL,
                    total INTEGER NOT NULL, last_path TEXT, last_ua TEXT) WITHOUT ROWID');
    }
    return $pdo;
}

/**
 * 記一筆請求（失敗不影響請求本身）。不直接寫資料庫：
 *   1. 回應送出後（fastcgi_finish_request）才記錄，使用者不用等磁碟
 *   2. 只在暫存檔 traffic.buffer 尾端追加一行（帶鎖，幾微秒）
 *   3. 暫存檔超過 TRAFFIC_FLUSH_BYTES 或第一行超過 TRAFFIC_FLUSH_SECONDS 秒時，由當下的請求一次合併寫進 SQLite（一個交易）
 * 後臺「流量管理」讀取前會先強制寫入，看到的是即時數字
 */
const TRAFFIC_BUFFER_FILE   = TDX_CACHE_DIR . '/traffic.buffer';
const TRAFFIC_FLUSH_LOCK    = TDX_CACHE_DIR . '/traffic.flush.lock';
// TRAFFIC_FLUSH_BYTES、TRAFFIC_FLUSH_SECONDS：設定項目（libs/config.json）

function trafficRecord($kind, $path)
{
    $ip = adminClientIp();
    if ($ip === '') {
        return;
    }
    $line = implode("\t", [time(), $ip, trafficClean(mb_substr((string) $kind, 0, 40)), trafficClean(mb_substr((string) $path, 0, 200)),
                            trafficClean(mb_substr(adminUserAgent(), 0, 300))]) . "\n";
    static $registered = false;
    static $lines = [];
    $lines[] = $line;
    if ($registered) {
        return;
    }
    $registered = true;
    register_shutdown_function(function () use (&$lines) {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        try {
            if (!is_dir(TDX_CACHE_DIR)) {
                @mkdir(TDX_CACHE_DIR, 0755, true);
            }
            @file_put_contents(TRAFFIC_BUFFER_FILE, implode('', $lines), FILE_APPEND | LOCK_EX);
            trafficFlush(false);
        } catch (Throwable $e) {
            error_log('[tdx-proxy] traffic: ' . $e->getMessage());
        }
    });
}

/** 暫存檔一行裡不能有 tab 與換行 */
function trafficClean($s)
{
    return str_replace(["\t", "\r", "\n"], ' ', (string) $s);
}

/**
 * 把暫存檔合併寫進 traffic.sqlite。$force＝false 時只在超過大小或時間門檻才做；
 * 同時只會有一個請求在寫（搶不到鎖就跳過，交給下一個請求）。回傳寫入的筆數
 */
function trafficFlush($force)
{
    clearstatcache(true, TRAFFIC_BUFFER_FILE);
    $pending = glob(TDX_CACHE_DIR . '/traffic.buffer.*.flushing') ?: [];   // 之前寫到一半中斷的
    if (!is_file(TRAFFIC_BUFFER_FILE) && !$pending) {
        return 0;
    }
    if (!$force && !$pending) {
        $size = (int) @filesize(TRAFFIC_BUFFER_FILE);
        $first = 0;
        if ($size < TRAFFIC_FLUSH_BYTES && ($h = @fopen(TRAFFIC_BUFFER_FILE, 'r'))) {
            $first = (int) strtok((string) fgets($h, 64), "\t");
            fclose($h);
        }
        if ($size < TRAFFIC_FLUSH_BYTES && $first > 0 && time() - $first < TRAFFIC_FLUSH_SECONDS) {
            return 0;
        }
    }
    $lock = @fopen(TRAFFIC_FLUSH_LOCK, 'c');
    if (!$lock || !flock($lock, $force ? LOCK_EX : LOCK_EX | LOCK_NB)) {
        if ($lock) {
            fclose($lock);
        }
        return 0;
    }
    $n = 0;
    try {
        if (is_file(TRAFFIC_BUFFER_FILE)) {
            $mine = TDX_CACHE_DIR . '/traffic.buffer.' . getmypid() . '-' . mt_rand() . '.flushing';
            if (@rename(TRAFFIC_BUFFER_FILE, $mine)) {
                $pending[] = $mine;
            }
        }
        foreach ($pending as $f) {
            // 等正在追加的請求寫完（它們拿著這個檔案的鎖）
            $h = @fopen($f, 'r');
            if (!$h) {
                continue;
            }
            flock($h, LOCK_SH);
            $raw = stream_get_contents($h);
            flock($h, LOCK_UN);
            fclose($h);
            $n += trafficWriteLines(explode("\n", (string) $raw));
            @unlink($f);
        }
        if ($n && mt_rand(1, 50) === 1) {
            trafficPrune();
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    return $n;
}

/** 讀流量紀錄前先把暫存檔寫進資料庫（失敗不影響讀取） */
function trafficFlushNow()
{
    try {
        trafficFlush(true);
    } catch (Throwable $e) {
        error_log('[tdx-proxy] traffic flush: ' . $e->getMessage());
    }
}

/** 暫存檔的多行 → 依 IP × 分鐘 × 種類合併，一個交易寫入 */
function trafficWriteLines(array $lines)
{
    $min = [];
    $ips = [];
    foreach ($lines as $l) {
        $p = explode("\t", $l);
        if (count($p) < 5 || (int) $p[0] <= 0) {
            continue;
        }
        list($ts, $ip, $kind, $path, $ua) = $p;
        $ts = (int) $ts;
        $k = $ip . "\t" . intdiv($ts, 60) . "\t" . $kind;
        $min[$k] = ($min[$k] ?? 0) + 1;
        if (!isset($ips[$ip])) {
            $ips[$ip] = ['first' => $ts, 'last' => $ts, 'n' => 0, 'path' => $path, 'ua' => $ua];
        }
        $ips[$ip]['n']++;
        $ips[$ip]['first'] = min($ips[$ip]['first'], $ts);
        if ($ts >= $ips[$ip]['last']) {
            $ips[$ip]['last'] = $ts;
            $ips[$ip]['path'] = $path;
            $ips[$ip]['ua'] = $ua;
        }
    }
    if (!$min) {
        return 0;
    }
    $db = trafficDb();
    $db->exec('BEGIN IMMEDIATE');
    try {
        $st = $db->prepare('INSERT INTO traffic_minute (ip, minute, kind, n) VALUES (?, ?, ?, ?)
                            ON CONFLICT (ip, minute, kind) DO UPDATE SET n = n + excluded.n');
        foreach ($min as $k => $c) {
            list($ip, $m, $kind) = explode("\t", $k);
            $st->execute([$ip, (int) $m, $kind, $c]);
        }
        $st = $db->prepare('INSERT INTO traffic_ip (ip, first_seen, last_seen, total, last_path, last_ua) VALUES (?, ?, ?, ?, ?, ?)
                            ON CONFLICT (ip) DO UPDATE SET first_seen = MIN(first_seen, excluded.first_seen), total = total + excluded.total,
                            last_path = CASE WHEN excluded.last_seen >= last_seen THEN excluded.last_path ELSE last_path END,
                            last_ua = CASE WHEN excluded.last_seen >= last_seen THEN excluded.last_ua ELSE last_ua END,
                            last_seen = MAX(last_seen, excluded.last_seen)');
        foreach ($ips as $ip => $x) {
            $st->execute([$ip, $x['first'], $x['last'], $x['n'], $x['path'], $x['ua']]);
        }
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
    return array_sum($min);
}

/** 清掉超過保留天數的紀錄；traffic_ip 的總量改成保留期內的總和 */
function trafficPrune()
{
    $db = trafficDb();
    $keep = max(1, (int) TRAFFIC_LOG_RETENTION_DAYS);
    $min = intdiv(time(), 60) - $keep * 1440;
    $db->prepare('DELETE FROM traffic_minute WHERE minute < ?')->execute([$min]);
    $db->prepare('DELETE FROM traffic_ip WHERE last_seen < ?')->execute([time() - $keep * 86400]);
    $db->exec('UPDATE traffic_ip SET total = (SELECT COALESCE(SUM(n), 0) FROM traffic_minute m WHERE m.ip = traffic_ip.ip)');
}

/** 這次請求的 IP 是否被封鎖（讀 ip_blocks.php 小檔；過期的不算） */
function trafficIsBlocked($ip = null)
{
    static $list = null;
    if ($list === null) {
        $list = is_file(ADMIN_BLOCKS_FILE) ? (@include ADMIN_BLOCKS_FILE) : [];
        if (!is_array($list)) {
            $list = [];
        }
    }
    $ip = $ip ?? adminClientIp();
    if (!isset($list[$ip])) {
        return false;
    }
    return (int) $list[$ip] === 0 || (int) $list[$ip] > time();
}

/**
 * 每個請求進來時呼叫：記錄流量，被封鎖的 IP 直接回 403（已登入後臺、帶有效 PAT、或是登入動作除外）
 * $kind：'api'（TDX 轉發）或 action 名稱
 */
function trafficGate($kind, $path)
{
    trafficRecord($kind, $path);
    if ($kind !== 'admin_auth' && trafficIsBlocked() && adminCurrent() === null) {
        appError(40303, ['ip' => adminClientIp()]);
    }
}

/** 依 admin_ip_blocks 重寫 ip_blocks.php（先寫暫存檔再改名） */
function trafficWriteBlocksFile()
{
    $list = [];
    foreach (adminDb()->query('SELECT ip, expires_at FROM admin_ip_blocks') as $r) {
        $list[$r['ip']] = (int) $r['expires_at'];
    }
    $src = "<?php\n// 由 admin_traffic 自動產生（封鎖的 IP => 到期時間，0＝永久），請勿手動修改\nreturn " . var_export($list, true) . ";\n";
    $tmp = ADMIN_BLOCKS_FILE . '.tmp' . getmypid();
    if (@file_put_contents($tmp, $src) !== false && @rename($tmp, ADMIN_BLOCKS_FILE)) {
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate(ADMIN_BLOCKS_FILE, true);
        }
        return true;
    }
    @unlink($tmp);
    return false;
}

function trafficBlocks()
{
    $out = [];
    $now = time();
    foreach (adminDb()->query('SELECT * FROM admin_ip_blocks ORDER BY created_at DESC') as $r) {
        $out[] = ['ip' => $r['ip'], 'reason' => $r['reason'], 'created_by' => $r['created_by'], 'created_at' => adminTime($r['created_at']),
                  'expires_at' => $r['expires_at'] ? adminTime($r['expires_at']) : null,
                  'active' => !$r['expires_at'] || (int) $r['expires_at'] > $now];
    }
    return $out;
}

// ================================================================
// /txapi/admin_traffic

/**
 *   func=list（預設，讀取）   各 IP 的本分鐘、1 小時、24 小時、保留期總量、1 小時內尖峰每分鐘；sort=d1|h1|m1|total|last、q=IP 片段、limit
 *   func=ip&ip=…（讀取）      某 IP 最近 60 分鐘每分鐘、最近 24 小時每小時的次數，與各種類的分布
 *   func=blocks（讀取）       封鎖清單
 *   func=block（寫入，POST）  ip、reason、hours（0 或省略＝永久）
 *   func=unblock（寫入，POST）ip
 */
function actionAdminTraffic()
{
    header('Cache-Control: no-store');
    $func = strtolower(trim((string) ($_GET['func'] ?? 'list')));
    switch ($func) {
        case 'list':
        case '':
            adminRequire('admin_traffic', 'read');
            trafficFlushNow();
            trafficFuncList();
        case 'ip':
            adminRequire('admin_traffic', 'read');
            trafficFlushNow();
            trafficFuncIp();
        case 'blocks':
            adminRequire('admin_traffic', 'read');
            sendJson(200, ['ok' => true, 'action' => 'admin_traffic', 'func' => 'blocks', 'blocks' => trafficBlocks()]);
        case 'block':
        case 'unblock':
            adminRequire('admin_traffic', 'write');
            adminDbOrFail();
            $ip = trim((string) adminParam('ip', ''));
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                adminBadInput('ip 格式不正確');
            }
            if ($func === 'block') {
                $hours = (float) adminParam('hours', 0);
                $exp = $hours > 0 ? time() + (int) round($hours * 3600) : null;
                adminDb()->prepare('INSERT INTO admin_ip_blocks (ip, reason, created_by, created_at, expires_at) VALUES (?, ?, ?, ?, ?)
                                    ON CONFLICT (ip) DO UPDATE SET reason = excluded.reason, created_by = excluded.created_by,
                                    created_at = excluded.created_at, expires_at = excluded.expires_at')
                    ->execute([$ip, mb_substr(trim((string) adminParam('reason', '')), 0, 200), adminCurrent()['username'], time(), $exp]);
            } else {
                adminDb()->prepare('DELETE FROM admin_ip_blocks WHERE ip = ?')->execute([$ip]);
            }
            if (!trafficWriteBlocksFile()) {
                sendJson(500, ['ok' => false, 'error' => 'cannot write ' . ADMIN_BLOCKS_FILE]);
            }
            sendJson(200, ['ok' => true, 'action' => 'admin_traffic', 'func' => $func, 'ip' => $ip, 'blocks' => trafficBlocks()]);
        default:
            adminRequire('admin_traffic', 'read');
            sendJson(400, ['ok' => false, 'error' => 'Unknown func: ' . $func, 'funcs' => ['list', 'ip', 'blocks', 'block', 'unblock']]);
    }
}

function trafficFuncList()
{
    $db = trafficDb();
    $now = intdiv(time(), 60);
    $st = $db->prepare('SELECT ip, minute, SUM(n) AS s FROM traffic_minute WHERE minute >= ? GROUP BY ip, minute');
    $st->execute([$now - 1439]);
    $agg = [];
    $sum = ['m1' => 0, 'h1' => 0, 'd1' => 0];
    foreach ($st as $r) {
        $a = &$agg[$r['ip']];
        $a = $a ?? ['m1' => 0, 'h1' => 0, 'd1' => 0, 'peak' => 0];
        $s = (int) $r['s'];
        $a['d1'] += $s;
        $sum['d1'] += $s;
        if ($r['minute'] >= $now - 59) {
            $a['h1'] += $s;
            $sum['h1'] += $s;
            $a['peak'] = max($a['peak'], $s);
        }
        if ($r['minute'] >= $now) {
            $a['m1'] += $s;
            $sum['m1'] += $s;
        }
        unset($a);
    }
    $blocked = [];
    foreach (adminDbExists() ? trafficBlocks() : [] as $b) {
        if ($b['active']) {
            $blocked[$b['ip']] = $b;
        }
    }
    $q = trim((string) ($_GET['q'] ?? ''));
    $rows = [];
    foreach ($db->query('SELECT * FROM traffic_ip') as $r) {
        if ($q !== '' && strpos($r['ip'], $q) === false) {
            continue;
        }
        $a = $agg[$r['ip']] ?? ['m1' => 0, 'h1' => 0, 'd1' => 0, 'peak' => 0];
        $rows[] = ['ip' => $r['ip'], 'm1' => $a['m1'], 'h1' => $a['h1'], 'd1' => $a['d1'], 'peak_per_min' => $a['peak'],
                   'avg_per_min' => round($a['h1'] / 60, 2), 'total' => (int) $r['total'],
                   'first_seen' => adminTime($r['first_seen']), 'last_seen' => adminTime($r['last_seen']), 'last_ts' => (int) $r['last_seen'],
                   'last_path' => $r['last_path'], 'last_ua' => $r['last_ua'], 'blocked' => $blocked[$r['ip']] ?? null];
    }
    $sort = (string) ($_GET['sort'] ?? 'd1');
    $key = in_array($sort, ['m1', 'h1', 'd1', 'total'], true) ? $sort : 'last_ts';
    usort($rows, function ($x, $y) use ($key) {
        return $y[$key] <=> $x[$key] ?: $y['last_ts'] <=> $x['last_ts'];
    });
    $limit = max(1, min(1000, (int) ($_GET['limit'] ?? 200)));
    sendJson(200, ['ok' => true, 'action' => 'admin_traffic', 'func' => 'list', 'now' => adminTime(time()),
                   'retention_days' => (int) TRAFFIC_LOG_RETENTION_DAYS, 'ips' => count($rows),
                   'summary' => $sum + ['blocked' => count($blocked)], 'rows' => array_slice($rows, 0, $limit),
                   'more' => count($rows) > $limit, 'my_ip' => adminClientIp()]);
}

function trafficFuncIp()
{
    $ip = trim((string) ($_GET['ip'] ?? ''));
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        adminBadInput('ip 格式不正確');
    }
    $db = trafficDb();
    $now = intdiv(time(), 60);
    $minutes = array_fill(0, 60, 0);
    $hours = array_fill(0, 24, 0);
    $kinds = [];
    $st = $db->prepare('SELECT minute, kind, n FROM traffic_minute WHERE ip = ? AND minute >= ?');
    $st->execute([$ip, $now - 1439]);
    foreach ($st as $r) {
        $ago = $now - (int) $r['minute'];
        if ($ago < 60) {
            $minutes[59 - $ago] += (int) $r['n'];
        }
        $hours[23 - intdiv($ago, 60)] += (int) $r['n'];
        $kinds[$r['kind']] = ($kinds[$r['kind']] ?? 0) + (int) $r['n'];
    }
    arsort($kinds);
    $st = $db->prepare('SELECT * FROM traffic_ip WHERE ip = ?');
    $st->execute([$ip]);
    $info = $st->fetch() ?: null;
    sendJson(200, ['ok' => true, 'action' => 'admin_traffic', 'func' => 'ip', 'ip' => $ip,
                   'per_minute' => $minutes, 'per_hour' => $hours, 'kinds_24h' => $kinds,
                   'info' => $info ? ['first_seen' => adminTime($info['first_seen']), 'last_seen' => adminTime($info['last_seen']),
                                      'total' => (int) $info['total'], 'last_path' => $info['last_path'], 'last_ua' => $info['last_ua']] : null,
                   'blocked' => trafficIsBlocked($ip)]);
}

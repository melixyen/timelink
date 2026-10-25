<?php
/**
 * 後臺帳號系統核心：SQLite 資料庫、登入狀態、PAT、角色與路由權限。
 *
 * 資料庫 custom/libs/txapi/admin/admin.sqlite（不進版控、隨 custom/ 備份、禁止網址存取），資料表一律 admin_ 開頭
 * （與未來的遊客帳號系統區分）：
 *   admin_roles             角色；is_super＝1 的角色（manager）擁有所有路由的讀寫，包含以後新增的路由
 *   admin_role_permissions  角色 × 路由（/txapi/admin_ 開頭的 action）的讀、寫
 *   admin_users             帳號；密碼用 password_hash；is_default_password＝還是初始化的預設密碼
 *   admin_sessions          面板登入狀態（cookie 原文不存，只存 HMAC）
 *   admin_login_logs        登入紀錄（成功與失敗、時間、IP），保留 ADMIN_LOG_RETENTION_DAYS 天
 *   admin_tokens            Personal Access Token（tdxp_ 開頭，原文只在產生時顯示一次，只存 HMAC）
 *   admin_ip_blocks         封鎖的 IP（traffic.php；同時寫出 ip_blocks.php 給每個請求快速判斷）
 *
 * 身分來源（adminCurrent）依序：
 *   1. Authorization: Bearer tdxp_…（PAT，給 API 與 AI 代理）
 *   2. cookie tdx_admin（後臺頁面登入；寫入動作另需 X-CSRF-Token 標頭）
 * admin_ 開頭的 action 一律只接受 PAT 或後臺登入，不接受其他任何密碼。
 *
 * 初始化（第一次用到後臺時）：先確保 APP_SECRET_KEY 存在（configEnsureSecret，沒有就產生並寫進 custom/libs/config.json），
 * 成功後才建資料庫 → manager 角色 → admin 帳號（密碼 admin，登入後提示更換、不強迫）。
 */

const ADMIN_DIR          = APP_CUSTOM_LIBS . '/txapi/admin';
// 舊位置 custom/libs/admin/（txapi 整理前）→ 搬到 custom/libs/txapi/admin/（只搬一次）
if (!is_dir(ADMIN_DIR) && is_dir(APP_CUSTOM_LIBS . '/admin')) {
    @mkdir(APP_CUSTOM_LIBS . '/txapi', 0755, true);
    @rename(APP_CUSTOM_LIBS . '/admin', ADMIN_DIR);
}
const ADMIN_DB_FILE      = ADMIN_DIR . '/admin.sqlite';
const ADMIN_SCHEMA       = 3;   // 2：加 admin_ip_blocks；3：加 admin_meta（首次設定），不再建立預設的 admin/admin
const ADMIN_COOKIE       = 'tdx_admin';
const ADMIN_PAT_PREFIX   = 'tdxp_';
const ADMIN_SUPER_ROLE   = 'manager';
const ADMIN_PASSWORD_MIN = 5;

/**
 * 有權限控管的路由（/txapi/admin_ 開頭的 action）：名稱 => [標題, 讀取代表, 寫入代表]
 * admin_auth（登入、自己的密碼與 PAT）每個帳號都能用，不在這裡
 */
const ADMIN_ROUTES = [
    'admin_settings'           => ['設定管理', '檢視所有設定值（金鑰只顯示已設定）、初始化檢查清單', '編輯設定、下載整包 custom/'],
    'admin_accounts'           => ['帳號管理', '帳號、角色、權限、登入紀錄、所有 PAT', '建立帳號、改密碼、改角色、建角色與權限、撤銷 PAT'],
    'admin_cache'              => ['快取管理', '快取統計與清單', '清除快取'],
    'admin_download'           => ['下載管理', '說明、清理前預覽', '同步、強制更新、清理'],
    'admin_download_to_sqlite' => ['資料庫重建', 'finder 資料庫狀態', '重建 finder 資料庫'],
    'admin_transfer'           => ['轉乘資料', '產生檔與舊版清單', 'build_groups、learn、換回舊版'],
    'admin_finder'             => ['查詢功能', 'finder 各功能、資料表是否最新與來源檔（面板「查詢功能」）', '—'],
    'admin_finder_try'         => ['查詢測試', '在面板輸入參數測試 finder 查詢（面板「查詢測試」）', '—'],
    'admin_traffic'            => ['流量管理', '各 IP 的請求頻率與總量、封鎖清單', '封鎖、解鎖 IP'],
];

class AdminSecretMissing extends RuntimeException
{
}

// ================================================================
// 資料庫

/** 資料庫是否已建立（公開請求不會因為帶了 cookie／Bearer 就觸發初始化） */
function adminDbExists()
{
    return is_file(ADMIN_DB_FILE);
}

/** 開啟資料庫；第一次用到時依序：秘密字串 → 建表 → 預設角色（不建帳號：第一個管理者由首次設定建立） */
function adminDb()
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    if (!adminDbExists() && !configEnsureSecret()) {
        throw new AdminSecretMissing('APP_SECRET_KEY 無法寫入 custom/libs/config.json，後臺無法初始化');
    }
    if (!is_dir(ADMIN_DIR) && !@mkdir(ADMIN_DIR, 0755, true) && !is_dir(ADMIN_DIR)) {
        throw new RuntimeException('cannot create ' . ADMIN_DIR);
    }
    if (!is_file(ADMIN_DIR . '/.htaccess')) {
        @file_put_contents(ADMIN_DIR . '/.htaccess', "Require all denied\n");
    }
    $pdo = new PDO('sqlite:' . ADMIN_DB_FILE, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                                                           PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA foreign_keys = ON');
    if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() < ADMIN_SCHEMA) {
        adminDbInit($pdo);
    }
    return $pdo;
}

function adminDbInit(PDO $pdo)
{
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() >= ADMIN_SCHEMA) {
            $pdo->exec('COMMIT');
            return;
        }
        $pdo->exec('CREATE TABLE IF NOT EXISTS admin_roles (
            id INTEGER PRIMARY KEY, name TEXT NOT NULL UNIQUE COLLATE NOCASE, description TEXT NOT NULL DEFAULT \'\',
            is_super INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS admin_role_permissions (
            role_id INTEGER NOT NULL REFERENCES admin_roles(id) ON DELETE CASCADE, route TEXT NOT NULL,
            can_read INTEGER NOT NULL DEFAULT 0, can_write INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (role_id, route))');
        $pdo->exec('CREATE TABLE IF NOT EXISTS admin_users (
            id INTEGER PRIMARY KEY, username TEXT NOT NULL UNIQUE COLLATE NOCASE, password_hash TEXT NOT NULL,
            role_id INTEGER NOT NULL REFERENCES admin_roles(id), is_default_password INTEGER NOT NULL DEFAULT 0,
            disabled INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL,
            password_changed_at INTEGER, last_login_at INTEGER, last_login_ip TEXT)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS admin_sessions (
            id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES admin_users(id) ON DELETE CASCADE,
            token_hash TEXT NOT NULL UNIQUE, ip TEXT, user_agent TEXT, created_at INTEGER NOT NULL,
            last_seen_at INTEGER NOT NULL, expires_at INTEGER NOT NULL)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS admin_login_logs (
            id INTEGER PRIMARY KEY, user_id INTEGER, username TEXT NOT NULL, ip TEXT, user_agent TEXT,
            success INTEGER NOT NULL, reason TEXT NOT NULL DEFAULT \'\', created_at INTEGER NOT NULL)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS admin_login_logs_time ON admin_login_logs (created_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS admin_login_logs_ip ON admin_login_logs (ip, success, created_at)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS admin_tokens (
            id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES admin_users(id) ON DELETE CASCADE,
            name TEXT NOT NULL, token_prefix TEXT NOT NULL, token_hash TEXT NOT NULL UNIQUE,
            expires_at INTEGER NOT NULL, created_at INTEGER NOT NULL, last_used_at INTEGER, last_used_ip TEXT, revoked_at INTEGER)');

        $pdo->exec('CREATE TABLE IF NOT EXISTS admin_ip_blocks (
            ip TEXT PRIMARY KEY, reason TEXT NOT NULL DEFAULT \'\', created_by TEXT, created_at INTEGER NOT NULL, expires_at INTEGER)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS admin_meta (key TEXT PRIMARY KEY, value TEXT NOT NULL)');

        $now = time();
        if (!$pdo->query('SELECT COUNT(*) FROM admin_roles')->fetchColumn()) {
            $pdo->prepare('INSERT INTO admin_roles (name, description, is_super, created_at, updated_at) VALUES (?, ?, 1, ?, ?)')
                ->execute([ADMIN_SUPER_ROLE, '可以管理所有路由的讀寫（包含以後新增的路由）', $now, $now]);
        }
        // 還沒有帳號：從現在起開放首次設定 ADMIN_SETUP_HOURS 小時（已經有帳號的舊資料庫不受影響）
        if (!$pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn()) {
            $pdo->prepare('INSERT OR IGNORE INTO admin_meta (key, value) VALUES (?, ?)')->execute(['setup_opened_at', (string) $now]);
        }
        $pdo->exec('PRAGMA user_version = ' . ADMIN_SCHEMA);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

/** 開資料庫失敗時直接回應錯誤（給 action 用） */
function adminDbOrFail()
{
    try {
        return adminDb();
    } catch (AdminSecretMissing $e) {
        appError(50301, [], ['detail' => $e->getMessage()]);
    } catch (Throwable $e) {
        sendJson(500, ['ok' => false, 'error' => 'Admin database error: ' . $e->getMessage()]);
    }
}

function adminHash($token)
{
    return hash_hmac('sha256', (string) $token, appSecretKey());
}

function adminClientIp()
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
}

function adminUserAgent()
{
    return mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
}

function adminIsHttps()
{
    return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
}

// ================================================================
// 身分與權限

/** 這次請求的 PAT 原文（Authorization: Bearer tdxp_…）；沒有回 null */
function adminRequestPat()
{
    $h = function_exists('requestAuthorizationHeader') ? requestAuthorizationHeader()
        : (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^Bearer\s+(' . preg_quote(ADMIN_PAT_PREFIX, '/') . '[A-Za-z0-9]+)$/i', trim($h), $m)) {
        return $m[1];
    }
    // 備用：主機或代理拿掉 Authorization 標頭時，改用 X-Admin-Token
    $x = trim((string) ($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? ''));
    if (preg_match('/^' . preg_quote(ADMIN_PAT_PREFIX, '/') . '[A-Za-z0-9]+$/i', $x)) {
        return $x;
    }
    return null;
}

/**
 * 目前的後臺身分；沒有回 null。
 * ['id', 'username', 'role_id', 'role', 'is_super', 'is_default_password', 'via' => pat|session, 'token_id'?]
 */
function adminCurrent()
{
    static $done = false, $user = null;
    if ($done) {
        return $user;
    }
    $done = true;
    $now = time();
    $pat = adminRequestPat();
    $cookie = (string) ($_COOKIE[ADMIN_COOKIE] ?? '');
    if (($pat !== null || $cookie !== '') && adminDbExists() && appSecretKey() !== '') {
        try {
            $db = adminDb();
            $sel = 'SELECT u.id, u.username, u.role_id, u.is_default_password, r.name AS role, r.is_super
                    FROM admin_users u JOIN admin_roles r ON r.id = u.role_id WHERE u.disabled = 0 AND u.id = ?';
            if ($pat !== null) {
                $st = $db->prepare('SELECT id, user_id, last_used_at FROM admin_tokens WHERE token_hash = ? AND revoked_at IS NULL AND expires_at > ?');
                $st->execute([adminHash($pat), $now]);
                if ($t = $st->fetch()) {
                    $st = $db->prepare($sel);
                    $st->execute([$t['user_id']]);
                    if ($u = $st->fetch()) {
                        $user = adminUserRow($u, 'pat') + ['token_id' => (int) $t['id']];
                        if ($now - (int) $t['last_used_at'] >= 60) {
                            $db->prepare('UPDATE admin_tokens SET last_used_at = ?, last_used_ip = ? WHERE id = ?')
                               ->execute([$now, adminClientIp(), $t['id']]);
                        }
                    }
                }
            }
            if ($user === null && $cookie !== '' && preg_match('/^[a-f0-9]{64}$/', $cookie)) {
                $st = $db->prepare('SELECT id, user_id, last_seen_at FROM admin_sessions WHERE token_hash = ? AND expires_at > ?');
                $st->execute([adminHash($cookie), $now]);
                if ($s = $st->fetch()) {
                    $st = $db->prepare($sel);
                    $st->execute([$s['user_id']]);
                    if ($u = $st->fetch()) {
                        $user = adminUserRow($u, 'session') + ['session_id' => (int) $s['id']];
                        if ($now - (int) $s['last_seen_at'] >= 60) {   // 每次使用延長有效期（最多每分鐘寫一次）
                            $db->prepare('UPDATE admin_sessions SET last_seen_at = ?, expires_at = ? WHERE id = ?')
                               ->execute([$now, $now + ADMIN_SESSION_HOURS * 3600, $s['id']]);
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('[tdx-proxy] admin auth: ' . $e->getMessage());
        }
    }
    return $user;
}

function adminUserRow(array $u, $via)
{
    return ['id' => (int) $u['id'], 'username' => $u['username'], 'role_id' => (int) $u['role_id'], 'role' => $u['role'],
            'is_super' => (bool) $u['is_super'], 'is_default_password' => (bool) $u['is_default_password'], 'via' => $via];
}

/** 角色的路由權限：route => ['read' => bool, 'write' => bool]（super 角色為全部） */
function adminRolePermissions($roleId, $isSuper)
{
    $out = [];
    foreach (array_keys(ADMIN_ROUTES) as $r) {
        $out[$r] = ['read' => (bool) $isSuper, 'write' => (bool) $isSuper];
    }
    if ($isSuper || !$roleId) {
        return $out;
    }
    $st = adminDb()->prepare('SELECT route, can_read, can_write FROM admin_role_permissions WHERE role_id = ?');
    $st->execute([$roleId]);
    foreach ($st as $p) {
        if (isset($out[$p['route']])) {
            $out[$p['route']] = ['read' => (bool) ($p['can_read'] || $p['can_write']), 'write' => (bool) $p['can_write']];
        }
    }
    return $out;
}

/** 目前身分對某路由有沒有讀（read）或寫（write）權限 */
function adminCan($route, $mode = 'read')
{
    $u = adminCurrent();
    if ($u === null) {
        return false;
    }
    if ($u['is_super']) {
        return true;
    }
    static $cache = [];
    if (!isset($cache[$u['role_id']])) {
        $cache[$u['role_id']] = adminRolePermissions($u['role_id'], false);
    }
    return !empty($cache[$u['role_id']][$route][$mode]);
}

/** CSRF token：由登入 cookie 與秘密字串算出（面板用 cookie 登入時，寫入動作要帶 X-CSRF-Token） */
function adminCsrf()
{
    $cookie = (string) ($_COOKIE[ADMIN_COOKIE] ?? '');
    return $cookie === '' ? '' : hash_hmac('sha256', 'csrf|' . $cookie, appSecretKey());
}

/** 寫入動作：用 cookie 登入時要帶正確的 X-CSRF-Token，且必須是 POST */
function adminCheckWrite()
{
    $u = adminCurrent();
    if ($u && $u['via'] === 'session') {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            appError(40501);
        }
        $h = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if ($h === '' || !hash_equals(adminCsrf(), $h)) {
            appError(40302);
        }
    }
}

/** 要求登入（任何後臺身分）；$write＝寫入動作 */
function adminRequireUser($write = false)
{
    if (adminCurrent() === null) {
        appError(40101);
    }
    if ($write) {
        adminCheckWrite();
    }
    return adminCurrent();
}

/** 要求某路由的讀或寫權限 */
function adminRequire($route, $mode = 'read')
{
    $u = adminRequireUser(false);
    if (!adminCan($route, $mode)) {
        appError(40301, ['route' => $route, 'mode' => $mode === 'write' ? '寫入' : '讀取'], ['user' => $u['username'], 'role' => $u['role']]);
    }
    if ($mode === 'write') {
        adminCheckWrite();
    }
    return $u;
}

// ================================================================
// 輸入

/** 請求參數：JSON 本文 → POST 表單 → 網址參數 */
function adminParam($name, $default = null)
{
    static $body = null;
    if ($body === null) {
        $raw = (string) file_get_contents('php://input');
        $j = $raw !== '' ? json_decode($raw, true) : null;
        $body = is_array($j) ? $j : [];
    }
    if (array_key_exists($name, $body)) {
        return $body[$name];
    }
    if (array_key_exists($name, $_POST)) {
        return $_POST[$name];
    }
    return $_GET[$name] ?? $default;
}

function adminBadInput($msg)
{
    appError(40010, ['detail' => $msg]);
}

function adminValidUsername($s)
{
    return is_string($s) && preg_match('/^[A-Za-z0-9_.\-]{3,40}$/', $s);
}

function adminValidPassword($s)
{
    return is_string($s) && mb_strlen($s) >= ADMIN_PASSWORD_MIN && mb_strlen($s) <= 200;
}

// ================================================================
// 紀錄與清理

function adminLog($userId, $username, $success, $reason = '')
{
    adminDb()->prepare('INSERT INTO admin_login_logs (user_id, username, ip, user_agent, success, reason, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$userId, mb_substr((string) $username, 0, 80), adminClientIp(), adminUserAgent(), $success ? 1 : 0, $reason, time()]);
}

/** 清掉超過保留天數的登入紀錄與過期的登入狀態 */
function adminPurgeOld()
{
    $db = adminDb();
    $days = max(1, (int) ADMIN_LOG_RETENTION_DAYS);
    $db->prepare('DELETE FROM admin_login_logs WHERE created_at < ?')->execute([time() - $days * 86400]);
    $db->prepare('DELETE FROM admin_sessions WHERE expires_at < ?')->execute([time()]);
}

/** 這個 IP 最近 ADMIN_LOGIN_FAIL_WINDOW_MINUTES 分鐘登入失敗次數超過上限時回應 429 */
function adminLoginThrottle()
{
    $max = (int) ADMIN_LOGIN_MAX_FAILS;
    if ($max <= 0) {
        return;
    }
    $st = adminDb()->prepare('SELECT COUNT(*), MIN(created_at) FROM admin_login_logs WHERE ip = ? AND success = 0 AND created_at > ?');
    $st->execute([adminClientIp(), time() - ADMIN_LOGIN_FAIL_WINDOW_MINUTES * 60]);
    list($n, $first) = $st->fetch(PDO::FETCH_NUM);
    if ((int) $n >= $max) {
        $retry = max(1, (int) $first + ADMIN_LOGIN_FAIL_WINDOW_MINUTES * 60 - time());
        header('Retry-After: ' . $retry);
        appError(42903, ['max' => $max, 'retry' => $retry]);
    }
}

// ================================================================
// 輸出

/** 角色清單（含權限） */
function adminRoles()
{
    $out = [];
    foreach (adminDb()->query('SELECT r.*, (SELECT COUNT(*) FROM admin_users u WHERE u.role_id = r.id) AS users FROM admin_roles r ORDER BY r.id') as $r) {
        $out[] = ['id' => (int) $r['id'], 'name' => $r['name'], 'description' => $r['description'], 'is_super' => (bool) $r['is_super'],
                  'users' => (int) $r['users'], 'permissions' => adminRolePermissions((int) $r['id'], (bool) $r['is_super']),
                  'created_at' => adminTime($r['created_at']), 'updated_at' => adminTime($r['updated_at'])];
    }
    return $out;
}

/** 路由清單（給權限設定畫面） */
function adminRouteList()
{
    $out = [];
    foreach (ADMIN_ROUTES as $name => $r) {
        $out[] = ['route' => $name, 'title' => $r[0], 'read' => $r[1], 'write' => $r[2]];
    }
    return $out;
}

/** Unix 時間 → 台北時間字串 */
function adminTime($ts)
{
    return $ts ? (new DateTime('@' . (int) $ts))->setTimezone(new DateTimeZone('Asia/Taipei'))->format('Y-m-d H:i:s') : null;
}

function adminTokenRow(array $t)
{
    $now = time();
    return ['id' => (int) $t['id'], 'name' => $t['name'], 'prefix' => $t['token_prefix'] . '…',
            'user_id' => (int) $t['user_id'], 'username' => $t['username'] ?? null,
            'created_at' => adminTime($t['created_at']), 'expires_at' => adminTime($t['expires_at']),
            'last_used_at' => adminTime($t['last_used_at']), 'last_used_ip' => $t['last_used_ip'],
            'revoked_at' => adminTime($t['revoked_at']),
            'status' => $t['revoked_at'] ? 'revoked' : ((int) $t['expires_at'] <= $now ? 'expired' : 'active')];
}

/** 產生 PAT：回傳 [原文, 資料列 id] */
function adminCreateToken($userId, $name, $expiresAt)
{
    $token = ADMIN_PAT_PREFIX . bin2hex(random_bytes(20));
    adminDb()->prepare('INSERT INTO admin_tokens (user_id, name, token_prefix, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$userId, $name, substr($token, 0, 12), adminHash($token), $expiresAt, time()]);
    return [$token, (int) adminDb()->lastInsertId()];
}

/** 到期日（YYYY-MM-DD，以台北時間當天 23:59:59 為止）→ Unix 時間；格式錯或已過去回 null */
function adminParseExpiry($s)
{
    if (!is_string($s) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
        return null;
    }
    $d = DateTime::createFromFormat('Y-m-d H:i:s', $s . ' 23:59:59', new DateTimeZone('Asia/Taipei'));
    if (!$d || $d->format('Y-m-d') !== $s || $d->getTimestamp() <= time()) {
        return null;
    }
    return $d->getTimestamp();
}

/** 除了 $exceptUserId 之外，還有沒有啟用中的 super 帳號（避免把最後一個管理者停用、刪除或降級） */
function adminOtherSuperExists($exceptUserId)
{
    $st = adminDb()->prepare('SELECT COUNT(*) FROM admin_users u JOIN admin_roles r ON r.id = u.role_id
                              WHERE r.is_super = 1 AND u.disabled = 0 AND u.id <> ?');
    $st->execute([$exceptUserId]);
    return (int) $st->fetchColumn() > 0;
}

// ================================================================
// 首次設定：新主機沒有任何帳號時，由網頁建立第一個管理者（任何佈署方式都能用，不需要讀主機上的檔案）
//
// - 開放時間：帳號庫建立（網站第一次執行）後 ADMIN_SETUP_HOURS 小時；有人建立帳號後立即關閉
// - 重新開放：在網站根目錄放 admin-setup.txt（FTP、主機檔案管理或 git 佈署都可以）。檔案每更新一次只處理一次：
//     內容空白或任意文字 → 沒有帳號時重新開放 ADMIN_SETUP_HOURS 小時
//     內容是 reset          → 清除所有帳號、登入狀態與 PAT（角色、權限、紀錄保留），再重新開放（帳號被別人搶先設定時用）
//   能放檔案的人本來就能改程式，所以用檔案證明身分；處理完可以刪掉這個檔案

const ADMIN_SETUP_FILE = APP_ROOT_DIR . '/admin-setup.txt';

function adminMetaGet(PDO $db, $key)
{
    $st = $db->prepare('SELECT value FROM admin_meta WHERE key = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? null : $v;
}

function adminMetaSet(PDO $db, $key, $value)
{
    $db->prepare('INSERT INTO admin_meta (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
       ->execute([$key, (string) $value]);
}

/** 處理 admin-setup.txt（每個修改時間只處理一次） */
function adminSetupFileCheck(PDO $db)
{
    clearstatcache(true, ADMIN_SETUP_FILE);
    if (!is_file(ADMIN_SETUP_FILE)) {
        return;
    }
    $mtime = (string) filemtime(ADMIN_SETUP_FILE);
    if (adminMetaGet($db, 'setup_file_mtime') === $mtime) {
        return;
    }
    $reset = strtolower(trim((string) @file_get_contents(ADMIN_SETUP_FILE, false, null, 0, 100))) === 'reset';
    $db->exec('BEGIN IMMEDIATE');
    try {
        if ($reset) {
            $db->exec('DELETE FROM admin_tokens');
            $db->exec('DELETE FROM admin_sessions');
            $db->exec('DELETE FROM admin_users');
        }
        if (!$db->query('SELECT COUNT(*) FROM admin_users')->fetchColumn()) {
            adminMetaSet($db, 'setup_opened_at', time());
        }
        adminMetaSet($db, 'setup_file_mtime', $mtime);
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
    error_log('[tdx-proxy] admin setup file processed' . ($reset ? ' (reset accounts)' : ''));
}

/** 首次設定狀態：needed＝還沒有任何帳號；open＝現在可以建立 */
function adminSetupState()
{
    $db = adminDb();
    adminSetupFileCheck($db);
    $users = (int) $db->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
    if ($users > 0) {
        return ['needed' => false, 'open' => false];
    }
    $opened = (int) (adminMetaGet($db, 'setup_opened_at') ?? 0);
    if ($opened === 0) {
        // 舊版建立、但帳號全被刪光的資料庫：從現在起開放
        $opened = time();
        adminMetaSet($db, 'setup_opened_at', $opened);
    }
    $until = $opened + max(1, (int) ADMIN_SETUP_HOURS) * 3600;
    return ['needed' => true, 'open' => time() < $until, 'opened_at' => adminTime($opened), 'expires_at' => adminTime($until),
            'reopen' => '在網站根目錄放 admin-setup.txt（內容空白）就會重新開放 ' . (int) ADMIN_SETUP_HOURS . ' 小時'];
}

// 網站第一次執行時就建立帳號庫，首次設定的開放時間從這時候開始算
if (!adminDbExists()) {
    try {
        adminDb();
    } catch (Throwable $e) {
        error_log('[tdx-proxy] admin init: ' . $e->getMessage());
    }
}


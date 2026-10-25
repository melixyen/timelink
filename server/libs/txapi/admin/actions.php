<?php
/**
 * 後臺的 txapi（/txapi/<action>/<func>）：
 *   admin_auth      登入、登出、目前身分、改自己的密碼、自己的 PAT（每個帳號都能用）
 *   admin_accounts  帳號管理：帳號、角色與權限、登入紀錄、所有 PAT（路由權限 admin_accounts）
 *   admin_settings  檢視所有設定（路由權限 admin_settings）
 * 讀取用 GET；寫入用 POST（JSON 本文或表單），用 cookie 登入時另需 X-CSRF-Token 標頭。
 */

require_once __DIR__ . '/admin.php';
require_once __DIR__ . '/traffic.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/cache.php';

function adminFunc()
{
    return strtolower(trim((string) ($_GET['func'] ?? '')));
}

function adminUnknownFunc($action, array $funcs)
{
    sendJson(400, ['ok' => false, 'error' => 'Unknown func: ' . adminFunc(), 'action' => $action, 'funcs' => $funcs,
                   'help' => '/txapi/readme']);
}

// ================================================================
// admin_auth

function actionAdminAuth()
{
    header('Cache-Control: no-store');
    $funcs = ['setup', 'login', 'logout', 'me', 'password', 'tokens', 'token_create', 'token_revoke'];
    switch (adminFunc()) {
        case 'setup':
            adminFuncSetup();
        case 'login':
            adminFuncLogin();
        case 'logout':
            adminFuncLogout();
        case 'me':
        case '':
            adminFuncMe();
        case 'password':
            adminFuncPassword();
        case 'tokens':
            adminFuncMyTokens();
        case 'token_create':
            adminFuncTokenCreate();
        case 'token_revoke':
            adminFuncTokenRevoke(false);
        default:
            adminUnknownFunc('admin_auth', $funcs);
    }
}

/** 目前身分的資訊（含可讀寫的路由、CSRF token） */
function adminMeInfo()
{
    $u = adminCurrent();
    $perms = [];
    foreach (array_keys(ADMIN_ROUTES) as $r) {
        $perms[$r] = ['read' => adminCan($r, 'read'), 'write' => adminCan($r, 'write')];
    }
    return ['ok' => true, 'action' => 'admin_auth', 'func' => 'me',
            'user' => ['id' => $u['id'], 'username' => $u['username'], 'role' => $u['role'], 'is_super' => $u['is_super'],
                       'is_default_password' => $u['is_default_password'], 'via' => $u['via']],
            'permissions' => $perms, 'routes' => adminRouteList(),
            'csrf' => $u['via'] === 'session' ? adminCsrf() : null,
            'notice' => $u['is_default_password'] ? '還在使用預設密碼，建議盡快更換（/txapi/admin_auth/password）' : null];
}

function adminFuncMe()
{
    if (adminCurrent() === null) {
        appError(40101, [], ['initialized' => adminDbExists()]);
    }
    sendJson(200, adminMeInfo());
}

function adminFuncLogin()
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        appError(40501);
    }
    $db = adminDbOrFail();
    adminLoginThrottle();
    $username = trim((string) adminParam('username', ''));
    $password = (string) adminParam('password', '');
    $st = $db->prepare('SELECT id, username, password_hash, disabled FROM admin_users WHERE username = ?');
    $st->execute([$username]);
    $u = $st->fetch();
    if (!$u || !password_verify($password, $u['password_hash']) || $u['disabled']) {
        adminLog($u ? (int) $u['id'] : null, $username, false, !$u ? 'no_user' : ($u['disabled'] ? 'disabled' : 'bad_password'));
        appError(40102);
    }
    $now = time();
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        $db->prepare('UPDATE admin_users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $u['id']]);
    }
    sendJson(200, adminLoginInfo($u['id'], adminStartSession($db, (int) $u['id'], $u['username'])));
}

/** 建立登入狀態（cookie）並記錄；回傳 cookie 原文 */
function adminStartSession(PDO $db, $userId, $username)
{
    $now = time();
    $token = bin2hex(random_bytes(32));
    $exp = $now + ADMIN_SESSION_HOURS * 3600;
    $db->prepare('INSERT INTO admin_sessions (user_id, token_hash, ip, user_agent, created_at, last_seen_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
       ->execute([$userId, adminHash($token), adminClientIp(), adminUserAgent(), $now, $now, $exp]);
    $db->prepare('UPDATE admin_users SET last_login_at = ?, last_login_ip = ? WHERE id = ?')->execute([$now, adminClientIp(), $userId]);
    adminLog($userId, $username, true);
    adminPurgeOld();
    setcookie(ADMIN_COOKIE, $token, ['expires' => $exp, 'path' => '/', 'secure' => adminIsHttps(), 'httponly' => true, 'samesite' => 'Strict']);
    // 讓這次回應就能算出身分與 CSRF
    $_COOKIE[ADMIN_COOKIE] = $token;
    return $token;
}

/**
 * 首次設定（沒有任何帳號時）：
 *   GET  /txapi/admin_auth/setup   狀態 {needed, open, expires_at}
 *   POST /txapi/admin_auth/setup   {"username", "password"} 建立第一個管理者（manager 角色）並直接登入
 * 只在沒有帳號、而且在開放時間內才能建立；同時有兩個請求時只有第一個成功
 */
function adminFuncSetup()
{
    $db = adminDbOrFail();
    $state = adminSetupState();
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        sendJson(200, ['ok' => true, 'action' => 'admin_auth', 'func' => 'setup'] + $state);
    }
    adminLoginThrottle();
    if (!$state['needed']) {
        appError(40901, ['detail' => '已經有管理者帳號，首次設定已關閉；請直接登入']);
    }
    if (!$state['open']) {
        appError(40901, ['detail' => '首次設定已逾時（' . $state['expires_at'] . '）。' . $state['reopen']]);
    }
    $username = trim((string) adminParam('username', ''));
    $password = (string) adminParam('password', '');
    if (!adminValidUsername($username)) {
        adminBadInput('帳號 3–40 字，只能用英數字與 _ . -');
    }
    if (mb_strlen($password) < 8 || mb_strlen($password) > 200) {
        adminBadInput('管理者密碼至少 8 個字');
    }
    $now = time();
    $db->exec('BEGIN IMMEDIATE');
    try {
        if ($db->query('SELECT COUNT(*) FROM admin_users')->fetchColumn()) {
            $db->exec('ROLLBACK');
            appError(40901, ['detail' => '已經有人完成首次設定，請直接登入']);
        }
        $rid = (int) $db->query('SELECT id FROM admin_roles WHERE is_super = 1 ORDER BY id LIMIT 1')->fetchColumn();
        $db->prepare('INSERT INTO admin_users (username, password_hash, role_id, created_at, updated_at, password_changed_at) VALUES (?, ?, ?, ?, ?, ?)')
           ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $rid, $now, $now, $now]);
        $uid = (int) $db->lastInsertId();
        adminMetaSet($db, 'setup_done_at', $now);
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->exec('ROLLBACK');
        }
        throw $e;
    }
    $out = adminLoginInfo($uid, adminStartSession($db, $uid, $username));
    $out['func'] = 'setup';
    sendJson(200, $out);
}

/** 登入成功的回應（這次請求還沒經過 adminCurrent 的 cookie 驗證，直接組） */
function adminLoginInfo($userId, $token)
{
    $st = adminDb()->prepare('SELECT u.id, u.username, u.role_id, u.is_default_password, r.name AS role, r.is_super
                              FROM admin_users u JOIN admin_roles r ON r.id = u.role_id WHERE u.id = ?');
    $st->execute([$userId]);
    $u = adminUserRow($st->fetch(), 'session');
    $perms = adminRolePermissions($u['role_id'], $u['is_super']);
    return ['ok' => true, 'action' => 'admin_auth', 'func' => 'login',
            'user' => ['id' => $u['id'], 'username' => $u['username'], 'role' => $u['role'], 'is_super' => $u['is_super'],
                       'is_default_password' => $u['is_default_password'], 'via' => 'session'],
            'permissions' => $perms, 'routes' => adminRouteList(),
            'csrf' => hash_hmac('sha256', 'csrf|' . $token, appSecretKey()),
            'notice' => $u['is_default_password'] ? '還在使用預設密碼，建議盡快更換' : null];
}

function adminFuncLogout()
{
    $u = adminRequireUser(true);
    if ($u['via'] === 'session') {
        adminDb()->prepare('DELETE FROM admin_sessions WHERE id = ?')->execute([$u['session_id']]);
    }
    setcookie(ADMIN_COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'secure' => adminIsHttps(), 'httponly' => true, 'samesite' => 'Strict']);
    sendJson(200, ['ok' => true, 'action' => 'admin_auth', 'func' => 'logout']);
}

/** 改自己的密碼：要舊密碼；成功後其他登入狀態全部登出 */
function adminFuncPassword()
{
    $u = adminRequireUser(true);
    $old = (string) adminParam('old_password', '');
    $new = (string) adminParam('new_password', '');
    $st = adminDb()->prepare('SELECT password_hash FROM admin_users WHERE id = ?');
    $st->execute([$u['id']]);
    if (!password_verify($old, (string) $st->fetchColumn())) {
        appError(40102);
    }
    if (!adminValidPassword($new)) {
        adminBadInput('新密碼至少 ' . ADMIN_PASSWORD_MIN . ' 個字');
    }
    $now = time();
    adminDb()->prepare('UPDATE admin_users SET password_hash = ?, is_default_password = 0, password_changed_at = ?, updated_at = ? WHERE id = ?')
        ->execute([password_hash($new, PASSWORD_DEFAULT), $now, $now, $u['id']]);
    adminDb()->prepare('DELETE FROM admin_sessions WHERE user_id = ? AND id <> ?')->execute([$u['id'], $u['session_id'] ?? 0]);
    sendJson(200, ['ok' => true, 'action' => 'admin_auth', 'func' => 'password', 'message' => '密碼已更換，其他裝置的登入已登出']);
}

function adminFuncMyTokens()
{
    $u = adminRequireUser(false);
    $rows = [];
    if ($u['id']) {
        $st = adminDb()->prepare('SELECT * FROM admin_tokens WHERE user_id = ? ORDER BY id DESC');
        $st->execute([$u['id']]);
        foreach ($st as $t) {
            $rows[] = adminTokenRow($t);
        }
    }
    sendJson(200, ['ok' => true, 'action' => 'admin_auth', 'func' => 'tokens', 'count' => count($rows), 'tokens' => $rows]);
}

/** 產生自己的 PAT：name、expires（YYYY-MM-DD）。原文只在這次回應出現 */
function adminFuncTokenCreate()
{
    $u = adminRequireUser(true);
    $name = trim((string) adminParam('name', ''));
    if ($name === '' || mb_strlen($name) > 60) {
        adminBadInput('name 必填，最多 60 字');
    }
    $exp = adminParseExpiry(adminParam('expires', ''));
    if ($exp === null) {
        adminBadInput('expires 要是今天以後的日期（YYYY-MM-DD）');
    }
    list($token, $id) = adminCreateToken($u['id'], $name, $exp);
    $st = adminDb()->prepare('SELECT * FROM admin_tokens WHERE id = ?');
    $st->execute([$id]);
    sendJson(200, ['ok' => true, 'action' => 'admin_auth', 'func' => 'token_create', 'token' => $token,
                   'info' => adminTokenRow($st->fetch()),
                   'usage' => 'Authorization: Bearer ' . $token . '（/txapi/admin_ 開頭的 action）',
                   'notice' => 'PAT 原文只會顯示這一次，請立刻保存']);
}

/** 撤銷 PAT：$any＝true（admin_accounts）可撤銷任何人的，否則只能撤銷自己的 */
function adminFuncTokenRevoke($any)
{
    $u = $any ? adminRequire('admin_accounts', 'write') : adminRequireUser(true);
    $id = (int) adminParam('id', 0);
    $st = adminDb()->prepare('SELECT * FROM admin_tokens WHERE id = ?');
    $st->execute([$id]);
    $t = $st->fetch();
    if (!$t || (!$any && (int) $t['user_id'] !== $u['id'])) {
        appError(40401, ['what' => 'PAT']);
    }
    if (!$t['revoked_at']) {
        adminDb()->prepare('UPDATE admin_tokens SET revoked_at = ? WHERE id = ?')->execute([time(), $id]);
    }
    sendJson(200, ['ok' => true, 'action' => $any ? 'admin_accounts' : 'admin_auth', 'func' => 'token_revoke', 'id' => $id]);
}

// ================================================================
// admin_accounts

function actionAdminAccounts()
{
    header('Cache-Control: no-store');
    $read = ['users', 'roles', 'routes', 'logs', 'tokens'];
    $write = ['user_create', 'user_update', 'user_delete', 'role_create', 'role_update', 'role_delete', 'token_revoke'];
    $func = adminFunc() ?: 'users';
    if (in_array($func, $read, true)) {
        adminDbOrFail();
        adminRequire('admin_accounts', 'read');
    } elseif (in_array($func, $write, true)) {
        adminDbOrFail();
        if ($func !== 'token_revoke') {
            adminRequire('admin_accounts', 'write');
        }
    } else {
        adminUnknownFunc('admin_accounts', array_merge($read, $write));
    }
    switch ($func) {
        case 'users':
            sendJson(200, ['ok' => true, 'action' => 'admin_accounts', 'func' => 'users', 'users' => adminUsersList()]);
        case 'roles':
            sendJson(200, ['ok' => true, 'action' => 'admin_accounts', 'func' => 'roles', 'roles' => adminRoles(), 'routes' => adminRouteList()]);
        case 'routes':
            sendJson(200, ['ok' => true, 'action' => 'admin_accounts', 'func' => 'routes', 'routes' => adminRouteList()]);
        case 'logs':
            adminFuncLogs();
        case 'tokens':
            $rows = [];
            foreach (adminDb()->query('SELECT t.*, u.username FROM admin_tokens t JOIN admin_users u ON u.id = t.user_id ORDER BY t.id DESC') as $t) {
                $rows[] = adminTokenRow($t);
            }
            sendJson(200, ['ok' => true, 'action' => 'admin_accounts', 'func' => 'tokens', 'count' => count($rows), 'tokens' => $rows]);
        case 'user_create':
            adminFuncUserCreate();
        case 'user_update':
            adminFuncUserUpdate();
        case 'user_delete':
            adminFuncUserDelete();
        case 'role_create':
        case 'role_update':
            adminFuncRoleSave($func === 'role_create');
        case 'role_delete':
            adminFuncRoleDelete();
        case 'token_revoke':
            adminFuncTokenRevoke(true);
    }
}

function adminUsersList()
{
    $out = [];
    $sql = 'SELECT u.*, r.name AS role, r.is_super,
                   (SELECT COUNT(*) FROM admin_tokens t WHERE t.user_id = u.id AND t.revoked_at IS NULL AND t.expires_at > ' . time() . ') AS active_tokens
            FROM admin_users u JOIN admin_roles r ON r.id = u.role_id ORDER BY u.id';
    foreach (adminDb()->query($sql) as $u) {
        $out[] = ['id' => (int) $u['id'], 'username' => $u['username'], 'role_id' => (int) $u['role_id'], 'role' => $u['role'],
                  'is_super' => (bool) $u['is_super'], 'disabled' => (bool) $u['disabled'], 'is_default_password' => (bool) $u['is_default_password'],
                  'created_at' => adminTime($u['created_at']), 'last_login_at' => adminTime($u['last_login_at']),
                  'last_login_ip' => $u['last_login_ip'], 'password_changed_at' => adminTime($u['password_changed_at']),
                  'active_tokens' => (int) $u['active_tokens']];
    }
    return $out;
}

/** 登入紀錄：user、ip、success（1／0）篩選；limit（最多 500）、before（id，往前翻頁） */
function adminFuncLogs()
{
    adminPurgeOld();
    $where = [];
    $args = [];
    if (($v = trim((string) ($_GET['user'] ?? ''))) !== '') {
        $where[] = 'username = ?';
        $args[] = $v;
    }
    if (($v = trim((string) ($_GET['ip'] ?? ''))) !== '') {
        $where[] = 'ip = ?';
        $args[] = $v;
    }
    if (isset($_GET['success']) && $_GET['success'] !== '') {
        $where[] = 'success = ?';
        $args[] = $_GET['success'] ? 1 : 0;
    }
    if (($v = (int) ($_GET['before'] ?? 0)) > 0) {
        $where[] = 'id < ?';
        $args[] = $v;
    }
    $limit = max(1, min(500, (int) ($_GET['limit'] ?? 100)));
    $st = adminDb()->prepare('SELECT * FROM admin_login_logs' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC LIMIT ' . ($limit + 1));
    $st->execute($args);
    $rows = $st->fetchAll();
    $more = count($rows) > $limit;
    $rows = array_slice($rows, 0, $limit);
    $logs = array_map(function ($r) {
        return ['id' => (int) $r['id'], 'time' => adminTime($r['created_at']), 'username' => $r['username'], 'ip' => $r['ip'],
                'success' => (bool) $r['success'], 'reason' => $r['reason'], 'user_agent' => $r['user_agent']];
    }, $rows);
    sendJson(200, ['ok' => true, 'action' => 'admin_accounts', 'func' => 'logs', 'retention_days' => (int) ADMIN_LOG_RETENTION_DAYS,
                   'count' => count($logs), 'more' => $more, 'next_before' => $more ? end($logs)['id'] : null, 'logs' => $logs]);
}

function adminRoleExists($id)
{
    $st = adminDb()->prepare('SELECT id, is_super FROM admin_roles WHERE id = ?');
    $st->execute([(int) $id]);
    return $st->fetch() ?: null;
}

function adminFuncUserCreate()
{
    $username = trim((string) adminParam('username', ''));
    $password = (string) adminParam('password', '');
    $role = adminRoleExists(adminParam('role_id', 0));
    if (!adminValidUsername($username)) {
        adminBadInput('帳號 3–40 字，只能用英數字與 _ . -');
    }
    if (!adminValidPassword($password)) {
        adminBadInput('密碼至少 ' . ADMIN_PASSWORD_MIN . ' 個字');
    }
    if (!$role) {
        adminBadInput('role_id 不存在');
    }
    $now = time();
    try {
        adminDb()->prepare('INSERT INTO admin_users (username, password_hash, role_id, created_at, updated_at, password_changed_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $role['id'], $now, $now, $now]);
    } catch (PDOException $e) {
        appError(40901, ['detail' => '帳號已存在：' . $username]);
    }
    sendJson(200, ['ok' => true, 'action' => 'admin_accounts', 'func' => 'user_create', 'id' => (int) adminDb()->lastInsertId(),
                   'users' => adminUsersList()]);
}

/** 改帳號：role_id、disabled、password（重設密碼）；不能讓系統沒有啟用中的 manager */
function adminFuncUserUpdate()
{
    $me = adminCurrent();
    $id = (int) adminParam('id', 0);
    $st = adminDb()->prepare('SELECT u.*, r.is_super FROM admin_users u JOIN admin_roles r ON r.id = u.role_id WHERE u.id = ?');
    $st->execute([$id]);
    $u = $st->fetch();
    if (!$u) {
        appError(40401, ['what' => '帳號']);
    }
    $set = [];
    $args = [];
    $losesSuper = false;
    if (($rid = adminParam('role_id')) !== null) {
        $role = adminRoleExists($rid);
        if (!$role) {
            adminBadInput('role_id 不存在');
        }
        $set[] = 'role_id = ?';
        $args[] = $role['id'];
        $losesSuper = $u['is_super'] && !$role['is_super'];
    }
    if (($dis = adminParam('disabled')) !== null) {
        $dis = filter_var($dis, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        if ($dis && $id === $me['id']) {
            adminBadInput('不能停用自己');
        }
        $set[] = 'disabled = ?';
        $args[] = $dis;
        $losesSuper = $losesSuper || ($u['is_super'] && $dis);
    }
    if (($pw = adminParam('password')) !== null && $pw !== '') {
        if (!adminValidPassword($pw)) {
            adminBadInput('密碼至少 ' . ADMIN_PASSWORD_MIN . ' 個字');
        }
        $set[] = 'password_hash = ?';
        $args[] = password_hash((string) $pw, PASSWORD_DEFAULT);
        $set[] = 'is_default_password = 0';
        $set[] = 'password_changed_at = ' . time();
    }
    if (!$set) {
        adminBadInput('沒有要改的欄位（role_id、disabled、password）');
    }
    if ($losesSuper && !$u['disabled'] && !adminOtherSuperExists($id)) {
        appError(40901, ['detail' => '這是最後一個啟用中的 manager 帳號，不能停用或改成其他角色']);
    }
    $set[] = 'updated_at = ' . time();
    $args[] = $id;
    adminDb()->prepare('UPDATE admin_users SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($args);
    if (adminParam('disabled') !== null || (adminParam('password') ?? '') !== '') {
        adminDb()->prepare('DELETE FROM admin_sessions WHERE user_id = ?' . ($id === $me['id'] ? ' AND id <> ' . (int) ($me['session_id'] ?? 0) : ''))
            ->execute([$id]);
    }
    sendJson(200, ['ok' => true, 'action' => 'admin_accounts', 'func' => 'user_update', 'id' => $id, 'users' => adminUsersList()]);
}

function adminFuncUserDelete()
{
    $me = adminCurrent();
    $id = (int) adminParam('id', 0);
    if ($id === $me['id']) {
        adminBadInput('不能刪除自己');
    }
    $st = adminDb()->prepare('SELECT u.*, r.is_super FROM admin_users u JOIN admin_roles r ON r.id = u.role_id WHERE u.id = ?');
    $st->execute([$id]);
    $u = $st->fetch();
    if (!$u) {
        appError(40401, ['what' => '帳號']);
    }
    if ($u['is_super'] && !$u['disabled'] && !adminOtherSuperExists($id)) {
        appError(40901, ['detail' => '這是最後一個啟用中的 manager 帳號，不能刪除']);
    }
    adminDb()->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$id]);
    sendJson(200, ['ok' => true, 'action' => 'admin_accounts', 'func' => 'user_delete', 'id' => $id, 'users' => adminUsersList()]);
}

/** 建立或修改角色：name、description、permissions {route: {read, write}}（勾寫入時自動也可讀）；manager 的權限固定為全部 */
function adminFuncRoleSave($create)
{
    $db = adminDb();
    $now = time();
    $name = adminParam('name');
    $desc = adminParam('description');
    $perms = adminParam('permissions');
    if ($create) {
        $name = trim((string) $name);
        if ($name === '' || mb_strlen($name) > 40) {
            adminBadInput('角色名稱必填，最多 40 字');
        }
        try {
            $db->prepare('INSERT INTO admin_roles (name, description, is_super, created_at, updated_at) VALUES (?, ?, 0, ?, ?)')
               ->execute([$name, mb_substr(trim((string) $desc), 0, 200), $now, $now]);
        } catch (PDOException $e) {
            appError(40901, ['detail' => '角色名稱已存在：' . $name]);
        }
        $id = (int) $db->lastInsertId();
        $role = ['id' => $id, 'is_super' => 0];
    } else {
        $role = adminRoleExists(adminParam('id', 0));
        if (!$role) {
            appError(40401, ['what' => '角色']);
        }
        $id = (int) $role['id'];
        $set = [];
        $args = [];
        if ($name !== null) {
            $name = trim((string) $name);
            if ($name === '' || mb_strlen($name) > 40) {
                adminBadInput('角色名稱最多 40 字');
            }
            $set[] = 'name = ?';
            $args[] = $name;
        }
        if ($desc !== null) {
            $set[] = 'description = ?';
            $args[] = mb_substr(trim((string) $desc), 0, 200);
        }
        if ($set) {
            $args[] = $id;
            try {
                $db->prepare('UPDATE admin_roles SET ' . implode(', ', $set) . ', updated_at = ' . $now . ' WHERE id = ?')->execute($args);
            } catch (PDOException $e) {
                appError(40901, ['detail' => '角色名稱已存在：' . $name]);
            }
        }
    }
    if (is_array($perms)) {
        if ($role['is_super']) {
            adminBadInput('manager 擁有所有路由的讀寫，權限不能修改');
        }
        $db->prepare('DELETE FROM admin_role_permissions WHERE role_id = ?')->execute([$id]);
        $ins = $db->prepare('INSERT INTO admin_role_permissions (role_id, route, can_read, can_write) VALUES (?, ?, ?, ?)');
        foreach ($perms as $route => $p) {
            if (!isset(ADMIN_ROUTES[$route]) || !is_array($p)) {
                continue;
            }
            $w = !empty($p['write']);
            $r = $w || !empty($p['read']);
            if ($r) {
                $ins->execute([$id, $route, 1, $w ? 1 : 0]);
            }
        }
    }
    sendJson(200, ['ok' => true, 'action' => 'admin_accounts', 'func' => $create ? 'role_create' : 'role_update', 'id' => $id, 'roles' => adminRoles()]);
}

function adminFuncRoleDelete()
{
    $role = adminRoleExists(adminParam('id', 0));
    if (!$role) {
        appError(40401, ['what' => '角色']);
    }
    if ($role['is_super']) {
        adminBadInput('manager 角色不能刪除');
    }
    $st = adminDb()->prepare('SELECT COUNT(*) FROM admin_users WHERE role_id = ?');
    $st->execute([$role['id']]);
    if ((int) $st->fetchColumn() > 0) {
        appError(40901, ['detail' => '還有帳號使用這個角色，先把帳號改成其他角色']);
    }
    adminDb()->prepare('DELETE FROM admin_roles WHERE id = ?')->execute([$role['id']]);
    sendJson(200, ['ok' => true, 'action' => 'admin_accounts', 'func' => 'role_delete', 'id' => (int) $role['id'], 'roles' => adminRoles()]);
}

// ================================================================
// admin_finder：面板「查詢功能」「查詢測試」用（finder 本身仍是公開 API）

/**
 * /txapi/admin_finder/status   finder 各功能、資料表是否最新、來源檔（admin_finder 讀取）
 * /txapi/admin_finder/<查詢>   同 /txapi/finder 的查詢（admin_finder_try 讀取；登入狀態不計查詢次數）
 */
function actionAdminFinder()
{
    header('Cache-Control: no-store');
    $func = strtolower(trim((string) ($_GET['func'] ?? 'status')));
    if ($func === 'status' || $func === 'list' || $func === '') {
        adminRequire('admin_finder', 'read');
        sendJson(200, ['ok' => true, 'action' => 'admin_finder', 'func' => 'status'] + finderStatus());
    }
    adminRequire('admin_finder_try', 'read');
    actionFinder();
}

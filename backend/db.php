<?php
require_once __DIR__ . '/config.php';

if (!is_dir($DOWNLOAD_DIR)) {
    mkdir($DOWNLOAD_DIR, 0755, true);
}
$AVATAR_DIR = __DIR__ . '/uploads/avatars';
if (!is_dir($AVATAR_DIR)) {
    mkdir($AVATAR_DIR, 0755, true);
}

function now_time() { return date('Y-m-d H:i:s'); }
function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function is_ajax() { return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'; }
function json_response($data, $status = 200) { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT); exit; }
function api_ok($data = [], $message = 'ok') { json_response(array_merge(['ok' => true, 'message' => $message], $data)); }
function api_error($message, $status = 400, $extra = []) { json_response(array_merge(['ok' => false, 'message' => $message], $extra), $status); }

function ucore_db() {
    static $pdo = null;
    global $DB_FILE;
    if ($pdo instanceof PDO) return $pdo;
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('当前 PHP 没有启用 pdo_sqlite 扩展，请启用 extension=pdo_sqlite 和 extension=sqlite3。');
    }
    $pdo = new PDO('sqlite:' . $DB_FILE, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    ucore_db_init($pdo);
    return $pdo;
}

function ucore_db_init(PDO $pdo) {
    global $DEFAULT_ADMIN_USERNAME, $DEFAULT_ADMIN_EMAIL, $DEFAULT_ADMIN_PASSWORD;

    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY,
        value TEXT NOT NULL DEFAULT '',
        updated_at TEXT NOT NULL DEFAULT ''
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        email TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        email_verified INTEGER NOT NULL DEFAULT 0,
        is_admin INTEGER NOT NULL DEFAULT 0,
        is_banned INTEGER NOT NULL DEFAULT 0,
        membership_until TEXT NOT NULL DEFAULT '',
        avatar_url TEXT NOT NULL DEFAULT '',
        nickname TEXT NOT NULL DEFAULT '',
        gender TEXT NOT NULL DEFAULT '',
        birthday TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT '',
        updated_at TEXT NOT NULL DEFAULT '',
        last_login_at TEXT NOT NULL DEFAULT ''
    )");


    $userCols = $pdo->query("PRAGMA table_info(users)")->fetchAll();
    $userColNames = array_map(function($c){ return $c['name']; }, $userCols);
    $profileColumns = [
        'avatar_url' => "TEXT NOT NULL DEFAULT ''",
        'nickname' => "TEXT NOT NULL DEFAULT ''",
        'gender' => "TEXT NOT NULL DEFAULT ''",
        'birthday' => "TEXT NOT NULL DEFAULT ''"
    ];
    foreach ($profileColumns as $col => $ddl) {
        if (!in_array($col, $userColNames, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN {$col} {$ddl}");
        }
    }
    $pdo->exec("UPDATE users SET nickname = username WHERE nickname = '' OR nickname IS NULL");

    $pdo->exec("CREATE TABLE IF NOT EXISTS email_codes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT NOT NULL,
        code_hash TEXT NOT NULL,
        purpose TEXT NOT NULL DEFAULT 'register',
        expires_at TEXT NOT NULL,
        used_at TEXT NOT NULL DEFAULT '',
        ip TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT ''
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS api_tokens (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        token_hash TEXT NOT NULL UNIQUE,
        name TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT '',
        expires_at TEXT NOT NULL DEFAULT '',
        last_used_at TEXT NOT NULL DEFAULT '',
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS apps (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        appid TEXT NOT NULL DEFAULT '',
        app_key TEXT NOT NULL UNIQUE,
        app_secret TEXT NOT NULL,
        name TEXT NOT NULL,
        package_name TEXT NOT NULL DEFAULT '',
        target_host_package TEXT NOT NULL DEFAULT '',
        description TEXT NOT NULL DEFAULT '',
        is_disabled INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT '',
        updated_at TEXT NOT NULL DEFAULT '',
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    // 旧版后台升级：给已创建 App 补上公开 AppID。
    $appCols = $pdo->query("PRAGMA table_info(apps)")->fetchAll();
    $appColNames = array_map(function($c){ return $c['name']; }, $appCols);
    if (!in_array('appid', $appColNames, true)) {
        $pdo->exec("ALTER TABLE apps ADD COLUMN appid TEXT NOT NULL DEFAULT ''");
    }
    $emptyApps = $pdo->query("SELECT id FROM apps WHERE appid = '' OR appid IS NULL")->fetchAll();
    foreach ($emptyApps as $ea) {
        $stmtAid = $pdo->prepare('UPDATE apps SET appid = ? WHERE id = ?');
        $stmtAid->execute([generate_appid(), (int)$ea['id']]);
    }
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_apps_appid ON apps(appid)');

    // 如果从旧版后台升级，旧 versions 表没有 app_id/user_id，直接重命名备份，避免新用户系统报错。
    $tableExists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='versions'")->fetchColumn();
    if ($tableExists) {
        $cols = $pdo->query("PRAGMA table_info(versions)")->fetchAll();
        $names = array_map(function($c){ return $c['name']; }, $cols);
        if (!in_array('app_id', $names, true)) {
            $pdo->exec('ALTER TABLE versions RENAME TO versions_legacy_' . date('YmdHis'));
        }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS versions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        app_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        enabled INTEGER NOT NULL DEFAULT 1,
        patch_code INTEGER NOT NULL DEFAULT 0,
        patch_name TEXT NOT NULL DEFAULT '',
        patch_url TEXT NOT NULL DEFAULT '',
        sha256 TEXT NOT NULL DEFAULT '',
        package_name TEXT NOT NULL DEFAULT '',
        target_host_package TEXT NOT NULL DEFAULT '',
        min_host_version_code INTEGER NOT NULL DEFAULT 0,
        entry_class TEXT NOT NULL DEFAULT '',
        entry_method TEXT NOT NULL DEFAULT 'onLoad',
        merge_dex INTEGER NOT NULL DEFAULT 1,
        restart_after_apply INTEGER NOT NULL DEFAULT 1,
        auto_apply INTEGER NOT NULL DEFAULT 1,
        message TEXT NOT NULL DEFAULT '',
        file_name TEXT NOT NULL DEFAULT '',
        original_file_name TEXT NOT NULL DEFAULT '',
        file_size INTEGER NOT NULL DEFAULT 0,
        is_current INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT '',
        updated_at TEXT NOT NULL DEFAULT '',
        FOREIGN KEY(app_id) REFERENCES apps(id) ON DELETE CASCADE,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS cards (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT NOT NULL UNIQUE,
        days INTEGER NOT NULL,
        status TEXT NOT NULL DEFAULT 'unused',
        batch_no TEXT NOT NULL DEFAULT '',
        created_by INTEGER,
        used_by INTEGER,
        used_at TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT '',
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL,
        FOREIGN KEY(used_by) REFERENCES users(id) ON DELETE SET NULL
    )");

    db_setting_default('site_name', 'UcoreReload');
    db_setting_default('purchase_url', '');
    db_setting_default('homepage_app_download_url', '');
    db_setting_default('email_enabled', '0');
    db_setting_default('smtp_host', '');
    db_setting_default('smtp_port', '587');
    db_setting_default('smtp_secure', 'tls');
    db_setting_default('smtp_user', '');
    db_setting_default('smtp_pass', '');
    db_setting_default('smtp_from_email', '');
    db_setting_default('smtp_from_name', 'UcoreReload');
    db_setting_default('user_app_version_code', '1');
    db_setting_default('user_app_version_name', '1.0');
    db_setting_default('user_app_download_url', '');
    db_setting_default('user_app_update_message', '');
    db_setting_default('user_app_force_update', '0');
    db_setting_default('user_app_announcement_enabled', '0');
    db_setting_default('user_app_announcement_title', '公告');
    db_setting_default('user_app_announcement', '');
    db_setting_default('user_app_announcement_updated_at', '');

    $count = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_admin = 1')->fetchColumn();
    if ($count === 0) {
        $stmt = $pdo->prepare('INSERT INTO users(username,email,password_hash,email_verified,is_admin,is_banned,membership_until,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)');
        $stmt->execute([
            $DEFAULT_ADMIN_USERNAME,
            $DEFAULT_ADMIN_EMAIL,
            password_hash($DEFAULT_ADMIN_PASSWORD, PASSWORD_DEFAULT),
            1,
            1,
            0,
            '2099-12-31 23:59:59',
            now_time(),
            now_time()
        ]);
    }
}

function db_setting_default($key, $value) {
    $pdo = ucore_db();
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO settings(key,value,updated_at) VALUES(?,?,?)');
    $stmt->execute([$key, $value, now_time()]);
}
function db_get_setting($key, $default = '') {
    $stmt = ucore_db()->prepare('SELECT value FROM settings WHERE key = ?');
    $stmt->execute([$key]);
    $v = $stmt->fetchColumn();
    return $v === false ? $default : $v;
}
function db_set_setting($key, $value) {
    $stmt = ucore_db()->prepare('INSERT INTO settings(key,value,updated_at) VALUES(?,?,?) ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at');
    $stmt->execute([$key, (string)$value, now_time()]);
}
function db_get_settings() {
    $rows = ucore_db()->query('SELECT key,value FROM settings')->fetchAll();
    $out = [];
    foreach ($rows as $r) $out[$r['key']] = $r['value'];
    return $out;
}

function infer_public_base_url() {
    global $PUBLIC_BASE_URL;
    if ($PUBLIC_BASE_URL !== '') return rtrim($PUBLIC_BASE_URL, '/');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $script = $_SERVER['SCRIPT_NAME'] ?? '/backend/api.php';
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if ($host === '') return '';
    return $scheme . '://' . $host . ($dir === '/' ? '' : $dir);
}
function make_patch_url($fileName) {
    $base = infer_public_base_url();
    if ($base === '') return 'downloads/' . rawurlencode($fileName);
    return rtrim($base, '/') . '/downloads/' . rawurlencode($fileName);
}

function make_avatar_url($fileName) {
    $base = infer_public_base_url();
    if ($base === '') return 'uploads/avatars/' . rawurlencode($fileName);
    return rtrim($base, '/') . '/uploads/avatars/' . rawurlencode($fileName);
}
function save_uploaded_avatar($file) {
    global $AVATAR_DIR;
    if (!isset($file) || (int)$file['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ((int)$file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('头像上传失败，错误码：' . (int)$file['error']);
    $originalName = basename($file['name']);
    if (!preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $originalName)) throw new RuntimeException('头像只支持 jpg、png、gif、webp。');
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $safeName = 'avatar_' . date('Ymd_His') . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
    $target = $AVATAR_DIR . '/' . $safeName;
    if (!move_uploaded_file($file['tmp_name'], $target)) throw new RuntimeException('头像保存失败，请检查 uploads/avatars 目录权限。');
    return make_avatar_url($safeName);
}

function save_uploaded_user_app_apk($file) {
    global $DOWNLOAD_DIR;
    if (!isset($file) || (int)$file['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ((int)$file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('软件 APK 上传失败，错误码：' . (int)$file['error']);
    $originalName = basename($file['name']);
    if (!preg_match('/\.apk$/i', $originalName)) throw new RuntimeException('UcoreReloadsUser 软件只能上传 APK 文件。');
    $safeName = 'ucorereloadsuser_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.apk';
    $target = $DOWNLOAD_DIR . '/' . $safeName;
    if (!move_uploaded_file($file['tmp_name'], $target)) throw new RuntimeException('软件 APK 保存失败，请检查 downloads 目录权限。');
    return [
        'fileName' => $safeName,
        'originalFileName' => $originalName,
        'fileSize' => filesize($target) ?: 0,
        'sha256' => hash_file('sha256', $target),
        'downloadUrl' => make_patch_url($safeName),
    ];
}

function user_app_public_config() {
    $versionCode = max(1, (int)db_get_setting('user_app_version_code', '1'));
    $versionName = db_get_setting('user_app_version_name', '1.0');
    $downloadUrl = db_get_setting('user_app_download_url', '');
    $homepageDownloadUrl = db_get_setting('homepage_app_download_url', '') ?: $downloadUrl;
    $updateMessage = db_get_setting('user_app_update_message', '');
    $forceUpdate = db_get_setting('user_app_force_update', '0') === '1';
    $announcementEnabled = db_get_setting('user_app_announcement_enabled', '0') === '1';
    $announcementTitle = db_get_setting('user_app_announcement_title', '公告');
    $announcement = db_get_setting('user_app_announcement', '');
    $announcementUpdatedAt = db_get_setting('user_app_announcement_updated_at', '');
    $hash = md5($announcementTitle . "
" . $announcement . "
" . $announcementUpdatedAt);
    return [
        'appUpdate' => [
            'versionCode' => $versionCode,
            'versionName' => $versionName,
            'downloadUrl' => $downloadUrl,
            'message' => $updateMessage,
            'forceUpdate' => $forceUpdate,
        ],
        'announcement' => [
            'enabled' => $announcementEnabled,
            'title' => $announcementTitle,
            'content' => $announcement,
            'hash' => $hash,
            'updatedAt' => $announcementUpdatedAt,
        ],
        'homepage' => [
            'siteName' => db_get_setting('site_name', 'UcoreReload'),
            'downloadUrl' => $homepageDownloadUrl,
            'purchaseUrl' => db_get_setting('purchase_url', ''),
        ],
    ];
}

function validate_username($username) {
    return preg_match('/^[A-Za-z0-9_\x{4e00}-\x{9fa5}]{3,32}$/u', $username);
}
function find_user_by_login($login) {
    $stmt = ucore_db()->prepare('SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1');
    $stmt->execute([$login, $login]);
    return $stmt->fetch() ?: null;
}
function find_user_by_email($email) {
    $stmt = ucore_db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([strtolower(trim($email))]);
    return $stmt->fetch() ?: null;
}
function find_user_by_username($username) {
    $stmt = ucore_db()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([trim($username)]);
    return $stmt->fetch() ?: null;
}
function find_user($id) {
    $stmt = ucore_db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([(int)$id]);
    return $stmt->fetch() ?: null;
}
function user_is_member($user) {
    if (!$user) return false;
    if (!empty($user['is_admin'])) return true;
    $until = (string)($user['membership_until'] ?? '');
    return $until !== '' && strtotime($until) >= time();
}
function normalize_gender($gender) {
    $gender = strtolower(trim((string)$gender));
    $allowed = ['male','female','other','secret',''];
    return in_array($gender, $allowed, true) ? $gender : 'secret';
}
function normalize_birthday($birthday) {
    $birthday = trim((string)$birthday);
    if ($birthday === '') return '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthday)) return '';
    [$y,$m,$d] = array_map('intval', explode('-', $birthday));
    return checkdate($m, $d, $y) ? $birthday : '';
}
function user_member_status($user) {
    if (!$user) return 'none';
    if (!empty($user['is_admin'])) return 'admin_lifetime';
    $until = (string)($user['membership_until'] ?? '');
    if ($until === '') return 'none';
    return strtotime($until) >= time() ? 'active' : 'expired';
}

function user_public($u) {
    return [
        'id' => (int)$u['id'],
        'username' => $u['username'],
        'nickname' => $u['nickname'] ?: $u['username'],
        'email' => $u['email'],
        'avatarUrl' => $u['avatar_url'] ?? '',
        'gender' => $u['gender'] ?? '',
        'birthday' => $u['birthday'] ?? '',
        'emailVerified' => (bool)$u['email_verified'],
        'isAdmin' => (bool)$u['is_admin'],
        'isBanned' => (bool)$u['is_banned'],
        'membershipUntil' => $u['membership_until'],
        'memberStatus' => user_member_status($u),
        'isMember' => user_is_member($u),
        'createdAt' => $u['created_at'],
        'updatedAt' => $u['updated_at'],
        'lastLoginAt' => $u['last_login_at'],
    ];
}
function create_token($userId, $name = 'api') {
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $stmt = ucore_db()->prepare('INSERT INTO api_tokens(user_id,token_hash,name,created_at,expires_at) VALUES(?,?,?,?,?)');
    $stmt->execute([(int)$userId, $hash, $name, now_time(), date('Y-m-d H:i:s', time() + 86400 * 30)]);
    return $token;
}
function bearer_token() {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(.+)/i', $h, $m)) return trim($m[1]);
    return $_POST['token'] ?? $_GET['token'] ?? '';
}
function api_current_user() {
    $token = bearer_token();
    if ($token === '') return null;
    $hash = hash('sha256', $token);
    $stmt = ucore_db()->prepare('SELECT u.* FROM api_tokens t JOIN users u ON u.id = t.user_id WHERE t.token_hash = ? AND (t.expires_at = "" OR t.expires_at >= ?) LIMIT 1');
    $stmt->execute([$hash, now_time()]);
    $u = $stmt->fetch();
    if ($u) {
        $upd = ucore_db()->prepare('UPDATE api_tokens SET last_used_at = ? WHERE token_hash = ?');
        $upd->execute([now_time(), $hash]);
    }
    return $u ?: null;
}
function require_api_user() {
    $u = api_current_user();
    if (!$u) api_error('未登录或 token 无效', 401);
    if ((int)$u['is_banned'] === 1) api_error('账号已被封禁', 403);
    return $u;
}
function require_api_admin() {
    $u = require_api_user();
    if ((int)$u['is_admin'] !== 1) api_error('需要管理员权限', 403);
    return $u;
}
function require_member($u) {
    if (!user_is_member($u)) api_error('需要会员才能发布新版本', 403, ['purchaseUrl' => db_get_setting('purchase_url', '')]);
}

function save_email_code($email, $purpose = 'register') {
    $code = (string)random_int(100000, 999999);
    $stmt = ucore_db()->prepare('INSERT INTO email_codes(email,code_hash,purpose,expires_at,ip,created_at) VALUES(?,?,?,?,?,?)');
    $stmt->execute([strtolower($email), password_hash($code, PASSWORD_DEFAULT), $purpose, date('Y-m-d H:i:s', time() + 600), $_SERVER['REMOTE_ADDR'] ?? '', now_time()]);
    return $code;
}
function verify_email_code($email, $code, $purpose = 'register') {
    $stmt = ucore_db()->prepare('SELECT * FROM email_codes WHERE email = ? AND purpose = ? AND used_at = "" AND expires_at >= ? ORDER BY id DESC LIMIT 5');
    $stmt->execute([strtolower($email), $purpose, now_time()]);
    $rows = $stmt->fetchAll();
    foreach ($rows as $r) {
        if (password_verify($code, $r['code_hash'])) {
            $upd = ucore_db()->prepare('UPDATE email_codes SET used_at = ? WHERE id = ?');
            $upd->execute([now_time(), $r['id']]);
            return true;
        }
    }
    return false;
}

function smtp_read($fp) {
    $data = '';
    while (($line = fgets($fp, 515)) !== false) {
        $data .= $line;
        if (isset($line[3]) && $line[3] === ' ') break;
    }
    return $data;
}
function smtp_cmd($fp, $cmd, $expect = null) {
    if ($cmd !== null) fwrite($fp, $cmd . "\r\n");
    $resp = smtp_read($fp);
    if ($expect !== null && substr($resp, 0, 3) !== (string)$expect) {
        throw new RuntimeException('SMTP 命令失败：' . trim($resp));
    }
    return $resp;
}
function send_smtp_mail($to, $subject, $body, $isHtml = false) {
    $enabled = db_get_setting('email_enabled', '0') === '1';
    if (!$enabled) throw new RuntimeException('后台未启用邮箱发送，请管理员先配置邮箱。');
    $host = db_get_setting('smtp_host', '');
    $port = (int)db_get_setting('smtp_port', '587');
    $secure = strtolower(db_get_setting('smtp_secure', 'tls'));
    $user = db_get_setting('smtp_user', '');
    $pass = db_get_setting('smtp_pass', '');
    $fromEmail = db_get_setting('smtp_from_email', '') ?: $user;
    $fromName = db_get_setting('smtp_from_name', 'UcoreReload');
    if ($host === '' || $fromEmail === '') throw new RuntimeException('邮箱配置不完整。');

    $target = ($secure === 'ssl' ? 'ssl://' : '') . $host;
    $fp = @stream_socket_client($target . ':' . $port, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);
    if (!$fp) throw new RuntimeException('SMTP 连接失败：' . $errstr);
    stream_set_timeout($fp, 20);
    smtp_cmd($fp, null, 220);
    smtp_cmd($fp, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'), 250);
    if ($secure === 'tls') {
        smtp_cmd($fp, 'STARTTLS', 220);
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('SMTP TLS 启动失败');
        smtp_cmd($fp, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'), 250);
    }
    if ($user !== '') {
        smtp_cmd($fp, 'AUTH LOGIN', 334);
        smtp_cmd($fp, base64_encode($user), 334);
        smtp_cmd($fp, base64_encode($pass), 235);
    }
    smtp_cmd($fp, 'MAIL FROM:<' . $fromEmail . '>', 250);
    smtp_cmd($fp, 'RCPT TO:<' . $to . '>', 250);
    smtp_cmd($fp, 'DATA', 354);
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $encodedFrom = '=?UTF-8?B?' . base64_encode($fromName) . '?= <' . $fromEmail . '>';
    $headers = [
        'From: ' . $encodedFrom,
        'To: <' . $to . '>',
        'Subject: ' . $encodedSubject,
        'MIME-Version: 1.0',
        'Content-Type: ' . ($isHtml ? 'text/html' : 'text/plain') . '; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
    ];
    $msg = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($body)) . "\r\n.";
    smtp_cmd($fp, $msg, 250);
    @smtp_cmd($fp, 'QUIT', 221);
    fclose($fp);
    return true;
}
function render_code_email_html($title, $code, $subtitle = '安全 · 高效 · 凭证服务') {
    $site = db_get_setting('site_name', 'UcoreReload');
    $safeTitle = h($title);
    $safeSite = h($site);
    $safeCode = h($code);
    $safeSubtitle = h($subtitle);
    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>' .
        '<body style="margin:0;background:linear-gradient(135deg,#f6fbff,#eef5ff 50%,#fff7fb);font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Noto Sans SC,Arial,sans-serif;color:#111827;">' .
        '<div style="padding:34px 18px;">' .
        '<div style="max-width:680px;margin:0 auto;">' .
        '<div style="font-size:28px;font-weight:800;letter-spacing:-.03em;margin:0 0 18px;">【' . $safeSite . '】' . $safeTitle . '</div>' .
        '<div style="background:rgba(255,255,255,.72);border:1px solid rgba(255,255,255,.82);border-radius:34px;padding:42px 24px;box-shadow:0 28px 80px rgba(31,41,55,.12);">' .
        '<div style="text-align:center;margin:12px auto 40px;">' .
        '<div style="font-size:40px;line-height:1.18;font-weight:500;letter-spacing:-.05em;">' . $safeSite . ' 安全中心</div>' .
        '<div style="font-size:18px;color:#8a94a6;margin-top:14px;">' . $safeSubtitle . '</div>' .
        '</div>' .
        '<div style="max-width:470px;margin:0 auto;background:rgba(241,245,249,.74);border:1px solid rgba(255,255,255,.9);border-radius:30px;padding:30px 20px;text-align:center;box-shadow:inset 0 1px 0 rgba(255,255,255,.9);">' .
        '<div style="font-size:22px;color:#596272;margin-bottom:18px;">您的动态验证码为：</div>' .
        '<div style="display:inline-block;min-width:300px;border-radius:999px;background:linear-gradient(135deg,#0a84ff,#2451df);padding:18px 30px;color:#fff;font-size:54px;font-weight:900;letter-spacing:.18em;box-shadow:0 18px 42px rgba(36,81,223,.28);">' . $safeCode . '</div>' .
        '<div style="font-size:14px;color:#8a94a6;margin-top:20px;">验证码 10 分钟内有效，请勿转发给他人。</div>' .
        '</div>' .
        '<div style="margin:28px auto 0;max-width:470px;color:#8a94a6;font-size:14px;line-height:1.7;text-align:center;">如果不是您本人操作，请忽略这封邮件。</div>' .
        '</div>' .
        '</div></div></body></html>';
}
function send_verification_email($email, $code, $purpose = 'register') {
    $site = db_get_setting('site_name', 'UcoreReload');
    $purpose = $purpose ?: 'register';
    if ($purpose === 'reset_password') {
        $subject = $site . ' 找回密码验证码';
        $title = '您的找回密码验证码为 ' . $code;
    } elseif ($purpose === 'test') {
        $subject = $site . ' 邮箱配置测试';
        $title = '您的测试验证码为 ' . $code;
    } else {
        $subject = $site . ' 注册验证码';
        $title = '您的验证码为 ' . $code;
    }
    $html = render_code_email_html($title, $code);
    return send_smtp_mail($email, $subject, $html, true);
}
function send_test_email($email) {
    $code = (string)random_int(100000, 999999);
    send_verification_email($email, $code, 'test');
    return $code;
}

function generate_appid() {
    $pdo = ucore_db();
    do {
        $appid = 'UA' . strtoupper(bin2hex(random_bytes(5)));
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM apps WHERE appid = ?');
        $stmt->execute([$appid]);
    } while ((int)$stmt->fetchColumn() > 0);
    return $appid;
}

function create_app_for_user($userId, $name, $packageName = '', $targetHostPackage = '', $desc = '') {
    $appid = generate_appid();
    $key = 'app_' . bin2hex(random_bytes(10));
    $secret = bin2hex(random_bytes(16));
    $stmt = ucore_db()->prepare('INSERT INTO apps(user_id,appid,app_key,app_secret,name,package_name,target_host_package,description,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([(int)$userId, $appid, $key, $secret, $name, $packageName, $targetHostPackage, $desc, now_time(), now_time()]);
    return (int)ucore_db()->lastInsertId();
}
function get_app($id) {
    $stmt = ucore_db()->prepare('SELECT a.*, u.username owner_username FROM apps a JOIN users u ON u.id = a.user_id WHERE a.id = ?');
    $stmt->execute([(int)$id]);
    return $stmt->fetch() ?: null;
}
function get_app_by_key($key) {
    $stmt = ucore_db()->prepare('SELECT * FROM apps WHERE app_key = ? AND is_disabled = 0');
    $stmt->execute([$key]);
    return $stmt->fetch() ?: null;
}
function get_app_by_appid($appid) {
    $stmt = ucore_db()->prepare('SELECT * FROM apps WHERE appid = ? AND is_disabled = 0');
    $stmt->execute([trim($appid)]);
    return $stmt->fetch() ?: null;
}
function can_manage_app($u, $app) {
    return $u && $app && ((int)$u['is_admin'] === 1 || (int)$app['user_id'] === (int)$u['id']);
}
function list_apps_for_user($u) {
    if ((int)$u['is_admin'] === 1) {
        return ucore_db()->query('SELECT a.*, u.username owner_username FROM apps a JOIN users u ON u.id = a.user_id ORDER BY a.id DESC')->fetchAll();
    }
    $stmt = ucore_db()->prepare('SELECT a.*, u.username owner_username FROM apps a JOIN users u ON u.id = a.user_id WHERE a.user_id = ? ORDER BY a.id DESC');
    $stmt->execute([(int)$u['id']]);
    return $stmt->fetchAll();
}
function list_apps_for_owner($userId) {
    $stmt = ucore_db()->prepare('SELECT a.*, u.username owner_username, u.email owner_email FROM apps a JOIN users u ON u.id = a.user_id WHERE a.user_id = ? ORDER BY a.id DESC');
    $stmt->execute([(int)$userId]);
    return $stmt->fetchAll();
}
function list_apps_with_versions_for_owner($userId) {
    $apps = list_apps_for_owner($userId);
    foreach ($apps as &$app) {
        $app['versions'] = list_versions((int)$app['id']);
    }
    unset($app);
    return $apps;
}
function list_versions($appId) {
    $stmt = ucore_db()->prepare('SELECT * FROM versions WHERE app_id = ? ORDER BY patch_code DESC, id DESC');
    $stmt->execute([(int)$appId]);
    return $stmt->fetchAll();
}
function get_version($id) {
    $stmt = ucore_db()->prepare('SELECT * FROM versions WHERE id = ?');
    $stmt->execute([(int)$id]);
    return $stmt->fetch() ?: null;
}
function current_version_for_app($appId) {
    $stmt = ucore_db()->prepare('SELECT v.*, a.appid, a.app_key, a.name app_name FROM versions v JOIN apps a ON a.id = v.app_id WHERE v.app_id = ? AND v.is_current = 1 AND v.enabled = 1 AND a.is_disabled = 0 ORDER BY v.patch_code DESC LIMIT 1');
    $stmt->execute([(int)$appId]);
    return $stmt->fetch() ?: null;
}
function current_version_any() {
    $stmt = ucore_db()->query('SELECT v.*, a.appid, a.app_key, a.name app_name FROM versions v JOIN apps a ON a.id = v.app_id WHERE v.is_current = 1 AND v.enabled = 1 AND a.is_disabled = 0 ORDER BY v.patch_code DESC LIMIT 1');
    return $stmt->fetch() ?: null;
}
function disabled_config() {
    return [
        'enabled' => false,
        'patchCode' => 0,
        'patchName' => '',
        'patchUrl' => '',
        'sha256' => '',
        'packageName' => '',
        'targetHostPackage' => '',
        'minHostVersionCode' => 1,
        'entryClass' => '',
        'entryMethod' => 'onLoad',
        'mergeDex' => true,
        'restartAfterApply' => true,
        'autoApply' => true,
        'message' => 'No patch configured'
    ];
}
function version_payload($v) {
    if (!$v) return disabled_config();
    return [
        'enabled' => ((int)$v['enabled']) === 1,
        'patchCode' => (int)$v['patch_code'],
        'patchName' => $v['patch_name'],
        'patchUrl' => $v['patch_url'],
        'sha256' => $v['sha256'],
        'packageName' => $v['package_name'],
        'targetHostPackage' => $v['target_host_package'],
        'minHostVersionCode' => (int)$v['min_host_version_code'],
        'entryClass' => $v['entry_class'],
        'entryMethod' => $v['entry_method'] ?: 'onLoad',
        'mergeDex' => ((int)$v['merge_dex']) === 1,
        'restartAfterApply' => ((int)$v['restart_after_apply']) === 1,
        'autoApply' => ((int)$v['auto_apply']) === 1,
        'message' => $v['message'],
        'appId' => $v['appid'] ?? '',
        'appKey' => $v['app_key'] ?? '',
        'appName' => $v['app_name'] ?? '',
    ];
}
function save_uploaded_patch($file, $patchCode, $oldFileName = '') {
    global $DOWNLOAD_DIR;
    if (!isset($file) || (int)$file['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ((int)$file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('上传失败，错误码：' . (int)$file['error']);
    $originalName = basename($file['name']);
    if (!preg_match('/\.(apk|jar|dex|zip)$/i', $originalName)) throw new RuntimeException('只能上传 .apk / .jar / .dex / .zip，完整资源热更新请上传 APK');
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $safeName = 'patch_v' . max(1, (int)$patchCode) . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
    $target = $DOWNLOAD_DIR . '/' . $safeName;
    if (!move_uploaded_file($file['tmp_name'], $target)) throw new RuntimeException('补丁保存失败，请检查 downloads 目录权限');
    if ($oldFileName !== '' && $oldFileName !== $safeName && preg_match('/^[A-Za-z0-9._-]+$/', $oldFileName)) {
        $old = $DOWNLOAD_DIR . '/' . $oldFileName;
        if (is_file($old)) @unlink($old);
    }
    return [
        'fileName' => $safeName,
        'originalFileName' => $originalName,
        'fileSize' => filesize($target) ?: 0,
        'sha256' => hash_file('sha256', $target),
        'patchUrl' => make_patch_url($safeName),
    ];
}
function create_or_update_version($user, $app, $post, $file = null, $existing = null) {
    $pdo = ucore_db();
    $patchCode = max(1, (int)($post['patchCode'] ?? ($existing['patch_code'] ?? 1)));
    $upload = null;
    if ($file && isset($file['patch']) && (int)$file['patch']['error'] !== UPLOAD_ERR_NO_FILE) {
        $upload = save_uploaded_patch($file['patch'], $patchCode, $existing['file_name'] ?? '');
    }
    if (!$existing && !$upload && trim($post['patchUrl'] ?? '') === '') throw new RuntimeException('请上传补丁 APK 或填写 patchUrl。');
    $data = [
        'app_id' => (int)$app['id'],
        'user_id' => (int)$app['user_id'],
        'enabled' => !empty($post['enabled']) ? 1 : 0,
        'patch_code' => $patchCode,
        'patch_name' => trim($post['patchName'] ?? ($existing['patch_name'] ?? 'hotfix-' . $patchCode)),
        'patch_url' => $upload['patchUrl'] ?? trim($post['patchUrl'] ?? ($existing['patch_url'] ?? '')),
        'sha256' => $upload['sha256'] ?? trim($post['sha256'] ?? ($existing['sha256'] ?? '')),
        'package_name' => trim($post['packageName'] ?? ($existing['package_name'] ?? '')),
        'target_host_package' => trim($post['targetHostPackage'] ?? ($existing['target_host_package'] ?? ($app['target_host_package'] ?: $app['package_name']))),
        'min_host_version_code' => max(0, (int)($post['minHostVersionCode'] ?? ($existing['min_host_version_code'] ?? 1))),
        'entry_class' => trim($post['entryClass'] ?? ($existing['entry_class'] ?? '')),
        'entry_method' => trim($post['entryMethod'] ?? ($existing['entry_method'] ?? 'onLoad')) ?: 'onLoad',
        'merge_dex' => !empty($post['mergeDex']) ? 1 : 0,
        'restart_after_apply' => !empty($post['restartAfterApply']) ? 1 : 0,
        'auto_apply' => !empty($post['autoApply']) ? 1 : 0,
        'message' => trim($post['message'] ?? ($existing['message'] ?? '发现热更新补丁。')),
        'file_name' => $upload['fileName'] ?? ($existing['file_name'] ?? ''),
        'original_file_name' => $upload['originalFileName'] ?? ($existing['original_file_name'] ?? ''),
        'file_size' => $upload['fileSize'] ?? (int)($existing['file_size'] ?? 0),
        'updated_at' => now_time(),
    ];
    $setCurrent = !empty($post['setCurrent']);
    if ($setCurrent) {
        $stmt = $pdo->prepare('UPDATE versions SET is_current = 0 WHERE app_id = ?');
        $stmt->execute([(int)$app['id']]);
    }
    if ($existing) {
        $stmt = $pdo->prepare('UPDATE versions SET enabled=:enabled, patch_code=:patch_code, patch_name=:patch_name, patch_url=:patch_url, sha256=:sha256, package_name=:package_name, target_host_package=:target_host_package, min_host_version_code=:min_host_version_code, entry_class=:entry_class, entry_method=:entry_method, merge_dex=:merge_dex, restart_after_apply=:restart_after_apply, auto_apply=:auto_apply, message=:message, file_name=:file_name, original_file_name=:original_file_name, file_size=:file_size, is_current=:is_current, updated_at=:updated_at WHERE id=:id');
        $updateData = $data;
        unset($updateData['app_id'], $updateData['user_id']);
        $updateData['is_current'] = $setCurrent ? 1 : (int)$existing['is_current'];
        $updateData['id'] = (int)$existing['id'];
        $stmt->execute($updateData);
        return (int)$existing['id'];
    } else {
        $stmt = $pdo->prepare('INSERT INTO versions(app_id,user_id,enabled,patch_code,patch_name,patch_url,sha256,package_name,target_host_package,min_host_version_code,entry_class,entry_method,merge_dex,restart_after_apply,auto_apply,message,file_name,original_file_name,file_size,is_current,created_at,updated_at) VALUES(:app_id,:user_id,:enabled,:patch_code,:patch_name,:patch_url,:sha256,:package_name,:target_host_package,:min_host_version_code,:entry_class,:entry_method,:merge_dex,:restart_after_apply,:auto_apply,:message,:file_name,:original_file_name,:file_size,:is_current,:created_at,:updated_at)');
        $data['is_current'] = $setCurrent ? 1 : 0;
        $data['created_at'] = now_time();
        $stmt->execute($data);
        return (int)$pdo->lastInsertId();
    }
}

function generate_card_code($prefix = 'UR') {
    $prefix = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $prefix));
    if ($prefix === '') $prefix = 'UR';
    return $prefix . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)) . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
}
function create_cards($days, $count, $creatorId, $prefix = 'UR') {
    $days = (int)$days; $count = max(1, min(1000, (int)$count));
    if (!in_array($days, [1, 30, 60, 90, 365], true)) throw new RuntimeException('会员天数只能是 1、30、60、90、365');
    $pdo = ucore_db();
    $batch = 'B' . date('YmdHis') . strtoupper(bin2hex(random_bytes(2)));
    $codes = [];
    $stmt = $pdo->prepare('INSERT INTO cards(code,days,status,batch_no,created_by,created_at) VALUES(?,?,?,?,?,?)');
    for ($i = 0; $i < $count; $i++) {
        do { $code = generate_card_code($prefix); } while ($pdo->query("SELECT COUNT(*) FROM cards WHERE code = " . $pdo->quote($code))->fetchColumn() > 0);
        $stmt->execute([$code, $days, 'unused', $batch, (int)$creatorId, now_time()]);
        $codes[] = $code;
    }
    return ['batchNo' => $batch, 'codes' => $codes];
}
function redeem_card($userId, $code) {
    $pdo = ucore_db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM cards WHERE code = ? LIMIT 1');
        $stmt->execute([trim($code)]);
        $card = $stmt->fetch();
        if (!$card) throw new RuntimeException('卡密不存在');
        if ($card['status'] !== 'unused') throw new RuntimeException('卡密已使用或已失效');
        $user = find_user($userId);
        if (!$user) throw new RuntimeException('用户不存在');
        $base = time();
        if (!empty($user['membership_until']) && strtotime($user['membership_until']) > $base) $base = strtotime($user['membership_until']);
        $newUntil = date('Y-m-d H:i:s', $base + (int)$card['days'] * 86400);
        $updUser = $pdo->prepare('UPDATE users SET membership_until = ?, updated_at = ? WHERE id = ?');
        $updUser->execute([$newUntil, now_time(), (int)$userId]);
        $updCard = $pdo->prepare('UPDATE cards SET status = ?, used_by = ?, used_at = ? WHERE id = ?');
        $updCard->execute(['used', (int)$userId, now_time(), (int)$card['id']]);
        $pdo->commit();
        return $newUntil;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// 初始化数据库
ucore_db();

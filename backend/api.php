<?php
require_once __DIR__ . '/db.php';
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;
if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    if (is_array($json)) $_POST = array_merge($_POST, $json);
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'check_update';

try {
    switch ($action) {
        case 'check_update': {
            $appId = trim($_GET['appId'] ?? $_GET['appid'] ?? $_GET['app_id'] ?? $_POST['appId'] ?? $_POST['appid'] ?? $_POST['app_id'] ?? '');
            $appKey = trim($_GET['appKey'] ?? $_GET['app_key'] ?? $_POST['appKey'] ?? $_POST['app_key'] ?? '');
            if ($appId !== '') {
                $app = get_app_by_appid($appId);
                if (!$app) json_response(disabled_config());
                json_response(version_payload(current_version_for_app((int)$app['id'])));
            }
            // 兼容旧客户端：仍然允许 appKey 查询，但新版 library 只使用 AppID。
            if ($appKey !== '') {
                $app = get_app_by_key($appKey);
                if (!$app) json_response(disabled_config());
                json_response(version_payload(current_version_for_app((int)$app['id'])));
            }
            json_response(version_payload(current_version_any()));
        }

        case 'purchase_info': {
            api_ok(['purchaseUrl' => db_get_setting('purchase_url', ''), 'siteName' => db_get_setting('site_name', 'UcoreReload')]);
        }

        case 'user_app_config':
        case 'homepage_config': {
            api_ok(user_app_public_config(), 'ok');
        }

        case 'send_email_code': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error('请使用 POST 请求', 405);
            $email = strtolower(trim($_POST['email'] ?? ''));
            $purpose = trim($_POST['purpose'] ?? 'register');
            if (!in_array($purpose, ['register', 'reset_password'], true)) $purpose = 'register';
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) api_error('邮箱格式不正确');
            $exists = find_user_by_email($email);
            if ($purpose === 'register' && $exists) api_error('该邮箱已注册');
            if ($purpose === 'reset_password' && !$exists) api_error('该邮箱还没有注册');
            $code = save_email_code($email, $purpose);
            send_verification_email($email, $code, $purpose);
            api_ok(['expiresIn' => 600, 'purpose' => $purpose], '验证码已发送到邮箱');
        }

        case 'register': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error('请使用 POST 请求', 405);
            $username = trim($_POST['username'] ?? '');
            $email = strtolower(trim($_POST['email'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $code = trim($_POST['code'] ?? '');
            if (!validate_username($username)) api_error('用户名只能是 3-32 位中文、字母、数字或下划线');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) api_error('邮箱格式不正确');
            if (find_user_by_email($email)) api_error('该邮箱已注册');
            if (find_user_by_username($username)) api_error('用户名已存在');
            if (strlen($password) < 6) api_error('密码至少 6 位');
            if (!verify_email_code($email, $code, 'register')) api_error('邮箱验证码错误或已过期');
            $pdo = ucore_db();
            $stmt = $pdo->prepare('INSERT INTO users(username,email,password_hash,email_verified,is_admin,is_banned,nickname,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), 1, 0, 0, $username, now_time(), now_time()]);
            $id = (int)$pdo->lastInsertId();
            $token = create_token($id, 'register');
            api_ok(['token' => $token, 'user' => user_public(find_user($id))], '注册成功');
        }

        case 'reset_password': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error('请使用 POST 请求', 405);
            $email = strtolower(trim($_POST['email'] ?? ''));
            $code = trim($_POST['code'] ?? '');
            $password = (string)($_POST['password'] ?? $_POST['newPassword'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) api_error('邮箱格式不正确');
            if (strlen($password) < 6) api_error('新密码至少 6 位');
            $u = find_user_by_email($email);
            if (!$u) api_error('该邮箱还没有注册', 404);
            if (!verify_email_code($email, $code, 'reset_password')) api_error('邮箱验证码错误或已过期');
            $stmt = ucore_db()->prepare('UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?');
            $stmt->execute([password_hash($password, PASSWORD_DEFAULT), now_time(), (int)$u['id']]);
            api_ok([], '密码已重置，请使用新密码登录');
        }

        case 'login': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error('请使用 POST 请求', 405);
            $login = trim($_POST['login'] ?? $_POST['username'] ?? '');
            $password = (string)($_POST['password'] ?? '');
            $u = find_user_by_login($login);
            if (!$u || !password_verify($password, $u['password_hash'])) api_error('账号或密码错误', 401);
            if ((int)$u['is_banned'] === 1) api_error('账号已被封禁', 403);
            if ((int)$u['email_verified'] !== 1) api_error('邮箱未验证', 403);
            $upd = ucore_db()->prepare('UPDATE users SET last_login_at = ? WHERE id = ?');
            $upd->execute([now_time(), (int)$u['id']]);
            $token = create_token((int)$u['id'], 'login');
            api_ok(['token' => $token, 'user' => user_public(find_user((int)$u['id']))], '登录成功');
        }

        case 'me':
        case 'profile_get': {
            $u = require_api_user();
            api_ok(['user' => user_public($u), 'purchaseUrl' => db_get_setting('purchase_url', '')]);
        }

        case 'profile_update': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error('请使用 POST 请求', 405);
            $u = require_api_user();
            $username = trim($_POST['username'] ?? $u['username']);
            $nickname = trim($_POST['nickname'] ?? $u['nickname'] ?? $u['username']);
            $email = strtolower(trim($_POST['email'] ?? $u['email']));
            $avatarUrl = trim($_POST['avatarUrl'] ?? $_POST['avatar_url'] ?? $u['avatar_url'] ?? '');
            if (isset($_FILES['avatar']) && (int)$_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
                $avatarUrl = save_uploaded_avatar($_FILES['avatar']);
            }
            $gender = normalize_gender($_POST['gender'] ?? $u['gender'] ?? '');
            $birthday = normalize_birthday($_POST['birthday'] ?? $u['birthday'] ?? '');
            if (!validate_username($username)) api_error('用户名只能是 3-32 位中文、字母、数字或下划线');
            if ($nickname === '') $nickname = $username;
            if ((function_exists('mb_strlen') ? mb_strlen($nickname, 'UTF-8') : strlen($nickname)) > 32) api_error('昵称最多 32 个字符');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) api_error('邮箱格式不正确');
            if ($avatarUrl !== '' && !preg_match('/^https?:\/\//i', $avatarUrl)) api_error('头像地址必须是 http 或 https 链接');
            $dupEmail = find_user_by_email($email);
            if ($dupEmail && (int)$dupEmail['id'] !== (int)$u['id']) api_error('该邮箱已被其他用户使用');
            $dupName = find_user_by_username($username);
            if ($dupName && (int)$dupName['id'] !== (int)$u['id']) api_error('该用户名已被其他用户使用');
            $stmt = ucore_db()->prepare('UPDATE users SET username=?, nickname=?, email=?, avatar_url=?, gender=?, birthday=?, updated_at=? WHERE id=?');
            $stmt->execute([$username, $nickname, $email, $avatarUrl, $gender, $birthday, now_time(), (int)$u['id']]);
            api_ok(['user' => user_public(find_user((int)$u['id']))], '个人资料已更新');
        }

        case 'avatar_upload': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error('请使用 POST 请求', 405);
            require_api_user();
            if (!isset($_FILES['avatar'])) api_error('请选择头像文件');
            $url = save_uploaded_avatar($_FILES['avatar']);
            api_ok(['avatarUrl' => $url], '头像已上传');
        }

        case 'redeem_card': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error('请使用 POST 请求', 405);
            $u = require_api_user();
            $code = trim($_POST['code'] ?? '');
            if ($code === '') api_error('请输入卡密');
            $until = redeem_card((int)$u['id'], $code);
            api_ok(['membershipUntil' => $until, 'user' => user_public(find_user((int)$u['id']))], '兑换成功');
        }

        case 'app_create': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error('请使用 POST 请求', 405);
            $u = require_api_user();
            $name = trim($_POST['name'] ?? '');
            if ($name === '') api_error('App 名称不能为空');
            $id = create_app_for_user((int)$u['id'], $name, trim($_POST['packageName'] ?? ''), trim($_POST['targetHostPackage'] ?? ''), trim($_POST['description'] ?? ''));
            api_ok(['app' => get_app($id)], 'App 创建成功');
        }

        case 'app_list': {
            $u = require_api_user();
            api_ok(['apps' => list_apps_for_user($u)]);
        }

        case 'app_detail': {
            $u = require_api_user();
            $app = get_app((int)($_GET['appId'] ?? $_POST['appId'] ?? 0));
            if (!can_manage_app($u, $app)) api_error('App 不存在或无权限', 404);
            api_ok(['app' => $app, 'versions' => list_versions((int)$app['id'])]);
        }

        case 'app_update': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error('请使用 POST 请求', 405);
            $u = require_api_user();
            $app = get_app((int)($_POST['appId'] ?? 0));
            if (!can_manage_app($u, $app)) api_error('App 不存在或无权限', 404);
            $stmt = ucore_db()->prepare('UPDATE apps SET name = ?, package_name = ?, target_host_package = ?, description = ?, is_disabled = ?, updated_at = ? WHERE id = ?');
            $stmt->execute([trim($_POST['name'] ?? $app['name']), trim($_POST['packageName'] ?? $app['package_name']), trim($_POST['targetHostPackage'] ?? $app['target_host_package']), trim($_POST['description'] ?? $app['description']), !empty($_POST['isDisabled']) ? 1 : 0, now_time(), (int)$app['id']]);
            api_ok(['app' => get_app((int)$app['id'])], 'App 已更新');
        }

        case 'app_delete': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error('请使用 POST 请求', 405);
            $u = require_api_user();
            $app = get_app((int)($_POST['appId'] ?? 0));
            if (!can_manage_app($u, $app)) api_error('App 不存在或无权限', 404);
            $stmt = ucore_db()->prepare('DELETE FROM apps WHERE id = ?');
            $stmt->execute([(int)$app['id']]);
            api_ok([], 'App 已删除');
        }

        case 'version_list': {
            $u = require_api_user();
            $app = get_app((int)($_GET['appId'] ?? $_POST['appId'] ?? 0));
            if (!can_manage_app($u, $app)) api_error('App 不存在或无权限', 404);
            api_ok(['versions' => list_versions((int)$app['id'])]);
        }

        case 'version_create': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error('请使用 POST 请求', 405);
            $u = require_api_user();
            require_member($u);
            $app = get_app((int)($_POST['appId'] ?? 0));
            if (!can_manage_app($u, $app)) api_error('App 不存在或无权限', 404);
            $id = create_or_update_version($u, $app, $_POST, $_FILES, null);
            api_ok(['version' => get_version($id)], '版本发布成功');
        }

        case 'version_update': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error('请使用 POST 请求', 405);
            $u = require_api_user();
            require_member($u);
            $v = get_version((int)($_POST['versionId'] ?? 0));
            if (!$v) api_error('版本不存在', 404);
            $app = get_app((int)$v['app_id']);
            if (!can_manage_app($u, $app)) api_error('无权限', 403);
            $id = create_or_update_version($u, $app, $_POST, $_FILES, $v);
            api_ok(['version' => get_version($id)], '版本已更新');
        }

        case 'version_delete': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error('请使用 POST 请求', 405);
            $u = require_api_user();
            $v = get_version((int)($_POST['versionId'] ?? 0));
            if (!$v) api_error('版本不存在', 404);
            $app = get_app((int)$v['app_id']);
            if (!can_manage_app($u, $app)) api_error('无权限', 403);
            if ($v['file_name'] && preg_match('/^[A-Za-z0-9._-]+$/', $v['file_name'])) { $p = $DOWNLOAD_DIR . '/' . $v['file_name']; if (is_file($p)) @unlink($p); }
            $stmt = ucore_db()->prepare('DELETE FROM versions WHERE id = ?');
            $stmt->execute([(int)$v['id']]);
            api_ok([], '版本已删除');
        }

        case 'version_set_current': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error('请使用 POST 请求', 405);
            $u = require_api_user();
            $v = get_version((int)($_POST['versionId'] ?? 0));
            if (!$v) api_error('版本不存在', 404);
            $app = get_app((int)$v['app_id']);
            if (!can_manage_app($u, $app)) api_error('无权限', 403);
            $pdo = ucore_db();
            $stmt = $pdo->prepare('UPDATE versions SET is_current = 0 WHERE app_id = ?');
            $stmt->execute([(int)$app['id']]);
            $stmt = $pdo->prepare('UPDATE versions SET is_current = 1 WHERE id = ?');
            $stmt->execute([(int)$v['id']]);
            api_ok([], '已设为当前版本');
        }

        case 'admin_user_list': {
            require_api_admin();
            $rows = ucore_db()->query('SELECT id,username,nickname,email,avatar_url,gender,birthday,email_verified,is_admin,is_banned,membership_until,created_at,updated_at,last_login_at FROM users ORDER BY id DESC')->fetchAll();
            api_ok(['users' => $rows]);
        }

        case 'admin_user_assets': {
            require_api_admin();
            $id = (int)($_GET['userId'] ?? $_POST['userId'] ?? 0);
            $target = find_user($id);
            if (!$target) api_error('用户不存在', 404);
            api_ok(['user' => user_public($target), 'apps' => list_apps_with_versions_for_owner($id)]);
        }

        case 'admin_user_update': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error('请使用 POST 请求', 405);
            require_api_admin();
            $id = (int)($_POST['userId'] ?? 0);
            $u = find_user($id);
            if (!$u) api_error('用户不存在', 404);
            $username = trim($_POST['username'] ?? $u['username']);
            $nickname = trim($_POST['nickname'] ?? $u['nickname'] ?? $username);
            $email = strtolower(trim($_POST['email'] ?? $u['email']));
            $avatarUrl = trim($_POST['avatarUrl'] ?? $_POST['avatar_url'] ?? $u['avatar_url'] ?? '');
            if (isset($_FILES['avatar']) && (int)$_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
                $avatarUrl = save_uploaded_avatar($_FILES['avatar']);
            }
            $gender = normalize_gender($_POST['gender'] ?? $u['gender'] ?? '');
            $birthday = normalize_birthday($_POST['birthday'] ?? $u['birthday'] ?? '');
            if (!validate_username($username)) api_error('用户名只能是 3-32 位中文、字母、数字或下划线');
            if ($nickname === '') $nickname = $username;
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) api_error('邮箱格式不正确');
            if ($avatarUrl !== '' && !preg_match('/^https?:\/\//i', $avatarUrl)) api_error('头像地址必须是 http 或 https 链接');
            $dupEmail = find_user_by_email($email);
            if ($dupEmail && (int)$dupEmail['id'] !== $id) api_error('该邮箱已被其他用户使用');
            $dupName = find_user_by_username($username);
            if ($dupName && (int)$dupName['id'] !== $id) api_error('该用户名已被其他用户使用');
            $stmt = ucore_db()->prepare('UPDATE users SET username = ?, nickname = ?, email = ?, avatar_url = ?, gender = ?, birthday = ?, is_admin = ?, is_banned = ?, membership_until = ?, updated_at = ? WHERE id = ?');
            $stmt->execute([$username, $nickname, $email, $avatarUrl, $gender, $birthday, !empty($_POST['isAdmin']) ? 1 : 0, !empty($_POST['isBanned']) ? 1 : 0, trim($_POST['membershipUntil'] ?? $u['membership_until']), now_time(), $id]);
            api_ok(['user' => user_public(find_user($id))], '用户已更新');
        }

        case 'admin_user_delete': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error('请使用 POST 请求', 405);
            $admin = require_api_admin();
            $id = (int)($_POST['userId'] ?? 0);
            if ($id === (int)$admin['id']) api_error('不能删除当前登录管理员');
            $stmt = ucore_db()->prepare('DELETE FROM users WHERE id = ?');
            $stmt->execute([$id]);
            api_ok([], '用户已删除');
        }

        case 'admin_card_batch_create': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error('请使用 POST 请求', 405);
            $admin = require_api_admin();
            $result = create_cards((int)($_POST['days'] ?? 30), (int)($_POST['count'] ?? 1), (int)$admin['id'], trim($_POST['prefix'] ?? 'UR'));
            api_ok($result, '卡密已生成');
        }

        case 'admin_card_list': {
            require_api_admin();
            $rows = ucore_db()->query('SELECT c.*, u.username used_by_username, u.email used_by_email FROM cards c LEFT JOIN users u ON u.id = c.used_by ORDER BY c.days ASC, c.id DESC LIMIT 500')->fetchAll();
            api_ok(['cards' => $rows]);
        }

        case 'admin_settings_get': {
            require_api_admin();
            $s = db_get_settings();
            unset($s['smtp_pass']);
            api_ok(['settings' => $s]);
        }

        case 'admin_settings_save': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error('请使用 POST 请求', 405);
            require_api_admin();
            $keys = ['site_name','purchase_url','homepage_app_download_url','email_enabled','smtp_host','smtp_port','smtp_secure','smtp_user','smtp_from_email','smtp_from_name','user_app_version_code','user_app_version_name','user_app_download_url','user_app_update_message','user_app_force_update','user_app_announcement_enabled','user_app_announcement_title','user_app_announcement'];
            $oldAnn = db_get_setting('user_app_announcement', '');
            $oldTitle = db_get_setting('user_app_announcement_title', '公告');
            foreach ($keys as $k) {
                if (array_key_exists($k, $_POST)) db_set_setting($k, $_POST[$k]);
            }
            if (isset($_POST['smtp_pass']) && $_POST['smtp_pass'] !== '') db_set_setting('smtp_pass', $_POST['smtp_pass']);
            if (isset($_FILES['userAppApk']) && (int)$_FILES['userAppApk']['error'] !== UPLOAD_ERR_NO_FILE) {
                $upload = save_uploaded_user_app_apk($_FILES['userAppApk']);
                if ($upload && !empty($upload['downloadUrl'])) db_set_setting('user_app_download_url', $upload['downloadUrl']);
            }
            if (isset($_FILES['homepageAppApk']) && (int)$_FILES['homepageAppApk']['error'] !== UPLOAD_ERR_NO_FILE) {
                $upload = save_uploaded_user_app_apk($_FILES['homepageAppApk']);
                if ($upload && !empty($upload['downloadUrl'])) db_set_setting('homepage_app_download_url', $upload['downloadUrl']);
            }
            if (($oldAnn !== db_get_setting('user_app_announcement', '')) || ($oldTitle !== db_get_setting('user_app_announcement_title', '公告'))) {
                db_set_setting('user_app_announcement_updated_at', now_time());
            }
            api_ok(user_app_public_config(), '设置已保存');
        }

        case 'admin_user_app_save': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error('请使用 POST 请求', 405);
            require_api_admin();
            $oldAnn = db_get_setting('user_app_announcement', '');
            $oldTitle = db_get_setting('user_app_announcement_title', '公告');
            $keys = ['homepage_app_download_url','user_app_version_code','user_app_version_name','user_app_download_url','user_app_update_message','user_app_force_update','user_app_announcement_enabled','user_app_announcement_title','user_app_announcement'];
            foreach ($keys as $k) {
                if (array_key_exists($k, $_POST)) db_set_setting($k, $_POST[$k]);
            }
            if (isset($_FILES['userAppApk']) && (int)$_FILES['userAppApk']['error'] !== UPLOAD_ERR_NO_FILE) {
                $upload = save_uploaded_user_app_apk($_FILES['userAppApk']);
                if ($upload && !empty($upload['downloadUrl'])) db_set_setting('user_app_download_url', $upload['downloadUrl']);
            }
            if (isset($_FILES['homepageAppApk']) && (int)$_FILES['homepageAppApk']['error'] !== UPLOAD_ERR_NO_FILE) {
                $upload = save_uploaded_user_app_apk($_FILES['homepageAppApk']);
                if ($upload && !empty($upload['downloadUrl'])) db_set_setting('homepage_app_download_url', $upload['downloadUrl']);
            }
            if (($oldAnn !== db_get_setting('user_app_announcement', '')) || ($oldTitle !== db_get_setting('user_app_announcement_title', '公告'))) {
                db_set_setting('user_app_announcement_updated_at', now_time());
            }
            api_ok(user_app_public_config(), 'UcoreReloadsUser 软件设置已保存');
        }

        case 'admin_account_update': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error('请使用 POST 请求', 405);
            $admin = require_api_admin();
            $username = trim($_POST['username'] ?? $admin['username']);
            $email = strtolower(trim($_POST['email'] ?? $admin['email']));
            $newPass = (string)($_POST['newPassword'] ?? $_POST['password'] ?? '');
            if (!validate_username($username)) api_error('管理员用户名只能是 3-32 位中文、字母、数字或下划线');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) api_error('管理员邮箱格式不正确');
            $dupEmail = find_user_by_email($email);
            if ($dupEmail && (int)$dupEmail['id'] !== (int)$admin['id']) api_error('该邮箱已被其他用户使用');
            $dupName = find_user_by_username($username);
            if ($dupName && (int)$dupName['id'] !== (int)$admin['id']) api_error('该用户名已被其他用户使用');
            if ($newPass !== '') {
                if (strlen($newPass) < 6) api_error('新密码至少 6 位');
                $stmt = ucore_db()->prepare('UPDATE users SET username=?, email=?, password_hash=?, updated_at=? WHERE id=?');
                $stmt->execute([$username, $email, password_hash($newPass, PASSWORD_DEFAULT), now_time(), (int)$admin['id']]);
            } else {
                $stmt = ucore_db()->prepare('UPDATE users SET username=?, email=?, updated_at=? WHERE id=?');
                $stmt->execute([$username, $email, now_time(), (int)$admin['id']]);
            }
            api_ok(['user' => user_public(find_user((int)$admin['id']))], '管理员账号已更新');
        }

        case 'admin_test_email': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error('请使用 POST 请求', 405);
            $admin = require_api_admin();
            $to = strtolower(trim($_POST['email'] ?? $admin['email']));
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) api_error('测试邮箱格式不正确');
            send_test_email($to);
            api_ok(['email' => $to], '测试邮件已发送');
        }

        default:
            api_error('未知 action：' . $action, 404);
    }
} catch (Throwable $e) {
    api_error($e->getMessage(), 500);
}

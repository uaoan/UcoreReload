<?php
require_once __DIR__ . '/db.php';

if (isset($SESSION_NAME) && $SESSION_NAME !== '') session_name($SESSION_NAME);
session_start();

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    header('Expires: 0');
}

function redirect_to($url = 'index.php') { header('Location: ' . $url); exit; }
function flash($msg, $type='ok') { $_SESSION['flash'] = ['msg' => $msg, 'type' => $type]; }
function take_flash() { $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f; }
function session_user() { return !empty($_SESSION['user_id']) ? find_user((int)$_SESSION['user_id']) : null; }
function require_login_page() { $u = session_user(); if (!$u) redirect_to('index.php?page=login'); if ((int)$u['is_banned'] === 1) { session_destroy(); redirect_to('index.php'); } return $u; }

$error = '';
$defaultPasswordText = $DEFAULT_ADMIN_PASSWORD ?: '123456';

if (isset($_GET['logout'])) { $_SESSION = []; session_destroy(); redirect_to('index.php'); }

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        if ($action === 'login') {
            $login = trim($_POST['login'] ?? '');
            $password = (string)($_POST['password'] ?? '');
            $u = find_user_by_login($login);
            if (!$u || !password_verify($password, $u['password_hash'])) throw new RuntimeException('账号或密码错误。');
            if ((int)$u['is_banned'] === 1) throw new RuntimeException('账号已被封禁。');
            if ((int)$u['email_verified'] !== 1) throw new RuntimeException('邮箱未验证。');
            $_SESSION['user_id'] = (int)$u['id'];
            $stmt = ucore_db()->prepare('UPDATE users SET last_login_at = ? WHERE id = ?');
            $stmt->execute([now_time(), (int)$u['id']]);
            redirect_to('index.php?page=console');
        }
        if ($action === 'register') {
            $username = trim($_POST['username'] ?? '');
            $email = strtolower(trim($_POST['email'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $code = trim($_POST['code'] ?? '');
            if (!validate_username($username)) throw new RuntimeException('用户名只能是 3-32 位中文、字母、数字或下划线。');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('邮箱格式不正确。');
            if (find_user_by_email($email)) throw new RuntimeException('该邮箱已注册。');
            if (find_user_by_username($username)) throw new RuntimeException('用户名已存在。');
            if (strlen($password) < 6) throw new RuntimeException('密码至少 6 位。');
            if (!verify_email_code($email, $code, 'register')) throw new RuntimeException('邮箱验证码错误或已过期。');
            $stmt = ucore_db()->prepare('INSERT INTO users(username,email,password_hash,email_verified,is_admin,is_banned,nickname,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), 1, 0, 0, $username, now_time(), now_time()]);
            $_SESSION['user_id'] = (int)ucore_db()->lastInsertId();
            redirect_to('index.php?page=console');
        }

        if ($action === 'reset_password') {
            $email = strtolower(trim($_POST['email'] ?? ''));
            $code = trim($_POST['code'] ?? '');
            $new = (string)($_POST['newPassword'] ?? '');
            $confirm = (string)($_POST['confirmPassword'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('邮箱格式不正确。');
            $u = find_user_by_email($email);
            if (!$u) throw new RuntimeException('该邮箱还没有注册。');
            if (strlen($new) < 6) throw new RuntimeException('新密码至少 6 位。');
            if ($new !== $confirm) throw new RuntimeException('两次新密码不一致。');
            if (!verify_email_code($email, $code, 'reset_password')) throw new RuntimeException('邮箱验证码错误或已过期。');
            $stmt = ucore_db()->prepare('UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?');
            $stmt->execute([password_hash($new, PASSWORD_DEFAULT), now_time(), (int)$u['id']]);
            flash('密码已重置，请使用新密码登录。');
            redirect_to('index.php');
        }

        $user = require_login_page();

        if ($action === 'update_profile') {
            $username = trim($_POST['username'] ?? $user['username']);
            $nickname = trim($_POST['nickname'] ?? ($user['nickname'] ?: $username));
            $email = strtolower(trim($_POST['email'] ?? $user['email']));
            $avatarUrl = trim($_POST['avatarUrl'] ?? $_POST['avatar_url'] ?? ($user['avatar_url'] ?? ''));
            if (isset($_FILES['avatar']) && (int)$_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
                $avatarUrl = save_uploaded_avatar($_FILES['avatar']);
            }
            $gender = normalize_gender($_POST['gender'] ?? ($user['gender'] ?? ''));
            $birthday = normalize_birthday($_POST['birthday'] ?? ($user['birthday'] ?? ''));
            if (!validate_username($username)) throw new RuntimeException('用户名只能是 3-32 位中文、字母、数字或下划线。');
            if ($nickname === '') $nickname = $username;
            if ((function_exists('mb_strlen') ? mb_strlen($nickname, 'UTF-8') : strlen($nickname)) > 32) throw new RuntimeException('昵称最多 32 个字符。');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('邮箱格式不正确。');
            if ($avatarUrl !== '' && !preg_match('/^https?:\/\//i', $avatarUrl)) throw new RuntimeException('头像地址必须是 http 或 https 链接。');
            $dupEmail = find_user_by_email($email); if ($dupEmail && (int)$dupEmail['id'] !== (int)$user['id']) throw new RuntimeException('该邮箱已被其他用户使用。');
            $dupName = find_user_by_username($username); if ($dupName && (int)$dupName['id'] !== (int)$user['id']) throw new RuntimeException('该用户名已被其他用户使用。');
            $stmt = ucore_db()->prepare('UPDATE users SET username=?, nickname=?, email=?, avatar_url=?, gender=?, birthday=?, updated_at=? WHERE id=?');
            $stmt->execute([$username, $nickname, $email, $avatarUrl, $gender, $birthday, now_time(), (int)$user['id']]);
            flash('个人资料已保存。'); redirect_to('index.php#sub-account');
        }

        if ($action === 'change_password') {
            $old = (string)($_POST['oldPassword'] ?? '');
            $new = (string)($_POST['newPassword'] ?? '');
            $confirm = (string)($_POST['confirmPassword'] ?? '');
            if (!password_verify($old, $user['password_hash'])) throw new RuntimeException('当前密码错误。');
            if (strlen($new) < 6) throw new RuntimeException('新密码至少 6 位。');
            if ($new !== $confirm) throw new RuntimeException('两次新密码不一致。');
            $stmt = ucore_db()->prepare('UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?');
            $stmt->execute([password_hash($new, PASSWORD_DEFAULT), now_time(), (int)$user['id']]);
            flash('密码已修改。'); redirect_to('index.php');
        }

        if ($action === 'redeem_card') {
            $until = redeem_card((int)$user['id'], trim($_POST['code'] ?? ''));
            flash('兑换成功，会员有效期到：' . $until); redirect_to('index.php');
        }

        if ($action === 'create_app') {
            $name = trim($_POST['name'] ?? '');
            if ($name === '') throw new RuntimeException('App 名称不能为空。');
            $id = create_app_for_user((int)$user['id'], $name, trim($_POST['packageName'] ?? ''), trim($_POST['targetHostPackage'] ?? ''), trim($_POST['description'] ?? ''));
            flash('App 创建成功。'); redirect_to('index.php?app_id=' . $id . '#tab-release');
        }

        if ($action === 'update_app') {
            $app = get_app((int)($_POST['appId'] ?? 0));
            if (!can_manage_app($user, $app)) throw new RuntimeException('App 不存在或无权限。');
            $stmt = ucore_db()->prepare('UPDATE apps SET name=?, package_name=?, target_host_package=?, description=?, is_disabled=?, updated_at=? WHERE id=?');
            $stmt->execute([trim($_POST['name'] ?? $app['name']), trim($_POST['packageName'] ?? ''), trim($_POST['targetHostPackage'] ?? ''), trim($_POST['description'] ?? ''), !empty($_POST['isDisabled']) ? 1 : 0, now_time(), (int)$app['id']]);
            flash('App 已保存。'); redirect_to('index.php?app_id=' . (int)$app['id'] . '#tab-release');
        }

        if ($action === 'delete_app') {
            $app = get_app((int)($_POST['appId'] ?? 0));
            if (!can_manage_app($user, $app)) throw new RuntimeException('App 不存在或无权限。');
            $stmt = ucore_db()->prepare('DELETE FROM apps WHERE id = ?');
            $stmt->execute([(int)$app['id']]);
            flash('App 已删除。'); redirect_to('index.php');
        }

        if ($action === 'save_version') {
            require_member($user);
            $app = get_app((int)($_POST['appId'] ?? 0));
            if (!can_manage_app($user, $app)) throw new RuntimeException('App 不存在或无权限。');
            $existing = null;
            if (!empty($_POST['versionId'])) {
                $existing = get_version((int)$_POST['versionId']);
                if (!$existing || (int)$existing['app_id'] !== (int)$app['id']) throw new RuntimeException('版本不存在。');
            }
            $id = create_or_update_version($user, $app, $_POST, $_FILES, $existing);
            $message = $existing ? '版本已更新。' : '新版本已发布。';
            if (is_ajax()) json_response(['ok' => true, 'message' => $message, 'versionId' => $id]);
            flash($message); redirect_to('index.php?app_id=' . (int)$app['id'] . '#tab-release');
        }

        if ($action === 'set_current') {
            $v = get_version((int)($_POST['versionId'] ?? 0));
            if (!$v) throw new RuntimeException('版本不存在。');
            $app = get_app((int)$v['app_id']);
            if (!can_manage_app($user, $app)) throw new RuntimeException('无权限。');
            $pdo = ucore_db();
            $stmt = $pdo->prepare('UPDATE versions SET is_current = 0 WHERE app_id = ?');
            $stmt->execute([(int)$app['id']]);
            $stmt = $pdo->prepare('UPDATE versions SET is_current = 1 WHERE id = ?');
            $stmt->execute([(int)$v['id']]);
            flash('已设为当前版本。'); redirect_to('index.php?app_id=' . (int)$app['id'] . '#tab-release');
        }

        if ($action === 'delete_version') {
            $v = get_version((int)($_POST['versionId'] ?? 0));
            if (!$v) throw new RuntimeException('版本不存在。');
            $app = get_app((int)$v['app_id']);
            if (!can_manage_app($user, $app)) throw new RuntimeException('无权限。');
            if ($v['file_name'] && preg_match('/^[A-Za-z0-9._-]+$/', $v['file_name'])) { global $DOWNLOAD_DIR; $p = $DOWNLOAD_DIR . '/' . $v['file_name']; if (is_file($p)) @unlink($p); }
            $stmt = ucore_db()->prepare('DELETE FROM versions WHERE id=?'); $stmt->execute([(int)$v['id']]);
            flash('版本已删除。'); redirect_to('index.php?app_id=' . (int)$app['id'] . '#tab-release');
        }

        if ($action === 'admin_save_user') {
            if ((int)$user['is_admin'] !== 1) throw new RuntimeException('需要管理员权限。');
            $id = (int)($_POST['userId'] ?? 0); $u = find_user($id); if (!$u) throw new RuntimeException('用户不存在。');
            $username = trim($_POST['username'] ?? $u['username']);
            $nickname = trim($_POST['nickname'] ?? ($u['nickname'] ?: $username));
            $email = strtolower(trim($_POST['email'] ?? $u['email']));
            $avatarUrl = trim($_POST['avatarUrl'] ?? $_POST['avatar_url'] ?? ($u['avatar_url'] ?? ''));
            if (isset($_FILES['avatar']) && (int)$_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
                $avatarUrl = save_uploaded_avatar($_FILES['avatar']);
            }
            $gender = normalize_gender($_POST['gender'] ?? ($u['gender'] ?? ''));
            $birthday = normalize_birthday($_POST['birthday'] ?? ($u['birthday'] ?? ''));
            if (!validate_username($username)) throw new RuntimeException('用户名只能是 3-32 位中文、字母、数字或下划线。');
            if ($nickname === '') $nickname = $username;
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('邮箱格式不正确。');
            $stmt = ucore_db()->prepare('UPDATE users SET username=?, nickname=?, email=?, avatar_url=?, gender=?, birthday=?, is_admin=?, is_banned=?, membership_until=?, updated_at=? WHERE id=?');
            $stmt->execute([$username, $nickname, $email, $avatarUrl, $gender, $birthday, !empty($_POST['isAdmin']) ? 1 : 0, !empty($_POST['isBanned']) ? 1 : 0, trim($_POST['membershipUntil'] ?? ''), now_time(), $id]);
            flash('用户已保存。'); redirect_to('index.php#tab-users');
        }

        if ($action === 'admin_delete_user') {
            if ((int)$user['is_admin'] !== 1) throw new RuntimeException('需要管理员权限。');
            $id = (int)($_POST['userId'] ?? 0); if ($id === (int)$user['id']) throw new RuntimeException('不能删除当前登录管理员。');
            $stmt = ucore_db()->prepare('DELETE FROM users WHERE id=?'); $stmt->execute([$id]);
            flash('用户已删除。'); redirect_to('index.php#tab-users');
        }

        if ($action === 'admin_create_cards') {
            if ((int)$user['is_admin'] !== 1) throw new RuntimeException('需要管理员权限。');
            $result = create_cards((int)($_POST['days'] ?? 30), (int)($_POST['count'] ?? 1), (int)$user['id'], trim($_POST['prefix'] ?? 'UR'));
            $_SESSION['last_cards'] = $result['codes'];
            $_SESSION['last_cards_days'] = (int)($_POST['days'] ?? 30);
            flash('已生成 ' . count($result['codes']) . ' 张 ' . (int)($_POST['days'] ?? 30) . ' 天卡密。'); redirect_to('index.php#tab-cards');
        }

        if ($action === 'admin_save_settings') {
            if ((int)$user['is_admin'] !== 1) throw new RuntimeException('需要管理员权限。');
            $oldAnn = db_get_setting('user_app_announcement', '');
            $oldTitle = db_get_setting('user_app_announcement_title', '公告');
            $keys = ['site_name','purchase_url','homepage_app_download_url','email_enabled','smtp_host','smtp_port','smtp_secure','smtp_user','smtp_from_email','smtp_from_name','user_app_version_code','user_app_version_name','user_app_download_url','user_app_update_message','user_app_force_update','user_app_announcement_enabled','user_app_announcement_title','user_app_announcement'];
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
            flash('系统设置已保存。'); redirect_to('index.php#tab-settings');
        }

        if ($action === 'admin_update_account') {
            if ((int)$user['is_admin'] !== 1) throw new RuntimeException('需要管理员权限。');
            $username = trim($_POST['adminUsername'] ?? $user['username']);
            $email = strtolower(trim($_POST['adminEmail'] ?? $user['email']));
            $newPass = (string)($_POST['adminNewPassword'] ?? '');
            $confirm = (string)($_POST['adminConfirmPassword'] ?? '');
            if (!validate_username($username)) throw new RuntimeException('管理员用户名只能是 3-32 位中文、字母、数字或下划线。');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('管理员邮箱格式不正确。');
            $dupEmail = find_user_by_email($email);
            if ($dupEmail && (int)$dupEmail['id'] !== (int)$user['id']) throw new RuntimeException('该邮箱已被其他用户使用。');
            $dupName = find_user_by_username($username);
            if ($dupName && (int)$dupName['id'] !== (int)$user['id']) throw new RuntimeException('该用户名已被其他用户使用。');
            if ($newPass !== '') {
                if (strlen($newPass) < 6) throw new RuntimeException('新密码至少 6 位。');
                if ($newPass !== $confirm) throw new RuntimeException('两次新密码不一致。');
                $stmt = ucore_db()->prepare('UPDATE users SET username=?, email=?, password_hash=?, updated_at=? WHERE id=?');
                $stmt->execute([$username, $email, password_hash($newPass, PASSWORD_DEFAULT), now_time(), (int)$user['id']]);
            } else {
                $stmt = ucore_db()->prepare('UPDATE users SET username=?, email=?, updated_at=? WHERE id=?');
                $stmt->execute([$username, $email, now_time(), (int)$user['id']]);
            }
            flash('默认管理员账号已修改。'); redirect_to('index.php#tab-settings');
        }

        if ($action === 'admin_test_email') {
            if ((int)$user['is_admin'] !== 1) throw new RuntimeException('需要管理员权限。');
            $to = strtolower(trim($_POST['testEmail'] ?? $user['email']));
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('测试邮箱格式不正确。');
            send_test_email($to);
            flash('测试邮件已发送到：' . $to); redirect_to('index.php#tab-settings');
        }
    }
} catch (Throwable $e) {
    if (is_ajax()) json_response(['ok'=>false,'message'=>$e->getMessage()], 400);
    $error = $e->getMessage();
}

$user = session_user();
$flash = take_flash();
$siteName = db_get_setting('site_name', 'UcoreReload');
$purchaseUrl = db_get_setting('purchase_url', '');
$userAppDownloadUrl = db_get_setting('user_app_download_url', '');
$homepageAppDownloadUrl = db_get_setting('homepage_app_download_url', '') ?: $userAppDownloadUrl;
$primaryAdmin = ucore_db()->query('SELECT username,email FROM users WHERE is_admin = 1 ORDER BY id ASC LIMIT 1')->fetch() ?: ['username' => $DEFAULT_ADMIN_USERNAME, 'email' => $DEFAULT_ADMIN_EMAIL];

$page = trim((string)($_GET['page'] ?? ''));
$showConsole = ($page === 'console') || isset($_GET['app_id']);
$showLogin = ($page === 'login');

if ((!$user && !$showLogin) || ($user && !$showConsole)) {
?><!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= h($siteName) ?> - Android 热更新云平台</title>
<meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate, max-age=0">
<meta http-equiv="Pragma" content="no-cache">
<meta http-equiv="Expires" content="0">
<link rel="stylesheet" href="assets/style.css?v=<?= time() ?>">
<style>
.landing-body{min-height:100svh;overflow-x:hidden}.landing-nav{width:min(1180px,calc(100% - 28px));margin:16px auto 0;padding:14px 18px;display:flex;align-items:center;justify-content:space-between;gap:16px;position:sticky;top:10px;z-index:10}.landing-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.landing-shell{width:min(1180px,calc(100% - 28px));margin:18px auto 56px}.landing-hero{padding:clamp(26px,5vw,56px);display:grid;grid-template-columns:minmax(0,1.1fr) minmax(300px,.9fr);gap:26px;align-items:center}.landing-title{font-size:clamp(42px,8vw,82px);line-height:.96;letter-spacing:-.07em;margin:0}.landing-lead{font-size:clamp(16px,2vw,21px);line-height:1.75;color:rgba(24,32,44,.68);margin:20px 0 0}.landing-badges{display:flex;gap:10px;flex-wrap:wrap;margin:24px 0}.landing-demo{padding:24px;border-radius:32px;background:linear-gradient(145deg,rgba(255,255,255,.68),rgba(255,255,255,.32));border:1px solid rgba(255,255,255,.72);box-shadow:inset 0 1px 0 rgba(255,255,255,.8)}.phone-card{border-radius:30px;padding:24px;background:linear-gradient(160deg,#101828,#263550);color:#fff;box-shadow:0 24px 60px rgba(16,24,40,.22)}.phone-card .screen{margin-top:18px;border-radius:24px;background:rgba(255,255,255,.10);padding:18px}.code-line{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:13px;color:rgba(255,255,255,.78);line-height:1.7;word-break:break-all}.landing-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin-top:16px}.feature-card{padding:24px}.feature-card h3{margin:10px 0 8px}.step-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.step{padding:20px}.step-num{width:38px;height:38px;border-radius:14px;display:grid;place-items:center;color:#fff;background:linear-gradient(135deg,var(--blue),var(--purple));font-weight:950}.landing-section{margin-top:18px;padding:clamp(22px,4vw,34px)}.use-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin-top:16px}.use-item{padding:16px;border-radius:20px;background:rgba(255,255,255,.46);border:1px solid rgba(255,255,255,.64)}.landing-cta{margin-top:18px;padding:28px;display:flex;align-items:center;justify-content:space-between;gap:18px}.btn-big{padding:14px 22px!important;font-size:16px}.landing-nav .primary,.landing-nav .secondary{font-size:15px}.landing-nav .primary{padding:13px 22px}.landing-nav .secondary{padding:12px 20px}@media(max-width:900px){.landing-hero,.landing-grid,.step-grid,.use-list{grid-template-columns:1fr}.landing-nav{align-items:flex-start;flex-direction:column}.landing-actions{width:100%}.landing-actions a{flex:1}.landing-cta{flex-direction:column;align-items:flex-start}.landing-cta .landing-actions{width:100%}.landing-title{font-size:44px}}
</style>
</head>
<body class="ios-glass-bg landing-body">
<div class="orb orb-a"></div><div class="orb orb-b"></div><div class="orb orb-c"></div>
<header class="landing-nav glass-card">
  <div class="brand-row compact"><div class="app-mark small">UR</div><div><strong><?= h($siteName) ?></strong><span>Android 无安装热更新云平台</span></div></div>
  <nav class="landing-actions">
    <?php if($user): ?>
      <a class="secondary" href="index.php?logout=1">退出登录</a>
      <a class="primary btn-big" href="index.php?page=console">进入控制台</a>
    <?php else: ?>
      <a class="secondary btn-big" href="index.php?page=login">登录 / 注册</a>
      <a class="primary btn-big" href="index.php?page=login">进入控制台</a>
    <?php endif; ?>
  </nav>
</header>
<main class="landing-shell">
  <section class="landing-hero glass-card">
    <div>
      <p class="eyebrow">UcoreReload</p>
      <h1 class="landing-title">整包 APK 级热更新，不安装也能加载代码与资源。</h1>
      <p class="landing-lead">UcoreReload 是一套 Android 热更新 Library + PHP 后台系统。客户端只需要填写 AppID，就会通过固定域名 <code>http://ucore.uaoan.cn</code> 自动检查版本、下载补丁 APK、加载 classes.dex、resources.arsc、layout、drawable、mipmap，并支持新增 Activity 代理跳转。</p>
      <div class="landing-badges"><span class="pill green">Java/Kotlin 可调用</span><span class="pill">iApp 兼容调试</span><span class="pill">SQLite 后台</span><span class="pill">会员/卡密系统</span></div>
      <div class="landing-actions"><a class="primary btn-big" href="<?= $user ? 'index.php?page=console' : 'index.php?page=login' ?>">立即进入控制台</a><a class="secondary btn-big" href="docs.php">查看 API 文档</a><?php if($homepageAppDownloadUrl): ?><a class="primary btn-big" href="<?= h($homepageAppDownloadUrl) ?>" target="_blank" rel="noopener">下载APP</a><?php endif; ?></div>
    </div>
    <div class="landing-demo">
      <div class="phone-card">
        <p class="eyebrow" style="color:rgba(255,255,255,.6)">Client Usage</p>
        <h2>只填 AppID</h2>
        <div class="screen">
          <div class="code-line">public static final String UCORE_APP_ID = "UAxxxxxxxxxx";</div>
          <div class="code-line">UcoreReload.installInApplication(this, UCORE_APP_ID);</div>
          <div class="code-line">// 自动检查 → 下载 → 合并 dex → 加载资源 → 重启生效</div>
        </div>
      </div>
    </div>
  </section>

  <section class="landing-grid">
    <article class="feature-card glass-card"><span class="step-num">1</span><h3>完整 APK 作为补丁</h3><p class="muted">后台上传完整 APK，不走系统安装器，客户端把它当补丁包加载代码和资源。</p></article>
    <article class="feature-card glass-card"><span class="step-num">2</span><h3>代码与资源同时更新</h3><p class="muted">支持 dex 合并、Resources/Assets 代理、layout/drawable/mipmap/values 更新。</p></article>
    <article class="feature-card glass-card"><span class="step-num">3</span><h3>新增 Activity 代理</h3><p class="muted">未注册 Activity 通过 Stub/宿主代理启动，适配原生 Android 和 iApp 场景。</p></article>
  </section>

  <section class="landing-section glass-card">
    <p class="eyebrow">How To Use</p><h2>使用方法</h2>
    <div class="step-grid">
      <div class="step"><div class="step-num">01</div><h3>注册/登录</h3><p class="muted">使用邮箱验证码注册账号，登录后进入用户空间。</p></div>
      <div class="step"><div class="step-num">02</div><h3>创建 App</h3><p class="muted">创建应用后复制后台生成的 AppID。</p></div>
      <div class="step"><div class="step-num">03</div><h3>集成 Library</h3><p class="muted">在 Application 里填写 AppID 并调用自动安装方法。</p></div>
      <div class="step"><div class="step-num">04</div><h3>发布热更新</h3><p class="muted">上传新 APK，patchCode 递增，客户端自动下载并加载。</p></div>
    </div>
  </section>

  <section class="landing-section glass-card">
    <p class="eyebrow">Backend</p><h2>后台能力</h2>
    <div class="use-list">
      <div class="use-item"><strong>用户系统</strong><p class="muted">邮箱验证码注册、登录、找回密码、用户空间。</p></div>
      <div class="use-item"><strong>App 管理</strong><p class="muted">创建 App、AppID、按 App 发布和管理热更新版本。</p></div>
      <div class="use-item"><strong>会员卡密</strong><p class="muted">1/30/60/90/365 天卡密、兑换会员、购买地址。</p></div>
      <div class="use-item"><strong>管理员控制台</strong><p class="muted">用户管理、封号、设为管理、SMTP 邮箱配置、API 文档。</p></div>
    </div>
  </section>

  <section class="landing-cta glass-card">
    <div><p class="eyebrow">Start</p><h2>开始管理你的热更新版本</h2><p class="muted">右上角可以随时进入登录页或控制台。</p></div>
    <div class="landing-actions"><a class="secondary btn-big" href="index.php?page=login">登录 / 注册</a><a class="primary btn-big" href="index.php?page=console">控制台</a><?php if($homepageAppDownloadUrl): ?><a class="secondary btn-big" href="<?= h($homepageAppDownloadUrl) ?>" target="_blank" rel="noopener">下载APP</a><?php endif; ?></div>
  </section>
</main>
</body></html><?php exit; }

if (!$user) {
?><!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= h($siteName) ?> 登录注册</title>
    <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate, max-age=0">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <link rel="stylesheet" href="assets/style.css?v=<?= time() ?>">

<style id="ucore-modal-critical-fix">
/* 强制修复弹窗：固定全屏、内部滚动、底部保存按钮始终可见 */
.modal-backdrop{
  position:fixed!important;
  left:0!important;right:0!important;top:0!important;bottom:0!important;
  width:100vw!important;height:100dvh!important;
  z-index:99999!important;
  display:none!important;
  align-items:center!important;
  justify-content:center!important;
  padding:20px!important;
  margin:0!important;
  background:rgba(18,24,38,.42)!important;
  overflow:hidden!important;
  backdrop-filter:blur(18px) saturate(160%)!important;
  -webkit-backdrop-filter:blur(18px) saturate(160%)!important;
}
.modal-backdrop.open{display:flex!important;}
.modal-card{
  position:relative!important;
  width:min(980px,calc(100vw - 32px))!important;
  height:min(760px,calc(100dvh - 44px))!important;
  max-width:calc(100vw - 32px)!important;
  max-height:calc(100dvh - 44px)!important;
  margin:0!important;
  padding:22px!important;
  display:flex!important;
  flex-direction:column!important;
  overflow:hidden!important;
  border-radius:30px!important;
  background:linear-gradient(145deg,rgba(255,255,255,.92),rgba(255,255,255,.66))!important;
}
.modal-head{
  flex:0 0 auto!important;
  display:flex!important;
  align-items:flex-start!important;
  justify-content:space-between!important;
  gap:16px!important;
  margin:0 0 14px 0!important;
}
.modal-card>form{
  flex:1 1 auto!important;
  min-height:0!important;
  overflow-y:auto!important;
  overflow-x:hidden!important;
  padding:0 10px 110px 0!important;
  margin:0!important;
}
.modal-card>form.grid.two{
  display:grid!important;
  grid-template-columns:repeat(2,minmax(0,1fr))!important;
  gap:14px!important;
  align-content:start!important;
}
.modal-card>form.grid.two>.full-row:last-child{
  position:absolute!important;
  left:22px!important;
  right:22px!important;
  bottom:18px!important;
  z-index:20!important;
  margin:0!important;
  padding:12px!important;
  border-radius:22px!important;
  background:linear-gradient(180deg,rgba(255,255,255,.72),rgba(255,255,255,.96))!important;
  border:1px solid rgba(255,255,255,.72)!important;
  box-shadow:0 -14px 34px rgba(30,38,60,.08), inset 0 1px 0 rgba(255,255,255,.9)!important;
  backdrop-filter:blur(18px) saturate(160%)!important;
  -webkit-backdrop-filter:blur(18px) saturate(160%)!important;
  display:flex!important;
  justify-content:flex-end!important;
}
.modal-card>form.grid.two>.full-row:last-child .primary{
  min-width:168px!important;
  min-height:44px!important;
}
.modal-table{
  flex:1 1 auto!important;
  min-height:0!important;
  overflow:auto!important;
}
body.modal-open{overflow:hidden!important;}
@media(max-width:760px){
  .modal-backdrop{padding:10px!important;align-items:flex-end!important;}
  .modal-card{width:calc(100vw - 20px)!important;height:calc(100dvh - 20px)!important;max-width:calc(100vw - 20px)!important;max-height:calc(100dvh - 20px)!important;border-radius:24px!important;padding:16px!important;}
  .modal-card>form.grid.two{grid-template-columns:1fr!important;}
  .modal-card>form.grid.two>.full-row:last-child{left:16px!important;right:16px!important;bottom:14px!important;}
  .modal-card>form.grid.two>.full-row:last-child .primary{width:100%!important;}
}
</style>


<style id="ucore-modal-final-fix">
/* final modal fix: full-screen overlay + internal scroll + fixed footer */
html.modal-lock, body.modal-open { overflow: hidden !important; }
.modal-backdrop{
  position: fixed !important;
  inset: 0 !important;
  z-index: 999999 !important;
  display: none !important;
  align-items: center !important;
  justify-content: center !important;
  padding: 20px !important;
  background: rgba(18, 24, 38, .48) !important;
  backdrop-filter: blur(20px) saturate(160%) !important;
  -webkit-backdrop-filter: blur(20px) saturate(160%) !important;
  overflow: hidden !important;
}
.modal-backdrop.open{ display: flex !important; }
.modal-card{
  width: min(960px, calc(100vw - 40px)) !important;
  height: min(760px, calc(100dvh - 40px)) !important;
  max-width: calc(100vw - 40px) !important;
  max-height: calc(100dvh - 40px) !important;
  margin: 0 !important;
  padding: 0 !important;
  border-radius: 30px !important;
  display: flex !important;
  flex-direction: column !important;
  overflow: hidden !important;
  transform: none !important;
  background: linear-gradient(145deg, rgba(255,255,255,.94), rgba(255,255,255,.72)) !important;
}
.modal-head{
  flex: 0 0 auto !important;
  display:flex !important;
  align-items:flex-start !important;
  justify-content:space-between !important;
  gap:16px !important;
  padding: 22px 24px 14px !important;
  margin:0 !important;
  border-bottom: 1px solid rgba(70,82,110,.10) !important;
}
.modal-form{
  flex: 1 1 auto !important;
  min-height: 0 !important;
  display: flex !important;
  flex-direction: column !important;
  overflow: hidden !important;
}
.modal-body{
  flex: 1 1 auto !important;
  min-height: 0 !important;
  overflow: auto !important;
  padding: 18px 24px 22px !important;
  -webkit-overflow-scrolling: touch !important;
}
.modal-footer{
  flex: 0 0 auto !important;
  display:flex !important;
  justify-content:flex-end !important;
  gap:10px !important;
  padding: 14px 24px 18px !important;
  background: rgba(255,255,255,.88) !important;
  border-top: 1px solid rgba(70,82,110,.12) !important;
  backdrop-filter: blur(18px) saturate(170%) !important;
  -webkit-backdrop-filter: blur(18px) saturate(170%) !important;
}
.modal-footer .primary,.modal-footer .secondary{ min-width: 132px !important; }
.modal-table{
  flex: 1 1 auto !important;
  min-height:0 !important;
  overflow:auto !important;
  margin: 0 22px 22px !important;
}
@media(max-width:760px){
  .modal-backdrop{ align-items:flex-end !important; padding: 10px !important; }
  .modal-card{ width:100% !important; height: min(88dvh, 760px) !important; max-width:100% !important; max-height:88dvh !important; border-radius: 26px 26px 18px 18px !important; }
  .modal-head{ padding:18px 18px 12px !important; }
  .modal-body{ padding:14px 18px 18px !important; grid-template-columns:1fr !important; }
  .modal-footer{ padding:12px 18px 16px !important; flex-direction:column-reverse !important; }
  .modal-footer .primary,.modal-footer .secondary{ width:100% !important; }
}
</style>
</head>
<body class="ios-glass-bg auth-page">
<div class="orb orb-a"></div><div class="orb orb-b"></div><div class="orb orb-c"></div>
<main class="auth-shell">
    <section class="glass-card auth-card">
        <div class="brand-row"><div class="app-mark">UR</div><div><p class="eyebrow">UcoreReload Cloud</p><h1><?= h($siteName) ?></h1></div></div>
        <p class="muted">用户可注册、创建 App、发布热更新；管理员可管理用户、会员卡密、邮箱和全站设置。</p>
        <p class="muted small">默认管理员：<code><?= h($primaryAdmin['username'] ?? $DEFAULT_ADMIN_USERNAME) ?></code>，默认密码：<code><?= h($defaultPasswordText) ?></code></p>
        <?php if ($error): ?><div class="alert error"><?= h($error) ?></div><?php endif; ?>
        <div class="tabs auth-tabs"><button type="button" class="tab active" data-tab="login">登录</button><button type="button" class="tab" data-tab="register">注册</button><button type="button" class="tab" data-tab="reset">找回密码</button></div>
        <form method="post" class="tab-panel active" id="login">
            <input type="hidden" name="action" value="login">
            <label>用户名或邮箱</label><input name="login" required autocomplete="username">
            <label>密码</label><input type="password" name="password" required autocomplete="current-password">
            <button class="primary full">登录</button>
            <button class="link-button" type="button" data-switch-tab="reset">忘记密码？用邮箱验证码找回</button>
        </form>
        <form method="post" class="tab-panel" id="register">
            <input type="hidden" name="action" value="register">
            <label>用户名</label><input name="username" required placeholder="3-32 位">
            <label>邮箱</label><div class="inline-field"><input name="email" id="regEmail" required type="email"><button type="button" data-send-code="register" data-email-input="regEmail" class="secondary">发送验证码</button></div>
            <label>邮箱验证码</label><input name="code" required placeholder="6 位验证码">
            <label>密码</label><input type="password" name="password" required minlength="6">
            <button class="primary full">注册并进入用户空间</button>
            <p class="help">注册获取验证码时会先判断邮箱是否已注册；已注册邮箱会直接提示。</p>
        </form>
        <form method="post" class="tab-panel" id="reset">
            <input type="hidden" name="action" value="reset_password">
            <label>注册邮箱</label><div class="inline-field"><input name="email" id="resetEmail" required type="email"><button type="button" data-send-code="reset_password" data-email-input="resetEmail" class="secondary">发送验证码</button></div>
            <label>邮箱验证码</label><input name="code" required placeholder="6 位验证码">
            <label>新密码</label><input type="password" name="newPassword" required minlength="6">
            <label>确认新密码</label><input type="password" name="confirmPassword" required minlength="6">
            <button class="primary full">重置密码</button>
        </form>
    </section>
</main>
<script>
function showAuthTab(id){document.querySelectorAll('.tab,.tab-panel').forEach(x=>x.classList.remove('active'));document.querySelector('[data-tab="'+id+'"]')?.classList.add('active');document.getElementById(id)?.classList.add('active')}
document.querySelectorAll('.tab').forEach(btn=>btn.onclick=()=>showAuthTab(btn.dataset.tab));
document.querySelectorAll('[data-switch-tab]').forEach(btn=>btn.onclick=()=>showAuthTab(btn.dataset.switchTab));
document.querySelectorAll('[data-send-code]').forEach(btn=>btn.onclick = async function(){
  const email = document.getElementById(this.dataset.emailInput).value.trim(); if(!email){alert('请先填写邮箱');return}
  this.disabled=true; const old=this.textContent; this.textContent='发送中...';
  const fd=new FormData(); fd.append('action','send_email_code'); fd.append('email',email); fd.append('purpose',this.dataset.sendCode);
  try{const r=await fetch('api.php',{method:'POST',body:fd,cache:'no-store'}); const j=await r.json(); alert(j.message||'已发送'); if(!j.ok){this.disabled=false;this.textContent=old;return}}catch(e){alert('发送失败：'+e.message); this.disabled=false; this.textContent=old; return}
  let n=60; const t=setInterval(()=>{this.textContent=n+'s'; if(--n<=0){clearInterval(t);this.disabled=false;this.textContent=old}},1000);
});
</script>
</body></html><?php exit; }

$apps = list_apps_for_user($user);
$appId = (int)($_GET['app_id'] ?? 0);
$currentApp = $appId ? get_app($appId) : null;
if ($currentApp && !can_manage_app($user, $currentApp)) $currentApp = null;
$versions = $currentApp ? list_versions((int)$currentApp['id']) : [];
$adminUsers = [];$cards = [];$settings = [];$lastCards = $_SESSION['last_cards'] ?? []; unset($_SESSION['last_cards']);
if ((int)$user['is_admin'] === 1) {
    $adminUsers = ucore_db()->query('SELECT * FROM users ORDER BY id DESC')->fetchAll();
    $cards = ucore_db()->query('SELECT c.*, u.username used_by_username, u.email used_by_email FROM cards c LEFT JOIN users u ON u.id = c.used_by ORDER BY c.days ASC, c.id DESC')->fetchAll();
    $settings = db_get_settings();
}
?><?php
$lastCardsDays = $_SESSION['last_cards_days'] ?? null; unset($_SESSION['last_cards_days']);
$cardGroups = [];
foreach ($cards as $c) {
    $d = (int)($c['days'] ?? 0);
    if (!isset($cardGroups[$d])) $cardGroups[$d] = [];
    $cardGroups[$d][] = $c;
}
ksort($cardGroups);
$defaultTab = $currentApp ? 'tab-release' : 'tab-apps';
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= h($siteName) ?> 用户空间</title><meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate, max-age=0"><meta http-equiv="Pragma" content="no-cache"><meta http-equiv="Expires" content="0"><link rel="stylesheet" href="assets/style.css?v=<?= time() ?>">

<style id="ucore-modal-critical-fix">
/* 强制修复弹窗：固定全屏、内部滚动、底部保存按钮始终可见 */
.modal-backdrop{
  position:fixed!important;
  left:0!important;right:0!important;top:0!important;bottom:0!important;
  width:100vw!important;height:100dvh!important;
  z-index:99999!important;
  display:none!important;
  align-items:center!important;
  justify-content:center!important;
  padding:20px!important;
  margin:0!important;
  background:rgba(18,24,38,.42)!important;
  overflow:hidden!important;
  backdrop-filter:blur(18px) saturate(160%)!important;
  -webkit-backdrop-filter:blur(18px) saturate(160%)!important;
}
.modal-backdrop.open{display:flex!important;}
.modal-card{
  position:relative!important;
  width:min(980px,calc(100vw - 32px))!important;
  height:min(760px,calc(100dvh - 44px))!important;
  max-width:calc(100vw - 32px)!important;
  max-height:calc(100dvh - 44px)!important;
  margin:0!important;
  padding:22px!important;
  display:flex!important;
  flex-direction:column!important;
  overflow:hidden!important;
  border-radius:30px!important;
  background:linear-gradient(145deg,rgba(255,255,255,.92),rgba(255,255,255,.66))!important;
}
.modal-head{
  flex:0 0 auto!important;
  display:flex!important;
  align-items:flex-start!important;
  justify-content:space-between!important;
  gap:16px!important;
  margin:0 0 14px 0!important;
}
.modal-card>form{
  flex:1 1 auto!important;
  min-height:0!important;
  overflow-y:auto!important;
  overflow-x:hidden!important;
  padding:0 10px 110px 0!important;
  margin:0!important;
}
.modal-card>form.grid.two{
  display:grid!important;
  grid-template-columns:repeat(2,minmax(0,1fr))!important;
  gap:14px!important;
  align-content:start!important;
}
.modal-card>form.grid.two>.full-row:last-child{
  position:absolute!important;
  left:22px!important;
  right:22px!important;
  bottom:18px!important;
  z-index:20!important;
  margin:0!important;
  padding:12px!important;
  border-radius:22px!important;
  background:linear-gradient(180deg,rgba(255,255,255,.72),rgba(255,255,255,.96))!important;
  border:1px solid rgba(255,255,255,.72)!important;
  box-shadow:0 -14px 34px rgba(30,38,60,.08), inset 0 1px 0 rgba(255,255,255,.9)!important;
  backdrop-filter:blur(18px) saturate(160%)!important;
  -webkit-backdrop-filter:blur(18px) saturate(160%)!important;
  display:flex!important;
  justify-content:flex-end!important;
}
.modal-card>form.grid.two>.full-row:last-child .primary{
  min-width:168px!important;
  min-height:44px!important;
}
.modal-table{
  flex:1 1 auto!important;
  min-height:0!important;
  overflow:auto!important;
}
body.modal-open{overflow:hidden!important;}
@media(max-width:760px){
  .modal-backdrop{padding:10px!important;align-items:flex-end!important;}
  .modal-card{width:calc(100vw - 20px)!important;height:calc(100dvh - 20px)!important;max-width:calc(100vw - 20px)!important;max-height:calc(100dvh - 20px)!important;border-radius:24px!important;padding:16px!important;}
  .modal-card>form.grid.two{grid-template-columns:1fr!important;}
  .modal-card>form.grid.two>.full-row:last-child{left:16px!important;right:16px!important;bottom:14px!important;}
  .modal-card>form.grid.two>.full-row:last-child .primary{width:100%!important;}
}
</style>


<style id="ucore-modal-final-fix">
/* final modal fix: full-screen overlay + internal scroll + fixed footer */
html.modal-lock, body.modal-open { overflow: hidden !important; }
.modal-backdrop{
  position: fixed !important;
  inset: 0 !important;
  z-index: 999999 !important;
  display: none !important;
  align-items: center !important;
  justify-content: center !important;
  padding: 20px !important;
  background: rgba(18, 24, 38, .48) !important;
  backdrop-filter: blur(20px) saturate(160%) !important;
  -webkit-backdrop-filter: blur(20px) saturate(160%) !important;
  overflow: hidden !important;
}
.modal-backdrop.open{ display: flex !important; }
.modal-card{
  width: min(960px, calc(100vw - 40px)) !important;
  height: min(760px, calc(100dvh - 40px)) !important;
  max-width: calc(100vw - 40px) !important;
  max-height: calc(100dvh - 40px) !important;
  margin: 0 !important;
  padding: 0 !important;
  border-radius: 30px !important;
  display: flex !important;
  flex-direction: column !important;
  overflow: hidden !important;
  transform: none !important;
  background: linear-gradient(145deg, rgba(255,255,255,.94), rgba(255,255,255,.72)) !important;
}
.modal-head{
  flex: 0 0 auto !important;
  display:flex !important;
  align-items:flex-start !important;
  justify-content:space-between !important;
  gap:16px !important;
  padding: 22px 24px 14px !important;
  margin:0 !important;
  border-bottom: 1px solid rgba(70,82,110,.10) !important;
}
.modal-form{
  flex: 1 1 auto !important;
  min-height: 0 !important;
  display: flex !important;
  flex-direction: column !important;
  overflow: hidden !important;
}
.modal-body{
  flex: 1 1 auto !important;
  min-height: 0 !important;
  overflow: auto !important;
  padding: 18px 24px 22px !important;
  -webkit-overflow-scrolling: touch !important;
}
.modal-footer{
  flex: 0 0 auto !important;
  display:flex !important;
  justify-content:flex-end !important;
  gap:10px !important;
  padding: 14px 24px 18px !important;
  background: rgba(255,255,255,.88) !important;
  border-top: 1px solid rgba(70,82,110,.12) !important;
  backdrop-filter: blur(18px) saturate(170%) !important;
  -webkit-backdrop-filter: blur(18px) saturate(170%) !important;
}
.modal-footer .primary,.modal-footer .secondary{ min-width: 132px !important; }
.modal-table{
  flex: 1 1 auto !important;
  min-height:0 !important;
  overflow:auto !important;
  margin: 0 22px 22px !important;
}
@media(max-width:760px){
  .modal-backdrop{ align-items:flex-end !important; padding: 10px !important; }
  .modal-card{ width:100% !important; height: min(88dvh, 760px) !important; max-width:100% !important; max-height:88dvh !important; border-radius: 26px 26px 18px 18px !important; }
  .modal-head{ padding:18px 18px 12px !important; }
  .modal-body{ padding:14px 18px 18px !important; grid-template-columns:1fr !important; }
  .modal-footer{ padding:12px 18px 16px !important; flex-direction:column-reverse !important; }
  .modal-footer .primary,.modal-footer .secondary{ width:100% !important; }
}
</style>
</head>
<body class="ios-glass-bg">
<div class="orb orb-a"></div><div class="orb orb-b"></div><div class="orb orb-c"></div>
<div class="drawer-overlay" id="drawerOverlay"></div>
<header class="topbar glass-card">
  <div class="topbar-left">
    <button type="button" class="menu-toggle" id="menuToggle" aria-label="打开菜单" aria-controls="sidebarMenu" aria-expanded="false">☰</button>
    <div class="brand-row compact"><div class="app-mark small">UR</div><div><strong><?= h($siteName) ?></strong><span><?= h($user['username']) ?><?= (int)$user['is_admin']===1?' · 管理员':'' ?></span></div></div>
  </div>
  <nav><?php if((int)$user['is_admin']===1): ?><a href="docs.php">API 文档</a><?php endif; ?><a href="?logout=1">退出</a></nav>
</header>
<main class="shell shell-drawer">
<?php if ($flash): ?><div class="glass-alert <?= h($flash['type']) ?>"><?= h($flash['msg']) ?></div><?php endif; ?>
<?php if ($error): ?><div class="glass-alert error"><?= h($error) ?></div><?php endif; ?>
<div class="workspace-layout">
<aside class="sidebar glass-card" id="sidebarMenu" aria-hidden="true">
  <div class="sidebar-head">
    <div class="brand-row compact"><div class="app-mark small">UR</div><div><strong><?= h($siteName) ?></strong><span>控制台菜单</span></div></div>
    <button type="button" class="sidebar-close" id="sidebarClose" aria-label="关闭菜单">×</button>
  </div>
  <nav class="sidebar-nav" aria-label="后台功能菜单">
    <button type="button" class="tab-btn sidebar-tab" data-tab="tab-apps"><span class="tab-icon">⌘</span><span>我的 App</span></button>
    <button type="button" class="tab-btn sidebar-tab" data-tab="tab-release"><span class="tab-icon">⇧</span><span>发布热更新版本</span></button>
    <?php if((int)$user['is_admin']===1): ?>
      <button type="button" class="tab-btn sidebar-tab" data-tab="tab-users"><span class="tab-icon">◎</span><span>用户管理</span></button>
      <button type="button" class="tab-btn sidebar-tab" data-tab="tab-cards"><span class="tab-icon">◆</span><span>会员卡密系统</span></button>
      <button type="button" class="tab-btn sidebar-tab" data-tab="tab-settings"><span class="tab-icon">⚙</span><span>系统设置 / 邮箱配置</span></button>
    <?php endif; ?>
  </nav>
  <?php if((int)$user['is_admin']!==1): ?>
  <div class="sidebar-foot">
    <?php if($purchaseUrl): ?><a class="primary full" href="<?= h($purchaseUrl) ?>" target="_blank" rel="noopener">去购买卡密</a><?php else: ?><button class="primary full" type="button" onclick="alert('管理员还没有配置购买卡密地址')">去购买卡密</button><?php endif; ?>
  </div>
  <?php endif; ?>
</aside>
<div class="page-stack">
<section class="hero glass-card simplified-hero">
  <div><p class="eyebrow">User Space</p><h1>用户空间</h1><p class="muted">会员有效期：<?= user_is_member($user) ? h($user['membership_until'] ?: '管理员永久会员') : '未开通会员' ?></p></div>
  <div class="hero-actions">
    <?php if((int)$user['is_admin']!==1): ?>
      <?php if($purchaseUrl): ?><a class="primary ghost buy-card-button top-buy" href="<?= h($purchaseUrl) ?>" target="_blank" rel="noopener">去购买卡密</a><?php else: ?><button class="primary ghost buy-card-button top-buy" type="button" onclick="alert('管理员还没有配置购买卡密地址')">去购买卡密</button><?php endif; ?>
    <?php endif; ?>
    <a class="secondary" href="api.php?action=check_update<?= $currentApp?'&appId='.urlencode($currentApp['appid']):'' ?>" target="_blank">更新接口</a>
  </div>
</section>


<section class="tab-panel" id="tab-apps" data-default-tab="<?= h($defaultTab) ?>">
  <div class="dashboard-grid">
    <div class="stat-card glass-card"><span>我的 App</span><strong><?= count($apps) ?></strong><small>每个 App 都有独立 AppID</small></div>
    <div class="stat-card glass-card"><span>会员状态</span><strong><?= user_is_member($user)?'有效':'未开通' ?></strong><small><?= user_is_member($user)?h($user['membership_until']):'发布版本需要会员' ?></small></div>
    <div class="stat-card glass-card"><span>账号类型</span><strong><?= (int)$user['is_admin']===1?'管理员':'用户' ?></strong><small>管理员可以进入后台管理</small></div>
  </div>

  <div class="subtab-shell glass-card">
    <button type="button" class="subtab-btn active" data-subtab="sub-create">创建 App</button>
    <button type="button" class="subtab-btn" data-subtab="sub-list">App 列表</button>
    <button type="button" class="subtab-btn" data-subtab="sub-account">会员 / 账号</button>
  </div>

  <div class="subtab-panel active" id="sub-create">
    <section class="glass-card form-card">
      <h2>创建 App</h2>
      <form method="post" class="grid two">
        <input type="hidden" name="action" value="create_app">
        <div><label>App 名称</label><input name="name" required placeholder="例如：我的应用"></div>
        <div><label>包名</label><input name="packageName" placeholder="com.example.app"></div>
        <div><label>目标宿主包名</label><input name="targetHostPackage" placeholder="默认可与包名一致"></div>
        <div><label>描述</label><input name="description" placeholder="备注"></div>
        <div class="full-row"><button class="primary">创建 App</button></div>
      </form>
    </section>
  </div>

  <div class="subtab-panel" id="sub-list">
    <section class="glass-card versions-card">
      <div class="section-head"><h2>我的 App</h2><span class="pill"><?= count($apps) ?> 个</span></div>
      <div class="cards-grid">
      <?php foreach($apps as $a): ?>
        <article class="mini-card <?= $currentApp && (int)$currentApp['id']===(int)$a['id']?'selected':'' ?>">
          <h3><?= h($a['name']) ?></h3><p class="muted">包名：<?= h($a['package_name'] ?: '-') ?></p><p class="mono strong-mono">AppID: <?= h($a['appid'] ?? '') ?></p><p class="mono muted">兼容 appKey: <?= h($a['app_key']) ?></p><p class="muted">所有者：<?= h($a['owner_username'] ?? $user['username']) ?></p><a class="primary small-btn" href="index.php?app_id=<?= (int)$a['id'] ?>#tab-release">进入发布</a>
        </article>
      <?php endforeach; if(!$apps): ?><p class="muted">还没有 App，先创建一个。</p><?php endif; ?>
      </div>
    </section>
  </div>

  <div class="subtab-panel" id="sub-account">
    <div class="content-grid">
      <section class="glass-card form-card profile-card">
        <div class="section-head"><div><p class="eyebrow">Profile</p><h2>我的资料</h2><p class="muted">可查看和修改头像、昵称、邮箱、用户名、性别、生日与会员状态。</p></div><?php if(!empty($user['avatar_url'])): ?><img class="avatar-preview" src="<?= h($user['avatar_url']) ?>" alt="avatar"><?php endif; ?></div>
        <form method="post" class="grid two">
          <input type="hidden" name="action" value="update_profile">
          <div><label>头像 URL</label><input name="avatarUrl" value="<?= h($user['avatar_url'] ?? '') ?>" placeholder="https://..."><input type="file" name="avatar" accept="image/*"></div>
          <div><label>昵称</label><input name="nickname" value="<?= h($user['nickname'] ?: $user['username']) ?>" maxlength="32"></div>
          <div><label>用户名</label><input name="username" value="<?= h($user['username']) ?>" required></div>
          <div><label>邮箱</label><input type="email" name="email" value="<?= h($user['email']) ?>" required></div>
          <div><label>性别</label><select name="gender"><option value="" <?= ($user['gender'] ?? '')===''?'selected':'' ?>>未设置</option><option value="male" <?= ($user['gender'] ?? '')==='male'?'selected':'' ?>>男</option><option value="female" <?= ($user['gender'] ?? '')==='female'?'selected':'' ?>>女</option><option value="other" <?= ($user['gender'] ?? '')==='other'?'selected':'' ?>>其他</option><option value="secret" <?= ($user['gender'] ?? '')==='secret'?'selected':'' ?>>保密</option></select></div>
          <div><label>生日</label><input type="date" name="birthday" value="<?= h($user['birthday'] ?? '') ?>"></div>
          <div><label>会员状态</label><input value="<?= h(user_member_status($user)) ?><?= user_is_member($user) ? ' / 有效期：'.h($user['membership_until'] ?: '管理员永久会员') : ' / 未开通' ?>" readonly></div>
          <div class="align-end"><button class="primary">保存资料</button></div>
        </form>
      </section>
      <section class="glass-card form-card"><h2>兑换会员卡密</h2><form method="post"><input type="hidden" name="action" value="redeem_card"><label>卡密</label><input name="code" placeholder="UR-XXXXXXXX-XXXXXXXX"><button class="primary full">兑换</button></form><?php if((int)$user['is_admin']!==1): ?><?php if($purchaseUrl): ?><a class="buy-card-button" href="<?= h($purchaseUrl) ?>" target="_blank" rel="noopener">没有卡密？去购买</a><?php else: ?><button class="buy-card-button button-reset" type="button" onclick="alert('管理员还没有配置购买卡密地址')">没有卡密？去购买</button><?php endif; ?><?php endif; ?></section>
      <section class="glass-card form-card"><h2>修改密码</h2><form method="post"><input type="hidden" name="action" value="change_password"><label>当前密码</label><input type="password" name="oldPassword"><label>新密码</label><input type="password" name="newPassword"><label>确认新密码</label><input type="password" name="confirmPassword"><button class="secondary full">保存密码</button></form></section>
    </div>
  </div>
</section>

<section class="tab-panel" id="tab-release">
  <?php if(!$currentApp): ?>
    <section class="glass-card form-card empty-state"><h2>先选择一个 App</h2><p class="muted">请到“我的 App → App 列表”里选择要发布热更新的 App，或者先创建一个 App。</p><button type="button" class="primary" data-jump-tab="tab-apps">去我的 App</button></section>
  <?php else: ?>
    <section class="glass-card form-card" id="app-panel">
      <div class="section-head"><div><p class="eyebrow">App Console</p><h2><?= h($currentApp['name']) ?></h2><p class="mono strong-mono">AppID: <?= h($currentApp['appid'] ?? '') ?></p><p class="muted">客户端只需要填写这个 AppID，库会固定请求 http://ucore.uaoan.cn</p></div><a class="secondary" href="api.php?action=check_update&appId=<?= urlencode($currentApp['appid'] ?? '') ?>" target="_blank">查看客户端更新 JSON</a></div>
      <form method="post" class="grid two">
        <input type="hidden" name="action" value="update_app"><input type="hidden" name="appId" value="<?= (int)$currentApp['id'] ?>">
        <div><label>App 名称</label><input name="name" value="<?= h($currentApp['name']) ?>"></div>
        <div><label>包名</label><input name="packageName" value="<?= h($currentApp['package_name']) ?>"></div>
        <div><label>目标宿主包名</label><input name="targetHostPackage" value="<?= h($currentApp['target_host_package']) ?>"></div>
        <div><label>描述</label><input name="description" value="<?= h($currentApp['description']) ?>"></div>
        <label class="check"><input type="checkbox" name="isDisabled" <?= (int)$currentApp['is_disabled']===1?'checked':'' ?>> 禁用这个 App 更新接口</label>
        <div class="actions"><button class="secondary">保存 App</button></div>
      </form>
    </section>

    <section class="glass-card form-card">
      <h2>发布热更新版本</h2>
      <?php if(!user_is_member($user)): ?><div class="alert error">必须是会员才能发布新版本。<?php if($purchaseUrl): ?><a href="<?= h($purchaseUrl) ?>" target="_blank">去购买卡密</a><?php endif; ?></div><?php endif; ?>
      <form method="post" enctype="multipart/form-data" id="versionForm" class="grid two">
        <input type="hidden" name="action" value="save_version"><input type="hidden" name="appId" value="<?= (int)$currentApp['id'] ?>">
        <div><label>patchCode</label><input type="number" name="patchCode" value="1" min="1" required></div>
        <div><label>版本名</label><input name="patchName" value="hotfix-1"></div>
        <div class="full-row"><label>拖拽或选择 APK / 补丁包</label><div class="drop-zone" id="dropZone"><input type="file" name="patch" id="patchFile" accept=".apk,.jar,.dex,.zip"><strong>拖动 APK 到这里上传</strong><span>或点击选择文件</span></div></div>
        <div><label>packageName</label><input name="packageName" placeholder="可留空，客户端自动读取"></div>
        <div><label>targetHostPackage</label><input name="targetHostPackage" value="<?= h($currentApp['target_host_package'] ?: $currentApp['package_name']) ?>"></div>
        <div><label>entryClass</label><input name="entryClass" placeholder="一般留空"></div>
        <div><label>entryMethod</label><input name="entryMethod" value="onLoad"></div>
        <div><label>minHostVersionCode</label><input type="number" name="minHostVersionCode" value="1" min="0"></div>
        <div class="full-row"><label>更新说明</label><textarea name="message">发现热更新补丁。</textarea></div>
        <label class="check"><input type="checkbox" name="enabled" checked> 启用</label><label class="check"><input type="checkbox" name="mergeDex" checked> 加载 dex</label><label class="check"><input type="checkbox" name="restartAfterApply" checked> 下载后重启</label><label class="check"><input type="checkbox" name="autoApply" checked> 自动应用</label><label class="check"><input type="checkbox" name="setCurrent" checked> 设为当前版本</label>
        <div class="full-row"><div class="progress-box" id="uploadProgress"><div class="progress-head"><strong>上传进度</strong><span id="progressText">0%</span></div><div class="bar"><i id="progressBar"></i></div><p id="progressInfo" class="help">等待上传。</p></div><button class="primary" <?= user_is_member($user)?'':'disabled' ?>>发布版本</button></div>
      </form>
    </section>

    <section class="glass-card versions-card">
      <h2>版本列表</h2>
      <?php foreach($versions as $v): ?>
      <article class="version-row version-edit-row">
        <div class="version-summary"><h3><?= h($v['patch_name']) ?> <span class="pill">patchCode <?= (int)$v['patch_code'] ?></span> <?= (int)$v['is_current']===1?'<span class="pill green">当前</span>':'' ?></h3><p class="muted"><?= h($v['message']) ?></p><p class="mono"><?= h($v['original_file_name'] ?: $v['patch_url']) ?> · SHA256 <?= h(substr($v['sha256'],0,16)) ?>...</p></div>
        <div class="version-actions"><form method="post"><input type="hidden" name="action" value="set_current"><input type="hidden" name="versionId" value="<?= (int)$v['id'] ?>"><button class="secondary">设为当前</button></form><button class="secondary" type="button" data-open-modal="version_edit_<?= (int)$v['id'] ?>">修改</button><form method="post" onsubmit="return confirm('确定删除这个版本？')"><input type="hidden" name="action" value="delete_version"><input type="hidden" name="versionId" value="<?= (int)$v['id'] ?>"><button class="danger">删除</button></form></div>
      </article>
      <div class="modal-backdrop" id="version_edit_<?= (int)$v['id'] ?>" aria-hidden="true">
        <div class="modal-card glass-card" role="dialog" aria-modal="true" aria-label="修改热更新版本">
          <div class="modal-head"><div><p class="eyebrow">Edit Version</p><h2>修改热更新版本</h2><p class="muted">可修改配置，也可重新上传 APK 替换原补丁。</p></div><div class="modal-head-actions"><button class="primary small-btn" form="version_edit_form_<?= (int)$v['id'] ?>">保存修改</button><button type="button" class="modal-close" data-close-modal="version_edit_<?= (int)$v['id'] ?>">×</button></div></div>
          <form id="version_edit_form_<?= (int)$v['id'] ?>" method="post" enctype="multipart/form-data" class="modal-form">
            <div class="modal-body grid two">
            <input type="hidden" name="action" value="save_version"><input type="hidden" name="appId" value="<?= (int)$currentApp['id'] ?>"><input type="hidden" name="versionId" value="<?= (int)$v['id'] ?>">
            <div><label>patchCode</label><input type="number" name="patchCode" value="<?= (int)$v['patch_code'] ?>" min="1" required></div>
            <div><label>版本名</label><input name="patchName" value="<?= h($v['patch_name']) ?>"></div>
            <div class="full-row"><label>重新上传 APK / 补丁包（可选）</label><input type="file" name="patch" accept=".apk,.jar,.dex,.zip"><p class="help">不选择文件则保留原文件。</p></div>
            <div><label>patchUrl</label><input name="patchUrl" value="<?= h($v['patch_url']) ?>"></div>
            <div><label>sha256</label><input name="sha256" value="<?= h($v['sha256']) ?>"></div>
            <div><label>packageName</label><input name="packageName" value="<?= h($v['package_name']) ?>"></div>
            <div><label>targetHostPackage</label><input name="targetHostPackage" value="<?= h($v['target_host_package']) ?>"></div>
            <div><label>entryClass</label><input name="entryClass" value="<?= h($v['entry_class']) ?>"></div>
            <div><label>entryMethod</label><input name="entryMethod" value="<?= h($v['entry_method'] ?: 'onLoad') ?>"></div>
            <div><label>minHostVersionCode</label><input type="number" name="minHostVersionCode" value="<?= (int)$v['min_host_version_code'] ?>" min="0"></div>
            <div class="full-row"><label>更新说明</label><textarea name="message"><?= h($v['message']) ?></textarea></div>
            <label class="check"><input type="checkbox" name="enabled" <?= (int)$v['enabled']===1?'checked':'' ?>> 启用</label><label class="check"><input type="checkbox" name="mergeDex" <?= (int)$v['merge_dex']===1?'checked':'' ?>> 加载 dex</label><label class="check"><input type="checkbox" name="restartAfterApply" <?= (int)$v['restart_after_apply']===1?'checked':'' ?>> 下载后重启</label><label class="check"><input type="checkbox" name="autoApply" <?= (int)$v['auto_apply']===1?'checked':'' ?>> 自动应用</label><label class="check"><input type="checkbox" name="setCurrent" <?= (int)$v['is_current']===1?'checked':'' ?>> 设为当前版本</label>
            </div>
            <div class="modal-footer">
              <button type="button" class="secondary" data-close-modal="version_edit_<?= (int)$v['id'] ?>">取消</button>
              <button class="primary">保存版本修改</button>
            </div>
          </form>
        </div>
      </div>
      <?php endforeach; if(!$versions): ?><p class="muted">暂无版本。</p><?php endif; ?>
    </section>
  <?php endif; ?>
</section>

<?php if((int)$user['is_admin']===1): ?>
<section class="tab-panel" id="tab-users">
  <section class="glass-card form-card"><div class="section-head"><h2>用户管理</h2><span class="pill">管理员后台</span></div>
    <p class="muted">可以查看每个用户创建的所有 APP 和全部版本，并直接编辑或删除。</p>
    <div class="table-wrap"><table><thead><tr><th>ID</th><th>用户</th><th>邮箱</th><th>资料</th><th>会员到期</th><th>管理</th><th>封号</th><th>操作</th></tr></thead><tbody>
    <?php foreach($adminUsers as $u): $assetsModalId = 'user_assets_' . (int)$u['id']; ?><tr><form method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="admin_save_user"><input type="hidden" name="userId" value="<?= (int)$u['id'] ?>"><td><?= (int)$u['id'] ?></td><td><input name="avatarUrl" value="<?= h($u['avatar_url'] ?? '') ?>" placeholder="头像URL"><input type="file" name="avatar" accept="image/*"><input name="nickname" value="<?= h($u['nickname'] ?: $u['username']) ?>" placeholder="昵称"></td><td><input name="username" value="<?= h($u['username']) ?>"><input name="email" value="<?= h($u['email']) ?>"></td><td><select name="gender"><option value="" <?= ($u['gender'] ?? '')===''?'selected':'' ?>>未设置</option><option value="male" <?= ($u['gender'] ?? '')==='male'?'selected':'' ?>>男</option><option value="female" <?= ($u['gender'] ?? '')==='female'?'selected':'' ?>>女</option><option value="other" <?= ($u['gender'] ?? '')==='other'?'selected':'' ?>>其他</option><option value="secret" <?= ($u['gender'] ?? '')==='secret'?'selected':'' ?>>保密</option></select><input type="date" name="birthday" value="<?= h($u['birthday'] ?? '') ?>"></td><td><input name="membershipUntil" value="<?= h($u['membership_until']) ?>" placeholder="YYYY-mm-dd HH:ii:ss"><small><?= h(user_member_status($u)) ?></small></td><td><input type="checkbox" name="isAdmin" <?= (int)$u['is_admin']===1?'checked':'' ?>></td><td><input type="checkbox" name="isBanned" <?= (int)$u['is_banned']===1?'checked':'' ?>></td><td class="actions"><button class="secondary small-btn">保存</button></form><button type="button" class="primary small-btn" data-open-modal="<?= h($assetsModalId) ?>">APP/版本</button><form method="post" onsubmit="return confirm('确定删除用户？')"><input type="hidden" name="action" value="admin_delete_user"><input type="hidden" name="userId" value="<?= (int)$u['id'] ?>"><button class="danger small-btn">删除</button></form></td></tr><?php endforeach; ?>
    </tbody></table></div>
  </section>

  <?php foreach($adminUsers as $u): $assetsModalId = 'user_assets_' . (int)$u['id']; $ownedApps = list_apps_with_versions_for_owner((int)$u['id']); ?>
  <div class="modal-backdrop" id="<?= h($assetsModalId) ?>" aria-hidden="true">
    <div class="modal-card glass-card" role="dialog" aria-modal="true" aria-label="用户 APP 和版本">
      <div class="modal-head"><div><p class="eyebrow">User Assets</p><h2><?= h($u['username']) ?> 的 APP 和版本</h2><p class="muted">共 <?= count($ownedApps) ?> 个 APP。这里可以编辑/删除 APP，也可以编辑/删除版本。</p></div><button type="button" class="modal-close" data-close-modal="<?= h($assetsModalId) ?>">×</button></div>
      <div class="modal-body single-col">
        <?php if(!$ownedApps): ?><p class="muted">该用户还没有创建 APP。</p><?php endif; ?>
        <?php foreach($ownedApps as $a): $versionsForApp = $a['versions'] ?? []; ?>
          <article class="mini-card user-asset-card">
            <form method="post" class="grid two compact-form">
              <input type="hidden" name="action" value="update_app"><input type="hidden" name="appId" value="<?= (int)$a['id'] ?>">
              <div><label>APP 名称</label><input name="name" value="<?= h($a['name']) ?>"></div>
              <div><label>包名</label><input name="packageName" value="<?= h($a['package_name']) ?>"></div>
              <div><label>目标宿主包名</label><input name="targetHostPackage" value="<?= h($a['target_host_package']) ?>"></div>
              <div><label>描述</label><input name="description" value="<?= h($a['description']) ?>"></div>
              <label class="check"><input type="checkbox" name="isDisabled" <?= (int)$a['is_disabled']===1?'checked':'' ?>> 禁用</label>
              <div class="actions align-end"><button class="secondary small-btn">保存 APP</button></div>
            </form>
            <form method="post" onsubmit="return confirm('确定删除这个 APP 及其全部版本？')" class="inline-form"><input type="hidden" name="action" value="delete_app"><input type="hidden" name="appId" value="<?= (int)$a['id'] ?>"><button class="danger small-btn">删除 APP</button></form>
            <p class="mono strong-mono">AppID: <?= h($a['appid'] ?? '') ?></p>
            <h4>版本列表</h4>
            <?php if(!$versionsForApp): ?><p class="muted">暂无版本。</p><?php endif; ?>
            <?php foreach($versionsForApp as $v): ?>
              <div class="version-row mini-card">
                <form method="post" class="grid three compact-form">
                  <input type="hidden" name="action" value="save_version"><input type="hidden" name="appId" value="<?= (int)$a['id'] ?>"><input type="hidden" name="versionId" value="<?= (int)$v['id'] ?>">
                  <div><label>patchCode</label><input type="number" name="patchCode" value="<?= (int)$v['patch_code'] ?>"></div>
                  <div><label>版本名</label><input name="patchName" value="<?= h($v['patch_name']) ?>"></div>
                  <div><label>更新说明</label><input name="message" value="<?= h($v['message']) ?>"></div>
                  <label class="check"><input type="checkbox" name="enabled" <?= (int)$v['enabled']===1?'checked':'' ?>> 启用</label>
                  <label class="check"><input type="checkbox" name="setCurrent" <?= (int)$v['is_current']===1?'checked':'' ?>> 当前版本</label>
                  <div class="actions align-end"><button class="secondary small-btn">保存版本</button></div>
                </form>
                <form method="post" class="inline-form" onsubmit="return confirm('确定删除这个版本？')"><input type="hidden" name="action" value="delete_version"><input type="hidden" name="versionId" value="<?= (int)$v['id'] ?>"><button class="danger small-btn">删除版本</button></form>
              </div>
            <?php endforeach; ?>
          </article>
        <?php endforeach; ?>
      </div>
      <div class="modal-footer"><button type="button" class="secondary" data-close-modal="<?= h($assetsModalId) ?>">关闭</button></div>
    </div>
  </div>
  <?php endforeach; ?>
</section>

<section class="tab-panel" id="tab-cards">
  <section class="glass-card form-card cards-console">
    <div class="section-head cards-title-head">
      <div><p class="eyebrow">Membership Cards</p><h2>会员卡密系统</h2><p class="muted">卡密按天数分类展示，每个分类默认只显示 10 条，展开后可查看全部。</p></div>
      <?php if($purchaseUrl): ?><a class="secondary glass-link" href="<?= h($purchaseUrl) ?>" target="_blank" rel="noopener">购买地址</a><?php endif; ?>
    </div>

    <form method="post" class="grid four create-card-form">
      <input type="hidden" name="action" value="admin_create_cards">
      <div><label>天数</label><select name="days"><option value="1">1 天</option><option value="30" selected>30 天</option><option value="60">60 天</option><option value="90">90 天</option><option value="365">365 天</option></select></div>
      <div><label>数量</label><input type="number" name="count" min="1" max="1000" value="10"></div>
      <div><label>前缀</label><input name="prefix" value="UR"></div>
      <div class="align-end"><button class="primary">批量生成</button></div>
    </form>

    <?php if($lastCards): ?>
      <div class="code-box last-cards-box">
        <div class="section-head"><label>本次生成 <?= h($lastCardsDays ?: '') ?> 天卡密</label><button type="button" class="secondary small-btn" data-copy-target="lastCardsText">复制本次全部</button></div>
        <textarea id="lastCardsText" class="codes" readonly><?= h(implode("\n", $lastCards)) ?></textarea>
      </div>
    <?php endif; ?>

    <?php if($cardGroups): ?>
      <div class="copy-dock glass-card">
        <div><strong>一键复制卡密</strong><p class="muted small">复制按钮集中在这里；每个天数可复制全部或仅未使用。</p></div>
        <div class="copy-dock-buttons">
        <?php foreach($cardGroups as $days => $group):
          $unusedCount = 0; foreach($group as $x){ if(($x['status'] ?? '') === 'unused') $unusedCount++; }
          $safeId = 'codes_'.$days;
          $safeUnusedId = 'unused_codes_'.$days;
        ?>
          <div class="copy-cluster"><span><?= (int)$days ?> 天</span><button type="button" class="secondary small-btn" data-copy-target="<?= h($safeId) ?>">全部</button><button type="button" class="secondary small-btn" data-copy-target="<?= h($safeUnusedId) ?>">未使用 <?= (int)$unusedCount ?></button></div>
        <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="card-day-groups">
    <?php foreach($cardGroups as $days => $group):
        $allCodes = array_map(function($x){ return $x['code']; }, $group);
        $unusedCodes = array_map(function($x){ return $x['code']; }, array_filter($group, function($x){ return ($x['status'] ?? '') === 'unused'; }));
        $usedCount = count($group) - count($unusedCodes);
        $safeId = 'codes_'.$days;
        $safeUnusedId = 'unused_codes_'.$days;
        $modalId = 'cards_modal_'.$days;
        $preview = array_slice($group, 0, 10);
    ?>
      <article class="glass-card card-group-card">
        <div class="section-head wrap">
          <div><h3><?= (int)$days ?> 天会员卡密</h3><p class="muted">共 <?= count($group) ?> 张 · 未使用 <?= count($unusedCodes) ?> 张 · 已使用 <?= $usedCount ?> 张 · 当前显示前 <?= min(10, count($group)) ?> 条</p></div>
          <div class="actions"><button type="button" class="primary small-btn" data-open-modal="<?= h($modalId) ?>">展开更多</button></div>
        </div>
        <textarea id="<?= h($safeId) ?>" class="copy-source" readonly><?= h(implode("\n", $allCodes)) ?></textarea>
        <textarea id="<?= h($safeUnusedId) ?>" class="copy-source" readonly><?= h(implode("\n", $unusedCodes)) ?></textarea>
        <div class="table-wrap compact-table"><table><thead><tr><th>卡密</th><th>状态</th><th>使用者</th><th>使用时间</th><th>创建时间</th></tr></thead><tbody>
        <?php foreach($preview as $c): $isUsed = ($c['status'] ?? '') === 'used'; ?><tr class="<?= $isUsed?'used-row':'unused-row' ?>"><td class="mono"><?= h($c['code']) ?></td><td><?= $isUsed ? '<span class="pill">已使用</span>' : '<span class="pill green">未使用</span>' ?></td><td><?= $isUsed ? h(($c['used_by_username'] ?: '-') . (!empty($c['used_by_email']) ? ' / '.$c['used_by_email'] : '')) : '-' ?></td><td><?= $isUsed ? h($c['used_at'] ?: '-') : '-' ?></td><td><?= h($c['created_at']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
      </article>

      <div class="modal-backdrop" id="<?= h($modalId) ?>" aria-hidden="true">
        <div class="modal-card glass-card" role="dialog" aria-modal="true" aria-label="<?= (int)$days ?> 天会员卡密全部列表">
          <div class="modal-head"><div><p class="eyebrow">All Cards</p><h2><?= (int)$days ?> 天会员卡密</h2><p class="muted">共 <?= count($group) ?> 张 · 未使用 <?= count($unusedCodes) ?> 张 · 已使用 <?= $usedCount ?> 张</p></div><button type="button" class="modal-close" data-close-modal="<?= h($modalId) ?>">×</button></div>
          <div class="modal-actions"><button type="button" class="secondary small-btn" data-copy-target="<?= h($safeId) ?>">复制<?= (int)$days ?>天全部</button><button type="button" class="secondary small-btn" data-copy-target="<?= h($safeUnusedId) ?>">复制未使用</button></div>
          <div class="table-wrap modal-table"><table><thead><tr><th>卡密</th><th>状态</th><th>使用者</th><th>使用时间</th><th>创建时间</th></tr></thead><tbody>
          <?php foreach($group as $c): $isUsed = ($c['status'] ?? '') === 'used'; ?><tr class="<?= $isUsed?'used-row':'unused-row' ?>"><td class="mono"><?= h($c['code']) ?></td><td><?= $isUsed ? '<span class="pill">已使用</span>' : '<span class="pill green">未使用</span>' ?></td><td><?= $isUsed ? h(($c['used_by_username'] ?: '-') . (!empty($c['used_by_email']) ? ' / '.$c['used_by_email'] : '')) : '-' ?></td><td><?= $isUsed ? h($c['used_at'] ?: '-') : '-' ?></td><td><?= h($c['created_at']) ?></td></tr><?php endforeach; ?>
          </tbody></table></div>
        </div>
      </div>
    <?php endforeach; if(!$cardGroups): ?><p class="muted">暂无卡密，先批量生成。</p><?php endif; ?>
    </div>
  </section>
</section>

<section class="tab-panel" id="tab-settings">
  <div class="content-grid settings-grid">
    <section class="glass-card form-card">
      <h2>系统设置 / 邮箱配置</h2>
      <form method="post" enctype="multipart/form-data" class="grid two">
        <input type="hidden" name="action" value="admin_save_settings">
        <div><label>站点名称</label><input name="site_name" value="<?= h($settings['site_name'] ?? 'UcoreReload') ?>"></div>
        <div><label>购买卡密地址</label><input name="purchase_url" value="<?= h($settings['purchase_url'] ?? '') ?>" placeholder="https://..."></div>
        <div class="full-row"><label>Web端主页“下载APP”按钮链接</label><input name="homepage_app_download_url" value="<?= h($settings['homepage_app_download_url'] ?? '') ?>" placeholder="https://.../UcoreReloadsUser.apk"></div>
        <div class="full-row"><label>或上传 Web 主页下载 APP 的 APK</label><input type="file" name="homepageAppApk" accept=".apk,application/vnd.android.package-archive"></div>
        <div><label>UcoreReloadsUser 版本号</label><input name="user_app_version_code" value="<?= h($settings['user_app_version_code'] ?? '1') ?>" placeholder="例如 2"></div>
        <div><label>UcoreReloadsUser 版本名</label><input name="user_app_version_name" value="<?= h($settings['user_app_version_name'] ?? '1.0') ?>" placeholder="例如 1.1.0"></div>
        <div class="full-row"><label>软件内版本更新下载链接</label><input name="user_app_download_url" value="<?= h($settings['user_app_download_url'] ?? '') ?>" placeholder="https://.../UcoreReloadsUser.apk"></div>
        <div class="full-row"><label>或上传软件内版本更新 APK</label><input type="file" name="userAppApk" accept=".apk,application/vnd.android.package-archive"></div>
        <div class="full-row"><label>软件更新说明</label><textarea name="user_app_update_message" rows="3" placeholder="新版本更新内容"><?= h($settings['user_app_update_message'] ?? '') ?></textarea></div>
        <label class="check"><input type="checkbox" name="user_app_force_update" value="1" <?= ($settings['user_app_force_update'] ?? '0')==='1'?'checked':'' ?>> 强制更新 UcoreReloadsUser</label><div></div>
        <label class="check"><input type="checkbox" name="user_app_announcement_enabled" value="1" <?= ($settings['user_app_announcement_enabled'] ?? '0')==='1'?'checked':'' ?>> 启用软件公告</label><div></div>
        <div><label>公告标题</label><input name="user_app_announcement_title" value="<?= h($settings['user_app_announcement_title'] ?? '公告') ?>"></div>
        <div class="full-row"><label>公告内容</label><textarea name="user_app_announcement" rows="3" placeholder="会在 UcoreReloadsUser APP 中弹窗并在 APP 页顶部走马灯显示"><?= h($settings['user_app_announcement'] ?? '') ?></textarea></div>
        <label class="check"><input type="checkbox" name="email_enabled" value="1" <?= ($settings['email_enabled'] ?? '0')==='1'?'checked':'' ?>> 启用邮箱验证码</label><div></div>
        <div><label>SMTP Host</label><input name="smtp_host" value="<?= h($settings['smtp_host'] ?? '') ?>"></div>
        <div><label>SMTP Port</label><input name="smtp_port" value="<?= h($settings['smtp_port'] ?? '587') ?>"></div>
        <div><label>加密方式</label><select name="smtp_secure"><option value="tls" <?= ($settings['smtp_secure'] ?? 'tls')==='tls'?'selected':'' ?>>TLS/STARTTLS</option><option value="ssl" <?= ($settings['smtp_secure'] ?? '')==='ssl'?'selected':'' ?>>SSL</option><option value="none" <?= ($settings['smtp_secure'] ?? '')==='none'?'selected':'' ?>>无</option></select></div>
        <div><label>SMTP 用户名</label><input name="smtp_user" value="<?= h($settings['smtp_user'] ?? '') ?>"></div>
        <div><label>SMTP 密码</label><input type="password" name="smtp_pass" placeholder="留空则不修改"></div>
        <div><label>发件邮箱</label><input name="smtp_from_email" value="<?= h($settings['smtp_from_email'] ?? '') ?>"></div>
        <div><label>发件名称</label><input name="smtp_from_name" value="<?= h($settings['smtp_from_name'] ?? 'UcoreReload') ?>"></div>
        <div class="full-row"><button class="primary">保存设置</button></div>
      </form>
      <form method="post" class="test-mail-box">
        <input type="hidden" name="action" value="admin_test_email">
        <label>测试发送邮箱</label><div class="inline-field"><input type="email" name="testEmail" value="<?= h($user['email']) ?>" required><button class="secondary">发送测试邮件</button></div>
      </form>
    </section>
    <section class="glass-card form-card">
      <p class="eyebrow">Default Admin</p><h2>默认管理员账号</h2><p class="muted small">修改后下次登录使用新的管理员账号；密码留空则不修改。</p>
      <form method="post" class="grid two">
        <input type="hidden" name="action" value="admin_update_account">
        <div><label>管理员用户名</label><input name="adminUsername" value="<?= h($user['username']) ?>" required></div>
        <div><label>管理员邮箱</label><input type="email" name="adminEmail" value="<?= h($user['email']) ?>" required></div>
        <div><label>新密码</label><input type="password" name="adminNewPassword" placeholder="留空则不修改"></div>
        <div><label>确认新密码</label><input type="password" name="adminConfirmPassword" placeholder="留空则不修改"></div>
        <div class="full-row"><button class="secondary">保存管理员账号</button></div>
      </form>
    </section>
  </div>
</section>
<?php endif; ?>
</div>
</div>
</main>
<script>
(function(){
 const body=document.body;
 const drawer=document.getElementById('sidebarMenu');
 const overlay=document.getElementById('drawerOverlay');
 const menu=document.getElementById('menuToggle');
 const closeBtn=document.getElementById('sidebarClose');
 function openDrawer(){ body.classList.add('drawer-open'); if(drawer) drawer.setAttribute('aria-hidden','false'); if(menu) menu.setAttribute('aria-expanded','true'); }
 function closeDrawer(){ body.classList.remove('drawer-open'); if(drawer) drawer.setAttribute('aria-hidden','true'); if(menu) menu.setAttribute('aria-expanded','false'); }
 if(menu) menu.addEventListener('click', openDrawer);
 if(closeBtn) closeBtn.addEventListener('click', closeDrawer);
 if(overlay) overlay.addEventListener('click', closeDrawer);

 const panels=[...document.querySelectorAll('.tab-panel')];
 const buttons=[...document.querySelectorAll('.tab-btn')];
 const valid=new Set(panels.map(p=>p.id));
 const alias={cards:'tab-cards',settings:'tab-settings','admin-users':'tab-users'};
 function showTab(id, push){
   id=alias[id]||id;
   if(!valid.has(id)) id=document.querySelector('[data-default-tab]')?.dataset.defaultTab || 'tab-apps';
   if(!valid.has(id)) id='tab-apps';
   panels.forEach(p=>p.classList.toggle('active',p.id===id));
   buttons.forEach(b=>b.classList.toggle('active',b.dataset.tab===id));
   if(push) history.replaceState(null,'','#'+id);
 }
 buttons.forEach(b=>b.addEventListener('click',()=>{ showTab(b.dataset.tab,true); closeDrawer(); }));
 document.querySelectorAll('[data-jump-tab]').forEach(b=>b.addEventListener('click',()=>showTab(b.dataset.jumpTab,true)));
 showTab((location.hash||'').replace('#',''), false);
 window.addEventListener('hashchange',()=>showTab((location.hash||'').replace('#',''), false));

 const subPanels=[...document.querySelectorAll('.subtab-panel')], subBtns=[...document.querySelectorAll('.subtab-btn')];
 function showSub(id){ subPanels.forEach(p=>p.classList.toggle('active',p.id===id)); subBtns.forEach(b=>b.classList.toggle('active',b.dataset.subtab===id)); }
 subBtns.forEach(b=>b.addEventListener('click',()=>showSub(b.dataset.subtab)));

 const dz=document.getElementById('dropZone'), file=document.getElementById('patchFile');
 if(dz&&file){
   dz.onclick=()=>file.click();
   ['dragenter','dragover'].forEach(e=>dz.addEventListener(e,ev=>{ev.preventDefault();dz.classList.add('drag')}));
   ['dragleave','drop'].forEach(e=>dz.addEventListener(e,ev=>{ev.preventDefault();dz.classList.remove('drag')}));
   dz.addEventListener('drop',ev=>{ if(ev.dataTransfer.files.length){file.files=ev.dataTransfer.files; dz.querySelector('span').textContent=ev.dataTransfer.files[0].name; }});
   file.onchange=()=>{if(file.files.length)dz.querySelector('span').textContent=file.files[0].name};
 }

 const form=document.getElementById('versionForm');
 if(form){
   form.addEventListener('submit', function(ev){
     ev.preventDefault();
     const box=document.getElementById('uploadProgress'); if(box) box.style.display='block';
     const fd=new FormData(form), xhr=new XMLHttpRequest();
     const bar=document.getElementById('progressBar'), txt=document.getElementById('progressText'), info=document.getElementById('progressInfo');
     let start=Date.now();
     xhr.upload.onprogress=e=>{ if(e.lengthComputable){ let p=Math.round(e.loaded/e.total*100); if(bar)bar.style.width=p+'%'; if(txt)txt.textContent=p+'%'; let sec=(Date.now()-start)/1000; let speed=e.loaded/Math.max(sec,0.1); let left=(e.total-e.loaded)/Math.max(speed,1); if(info)info.textContent=formatBytes(e.loaded)+' / '+formatBytes(e.total)+' · '+formatBytes(speed)+'/s · 剩余 '+Math.round(left)+' 秒'; }};
     xhr.onload=()=>{try{let j=JSON.parse(xhr.responseText); alert(j.message||'完成'); if(j.ok) location.href='index.php?app_id=<?= $currentApp?(int)$currentApp['id']:0 ?>#tab-release';}catch(e){alert(xhr.responseText||'上传完成')}};
     xhr.onerror=()=>alert('上传失败');
     xhr.open('POST','index.php'); xhr.setRequestHeader('X-Requested-With','XMLHttpRequest'); xhr.send(fd);
   });
 }

 document.querySelectorAll('[data-copy-target]').forEach(btn=>btn.addEventListener('click', async()=>{
   const el=document.getElementById(btn.dataset.copyTarget); if(!el)return;
   const text=el.value||el.textContent||'';
   try{ await navigator.clipboard.writeText(text); const old=btn.textContent; btn.textContent='已复制'; setTimeout(()=>btn.textContent=old,1400); }
   catch(e){ el.classList.remove('copy-source'); el.select(); document.execCommand('copy'); el.classList.add('copy-source'); alert('已复制'); }
 }));

 function closeModal(id){ const m=document.getElementById(id); if(!m)return; m.classList.remove('open'); m.setAttribute('aria-hidden','true'); if(!document.querySelector('.modal-backdrop.open')){ body.classList.remove('modal-open'); document.documentElement.classList.remove('modal-lock'); } }
 document.querySelectorAll('[data-open-modal]').forEach(btn=>btn.addEventListener('click',()=>{ const m=document.getElementById(btn.dataset.openModal); if(!m)return; if(m.parentNode!==document.body) document.body.appendChild(m); m.classList.add('open'); m.setAttribute('aria-hidden','false'); body.classList.add('modal-open'); document.documentElement.classList.add('modal-lock'); }));
 document.querySelectorAll('[data-close-modal]').forEach(btn=>btn.addEventListener('click',()=>closeModal(btn.dataset.closeModal)));
 document.querySelectorAll('.modal-backdrop').forEach(m=>m.addEventListener('click',e=>{ if(e.target===m) closeModal(m.id); }));
 window.addEventListener('keydown',e=>{ if(e.key==='Escape'){ closeDrawer(); document.querySelectorAll('.modal-backdrop.open').forEach(m=>closeModal(m.id)); }});
 function formatBytes(n){ if(!n)return '0 B'; const u=['B','KB','MB','GB']; let i=0; while(n>=1024&&i<u.length-1){n/=1024;i++} return n.toFixed(i?2:0)+' '+u[i]; }
})();
</script>
</body></html>

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
function docs_session_user() { return !empty($_SESSION['user_id']) ? find_user((int)$_SESSION['user_id']) : null; }
$viewer = docs_session_user();
if (!$viewer) { header('Location: index.php'); exit; }
if ((int)$viewer['is_admin'] !== 1) { http_response_code(403); ?><!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>无权限</title><link rel="stylesheet" href="assets/style.css?v=<?= time() ?>">
<style>
.docs-page{overflow-x:hidden}.docs-topbar{width:min(1380px,calc(100% - 32px));margin:14px auto 0;position:sticky;top:10px;z-index:20}.docs-layout{width:min(1380px,calc(100% - 32px));margin:14px auto 60px;display:grid;grid-template-columns:300px minmax(0,1fr);gap:18px;align-items:start}.docs-sidebar{position:sticky;top:96px;max-height:calc(100dvh - 116px);overflow:auto;padding:18px;border-radius:24px}.docs-side-title{font-size:15px;font-weight:900;margin:0 0 14px}.docs-group-title{font-size:13px;color:rgba(55,68,96,.74);font-weight:900;margin:18px 0 8px}.docs-nav-link{display:flex;align-items:center;justify-content:space-between;gap:8px;text-decoration:none;color:rgba(24,32,44,.86);padding:10px 11px;border-radius:16px;margin:3px 0;background:rgba(255,255,255,.38);border:1px solid rgba(255,255,255,.48);font-weight:800}.docs-nav-link:hover{background:rgba(255,255,255,.68);transform:translateY(-1px)}.method-mini,.method-pill{border-radius:999px;padding:4px 8px;font-size:11px;font-weight:950;text-transform:uppercase}.method-mini.get,.method-pill.get{background:rgba(52,199,89,.14);color:#167a3a}.method-mini.post,.method-pill.post{background:rgba(255,149,0,.14);color:#a45b00}.docs-content{display:grid;gap:18px;min-width:0}.docs-hero-card,.endpoint-card{padding:clamp(18px,3vw,30px)}.docs-hero-card h1{font-size:clamp(34px,5vw,56px);letter-spacing:-.06em}.docs-section-heading{margin:14px 0 0;font-size:clamp(26px,4vw,40px)}.endpoint-card{scroll-margin-top:110px}.endpoint-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:14px}.auth-pill{white-space:nowrap;border-radius:999px;padding:8px 12px;background:rgba(255,255,255,.64);border:1px solid rgba(80,90,120,.14);font-weight:900;color:rgba(45,55,75,.70)}.endpoint-url{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:13px 14px;border-radius:16px;background:rgba(255,255,255,.58);border:1px solid rgba(80,90,120,.14);margin:12px 0 22px}.endpoint-url code{font-size:14px;word-break:break-all}.docs-table{margin:10px 0 22px;border-radius:18px;overflow:auto}.docs-table table{min-width:680px;background:rgba(255,255,255,.42)}.code-block{margin:10px 0 22px;padding:18px;border-radius:18px;background:#070b0a;color:#eafff2;overflow:auto;line-height:1.7}.code-block code{color:inherit}.endpoint-card h3{margin:20px 0 8px}.endpoint-card p{line-height:1.65}@media(max-width:920px){.docs-layout{grid-template-columns:1fr}.docs-sidebar{position:relative;top:auto;max-height:none}.endpoint-head,.endpoint-url{flex-direction:column;align-items:flex-start}.docs-topbar{position:relative;top:auto}.docs-topbar nav{width:100%}.docs-topbar nav a{flex:1}}
</style>
</head><body class="ios-glass-bg"><main class="auth-shell"><section class="glass-card auth-card"><h1>403</h1><p class="muted">只有后台管理员可以查看 API 文档。</p><a class="primary" href="index.php">返回后台</a></section>
<section class="glass-card"><h2>Web 主页下载 APP</h2><p>管理员可在系统设置中填写 <code>homepage_app_download_url</code>，或上传 <code>homepageAppApk</code>。公共接口 <code>user_app_config</code> 会在 <code>homepage.downloadUrl</code> 返回该链接，用于 Web 主页“下载APP”按钮。</p></section>
</main></body></html><?php exit; }
$siteName = db_get_setting('site_name', 'UcoreReload');
$base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\') . '/api.php';
$sections = [
    '系统与认证' => [
        [
            'id' => 'health', 'method'=>'GET', 'action'=>'purchase_info', 'auth'=>'否',
            'title'=>'购买地址 / 站点信息', 'desc'=>'获取后台配置的购买卡密地址和站点名称。',
            'params'=>[],
            'returns'=>[['ok','boolean','是否成功'],['message','string','响应消息'],['purchaseUrl','string','购买卡密地址'],['siteName','string','站点名称']],
            'request'=>"GET {$base}?action=purchase_info",
            'response'=>['ok'=>true,'message'=>'ok','purchaseUrl'=>'https://example.com/buy','siteName'=>'UcoreReload']
        ],
        [
            'id'=>'send_email_code','method'=>'POST','action'=>'send_email_code','auth'=>'否',
            'title'=>'获取邮箱验证码','desc'=>'注册或找回密码前发送 6 位邮箱验证码。purpose=register 时会判断邮箱是否已注册，已注册则返回错误；purpose=reset_password 时会判断邮箱是否存在。',
            'params'=>[['email','string','必填，接收验证码的邮箱'],['purpose','string','register 或 reset_password，默认 register']],
            'returns'=>[['ok','boolean','是否成功'],['message','string','响应消息'],['expiresIn','number','验证码有效期，秒'],['purpose','string','验证码用途']],
            'request'=>json_encode(['email'=>'user@example.com','purpose'=>'register'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),
            'response'=>['ok'=>true,'message'=>'验证码已发送到邮箱','expiresIn'=>600,'purpose'=>'register']
        ],
        [
            'id'=>'register','method'=>'POST','action'=>'register','auth'=>'否',
            'title'=>'注册','desc'=>'使用用户名、邮箱、密码和邮箱验证码注册，成功后返回 Bearer Token。',
            'params'=>[['username','string','必填，3-32 位中文/字母/数字/下划线'],['email','string','必填，邮箱'],['password','string','必填，至少 6 位'],['code','string','必填，邮箱验证码']],
            'returns'=>[['ok','boolean','是否成功'],['message','string','响应消息'],['token','string','接口访问令牌'],['user','object','登录用户资料']],
            'request'=>json_encode(['username'=>'demo','email'=>'user@example.com','password'=>'123456','code'=>'123456'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),
            'response'=>['ok'=>true,'message'=>'注册成功','token'=>'BearerTokenString','user'=>['id'=>2,'username'=>'demo','email'=>'user@example.com','isAdmin'=>false,'membershipUntil'=>'']]
        ],
        [
            'id'=>'login','method'=>'POST','action'=>'login','auth'=>'否',
            'title'=>'登录','desc'=>'account 支持用户名或邮箱，成功后返回 Bearer Token。',
            'params'=>[['login / account','string','必填，用户名或邮箱'],['password','string','必填，密码']],
            'returns'=>[['ok','boolean','是否成功'],['message','string','响应消息'],['token','string','接口访问令牌'],['user','object','登录用户资料']],
            'request'=>json_encode(['login'=>'demo','password'=>'123456'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),
            'response'=>['ok'=>true,'message'=>'登录成功','token'=>'BearerTokenString','user'=>['id'=>2,'username'=>'demo','email'=>'user@example.com','isAdmin'=>false]]
        ],
        [
            'id'=>'reset_password','method'=>'POST','action'=>'reset_password','auth'=>'否',
            'title'=>'邮箱验证码找回密码','desc'=>'通过邮箱验证码重置账号密码。先调用 send_email_code 且 purpose=reset_password。',
            'params'=>[['email','string','必填，注册邮箱'],['code','string','必填，邮箱验证码'],['password / newPassword','string','必填，新密码，至少 6 位']],
            'returns'=>[['ok','boolean','是否成功'],['message','string','响应消息']],
            'request'=>json_encode(['email'=>'user@example.com','code'=>'104071','password'=>'newpass123'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),
            'response'=>['ok'=>true,'message'=>'密码已重置，请使用新密码登录']
        ],
        [
            'id'=>'me','method'=>'GET','action'=>'me','auth'=>'是',
            'title'=>'当前用户资料','desc'=>'获取当前登录用户、会员状态和购买地址。',
            'params'=>[],
            'returns'=>[['ok','boolean','是否成功'],['user','object','用户资料'],['purchaseUrl','string','购买卡密地址']],
            'request'=>"GET {$base}?action=me\nAuthorization: Bearer TOKEN",
            'response'=>['ok'=>true,'message'=>'ok','user'=>['id'=>2,'username'=>'demo','membershipUntil'=>'2026-01-01 00:00:00'],'purchaseUrl'=>'https://example.com/buy']
        ],
        [
            'id'=>'profile_update','method'=>'POST','action'=>'profile_update','auth'=>'是',
            'title'=>'查看/修改自己的资料','desc'=>'修改头像、昵称、邮箱、用户名、性别、生日；会员状态由后台自动计算并返回。profile_get 与 me 一样用于读取资料。',
            'params'=>[['avatarUrl / avatar_url','string','头像 URL，http/https'],['nickname','string','昵称，最多 32 个字符'],['username','string','用户名'],['email','string','邮箱'],['gender','string','male / female / other / secret'],['birthday','string','YYYY-MM-DD']],
            'returns'=>[['ok','boolean','是否成功'],['user','object','最新用户资料']],
            'request'=>json_encode(['avatarUrl'=>'https://example.com/avatar.png','nickname'=>'Demo','username'=>'demo','email'=>'user@example.com','gender'=>'secret','birthday'=>'2000-01-01'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),
            'response'=>['ok'=>true,'message'=>'个人资料已更新','user'=>['username'=>'demo','nickname'=>'Demo','avatarUrl'=>'https://example.com/avatar.png','memberStatus'=>'active','gender'=>'secret','birthday'=>'2000-01-01']]
        ],
    ],
    '用户与会员' => [
        [
            'id'=>'redeem_card','method'=>'POST','action'=>'redeem_card','auth'=>'是',
            'title'=>'兑换会员卡密','desc'=>'用户使用卡密开通或续费会员。',
            'params'=>[['code','string','必填，会员卡密']],
            'returns'=>[['ok','boolean','是否成功'],['membershipUntil','string','兑换后的会员到期时间'],['user','object','最新用户资料']],
            'request'=>json_encode(['code'=>'UR-XXXXXXXX-XXXXXXXX'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),
            'response'=>['ok'=>true,'message'=>'兑换成功','membershipUntil'=>'2026-01-01 00:00:00','user'=>['username'=>'demo','membershipUntil'=>'2026-01-01 00:00:00']]
        ],
    ],
    'App 管理' => [
        [
            'id'=>'app_create','method'=>'POST','action'=>'app_create','auth'=>'是',
            'title'=>'创建 App','desc'=>'用户创建自己的 App，后台会生成用于客户端热更新的 AppID。',
            'params'=>[['name','string','必填，App 名称'],['packageName','string','可选，App 包名'],['targetHostPackage','string','可选，目标宿主包名'],['description','string','可选，备注']],
            'returns'=>[['ok','boolean','是否成功'],['app','object','新创建的 App']],
            'request'=>json_encode(['name'=>'我的 App','packageName'=>'com.demo.app','targetHostPackage'=>'com.demo.app','description'=>'测试应用'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),
            'response'=>['ok'=>true,'message'=>'App 创建成功','app'=>['id'=>1,'appid'=>'UA1234567890','app_key'=>'app_xxxxxxxxx','name'=>'我的 App']]
        ],
        [
            'id'=>'app_list','method'=>'GET','action'=>'app_list','auth'=>'是',
            'title'=>'App 列表','desc'=>'获取当前用户可管理的 App。管理员可看到全部 App。',
            'params'=>[],
            'returns'=>[['ok','boolean','是否成功'],['apps','array','App 列表']],
            'request'=>"GET {$base}?action=app_list\nAuthorization: Bearer TOKEN",
            'response'=>['ok'=>true,'message'=>'ok','apps'=>[['id'=>1,'appid'=>'UA1234567890','app_key'=>'app_xxxxxxxxx','name'=>'我的 App']]]
        ],
        [
            'id'=>'app_detail','method'=>'GET','action'=>'app_detail','auth'=>'是',
            'title'=>'App 详情','desc'=>'获取指定 App 详情和版本列表。',
            'params'=>[['appId','number','必填，App ID']],
            'returns'=>[['ok','boolean','是否成功'],['app','object','App 详情'],['versions','array','版本列表']],
            'request'=>"GET {$base}?action=app_detail&appId=1\nAuthorization: Bearer TOKEN",
            'response'=>['ok'=>true,'message'=>'ok','app'=>['id'=>1,'name'=>'我的 App'],'versions'=>[]]
        ],
        [
            'id'=>'app_update','method'=>'POST','action'=>'app_update','auth'=>'是',
            'title'=>'编辑 App','desc'=>'编辑 App 基本信息或禁用更新接口。',
            'params'=>[['appId','number','必填，App ID'],['name','string','App 名称'],['packageName','string','包名'],['targetHostPackage','string','目标宿主包名'],['description','string','备注'],['isDisabled','number','1 禁用，0 启用']],
            'returns'=>[['ok','boolean','是否成功'],['app','object','更新后的 App']],
            'request'=>json_encode(['appId'=>1,'name'=>'新名称','packageName'=>'com.demo.app','isDisabled'=>0], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),
            'response'=>['ok'=>true,'message'=>'App 已更新','app'=>['id'=>1,'name'=>'新名称']]
        ],
        [
            'id'=>'app_delete','method'=>'POST','action'=>'app_delete','auth'=>'是',
            'title'=>'删除 App','desc'=>'删除 App 及其版本数据。',
            'params'=>[['appId','number','必填，App ID']],
            'returns'=>[['ok','boolean','是否成功'],['message','string','响应消息']],
            'request'=>json_encode(['appId'=>1], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),
            'response'=>['ok'=>true,'message'=>'App 已删除']
        ],
        [
            'id'=>'admin_user_assets','method'=>'GET','action'=>'admin_user_assets','auth'=>'管理员',
            'title'=>'查看用户所有 APP 和版本','desc'=>'管理员查看某个用户创建的全部 APP 以及每个 APP 下的全部热更新版本。返回后可继续调用 app_update/app_delete/version_update/version_delete 编辑或删除。',
            'params'=>[['userId','number','必填，用户 ID']],
            'returns'=>[['ok','boolean','是否成功'],['user','object','目标用户资料'],['apps','array','该用户全部 APP，内含 versions 版本数组']],
            'request'=>"GET {$base}?action=admin_user_assets&userId=2\nAuthorization: Bearer ADMIN_TOKEN",
            'response'=>['ok'=>true,'message'=>'ok','user'=>['id'=>2,'username'=>'demo'],'apps'=>[['id'=>1,'appid'=>'UA1234567890','name'=>'DemoApp','versions'=>[['id'=>11,'patch_code'=>2,'patch_name'=>'hotfix-2']]]]]
        ],
    ],
    '热更新版本' => [
        [
            'id'=>'check_update','method'=>'GET','action'=>'check_update','auth'=>'否',
            'title'=>'客户端检查更新','desc'=>'Android 客户端用 AppID 获取当前热更新版本。新版 URlibrary 已固定域名 http://ucore.uaoan.cn，App 端只需要填写 AppID。兼容旧客户端：仍支持 appKey。',
            'params'=>[['appId','string','必填，App 的 AppID；兼容参数 appid / app_id'],['appKey','string','兼容旧客户端，可选']],
            'returns'=>[['enabled','boolean','是否启用更新'],['patchCode','number','补丁版本号'],['patchName','string','补丁名称'],['patchUrl','string','补丁 APK 下载地址'],['sha256','string','补丁 SHA-256'],['message','string','更新说明']],
            'request'=>"GET http://ucore.uaoan.cn/api.php?action=check_update&appId=UA1234567890",
            'response'=>['enabled'=>true,'patchCode'=>2,'patchName'=>'hotfix-2','patchUrl'=>'downloads/patch_x.apk','sha256'=>'abcdef...','autoApply'=>true,'restartAfterApply'=>true,'appId'=>'UA1234567890','message'=>'发现新版本']
        ],
        [
            'id'=>'version_list','method'=>'GET','action'=>'version_list','auth'=>'是',
            'title'=>'版本列表','desc'=>'获取指定 App 的热更新版本列表。',
            'params'=>[['appId','number','必填，App ID']],
            'returns'=>[['ok','boolean','是否成功'],['versions','array','版本列表']],
            'request'=>"GET {$base}?action=version_list&appId=1\nAuthorization: Bearer TOKEN",
            'response'=>['ok'=>true,'message'=>'ok','versions'=>[['id'=>1,'patch_code'=>2,'patch_name'=>'hotfix-2','is_current'=>1]]]
        ],
        [
            'id'=>'version_create','method'=>'POST','action'=>'version_create','auth'=>'是，且需要会员',
            'title'=>'发布热更新版本','desc'=>'multipart/form-data 上传 APK / 补丁包。只有会员或管理员可以发布。',
            'params'=>[['appId','number','必填，App ID'],['patchCode','number','必填，递增版本号'],['patchName','string','版本名'],['patch','file','必填，APK/JAR/DEX/ZIP 文件'],['packageName','string','可留空'],['targetHostPackage','string','目标宿主包名'],['entryClass','string','一般留空'],['entryMethod','string','默认 onLoad'],['mergeDex','number','1 加载 dex'],['restartAfterApply','number','1 下载后重启'],['autoApply','number','1 自动应用'],['setCurrent','number','1 设为当前']],
            'returns'=>[['ok','boolean','是否成功'],['version','object','版本信息']],
            'request'=>"curl -X POST '{$base}?action=version_create' \\\n  -H 'Authorization: Bearer TOKEN' \\\n  -F 'appId=1' -F 'patchCode=2' -F 'patchName=hotfix-2' \\\n  -F 'patch=@app-debug.apk' -F 'setCurrent=1'",
            'response'=>['ok'=>true,'message'=>'版本已发布','version'=>['id'=>2,'patch_code'=>2,'patch_url'=>'downloads/patch_x.apk']]
        ],
        [
            'id'=>'version_update','method'=>'POST','action'=>'version_update','auth'=>'是，且需要会员',
            'title'=>'编辑版本','desc'=>'编辑已发布版本；patch 文件可选，不上传则保留原文件。',
            'params'=>[['versionId','number','必填，版本 ID'],['patchCode','number','版本号'],['patchName','string','版本名'],['patch','file','可选，新补丁文件'],['message','string','更新说明']],
            'returns'=>[['ok','boolean','是否成功'],['version','object','版本信息']],
            'request'=>json_encode(['versionId'=>2,'patchCode'=>3,'patchName'=>'hotfix-3','message'=>'修复问题'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),
            'response'=>['ok'=>true,'message'=>'版本已更新','version'=>['id'=>2,'patch_code'=>3]]
        ],
        [
            'id'=>'version_set_current','method'=>'POST','action'=>'version_set_current','auth'=>'是',
            'title'=>'设为当前版本','desc'=>'把某个版本设为客户端当前可更新版本。',
            'params'=>[['versionId','number','必填，版本 ID']],
            'returns'=>[['ok','boolean','是否成功'],['message','string','响应消息']],
            'request'=>json_encode(['versionId'=>2], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),
            'response'=>['ok'=>true,'message'=>'已设为当前版本']
        ],
        [
            'id'=>'version_delete','method'=>'POST','action'=>'version_delete','auth'=>'是',
            'title'=>'删除版本','desc'=>'删除版本记录，并删除对应本地补丁文件。',
            'params'=>[['versionId','number','必填，版本 ID']],
            'returns'=>[['ok','boolean','是否成功'],['message','string','响应消息']],
            'request'=>json_encode(['versionId'=>2], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),
            'response'=>['ok'=>true,'message'=>'版本已删除']
        ],
    ],
    '管理员接口' => [
        [
            'id'=>'admin_user_list','method'=>'GET','action'=>'admin_user_list','auth'=>'管理员',
            'title'=>'用户列表','desc'=>'管理员查看全部用户。',
            'params'=>[],
            'returns'=>[['ok','boolean','是否成功'],['users','array','用户列表']],
            'request'=>"GET {$base}?action=admin_user_list\nAuthorization: Bearer ADMIN_TOKEN",
            'response'=>['ok'=>true,'message'=>'ok','users'=>[['id'=>1,'username'=>'admin','is_admin'=>1,'is_banned'=>0]]]
        ],
        [
            'id'=>'admin_user_update','method'=>'POST','action'=>'admin_user_update','auth'=>'管理员',
            'title'=>'编辑用户 / 封号 / 设为管理','desc'=>'管理员编辑用户资料，封禁或解封用户，也可设置为管理员。',
            'params'=>[['userId','number','必填，用户 ID'],['username','string','用户名'],['nickname','string','昵称'],['avatarUrl','string','头像 URL'],['email','string','邮箱'],['gender','string','性别'],['birthday','string','生日'],['isAdmin','number','1 管理员，0 普通用户'],['isBanned','number','1 封号，0 正常'],['membershipUntil','string','会员到期时间']],
            'returns'=>[['ok','boolean','是否成功'],['user','object','更新后的用户']],
            'request'=>json_encode(['userId'=>2,'username'=>'demo','email'=>'user@example.com','isAdmin'=>0,'isBanned'=>0,'membershipUntil'=>'2026-01-01 00:00:00'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),
            'response'=>['ok'=>true,'message'=>'用户已更新','user'=>['id'=>2,'username'=>'demo','isAdmin'=>false]]
        ],
        [
            'id'=>'admin_user_delete','method'=>'POST','action'=>'admin_user_delete','auth'=>'管理员',
            'title'=>'删除用户','desc'=>'管理员删除用户，不能删除当前登录的管理员自己。',
            'params'=>[['userId','number','必填，用户 ID']],
            'returns'=>[['ok','boolean','是否成功'],['message','string','响应消息']],
            'request'=>json_encode(['userId'=>2], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),
            'response'=>['ok'=>true,'message'=>'用户已删除']
        ],
        [
            'id'=>'admin_card_batch_create','method'=>'POST','action'=>'admin_card_batch_create','auth'=>'管理员',
            'title'=>'批量生成会员卡密','desc'=>'生成 1 / 30 / 60 / 90 / 365 天会员卡密。',
            'params'=>[['days','number','必填，会员天数'],['count','number','必填，生成数量'],['prefix','string','卡密前缀，默认 UR']],
            'returns'=>[['ok','boolean','是否成功'],['codes','array','生成的卡密'],['days','number','会员天数']],
            'request'=>json_encode(['days'=>30,'count'=>10,'prefix'=>'UR'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),
            'response'=>['ok'=>true,'message'=>'卡密已生成','days'=>30,'codes'=>['UR-ABCD1234-EFGH5678']]
        ],
        [
            'id'=>'admin_card_list','method'=>'GET','action'=>'admin_card_list','auth'=>'管理员',
            'title'=>'卡密列表','desc'=>'查看卡密状态、使用者和使用时间。',
            'params'=>[],
            'returns'=>[['ok','boolean','是否成功'],['cards','array','卡密列表']],
            'request'=>"GET {$base}?action=admin_card_list\nAuthorization: Bearer ADMIN_TOKEN",
            'response'=>['ok'=>true,'message'=>'ok','cards'=>[['code'=>'UR-XXXX','days'=>30,'status'=>'used','used_by_username'=>'demo','used_at'=>'2026-01-01 12:00:00']]]
        ],
        [
            'id'=>'admin_account_update','method'=>'POST','action'=>'admin_account_update','auth'=>'管理员',
            'title'=>'修改默认管理员账号','desc'=>'修改当前登录管理员的用户名、邮箱，密码可选；密码留空则不修改。',
            'params'=>[['username','string','管理员用户名'],['email','string','管理员邮箱'],['newPassword / password','string','可选，新密码，至少 6 位']],
            'returns'=>[['ok','boolean','是否成功'],['user','object','更新后的管理员资料']],
            'request'=>json_encode(['username'=>'admin2','email'=>'admin@example.com','newPassword'=>'123456'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),
            'response'=>['ok'=>true,'message'=>'管理员账号已更新','user'=>['id'=>1,'username'=>'admin2','email'=>'admin@example.com','isAdmin'=>true]]
        ],
        [
            'id'=>'admin_test_email','method'=>'POST','action'=>'admin_test_email','auth'=>'管理员',
            'title'=>'测试发送邮箱','desc'=>'使用当前 SMTP 配置发送一封 iOS 毛玻璃验证码样式的测试邮件。',
            'params'=>[['email','string','必填，接收测试邮件的邮箱']],
            'returns'=>[['ok','boolean','是否成功'],['message','string','响应消息'],['email','string','接收邮箱']],
            'request'=>json_encode(['email'=>'admin@example.com'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),
            'response'=>['ok'=>true,'message'=>'测试邮件已发送','email'=>'admin@example.com']
        ],
        [
            'id'=>'admin_settings_get','method'=>'GET','action'=>'admin_settings_get','auth'=>'管理员',
            'title'=>'读取系统设置','desc'=>'读取站点名称、购买地址和邮箱配置。SMTP 密码不会返回。',
            'params'=>[],
            'returns'=>[['ok','boolean','是否成功'],['settings','object','系统设置']],
            'request'=>"GET {$base}?action=admin_settings_get\nAuthorization: Bearer ADMIN_TOKEN",
            'response'=>['ok'=>true,'message'=>'ok','settings'=>['site_name'=>'UcoreReload','purchase_url'=>'https://example.com/buy','email_enabled'=>'1']]
        ],
        [
            'id'=>'admin_settings_save','method'=>'POST','action'=>'admin_settings_save','auth'=>'管理员',
            'title'=>'保存系统设置 / 邮箱配置','desc'=>'保存购买卡密地址和 SMTP 邮箱参数。smtp_pass 留空则不修改。',
            'params'=>[['site_name','string','站点名称'],['purchase_url','string','购买卡密地址'],['email_enabled','number','1 启用邮箱验证码'],['smtp_host','string','SMTP 地址'],['smtp_port','number','SMTP 端口'],['smtp_secure','string','tls / ssl / none'],['smtp_user','string','SMTP 用户名'],['smtp_pass','string','SMTP 密码'],['smtp_from_email','string','发件邮箱'],['smtp_from_name','string','发件名称']],
            'returns'=>[['ok','boolean','是否成功'],['message','string','响应消息']],
            'request'=>json_encode(['site_name'=>'UcoreReload','purchase_url'=>'https://example.com/buy','email_enabled'=>1,'smtp_host'=>'smtp.example.com','smtp_port'=>587,'smtp_secure'=>'tls','smtp_user'=>'noreply@example.com','smtp_from_email'=>'noreply@example.com','smtp_from_name'=>'UcoreReload'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),
            'response'=>['ok'=>true,'message'=>'设置已保存']
        ],
    ],

    'UcoreReloadsUser 软件' => [
        [
            'id'=>'user_app_config','method'=>'GET','action'=>'user_app_config','auth'=>'否',
            'title'=>'软件公告 / 更新配置','desc'=>'UcoreReloadsUser 启动时调用，返回软件版本更新、下载链接和公告内容。公告 hash 变化后，客户端会再次弹窗。',
            'params'=>[],
            'returns'=>[['ok','boolean','是否成功'],['appUpdate','object','软件更新配置'],['announcement','object','公告配置'],['homepage','object','首页下载信息']],
            'request'=>"GET {$base}?action=user_app_config",
            'response'=>['ok'=>true,'message'=>'ok','appUpdate'=>['versionCode'=>2,'versionName'=>'1.1.0','downloadUrl'=>'http://ucore.uaoan.cn/downloads/ucorereloadsuser.apk','message'=>'优化 UI','forceUpdate'=>false],'announcement'=>['enabled'=>true,'title'=>'公告','content'=>'欢迎使用','hash'=>'md5...','updatedAt'=>'2026-06-27 12:00:00']]
        ],
        [
            'id'=>'admin_user_app_save','method'=>'POST','action'=>'admin_user_app_save','auth'=>'管理员',
            'title'=>'保存软件更新 / 公告','desc'=>'管理员设置 UcoreReloadsUser 版本更新、公告、下载链接；支持 multipart 直接上传 APK，字段名 userAppApk。',
            'params'=>[['user_app_version_code','number','软件版本号'],['user_app_version_name','string','软件版本名'],['user_app_download_url','string','软件下载链接'],['user_app_update_message','string','更新说明'],['user_app_force_update','number','1 强制更新'],['user_app_announcement_enabled','number','1 启用公告'],['user_app_announcement_title','string','公告标题'],['user_app_announcement','string','公告内容'],['userAppApk','file','可选，上传 UcoreReloadsUser APK']],
            'returns'=>[['ok','boolean','是否成功'],['appUpdate','object','最新软件更新配置'],['announcement','object','最新公告配置']],
            'request'=>"curl -X POST '{$base}?action=admin_user_app_save' \
  -H 'Authorization: Bearer ADMIN_TOKEN' \
  -F 'user_app_version_code=2' -F 'user_app_version_name=1.1.0' \
  -F 'userAppApk=@UcoreReloadsUser.apk'",
            'response'=>['ok'=>true,'message'=>'UcoreReloadsUser 软件设置已保存','appUpdate'=>['versionCode'=>2,'downloadUrl'=>'http://ucore.uaoan.cn/downloads/ucorereloadsuser.apk']]
        ],
    ],
];
function method_class($method) { return strtolower(str_replace(' ', '-', $method)); }
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate, max-age=0">
<meta http-equiv="Pragma" content="no-cache">
<meta http-equiv="Expires" content="0">
<title><?= h($siteName) ?> API 文档</title>
<link rel="stylesheet" href="assets/style.css?v=<?= time() ?>">

<style>
.docs-page{overflow-x:hidden}.docs-topbar{width:min(1380px,calc(100% - 32px));margin:14px auto 0;position:sticky;top:10px;z-index:20}.docs-layout{width:min(1380px,calc(100% - 32px));margin:14px auto 60px;display:grid;grid-template-columns:300px minmax(0,1fr);gap:18px;align-items:start}.docs-sidebar{position:sticky;top:96px;max-height:calc(100dvh - 116px);overflow:auto;padding:18px;border-radius:24px}.docs-side-title{font-size:15px;font-weight:900;margin:0 0 14px}.docs-group-title{font-size:13px;color:rgba(55,68,96,.74);font-weight:900;margin:18px 0 8px}.docs-nav-link{display:flex;align-items:center;justify-content:space-between;gap:8px;text-decoration:none;color:rgba(24,32,44,.86);padding:10px 11px;border-radius:16px;margin:3px 0;background:rgba(255,255,255,.38);border:1px solid rgba(255,255,255,.48);font-weight:800}.docs-nav-link:hover{background:rgba(255,255,255,.68);transform:translateY(-1px)}.method-mini,.method-pill{border-radius:999px;padding:4px 8px;font-size:11px;font-weight:950;text-transform:uppercase}.method-mini.get,.method-pill.get{background:rgba(52,199,89,.14);color:#167a3a}.method-mini.post,.method-pill.post{background:rgba(255,149,0,.14);color:#a45b00}.docs-content{display:grid;gap:18px;min-width:0}.docs-hero-card,.endpoint-card{padding:clamp(18px,3vw,30px)}.docs-hero-card h1{font-size:clamp(34px,5vw,56px);letter-spacing:-.06em}.docs-section-heading{margin:14px 0 0;font-size:clamp(26px,4vw,40px)}.endpoint-card{scroll-margin-top:110px}.endpoint-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:14px}.auth-pill{white-space:nowrap;border-radius:999px;padding:8px 12px;background:rgba(255,255,255,.64);border:1px solid rgba(80,90,120,.14);font-weight:900;color:rgba(45,55,75,.70)}.endpoint-url{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:13px 14px;border-radius:16px;background:rgba(255,255,255,.58);border:1px solid rgba(80,90,120,.14);margin:12px 0 22px}.endpoint-url code{font-size:14px;word-break:break-all}.docs-table{margin:10px 0 22px;border-radius:18px;overflow:auto}.docs-table table{min-width:680px;background:rgba(255,255,255,.42)}.code-block{margin:10px 0 22px;padding:18px;border-radius:18px;background:#070b0a;color:#eafff2;overflow:auto;line-height:1.7}.code-block code{color:inherit}.endpoint-card h3{margin:20px 0 8px}.endpoint-card p{line-height:1.65}@media(max-width:920px){.docs-layout{grid-template-columns:1fr}.docs-sidebar{position:relative;top:auto;max-height:none}.endpoint-head,.endpoint-url{flex-direction:column;align-items:flex-start}.docs-topbar{position:relative;top:auto}.docs-topbar nav{width:100%}.docs-topbar nav a{flex:1}}
</style>
</head>
<body class="ios-glass-bg docs-page">
<div class="orb orb-a"></div><div class="orb orb-b"></div><div class="orb orb-c"></div>
<header class="topbar glass-card docs-topbar">
  <div class="brand-row compact"><div class="app-mark small">API</div><div><strong><?= h($siteName) ?> API 文档</strong><span>仅后台管理员可见 · 页面禁用缓存</span></div></div>
  <nav><a href="index.php">返回后台</a><a href="API_DOCS.md?v=<?= time() ?>" target="_blank">Markdown</a></nav>
</header>
<main class="docs-layout">
  <aside class="docs-sidebar glass-card">
    <div class="docs-side-title">接口目录</div>
    <?php foreach($sections as $sectionName => $items): ?>
      <p class="docs-group-title"><?= h($sectionName) ?></p>
      <?php foreach($items as $ep): ?>
        <a class="docs-nav-link" href="#<?= h($ep['id']) ?>"><span><?= h($ep['title']) ?></span><em class="method-mini <?= h(method_class($ep['method'])) ?>"><?= h($ep['method']) ?></em></a>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </aside>
  <section class="docs-content">
    <section class="glass-card docs-hero-card">
      <p class="eyebrow">API Reference</p>
      <h1>接口文档</h1>
      <p class="muted">统一入口：<code><?= h($base) ?></code>。POST 接口支持 <code>application/json</code>、<code>application/x-www-form-urlencoded</code> 和 <code>multipart/form-data</code>；需要权限的接口使用 <code>Authorization: Bearer TOKEN</code>。</p>
    </section>
    <?php foreach($sections as $sectionName => $items): ?>
      <h2 class="docs-section-heading"><?= h($sectionName) ?></h2>
      <?php foreach($items as $ep): ?>
        <article class="glass-card endpoint-card" id="<?= h($ep['id']) ?>">
          <div class="endpoint-head">
            <div><p class="eyebrow"><?= h($sectionName) ?></p><h2><?= h($ep['action']) ?></h2><p class="muted"><?= h($ep['desc']) ?></p></div>
            <span class="auth-pill">权限：<?= h($ep['auth']) ?></span>
          </div>
          <div class="endpoint-url"><span class="method-pill <?= h(method_class($ep['method'])) ?>"><?= h($ep['method']) ?> JSON</span><code>/api.php?action=<?= h($ep['action']) ?></code></div>
          <h3><?= $ep['method'] === 'GET' ? 'Query 参数' : 'Body 参数' ?></h3>
          <div class="table-wrap docs-table"><table><thead><tr><th>参数</th><th>类型</th><th>说明</th></tr></thead><tbody>
          <?php if(!empty($ep['params'])): foreach($ep['params'] as $p): ?><tr><td class="mono"><?= h($p[0]) ?></td><td><?= h($p[1]) ?></td><td><?= h($p[2]) ?></td></tr><?php endforeach; else: ?><tr><td colspan="3" class="muted">无</td></tr><?php endif; ?>
          </tbody></table></div>
          <h3>返回响应</h3>
          <div class="table-wrap docs-table"><table><thead><tr><th>字段</th><th>类型</th><th>说明</th></tr></thead><tbody>
          <?php foreach($ep['returns'] as $r): ?><tr><td class="mono"><?= h($r[0]) ?></td><td><?= h($r[1]) ?></td><td><?= h($r[2]) ?></td></tr><?php endforeach; ?>
          </tbody></table></div>
          <h3>请求示例代码</h3>
          <pre class="code-block"><code><?= h($ep['request']) ?></code></pre>
          <h3>响应示例</h3>
          <pre class="code-block"><code><?= h(json_encode($ep['response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></code></pre>
        </article>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </section>

<section class="glass-card"><h2>Web 主页下载 APP</h2><p>管理员可在系统设置中填写 <code>homepage_app_download_url</code>，或上传 <code>homepageAppApk</code>。公共接口 <code>user_app_config</code> 会在 <code>homepage.downloadUrl</code> 返回该链接，用于 Web 主页“下载APP”按钮。</p></section>
</main>
</body>
</html>

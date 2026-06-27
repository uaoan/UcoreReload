# UcoreReload API 文档

统一入口：`backend/api.php`。需要权限的接口使用请求头：`Authorization: Bearer TOKEN`。POST 接口支持 `application/json`、`application/x-www-form-urlencoded` 与 `multipart/form-data`。

## 系统与认证

### GET /api.php?action=purchase_info
获取站点名称和购买卡密地址。

### POST /api.php?action=send_email_code
发送邮箱验证码。

Body 参数：

| 参数 | 类型 | 说明 |
|---|---|---|
| email | string | 必填，接收验证码的邮箱 |
| purpose | string | `register` 或 `reset_password`，默认 `register` |

说明：`purpose=register` 时会检查邮箱是否已经注册，已注册会返回“该邮箱已注册”。`purpose=reset_password` 时会检查邮箱是否存在，不存在会返回“该邮箱还没有注册”。

请求示例：

```json
{
  "email": "user@example.com",
  "purpose": "register"
}
```

返回响应：

```json
{
  "ok": true,
  "message": "验证码已发送到邮箱",
  "expiresIn": 600,
  "purpose": "register"
}
```

### POST /api.php?action=register
邮箱验证码注册。

Body 参数：`username`、`email`、`password`、`code`。

### POST /api.php?action=login
账号密码登录。`login` 支持用户名或邮箱。

### POST /api.php?action=reset_password
通过邮箱验证码找回密码。先调用 `send_email_code`，并传 `purpose=reset_password`。

Body 参数：

| 参数 | 类型 | 说明 |
|---|---|---|
| email | string | 注册邮箱 |
| code | string | 邮箱验证码 |
| password / newPassword | string | 新密码，至少 6 位 |

请求示例：

```json
{
  "email": "user@example.com",
  "code": "104071",
  "password": "newpass123"
}
```

返回响应：

```json
{
  "ok": true,
  "message": "密码已重置，请使用新密码登录"
}
```

### GET /api.php?action=me
获取当前登录用户信息。返回字段包含：头像、昵称、邮箱、用户名、会员状态、性别、生日。

### GET /api.php?action=profile_get
获取当前登录用户完整资料。返回字段包含：`avatarUrl`、`nickname`、`email`、`username`、`isMember`、`memberStatus`、`membershipUntil`、`gender`、`birthday`。

### POST /api.php?action=profile_update
修改自己的资料。

Body 参数：

| 参数 | 类型 | 说明 |
|---|---|---|
| avatarUrl / avatar_url | string | 头像 URL，支持 http/https |
| nickname | string | 昵称，最多 32 个字符 |
| username | string | 用户名，3-32 位中文/字母/数字/下划线 |
| email | string | 邮箱 |
| gender | string | `male` / `female` / `other` / `secret` / 空 |
| birthday | string | 生日，格式 `YYYY-MM-DD` |

请求示例：

```json
{
  "avatarUrl": "https://example.com/avatar.png",
  "nickname": "Demo",
  "username": "demo",
  "email": "user@example.com",
  "gender": "secret",
  "birthday": "2000-01-01"
}
```

## 用户与会员

### POST /api.php?action=redeem_card
兑换会员卡密。

## App 管理

### POST /api.php?action=app_create
创建 App。

### GET /api.php?action=app_list
获取 App 列表。

### GET /api.php?action=app_detail&appId=1
获取 App 详情和版本列表。

### POST /api.php?action=app_update
编辑 App。

### POST /api.php?action=app_delete
删除 App。

## 热更新版本

### GET /api.php?action=check_update&appId=UA1234567890
客户端检查热更新。

### GET /api.php?action=version_list&appId=1
获取热更新版本列表。

### POST /api.php?action=version_create
发布热更新版本。需要会员。

### POST /api.php?action=version_update
编辑热更新版本。补丁文件可选；不上传则保留原补丁。

### POST /api.php?action=version_set_current
设为当前版本。

### POST /api.php?action=version_delete
删除版本。

## 管理员接口

### GET /api.php?action=admin_user_list
获取用户列表。

### POST /api.php?action=admin_user_update
编辑用户、封号、解封、设为管理员。

### POST /api.php?action=admin_user_delete
删除用户。

### POST /api.php?action=admin_card_batch_create
批量生成会员卡密。

### GET /api.php?action=admin_card_list
查看卡密列表、使用者和使用时间。

### POST /api.php?action=admin_account_update
修改默认管理员账号。

Body 参数：

| 参数 | 类型 | 说明 |
|---|---|---|
| username | string | 管理员用户名 |
| nickname | string | 昵称 |
| avatarUrl | string | 头像 URL |
| gender | string | 性别 |
| birthday | string | 生日 |
| email | string | 管理员邮箱 |
| newPassword / password | string | 可选，新密码，留空不修改 |

### GET /api.php?action=admin_settings_get
读取系统设置。

### POST /api.php?action=admin_settings_save
保存系统设置 / 邮箱配置。

### POST /api.php?action=admin_test_email
测试发送邮箱。

Body 参数：

| 参数 | 类型 | 说明 |
|---|---|---|
| email | string | 接收测试邮件的邮箱 |

返回响应：

```json
{
  "ok": true,
  "message": "测试邮件已发送",
  "email": "admin@example.com"
}
```

## UcoreReloadsUser 软件公告与更新接口

### 获取软件公告 / 版本更新配置

`GET /api.php?action=user_app_config`

无需登录。UcoreReloadsUser 启动时调用，用于判断是否弹出更新窗口、公告窗口，并在 APP 页顶部显示公告走马灯。

响应示例：

```json
{
  "ok": true,
  "message": "ok",
  "appUpdate": {
    "versionCode": 2,
    "versionName": "1.1.0",
    "downloadUrl": "http://ucore.uaoan.cn/downloads/ucorereloadsuser.apk",
    "message": "修复问题并优化 UI",
    "forceUpdate": false
  },
  "announcement": {
    "enabled": true,
    "title": "公告",
    "content": "欢迎使用 UcoreReload",
    "hash": "公告内容标识",
    "updatedAt": "2026-06-27 12:00:00"
  },
  "homepage": {
    "siteName": "UcoreReload",
    "downloadUrl": "http://ucore.uaoan.cn/downloads/ucorereloadsuser.apk",
    "purchaseUrl": "https://..."
  }
}
```

### 管理员保存 UcoreReloadsUser 软件设置

`POST /api.php?action=admin_user_app_save`

需要管理员 `Authorization: Bearer <token>`。支持普通表单，也支持 `multipart/form-data` 直接上传软件 APK。

参数：

| 参数 | 类型 | 说明 |
|---|---|---|
| user_app_version_code | number | UcoreReloadsUser 版本号，客户端用它判断是否需要更新 |
| user_app_version_name | string | 版本名 |
| user_app_download_url | string | 软件下载链接 |
| user_app_update_message | string | 更新说明 |
| user_app_force_update | number | 1 强制更新，0 可选更新 |
| user_app_announcement_enabled | number | 1 启用公告 |
| user_app_announcement_title | string | 公告标题 |
| user_app_announcement | string | 公告内容 |
| userAppApk | file | 可选，直接上传 UcoreReloadsUser APK，上传后自动写入下载链接 |

## APP 管理补充接口

### 编辑 APP 名称和包名

`POST /api.php?action=app_update`

参数：`appId`、`name`、`packageName`、`targetHostPackage`、`description`、`isDisabled`。

### 删除 APP

`POST /api.php?action=app_delete`

参数：`appId`。


### Web 主页下载 APP 设置

管理员可通过 `admin_settings_save` 提交 `homepage_app_download_url`，或在 Web 后台上传 `homepageAppApk` 文件，用于首页“下载APP”按钮。公共接口 `user_app_config` 的 `homepage.downloadUrl` 会返回该链接。


### 管理员查看指定用户所有 APP 和版本

`GET /api.php?action=admin_user_assets&userId=用户ID`

需要管理员 `Authorization: Bearer <token>`。用于 Web 后台和 UcoreReloadsUser 用户管理页面查看某个用户创建的全部 APP，以及每个 APP 下的全部热更新版本。返回的 APP 和版本可以继续通过已有接口编辑或删除：`app_update`、`app_delete`、`version_update`、`version_delete`、`version_set_current`。

响应示例：

```json
{
  "ok": true,
  "user": {"id": 2, "username": "demo"},
  "apps": [
    {
      "id": 1,
      "appid": "UA1234567890",
      "name": "DemoApp",
      "package_name": "com.demo.app",
      "owner_username": "demo",
      "versions": [
        {"id": 11, "patch_code": 2, "patch_name": "hotfix-2", "is_current": 1}
      ]
    }
  ]
}
```

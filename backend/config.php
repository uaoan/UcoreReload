<?php
// UcoreReload 后台基础配置
// 生产环境建议放到 HTTPS 站点并限制 backend 目录访问权限。

// 公网地址：留空时自动用当前访问地址生成 downloads/xxx 链接。
// 示例：https://example.com/ucore-backend
$PUBLIC_BASE_URL = getenv('UCORE_PUBLIC_BASE_URL') ?: '';

// 首次初始化后台会自动创建 admin 管理员账号。
$DEFAULT_ADMIN_USERNAME = getenv('UCORE_ADMIN_USERNAME') ?: 'admin';
$DEFAULT_ADMIN_EMAIL = getenv('UCORE_ADMIN_EMAIL') ?: 'admin@example.com';
$DEFAULT_ADMIN_PASSWORD = getenv('UCORE_ADMIN_PASSWORD') ?: '123456';

// SQLite 数据库文件。请确保 backend 目录可写。
$DB_FILE = __DIR__ . '/ucore_reload.sqlite';
$DOWNLOAD_DIR = __DIR__ . '/downloads';
$SESSION_NAME = 'UCORE_RELOAD_USER';

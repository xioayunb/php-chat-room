# PHP 单文件聊天室

[![PHP Version](https://img.shields.io/badge/PHP-5.6%2B-blue.svg)](https://php.net)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![No Database](https://img.shields.io/badge/Database-None-orange.svg)]()

一个功能完善的单文件PHP聊天室，无需数据库，适合虚拟主机部署。

## 功能特性

- **用户昵称设置** - 支持自定义昵称，本地存储
- **实时消息收发** - AJAX轮询，2秒刷新
- **在线用户列表** - 右侧显示当前在线用户
- **表情包支持** - 内置100+ emoji表情
- **图片发送** - 支持通过URL发送图片
- **聊天记录导出** - 一键导出为txt文件
- **管理员系统** - 密码登录，可删除消息/清空记录
- **禁言功能** - 按IP禁言用户
- **响应式设计** - 适配手机和桌面

## 部署要求

- PHP 5.6+ (推荐7.0+)
- 无需数据库
- 目录写入权限 (755 或 777)

## 安装步骤

1. 将 `index.php` 上传到网站目录
2. 确保目录有写入权限
3. 访问域名即可使用

## 管理员设置

编辑 `index.php` 第14行修改密码：
```php
$admin_password = "你的新密码";
```

默认密码: `admin123`

## 文件说明

| 文件 | 说明 |
|------|------|
| `index.php` | 主程序文件 |
| `messages.json` | 聊天记录 (自动创建) |
| `users.json` | 在线用户数据 (自动创建) |
| `banned.json` | 禁言列表 (自动创建) |

## 技术栈

- **后端**: PHP (单文件，无框架)
- **前端**: HTML5 + CSS3 + JavaScript (原生)
- **数据存储**: JSON文件 (无需数据库)
- **通信**: AJAX轮询

## 安全特性

- XSS防护 (HTML转义)
- 文件锁防止数据竞争
- 管理员权限验证
- IP禁言机制

## 许可证

[MIT License](LICENSE)

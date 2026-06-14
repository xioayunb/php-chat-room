<?php
/**
 * PHP Chat Room - 完善的单文件聊天室
 * 无需数据库，适合虚拟主机部署
 * 新增：邮箱验证码注册功能
 */

session_start();

// 配置文件
$messages_buffer_file = "messages.json";
$users_file = "users.json";
$banned_file = "banned.json";
$email_config_file = "email_config.json";
$email_codes_file = "email_codes.json";
$messages_buffer_size = 200;
$admin_password = "admin123"; // 建议修改此密码

// 创建必要文件
foreach ([$messages_buffer_file, $users_file, $banned_file, $email_config_file, $email_codes_file] as $file) {
    if (!file_exists($file)) {
        file_put_contents($file, json_encode([]));
    }
}

// 读取数据
function read_json($file) {
    $data = file_get_contents($file);
    return $data ? json_decode($data, true) : [];
}

function write_json($file, $data) {
    $fp = fopen($file, "w");
    if (flock($fp, LOCK_EX)) {
        fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE));
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}

// 检查是否被禁言
function is_banned($ip) {
    $banned = read_json($GLOBALS['banned_file']);
    return isset($banned[$ip]) && $banned[$ip] > time();
}

// 获取用户IP
function get_user_ip() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) return $_SERVER['HTTP_CLIENT_IP'];
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return $_SERVER['HTTP_X_FORWARDED_FOR'];
    return $_SERVER['REMOTE_ADDR'];
}

// 发送邮件函数
function send_email($to, $subject, $body) {
    $config = read_json($GLOBALS['email_config_file']);
    if (empty($config['smtp_host']) || empty($config['smtp_user']) || empty($config['smtp_pass'])) {
        return ['success' => false, 'error' => 'SMTP未配置'];
    }
    
    $host = $config['smtp_host'];
    $port = intval($config['smtp_port'] ?? 587);
    $user = $config['smtp_user'];
    $pass = $config['smtp_pass'];
    $from = $config['smtp_from'] ?? $user;
    $from_name = $config['smtp_from_name'] ?? 'PHP聊天室';
    $secure = $config['smtp_secure'] ?? 'tls';
    
    if (function_exists('mail') && empty($host)) {
        $headers = "From: {$from_name} <{$from}>\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $result = mail($to, $subject, $body, $headers);
        return ['success' => $result];
    }
    
    $socket = fsockopen(($secure === 'ssl' ? 'ssl://' : '') . $host, $port, $errno, $errstr, 10);
    if (!$socket) {
        return ['success' => false, 'error' => "连接SMTP失败: $errstr"];
    }
    
    function smtp_cmd($socket, $cmd, $expected = null) {
        if ($cmd) fwrite($socket, $cmd . "\r\n");
        $response = fgets($socket, 512);
        if ($expected && strpos($response, $expected) !== 0) {
            return ['success' => false, 'error' => "SMTP错误: $response"];
        }
        return ['success' => true, 'response' => $response];
    }
    
    smtp_cmd($socket, null, '220');
    smtp_cmd($socket, "EHLO " . gethostname(), '250');
    
    if ($secure === 'tls') {
        smtp_cmd($socket, "STARTTLS", '220');
        stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        smtp_cmd($socket, "EHLO " . gethostname(), '250');
    }
    
    smtp_cmd($socket, "AUTH LOGIN", '334');
    smtp_cmd($socket, base64_encode($user), '334');
    smtp_cmd($socket, base64_encode($pass), '235');
    smtp_cmd($socket, "MAIL FROM:<{$from}>", '250');
    smtp_cmd($socket, "RCPT TO:<{$to}>", '250');
    smtp_cmd($socket, "DATA", '354');
    
    $message = "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
    $message .= "From: =?UTF-8?B?" . base64_encode($from_name) . "?= <{$from}>\r\n";
    $message .= "To: {$to}\r\n";
    $message .= "Content-Type: text/html; charset=UTF-8\r\n";
    $message .= "\r\n" . $body . "\r\n.\r\n";
    
    fwrite($socket, $message);
    $response = fgets($socket, 512);
    
    smtp_cmd($socket, "QUIT", '221');
    fclose($socket);
    
    return ['success' => strpos($response, '250') === 0, 'response' => $response];
}

function generate_code() {
    return str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
}

// 处理API请求
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    if ($_GET['action'] === 'get_messages') {
        $messages = read_json($messages_buffer_file);
        echo json_encode($messages);
        exit;
    }
    
    if ($_GET['action'] === 'get_users') {
        $users = read_json($users_file);
        $now = time();
        $online_users = [];
        foreach ($users as $ip => $user) {
            if ($now - $user['last_active'] < 60) {
                $online_users[] = [
                    'name' => $user['name'],
                    'is_admin' => !empty($user['is_admin']),
                    'join_time' => $user['join_time']
                ];
            }
        }
        echo json_encode($online_users);
        exit;
    }
    
    if ($_GET['action'] === 'get_email_config') {
        $config = read_json($email_config_file);
        echo json_encode([
            'enabled' => !empty($config['enabled']),
            'smtp_host' => $config['smtp_host'] ?? '',
            'smtp_port' => $config['smtp_port'] ?? '587',
            'smtp_user' => $config['smtp_user'] ?? '',
            'smtp_from' => $config['smtp_from'] ?? '',
            'smtp_from_name' => $config['smtp_from_name'] ?? 'PHP聊天室',
            'smtp_secure' => $config['smtp_secure'] ?? 'tls'
        ]);
        exit;
    }
    
    if ($_GET['action'] === 'save_email_config' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_SESSION['is_admin'])) {
            echo json_encode(['success' => false, 'error' => '无权限']);
            exit;
        }
        $input = json_decode(file_get_contents('php://input'), true);
        $config = [
            'enabled' => !empty($input['enabled']),
            'smtp_host' => $input['smtp_host'] ?? '',
            'smtp_port' => $input['smtp_port'] ?? '587',
            'smtp_user' => $input['smtp_user'] ?? '',
            'smtp_pass' => $input['smtp_pass'] ?? '',
            'smtp_from' => $input['smtp_from'] ?? ($input['smtp_user'] ?? ''),
            'smtp_from_name' => $input['smtp_from_name'] ?? 'PHP聊天室',
            'smtp_secure' => $input['smtp_secure'] ?? 'tls'
        ];
        write_json($email_config_file, $config);
        echo json_encode(['success' => true]);
        exit;
    }
    
    if ($_GET['action'] === 'send_verify_code' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $email = isset($input['email']) ? trim($input['email']) : '';
        
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'error' => '邮箱格式不正确']);
            exit;
        }
        
        $config = read_json($email_config_file);
        if (empty($config['enabled'])) {
            echo json_encode(['success' => false, 'error' => '邮箱验证未开启']);
            exit;
        }
        
        $code = generate_code();
        $codes = read_json($email_codes_file);
        $codes[$email] = [
            'code' => $code,
            'time' => time(),
            'used' => false
        ];
        write_json($email_codes_file, $codes);
        
        $subject = 'PHP聊天室 - 邮箱验证码';
        $body = "<h2>PHP聊天室</h2><p>您的验证码是：<strong style='font-size:24px;color:#667eea;'>{$code}</strong></p><p>验证码5分钟内有效，请勿泄露给他人。</p>";
        $result = send_email($email, $subject, $body);
        
        echo json_encode($result);
        exit;
    }
    
    if ($_GET['action'] === 'verify_code' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $email = isset($input['email']) ? trim($input['email']) : '';
        $code = isset($input['code']) ? trim($input['code']) : '';
        
        if (empty($email) || empty($code)) {
            echo json_encode(['success' => false, 'error' => '邮箱和验证码不能为空']);
            exit;
        }
        
        $codes = read_json($email_codes_file);
        if (!isset($codes[$email])) {
            echo json_encode(['success' => false, 'error' => '验证码不存在']);
            exit;
        }
        
        $record = $codes[$email];
        if ($record['used']) {
            echo json_encode(['success' => false, 'error' => '验证码已使用']);
            exit;
        }
        
        if (time() - $record['time'] > 300) {
            echo json_encode(['success' => false, 'error' => '验证码已过期']);
            exit;
        }
        
        if ($record['code'] !== $code) {
            echo json_encode(['success' => false, 'error' => '验证码错误']);
            exit;
        }
        
        $codes[$email]['used'] = true;
        write_json($email_codes_file, $codes);
        $_SESSION['verified_email'] = $email;
        
        echo json_encode(['success' => true]);
        exit;
    }
    
    if ($_GET['action'] === 'send_message' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $name = isset($input['name']) ? trim($input['name']) : '';
        $content = isset($input['content']) ? trim($input['content']) : '';
        $type = isset($input['type']) ? $input['type'] : 'text';
        $ip = get_user_ip();
        
        if (empty($name) || empty($content)) {
            echo json_encode(['success' => false, 'error' => '名称和消息不能为空']);
            exit;
        }
        
        if (is_banned($ip)) {
            echo json_encode(['success' => false, 'error' => '你已被禁言']);
            exit;
        }
        
        $users = read_json($users_file);
        $users[$ip] = [
            'name' => $name,
            'last_active' => time(),
            'join_time' => isset($users[$ip]) ? $users[$ip]['join_time'] : time(),
            'is_admin' => isset($users[$ip]) ? $users[$ip]['is_admin'] : false
        ];
        write_json($users_file, $users);
        
        $messages = read_json($messages_buffer_file);
        $next_id = count($messages) > 0 ? $messages[count($messages) - 1]['id'] + 1 : 0;
        $messages[] = [
            'id' => $next_id,
            'time' => time(),
            'name' => $name,
            'content' => $content,
            'type' => $type,
            'ip' => $ip
        ];
        
        if (count($messages) > $messages_buffer_size) {
            $messages = array_slice($messages, count($messages) - $messages_buffer_size);
        }
        
        write_json($messages_buffer_file, $messages);
        echo json_encode(['success' => true]);
        exit;
    }
    
    if ($_GET['action'] === 'heartbeat' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $name = isset($input['name']) ? trim($input['name']) : '';
        $ip = get_user_ip();
        
        if (!empty($name)) {
            $users = read_json($users_file);
            $users[$ip] = [
                'name' => $name,
                'last_active' => time(),
                'join_time' => isset($users[$ip]) ? $users[$ip]['join_time'] : time(),
                'is_admin' => isset($users[$ip]) ? $users[$ip]['is_admin'] : false
            ];
            write_json($users_file, $users);
        }
        echo json_encode(['success' => true]);
        exit;
    }
    
    if ($_GET['action'] === 'admin_login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $password = isset($input['password']) ? $input['password'] : '';
        
        if ($password === $admin_password) {
            $ip = get_user_ip();
            $users = read_json($users_file);
            if (isset($users[$ip])) {
                $users[$ip]['is_admin'] = true;
                write_json($users_file, $users);
            }
            $_SESSION['is_admin'] = true;
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => '密码错误']);
        }
        exit;
    }
    
    if ($_GET['action'] === 'delete_message' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_SESSION['is_admin'])) {
            echo json_encode(['success' => false, 'error' => '无权限']);
            exit;
        }
        $input = json_decode(file_get_contents('php://input'), true);
        $msg_id = isset($input['id']) ? intval($input['id']) : -1;
        
        $messages = read_json($messages_buffer_file);
        $messages = array_filter($messages, function($msg) use ($msg_id) {
            return $msg['id'] !== $msg_id;
        });
        $messages = array_values($messages);
        write_json($messages_buffer_file, $messages);
        echo json_encode(['success' => true]);
        exit;
    }
    
    if ($_GET['action'] === 'clear_messages' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_SESSION['is_admin'])) {
            echo json_encode(['success' => false, 'error' => '无权限']);
            exit;
        }
        write_json($messages_buffer_file, []);
        echo json_encode(['success' => true]);
        exit;
    }
    
    exit;
}

$default_name = isset($_SESSION['chat_name']) ? $_SESSION['chat_name'] : '用户' . rand(1000, 9999);
$email_config = read_json($email_config_file);
$email_enabled = !empty($email_config['enabled']);
$verified_email = isset($_SESSION['verified_email']) ? $_SESSION['verified_email'] : '';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHP 聊天室</title>
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@500;700;800&family=Work+Sans:ital,wght@0,300;0,400;0,500;0,600;1,400&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-void: #05050d;
            --bg-deep: #0a0a18;
            --bg-surface: rgba(255,255,255,0.02);
            --bg-glass: rgba(18,18,42,0.65);
            --accent-blue: #4dabf7;
            --accent-blue-dim: rgba(77,171,247,0.15);
            --accent-amber: #ffb347;
            --accent-amber-dim: rgba(255,179,71,0.12);
            --accent-rose: #f06595;
            --text-primary: #e9ecef;
            --text-secondary: #868e96;
            --text-muted: #495057;
            --border-glass: rgba(255,255,255,0.06);
            --border-active: rgba(77,171,247,0.35);
            --font-display: 'Syne', sans-serif;
            --font-body: 'Work Sans', sans-serif;
            --font-mono: 'JetBrains Mono', monospace;
            --radius-xs: 6px;
            --radius-sm: 10px;
            --radius-md: 16px;
            --radius-lg: 24px;
            --radius-xl: 32px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: var(--font-body);
            font-weight: 400;
            background: var(--bg-void);
            color: var(--text-primary);
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            overflow: hidden;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        /* Ambient background mesh */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background:
                radial-gradient(ellipse 80% 60% at 15% 10%, rgba(77,171,247,0.06) 0%, transparent 55%),
                radial-gradient(ellipse 60% 80% at 85% 90%, rgba(255,179,71,0.05) 0%, transparent 55%),
                radial-gradient(ellipse 50% 50% at 50% 50%, rgba(240,101,149,0.03) 0%, transparent 70%);
            pointer-events: none;
            z-index: 0;
        }

        /* Subtle noise grain */
        body::after {
            content: '';
            position: fixed;
            inset: 0;
            opacity: 0.03;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
            background-size: 200px 200px;
            pointer-events: none;
            z-index: 0;
        }

        .chat-container {
            width: 100%;
            max-width: 1040px;
            height: 94vh;
            background: var(--bg-glass);
            backdrop-filter: blur(60px) saturate(140%);
            -webkit-backdrop-filter: blur(60px) saturate(140%);
            border: 1px solid var(--border-glass);
            border-radius: var(--radius-xl);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            position: relative;
            z-index: 1;
            box-shadow:
                0 0 0 1px rgba(255,255,255,0.03) inset,
                0 30px 100px rgba(0,0,0,0.5),
                0 0 120px rgba(77,171,247,0.04);
        }

        .chat-header {
            padding: 18px 28px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border-glass);
            background: rgba(10,10,24,0.5);
            flex-shrink: 0;
        }

        .chat-header .brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .chat-header .brand-logo {
            width: 36px;
            height: 36px;
            border-radius: var(--radius-sm);
            background: linear-gradient(135deg, var(--accent-blue) 0%, var(--accent-rose) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1em;
            box-shadow: 0 0 20px rgba(77,171,247,0.3);
        }

        .chat-header h1 {
            font-family: var(--font-display);
            font-size: 1.25em;
            font-weight: 800;
            letter-spacing: -0.02em;
            color: var(--text-primary);
        }

        .online-count {
            font-family: var(--font-mono);
            font-size: 0.78em;
            color: var(--accent-blue);
            margin-top: 1px;
            font-weight: 400;
        }

        .header-actions {
            display: flex;
            gap: 8px;
        }

        .btn-ghost {
            background: transparent;
            border: 1px solid var(--border-glass);
            color: var(--text-secondary);
            padding: 8px 16px;
            border-radius: 9999px;
            cursor: pointer;
            font-size: 0.8em;
            font-family: var(--font-body);
            font-weight: 500;
            letter-spacing: 0.01em;
            transition: all 0.25s ease;
            white-space: nowrap;
        }

        .btn-ghost:hover {
            background: rgba(77,171,247,0.1);
            border-color: var(--border-active);
            color: var(--accent-blue);
            box-shadow: 0 0 16px rgba(77,171,247,0.12);
        }

        .chat-body {
            flex: 1;
            display: flex;
            overflow: hidden;
            min-height: 0;
        }

        .messages-area {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            min-width: 0;
        }

        .messages-list {
            flex: 1;
            overflow-y: auto;
            padding: 24px 28px;
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 6px;
            scrollbar-width: thin;
            scrollbar-color: rgba(77,171,247,0.2) transparent;
        }

        .messages-list::-webkit-scrollbar { width: 5px; }
        .messages-list::-webkit-scrollbar-track { background: transparent; }
        .messages-list::-webkit-scrollbar-thumb {
            background: rgba(77,171,247,0.25);
            border-radius: 10px;
        }
        .messages-list::-webkit-scrollbar-thumb:hover {
            background: rgba(77,171,247,0.5);
        }

        .message-item {
            padding: 10px 16px;
            border-radius: var(--radius-sm);
            background: rgba(255,255,255,0.025);
            border: 1px solid var(--border-glass);
            max-width: 72%;
            word-wrap: break-word;
            align-self: flex-start;
            animation: msgEnter 0.45s cubic-bezier(0.16, 1, 0.3, 1);
            transition: background 0.2s ease, border-color 0.2s ease;
            position: relative;
        }

        .message-item:hover {
            background: rgba(255,255,255,0.05);
            border-color: rgba(255,255,255,0.12);
        }

        .message-item.own {
            align-self: flex-end;
            background: var(--accent-blue-dim);
            border-color: rgba(77,171,247,0.2);
        }

        .message-item.own:hover {
            background: rgba(77,171,247,0.22);
            border-color: rgba(77,171,247,0.35);
        }

        .message-item.admin {
            background: var(--accent-amber-dim);
            border-left: 3px solid var(--accent-amber);
            border-color: rgba(255,179,71,0.2);
        }

        .message-header {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            margin-bottom: 4px;
        }

        .message-name {
            font-family: var(--font-display);
            font-weight: 700;
            font-size: 0.78em;
            letter-spacing: 0.02em;
            color: var(--accent-blue);
        }

        .message-item.own .message-name { color: var(--accent-blue); }
        .message-item.admin .message-name { color: var(--accent-amber); }

        .message-time {
            font-family: var(--font-mono);
            font-size: 0.65em;
            color: var(--text-muted);
            margin-left: auto;
            padding-left: 12px;
            white-space: nowrap;
        }

        .message-content {
            font-size: 0.9em;
            line-height: 1.6;
            color: var(--text-primary);
            font-weight: 400;
        }

        .message-content a {
            color: var(--accent-blue);
            text-decoration: none;
            border-bottom: 1px solid rgba(77,171,247,0.3);
            transition: border-color 0.2s;
        }

        .message-content a:hover {
            border-color: var(--accent-blue);
        }

        .message-content img {
            max-width: 100%;
            border-radius: var(--radius-xs);
            margin-top: 8px;
            border: 1px solid var(--border-glass);
        }

        .input-area {
            padding: 14px 28px 18px;
            border-top: 1px solid var(--border-glass);
            background: rgba(10,10,24,0.4);
            flex-shrink: 0;
            position: relative;
        }

        .toolbar {
            display: flex;
            gap: 6px;
            margin-bottom: 10px;
        }

        .toolbar button {
            background: rgba(255,255,255,0.03);
            border: 1px solid var(--border-glass);
            padding: 5px 12px;
            border-radius: 9999px;
            cursor: pointer;
            font-size: 0.78em;
            font-family: var(--font-body);
            font-weight: 500;
            color: var(--text-secondary);
            transition: all 0.25s ease;
        }

        .toolbar button:hover {
            background: rgba(77,171,247,0.12);
            border-color: rgba(77,171,247,0.3);
            color: var(--accent-blue);
            transform: translateY(-1px);
        }

        .input-row {
            display: flex;
            gap: 10px;
        }

        .input-row input[type="text"] {
            flex: 1;
            padding: 13px 20px;
            background: rgba(255,255,255,0.04);
            border: 1px solid var(--border-glass);
            border-radius: 9999px;
            font-size: 0.9em;
            font-family: var(--font-body);
            font-weight: 400;
            color: var(--text-primary);
            outline: none;
            transition: all 0.3s ease;
        }

        .input-row input[type="text"]::placeholder {
            color: var(--text-muted);
            font-style: italic;
        }

        .input-row input[type="text"]:focus {
            border-color: var(--accent-blue);
            background: rgba(77,171,247,0.06);
            box-shadow: 0 0 0 4px rgba(77,171,247,0.08);
        }

        .btn-send {
            padding: 13px 28px;
            background: linear-gradient(135deg, var(--accent-blue) 0%, #3b82f6 100%);
            color: #fff;
            border: none;
            border-radius: 9999px;
            cursor: pointer;
            font-size: 0.88em;
            font-family: var(--font-display);
            font-weight: 700;
            letter-spacing: 0.03em;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .btn-send::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, transparent 0%, rgba(255,255,255,0.2) 50%, transparent 100%);
            transform: translateX(-100%);
            transition: transform 0.6s ease;
        }

        .btn-send:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(77,171,247,0.35);
        }

        .btn-send:hover::before {
            transform: translateX(100%);
        }

        .btn-send:active {
            transform: translateY(0) scale(0.97);
        }

        /* Sidebar */
        .sidebar {
            width: 230px;
            border-left: 1px solid var(--border-glass);
            background: rgba(8,8,20,0.35);
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
        }

        .sidebar-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-glass);
            font-family: var(--font-display);
            font-weight: 700;
            font-size: 0.82em;
            letter-spacing: 0.04em;
            color: var(--text-secondary);
            text-transform: uppercase;
        }

        .users-list {
            flex: 1;
            overflow-y: auto;
            padding: 10px 12px;
            list-style: none;
            scrollbar-width: thin;
            scrollbar-color: rgba(77,171,247,0.15) transparent;
        }

        .users-list::-webkit-scrollbar { width: 3px; }
        .users-list::-webkit-scrollbar-thumb {
            background: rgba(77,171,247,0.2);
            border-radius: 10px;
        }

        .user-item {
            padding: 9px 12px;
            border-radius: var(--radius-xs);
            margin-bottom: 3px;
            font-size: 0.82em;
            font-weight: 400;
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--text-secondary);
            transition: all 0.2s ease;
            border: 1px solid transparent;
        }

        .user-item:hover {
            background: rgba(255,255,255,0.03);
            border-color: var(--border-glass);
            color: var(--text-primary);
        }

        .user-item .dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--accent-blue);
            box-shadow: 0 0 8px rgba(77,171,247,0.5);
            flex-shrink: 0;
            animation: breathe 2s ease-in-out infinite;
        }

        .user-item.admin .dot {
            background: var(--accent-amber);
            box-shadow: 0 0 8px rgba(255,179,71,0.5);
            animation: breathe 1.5s ease-in-out infinite;
        }

        .user-item.admin {
            background: var(--accent-amber-dim);
            border-color: rgba(255,179,71,0.2);
            color: var(--accent-amber);
            font-weight: 500;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(5,5,13,0.85);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }

        .modal.show { display: flex; }

        .modal-content {
            background: var(--bg-deep);
            padding: 32px;
            border-radius: var(--radius-lg);
            width: 90%;
            max-width: 440px;
            border: 1px solid var(--border-glass);
            box-shadow: 0 30px 80px rgba(0,0,0,0.6), 0 0 0 1px rgba(255,255,255,0.03) inset;
            animation: modalEnter 0.35s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .modal-content h3 {
            font-family: var(--font-display);
            font-size: 1.15em;
            font-weight: 700;
            letter-spacing: -0.01em;
            margin-bottom: 20px;
            color: var(--text-primary);
        }

        .modal-content input,
        .modal-content select {
            width: 100%;
            padding: 12px 16px;
            background: rgba(255,255,255,0.04);
            border: 1px solid var(--border-glass);
            border-radius: var(--radius-sm);
            margin-bottom: 10px;
            font-size: 0.92em;
            font-family: var(--font-body);
            color: var(--text-primary);
            outline: none;
            transition: all 0.3s ease;
        }

        .modal-content input:focus,
        .modal-content select:focus {
            border-color: var(--accent-blue);
            box-shadow: 0 0 0 3px rgba(77,171,247,0.08);
        }

        .modal-content input::placeholder {
            color: var(--text-muted);
        }

        .btn-primary {
            width: 100%;
            padding: 13px;
            background: linear-gradient(135deg, var(--accent-blue), #3b82f6);
            color: #fff;
            border: none;
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-size: 0.9em;
            font-family: var(--font-display);
            font-weight: 700;
            letter-spacing: 0.02em;
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(77,171,247,0.3);
        }

        .btn-secondary {
            width: 100%;
            padding: 13px;
            background: rgba(255,255,255,0.04);
            color: var(--text-secondary);
            border: 1px solid var(--border-glass);
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-size: 0.9em;
            font-family: var(--font-display);
            font-weight: 500;
            margin-top: 8px;
            transition: all 0.3s ease;
        }

        .btn-secondary:hover {
            background: rgba(77,171,247,0.08);
            border-color: rgba(77,171,247,0.3);
            color: var(--accent-blue);
        }

        .btn-danger {
            background: rgba(240,101,149,0.15);
            color: var(--accent-rose);
            border: 1px solid rgba(240,101,149,0.3);
        }

        .btn-danger:hover {
            background: rgba(240,101,149,0.25);
            box-shadow: 0 8px 25px rgba(240,101,149,0.2);
        }

        .system-message {
            text-align: center;
            color: var(--text-muted);
            font-size: 0.8em;
            padding: 20px;
            font-style: italic;
            font-family: var(--font-mono);
            align-self: center;
        }

        .admin-badge {
            background: linear-gradient(135deg, var(--accent-amber), #f08c00);
            color: #fff;
            font-size: 0.65em;
            padding: 2px 7px;
            border-radius: 9999px;
            margin-left: 4px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            font-family: var(--font-display);
        }

        .delete-btn {
            background: none;
            border: 1px solid rgba(240,101,149,0.3);
            color: var(--accent-rose);
            padding: 2px 8px;
            border-radius: 9999px;
            font-size: 0.7em;
            cursor: pointer;
            margin-left: 8px;
            transition: all 0.2s ease;
            font-family: var(--font-display);
            font-weight: 700;
        }

        .delete-btn:hover {
            background: rgba(240,101,149,0.2);
            box-shadow: 0 0 12px rgba(240,101,149,0.3);
        }

        /* Emoji */
        .emoji-panel {
            display: none;
            position: absolute;
            bottom: 100%;
            left: 28px;
            margin-bottom: 8px;
            background: var(--bg-deep);
            border: 1px solid var(--border-glass);
            border-radius: var(--radius-md);
            padding: 12px;
            box-shadow: 0 16px 48px rgba(0,0,0,0.5);
            z-index: 100;
            max-width: 320px;
            backdrop-filter: blur(30px);
            -webkit-backdrop-filter: blur(30px);
        }

        .emoji-panel.show { display: block; }

        .emoji-grid {
            display: grid;
            grid-template-columns: repeat(8, 1fr);
            gap: 5px;
        }

        .emoji-grid span {
            cursor: pointer;
            font-size: 1.25em;
            padding: 5px;
            text-align: center;
            border-radius: var(--radius-xs);
            transition: all 0.15s ease;
        }

        .emoji-grid span:hover {
            background: var(--accent-blue-dim);
            transform: scale(1.25);
        }

        /* Email verify */
        .email-verify-row {
            display: flex;
            gap: 8px;
            margin-bottom: 8px;
        }

        .email-verify-row input {
            flex: 1;
            background: rgba(255,255,255,0.04);
            border: 1px solid var(--border-glass);
            border-radius: var(--radius-sm);
            padding: 10px 14px;
            color: var(--text-primary);
            outline: none;
            font-family: var(--font-body);
            transition: all 0.3s ease;
        }

        .email-verify-row input:focus {
            border-color: var(--accent-blue);
            box-shadow: 0 0 0 3px rgba(77,171,247,0.08);
        }

        .email-verify-row button {
            white-space: nowrap;
            padding: 10px 14px;
            background: linear-gradient(135deg, var(--accent-blue), #3b82f6);
            color: #fff;
            border: none;
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-weight: 600;
            font-family: var(--font-display);
            font-size: 0.85em;
            transition: all 0.3s ease;
        }

        .email-verify-row button:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 20px rgba(77,171,247,0.3);
        }

        .email-verify-row button:disabled {
            background: rgba(255,255,255,0.06);
            color: var(--text-muted);
            cursor: not-allowed;
            box-shadow: none;
            transform: none;
        }

        .countdown {
            color: var(--text-muted);
            font-size: 0.78em;
            font-family: var(--font-mono);
        }

        /* Settings */
        .settings-panel {
            display: none;
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid var(--border-glass);
        }
        .settings-panel.show { display: block; }

        .form-group {
            margin-bottom: 12px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-size: 0.8em;
            color: var(--text-secondary);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            font-family: var(--font-display);
        }

        .toggle-row {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 14px;
        }

        .toggle-row input[type="checkbox"] {
            width: 44px;
            height: 24px;
            appearance: none;
            background: rgba(255,255,255,0.08);
            border: 1px solid var(--border-glass);
            border-radius: 24px;
            position: relative;
            cursor: pointer;
            transition: all 0.3s ease;
            flex-shrink: 0;
        }

        .toggle-row input[type="checkbox"]:checked {
            background: var(--accent-blue);
            border-color: var(--accent-blue);
        }

        .toggle-row input[type="checkbox"]::after {
            content: '';
            position: absolute;
            width: 18px;
            height: 18px;
            background: #fff;
            border-radius: 50%;
            top: 2px;
            left: 2px;
            transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        .toggle-row input[type="checkbox"]:checked::after {
            transform: translateX(20px);
        }

        .toggle-row label {
            color: var(--text-secondary);
            font-size: 0.9em;
        }

        hr {
            border: none;
            border-top: 1px solid var(--border-glass);
            margin: 15px 0;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 10px 14px;
            background: rgba(255,255,255,0.04);
            border: 1px solid var(--border-glass);
            border-radius: var(--radius-sm);
            font-size: 0.9em;
            font-family: var(--font-body);
            color: var(--text-primary);
            outline: none;
        }

        .form-group input:focus,
        .form-group select:focus {
            border-color: var(--accent-blue);
        }

        .form-group select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23868e96' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
        }

        /* Animations */
        @keyframes msgEnter {
            from { opacity: 0; transform: translateY(16px) scale(0.96); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        @keyframes modalEnter {
            from { opacity: 0; transform: translateY(40px) scale(0.93); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        @keyframes breathe {
            0%, 100% { opacity: 0.6; transform: scale(1); }
            50% { opacity: 1; transform: scale(1.3); }
        }

        @media (max-width: 640px) {
            .sidebar { display: none; }
            .chat-container { height: 100vh; border-radius: 0; max-width: 100%; }
            .chat-header { padding: 14px 16px; }
            .messages-list { padding: 16px; }
            .input-area { padding: 10px 16px 14px; }
            .message-item { max-width: 88%; }
            .btn-ghost { padding: 6px 12px; font-size: 0.75em; }
        }
    </style>
</head>
<body>
    <div class="chat-container">
        <div class="chat-header">
            <div class="brand">
                <div class="brand-logo">⬡</div>
                <div>
                    <h1>Lumen Chat</h1>
                    <div class="online-count" id="onlineCount">0 在线</div>
                </div>
            </div>
            <div class="header-actions">
                <button class="btn-ghost" onclick="showNameModal()">昵称</button>
                <button class="btn-ghost" onclick="showAdminModal()">管理</button>
            </div>
        </div>

        <div class="chat-body">
            <div class="messages-area">
                <ul class="messages-list" id="messagesList">
                    <li class="system-message">连接中...</li>
                </ul>

                <div class="input-area">
                    <div class="toolbar">
                        <button onclick="toggleEmoji()">表情</button>
                        <button onclick="insertImage()">图片</button>
                        <button onclick="exportChat()">导出</button>
                    </div>

                    <div class="emoji-panel" id="emojiPanel">
                        <div class="emoji-grid" id="emojiGrid"></div>
                    </div>

                    <div class="input-row">
                        <input type="text" id="messageInput" placeholder="输入消息..." maxlength="500" onkeypress="if(event.key==='Enter')sendMessage()">
                        <button class="btn-send" onclick="sendMessage()">发送</button>
                    </div>
                </div>
            </div>

            <aside class="sidebar">
                <div class="sidebar-header">在线</div>
                <ul class="users-list" id="usersList"></ul>
            </aside>
        </div>
    </div>

    <!-- Name Modal -->
    <div class="modal" id="nameModal">
        <div class="modal-content">
            <h3>设置昵称</h3>
            <input type="text" id="nameInput" placeholder="你的昵称" maxlength="20" value="<?php echo htmlspecialchars($default_name); ?>">

            <div id="emailVerifySection" style="display:none;">
                <hr>
                <p style="font-size:0.82em;color:var(--text-muted);margin-bottom:10px;">需要邮箱验证后才能聊天</p>
                <div class="email-verify-row">
                    <input type="email" id="emailInput" placeholder="邮箱地址">
                    <button id="sendCodeBtn" onclick="sendVerifyCode()">发送验证码</button>
                </div>
                <div class="email-verify-row">
                    <input type="text" id="codeInput" placeholder="6位验证码" maxlength="6">
                    <button onclick="verifyCode()">验证</button>
                </div>
                <p id="emailStatus" class="countdown"></p>
            </div>

            <button class="btn-primary" onclick="saveName()" style="margin-top:8px;">确定</button>
        </div>
    </div>

    <!-- Admin Modal -->
    <div class="modal" id="adminModal">
        <div class="modal-content">
            <h3>管理员</h3>
            <input type="password" id="adminPassword" placeholder="管理员密码">
            <button class="btn-primary" onclick="adminLogin()">登录</button>

            <div id="adminPanel" style="display:none; margin-top:16px;">
                <button class="btn-primary btn-danger" onclick="clearAllMessages()">清空所有消息</button>
                <button class="btn-secondary" onclick="toggleSettings()" style="margin-bottom:8px;">邮箱设置</button>

                <div class="settings-panel" id="settingsPanel">
                    <div class="toggle-row">
                        <input type="checkbox" id="emailEnabled">
                        <label>启用邮箱验证码</label>
                    </div>

                    <div class="form-group">
                        <label>SMTP服务器</label>
                        <input type="text" id="smtpHost" placeholder="smtp.qq.com">
                    </div>
                    <div class="form-group">
                        <label>端口</label>
                        <input type="text" id="smtpPort" value="587">
                    </div>
                    <div class="form-group">
                        <label>加密</label>
                        <select id="smtpSecure">
                            <option value="tls">TLS</option>
                            <option value="ssl">SSL</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>账号</label>
                        <input type="text" id="smtpUser" placeholder="your@email.com">
                    </div>
                    <div class="form-group">
                        <label>密码/授权码</label>
                        <input type="password" id="smtpPass" placeholder="授权码">
                    </div>
                    <div class="form-group">
                        <label>发件人名称</label>
                        <input type="text" id="smtpFromName" value="Lumen Chat">
                    </div>

                    <button class="btn-primary" onclick="saveEmailConfig()">保存配置</button>
                    <button class="btn-secondary" onclick="testEmailConfig()">测试发送</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Image Modal -->
    <div class="modal" id="imageModal">
        <div class="modal-content">
            <h3>发送图片</h3>
            <input type="text" id="imageUrl" placeholder="图片URL地址">
            <button class="btn-primary" onclick="sendImage()">发送</button>
        </div>
    </div>

    <script>
        let userName = localStorage.getItem('chat_name') || '<?php echo htmlspecialchars($default_name); ?>';
        let isAdmin = localStorage.getItem('is_admin') === 'true';
        let lastMessageId = -1;
        let messages = [];
        let emailEnabled = <?php echo $email_enabled ? 'true' : 'false'; ?>;
        let verifiedEmail = '<?php echo $verified_email; ?>';
        let countdownTimer = null;

        const emojis = ['😀','😃','😄','😁','😅','😂','🤣','😊','😇','🙂','🙃','😉','😌','😍','🥰','😘','😗','😙','😚','😋','😛','😝','😜','🤪','🤨','🧐','🤓','😎','🥸','🤩','🥳','😏','😒','😞','😔','😟','😕','🙁','☹️','😣','😖','😫','😩','🥺','😢','😭','😤','😠','😡','🤬','🤯','😳','🥵','🥶','😱','😨','😰','😥','😓','🤗','🤔','🤭','🤫','🤥','😶','😐','😑','😬','🙄','😯','😦','😧','😮','😲','🥱','😴','🤤','😪','😵','🤐','🥴','🤢','🤮','🤧','😷','🤒','🤕','🤑','🤠','😈','👿','👹','👺','🤡','💩','👻','💀','☠️','👽','👾','🤖','🎃','😺','😸','😹','😻','😼','😽','🙀','😿','😾'];

        document.addEventListener('DOMContentLoaded', () => {
            initEmojiPanel();
            pollMessages();
            pollUsers();
            heartbeat();
            setInterval(pollMessages, 2000);
            setInterval(pollUsers, 5000);
            setInterval(heartbeat, 30000);
            if (emailEnabled && !verifiedEmail) {
                document.getElementById('emailVerifySection').style.display = 'block';
            }
        });

        function initEmojiPanel() {
            const grid = document.getElementById('emojiGrid');
            emojis.forEach(emoji => {
                const span = document.createElement('span');
                span.textContent = emoji;
                span.onclick = () => insertEmoji(emoji);
                grid.appendChild(span);
            });
        }

        function toggleEmoji() {
            document.getElementById('emojiPanel').classList.toggle('show');
        }

        function insertEmoji(emoji) {
            const input = document.getElementById('messageInput');
            input.value += emoji;
            input.focus();
            document.getElementById('emojiPanel').classList.remove('show');
        }

        function showNameModal() {
            document.getElementById('nameModal').classList.add('show');
            document.getElementById('nameInput').value = userName;
            if (emailEnabled && !verifiedEmail) {
                document.getElementById('emailVerifySection').style.display = 'block';
            } else {
                document.getElementById('emailVerifySection').style.display = 'none';
            }
        }

        function saveName() {
            const name = document.getElementById('nameInput').value.trim();
            if (name) {
                userName = name;
                localStorage.setItem('chat_name', name);
                document.getElementById('nameModal').classList.remove('show');
            }
        }

        async function sendVerifyCode() {
            const email = document.getElementById('emailInput').value.trim();
            const btn = document.getElementById('sendCodeBtn');
            if (!email) { alert('请输入邮箱地址'); return; }
            btn.disabled = true;
            document.getElementById('emailStatus').textContent = '发送中...';
            const response = await fetch('?action=send_verify_code', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email })
            });
            const data = await response.json();
            if (data.success) {
                document.getElementById('emailStatus').textContent = '验证码已发送';
                startCountdown(60);
            } else {
                document.getElementById('emailStatus').textContent = data.error || '发送失败';
                btn.disabled = false;
            }
        }

        function startCountdown(seconds) {
            const btn = document.getElementById('sendCodeBtn');
            let remaining = seconds;
            countdownTimer = setInterval(() => {
                remaining--;
                btn.textContent = `${remaining}s`;
                if (remaining <= 0) {
                    clearInterval(countdownTimer);
                    btn.disabled = false;
                    btn.textContent = '发送验证码';
                }
            }, 1000);
        }

        async function verifyCode() {
            const email = document.getElementById('emailInput').value.trim();
            const code = document.getElementById('codeInput').value.trim();
            if (!email || !code) { alert('请输入邮箱和验证码'); return; }
            const response = await fetch('?action=verify_code', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email, code })
            });
            const data = await response.json();
            if (data.success) {
                verifiedEmail = email;
                document.getElementById('emailStatus').textContent = '验证成功';
                setTimeout(() => { document.getElementById('emailVerifySection').style.display = 'none'; }, 1200);
            } else {
                document.getElementById('emailStatus').textContent = data.error || '验证失败';
            }
        }

        function showAdminModal() {
            document.getElementById('adminModal').classList.add('show');
            if (isAdmin) {
                document.getElementById('adminPanel').style.display = 'block';
                loadEmailConfig();
            }
        }

        async function loadEmailConfig() {
            const response = await fetch('?action=get_email_config');
            const config = await response.json();
            document.getElementById('emailEnabled').checked = config.enabled;
            document.getElementById('smtpHost').value = config.smtp_host;
            document.getElementById('smtpPort').value = config.smtp_port;
            document.getElementById('smtpSecure').value = config.smtp_secure;
            document.getElementById('smtpUser').value = config.smtp_user;
            document.getElementById('smtpFromName').value = config.smtp_from_name;
        }

        function toggleSettings() { document.getElementById('settingsPanel').classList.toggle('show'); }

        async function saveEmailConfig() {
            const config = {
                enabled: document.getElementById('emailEnabled').checked,
                smtp_host: document.getElementById('smtpHost').value,
                smtp_port: document.getElementById('smtpPort').value,
                smtp_secure: document.getElementById('smtpSecure').value,
                smtp_user: document.getElementById('smtpUser').value,
                smtp_pass: document.getElementById('smtpPass').value,
                smtp_from: document.getElementById('smtpUser').value,
                smtp_from_name: document.getElementById('smtpFromName').value
            };
            const response = await fetch('?action=save_email_config', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(config)
            });
            const data = await response.json();
            alert(data.success ? '配置已保存' : (data.error || '保存失败'));
            emailEnabled = config.enabled;
        }

        async function testEmailConfig() {
            const email = prompt('测试接收邮箱:');
            if (!email) return;
            await saveEmailConfig();
            const response = await fetch('?action=send_verify_code', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email })
            });
            const data = await response.json();
            alert(data.success ? '测试邮件已发送' : (data.error || '发送失败'));
        }

        async function adminLogin() {
            const password = document.getElementById('adminPassword').value;
            const response = await fetch('?action=admin_login', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ password })
            });
            const data = await response.json();
            if (data.success) {
                isAdmin = true;
                localStorage.setItem('is_admin', 'true');
                document.getElementById('adminPanel').style.display = 'block';
                loadEmailConfig();
                alert('管理员登录成功');
            } else {
                alert(data.error || '密码错误');
            }
        }

        async function sendMessage() {
            if (emailEnabled && !verifiedEmail) { alert('请先完成邮箱验证'); showNameModal(); return; }
            const input = document.getElementById('messageInput');
            const content = input.value.trim();
            if (!content) return;
            await fetch('?action=send_message', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name: userName, content, type: 'text' })
            });
            input.value = '';
            pollMessages();
        }

        function insertImage() { document.getElementById('imageModal').classList.add('show'); }

        async function sendImage() {
            if (emailEnabled && !verifiedEmail) { alert('请先完成邮箱验证'); showNameModal(); return; }
            const url = document.getElementById('imageUrl').value.trim();
            if (!url) return;
            await fetch('?action=send_message', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name: userName, content: url, type: 'image' })
            });
            document.getElementById('imageModal').classList.remove('show');
            document.getElementById('imageUrl').value = '';
            pollMessages();
        }

        async function pollMessages() {
            const response = await fetch('?action=get_messages', { cache: 'no-cache' });
            const msgs = await response.json();
            if (msgs.length !== messages.length ||
                (msgs.length > 0 && messages.length > 0 && msgs[msgs.length-1].id !== messages[messages.length-1].id)) {
                messages = msgs;
                renderMessages();
            }
        }

        function renderMessages() {
            const list = document.getElementById('messagesList');
            const atBottom = list.scrollHeight - list.scrollTop - list.clientHeight < 60;
            list.innerHTML = '';
            messages.forEach((msg, i) => {
                const li = document.createElement('li');
                const isOwn = msg.name === userName;
                const isAdminMsg = msg.name === '管理员' || msg.name.includes('admin');
                li.className = 'message-item' + (isOwn ? ' own' : '') + (isAdminMsg ? ' admin' : '');
                li.style.animationDelay = `${Math.min(i * 0.02, 0.3)}s`;
                let content = msg.content;
                if (msg.type === 'image') {
                    content = `<img src="${escapeHtml(content)}" alt="图片" onerror="this.style.display='none'">`;
                } else {
                    content = escapeHtml(content).replace(/(https?:\/\/[^\s]+)/g, '<a href="$1" target="_blank">$1</a>');
                }
                const time = new Date(msg.time * 1000).toLocaleTimeString('zh-CN', { hour: '2-digit', minute: '2-digit' });
                const badge = isAdminMsg ? '<span class="admin-badge">管理</span>' : '';
                const delBtn = isAdmin ? `<button class="delete-btn" onclick="deleteMessage(${msg.id})">×</button>` : '';
                li.innerHTML = `<div class="message-header"><span class="message-name">${escapeHtml(msg.name)}${badge}</span><span class="message-time">${time}${delBtn}</span></div><div class="message-content">${content}</div>`;
                list.appendChild(li);
            });
            if (atBottom || lastMessageId === -1) list.scrollTop = list.scrollHeight;
            if (messages.length > 0) lastMessageId = messages[messages.length-1].id;
        }

        async function pollUsers() {
            const response = await fetch('?action=get_users');
            const users = await response.json();
            document.getElementById('onlineCount').textContent = `${users.length} 在线`;
            const list = document.getElementById('usersList');
            list.innerHTML = '';
            users.forEach(u => {
                const li = document.createElement('li');
                li.className = 'user-item' + (u.is_admin ? ' admin' : '');
                li.innerHTML = `<span class="dot"></span>${escapeHtml(u.name)}${u.is_admin ? ' <span style="font-size:0.7em;opacity:0.5;">ADMIN</span>' : ''}`;
                list.appendChild(li);
            });
        }

        async function heartbeat() {
            await fetch('?action=heartbeat', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name: userName })
            });
        }

        async function deleteMessage(id) {
            if (!confirm('删除这条消息？')) return;
            await fetch('?action=delete_message', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id })
            });
            pollMessages();
        }

        async function clearAllMessages() {
            if (!confirm('清空所有聊天记录？此操作不可恢复！')) return;
            await fetch('?action=clear_messages', { method: 'POST' });
            pollMessages();
        }

        function exportChat() {
            let text = '';
            messages.forEach(msg => {
                const time = new Date(msg.time * 1000).toLocaleString('zh-CN');
                text += `[${time}] ${msg.name}: ${msg.content}\n`;
            });
            const blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `聊天记录_${new Date().toLocaleDateString('zh-CN')}.txt`;
            a.click();
            URL.revokeObjectURL(url);
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        document.querySelectorAll('.modal').forEach(m => {
            m.addEventListener('click', e => { if (e.target === m) m.classList.remove('show'); });
        });
        document.addEventListener('click', e => {
            if (!e.target.closest('.toolbar') && !e.target.closest('.emoji-panel')) {
                document.getElementById('emojiPanel').classList.remove('show');
            }
        });
    </script>
</body>
</html>
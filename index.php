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
    
    // 使用mail()函数作为备选
    if (function_exists('mail') && empty($host)) {
        $headers = "From: {$from_name} <{$from}>\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $result = mail($to, $subject, $body, $headers);
        return ['success' => $result];
    }
    
    // SMTP发送
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

// 生成验证码
function generate_code() {
    return str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
}

// 处理API请求
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    // 获取消息
    if ($_GET['action'] === 'get_messages') {
        $messages = read_json($messages_buffer_file);
        echo json_encode($messages);
        exit;
    }
    
    // 获取在线用户
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
    
    // 获取邮箱配置状态
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
    
    // 保存邮箱配置
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
    
    // 发送验证码
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
        
        // 生成验证码
        $code = generate_code();
        $codes = read_json($email_codes_file);
        $codes[$email] = [
            'code' => $code,
            'time' => time(),
            'used' => false
        ];
        write_json($email_codes_file, $codes);
        
        // 发送邮件
        $subject = 'PHP聊天室 - 邮箱验证码';
        $body = "<h2>PHP聊天室</h2><p>您的验证码是：<strong style='font-size:24px;color:#667eea;'>{$code}</strong></p><p>验证码5分钟内有效，请勿泄露给他人。</p>";
        $result = send_email($email, $subject, $body);
        
        if ($result['success']) {
            echo json_encode(['success' => true, 'message' => '验证码已发送']);
        } else {
            echo json_encode(['success' => false, 'error' => $result['error'] ?? '发送失败']);
        }
        exit;
    }
    
    // 验证验证码
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
        
        if (time() - $record['time'] > 300) { // 5分钟过期
            echo json_encode(['success' => false, 'error' => '验证码已过期']);
            exit;
        }
        
        if ($record['code'] !== $code) {
            echo json_encode(['success' => false, 'error' => '验证码错误']);
            exit;
        }
        
        // 标记为已使用
        $codes[$email]['used'] = true;
        write_json($email_codes_file, $codes);
        
        // 记录已验证的邮箱
        $_SESSION['verified_email'] = $email;
        
        echo json_encode(['success' => true]);
        exit;
    }
    
    // 发送消息
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
    
    // 心跳更新
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
    
    // 管理员登录
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
    
    // 删除消息
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
    
    // 清空聊天记录
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

// 默认昵称
$default_name = isset($_SESSION['chat_name']) ? $_SESSION['chat_name'] : '用户' . rand(1000, 9999);

// 检查是否需要邮箱验证
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
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+SC:wght@300;400;500;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-primary: #0f0f23;
            --bg-secondary: #1a1a2e;
            --bg-tertiary: #16213e;
            --bg-card: #1e1e3f;
            --accent-primary: #00d4aa;
            --accent-secondary: #7c3aed;
            --accent-gradient: linear-gradient(135deg, #00d4aa 0%, #7c3aed 100%);
            --text-primary: #e2e8f0;
            --text-secondary: #94a3b8;
            --text-muted: #64748b;
            --border-color: rgba(148, 163, 184, 0.1);
            --shadow-glow: 0 0 40px rgba(0, 212, 170, 0.15);
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 20px;
            --font-body: 'Noto Sans SC', sans-serif;
            --font-mono: 'JetBrains Mono', monospace;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: var(--font-body);
            background: var(--bg-primary);
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 10px;
            overflow: hidden;
        }

        /* Animated background particles */
        body::before {
            content: '';
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background:
                radial-gradient(circle at 20% 80%, rgba(0, 212, 170, 0.08) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(124, 58, 237, 0.08) 0%, transparent 50%),
                radial-gradient(circle at 50% 50%, rgba(0, 212, 170, 0.03) 0%, transparent 70%);
            pointer-events: none;
            z-index: 0;
        }

        .chat-container {
            width: 100%;
            max-width: 1000px;
            height: 92vh;
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-glow), 0 25px 80px rgba(0,0,0,0.4);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            position: relative;
            z-index: 1;
        }

        .chat-header {
            background: linear-gradient(135deg, rgba(0, 212, 170, 0.15) 0%, rgba(124, 58, 237, 0.15) 100%);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--border-color);
            color: var(--text-primary);
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .chat-header h1 {
            font-size: 1.4em;
            font-weight: 700;
            background: var(--accent-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .header-buttons {
            display: flex;
            gap: 10px;
        }

        .header-buttons button {
            background: rgba(0, 212, 170, 0.1);
            border: 1px solid rgba(0, 212, 170, 0.3);
            color: var(--accent-primary);
            padding: 8px 16px;
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-size: 0.85em;
            font-weight: 500;
            transition: all 0.3s ease;
            font-family: var(--font-body);
        }

        .header-buttons button:hover {
            background: rgba(0, 212, 170, 0.2);
            border-color: var(--accent-primary);
            box-shadow: 0 0 20px rgba(0, 212, 170, 0.3);
            transform: translateY(-1px);
        }

        .chat-body {
            flex: 1;
            display: flex;
            overflow: hidden;
        }

        .messages-area {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .messages-list {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            list-style: none;
            scrollbar-width: thin;
            scrollbar-color: var(--accent-primary) transparent;
        }

        .messages-list::-webkit-scrollbar {
            width: 6px;
        }

        .messages-list::-webkit-scrollbar-track {
            background: transparent;
        }

        .messages-list::-webkit-scrollbar-thumb {
            background: var(--accent-primary);
            border-radius: 3px;
        }

        .message-item {
            margin-bottom: 16px;
            animation: messageSlide 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
            padding: 12px 16px;
            border-radius: var(--radius-md);
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            max-width: 75%;
            word-wrap: break-word;
            position: relative;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .message-item:hover {
            transform: translateX(4px);
            box-shadow: 0 4px 20px rgba(0, 212, 170, 0.1);
        }

        .message-item.own {
            background: linear-gradient(135deg, rgba(0, 212, 170, 0.2) 0%, rgba(124, 58, 237, 0.2) 100%);
            border-color: rgba(0, 212, 170, 0.3);
            color: var(--text-primary);
            margin-left: auto;
        }

        .message-item.own:hover {
            box-shadow: 0 4px 20px rgba(0, 212, 170, 0.2);
        }

        .message-item.admin {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.15) 0%, rgba(217, 119, 6, 0.15) 100%);
            border-left: 3px solid #f59e0b;
        }

        .message-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 6px;
        }

        .message-name {
            font-weight: 600;
            font-size: 0.85em;
            color: var(--accent-primary);
            font-family: var(--font-mono);
        }

        .message-item.own .message-name { color: rgba(0, 212, 170, 0.9); }
        .message-item.admin .message-name { color: #f59e0b; }

        .message-time {
            font-size: 0.7em;
            color: var(--text-muted);
            font-family: var(--font-mono);
        }

        .message-item.own .message-time { color: rgba(148, 163, 184, 0.7); }

        .message-content {
            font-size: 0.95em;
            line-height: 1.6;
            color: var(--text-primary);
        }

        .message-content img {
            max-width: 100%;
            border-radius: var(--radius-sm);
            margin-top: 8px;
            border: 1px solid var(--border-color);
        }

        .input-area {
            padding: 16px 20px;
            border-top: 1px solid var(--border-color);
            background: var(--bg-tertiary);
            position: relative;
        }

        .input-row {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
        }

        .input-row input[type="text"] {
            flex: 1;
            padding: 12px 18px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            font-size: 0.95em;
            outline: none;
            transition: all 0.3s ease;
            color: var(--text-primary);
            font-family: var(--font-body);
        }

        .input-row input[type="text"]::placeholder {
            color: var(--text-muted);
        }

        .input-row input[type="text"]:focus {
            border-color: var(--accent-primary);
            box-shadow: 0 0 0 3px rgba(0, 212, 170, 0.1);
        }

        .input-row button {
            padding: 12px 24px;
            background: var(--accent-gradient);
            color: white;
            border: none;
            border-radius: var(--radius-md);
            cursor: pointer;
            font-size: 0.9em;
            font-weight: 600;
            transition: all 0.3s ease;
            font-family: var(--font-body);
            position: relative;
            overflow: hidden;
        }

        .input-row button::before {
            content: '';
            position: absolute;
            top: 0; left: -100%;
            width: 100%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s ease;
        }

        .input-row button:hover::before {
            left: 100%;
        }

        .input-row button:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 212, 170, 0.4);
        }

        .toolbar {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .toolbar button {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            padding: 6px 12px;
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-size: 0.85em;
            color: var(--text-secondary);
            transition: all 0.3s ease;
            font-family: var(--font-body);
        }

        .toolbar button:hover {
            background: rgba(0, 212, 170, 0.1);
            color: var(--accent-primary);
            border-color: rgba(0, 212, 170, 0.3);
            transform: translateY(-1px);
        }
        .input-row button:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        .input-row button:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        .toolbar {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        .toolbar button {
            background: none;
            border: 1px solid #ddd;
            padding: 5px 10px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.85em;
            color: #666;
            transition: all 0.2s;
        }
        .toolbar button:hover {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        .emoji-panel {
            display: none;
            position: absolute;
            bottom: 80px;
            left: 20px;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 12px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.4);
            z-index: 100;
            max-width: 320px;
        }
        .emoji-panel.show { display: block; }
        .emoji-grid {
            display: grid;
            grid-template-columns: repeat(8, 1fr);
            gap: 6px;
        }
        .emoji-grid span {
            cursor: pointer;
            font-size: 1.3em;
            padding: 6px;
            text-align: center;
            border-radius: var(--radius-sm);
            transition: all 0.2s ease;
        }
        .emoji-grid span:hover {
            background: rgba(0, 212, 170, 0.2);
            transform: scale(1.2);
        }
        .sidebar {
            width: 220px;
            border-left: 1px solid var(--border-color);
            background: var(--bg-tertiary);
            display: flex;
            flex-direction: column;
        }
        .sidebar-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-color);
            font-weight: 600;
            color: var(--text-primary);
            font-size: 0.9em;
            background: linear-gradient(135deg, rgba(0, 212, 170, 0.1) 0%, rgba(124, 58, 237, 0.1) 100%);
        }
        .online-count {
            color: var(--accent-primary);
            font-size: 0.85em;
            margin-top: 4px;
            font-family: var(--font-mono);
        }
        .users-list {
            flex: 1;
            overflow-y: auto;
            padding: 12px;
            list-style: none;
            scrollbar-width: thin;
            scrollbar-color: var(--accent-primary) transparent;
        }
        .users-list::-webkit-scrollbar {
            width: 4px;
        }
        .users-list::-webkit-scrollbar-thumb {
            background: var(--accent-primary);
            border-radius: 2px;
        }
        .user-item {
            padding: 10px 12px;
            border-radius: var(--radius-sm);
            margin-bottom: 6px;
            font-size: 0.85em;
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-secondary);
            transition: all 0.2s ease;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
        }
        .user-item:hover {
            background: rgba(0, 212, 170, 0.1);
            border-color: rgba(0, 212, 170, 0.3);
        }
        .user-item::before {
            content: '';
            width: 8px;
            height: 8px;
            background: #00d4aa;
            border-radius: 50%;
            flex-shrink: 0;
            box-shadow: 0 0 8px rgba(0, 212, 170, 0.5);
        }
        .user-item.admin::before {
            background: #f59e0b;
            box-shadow: 0 0 8px rgba(245, 158, 11, 0.5);
        }
        .user-item.admin {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.1) 0%, rgba(217, 119, 6, 0.1) 100%);
            border-color: rgba(245, 158, 11, 0.3);
            color: #f59e0b;
        }
        .modal {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(15, 15, 35, 0.8);
            backdrop-filter: blur(10px);
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }
        .modal.show { display: flex; }
        .modal-content {
            background: var(--bg-secondary);
            padding: 28px;
            border-radius: var(--radius-lg);
            width: 90%;
            max-width: 420px;
            box-shadow: var(--shadow-glow), 0 20px 60px rgba(0,0,0,0.5);
            border: 1px solid var(--border-color);
            animation: modalSlide 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        .modal-content h3 {
            margin-bottom: 18px;
            color: var(--text-primary);
            font-size: 1.2em;
            font-weight: 700;
        }
        .modal-content input, .modal-content select {
            width: 100%;
            padding: 12px 16px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            margin-bottom: 12px;
            font-size: 1em;
            color: var(--text-primary);
            outline: none;
            transition: all 0.3s ease;
            font-family: var(--font-body);
        }
        .modal-content input:focus, .modal-content select:focus {
            border-color: var(--accent-primary);
            box-shadow: 0 0 0 3px rgba(0, 212, 170, 0.1);
        }
        .modal-content input::placeholder {
            color: var(--text-muted);
        }
        .modal-content button {
            width: 100%;
            padding: 12px;
            background: var(--accent-gradient);
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-size: 1em;
            font-weight: 600;
            transition: all 0.3s ease;
            font-family: var(--font-body);
        }
        .modal-content button:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 212, 170, 0.4);
        }
        .modal-content .btn-secondary {
            background: var(--bg-card);
            color: var(--text-secondary);
            border: 1px solid var(--border-color);
            margin-top: 8px;
        }
        .modal-content .btn-secondary:hover {
            background: rgba(0, 212, 170, 0.1);
            color: var(--accent-primary);
            border-color: rgba(0, 212, 170, 0.3);
        }
        .system-message {
            text-align: center;
            color: var(--text-muted);
            font-size: 0.85em;
            padding: 12px;
            font-style: italic;
            font-family: var(--font-mono);
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes messageSlide {
            from { opacity: 0; transform: translateX(-20px) scale(0.95); }
            to { opacity: 1; transform: translateX(0) scale(1); }
        }
        @keyframes modalSlide {
            from { opacity: 0; transform: translateY(-30px) scale(0.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        @media (max-width: 600px) {
            .sidebar { display: none; }
            .chat-container { height: 100vh; border-radius: 0; }
            body { padding: 0; }
        }
        .admin-badge {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
            font-size: 0.7em;
            padding: 2px 8px;
            border-radius: 4px;
            margin-left: 6px;
            font-weight: 600;
        }
        .delete-btn {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            border: none;
            padding: 3px 10px;
            border-radius: 4px;
            font-size: 0.75em;
            cursor: pointer;
            margin-left: 8px;
            transition: all 0.2s ease;
        }
        .delete-btn:hover {
            box-shadow: 0 0 12px rgba(239, 68, 68, 0.5);
            transform: scale(1.05);
        }
        .email-verify-row {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
        }
        .email-verify-row input {
            flex: 1;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            padding: 10px 14px;
            color: var(--text-primary);
            outline: none;
            transition: all 0.3s ease;
        }
        .email-verify-row input:focus {
            border-color: var(--accent-primary);
            box-shadow: 0 0 0 3px rgba(0, 212, 170, 0.1);
        }
        .email-verify-row button {
            white-space: nowrap;
            padding: 10px 16px;
            background: var(--accent-gradient);
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .email-verify-row button:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 15px rgba(0, 212, 170, 0.4);
        }
        .countdown {
            color: var(--text-muted);
            font-size: 0.85em;
            font-family: var(--font-mono);
        }
        .settings-panel {
            display: none;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid var(--border-color);
        }
        .settings-panel.show { display: block; }
        .form-group {
            margin-bottom: 14px;
        }
        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-size: 0.9em;
            color: var(--text-secondary);
            font-weight: 500;
        }
        .toggle-switch {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 14px;
        }
        .toggle-switch input[type="checkbox"] {
            width: 44px;
            height: 24px;
            appearance: none;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 24px;
            position: relative;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .toggle-switch input[type="checkbox"]:checked {
            background: linear-gradient(135deg, #00d4aa, #7c3aed);
            border-color: transparent;
        }
        .toggle-switch input[type="checkbox"]::after {
            content: '';
            position: absolute;
            width: 18px;
            height: 18px;
            background: white;
            border-radius: 50%;
            top: 2px;
            left: 2px;
            transition: transform 0.3s ease;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
        .toggle-switch input[type="checkbox"]:checked::after {
            transform: translateX(20px);
        }
        .toggle-switch label {
            color: var(--text-secondary);
            font-size: 0.9em;
        }
    </style>
</head>
<body>
    <div class="chat-container">
        <div class="chat-header">
            <div>
                <h1>PHP 聊天室</h1>
                <div class="online-count" id="onlineCount">在线: 0人</div>
            </div>
            <div class="header-buttons">
                <button onclick="showNameModal()">修改昵称</button>
                <button onclick="showAdminModal()">管理</button>
            </div>
        </div>
        
        <div class="chat-body">
            <div class="messages-area">
                <ul class="messages-list" id="messagesList">
                    <li class="system-message">正在连接聊天室...</li>
                </ul>
                
                <div class="input-area" style="position: relative;">
                    <div class="toolbar">
                        <button onclick="toggleEmoji()">😊 表情</button>
                        <button onclick="insertImage()">📷 图片</button>
                        <button onclick="exportChat()">📥 导出</button>
                    </div>
                    
                    <div class="emoji-panel" id="emojiPanel">
                        <div class="emoji-grid" id="emojiGrid"></div>
                    </div>
                    
                    <div class="input-row">
                        <input type="text" id="messageInput" placeholder="输入消息..." maxlength="500" onkeypress="if(event.key==='Enter')sendMessage()">
                        <button onclick="sendMessage()">发送</button>
                    </div>
                </div>
            </div>
            
            <div class="sidebar">
                <div class="sidebar-header">
                    在线用户
                </div>
                <ul class="users-list" id="usersList"></ul>
            </div>
        </div>
    </div>
    
    <!-- 昵称设置模态框 -->
    <div class="modal" id="nameModal">
        <div class="modal-content">
            <h3>设置你的昵称</h3>
            <input type="text" id="nameInput" placeholder="输入昵称" maxlength="20" value="<?php echo htmlspecialchars($default_name); ?>">
            
            <!-- 邮箱验证区域 -->
            <div id="emailVerifySection" style="display:none;">
                <hr style="margin:15px 0;border:none;border-top:1px solid #eee;">
                <p style="font-size:0.85em;color:#666;margin-bottom:10px;">📧 需要邮箱验证后才能聊天</p>
                <div class="email-verify-row">
                    <input type="email" id="emailInput" placeholder="输入邮箱地址">
                    <button id="sendCodeBtn" onclick="sendVerifyCode()">发送验证码</button>
                </div>
                <div class="email-verify-row">
                    <input type="text" id="codeInput" placeholder="输入6位验证码" maxlength="6">
                    <button onclick="verifyCode()">验证</button>
                </div>
                <p id="emailStatus" class="countdown"></p>
            </div>
            
            <button onclick="saveName()">确定</button>
        </div>
    </div>
    
    <!-- 管理员模态框 -->
    <div class="modal" id="adminModal">
        <div class="modal-content">
            <h3>管理员登录</h3>
            <input type="password" id="adminPassword" placeholder="输入管理员密码">
            <button onclick="adminLogin()">登录</button>
            
            <div id="adminPanel" style="display:none; margin-top:15px;">
                <button onclick="clearAllMessages()" style="background:#ff4444; margin-bottom:8px;">清空所有消息</button>
                <button onclick="toggleSettings()" class="btn-secondary">⚙️ 邮箱设置</button>
                
                <!-- 邮箱设置面板 -->
                <div class="settings-panel" id="settingsPanel">
                    <h4 style="margin-bottom:12px;">SMTP邮箱配置</h4>
                    
                    <div class="toggle-switch">
                        <input type="checkbox" id="emailEnabled" onchange="toggleEmailEnabled()">
                        <label for="emailEnabled">启用邮箱验证码</label>
                    </div>
                    
                    <div class="form-group">
                        <label>SMTP服务器</label>
                        <input type="text" id="smtpHost" placeholder="如: smtp.qq.com">
                    </div>
                    
                    <div class="form-group">
                        <label>SMTP端口</label>
                        <input type="text" id="smtpPort" placeholder="587" value="587">
                    </div>
                    
                    <div class="form-group">
                        <label>加密方式</label>
                        <select id="smtpSecure">
                            <option value="tls">TLS</option>
                            <option value="ssl">SSL</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>SMTP账号</label>
                        <input type="text" id="smtpUser" placeholder="邮箱地址">
                    </div>
                    
                    <div class="form-group">
                        <label>SMTP密码/授权码</label>
                        <input type="password" id="smtpPass" placeholder="邮箱密码或授权码">
                    </div>
                    
                    <div class="form-group">
                        <label>发件人名称</label>
                        <input type="text" id="smtpFromName" placeholder="PHP聊天室" value="PHP聊天室">
                    </div>
                    
                    <button onclick="saveEmailConfig()">保存配置</button>
                    <button onclick="testEmailConfig()" class="btn-secondary">测试发送</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- 图片上传模态框 -->
    <div class="modal" id="imageModal">
        <div class="modal-content">
            <h3>发送图片</h3>
            <input type="text" id="imageUrl" placeholder="输入图片URL地址">
            <button onclick="sendImage()">发送</button>
        </div>
    </div>

    <script>
        // 全局变量
        let userName = localStorage.getItem('chat_name') || '<?php echo htmlspecialchars($default_name); ?>';
        let isAdmin = localStorage.getItem('is_admin') === 'true';
        let lastMessageId = -1;
        let messages = [];
        let emailEnabled = <?php echo $email_enabled ? 'true' : 'false'; ?>;
        let verifiedEmail = '<?php echo $verified_email; ?>';
        let countdownTimer = null;
        
        // 表情列表
        const emojis = ['😀','😃','😄','😁','😅','😂','🤣','😊','😇','🙂','🙃','😉','😌','😍','🥰','😘','😗','😙','😚','😋','😛','😝','😜','🤪','🤨','🧐','🤓','😎','🥸','🤩','🥳','😏','😒','😞','😔','😟','😕','🙁','☹️','😣','😖','😫','😩','🥺','😢','😭','😤','😠','😡','🤬','🤯','😳','🥵','🥶','😱','😨','😰','😥','😓','🤗','🤔','🤭','🤫','🤥','😶','😐','😑','😬','🙄','😯','😦','😧','😮','😲','🥱','😴','🤤','😪','😵','🤐','🥴','🤢','🤮','🤧','😷','🤒','🤕','🤑','🤠','😈','👿','👹','👺','🤡','💩','👻','💀','☠️','👽','👾','🤖','🎃','😺','😸','😹','😻','😼','😽','🙀','😿','😾'];
        
        // 初始化
        document.addEventListener('DOMContentLoaded', () => {
            initEmojiPanel();
            pollMessages();
            pollUsers();
            heartbeat();
            setInterval(pollMessages, 2000);
            setInterval(pollUsers, 5000);
            setInterval(heartbeat, 30000);
            
            // 检查是否需要显示邮箱验证
            if (emailEnabled && !verifiedEmail) {
                document.getElementById('emailVerifySection').style.display = 'block';
            }
        });
        
        // 初始化表情面板
        function initEmojiPanel() {
            const grid = document.getElementById('emojiGrid');
            emojis.forEach(emoji => {
                const span = document.createElement('span');
                span.textContent = emoji;
                span.onclick = () => insertEmoji(emoji);
                grid.appendChild(span);
            });
        }
        
        // 切换表情面板
        function toggleEmoji() {
            document.getElementById('emojiPanel').classList.toggle('show');
        }
        
        // 插入表情
        function insertEmoji(emoji) {
            const input = document.getElementById('messageInput');
            input.value += emoji;
            input.focus();
            document.getElementById('emojiPanel').classList.remove('show');
        }
        
        // 显示昵称模态框
        function showNameModal() {
            document.getElementById('nameModal').classList.add('show');
            document.getElementById('nameInput').value = userName;
            
            // 如果启用了邮箱验证且未验证，显示验证区域
            if (emailEnabled && !verifiedEmail) {
                document.getElementById('emailVerifySection').style.display = 'block';
            } else {
                document.getElementById('emailVerifySection').style.display = 'none';
            }
        }
        
        // 保存昵称
        function saveName() {
            const name = document.getElementById('nameInput').value.trim();
            if (name) {
                userName = name;
                localStorage.setItem('chat_name', name);
                document.getElementById('nameModal').classList.remove('show');
            }
        }
        
        // 发送验证码
        async function sendVerifyCode() {
            const email = document.getElementById('emailInput').value.trim();
            const btn = document.getElementById('sendCodeBtn');
            
            if (!email) {
                alert('请输入邮箱地址');
                return;
            }
            
            btn.disabled = true;
            document.getElementById('emailStatus').textContent = '正在发送...';
            
            const response = await fetch('?action=send_verify_code', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email })
            });
            
            const data = await response.json();
            if (data.success) {
                document.getElementById('emailStatus').textContent = '验证码已发送，请查收邮件';
                startCountdown(60);
            } else {
                document.getElementById('emailStatus').textContent = data.error || '发送失败';
                btn.disabled = false;
            }
        }
        
        // 倒计时
        function startCountdown(seconds) {
            const btn = document.getElementById('sendCodeBtn');
            let remaining = seconds;
            
            countdownTimer = setInterval(() => {
                remaining--;
                btn.textContent = `${remaining}秒后重试`;
                
                if (remaining <= 0) {
                    clearInterval(countdownTimer);
                    btn.disabled = false;
                    btn.textContent = '发送验证码';
                }
            }, 1000);
        }
        
        // 验证验证码
        async function verifyCode() {
            const email = document.getElementById('emailInput').value.trim();
            const code = document.getElementById('codeInput').value.trim();
            
            if (!email || !code) {
                alert('请输入邮箱和验证码');
                return;
            }
            
            const response = await fetch('?action=verify_code', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email, code })
            });
            
            const data = await response.json();
            if (data.success) {
                verifiedEmail = email;
                document.getElementById('emailStatus').textContent = '✅ 验证成功！';
                document.getElementById('emailStatus').style.color = '#4caf50';
                setTimeout(() => {
                    document.getElementById('emailVerifySection').style.display = 'none';
                }, 1500);
            } else {
                document.getElementById('emailStatus').textContent = data.error || '验证失败';
                document.getElementById('emailStatus').style.color = '#ff4444';
            }
        }
        
        // 显示管理员模态框
        function showAdminModal() {
            document.getElementById('adminModal').classList.add('show');
            if (isAdmin) {
                document.getElementById('adminPanel').style.display = 'block';
                loadEmailConfig();
            }
        }
        
        // 加载邮箱配置
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
        
        // 切换设置面板
        function toggleSettings() {
            document.getElementById('settingsPanel').classList.toggle('show');
        }
        
        // 保存邮箱配置
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
            if (data.success) {
                alert('配置已保存');
                emailEnabled = config.enabled;
            } else {
                alert(data.error || '保存失败');
            }
        }
        
        // 测试邮件
        async function testEmailConfig() {
            const email = prompt('请输入测试接收邮箱:');
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
        
        // 管理员登录
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
        
        // 发送消息
        async function sendMessage() {
            // 检查是否需要邮箱验证
            if (emailEnabled && !verifiedEmail) {
                alert('请先完成邮箱验证');
                showNameModal();
                return;
            }
            
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
        
        // 插入图片
        function insertImage() {
            document.getElementById('imageModal').classList.add('show');
        }
        
        // 发送图片
        async function sendImage() {
            if (emailEnabled && !verifiedEmail) {
                alert('请先完成邮箱验证');
                showNameModal();
                return;
            }
            
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
        
        // 获取消息
        async function pollMessages() {
            const response = await fetch('?action=get_messages', { cache: 'no-cache' });
            const newMessages = await response.json();
            
            if (newMessages.length !== messages.length || 
                (newMessages.length > 0 && messages.length > 0 && 
                 newMessages[newMessages.length-1].id !== messages[messages.length-1].id)) {
                messages = newMessages;
                renderMessages();
            }
        }
        
        // 渲染消息
        function renderMessages() {
            const list = document.getElementById('messagesList');
            const wasAtBottom = list.scrollHeight - list.scrollTop - list.clientHeight < 50;
            
            list.innerHTML = '';
            
            messages.forEach(msg => {
                const li = document.createElement('li');
                const isOwn = msg.name === userName;
                const isAdminMsg = msg.name === '管理员' || msg.name.includes('admin');
                
                li.className = 'message-item' + (isOwn ? ' own' : '') + (isAdminMsg ? ' admin' : '');
                
                let content = msg.content;
                if (msg.type === 'image') {
                    content = `<img src="${escapeHtml(content)}" alt="图片" onerror="this.style.display='none'">`;
                } else {
                    content = escapeHtml(content);
                    content = content.replace(/(https?:\/\/[^\s]+)/g, '<a href="$1" target="_blank" style="color:inherit;">$1</a>');
                }
                
                const time = new Date(msg.time * 1000).toLocaleString('zh-CN');
                const adminBadge = isAdminMsg ? '<span class="admin-badge">管理</span>' : '';
                const deleteBtn = isAdmin ? `<button class="delete-btn" onclick="deleteMessage(${msg.id})">删除</button>` : '';
                
                li.innerHTML = `
                    <div class="message-header">
                        <span class="message-name">${escapeHtml(msg.name)}${adminBadge}</span>
                        <span class="message-time">${time}${deleteBtn}</span>
                    </div>
                    <div class="message-content">${content}</div>
                `;
                
                list.appendChild(li);
            });
            
            if (wasAtBottom || lastMessageId === -1) {
                list.scrollTop = list.scrollHeight;
            }
            
            if (messages.length > 0) {
                lastMessageId = messages[messages.length - 1].id;
            }
        }
        
        // 获取在线用户
        async function pollUsers() {
            const response = await fetch('?action=get_users');
            const users = await response.json();
            
            document.getElementById('onlineCount').textContent = `在线: ${users.length}人`;
            
            const list = document.getElementById('usersList');
            list.innerHTML = '';
            
            users.forEach(user => {
                const li = document.createElement('li');
                li.className = 'user-item' + (user.is_admin ? ' admin' : '');
                li.textContent = user.name + (user.is_admin ? ' (管理)' : '');
                list.appendChild(li);
            });
        }
        
        // 心跳
        async function heartbeat() {
            await fetch('?action=heartbeat', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name: userName })
            });
        }
        
        // 删除消息
        async function deleteMessage(id) {
            if (!confirm('确定删除这条消息吗？')) return;
            
            await fetch('?action=delete_message', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id })
            });
            
            pollMessages();
        }
        
        // 清空所有消息
        async function clearAllMessages() {
            if (!confirm('确定清空所有聊天记录吗？此操作不可恢复！')) return;
            
            await fetch('?action=clear_messages', { method: 'POST' });
            pollMessages();
        }
        
        // 导出聊天记录
        function exportChat() {
            let text = '聊天记录导出\n';
            text += '==================\n\n';
            
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
        
        // HTML转义
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // 点击模态框外部关闭
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) modal.classList.remove('show');
            });
        });
        
        // 点击其他地方关闭表情面板
        document.addEventListener('click', (e) => {
            if (!e.target.closest('.toolbar') && !e.target.closest('.emoji-panel')) {
                document.getElementById('emojiPanel').classList.remove('show');
            }
        });
    </script>
</body>
</html>

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
$db_config_file = "db_config.json";
$messages_buffer_size = 200;
$admin_password = "admin123"; // 建议修改此密码

// 创建必要文件
foreach ([$messages_buffer_file, $users_file, $banned_file, $email_config_file, $email_codes_file, $db_config_file] as $file) {
    if (!file_exists($file)) {
        file_put_contents($file, json_encode([]));
    }
}

// ========== 数据库配置与连接 ==========
$db_config = read_json($db_config_file);
$db_enabled = !empty($db_config['enabled']);
$db_conn = null;

function db_connect() {
    global $db_config, $db_conn;
    if ($db_conn !== null) return $db_conn;
    if (empty($db_config['host']) || empty($db_config['dbname'])) return null;

    $dsn = "mysql:host={$db_config['host']};dbname={$db_config['dbname']};charset=utf8mb4";
    try {
        $db_conn = new PDO($dsn, $db_config['user'] ?? 'root', $db_config['pass'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        return $db_conn;
    } catch (PDOException $e) {
        error_log("DB Connect Error: " . $e->getMessage());
        return null;
    }
}

function db_init_tables() {
    $db = db_connect();
    if (!$db) return false;

    $db->exec("CREATE TABLE IF NOT EXISTS chat_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        msg_id INT NOT NULL UNIQUE,
        time INT NOT NULL,
        name VARCHAR(64) NOT NULL,
        content TEXT NOT NULL,
        type VARCHAR(20) DEFAULT 'text',
        ip VARCHAR(45) NOT NULL,
        INDEX idx_time (time)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS chat_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ip VARCHAR(45) NOT NULL UNIQUE,
        name VARCHAR(64) NOT NULL,
        last_active INT NOT NULL,
        join_time INT NOT NULL,
        is_admin TINYINT DEFAULT 0,
        INDEX idx_last_active (last_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS chat_banned (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ip VARCHAR(45) NOT NULL UNIQUE,
        until INT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    return true;
}

// 数据库操作封装
function db_get_messages($limit = 200) {
    $db = db_connect();
    if (!$db) return [];
    $stmt = $db->prepare("SELECT msg_id as id, time, name, content, type, ip FROM chat_messages ORDER BY msg_id ASC LIMIT ?");
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

function db_add_message($msg) {
    $db = db_connect();
    if (!$db) return false;
    $stmt = $db->prepare("INSERT INTO chat_messages (msg_id, time, name, content, type, ip) VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE time=VALUES(time), name=VALUES(name), content=VALUES(content), type=VALUES(type), ip=VALUES(ip)");
    return $stmt->execute([$msg['id'], $msg['time'], $msg['name'], $msg['content'], $msg['type'], $msg['ip']]);
}

function db_delete_message($msg_id) {
    $db = db_connect();
    if (!$db) return false;
    $stmt = $db->prepare("DELETE FROM chat_messages WHERE msg_id = ?");
    return $stmt->execute([$msg_id]);
}

function db_clear_messages() {
    $db = db_connect();
    if (!$db) return false;
    return $db->exec("TRUNCATE TABLE chat_messages") !== false;
}

function db_get_users() {
    $db = db_connect();
    if (!$db) return [];
    $stmt = $db->query("SELECT ip, name, last_active, join_time, is_admin FROM chat_users");
    $users = [];
    foreach ($stmt->fetchAll() as $row) {
        $users[$row['ip']] = [
            'name' => $row['name'],
            'last_active' => (int)$row['last_active'],
            'join_time' => (int)$row['join_time'],
            'is_admin' => (bool)$row['is_admin']
        ];
    }
    return $users;
}

function db_save_user($ip, $user) {
    $db = db_connect();
    if (!$db) return false;
    $stmt = $db->prepare("INSERT INTO chat_users (ip, name, last_active, join_time, is_admin) VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE name=VALUES(name), last_active=VALUES(last_active), join_time=VALUES(join_time), is_admin=VALUES(is_admin)");
    return $stmt->execute([$ip, $user['name'], $user['last_active'], $user['join_time'], $user['is_admin'] ? 1 : 0]);
}

function db_get_banned() {
    $db = db_connect();
    if (!$db) return [];
    $stmt = $db->query("SELECT ip, until FROM chat_banned");
    $banned = [];
    foreach ($stmt->fetchAll() as $row) {
        $banned[$row['ip']] = (int)$row['until'];
    }
    return $banned;
}

function db_save_banned($ip, $until) {
    $db = db_connect();
    if (!$db) return false;
    $stmt = $db->prepare("INSERT INTO chat_banned (ip, until) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE until=VALUES(until)");
    return $stmt->execute([$ip, $until]);
}

function db_remove_banned($ip) {
    $db = db_connect();
    if (!$db) return false;
    $stmt = $db->prepare("DELETE FROM chat_banned WHERE ip = ?");
    return $stmt->execute([$ip]);
}

// 统一的数据读写接口（JSON + 数据库双存储）
function get_messages() {
    global $messages_buffer_file, $db_enabled;
    $messages = read_json($messages_buffer_file);
    if ($db_enabled) {
        $db_msgs = db_get_messages($GLOBALS['messages_buffer_size']);
        if (!empty($db_msgs)) {
            // 合并数据库和JSON数据，以数据库为准
            $messages = $db_msgs;
            write_json($messages_buffer_file, $messages);
        } else if (!empty($messages)) {
            // 数据库为空但JSON有数据，同步到数据库
            foreach ($messages as $msg) {
                db_add_message($msg);
            }
        }
    }
    return $messages;
}

function save_message($msg) {
    global $messages_buffer_file, $db_enabled;
    $messages = read_json($messages_buffer_file);
    $messages[] = $msg;
    if (count($messages) > $GLOBALS['messages_buffer_size']) {
        $messages = array_slice($messages, count($messages) - $GLOBALS['messages_buffer_size']);
    }
    write_json($messages_buffer_file, $messages);
    if ($db_enabled) {
        db_add_message($msg);
    }
}

function delete_message_by_id($msg_id) {
    global $messages_buffer_file, $db_enabled;
    $messages = read_json($messages_buffer_file);
    $messages = array_filter($messages, function($msg) use ($msg_id) {
        return $msg['id'] !== $msg_id;
    });
    $messages = array_values($messages);
    write_json($messages_buffer_file, $messages);
    if ($db_enabled) {
        db_delete_message($msg_id);
    }
}

function clear_all_messages() {
    global $messages_buffer_file, $db_enabled;
    write_json($messages_buffer_file, []);
    if ($db_enabled) {
        db_clear_messages();
    }
}

function get_users() {
    global $users_file, $db_enabled;
    $users = read_json($users_file);
    if ($db_enabled) {
        $db_users = db_get_users();
        if (!empty($db_users)) {
            $users = $db_users;
            write_json($users_file, $users);
        } else if (!empty($users)) {
            foreach ($users as $ip => $user) {
                db_save_user($ip, $user);
            }
        }
    }
    return $users;
}

function save_user($ip, $user) {
    global $users_file, $db_enabled;
    $users = read_json($users_file);
    $users[$ip] = $user;
    write_json($users_file, $users);
    if ($db_enabled) {
        db_save_user($ip, $user);
    }
}

function get_banned() {
    global $banned_file, $db_enabled;
    $banned = read_json($banned_file);
    if ($db_enabled) {
        $db_banned = db_get_banned();
        if (!empty($db_banned)) {
            $banned = $db_banned;
            write_json($banned_file, $banned);
        } else if (!empty($banned)) {
            foreach ($banned as $ip => $until) {
                db_save_banned($ip, $until);
            }
        }
    }
    return $banned;
}

function save_banned($ip, $until) {
    global $banned_file, $db_enabled;
    $banned = read_json($banned_file);
    $banned[$ip] = $until;
    write_json($banned_file, $banned);
    if ($db_enabled) {
        db_save_banned($ip, $until);
    }
}

// 初始化数据库表（如果启用）
if ($db_enabled) {
    db_init_tables();
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
    $banned = get_banned();
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
        $messages = get_messages();
        echo json_encode($messages);
        exit;
    }
    
    if ($_GET['action'] === 'get_users') {
        $users = get_users();
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

        $users = get_users();
        $users[$ip] = [
            'name' => $name,
            'last_active' => time(),
            'join_time' => isset($users[$ip]) ? $users[$ip]['join_time'] : time(),
            'is_admin' => isset($users[$ip]) ? $users[$ip]['is_admin'] : false
        ];
        save_user($ip, $users[$ip]);

        $messages = get_messages();
        $next_id = count($messages) > 0 ? $messages[count($messages) - 1]['id'] + 1 : 0;
        $msg = [
            'id' => $next_id,
            'time' => time(),
            'name' => $name,
            'content' => $content,
            'type' => $type,
            'ip' => $ip
        ];

        save_message($msg);
        echo json_encode(['success' => true]);
        exit;
    }
    
    if ($_GET['action'] === 'heartbeat' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $name = isset($input['name']) ? trim($input['name']) : '';
        $ip = get_user_ip();

        if (!empty($name)) {
            $users = get_users();
            $users[$ip] = [
                'name' => $name,
                'last_active' => time(),
                'join_time' => isset($users[$ip]) ? $users[$ip]['join_time'] : time(),
                'is_admin' => isset($users[$ip]) ? $users[$ip]['is_admin'] : false
            ];
            save_user($ip, $users[$ip]);
        }
        echo json_encode(['success' => true]);
        exit;
    }
    
    if ($_GET['action'] === 'admin_login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $password = isset($input['password']) ? $input['password'] : '';

        if ($password === $admin_password) {
            $ip = get_user_ip();
            $users = get_users();
            if (isset($users[$ip])) {
                $users[$ip]['is_admin'] = true;
                save_user($ip, $users[$ip]);
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

        delete_message_by_id($msg_id);
        echo json_encode(['success' => true]);
        exit;
    }
    
    if ($_GET['action'] === 'clear_messages' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_SESSION['is_admin'])) {
            echo json_encode(['success' => false, 'error' => '无权限']);
            exit;
        }
        clear_all_messages();
        echo json_encode(['success' => true]);
        exit;
    }

    // ========== 数据库配置 API ==========
    if ($_GET['action'] === 'get_db_config') {
        $config = read_json($db_config_file);
        echo json_encode([
            'enabled' => !empty($config['enabled']),
            'host' => $config['host'] ?? 'localhost',
            'dbname' => $config['dbname'] ?? '',
            'user' => $config['user'] ?? 'root',
            'pass' => '' // 不返回密码
        ]);
        exit;
    }

    if ($_GET['action'] === 'save_db_config' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_SESSION['is_admin'])) {
            echo json_encode(['success' => false, 'error' => '无权限']);
            exit;
        }
        $input = json_decode(file_get_contents('php://input'), true);
        $config = [
            'enabled' => !empty($input['enabled']),
            'host' => $input['host'] ?? 'localhost',
            'dbname' => $input['dbname'] ?? '',
            'user' => $input['user'] ?? 'root',
            'pass' => $input['pass'] ?? ''
        ];
        // 如果密码为空，保留原密码
        $old_config = read_json($db_config_file);
        if (empty($config['pass']) && !empty($old_config['pass'])) {
            $config['pass'] = $old_config['pass'];
        }
        write_json($db_config_file, $config);

        // 如果启用，尝试连接并初始化
        if ($config['enabled']) {
            global $db_config, $db_enabled;
            $db_config = $config;
            $db_enabled = true;
            if (db_init_tables()) {
                echo json_encode(['success' => true, 'message' => '配置已保存，数据库连接成功']);
            } else {
                echo json_encode(['success' => false, 'error' => '配置已保存，但数据库连接失败，请检查配置']);
            }
        } else {
            echo json_encode(['success' => true, 'message' => '配置已保存，数据库已禁用']);
        }
        exit;
    }

    if ($_GET['action'] === 'test_db_config' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_SESSION['is_admin'])) {
            echo json_encode(['success' => false, 'error' => '无权限']);
            exit;
        }
        $input = json_decode(file_get_contents('php://input'), true);
        $test_config = [
            'host' => $input['host'] ?? 'localhost',
            'dbname' => $input['dbname'] ?? '',
            'user' => $input['user'] ?? 'root',
            'pass' => $input['pass'] ?? ''
        ];
        // 如果密码为空，使用原密码
        $old_config = read_json($db_config_file);
        if (empty($test_config['pass']) && !empty($old_config['pass'])) {
            $test_config['pass'] = $old_config['pass'];
        }

        $dsn = "mysql:host={$test_config['host']};dbname={$test_config['dbname']};charset=utf8mb4";
        try {
            $test_conn = new PDO($dsn, $test_config['user'], $test_config['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 3
            ]);
            echo json_encode(['success' => true, 'message' => '数据库连接成功']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => '连接失败: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($_GET['action'] === 'sync_to_db' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_SESSION['is_admin'])) {
            echo json_encode(['success' => false, 'error' => '无权限']);
            exit;
        }
        if (!$db_enabled) {
            echo json_encode(['success' => false, 'error' => '数据库未启用']);
            exit;
        }
        $messages = read_json($messages_buffer_file);
        $sync_count = 0;
        foreach ($messages as $msg) {
            if (db_add_message($msg)) $sync_count++;
        }
        $users = read_json($users_file);
        foreach ($users as $ip => $user) {
            db_save_user($ip, $user);
        }
        $banned = read_json($banned_file);
        foreach ($banned as $ip => $until) {
            db_save_banned($ip, $until);
        }
        echo json_encode(['success' => true, 'message' => "同步完成，共同步 {$sync_count} 条消息"]);
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
    <title>Nebula Chat</title>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --void: #02040a;
            --abyss: #060b16;
            --surface: rgba(255,255,255,0.02);
            --glass: rgba(8,12,24,0.7);
            --glass-hover: rgba(12,18,36,0.85);
            --neon-cyan: #00e5ff;
            --neon-cyan-dim: rgba(0,229,255,0.1);
            --neon-cyan-glow: rgba(0,229,255,0.25);
            --neon-magenta: #ff2d95;
            --neon-magenta-dim: rgba(255,45,149,0.1);
            --neon-magenta-glow: rgba(255,45,149,0.25);
            --neon-green: #39ff14;
            --neon-green-dim: rgba(57,255,20,0.08);
            --text-primary: #e8ecf1;
            --text-secondary: #8892a4;
            --text-muted: #4a5568;
            --border-subtle: rgba(255,255,255,0.04);
            --border-glass: rgba(255,255,255,0.07);
            --border-neon: rgba(0,229,255,0.3);
            --font-display: 'Sora', sans-serif;
            --font-body: 'DM Sans', sans-serif;
            --font-mono: 'JetBrains Mono', monospace;
            --radius-sm: 8px;
            --radius-md: 14px;
            --radius-lg: 22px;
            --radius-xl: 28px;
            --ease-out-expo: cubic-bezier(0.16, 1, 0.3, 1);
            --ease-spring: cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: var(--font-body);
            font-weight: 400;
            background: var(--void);
            color: var(--text-primary);
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            overflow: hidden;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            cursor: crosshair;
        }

        /* Animated liquid gradient blobs */
        .bg-blobs {
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: 0;
            overflow: hidden;
        }
        .bg-blobs .blob {
            position: absolute;
            border-radius: 50%;
            filter: blur(120px);
            opacity: 0.35;
            animation-timing-function: ease-in-out;
            animation-iteration-count: infinite;
        }
        .blob-1 {
            width: 700px; height: 700px;
            background: radial-gradient(circle, var(--neon-cyan) 0%, transparent 70%);
            top: -15%; left: -10%;
            animation: blobFloat1 18s infinite;
        }
        .blob-2 {
            width: 600px; height: 600px;
            background: radial-gradient(circle, var(--neon-magenta) 0%, transparent 70%);
            bottom: -20%; right: -8%;
            animation: blobFloat2 22s infinite;
        }
        .blob-3 {
            width: 500px; height: 500px;
            background: radial-gradient(circle, var(--neon-green) 0%, transparent 70%);
            top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            animation: blobFloat3 20s infinite;
        }
        @keyframes blobFloat1 {
            0%, 100% { transform: translate(0, 0) scale(1); }
            25% { transform: translate(120px, -60px) scale(1.15); }
            50% { transform: translate(-40px, 80px) scale(0.9); }
            75% { transform: translate(-100px, -30px) scale(1.1); }
        }
        @keyframes blobFloat2 {
            0%, 100% { transform: translate(0, 0) scale(1); }
            33% { transform: translate(-80px, 40px) scale(1.2); }
            66% { transform: translate(60px, -70px) scale(0.85); }
        }
        @keyframes blobFloat3 {
            0%, 100% { transform: translate(-50%, -50%) scale(1); }
            50% { transform: translate(-50%, -50%) scale(1.3) rotate(15deg); }
        }

        /* Noise grain overlay */
        .grain {
            position: fixed; inset: 0;
            opacity: 0.025;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 300 300' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.75' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
            background-size: 180px 180px;
            pointer-events: none;
            z-index: 0;
        }

        /* Cursor trail */
        .cursor-trail {
            position: fixed;
            pointer-events: none;
            z-index: 9999;
            width: 180px; height: 180px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(0,229,255,0.06) 0%, transparent 70%);
            transform: translate(-50%, -50%);
            transition: left 0.4s linear, top 0.4s linear;
        }

        .chat-container {
            width: 100%;
            max-width: 1060px;
            height: 94vh;
            background: var(--glass);
            backdrop-filter: blur(80px) saturate(160%);
            -webkit-backdrop-filter: blur(80px) saturate(160%);
            border: 1px solid var(--border-glass);
            border-radius: var(--radius-xl);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            position: relative;
            z-index: 1;
            box-shadow:
                0 0 0 1px rgba(255,255,255,0.02) inset,
                0 40px 120px rgba(0,0,0,0.6),
                0 0 80px rgba(0,229,255,0.03),
                0 0 40px rgba(255,45,149,0.02);
            transition: border-color 0.5s ease;
        }
        .chat-container:hover {
            border-color: rgba(0,229,255,0.12);
        }

        .chat-header {
            padding: 20px 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border-glass);
            background: rgba(6,11,22,0.6);
            flex-shrink: 0;
            position: relative;
        }
        .chat-header::after {
            content: '';
            position: absolute;
            bottom: -1px; left: 0; right: 0;
            height: 1px;
            background: linear-gradient(90deg, transparent, var(--neon-cyan), var(--neon-magenta), transparent);
            opacity: 0.3;
            animation: headerLine 4s ease-in-out infinite;
        }
        @keyframes headerLine {
            0%, 100% { opacity: 0.2; }
            50% { opacity: 0.5; }
        }

        .chat-header .brand {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .chat-header .brand-logo {
            width: 40px; height: 40px;
            border-radius: var(--radius-sm);
            background: linear-gradient(135deg, var(--neon-cyan) 0%, var(--neon-magenta) 100%);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.1em;
            position: relative;
            box-shadow: 0 0 24px var(--neon-cyan-glow);
        }
        .chat-header .brand-logo::after {
            content: '';
            position: absolute; inset: -3px;
            border-radius: 11px;
            background: linear-gradient(135deg, var(--neon-cyan), var(--neon-magenta));
            z-index: -1;
            opacity: 0.4;
            filter: blur(8px);
            animation: logoPulse 3s ease-in-out infinite;
        }
        @keyframes logoPulse {
            0%, 100% { opacity: 0.3; }
            50% { opacity: 0.6; }
        }
        .chat-header h1 {
            font-family: var(--font-display);
            font-size: 1.3em;
            font-weight: 800;
            letter-spacing: -0.03em;
            background: linear-gradient(135deg, var(--text-primary) 0%, var(--neon-cyan) 50%, var(--text-primary) 100%);
            background-size: 200% auto;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            animation: titleShine 4s ease-in-out infinite;
        }
        @keyframes titleShine {
            0%, 100% { background-position: 0% center; }
            50% { background-position: 200% center; }
        }
        .online-count {
            font-family: var(--font-mono);
            font-size: 0.72em;
            font-weight: 500;
            color: var(--neon-cyan);
            margin-top: 2px;
            letter-spacing: 0.05em;
        }

        .header-actions {
            display: flex;
            gap: 10px;
        }
        .btn-ghost {
            background: transparent;
            border: 1px solid var(--border-glass);
            color: var(--text-secondary);
            padding: 9px 18px;
            border-radius: 9999px;
            cursor: pointer;
            font-size: 0.78em;
            font-family: var(--font-body);
            font-weight: 500;
            letter-spacing: 0.02em;
            transition: all 0.3s var(--ease-out-expo);
            white-space: nowrap;
            position: relative;
            overflow: hidden;
        }
        .btn-ghost::before {
            content: '';
            position: absolute; inset: 0;
            background: linear-gradient(135deg, var(--neon-cyan-dim), var(--neon-magenta-dim));
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .btn-ghost:hover {
            border-color: var(--border-neon);
            color: var(--neon-cyan);
            box-shadow: 0 0 20px var(--neon-cyan-dim);
            transform: translateY(-1px);
        }
        .btn-ghost:hover::before {
            opacity: 1;
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
            padding: 28px 32px;
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 8px;
            scrollbar-width: thin;
            scrollbar-color: var(--neon-cyan-dim) transparent;
        }
        .messages-list::-webkit-scrollbar { width: 4px; }
        .messages-list::-webkit-scrollbar-track { background: transparent; }
        .messages-list::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, var(--neon-cyan), var(--neon-magenta));
            border-radius: 10px;
            opacity: 0.5;
        }
        .messages-list::-webkit-scrollbar-thumb:hover {
            opacity: 0.8;
        }

        .message-item {
            padding: 12px 18px;
            border-radius: var(--radius-md);
            background: rgba(255,255,255,0.018);
            border: 1px solid var(--border-subtle);
            max-width: 70%;
            word-wrap: break-word;
            align-self: flex-start;
            animation: msgSlideIn 0.5s var(--ease-out-expo) both;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        .message-item::after {
            content: '';
            position: absolute; inset: 0;
            border-radius: inherit;
            background: linear-gradient(135deg, transparent 60%, var(--neon-cyan-dim) 100%);
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .message-item:hover {
            background: rgba(255,255,255,0.035);
            border-color: rgba(0,229,255,0.15);
            transform: translateX(3px);
        }
        .message-item:hover::after {
            opacity: 1;
        }
        .message-item.own {
            align-self: flex-end;
            background: linear-gradient(135deg, rgba(0,229,255,0.08), rgba(255,45,149,0.05));
            border-color: rgba(0,229,255,0.15);
        }
        .message-item.own:hover {
            background: linear-gradient(135deg, rgba(0,229,255,0.14), rgba(255,45,149,0.08));
            border-color: rgba(0,229,255,0.3);
            transform: translateX(-3px);
        }
        .message-item.admin {
            background: linear-gradient(135deg, rgba(57,255,20,0.06), rgba(57,255,20,0.02));
            border-left: 3px solid var(--neon-green);
            border-color: rgba(57,255,20,0.18);
        }
        .message-item.admin:hover {
            background: linear-gradient(135deg, rgba(57,255,20,0.1), rgba(57,255,20,0.04));
            border-color: rgba(57,255,20,0.3);
        }

        .message-header {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            margin-bottom: 6px;
        }
        .message-name {
            font-family: var(--font-display);
            font-weight: 700;
            font-size: 0.76em;
            letter-spacing: 0.03em;
            color: var(--neon-cyan);
        }
        .message-item.own .message-name { color: var(--neon-magenta); }
        .message-item.admin .message-name { color: var(--neon-green); }
        .message-time {
            font-family: var(--font-mono);
            font-size: 0.62em;
            color: var(--text-muted);
            margin-left: auto;
            padding-left: 14px;
            white-space: nowrap;
            font-weight: 500;
        }
        .message-content {
            font-size: 0.88em;
            line-height: 1.65;
            color: var(--text-primary);
            font-weight: 400;
        }
        .message-content a {
            color: var(--neon-cyan);
            text-decoration: none;
            border-bottom: 1px solid rgba(0,229,255,0.25);
            transition: border-color 0.2s;
        }
        .message-content a:hover {
            border-color: var(--neon-cyan);
        }
        .message-content img {
            max-width: 100%;
            border-radius: var(--radius-sm);
            margin-top: 10px;
            border: 1px solid var(--border-glass);
            transition: transform 0.3s ease;
        }
        .message-content img:hover {
            transform: scale(1.02);
        }

        .input-area {
            padding: 16px 32px 20px;
            border-top: 1px solid var(--border-glass);
            background: rgba(6,11,22,0.5);
            flex-shrink: 0;
            position: relative;
        }
        .toolbar {
            display: flex;
            gap: 8px;
            margin-bottom: 12px;
        }
        .toolbar button {
            background: rgba(255,255,255,0.02);
            border: 1px solid var(--border-subtle);
            padding: 6px 14px;
            border-radius: 9999px;
            cursor: pointer;
            font-size: 0.75em;
            font-family: var(--font-body);
            font-weight: 500;
            color: var(--text-secondary);
            transition: all 0.3s var(--ease-out-expo);
            letter-spacing: 0.01em;
        }
        .toolbar button:hover {
            background: var(--neon-cyan-dim);
            border-color: rgba(0,229,255,0.25);
            color: var(--neon-cyan);
            transform: translateY(-2px);
            box-shadow: 0 4px 16px var(--neon-cyan-dim);
        }

        .input-row {
            display: flex;
            gap: 12px;
        }
        .input-row input[type="text"] {
            flex: 1;
            padding: 14px 22px;
            background: rgba(255,255,255,0.03);
            border: 1px solid var(--border-glass);
            border-radius: 9999px;
            font-size: 0.88em;
            font-family: var(--font-body);
            font-weight: 400;
            color: var(--text-primary);
            outline: none;
            transition: all 0.4s var(--ease-out-expo);
        }
        .input-row input[type="text"]::placeholder {
            color: var(--text-muted);
            font-style: italic;
        }
        .input-row input[type="text"]:focus {
            border-color: var(--neon-cyan);
            background: rgba(0,229,255,0.04);
            box-shadow: 0 0 0 5px rgba(0,229,255,0.06), 0 0 24px rgba(0,229,255,0.08);
        }

        .btn-send {
            padding: 14px 32px;
            background: linear-gradient(135deg, var(--neon-cyan) 0%, #00b8d4 100%);
            color: #02040a;
            border: none;
            border-radius: 9999px;
            cursor: pointer;
            font-size: 0.85em;
            font-family: var(--font-display);
            font-weight: 700;
            letter-spacing: 0.04em;
            transition: all 0.35s var(--ease-out-expo);
            position: relative;
            overflow: hidden;
        }
        .btn-send::before {
            content: '';
            position: absolute; inset: 0;
            background: linear-gradient(135deg, transparent 0%, rgba(255,255,255,0.3) 50%, transparent 100%);
            transform: translateX(-100%) skewX(-15deg);
            transition: transform 0.7s ease;
        }
        .btn-send:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 40px rgba(0,229,255,0.4), 0 0 60px rgba(0,229,255,0.15);
        }
        .btn-send:hover::before {
            transform: translateX(100%) skewX(-15deg);
        }
        .btn-send:active {
            transform: translateY(0) scale(0.96);
        }

        /* Sidebar */
        .sidebar {
            width: 240px;
            border-left: 1px solid var(--border-glass);
            background: rgba(4,8,18,0.45);
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
        }
        .sidebar-header {
            padding: 18px 22px;
            border-bottom: 1px solid var(--border-glass);
            font-family: var(--font-display);
            font-weight: 700;
            font-size: 0.78em;
            letter-spacing: 0.08em;
            color: var(--text-secondary);
            text-transform: uppercase;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .sidebar-header::before {
            content: '';
            width: 6px; height: 6px;
            border-radius: 50%;
            background: var(--neon-green);
            box-shadow: 0 0 8px var(--neon-green);
            animation: dotPulse 2s ease-in-out infinite;
        }
        .users-list {
            flex: 1;
            overflow-y: auto;
            padding: 10px 14px;
            list-style: none;
            scrollbar-width: thin;
            scrollbar-color: var(--neon-cyan-dim) transparent;
        }
        .users-list::-webkit-scrollbar { width: 3px; }
        .users-list::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, var(--neon-cyan), var(--neon-magenta));
            border-radius: 10px;
        }
        .user-item {
            padding: 10px 14px;
            border-radius: var(--radius-sm);
            margin-bottom: 4px;
            font-size: 0.8em;
            font-weight: 400;
            display: flex;
            align-items: center;
            gap: 12px;
            color: var(--text-secondary);
            transition: all 0.25s var(--ease-out-expo);
            border: 1px solid transparent;
            cursor: default;
        }
        .user-item:hover {
            background: rgba(0,229,255,0.04);
            border-color: rgba(0,229,255,0.1);
            color: var(--text-primary);
            transform: translateX(2px);
        }
        .user-item .dot {
            width: 8px; height: 8px;
            border-radius: 50%;
            background: var(--neon-cyan);
            box-shadow: 0 0 10px var(--neon-cyan-glow);
            flex-shrink: 0;
            animation: dotPulse 2.5s ease-in-out infinite;
        }
        .user-item.admin .dot {
            background: var(--neon-green);
            box-shadow: 0 0 10px rgba(57,255,20,0.5);
            animation: dotPulse 1.8s ease-in-out infinite;
        }
        .user-item.admin {
            background: var(--neon-green-dim);
            border-color: rgba(57,255,20,0.15);
            color: var(--neon-green);
            font-weight: 500;
        }
        @keyframes dotPulse {
            0%, 100% { opacity: 0.5; transform: scale(1); }
            50% { opacity: 1; transform: scale(1.4); }
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed; inset: 0;
            background: rgba(2,4,10,0.88);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }
        .modal.show { display: flex; }
        .modal-content {
            background: var(--abyss);
            padding: 36px;
            border-radius: var(--radius-lg);
            width: 90%;
            max-width: 460px;
            border: 1px solid var(--border-glass);
            box-shadow:
                0 40px 100px rgba(0,0,0,0.7),
                0 0 0 1px rgba(255,255,255,0.02) inset,
                0 0 60px rgba(0,229,255,0.04);
            animation: modalReveal 0.45s var(--ease-out-expo);
            position: relative;
            overflow: hidden;
        }
        .modal-content::before {
            content: '';
            position: absolute; top: 0; left: 0; right: 0;
            height: 1px;
            background: linear-gradient(90deg, transparent, var(--neon-cyan), var(--neon-magenta), transparent);
            opacity: 0.5;
        }
        @keyframes modalReveal {
            from { opacity: 0; transform: translateY(50px) scale(0.9); filter: blur(8px); }
            to { opacity: 1; transform: translateY(0) scale(1); filter: blur(0); }
        }
        .modal-content h3 {
            font-family: var(--font-display);
            font-size: 1.2em;
            font-weight: 700;
            letter-spacing: -0.02em;
            margin-bottom: 22px;
            color: var(--text-primary);
        }
        .modal-content input,
        .modal-content select {
            width: 100%;
            padding: 13px 18px;
            background: rgba(255,255,255,0.03);
            border: 1px solid var(--border-glass);
            border-radius: var(--radius-sm);
            margin-bottom: 10px;
            font-size: 0.9em;
            font-family: var(--font-body);
            color: var(--text-primary);
            outline: none;
            transition: all 0.3s var(--ease-out-expo);
        }
        .modal-content input:focus,
        .modal-content select:focus {
            border-color: var(--neon-cyan);
            box-shadow: 0 0 0 4px rgba(0,229,255,0.06);
            background: rgba(0,229,255,0.04);
        }
        .modal-content input::placeholder {
            color: var(--text-muted);
        }

        .btn-primary {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, var(--neon-cyan), #00b8d4);
            color: #02040a;
            border: none;
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-size: 0.88em;
            font-family: var(--font-display);
            font-weight: 700;
            letter-spacing: 0.03em;
            transition: all 0.35s var(--ease-out-expo);
            position: relative;
            overflow: hidden;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 35px rgba(0,229,255,0.35);
        }
        .btn-primary:active {
            transform: translateY(0) scale(0.97);
        }
        .btn-secondary {
            width: 100%;
            padding: 14px;
            background: rgba(255,255,255,0.03);
            color: var(--text-secondary);
            border: 1px solid var(--border-glass);
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-size: 0.88em;
            font-family: var(--font-display);
            font-weight: 500;
            margin-top: 8px;
            transition: all 0.3s var(--ease-out-expo);
        }
        .btn-secondary:hover {
            background: rgba(0,229,255,0.06);
            border-color: rgba(0,229,255,0.25);
            color: var(--neon-cyan);
        }
        .btn-danger {
            background: rgba(255,45,149,0.12);
            color: var(--neon-magenta);
            border: 1px solid rgba(255,45,149,0.25);
        }
        .btn-danger:hover {
            background: rgba(255,45,149,0.22);
            box-shadow: 0 8px 30px rgba(255,45,149,0.2);
        }

        .system-message {
            text-align: center;
            color: var(--text-muted);
            font-size: 0.78em;
            padding: 24px;
            font-style: italic;
            font-family: var(--font-mono);
            align-self: center;
            animation: fadeIn 1s ease;
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .admin-badge {
            background: linear-gradient(135deg, var(--neon-green), #00c853);
            color: #02040a;
            font-size: 0.6em;
            padding: 3px 8px;
            border-radius: 9999px;
            margin-left: 5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            font-family: var(--font-display);
            box-shadow: 0 0 10px rgba(57,255,20,0.3);
        }
        .delete-btn {
            background: none;
            border: 1px solid rgba(255,45,149,0.25);
            color: var(--neon-magenta);
            padding: 2px 8px;
            border-radius: 9999px;
            font-size: 0.68em;
            cursor: pointer;
            margin-left: 10px;
            transition: all 0.25s var(--ease-out-expo);
            font-family: var(--font-display);
            font-weight: 700;
        }
        .delete-btn:hover {
            background: rgba(255,45,149,0.18);
            box-shadow: 0 0 14px rgba(255,45,149,0.3);
            transform: scale(1.1);
        }

        /* Emoji panel */
        .emoji-panel {
            display: none;
            position: absolute;
            bottom: 100%;
            left: 32px;
            margin-bottom: 10px;
            background: var(--abyss);
            border: 1px solid var(--border-glass);
            border-radius: var(--radius-md);
            padding: 14px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.6), 0 0 30px rgba(0,229,255,0.05);
            z-index: 100;
            max-width: 340px;
            backdrop-filter: blur(40px);
            -webkit-backdrop-filter: blur(40px);
            animation: emojiPop 0.25s var(--ease-spring);
        }
        .emoji-panel.show { display: block; }
        @keyframes emojiPop {
            from { opacity: 0; transform: translateY(8px) scale(0.92); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .emoji-grid {
            display: grid;
            grid-template-columns: repeat(8, 1fr);
            gap: 5px;
        }
        .emoji-grid span {
            cursor: pointer;
            font-size: 1.2em;
            padding: 6px;
            text-align: center;
            border-radius: var(--radius-sm);
            transition: all 0.2s var(--ease-spring);
        }
        .emoji-grid span:hover {
            background: var(--neon-cyan-dim);
            transform: scale(1.3);
        }

        /* Email verify */
        .email-verify-row {
            display: flex;
            gap: 8px;
            margin-bottom: 8px;
        }
        .email-verify-row input {
            flex: 1;
            background: rgba(255,255,255,0.03);
            border: 1px solid var(--border-glass);
            border-radius: var(--radius-sm);
            padding: 11px 16px;
            color: var(--text-primary);
            outline: none;
            font-family: var(--font-body);
            transition: all 0.3s var(--ease-out-expo);
        }
        .email-verify-row input:focus {
            border-color: var(--neon-cyan);
            box-shadow: 0 0 0 3px rgba(0,229,255,0.06);
        }
        .email-verify-row button {
            white-space: nowrap;
            padding: 11px 16px;
            background: linear-gradient(135deg, var(--neon-cyan), #00b8d4);
            color: #02040a;
            border: none;
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-weight: 600;
            font-family: var(--font-display);
            font-size: 0.82em;
            letter-spacing: 0.02em;
            transition: all 0.3s var(--ease-out-expo);
        }
        .email-verify-row button:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 20px rgba(0,229,255,0.3);
        }
        .email-verify-row button:disabled {
            background: rgba(255,255,255,0.05);
            color: var(--text-muted);
            cursor: not-allowed;
            box-shadow: none;
            transform: none;
        }
        .countdown {
            color: var(--text-muted);
            font-size: 0.76em;
            font-family: var(--font-mono);
        }

        /* Settings */
        .settings-panel {
            display: none;
            margin-top: 18px;
            padding-top: 18px;
            border-top: 1px solid var(--border-glass);
        }
        .settings-panel.show { display: block; }
        .form-group {
            margin-bottom: 14px;
        }
        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-size: 0.76em;
            color: var(--text-secondary);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            font-family: var(--font-display);
        }
        .toggle-row {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 16px;
        }
        .toggle-row input[type="checkbox"] {
            width: 46px; height: 26px;
            appearance: none;
            background: rgba(255,255,255,0.06);
            border: 1px solid var(--border-glass);
            border-radius: 26px;
            position: relative;
            cursor: pointer;
            transition: all 0.35s var(--ease-out-expo);
            flex-shrink: 0;
        }
        .toggle-row input[type="checkbox"]:checked {
            background: var(--neon-cyan);
            border-color: var(--neon-cyan);
            box-shadow: 0 0 16px var(--neon-cyan-glow);
        }
        .toggle-row input[type="checkbox"]::after {
            content: '';
            position: absolute;
            width: 20px; height: 20px;
            background: #fff;
            border-radius: 50%;
            top: 2px; left: 2px;
            transition: transform 0.35s var(--ease-spring);
        }
        .toggle-row input[type="checkbox"]:checked::after {
            transform: translateX(20px);
        }
        .toggle-row label {
            color: var(--text-secondary);
            font-size: 0.88em;
        }
        hr {
            border: none;
            border-top: 1px solid var(--border-glass);
            margin: 16px 0;
        }
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 11px 16px;
            background: rgba(255,255,255,0.03);
            border: 1px solid var(--border-glass);
            border-radius: var(--radius-sm);
            font-size: 0.88em;
            font-family: var(--font-body);
            color: var(--text-primary);
            outline: none;
            transition: all 0.3s var(--ease-out-expo);
        }
        .form-group input:focus,
        .form-group select:focus {
            border-color: var(--neon-cyan);
        }
        .form-group select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%238892a4' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 14px center;
        }

        /* Animations */
        @keyframes msgSlideIn {
            from { opacity: 0; transform: translateY(20px) scale(0.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        @keyframes shimmer {
            0% { background-position: -200% center; }
            100% { background-position: 200% center; }
        }

        @media (max-width: 640px) {
            .sidebar { display: none; }
            .chat-container { height: 100vh; border-radius: 0; max-width: 100%; }
            .chat-header { padding: 16px 20px; }
            .messages-list { padding: 18px 20px; }
            .input-area { padding: 12px 20px 16px; }
            .message-item { max-width: 85%; }
            .btn-ghost { padding: 7px 14px; font-size: 0.72em; }
            .cursor-trail { display: none; }
        }
    </style>
</head>
<body>
    <!-- Animated background blobs -->
    <div class="bg-blobs">
        <div class="blob blob-1"></div>
        <div class="blob blob-2"></div>
        <div class="blob blob-3"></div>
    </div>
    <div class="grain"></div>
    <div class="cursor-trail" id="cursorTrail"></div>

    <div class="chat-container">
        <div class="chat-header">
            <div class="brand">
                <div class="brand-logo">◆</div>
                <div>
                    <h1>Nebula Chat</h1>
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
                <p style="font-size:0.8em;color:var(--text-muted);margin-bottom:12px;">需要邮箱验证后才能聊天</p>
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

            <div id="adminPanel" style="display:none; margin-top:18px;">
                <button class="btn-primary btn-danger" onclick="clearAllMessages()">清空所有消息</button>
                <button class="btn-secondary" onclick="toggleSettings()" style="margin-bottom:8px;">邮箱设置</button>
                <button class="btn-secondary" onclick="toggleDbSettings()" style="margin-bottom:8px;">数据库设置</button>

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
                        <input type="text" id="smtpFromName" value="Nebula Chat">
                    </div>

                    <button class="btn-primary" onclick="saveEmailConfig()">保存配置</button>
                    <button class="btn-secondary" onclick="testEmailConfig()">测试发送</button>
                </div>

                <div class="settings-panel" id="dbSettingsPanel">
                    <div class="toggle-row">
                        <input type="checkbox" id="dbEnabled">
                        <label>启用数据库存储（双存储模式）</label>
                    </div>

                    <div class="form-group">
                        <label>数据库主机</label>
                        <input type="text" id="dbHost" placeholder="localhost">
                    </div>
                    <div class="form-group">
                        <label>数据库名</label>
                        <input type="text" id="dbName" placeholder="chatroom">
                    </div>
                    <div class="form-group">
                        <label>用户名</label>
                        <input type="text" id="dbUser" placeholder="root">
                    </div>
                    <div class="form-group">
                        <label>密码</label>
                        <input type="password" id="dbPass" placeholder="数据库密码">
                    </div>

                    <button class="btn-primary" onclick="saveDbConfig()">保存配置</button>
                    <button class="btn-secondary" onclick="testDbConfig()">测试连接</button>
                    <button class="btn-secondary" onclick="syncToDb()">同步现有数据到数据库</button>
                    <p id="dbStatus" style="font-size:0.78em;color:var(--text-muted);margin-top:10px;"></p>
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

        // Cursor trail
        const trail = document.getElementById('cursorTrail');
        let mouseX = -200, mouseY = -200;
        document.addEventListener('mousemove', e => {
            mouseX = e.clientX;
            mouseY = e.clientY;
        });
        function animateTrail() {
            trail.style.left = mouseX + 'px';
            trail.style.top = mouseY + 'px';
            requestAnimationFrame(animateTrail);
        }
        animateTrail();

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
                loadDbConfig();
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

        async function loadDbConfig() {
            const response = await fetch('?action=get_db_config');
            const config = await response.json();
            document.getElementById('dbEnabled').checked = config.enabled;
            document.getElementById('dbHost').value = config.host;
            document.getElementById('dbName').value = config.dbname;
            document.getElementById('dbUser').value = config.user;
        }

        function toggleSettings() { document.getElementById('settingsPanel').classList.toggle('show'); }
        function toggleDbSettings() { document.getElementById('dbSettingsPanel').classList.toggle('show'); }

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

        async function saveDbConfig() {
            const config = {
                enabled: document.getElementById('dbEnabled').checked,
                host: document.getElementById('dbHost').value,
                dbname: document.getElementById('dbName').value,
                user: document.getElementById('dbUser').value,
                pass: document.getElementById('dbPass').value
            };
            const statusEl = document.getElementById('dbStatus');
            statusEl.textContent = '保存中...';
            statusEl.style.color = 'var(--text-secondary)';
            const response = await fetch('?action=save_db_config', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(config)
            });
            const data = await response.json();
            if (data.success) {
                statusEl.textContent = data.message || '配置已保存';
                statusEl.style.color = 'var(--neon-green)';
            } else {
                statusEl.textContent = data.error || '保存失败';
                statusEl.style.color = 'var(--neon-magenta)';
            }
        }

        async function testDbConfig() {
            const config = {
                host: document.getElementById('dbHost').value,
                dbname: document.getElementById('dbName').value,
                user: document.getElementById('dbUser').value,
                pass: document.getElementById('dbPass').value
            };
            const statusEl = document.getElementById('dbStatus');
            statusEl.textContent = '连接测试中...';
            statusEl.style.color = 'var(--text-secondary)';
            const response = await fetch('?action=test_db_config', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(config)
            });
            const data = await response.json();
            if (data.success) {
                statusEl.textContent = data.message || '连接成功';
                statusEl.style.color = 'var(--neon-green)';
            } else {
                statusEl.textContent = data.error || '连接失败';
                statusEl.style.color = 'var(--neon-magenta)';
            }
        }

        async function syncToDb() {
            const statusEl = document.getElementById('dbStatus');
            statusEl.textContent = '同步中...';
            statusEl.style.color = 'var(--text-secondary)';
            const response = await fetch('?action=sync_to_db', { method: 'POST' });
            const data = await response.json();
            if (data.success) {
                statusEl.textContent = data.message || '同步完成';
                statusEl.style.color = 'var(--neon-green)';
            } else {
                statusEl.textContent = data.error || '同步失败';
                statusEl.style.color = 'var(--neon-magenta)';
            }
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
                li.style.animationDelay = `${Math.min(i * 0.015, 0.25)}s`;
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
            users.forEach((u, i) => {
                const li = document.createElement('li');
                li.className = 'user-item' + (u.is_admin ? ' admin' : '');
                li.style.animationDelay = `${i * 0.03}s`;
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
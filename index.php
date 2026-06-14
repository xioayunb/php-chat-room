<?php
/**
 * PHP Chat Room - 完善的单文件聊天室
 * 无需数据库，适合虚拟主机部署
 */

session_start();

// 配置文件
$messages_buffer_file = "messages.json";
$users_file = "users.json";
$banned_file = "banned.json";
$messages_buffer_size = 200;
$admin_password = "admin123"; // 建议修改此密码

// 创建必要文件
foreach ([$messages_buffer_file, $users_file, $banned_file] as $file) {
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
            if ($now - $user['last_active'] < 60) { // 60秒内活跃视为在线
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
        
        // 更新用户信息
        $users = read_json($users_file);
        $users[$ip] = [
            'name' => $name,
            'last_active' => time(),
            'join_time' => isset($users[$ip]) ? $users[$ip]['join_time'] : time(),
            'is_admin' => isset($users[$ip]) ? $users[$ip]['is_admin'] : false
        ];
        write_json($users_file, $users);
        
        // 添加消息
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
    
    // 禁言用户
    if ($_GET['action'] === 'ban_user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_SESSION['is_admin'])) {
            echo json_encode(['success' => false, 'error' => '无权限']);
            exit;
        }
        $input = json_decode(file_get_contents('php://input'), true);
        $ban_ip = isset($input['ip']) ? $input['ip'] : '';
        $duration = isset($input['duration']) ? intval($input['duration']) : 3600;
        
        if (!empty($ban_ip)) {
            $banned = read_json($banned_file);
            $banned[$ban_ip] = time() + $duration;
            write_json($banned_file, $banned);
        }
        echo json_encode(['success' => true]);
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
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHP 聊天室</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 10px;
        }
        .chat-container {
            width: 100%;
            max-width: 900px;
            height: 90vh;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .chat-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .chat-header h1 { font-size: 1.3em; font-weight: 600; }
        .header-buttons {
            display: flex;
            gap: 8px;
        }
        .header-buttons button {
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            padding: 6px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.85em;
            transition: background 0.2s;
        }
        .header-buttons button:hover { background: rgba(255,255,255,0.3); }
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
            padding: 15px;
            list-style: none;
        }
        .message-item {
            margin-bottom: 12px;
            animation: fadeIn 0.3s ease;
            padding: 8px 12px;
            border-radius: 12px;
            background: #f5f5f5;
            max-width: 80%;
            word-wrap: break-word;
        }
        .message-item.own {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            margin-left: auto;
        }
        .message-item.admin {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
        }
        .message-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 4px;
        }
        .message-name {
            font-weight: 600;
            font-size: 0.85em;
            color: #667eea;
        }
        .message-item.own .message-name { color: rgba(255,255,255,0.9); }
        .message-time {
            font-size: 0.7em;
            color: #999;
        }
        .message-item.own .message-time { color: rgba(255,255,255,0.7); }
        .message-content {
            font-size: 0.95em;
            line-height: 1.5;
        }
        .message-content img {
            max-width: 100%;
            border-radius: 8px;
            margin-top: 5px;
        }
        .input-area {
            padding: 12px 15px;
            border-top: 1px solid #eee;
            background: #fafafa;
        }
        .input-row {
            display: flex;
            gap: 8px;
            margin-bottom: 8px;
        }
        .input-row input[type="text"] {
            flex: 1;
            padding: 10px 14px;
            border: 1px solid #ddd;
            border-radius: 20px;
            font-size: 0.95em;
            outline: none;
            transition: border-color 0.2s;
        }
        .input-row input[type="text"]:focus {
            border-color: #667eea;
        }
        .input-row button {
            padding: 10px 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 20px;
            cursor: pointer;
            font-size: 0.9em;
            transition: transform 0.1s, box-shadow 0.2s;
        }
        .input-row button:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
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
            bottom: 70px;
            left: 15px;
            background: white;
            border: 1px solid #ddd;
            border-radius: 12px;
            padding: 10px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            z-index: 100;
            max-width: 300px;
        }
        .emoji-panel.show { display: block; }
        .emoji-grid {
            display: grid;
            grid-template-columns: repeat(8, 1fr);
            gap: 5px;
        }
        .emoji-grid span {
            cursor: pointer;
            font-size: 1.3em;
            padding: 4px;
            text-align: center;
            border-radius: 4px;
            transition: background 0.1s;
        }
        .emoji-grid span:hover { background: #f0f0f0; }
        .sidebar {
            width: 200px;
            border-left: 1px solid #eee;
            background: #fafafa;
            display: flex;
            flex-direction: column;
        }
        .sidebar-header {
            padding: 12px 15px;
            border-bottom: 1px solid #eee;
            font-weight: 600;
            color: #333;
            font-size: 0.9em;
        }
        .online-count {
            color: #667eea;
            font-size: 0.85em;
            margin-top: 2px;
        }
        .users-list {
            flex: 1;
            overflow-y: auto;
            padding: 8px;
            list-style: none;
        }
        .user-item {
            padding: 8px 10px;
            border-radius: 8px;
            margin-bottom: 4px;
            font-size: 0.85em;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .user-item::before {
            content: '';
            width: 8px;
            height: 8px;
            background: #4caf50;
            border-radius: 50%;
            flex-shrink: 0;
        }
        .user-item.admin::before { background: #ff9800; }
        .user-item.admin {
            background: #fff3cd;
            font-weight: 600;
        }
        .modal {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.5);
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }
        .modal.show { display: flex; }
        .modal-content {
            background: white;
            padding: 25px;
            border-radius: 16px;
            width: 90%;
            max-width: 400px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        .modal-content h3 {
            margin-bottom: 15px;
            color: #333;
        }
        .modal-content input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            margin-bottom: 12px;
            font-size: 1em;
        }
        .modal-content button {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1em;
        }
        .system-message {
            text-align: center;
            color: #999;
            font-size: 0.85em;
            padding: 8px;
            font-style: italic;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @media (max-width: 600px) {
            .sidebar { display: none; }
            .chat-container { height: 100vh; border-radius: 0; }
            body { padding: 0; }
        }
        .admin-badge {
            background: #ff9800;
            color: white;
            font-size: 0.7em;
            padding: 2px 6px;
            border-radius: 4px;
            margin-left: 4px;
        }
        .delete-btn {
            background: #ff4444;
            color: white;
            border: none;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.75em;
            cursor: pointer;
            margin-left: 8px;
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
        
        // 表情列表
        const emojis = ['😀','😃','😄','😁','😅','😂','🤣','😊','😇','🙂','🙃','😉','😌','😍','🥰','😘','😗','😙','😚','😋','😛','😝','😜','🤪','🤨','🧐','🤓','😎','🥸','🤩','🥳','😏','😒','😞','😔','😟','😕','🙁','☹️','😣','😖','😫','😩','🥺','😢','😭','😤','😠','😡','🤬','🤯','😳','🥵','🥶','😱','😨','😰','😥','😓','🤗','🤔','🤭','🤫','🤥','😶','😐','😑','😬','🙄','😯','😦','😧','😮','😲','🥱','😴','🤤','😪','😵','🤐','🥴','🤢','🤮','🤧','😷','🤒','🤕','🤑','🤠','😈','👿','👹','👺','🤡','💩','👻','💀','☠️','👽','👾','🤖','🎃','😺','😸','😹','😻','😼','😽','🙀','😿','😾'];
        
        // 初始化
        document.addEventListener('DOMContentLoaded', () => {
            if (!localStorage.getItem('chat_name')) {
                showNameModal();
            }
            initEmojiPanel();
            pollMessages();
            pollUsers();
            heartbeat();
            setInterval(pollMessages, 2000);
            setInterval(pollUsers, 5000);
            setInterval(heartbeat, 30000);
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
        
        // 显示管理员模态框
        function showAdminModal() {
            document.getElementById('adminModal').classList.add('show');
            if (isAdmin) {
                document.getElementById('adminPanel').style.display = 'block';
            }
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
                alert('管理员登录成功');
            } else {
                alert(data.error || '密码错误');
            }
        }
        
        // 发送消息
        async function sendMessage() {
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
                    // 转换URL为链接
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

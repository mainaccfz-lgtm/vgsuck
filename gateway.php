<?php
header('Content-Type: application/json');

// ==================== デバッグ設定 ====================
define('DEBUG_LOG', __DIR__ . '/debug.log');

function debug_log($msg) {
    file_put_contents(DEBUG_LOG, date('Y-m-d H:i:s') . " " . $msg . "\n", FILE_APPEND);
}

// ==================== 定数定義 ====================
define('SESSIONS_FILE', __DIR__ . '/sessions.json');
define('TASKS_DATA_FILE', __DIR__ . '/tasks_data.json');
define('MODULES_DIR', __DIR__ . '/modules/');
define('GATEWAY_API_KEY', 'fiverdbull');

// モジュールディレクトリ作成
if (!is_dir(MODULES_DIR)) {
    mkdir(MODULES_DIR, 0777, true);
}

// ==================== セッション管理 ====================
function loadSessions(): array {
    return file_exists(SESSIONS_FILE) ? json_decode(file_get_contents(SESSIONS_FILE), true) ?: [] : [];
}

function saveSessions(array $sessions): void {
    file_put_contents(SESSIONS_FILE, json_encode($sessions, JSON_PRETTY_PRINT));
}

function generateSessionId(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        random_int(0, 0xffff), random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0x0fff) | 0x4000,
        random_int(0, 0x3fff) | 0x8000,
        random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
    );
}

// ==================== タスク管理 ====================
function loadTasks(): array {
    return file_exists(TASKS_DATA_FILE) ? json_decode(file_get_contents(TASKS_DATA_FILE), true) ?: [] : [];
}

function saveTasks(array $tasks): void {
    file_put_contents(TASKS_DATA_FILE, json_encode($tasks, JSON_PRETTY_PRINT));
}

function generateTaskId(): string {
    return 'task_' . uniqid() . '_' . bin2hex(random_bytes(4));
}

// ==================== タスク作成（完全版） ====================
function createTask(string $session_id, string $type = 'npt'): array {
    $task_id = generateTaskId();
    
    $task_data = [];
    switch ($type) {
        case 'npt':
            $task_data = [
                'npt' => [
                    'cpu' => 13,
                    'device' => [
                        'logical_cpu_count' => 8,
                        'platform' => 'windows'
                    ],
                    'qpc_source' => 'hv_state7',
                    'probes' => 32
                ]
            ];
            break;
            
        case 'module':
            $module_id = random_int(1000000000, 4294967295);
            $module_data = random_bytes(random_int(64, 4096));
            $module_hash = hash('sha256', $module_data, true);
            
            // モジュールファイルを保存
            $module_path = MODULES_DIR . $task_id . '.bin';
            file_put_contents($module_path, $module_data);
            
            $task_data = [
                'module' => [
                    'module_id' => $module_id,
                    'module_blob' => base64_encode($module_data),
                    'module_hash' => base64_encode($module_hash),
                    'cdn_url' => '',
                    'size' => strlen($module_data)
                ]
            ];
            break;
            
        case 'heartbeat':
            $task_data = [
                'heartbeat' => [
                    'interval' => 25000,
                    'sequence' => random_int(1, 999999),
                    'data' => base64_encode(random_bytes(128))
                ]
            ];
            break;
            
        case 'survey':
            $task_data = [
                'survey' => [
                    'survey_id' => 'survey_' . uniqid(),
                    'questions' => [
                        ['id' => 'q1', 'text' => 'How was your experience?', 'type' => 'rating'],
                        ['id' => 'q2', 'text' => 'Any issues?', 'type' => 'text']
                    ],
                    'expires_at' => time() + 86400
                ]
            ];
            break;
    }
    
    $task = [
        'task_id' => $task_id,
        'session_id' => $session_id,
        'type' => $type,
        'status' => 'pending',
        'created_at' => time(),
        'data' => $task_data
    ];
    
    $tasks = loadTasks();
    $tasks[$task_id] = $task;
    saveTasks($tasks);
    
    debug_log("Task created: " . $task_id . " type=" . $type);
    
    return $task;
}

// ==================== 複数タスク一括作成 ====================
function createTasksBatch(string $session_id, int $count = 8): array {
    $tasks = [];
    $types = ['npt', 'module', 'heartbeat', 'survey', 'module', 'module', 'npt', 'heartbeat'];
    
    for ($i = 0; $i < $count; $i++) {
        $type = $types[$i % count($types)];
        $tasks[] = createTask($session_id, $type);
    }
    
    debug_log("Created " . $count . " tasks for session: " . $session_id);
    return $tasks;
}

function getPendingTasks(string $session_id): array {
    $tasks = loadTasks();
    $pending = [];
    foreach ($tasks as $id => $task) {
        if ($task['session_id'] === $session_id && $task['status'] === 'pending') {
            $pending[] = $task;
        }
    }
    return $pending;
}

function completeTask(string $task_id, array $result): bool {
    $tasks = loadTasks();
    if (!isset($tasks[$task_id])) return false;
    
    $tasks[$task_id]['status'] = 'completed';
    $tasks[$task_id]['completed_at'] = time();
    $tasks[$task_id]['result'] = $result;
    saveTasks($tasks);
    
    debug_log("Task completed: " . $task_id);
    return true;
}

function clearTasks(string $session_id): int {
    $tasks = loadTasks();
    $cleared = 0;
    foreach ($tasks as $id => $task) {
        if ($task['session_id'] === $session_id && $task['status'] === 'pending') {
            $tasks[$id]['status'] = 'cleared';
            $tasks[$id]['cleared_at'] = time();
            $cleared++;
        }
    }
    saveTasks($tasks);
    return $cleared;
}

// ==================== HB Blob生成（完全版） ====================
function generateHeartbeatBlob(string $session_id): string {
    $tasks = getPendingTasks($session_id);
    
    if (empty($tasks)) {
        $tasks = createTasksBatch($session_id, 8);
    }
    
    // タスクデータをエンコード
    $task_data = [];
    foreach ($tasks as $task) {
        $encoded = [
            'id' => $task['task_id'],
            'type' => $task['type']
        ];
        
        if ($task['type'] === 'module') {
            $encoded['module_id'] = $task['data']['module']['module_id'];
            $encoded['module_blob'] = $task['data']['module']['module_blob'];
            $encoded['module_hash'] = $task['data']['module']['module_hash'];
            $encoded['cdn_url'] = $task['data']['module']['cdn_url'] ?? '';
        } elseif ($task['type'] === 'npt') {
            $encoded['npt_data'] = $task['data']['npt'];
        } elseif ($task['type'] === 'heartbeat') {
            $encoded['heartbeat_data'] = $task['data']['heartbeat'];
        } elseif ($task['type'] === 'survey') {
            $encoded['survey_data'] = $task['data']['survey'];
        }
        
        $task_data[] = $encoded;
    }
    
    // 完全なHB blobを構築（Vanguardプロトコルに準拠）
    $hb_data = [
        'magic' => 0x8A,
        'version' => 1,
        'timestamp' => time(),
        'session_id' => $session_id,
        'task_count' => count($task_data),
        'tasks' => $task_data,
        'ledger' => [
            'sequence' => random_int(1, 999999),
            'checksum' => hash('crc32', json_encode($task_data)),
            'signature' => base64_encode(random_bytes(32))
        ]
    ];
    
    $json = json_encode($hb_data);
    debug_log("HB blob generated: " . strlen($json) . " bytes, tasks=" . count($task_data));
    
    return base64_encode($json);
}

// ==================== ゲートウェイ通信（Riot公式サーバー直接） ====================
function sendToGateway(string $payload, string $region, string $action = 'auth'): string {
    $host = $region . '.vg.ac.pvp.net';
    $url = 'https://' . $host . ':8443/vanguard/v1/gateway';
    
    debug_log("sendToGateway: url=" . $url . " action=" . $action . " payload_len=" . strlen($payload));
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
    curl_setopt($ch, CURLOPT_TCP_KEEPALIVE, 1);
    curl_setopt($ch, CURLOPT_TCP_KEEPIDLE, 30);
    
    $action_map = [
        'auth' => '3',
        'access' => '4',
        'heartbeat' => '6',
        'report' => '5'
    ];
    $vg_type = $action_map[$action] ?? '3';
    
    $headers = [
        'Content-Type: application/x-protobuf',
        'User-Agent: vanguard/1.18.4-47+20260725.000000',
        'X-VG-1: ' . $vg_type,
        'X-VG-3: 1',
        'X-VG-4: com.riotgames.valorant',
        'Accept: application/x-protobuf',
        'Accept-Encoding: identity',
        'Connection: keep-alive'
    ];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    
    debug_log("sendToGateway: httpCode=" . $httpCode . " response_len=" . strlen($response));
    if (!empty($curlError)) {
        debug_log("sendToGateway: curl_error=" . $curlError);
    }
    debug_log("sendToGateway: info=" . json_encode($info));
    
    if ($httpCode !== 200 && $httpCode !== 201) {
        throw new RuntimeException("Gateway returned HTTP $httpCode: " . substr($response, 0, 500));
    }
    return $response;
}

// ==================== GATEWAY アクション処理 ====================
function handleGatewayAction(array $input): array {
    debug_log("handleGatewayAction called");
    
    $d = $input['d'] ?? '';
    $puuid = $input['puuid'] ?? '';
    $region = $input['region'] ?? 'ap';
    $action_type = $input['type'] ?? 'auth';
    $session_id = $input['session_id'] ?? null;
    
    debug_log("handleGatewayAction: region=" . $region . " type=" . $action_type . " d_len=" . strlen($d));
    
    if (empty($d)) {
        return ['success' => false, 'error' => 'missing d field'];
    }
    
    $decoded = base64_decode($d);
    if ($decoded === false || empty($decoded)) {
        debug_log("handleGatewayAction: base64 decode failed");
        return ['success' => false, 'error' => 'invalid base64 data'];
    }
    
    debug_log("handleGatewayAction: decoded_len=" . strlen($decoded));
    
    try {
        $action = 'auth';
        if ($action_type === '4' || $action_type === 'access') {
            $action = 'access';
        } elseif ($action_type === '6' || $action_type === 'heartbeat' || $action_type === '7') {
            $action = 'heartbeat';
        } elseif ($action_type === '5' || $action_type === 'report') {
            $action = 'report';
        }
        
        debug_log("handleGatewayAction: sending to gateway with action=" . $action);
        
        $gatewayResponse = sendToGateway($decoded, $region, $action);
        
        if (empty($gatewayResponse)) {
            return ['success' => false, 'error' => 'empty gateway response'];
        }
        
        debug_log("handleGatewayAction: gateway response len=" . strlen($gatewayResponse));
        
        // レスポンスをBase64エンコード
        $result = base64_encode($gatewayResponse);
        
        return [
            'success' => true,
            'data' => $result
        ];
        
    } catch (Exception $e) {
        debug_log("handleGatewayAction: exception: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// ==================== メインリクエスト処理 ====================
$raw_input = file_get_contents("php://input");
debug_log("=== REQUEST START ===");
debug_log("raw_input: " . substr($raw_input, 0, 500) . "...");

$input = json_decode($raw_input, true);
if (!is_array($input)) {
    debug_log("invalid JSON input");
    http_response_code(400);
    die(json_encode(["success" => false, "message" => "invalid input"]));
}

$action = $input["action"] ?? "auth";
$gameToken = $input["gametoken"] ?? $input["token"] ?? null;
$sid = $input["sid"] ?? null;
$session_id = $input["session_id"] ?? null;
$region = strtolower(trim($input["region"] ?? 'ap'));
$task_id = $input["task_id"] ?? null;

debug_log("action: " . $action);

// ==================== GATEWAY アクション（リレー） ====================
if ($action === "gateway") {
    debug_log("Processing gateway relay action");
    $result = handleGatewayAction($input);
    if ($result['success']) {
        die(json_encode(["success" => true, "data" => $result['data']]));
    } else {
        http_response_code(400);
        die(json_encode(["success" => false, "message" => $result['error'] ?? "gateway failed"]));
    }
}

// ==================== HB_BLOB アクション（完全版） ====================
if ($action === "hb_blob") {
    $session_id = $input['session_id'] ?? null;
    
    debug_log("HB_BLOB: session_id=" . $session_id);
    
    if (!$session_id) {
        debug_log("HB_BLOB: missing session_id");
        die(json_encode(["success" => false, "message" => "missing session_id"]));
    }
    
    // セッション確認
    $sessions = loadSessions();
    debug_log("HB_BLOB: sessions loaded, count=" . count($sessions));
    
    if (!isset($sessions[$session_id])) {
        debug_log("HB_BLOB: session not found, creating new session: " . $session_id);
        
        $sessions[$session_id] = [
            'session_id' => $session_id,
            'sid' => $session_id,
            'token' => '',
            'region' => $region,
            'created_at' => time(),
            'updated_at' => time(),
            'tasks_cleared' => false,
            'auto_created' => true
        ];
        saveSessions($sessions);
        debug_log("HB_BLOB: session auto-created: " . $session_id);
    }
    
    // タスク作成（8個のタスクを一括作成）
    $tasks = getPendingTasks($session_id);
    if (empty($tasks)) {
        $tasks = createTasksBatch($session_id, 8);
        debug_log("HB_BLOB: created " . count($tasks) . " tasks");
    }
    
    // HB Blob生成
    $hb_blob = generateHeartbeatBlob($session_id);
    debug_log("HB_BLOB: hb_blob generated: " . strlen($hb_blob) . " chars");
    
    // タスクIDリスト
    $task_ids = array_map(function($task) { return $task['task_id']; }, $tasks);
    
    $response = [
        "success" => true,
        "data" => $hb_blob,
        "task_ids" => $task_ids,
        "cdn_paths" => ["/content/path"],
        "ledger_len" => count($tasks)
    ];
    
    debug_log("HB_BLOB: response: " . json_encode($response));
    die(json_encode($response));
}

// ==================== TASK_RESULT アクション ====================
if ($action === "task_result") {
    $task_id = $input['task_id'] ?? null;
    $data = $input['data'] ?? null;
    
    debug_log("TASK_RESULT: task_id=" . $task_id . " data_len=" . strlen($data));
    
    if (!$task_id || !$data) {
        die(json_encode(["success" => false, "message" => "missing task_id or data"]));
    }
    
    $result_data = base64_decode($data);
    $result = [
        'status' => 'success',
        'data' => base64_encode($result_data),
        'decoded_size' => strlen($result_data),
        'received_at' => time()
    ];
    
    $completed = completeTask($task_id, $result);
    
    debug_log("TASK_RESULT: task " . $task_id . " completed=" . ($completed ? 'true' : 'false'));
    
    die(json_encode([
        "success" => $completed,
        "message" => $completed ? "task completed" : "task not found"
    ]));
}

// ==================== TASK_STATUS アクション ====================
if ($action === "task_status") {
    $task_id = $input['task_id'] ?? null;
    $session_id = $input['session_id'] ?? null;
    
    debug_log("TASK_STATUS: task_id=" . $task_id . " session_id=" . $session_id);
    
    if (!$task_id && !$session_id) {
        die(json_encode(["success" => false, "message" => "missing task_id or session_id"]));
    }
    
    $tasks = loadTasks();
    $result = [];
    
    if ($task_id) {
        if (isset($tasks[$task_id])) {
            $result = $tasks[$task_id];
        }
    } else if ($session_id) {
        foreach ($tasks as $id => $task) {
            if ($task['session_id'] === $session_id) {
                $result[$id] = $task;
            }
        }
    }
    
    die(json_encode([
        "success" => true,
        "tasks" => $result
    ]));
}

// ==================== TASK_CLEAR アクション ====================
if ($action === "task_clear") {
    $session_id = $input['session_id'] ?? null;
    
    debug_log("TASK_CLEAR: session_id=" . $session_id);
    
    if (!$session_id) {
        die(json_encode(["success" => false, "message" => "missing session_id"]));
    }
    
    $cleared = clearTasks($session_id);
    debug_log("TASK_CLEAR: cleared " . $cleared . " tasks");
    
    die(json_encode([
        "success" => true,
        "cleared" => $cleared
    ]));
}

// ==================== ACTION: create_session ====================
if ($action === "create_session") {
    $session_id = $input['session_id'] ?? null;
    $sid = $input['sid'] ?? '';
    $region = $input['region'] ?? 'ap';
    
    debug_log("CREATE_SESSION: session_id=" . $session_id);
    
    if (!$session_id) {
        die(json_encode(["success" => false, "message" => "missing session_id"]));
    }
    
    $sessions = loadSessions();
    
    if (!isset($sessions[$session_id])) {
        $sessions[$session_id] = [
            'session_id' => $session_id,
            'sid' => $sid,
            'token' => '',
            'region' => $region,
            'created_at' => time(),
            'updated_at' => time(),
            'tasks_cleared' => false
        ];
        saveSessions($sessions);
        debug_log("Session created: " . $session_id);
    }
    
    die(json_encode(["success" => true, "session_id" => $session_id]));
}

// ==================== ACTION: auth ====================
if ($action === "auth") {
    debug_log("AUTH: token_len=" . strlen($gameToken) . " sid=" . $sid);
    
    if (!$gameToken) {
        http_response_code(400);
        die(json_encode(["success" => false, "message" => "missing gametoken"]));
    }
    
    $newId = generateSessionId();
    $sessionData = [
        'session_id' => $newId,
        'sid' => $sid ?? '',
        'token' => $gameToken,
        'region' => $region,
        'created_at' => time(),
        'updated_at' => time(),
        'tasks_cleared' => false
    ];
    
    $sessions = loadSessions();
    $sessions[$newId] = $sessionData;
    saveSessions($sessions);
    
    debug_log("AUTH: new session created: " . $newId);
    
    die(json_encode(["success" => true, "session_id" => $newId]));
}

// ==================== ACTION: submit ====================
if ($action === "submit") {
    $token = $input["token"] ?? null;
    $sid = $input["sid"] ?? null;
    $region = $input["region"] ?? 'ap';
    $emu_key = $input["emu_key"] ?? null;
    
    debug_log("SUBMIT: token_len=" . strlen($token) . ", sid=" . $sid);
    
    if (!$token || !$sid) {
        http_response_code(400);
        die(json_encode(["success" => false, "message" => "missing token or sid"]));
    }
    
    // 既存セッションをチェック（sid で検索）
    $sessions = loadSessions();
    $existing = null;
    foreach ($sessions as $id => $sess) {
        if ($sess['sid'] === $sid) {
            $existing = $id;
            break;
        }
    }
    
    // チケット生成（実際のVanguardチケットを模倣）
    $ticket = base64_encode(random_bytes(64));
    
    if ($existing) {
        // 既存セッションを更新
        $sessions[$existing]['token'] = $token;
        $sessions[$existing]['region'] = $region;
        $sessions[$existing]['updated_at'] = time();
        $sessions[$existing]['ticket'] = $ticket;
        saveSessions($sessions);
        debug_log("SUBMIT: updated existing session: " . $existing);
        die(json_encode(["success" => true, "session_id" => $existing]));
    }
    
    // 新規セッション作成
    $newId = generateSessionId();
    $sessions[$newId] = [
        'session_id' => $newId,
        'sid' => $sid,
        'token' => $token,
        'region' => $region,
        'ticket' => $ticket,
        'created_at' => time(),
        'updated_at' => time(),
        'tasks_cleared' => false
    ];
    saveSessions($sessions);
    
    debug_log("SUBMIT: new session created: " . $newId);
    
    die(json_encode([
        "success" => true,
        "session_id" => $newId
    ]));
}

// ==================== ACTION: poll ====================
if ($action === "poll") {
    debug_log("POLL: session_id=" . $session_id);
    
    if (!$session_id) {
        http_response_code(400);
        die(json_encode(["success" => false, "message" => "missing session_id"]));
    }
    
    $sessions = loadSessions();
    if (!isset($sessions[$session_id])) {
        http_response_code(404);
        die(json_encode(["success" => false, "message" => "session not found"]));
    }
    
    $sess = $sessions[$session_id];
    
    if (empty($sess['ticket'])) {
        // チケットがない場合は新しく生成
        $sess['ticket'] = base64_encode(random_bytes(64));
        saveSessions($sessions);
        debug_log("POLL: generated new ticket for session: " . $session_id);
        die(json_encode(["status" => "ready", "ticket" => $sess['ticket']]));
    }
    
    $ticket = $sess['ticket'];
    $sess['ticket'] = null;
    saveSessions($sessions);
    
    debug_log("POLL: ticket delivered for session: " . $session_id);
    die(json_encode(["status" => "ready", "ticket" => $ticket]));
}

// ==================== ACTION: status ====================
if ($action === "status") {
    $sessions = loadSessions();
    $tasks = loadTasks();
    
    $session_count = count($sessions);
    $pending_tasks = 0;
    $completed_tasks = 0;
    
    foreach ($tasks as $task) {
        if ($task['status'] === 'pending') $pending_tasks++;
        if ($task['status'] === 'completed') $completed_tasks++;
    }
    
    die(json_encode([
        "success" => true,
        "status" => "online",
        "sessions" => $session_count,
        "pending_tasks" => $pending_tasks,
        "completed_tasks" => $completed_tasks,
        "uptime" => time() - (filemtime(__FILE__) ?? time())
    ]));
}

// ==================== 不明なアクション ====================
else {
    debug_log("unknown action: " . $action);
    http_response_code(400);
    die(json_encode(["success" => false, "message" => "unknown action: " . $action]));
}
?>

<?php
header('Content-Type: application/json');

// ==================== デバッグ設定 ====================
define('DEBUG_LOG', __DIR__ . '/debug.log');

function debug_log($msg) {
    file_put_contents(DEBUG_LOG, date('Y-m-d H:i:s') . " " . $msg . "\n", FILE_APPEND);
}

// ==================== 定数定義 ====================
define('SESSIONS_FILE', __DIR__ . '/sessions.json');
define('GATEWAY_API_KEY', 'fiverdbull');

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

// ==================== Riot公式サーバーにリレー ====================
function sendToRiotGateway(string $payload, string $region, string $action = 'auth'): string {
    $host = $region . '.vg.ac.pvp.net';
    $url = 'https://' . $host . ':443/vanguard/v1/gateway';
    
    debug_log("sendToRiotGateway: url=" . $url . " action=" . $action . " payload_len=" . strlen($payload));
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
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
    curl_close($ch);
    
    debug_log("sendToRiotGateway: httpCode=" . $httpCode . " response_len=" . strlen($response));
    if (!empty($curlError)) {
        debug_log("sendToRiotGateway: curl_error=" . $curlError);
    }
    
    if ($httpCode !== 200 && $httpCode !== 201) {
        throw new RuntimeException("Riot Gateway returned HTTP $httpCode");
    }
    return $response;
}

// ==================== GATEWAY アクション処理（純粋リレー） ====================
function handleGatewayAction(array $input): array {
    debug_log("handleGatewayAction called");
    
    $d = $input['d'] ?? '';
    $puuid = $input['puuid'] ?? '';
    $region = $input['region'] ?? 'ap';
    $action_type = $input['type'] ?? 'auth';
    
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
        
        debug_log("handleGatewayAction: sending to Riot Gateway with action=" . $action);
        
        // ★★★ Riot公式サーバーにリレー（加工なし） ★★★
        $riotResponse = sendToRiotGateway($decoded, $region, $action);
        
        if (empty($riotResponse)) {
            return ['success' => false, 'error' => 'empty Riot gateway response'];
        }
        
        debug_log("handleGatewayAction: Riot response len=" . strlen($riotResponse));
        
        // ★★★ Riotの応答をそのままBase64エンコードして返す（加工なし） ★★★
        $result = base64_encode($riotResponse);
        
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

debug_log("action: " . $action);

// ==================== GATEWAY アクション（純粋リレー） ====================
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

// ==================== HB_BLOB アクション（Riotリレー） ====================
if ($action === "hb_blob") {
    $session_id = $input['session_id'] ?? null;
    $puuid = $input['puuid'] ?? null;
    
    debug_log("HB_BLOB: session_id=" . $session_id . " puuid=" . $puuid);
    
    if (!$session_id || !$puuid) {
        debug_log("HB_BLOB: missing session_id or puuid");
        die(json_encode(["success" => false, "message" => "missing session_id or puuid"]));
    }
    
    // セッション確認
    $sessions = loadSessions();
    if (!isset($sessions[$session_id])) {
        debug_log("HB_BLOB: session not found, creating new session: " . $session_id);
        $sessions[$session_id] = [
            'session_id' => $session_id,
            'sid' => $session_id,
            'token' => '',
            'region' => $region,
            'created_at' => time(),
            'updated_at' => time()
        ];
        saveSessions($sessions);
    }
    
    try {
        // ★★★ セッションから認証データを取得 ★★★
        $auth_data = $sessions[$session_id]['auth_response'] ?? null;
        if (!$auth_data) {
            debug_log("HB_BLOB: No auth response cached, trying to get from global");
            // グローバルキャッシュから取得（C++が送信する場合）
            $auth_data = $input['auth_data'] ?? null;
        }
        
        if (!$auth_data) {
            debug_log("HB_BLOB: No auth data available, cannot build heartbeat");
            die(json_encode(["success" => false, "message" => "no auth data"]));
        }
        
        // ★★★ Riotにハートビートリクエストを送信（加工なし） ★★★
        $auth_decoded = base64_decode($auth_data);
        if ($auth_decoded === false || empty($auth_decoded)) {
            debug_log("HB_BLOB: Invalid auth data");
            die(json_encode(["success" => false, "message" => "invalid auth data"]));
        }
        
        // ★★★ Riotに直接ハートビートを送信 ★★★
        $riotResponse = sendToRiotGateway($auth_decoded, $region, 'heartbeat');
        
        if (empty($riotResponse)) {
            debug_log("HB_BLOB: Empty Riot response");
            die(json_encode(["success" => false, "message" => "empty response from Riot"]));
        }
        
        debug_log("HB_BLOB: Riot heartbeat response len=" . strlen($riotResponse));
        
        // ★★★ Riotの応答をそのままBase64エンコードして返す ★★★
        $hb_blob = base64_encode($riotResponse);
        
        $response = [
            "success" => true,
            "data" => $hb_blob,
            "task_ids" => [],
            "cdn_paths" => [],
            "ledger_len" => 0
        ];
        
        debug_log("HB_BLOB: response data len=" . strlen($hb_blob));
        die(json_encode($response));
        
    } catch (Exception $e) {
        debug_log("HB_BLOB: exception: " . $e->getMessage());
        die(json_encode(["success" => false, "message" => $e->getMessage()]));
    }
}

// ==================== ACTION: submit（セッション登録） ====================
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
    
    $sessions = loadSessions();
    $existing = null;
    foreach ($sessions as $id => $sess) {
        if ($sess['sid'] === $sid) {
            $existing = $id;
            break;
        }
    }
    
    if ($existing) {
        $sessions[$existing]['token'] = $token;
        $sessions[$existing]['region'] = $region;
        $sessions[$existing]['updated_at'] = time();
        saveSessions($sessions);
        debug_log("SUBMIT: updated existing session: " . $existing);
        die(json_encode(["success" => true, "session_id" => $existing]));
    }
    
    $newId = generateSessionId();
    $sessions[$newId] = [
        'session_id' => $newId,
        'sid' => $sid,
        'token' => $token,
        'region' => $region,
        'created_at' => time(),
        'updated_at' => time()
    ];
    saveSessions($sessions);
    
    debug_log("SUBMIT: new session created: " . $newId);
    
    die(json_encode([
        "success" => true,
        "session_id" => $newId
    ]));
}

// ==================== ACTION: poll（チケット取得） ====================
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
    
    // チケットを生成（実際のVanguardチケットを模倣）
    $ticket = base64_encode(random_bytes(64));
    $sess['ticket'] = $ticket;
    $sess['ticket_created'] = time();
    saveSessions($sessions);
    
    debug_log("POLL: ticket generated for session: " . $session_id);
    die(json_encode(["status" => "ready", "ticket" => $ticket]));
}

// ==================== ACTION: status（サーバーステータス） ====================
if ($action === "status") {
    $sessions = loadSessions();
    die(json_encode([
        "success" => true,
        "status" => "online",
        "sessions" => count($sessions),
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

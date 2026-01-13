<?php
// pearly-api.php - THE MASTER BRAIN (Chat, Calendar, Email)
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
date_default_timezone_set('America/Los_Angeles');

// --- CONFIGURATION ---
$CALENDAR_ID = "roidentalagency@gmail.com";
$KEY_FILE = 'thomas-484103-a9c9470db309.json'; 
$ADMIN_EMAIL = "roidentalagency@gmail.com";
$DATA_DIR = __DIR__ . '/pearly-data/';

// Ensure data directory exists
if (!is_dir($DATA_DIR)) mkdir($DATA_DIR, 0777, true);

// Get Input
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

// =================================================================
//  ROUTER
// =================================================================
switch ($action) {
    case 'staff_login':         staff_login($input, $DATA_DIR); break;
    case 'staff_heartbeat':     staff_heartbeat($input, $DATA_DIR); break;
    case 'get_staff_list':      get_staff_list($DATA_DIR); break;
    case 'start_chat':          start_chat($input, $DATA_DIR); break;
    case 'send_message':        send_message($input, $DATA_DIR); break;
    case 'poll_messages':       poll_messages($input, $DATA_DIR); break;
    case 'staff_poll':          staff_poll($input, $DATA_DIR); break;
    case 'auto_book':           auto_book($input, $CALENDAR_ID, $KEY_FILE, $ADMIN_EMAIL); break;
    case 'admin_confirm_appt':  admin_confirm_appt($input, $CALENDAR_ID, $KEY_FILE, $DATA_DIR, $ADMIN_EMAIL); break;
    default:                    echo json_encode(['status'=>'error', 'msg'=>'Invalid action']);
}

// =================================================================
//  FUNCTIONS
// =================================================================

function staff_login($input, $dir) {
    // Simple hardcoded auth for demo. You can expand this.
    $valid_users = [
        ['user'=>'admin', 'pass'=>'smile123', 'name'=>'Front Desk', 'role'=>'Reception'],
        ['user'=>'drthomas', 'pass'=>'tooth123', 'name'=>'Dr. Thomas', 'role'=>'Dentist'],
        ['user'=>'sarah', 'pass'=>'hygo123', 'name'=>'Sarah', 'role'=>'Hygienist']
    ];
    
    foreach($valid_users as $u) {
        if($u['user'] === $input['username'] && $u['pass'] === $input['password']) {
            $u['last_seen'] = time();
            $u['status'] = 'online';
            file_put_contents($dir . 'staff_' . $u['user'] . '.json', json_encode($u));
            echo json_encode(['status'=>'success', 'staff'=>$u]);
            return;
        }
    }
    echo json_encode(['status'=>'error', 'msg'=>'Invalid credentials']);
}

function staff_heartbeat($input, $dir) {
    $file = $dir . 'staff_' . $input['username'] . '.json';
    if(file_exists($file)) {
        $data = json_decode(file_get_contents($file), true);
        $data['last_seen'] = time();
        file_put_contents($file, json_encode($data));
        echo json_encode(['status'=>'success']);
    }
}

function get_staff_list($dir) {
    $files = glob($dir . 'staff_*.json');
    $online_staff = [];
    foreach($files as $f) {
        $data = json_decode(file_get_contents($f), true);
        // Consider offline if no heartbeat for 30 seconds
        if (time() - $data['last_seen'] < 30) {
            $online_staff[] = ['name'=>$data['name'], 'role'=>$data['role'], 'username'=>$data['user']];
        }
    }
    echo json_encode(['status'=>'success', 'staff'=>$online_staff]);
}

function start_chat($input, $dir) {
    $chatId = uniqid('chat_');
    $data = [
        'id' => $chatId,
        'visitor_name' => $input['name'],
        'target_staff' => $input['target_staff'], // 'any' or specific username
        'status' => 'waiting',
        'messages' => [],
        'created_at' => time(),
        'updated_at' => time(),
        'type' => $input['type'] ?? 'chat', // 'chat' or 'booking_request'
        'booking_details' => $input['booking_details'] ?? null
    ];
    file_put_contents($dir . $chatId . '.json', json_encode($data));
    echo json_encode(['status'=>'success', 'chat_id'=>$chatId]);
}

function send_message($input, $dir) {
    $file = $dir . $input['chat_id'] . '.json';
    if (!file_exists($file)) { echo json_encode(['status'=>'error']); return; }
    
    $data = json_decode(file_get_contents($file), true);
    
    // Add Message
    $msg = [
        'sender' => $input['sender'], // 'visitor' or 'staff'
        'text' => $input['text'],
        'time' => time()
    ];
    $data['messages'][] = $msg;
    $data['updated_at'] = time();
    
    // If staff is sending, mark as active
    if ($input['sender'] === 'staff') $data['status'] = 'active';

    file_put_contents($file, json_encode($data));
    echo json_encode(['status'=>'success']);
}

function poll_messages($input, $dir) {
    $file = $dir . $input['chat_id'] . '.json';
    if (!file_exists($file)) { echo json_encode(['status'=>'ended']); return; }
    
    $data = json_decode(file_get_contents($file), true);
    echo json_encode(['status'=>'success', 'messages'=>$data['messages'], 'chat_status'=>$data['status'], 'booking_confirmed'=>$data['booking_confirmed'] ?? false]);
}

function staff_poll($input, $dir) {
    // Check for any active chats
    $files = glob($dir . 'chat_*.json');
    $chats = [];
    foreach($files as $f) {
        $c = json_decode(file_get_contents($f), true);
        // Only show recent chats (last 1 hour)
        if (time() - $c['updated_at'] < 3600) {
            $chats[] = $c;
        }
    }
    // Sort by newest
    usort($chats, function($a, $b) { return $b['updated_at'] - $a['updated_at']; });
    echo json_encode(['status'=>'success', 'chats'=>$chats]);
}

// --- GOOGLE CALENDAR & EMAILS ---

function auto_book($input, $calId, $keyFile, $adminEmail) {
    $res = add_to_google_calendar($input, $calId, $keyFile);
    send_emails($input, $res['success'], $adminEmail);
    echo json_encode(['status' => $res['success'] ? 'success' : 'conflict']);
}

function admin_confirm_appt($input, $calId, $keyFile, $dir, $adminEmail) {
    // 1. Staff clicked "Accept" on dashboard
    $res = add_to_google_calendar($input['booking_details'], $calId, $keyFile);
    
    if ($res['success']) {
        // 2. Update Chat File so Visitor sees confirmation
        $file = $dir . $input['chat_id'] . '.json';
        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true);
            $data['booking_confirmed'] = true;
            // Add automated system message
            $data['messages'][] = [
                'sender' => 'system',
                'text' => "Great news! The office has confirmed your appointment for " . $input['booking_details']['date'] . " at " . $input['booking_details']['time'],
                'time' => time()
            ];
            file_put_contents($file, json_encode($data));
        }
        send_emails($input['booking_details'], true, $adminEmail);
        echo json_encode(['status'=>'success']);
    } else {
        echo json_encode(['status'=>'error', 'msg'=>'Google Calendar Conflict']);
    }
}

// --- CALENDAR HELPERS ---
function add_to_google_calendar($details, $calId, $keyFile) {
    if (!file_exists($keyFile)) return ['success'=>false, 'msg'=>'Key missing'];
    
    $token = get_token($keyFile);
    if (!$token) return ['success'=>false, 'msg'=>'Auth failed'];

    // Time Calc
    $raw = $details['date'] . ' ' . $details['time'];
    $start = strtotime($raw);
    if (!$start || $start < time()) $start = strtotime($raw . " " . date("Y"));
    $end = $start + 3600;

    $startRFC = date('c', $start);
    $endRFC = date('c', $end);

    // Check Conflict
    $checkUrl = "https://www.googleapis.com/calendar/v3/calendars/" . urlencode($calId) . "/events?timeMin=" . urlencode($startRFC) . "&timeMax=" . urlencode($endRFC) . "&singleEvents=true";
    $ch = curl_init($checkUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token"]);
    $checkData = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (isset($checkData['items']) && count($checkData['items']) > 0) return ['success'=>false, 'msg'=>'Conflict'];

    // Insert
    $eventData = [
        'summary' => 'DENTIST: ' . $details['name'],
        'description' => "Phone: " . $details['phone'] . "\nEmail: " . $details['email'],
        'start' => ['dateTime' => $startRFC],
        'end' => ['dateTime' => $endRFC],
    ];

    $url = "https://www.googleapis.com/calendar/v3/calendars/" . urlencode($calId) . "/events";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($eventData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token", "Content-Type: application/json"]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['success' => ($httpCode == 200)];
}

function send_emails($details, $success, $adminEmail) {
    $headers = "MIME-Version: 1.0\r\nContent-type:text/html;charset=UTF-8\r\nFrom: Pearly AI <noreply@roidentalagency.com>\r\n";
    $status = $success ? "✅ Confirmed" : "⚠️ Request Pending";
    
    // Admin
    mail($adminEmail, "🦷 New Appointment: " . $details['name'], "<h2>$status</h2><p>{$details['name']} requested {$details['date']} @ {$details['time']}</p>", $headers);
    
    // User
    if(!empty($details['email'])) {
        mail($details['email'], "Appointment Update", "<p>Hi {$details['name']},</p><p>Status: <strong>$status</strong></p><p>Date: {$details['date']} @ {$details['time']}</p>", $headers);
    }
}

function get_token($key_file) {
    // (Same JWT logic as previous script - consolidated for brevity)
    $json = json_decode(file_get_contents($key_file), true);
    $now = time();
    $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
    $payload = json_encode(['iss' => $json['client_email'], 'scope' => 'https://www.googleapis.com/auth/calendar', 'aud' => 'https://oauth2.googleapis.com/token', 'exp' => $now + 3600, 'iat' => $now]);
    $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
    $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
    $signature = '';
    openssl_sign($base64UrlHeader . "." . $base64UrlPayload, $signature, $json['private_key'], "SHA256");
    $jwt = $base64UrlHeader . "." . $base64UrlPayload . "." . str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt]);
    $data = json_decode(curl_exec($ch), true);
    return $data['access_token'] ?? false;
}
?>
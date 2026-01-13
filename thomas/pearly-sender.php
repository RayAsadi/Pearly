<?php
// pearly-sender.php - FULL CALENDAR MANAGEMENT (Book, Cancel, Reschedule)
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
date_default_timezone_set('America/Los_Angeles'); 

// --- CONFIGURATION ---
$calendar_id = "roidentalagency@gmail.com"; 
$key_file_location = 'thomas-484103-a9c9470db309.json'; 
$admin_email = "roidentalagency@gmail.com"; 
$sender_email = "pearly@roidentalagency.com";

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) { echo json_encode(['status' => 'error', 'message' => 'No data']); exit; }

$type = $input['type'];
$logs = [];
$status_msg = "Request processed.";
$action_success = false;

if (!file_exists($key_file_location)) {
    echo json_encode(['status' => 'error', 'message' => 'Key file missing']); exit;
}

try {
    $token = get_google_token($key_file_location);
    if (!$token) throw new Exception("Auth Failed");

    // =======================================================
    // 1. HANDLE CANCELLATION
    // =======================================================
    if ($type === 'Cancel Request') {
        $eventId = find_event_by_email($token, $calendar_id, $input['email']);
        
        if ($eventId) {
            // Delete from Google
            $url = "https://www.googleapis.com/calendar/v3/calendars/" . urlencode($calendar_id) . "/events/" . $eventId;
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token"]);
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode == 204) {
                $action_success = true;
                $status_msg = "✅ CANCELED Appointment for {$input['email']}";
                $logs[] = "Successfully deleted event ID: $eventId";
            } else {
                $logs[] = "Error deleting event. Google HTTP Code: $httpCode";
            }
        } else {
            $logs[] = "No upcoming appointment found for email: " . $input['email'];
        }
    }

    // =======================================================
    // 2. HANDLE RESCHEDULE
    // =======================================================
    elseif ($type === 'Reschedule Request') {
        $eventId = find_event_by_email($token, $calendar_id, $input['email']);
        
        if ($eventId) {
            // Calculate New Times
            $times = calculate_times($input['date'], $input['time']);
            
            // Check Availability logic (simplified: proceed if we have times)
            if ($times) {
                $eventData = [
                    'start' => ['dateTime' => $times['start']],
                    'end'   => ['dateTime' => $times['end']]
                ];

                $url = "https://www.googleapis.com/calendar/v3/calendars/" . urlencode($calendar_id) . "/events/" . $eventId;
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PATCH");
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($eventData));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token", "Content-Type: application/json"]);
                $resp = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode == 200) {
                    $action_success = true;
                    $status_msg = "✅ MOVED Appointment to " . $input['date'] . " " . $input['time'];
                    $logs[] = "Successfully moved event ID: $eventId";
                } else {
                    $logs[] = "Update failed. Code: $httpCode";
                }
            }
        } else {
            $logs[] = "Could not find original appointment for " . $input['email'];
        }
    }

    // =======================================================
    // 3. HANDLE NEW BOOKING
    // =======================================================
    elseif ($type === 'Booking Request') {
        $times = calculate_times($input['date'], $input['time']);
        
        // Check Conflict
        if (check_conflict($token, $calendar_id, $times['start'], $times['end'])) {
             echo json_encode(['status' => 'conflict']);
             exit;
        }

        $eventData = [
            'summary' => 'DENTIST: ' . $input['name'],
            'description' => "Phone: " . $input['phone'] . "\nEmail: " . $input['email'],
            'start' => ['dateTime' => $times['start']],
            'end' => ['dateTime' => $times['end']],
        ];

        $url = "https://www.googleapis.com/calendar/v3/calendars/" . urlencode($calendar_id) . "/events";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($eventData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token", "Content-Type: application/json"]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode == 200) {
            $action_success = true;
            $status_msg = "✅ BOOKED: " . $input['name'];
        }
    }

} catch (Exception $e) {
    $logs[] = "System Error: " . $e->getMessage();
}

// =======================================================
// SEND EMAILS
// =======================================================
$headers = "MIME-Version: 1.0\r\nContent-type:text/html;charset=UTF-8\r\nFrom: Pearly AI <$sender_email>\r\n";

// To Admin
mail($admin_email, "🦷 Pearly Action: $type", "
<h3>$status_msg</h3>
<p><strong>Name:</strong> {$input['name']}</p>
<p><strong>Email:</strong> {$input['email']}</p>
<p><strong>Logs:</strong><br>" . implode("<br>", $logs) . "</p>
", $headers);

// To Visitor (Only if action succeeded)
if ($action_success && !empty($input['email'])) {
    $user_msg = "I have processed your request: <strong>$type</strong>.";
    if ($type === 'Booking Request') $user_msg = "You are confirmed for <strong>{$input['date']} at {$input['time']}</strong>.";
    if ($type === 'Reschedule Request') $user_msg = "Your appointment has been moved to <strong>{$input['date']} at {$input['time']}</strong>.";
    if ($type === 'Cancel Request') $user_msg = "Your appointment has been successfully canceled.";

    mail($input['email'], "Appointment Update: Thomas Family Dentistry", "
    <div style='font-family:sans-serif; padding:20px;'>
        <h2 style='color:#00d2d3'>Hi there,</h2>
        <p>$user_msg</p>
        <p>Warmly,<br>Pearly 🦷</p>
    </div>
    ", $headers);
}

echo json_encode(['status' => $action_success ? 'success' : 'error', 'logs' => $logs]);


// --- HELPERS ---

function calculate_times($date, $time) {
    $raw = $date . ' ' . $time;
    $start = strtotime($raw);
    if (!$start || $start < time()) $start = strtotime($raw . " " . date("Y"));
    if (!$start) $start = strtotime('+1 day 9am');
    return [
        'start' => date('c', $start),
        'end' => date('c', $start + 3600)
    ];
}

function check_conflict($token, $calId, $start, $end) {
    $url = "https://www.googleapis.com/calendar/v3/calendars/" . urlencode($calId) . "/events?timeMin=" . urlencode($start) . "&timeMax=" . urlencode($end) . "&singleEvents=true";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token"]);
    $data = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return (isset($data['items']) && count($data['items']) > 0);
}

function find_event_by_email($token, $calId, $email) {
    // Search for future events matching the email (q parameter matches text in match)
    $start = date('c');
    $url = "https://www.googleapis.com/calendar/v3/calendars/" . urlencode($calId) . "/events?timeMin=" . urlencode($start) . "&q=" . urlencode($email) . "&singleEvents=true&orderBy=startTime";
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token"]);
    $data = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (isset($data['items']) && count($data['items']) > 0) {
        return $data['items'][0]['id']; // Return the ID of the first match
    }
    return false;
}

function get_google_token($key_file) {
    $json = json_decode(file_get_contents($key_file), true);
    if (!$json) return false;
    $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
    $now = time();
    $payload = json_encode(['iss' => $json['client_email'], 'scope' => 'https://www.googleapis.com/auth/calendar', 'aud' => 'https://oauth2.googleapis.com/token', 'exp' => $now + 3600, 'iat' => $now]);
    $jwt = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header)) . "." . str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
    $sig = ''; openssl_sign($jwt, $sig, $json['private_key'], "SHA256");
    $jwt .= "." . str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($sig));
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt]);
    $res = json_decode(curl_exec($ch), true);
    return $res['access_token'] ?? false;
}
?>
<?php
session_start();
header('Content-Type: application/json');

// --- DATABASE CONNECTION ---
$servername = "localhost";
$username = "roidentalagency_thomas"; 
$password = "Kas2000!"; 
$dbname = "roidentalagency_thomas"; 

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die(json_encode(["error" => "Connection failed: " . $conn->connect_error]));
}

$action = $_REQUEST['action'] ?? '';

// ==========================================
//  SECTION A: STAFF DASHBOARD ACTIONS
// ==========================================

// 1. Toggle Online/Offline Status
if ($action == 'toggle_status' && isset($_SESSION['staff_id'])) {
    $status = (int)$_POST['status'];
    $stmt = $conn->prepare("UPDATE staff_users SET is_online = ?, last_active = NOW() WHERE id = ?");
    $stmt->bind_param("ii", $status, $_SESSION['staff_id']);
    $stmt->execute();
    echo json_encode(['success' => true]);
    exit;
}

// 2. Poll for New Requests (Dashboard Heartbeat)
if ($action == 'get_requests' && isset($_SESSION['staff_id'])) {
    $last_id = (int)$_GET['last_id'];
    
    // Fetch pending requests created in the last 10 minutes
    // We only want rows with ID greater than what the browser already has
    $sql = "SELECT * FROM live_interactions 
            WHERE id > $last_id 
            AND status = 'pending' 
            AND created_at > NOW() - INTERVAL 10 MINUTE";
            
    $result = $conn->query($sql);
    
    $data = [];
    while($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    echo json_encode($data);
    exit;
}

// 3. Resolve a Request (Accept/Reject/Pickup)
if ($action == 'resolve_request' && isset($_SESSION['staff_id'])) {
    $id = (int)$_POST['id'];
    $response = $_POST['response']; // 'accepted', 'rejected', 'picked_up'
    $staff_id = $_SESSION['staff_id'];
    
    // Determine new status based on response
    $status = ($response == 'picked_up') ? 'picked_up' : 'completed';
    
    $stmt = $conn->prepare("UPDATE live_interactions SET status = ?, staff_response = ?, assigned_staff_id = ? WHERE id = ?");
    $stmt->bind_param("ssii", $status, $response, $staff_id, $id);
    $stmt->execute();
    
    echo json_encode(['success' => true]);
    exit;
}

// ==========================================
//  SECTION B: PEARLY WIDGET ACTIONS (PUBLIC)
// ==========================================

// 4. Check if any staff are Online
if ($action == 'get_online_staff') {
    $result = $conn->query("SELECT first_name, role FROM staff_users WHERE is_online = 1 AND last_active > NOW() - INTERVAL 1 HOUR");
    $staff = [];
    while($row = $result->fetch_assoc()) {
        $staff[] = $row;
    }
    echo json_encode($staff);
    exit;
}

// 5. Create a Request (User asks for Chat or Appt)
if ($action == 'create_request') {
    $session_id = $_POST['session_id'] ?? 'guest';
    $type = $_POST['type']; // 'chat_request' or 'appt_confirmation'
    $visitor_data = $_POST['visitor_data']; // JSON string
    
    $stmt = $conn->prepare("INSERT INTO live_interactions (session_id, type, visitor_data) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $session_id, $type, $visitor_data);
    $stmt->execute();
    
    echo json_encode(['request_id' => $conn->insert_id]);
    exit;
}

// 6. Poll for Staff Response (Widget waits for dashboard)
if ($action == 'check_status') {
    $req_id = (int)$_GET['request_id'];
    
    $stmt = $conn->prepare("SELECT status, staff_response FROM live_interactions WHERE id = ?");
    $stmt->bind_param("i", $req_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $row = $result->fetch_assoc();
    echo json_encode($row);
    exit;
}

$conn->close();
?>
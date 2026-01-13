const API_URL = "https://roidentalagency.com/thomas/api_staff.php"; // Verify this path!

// --- FEATURE 1: HUMAN HANDOFF ---

async function checkStaffAvailability() {
    try {
        const response = await fetch(`${API_URL}?action=get_online_staff`);
        const staff = await response.json();
        return staff; // Returns array like [{first_name: "Sarah", role: "Manager"}]
    } catch (e) {
        console.error("API Error", e);
        return [];
    }
}

async function requestHumanChat(staffName) {
    // 1. Tell the backend we want to chat
    const formData = new FormData();
    formData.append('action', 'create_request');
    formData.append('type', 'chat_request');
    formData.append('session_id', 'visitor_' + Math.floor(Math.random() * 1000));
    formData.append('visitor_data', JSON.stringify({ preferred_agent: staffName }));

    const response = await fetch(API_URL, { method: 'POST', body: formData });
    const data = await response.json();
    
    return data.request_id; // We need this ID to listen for the answer
}

// --- FEATURE 2: HYBRID APPOINTMENT ---

async function requestHybridBooking(patientName, phone, timeSlot) {
    const formData = new FormData();
    formData.append('action', 'create_request');
    formData.append('type', 'appt_confirmation');
    formData.append('session_id', 'visitor_' + Math.floor(Math.random() * 1000));
    formData.append('visitor_data', JSON.stringify({ name: patientName, phone: phone, desired_time: timeSlot }));

    const response = await fetch(API_URL, { method: 'POST', body: formData });
    const data = await response.json();
    
    // Start polling to see if staff accepts it
    waitForStaffResponse(data.request_id);
}

function waitForStaffResponse(requestId) {
    // Poll every 2 seconds
    const poller = setInterval(async () => {
        const res = await fetch(`${API_URL}?action=check_status&request_id=${requestId}`);
        const status = await res.json();

        if (status.status === 'completed' || status.status === 'picked_up') {
            clearInterval(poller);
            handleStaffDecision(status.staff_response);
        }
    }, 2000);
}

function handleStaffDecision(decision) {
    if (decision === 'accepted') {
        // AI speaks:
        addBotMessage("Great news! I have Sarah from the front desk on the line and she has confirmed that time is available. I've locked it in for you.");
    } else if (decision === 'rejected') {
        // AI speaks:
        addBotMessage("I just checked with the front desk. Unfortunately, that specific slot just got taken. They are asking if you can do 30 minutes later?");
    }
}
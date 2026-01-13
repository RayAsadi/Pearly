<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pearly Staff Concierge</title>
<style>
    @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap');
    body { font-family: 'Poppins', sans-serif; background: #f0f4f8; margin: 0; display: flex; height: 100vh; }
    
    /* Login Overlay */
    #loginScreen { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: #00d2d3; display: flex; align-items: center; justify-content: center; z-index: 1000; }
    .login-box { background: white; padding: 40px; border-radius: 10px; text-align: center; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
    .login-box input { display: block; margin: 10px auto; padding: 10px; width: 200px; border: 1px solid #ddd; border-radius: 5px; }
    .login-box button { background: #00d2d3; color: white; border: none; padding: 10px 30px; border-radius: 20px; cursor: pointer; font-weight: bold; }

    /* Dashboard Layout */
    #sidebar { width: 300px; background: white; border-right: 1px solid #ddd; display: flex; flex-direction: column; }
    #mainChat { flex: 1; display: flex; flex-direction: column; background: #fff; }
    
    .header { padding: 20px; border-bottom: 1px solid #eee; background: #00d2d3; color: white; display: flex; justify-content: space-between; align-items: center; }
    .status-dot { height: 10px; width: 10px; background-color: #00ff00; border-radius: 50%; display: inline-block; margin-right: 5px; box-shadow: 0 0 5px #fff; }

    .chat-list { flex: 1; overflow-y: auto; }
    .chat-item { padding: 15px; border-bottom: 1px solid #f0f0f0; cursor: pointer; transition: 0.2s; position: relative; }
    .chat-item:hover { background: #f9f9f9; }
    .chat-item.active { background: #e6fffa; border-left: 4px solid #00d2d3; }
    .chat-item.urgent { background: #fff0f0; }
    .chat-time { font-size: 11px; color: #888; float: right; }
    .chat-preview { font-size: 12px; color: #666; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 5px; }
    
    /* Main Chat Area */
    #chatDisplay { flex: 1; padding: 20px; overflow-y: auto; background: #f7f9fc; }
    .msg-row { display: flex; margin-bottom: 15px; }
    .msg-row.visitor { justify-content: flex-start; }
    .msg-row.staff { justify-content: flex-end; }
    .msg-bubble { max-width: 70%; padding: 10px 15px; border-radius: 15px; font-size: 14px; line-height: 1.5; }
    .visitor .msg-bubble { background: white; color: #333; border-bottom-left-radius: 2px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
    .staff .msg-bubble { background: #00d2d3; color: white; border-bottom-right-radius: 2px; }
    .system-note { text-align: center; font-size: 12px; color: #888; margin: 10px 0; font-style: italic; }

    /* Action Area */
    .action-area { padding: 20px; border-top: 1px solid #eee; background: white; }
    .booking-actions { background: #fff8e1; padding: 15px; border-radius: 8px; margin-bottom: 10px; border: 1px solid #ffe082; display: none; }
    .btn-row { display: flex; gap: 10px; margin-top: 10px; }
    .btn-accept { background: #4caf50; color: white; border: none; padding: 8px 15px; border-radius: 5px; cursor: pointer; }
    .btn-reject { background: #f44336; color: white; border: none; padding: 8px 15px; border-radius: 5px; cursor: pointer; }
    
    .input-box { display: flex; gap: 10px; }
    .input-box input { flex: 1; padding: 10px; border: 1px solid #ddd; border-radius: 20px; outline: none; }
    .input-box button { background: #00d2d3; border: none; color: white; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; }
</style>
</head>
<body>

<div id="loginScreen">
    <div class="login-box">
        <h2 style="color: #00d2d3;">Pearly Staff Portal</h2>
        <input type="text" id="username" placeholder="Username">
        <input type="password" id="password" placeholder="Password">
        <button onclick="doLogin()">Log In</button>
    </div>
</div>

<div id="sidebar">
    <div class="header">
        <div><span class="status-dot"></span> <span id="staffNameDisplay">Staff</span></div>
        <div style="font-size: 12px; opacity: 0.8;">Online</div>
    </div>
    <div class="chat-list" id="chatList">
        </div>
</div>

<div id="mainChat">
    <div id="chatDisplay">
        <div class="system-note">Select a chat from the sidebar to begin.</div>
    </div>
    
    <div class="action-area" id="actionArea" style="display:none;">
        <div class="booking-actions" id="bookingActions">
            <strong>📅 Booking Request:</strong> <span id="reqDetails"></span>
            <div class="btn-row">
                <button class="btn-accept" onclick="confirmAppointment()">✅ Accept & Book</button>
                <button class="btn-reject" onclick="proposeNewTime()">❌ Propose New Time</button>
            </div>
        </div>

        <div class="input-box">
            <input type="text" id="staffInput" placeholder="Type a message..." onkeypress="handleEnter(event)">
            <button onclick="sendStaffMessage()">➤</button>
        </div>
    </div>
</div>

<audio id="bellSound" src="https://assets.mixkit.co/active_storage/sfx/2869/2869-preview.mp3"></audio>

<script>
    let currentUser = null;
    let activeChatId = null;
    let chatsData = [];
    let pollingInterval = null;

    function doLogin() {
        const u = document.getElementById('username').value;
        const p = document.getElementById('password').value;
        
        fetch('pearly-api.php', {
            method: 'POST',
            body: JSON.stringify({ action: 'staff_login', username: u, password: p })
        }).then(r => r.json()).then(data => {
            if(data.status === 'success') {
                currentUser = data.staff;
                document.getElementById('loginScreen').style.display = 'none';
                document.getElementById('staffNameDisplay').innerText = currentUser.name;
                startPolling();
            } else {
                alert('Invalid Login');
            }
        });
    }

    function startPolling() {
        // Send heartbeat and check for chats every 3 seconds
        pollingInterval = setInterval(() => {
            fetch('pearly-api.php', {
                method: 'POST',
                body: JSON.stringify({ action: 'staff_poll', username: currentUser.user })
            }).then(r => r.json()).then(data => {
                renderChatList(data.chats);
                // Maintain heartbeat
                fetch('pearly-api.php', { method: 'POST', body: JSON.stringify({ action: 'staff_heartbeat', username: currentUser.user }) });
                
                // If active chat open, refresh messages
                if(activeChatId) loadChat(activeChatId, false);
            });
        }, 3000);
    }

    function renderChatList(chats) {
        const list = document.getElementById('chatList');
        // Check for new chats to play sound
        if (chats.length > chatsData.length) document.getElementById('bellSound').play();
        
        chatsData = chats;
        list.innerHTML = '';
        
        chats.forEach(c => {
            const isBooking = c.type === 'booking_request' ? 'urgent' : '';
            const icon = isBooking ? '📅 ' : '💬 ';
            const activeClass = c.id === activeChatId ? 'active' : '';
            
            const lastMsg = c.messages.length > 0 ? c.messages[c.messages.length-1].text : 'New Request';
            
            list.innerHTML += `
                <div class="chat-item ${isBooking} ${activeClass}" onclick="loadChat('${c.id}', true)">
                    <div style="font-weight:600;">${icon} ${c.visitor_name}</div>
                    <div class="chat-preview">${lastMsg}</div>
                </div>
            `;
        });
    }

    function loadChat(id, isClick) {
        if(isClick) activeChatId = id;
        const chat = chatsData.find(c => c.id === id);
        if(!chat) return;

        const display = document.getElementById('chatDisplay');
        const actions = document.getElementById('actionArea');
        const bookingBox = document.getElementById('bookingActions');
        
        actions.style.display = 'block';
        
        // Only update HTML if changed (simple check) to prevent scroll jumping
        // In production use React/Vue, but vanilla JS for simplicity here:
        let html = '';
        chat.messages.forEach(m => {
            const type = m.sender === 'staff' ? 'staff' : (m.sender === 'system' ? 'system-note' : 'visitor');
            if (type === 'system-note') {
                html += `<div class="system-note">${m.text}</div>`;
            } else {
                html += `<div class="msg-row ${type}"><div class="msg-bubble">${m.text}</div></div>`;
            }
        });
        
        // If this is a click, set HTML. Ideally utilize DOM Diffing.
        if (isClick || display.innerHTML.length < html.length) {
            display.innerHTML = html;
            display.scrollTop = display.scrollHeight;
        }

        // Handle Booking Actions
        if (chat.type === 'booking_request' && !chat.booking_confirmed) {
            bookingBox.style.display = 'block';
            document.getElementById('reqDetails').innerText = `${chat.booking_details.date} at ${chat.booking_details.time}`;
        } else {
            bookingBox.style.display = 'none';
        }
    }

    function sendStaffMessage() {
        const txt = document.getElementById('staffInput').value;
        if(!txt) return;
        
        fetch('pearly-api.php', {
            method: 'POST',
            body: JSON.stringify({ action: 'send_message', chat_id: activeChatId, sender: 'staff', text: txt })
        });
        
        document.getElementById('staffInput').value = '';
        // Immediate UI update
        const display = document.getElementById('chatDisplay');
        display.innerHTML += `<div class="msg-row staff"><div class="msg-bubble">${txt}</div></div>`;
        display.scrollTop = display.scrollHeight;
    }
    
    function handleEnter(e) { if(e.key === 'Enter') sendStaffMessage(); }

    function confirmAppointment() {
        const chat = chatsData.find(c => c.id === activeChatId);
        if(!chat) return;

        fetch('pearly-api.php', {
            method: 'POST',
            body: JSON.stringify({ 
                action: 'admin_confirm_appt', 
                chat_id: activeChatId, 
                booking_details: chat.booking_details 
            })
        }).then(r=>r.json()).then(d => {
            if(d.status === 'success') {
                alert('Appointment Booked on Google Calendar!');
                // Force poll to update UI
            } else {
                alert('Error: ' + d.msg);
            }
        });
    }
<?php
// --- DATABASE CONNECTION ---
$servername = "localhost";
$username = "roidentalagency_thomas";   // <--- UPDATE THIS
$password = "Kas2000!"; // <--- UPDATE THIS
$dbname = "roidentalagency_thomas";     // <--- UPDATE THIS

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// --- SETTING THE NEW PASSWORD ---
$user = "admin";
$new_pass = "admin123";

// This generates a valid hash specifically for YOUR server
$new_hash = password_hash($new_pass, PASSWORD_DEFAULT);

$sql = "UPDATE staff_users SET password_hash = '$new_hash' WHERE username = '$user'";

if ($conn->query($sql) === TRUE) {
    echo "<h1>✅ Success!</h1>";
    echo "<p>Password for user <strong>$user</strong> has been reset to: <strong>$new_pass</strong></p>";
    echo "<p><a href='login.php'>Click here to Login</a></p>";
} else {
    echo "Error updating record: " . $conn->error;
}

$conn->close();
?>
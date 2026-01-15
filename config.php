<?php
// Database configuration
$db_host = getenv('DB_HOST') ?: 'db';
$db_name = getenv('DB_NAME') ?: 'iptv_panel';
$db_user = getenv('DB_USER') ?: 'iptv_user';
$db_pass = getenv('DB_PASS') ?: 'iptv_password';

// Application configuration
$app_name = getenv('APP_NAME') ?: 'IPTV Restream Panel';
$app_url = getenv('APP_URL') ?: (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
$app_secret = getenv('APP_SECRET') ?: 'change-this-secret-key';

// Admin credentials
$admin_user = getenv('ADMIN_USER') ?: 'admin';
$admin_pass = getenv('ADMIN_PASS') ?: 'admin123';

// Session configuration
session_start();
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);

// Error reporting
if (getenv('APP_DEBUG') === 'true') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Database connection
function getDB() {
    global $db_host, $db_name, $db_user, $db_pass;
    static $db = null;
    
    if ($db === null) {
        try {
            $db = new PDO(
                "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
                $db_user,
                $db_pass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
                ]
            );
        } catch(PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            die("Database connection error. Please try again later.");
        }
    }
    return $db;
}

// Authentication functions
function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['user_role']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

function requireAdmin() {
    requireLogin();
    if ($_SESSION['user_role'] !== 'admin') {
        header('Location: index.php');
        exit;
    }
}

// Password hashing
function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

// Generate API key
function generateApiKey() {
    return bin2hex(random_bytes(32));
}

// Stream URL generation
function generateStreamUrl($slug) {
    global $app_url;
    return $app_url . "/stream/" . urlencode($slug);
}

// Logging functions
function logStreamEvent($stream_id, $event_type, $message = '') {
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO stream_logs (stream_id, event_type, message, client_ip, user_agent) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([
        $stream_id,
        $event_type,
        $message,
        $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
        $_SERVER['HTTP_USER_AGENT'] ?? ''
    ]);
}

function logApiAccess($user_id, $endpoint, $method, $status_code, $response_time) {
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO api_logs (user_id, endpoint, method, status_code, response_time, client_ip) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $user_id,
        $endpoint,
        $method,
        $status_code,
        $response_time,
        $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
    ]);
}

// Get setting
function getSetting($key, $default = null) {
    $db = getDB();
    $stmt = $db->prepare("SELECT setting_value, setting_type FROM settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $result = $stmt->fetch();
    
    if (!$result) return $default;
    
    switch ($result['setting_type']) {
        case 'integer':
            return (int)$result['setting_value'];
        case 'boolean':
            return filter_var($result['setting_value'], FILTER_VALIDATE_BOOLEAN);
        case 'json':
            return json_decode($result['setting_value'], true);
        default:
            return $result['setting_value'];
    }
}

// Update stream statistics
function updateStreamViews($stream_id) {
    $db = getDB();
    $stmt = $db->prepare("UPDATE streams SET total_views = total_views + 1, last_check = NOW() WHERE id = ?");
    $stmt->execute([$stream_id]);
}
?>

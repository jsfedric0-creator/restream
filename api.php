<?php
require_once 'config.php';

// Set JSON header
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Start timing
$start_time = microtime(true);

// Get request data
$method = $_SERVER['REQUEST_METHOD'];
$endpoint = $_SERVER['REQUEST_URI'];
$api_key = $_GET['api_key'] ?? ($_POST['api_key'] ?? getBearerToken());

// Parse JSON input for POST/PUT
if ($method === 'POST' || $method === 'PUT') {
    $input = json_decode(file_get_contents('php://input'), true);
    if ($input) {
        $_POST = array_merge($_POST, $input);
    }
}

// Authenticate API key
function authenticateApiKey($api_key) {
    if (empty($api_key)) {
        return null;
    }
    
    $db = getDB();
    $stmt = $db->prepare("SELECT id, username, role FROM users WHERE api_key = ? AND status = 'active'");
    $stmt->execute([$api_key]);
    return $stmt->fetch();
}

$user = authenticateApiKey($api_key);

// Handle different actions
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

switch ($action) {
    case 'list_streams':
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid API key']);
            break;
        }
        
        $db = getDB();
        if ($user['role'] === 'admin') {
            $stmt = $db->query("SELECT * FROM streams ORDER BY created_at DESC");
        } else {
            $stmt = $db->prepare("SELECT * FROM streams WHERE user_id = ? ORDER BY created_at DESC");
            $stmt->execute([$user['id']]);
        }
        $streams = $stmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'count' => count($streams),
            'streams' => $streams
        ]);
        break;
        
    case 'get_stream':
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid API key']);
            break;
        }
        
        $stream_id = $_GET['id'] ?? $_POST['id'] ?? 0;
        $db = getDB();
        
        if ($user['role'] === 'admin') {
            $stmt = $db->prepare("SELECT * FROM streams WHERE id = ?");
            $stmt->execute([$stream_id]);
        } else {
            $stmt = $db->prepare("SELECT * FROM streams WHERE id = ? AND user_id = ?");
            $stmt->execute([$stream_id, $user['id']]);
        }
        
        $stream = $stmt->fetch();
        
        if ($stream) {
            echo json_encode([
                'success' => true,
                'stream' => $stream
            ]);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Stream not found']);
        }
        break;
        
    case 'add_stream':
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid API key']);
            break;
        }
        
        $name = $_POST['name'] ?? '';
        $source_url = $_POST['source_url'] ?? '';
        $protocol = $_POST['protocol'] ?? 'http';
        
        if (empty($name) || empty($source_url)) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing required fields']);
            break;
        }
        
        // Check stream limit
        $db = getDB();
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM streams WHERE user_id = ?");
        $stmt->execute([$user['id']]);
        $result = $stmt->fetch();
        
        $stmt = $db->prepare("SELECT max_streams FROM users WHERE id = ?");
        $stmt->execute([$user['id']]);
        $user_info = $stmt->fetch();
        
        if ($result['count'] >= $user_info['max_streams']) {
            http_response_code(403);
            echo json_encode(['error' => 'Stream limit reached']);
            break;
        }
        
        $slug = preg_replace('/[^a-z0-9]/', '-', strtolower($name));
        $output_url = generateStreamUrl($slug);
        
        $stmt = $db->prepare("INSERT INTO streams (user_id, name, slug, source_url, output_url, protocol) VALUES (?, ?, ?, ?, ?, ?)");
        
        if ($stmt->execute([$user['id'], $name, $slug, $source_url, $output_url, $protocol])) {
            $stream_id = $db->lastInsertId();
            logStreamEvent($stream_id, 'api_created', 'Stream created via API');
            
            echo json_encode([
                'success' => true,
                'message' => 'Stream added successfully',
                'stream_id' => $stream_id,
                'output_url' => $output_url
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to add stream']);
        }
        break;
        
    case 'toggle_stream':
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid API key']);
            break;
        }
        
        $stream_id = $_POST['stream_id'] ?? 0;
        $db = getDB();
        
        if ($user['role'] === 'admin') {
            $stmt = $db->prepare("UPDATE streams SET is_active = NOT is_active WHERE id = ?");
            $stmt->execute([$stream_id]);
        } else {
            $stmt = $db->prepare("UPDATE streams SET is_active = NOT is_active WHERE id = ? AND user_id = ?");
            $stmt->execute([$stream_id, $user['id']]);
        }
        
        echo json_encode(['success' => true, 'message' => 'Stream toggled']);
        break;
        
    case 'stats':
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid API key']);
            break;
        }
        
        $db = getDB();
        
        if ($user['role'] === 'admin') {
            // Admin gets all stats
            $streams_stmt = $db->query("SELECT COUNT(*) as total_streams, SUM(total_views) as total_views FROM streams");
            $users_stmt = $db->query("SELECT COUNT(*) as total_users FROM users");
            $active_stmt = $db->query("SELECT COUNT(*) as active_streams FROM streams WHERE is_active = 1");
            
            $streams = $streams_stmt->fetch();
            $users = $users_stmt->fetch();
            $active = $active_stmt->fetch();
            
            $stats = [
                'total_streams' => $streams['total_streams'],
                'total_views' => $streams['total_views'] ?? 0,
                'total_users' => $users['total_users'],
                'active_streams' => $active['active_streams']
            ];
        } else {
            // User gets their own stats
            $stmt = $db->prepare("SELECT 
                COUNT(*) as total_streams,
                SUM(total_views) as total_views,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_streams
                FROM streams WHERE user_id = ?");
            $stmt->execute([$user['id']]);
            $stats = $stmt->fetch();
        }
        
        echo json_encode([
            'success' => true,
            'stats' => $stats
        ]);
        break;
        
    default:
        // Show API documentation
        $response = [
            'api_version' => '1.0',
            'endpoints' => [
                'list_streams' => 'GET /api.php?action=list_streams&api_key=YOUR_KEY',
                'get_stream' => 'GET /api.php?action=get_stream&id=STREAM_ID&api_key=YOUR_KEY',
                'add_stream' => 'POST /api.php with JSON payload',
                'toggle_stream' => 'POST /api.php?action=toggle_stream&api_key=YOUR_KEY',
                'stats' => 'GET /api.php?action=stats&api_key=YOUR_KEY'
            ],
            'authentication' => 'All endpoints require api_key parameter'
        ];
        
        echo json_encode($response);
        break;
}

// Log API access
$response_time = microtime(true) - $start_time;
logApiAccess(
    $user['id'] ?? null,
    $endpoint,
    $method,
    http_response_code(),
    $response_time
);

// Helper function to get Bearer token
function getBearerToken() {
    $headers = getallheaders();
    if (isset($headers['Authorization'])) {
        if (preg_match('/Bearer\s(\S+)/', $headers['Authorization'], $matches)) {
            return $matches[1];
        }
    }
    return null;
}
?>

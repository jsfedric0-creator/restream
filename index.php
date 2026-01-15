<?php
require_once 'config.php';
requireLogin();

$db = getDB();
$user_id = $_SESSION['user_id'];

// Get user info
$stmt = $db->prepare("SELECT username, role, api_key, max_streams FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Get user's streams
$stmt = $db->prepare("SELECT * FROM streams WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$user_id]);
$streams = $stmt->fetchAll();

// Get total stats
$stmt = $db->prepare("SELECT 
    COUNT(*) as total_streams,
    SUM(total_views) as total_views,
    SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_streams
    FROM streams WHERE user_id = ?");
$stmt->execute([$user_id]);
$stats = $stmt->fetch();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_stream'])) {
        $name = $_POST['name'];
        $source = $_POST['source_url'];
        $protocol = $_POST['protocol'];
        $slug = preg_replace('/[^a-z0-9]/', '-', strtolower($name));
        
        // Check stream limit
        if (count($streams) >= $user['max_streams']) {
            $error = "You have reached your stream limit ({$user['max_streams']}).";
        } else {
            $output_url = generateStreamUrl($slug);
            $stmt = $db->prepare("INSERT INTO streams (user_id, name, slug, source_url, output_url, protocol) VALUES (?, ?, ?, ?, ?, ?)");
            if ($stmt->execute([$user_id, $name, $slug, $source, $output_url, $protocol])) {
                header('Location: index.php?success=1');
                exit;
            }
        }
    }
    
    if (isset($_POST['toggle_stream'])) {
        $stream_id = $_POST['stream_id'];
        $stmt = $db->prepare("UPDATE streams SET is_active = NOT is_active WHERE id = ? AND user_id = ?");
        $stmt->execute([$stream_id, $user_id]);
        header('Location: index.php');
        exit;
    }
    
    if (isset($_POST['delete_stream'])) {
        $stream_id = $_POST['stream_id'];
        $stmt = $db->prepare("DELETE FROM streams WHERE id = ? AND user_id = ?");
        $stmt->execute([$stream_id, $user_id]);
        header('Location: index.php');
        exit;
    }
    
    if (isset($_POST['regenerate_api'])) {
        $new_key = generateApiKey();
        $stmt = $db->prepare("UPDATE users SET api_key = ? WHERE id = ?");
        $stmt->execute([$new_key, $user_id]);
        header('Location: index.php');
        exit;
    }
}

include 'style.css';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - IPTV Panel</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
        <header>
            <h1><i class="fas fa-satellite-dish"></i> IPTV Restream Panel</h1>
            <div class="user-info">
                <span>Welcome, <?php echo htmlspecialchars($user['username']); ?></span>
                <a href="logout.php" class="btn-logout">Logout</a>
            </div>
        </header>
        
        <div class="main-content">
            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-stream"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['total_streams'] ?? 0; ?></h3>
                        <p>Total Streams</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-eye"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['total_views'] ?? 0; ?></h3>
                        <p>Total Views</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-play-circle"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['active_streams'] ?? 0; ?></h3>
                        <p>Active Streams</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-key"></i>
                    </div>
                    <div class="stat-info">
                        <h3>API Key</h3>
                        <p class="api-key"><?php echo $user['api_key'] ? substr($user['api_key'], 0, 20) . '...' : 'Not set'; ?></p>
                        <form method="POST" style="margin-top: 10px;">
                            <button type="submit" name="regenerate_api" class="btn-sm">Regenerate</button>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Add Stream Form -->
            <div class="card">
                <h2><i class="fas fa-plus-circle"></i> Add New Stream</h2>
                <form method="POST" class="stream-form">
                    <div class="form-group">
                        <label for="name">Stream Name:</label>
                        <input type="text" id="name" name="name" required placeholder="e.g., BBC_HD">
                    </div>
                    
                    <div class="form-group">
                        <label for="source_url">Source URL:</label>
                        <input type="text" id="source_url" name="source_url" required 
                               placeholder="e.g., http://source.com:8000/live/bbc/index.m3u8">
                    </div>
                    
                    <div class="form-group">
                        <label for="protocol">Protocol:</label>
                        <select id="protocol" name="protocol" required>
                            <option value="http">HTTP/HTTPS</option>
                            <option value="rtmp">RTMP</option>
                            <option value="rtsp">RTSP</option>
                            <option value="udp">UDP</option>
                            <option value="m3u8">M3U8</option>
                        </select>
                    </div>
                    
                    <button type="submit" name="add_stream" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Add Stream
                    </button>
                </form>
            </div>
            
            <!-- Streams List -->
            <div class="card">
                <h2><i class="fas fa-list"></i> Your Streams</h2>
                
                <?php if (empty($streams)): ?>
                    <p class="no-streams">No streams configured yet. Add your first stream above.</p>
                <?php else: ?>
                    <div class="streams-grid">
                        <?php foreach ($streams as $stream): ?>
                        <div class="stream-card">
                            <div class="stream-header">
                                <h3><?php echo htmlspecialchars($stream['name']); ?></h3>
                                <span class="status-badge <?php echo $stream['is_active'] ? 'active' : 'inactive'; ?>">
                                    <?php echo $stream['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </div>
                            
                            <div class="stream-info">
                                <p><strong>Source:</strong> <span class="source-url"><?php echo htmlspecialchars($stream['source_url']); ?></span></p>
                                <p><strong>Output URL:</strong> <a href="<?php echo $stream['output_url']; ?>" target="_blank"><?php echo $stream['output_url']; ?></a></p>
                                <p><strong>Protocol:</strong> <span class="protocol"><?php echo strtoupper($stream['protocol']); ?></span></p>
                                <p><strong>Views:</strong> <?php echo $stream['total_views']; ?></p>
                                <p><strong>Created:</strong> <?php echo date('Y-m-d H:i', strtotime($stream['created_at'])); ?></p>
                            </div>
                            
                            <div class="stream-actions">
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="stream_id" value="<?php echo $stream['id']; ?>">
                                    <button type="submit" name="toggle_stream" class="btn-sm <?php echo $stream['is_active'] ? 'btn-warning' : 'btn-success'; ?>">
                                        <?php echo $stream['is_active'] ? 'Stop' : 'Start'; ?>
                                    </button>
                                </form>
                                
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this stream?');">
                                    <input type="hidden" name="stream_id" value="<?php echo $stream['id']; ?>">
                                    <button type="submit" name="delete_stream" class="btn-sm btn-danger">Delete</button>
                                </form>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- API Documentation -->
            <div class="card">
                <h2><i class="fas fa-code"></i> API Documentation</h2>
                <div class="api-docs">
                    <h3>Base URL: <?php echo $app_url; ?>/api.php</h3>
                    
                    <div class="api-endpoint">
                        <h4>Get All Streams</h4>
                        <pre>GET /api.php?action=list_streams&api_key=YOUR_API_KEY</pre>
                    </div>
                    
                    <div class="api-endpoint">
                        <h4>Get Single Stream</h4>
                        <pre>GET /api.php?action=get_stream&id=STREAM_ID&api_key=YOUR_API_KEY</pre>
                    </div>
                    
                    <div class="api-endpoint">
                        <h4>Add Stream</h4>
                        <pre>POST /api.php
Content-Type: application/json

{
    "action": "add_stream",
    "api_key": "YOUR_API_KEY",
    "name": "Stream Name",
    "source_url": "http://source.com/stream.m3u8",
    "protocol": "http"
}</pre>
                    </div>
                </div>
            </div>
        </div>
        
        <footer>
            <p>IPTV Restreaming Panel v1.0 &copy; <?php echo date('Y'); ?></p>
            <p>Server Time: <?php echo date('Y-m-d H:i:s'); ?></p>
        </footer>
    </div>
    
    <script>
    // Auto-refresh every 60 seconds
    setTimeout(() => location.reload(), 60000);
    
    // Copy source URL on click
    document.querySelectorAll('.source-url').forEach(el => {
        el.addEventListener('click', function() {
            navigator.clipboard.writeText(this.textContent).then(() => {
                alert('URL copied to clipboard!');
            });
        });
    });
    </script>
</body>
</html>

<?php
require_once 'config.php';

// Public stream listing (no auth required)
$db = getDB();
$stmt = $db->prepare("SELECT 
    s.id, s.name, s.slug, s.output_url, s.protocol, s.total_views, s.created_at,
    u.username as owner
    FROM streams s 
    JOIN users u ON s.user_id = u.id 
    WHERE s.is_active = 1 
    ORDER BY s.total_views DESC");
$stmt->execute();
$streams = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Public Streams - IPTV Panel</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: #f5f5f5;
            color: #333;
            line-height: 1.6;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 10px;
            margin-bottom: 30px;
            text-align: center;
        }
        header h1 {
            margin-bottom: 10px;
        }
        .streams-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stream-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            transition: transform 0.3s;
        }
        .stream-card:hover {
            transform: translateY(-5px);
        }
        .stream-card h3 {
            color: #667eea;
            margin-bottom: 10px;
            font-size: 1.3em;
        }
        .stream-info p {
            margin: 8px 0;
            color: #555;
        }
        .stream-info strong {
            color: #333;
        }
        .play-link {
            display: inline-block;
            background: #667eea;
            color: white;
            padding: 10px 20px;
            border-radius: 5px;
            text-decoration: none;
            margin-top: 10px;
            transition: background 0.3s;
        }
        .play-link:hover {
            background: #764ba2;
        }
        .stats {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            text-align: center;
        }
        .stats h2 {
            color: #667eea;
            margin-bottom: 15px;
        }
        footer {
            text-align: center;
            margin-top: 40px;
            padding: 20px;
            color: #666;
            border-top: 1px solid #ddd;
        }
        .protocol-badge {
            display: inline-block;
            padding: 3px 8px;
            background: #e0e0e0;
            border-radius: 3px;
            font-size: 0.8em;
            margin-left: 5px;
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>Public IPTV Streams</h1>
            <p>Live streaming channels available for public access</p>
        </header>
        
        <div class="stats">
            <h2>Total Available Streams: <?php echo count($streams); ?></h2>
        </div>
        
        <div class="streams-grid">
            <?php foreach ($streams as $stream): ?>
            <div class="stream-card">
                <h3><?php echo htmlspecialchars($stream['name']); ?></h3>
                <div class="stream-info">
                    <p><strong>Owner:</strong> <?php echo htmlspecialchars($stream['owner']); ?></p>
                    <p><strong>Protocol:</strong> <?php echo strtoupper($stream['protocol']); ?></p>
                    <p><strong>Views:</strong> <?php echo number_format($stream['total_views']); ?></p>
                    <p><strong>Added:</strong> <?php echo date('M d, Y', strtotime($stream['created_at'])); ?></p>
                    
                    <a href="<?php echo $stream['output_url']; ?>" target="_blank" class="play-link">
                        Play Stream
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
            
            <?php if (empty($streams)): ?>
            <div class="stream-card" style="grid-column: 1 / -1; text-align: center;">
                <h3>No public streams available at the moment.</h3>
                <p>Check back later or contact the administrator.</p>
            </div>
            <?php endif; ?>
        </div>
        
        <footer>
            <p>IPTV Restreaming Service &copy; <?php echo date('Y'); ?></p>
            <p>All streams are provided by their respective owners.</p>
        </footer>
    </div>
</body>
</html>

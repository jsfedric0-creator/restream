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
            color

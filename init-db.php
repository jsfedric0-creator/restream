<?php
require_once 'config.php';

$db = getDB();

// Add new columns for auto management
$alter_queries = [
    // For auto management
    "ALTER TABLE streams ADD COLUMN IF NOT EXISTS auto_added BOOLEAN DEFAULT 0",
    "ALTER TABLE streams ADD COLUMN IF NOT EXISTS health_status ENUM('online', 'offline', 'unknown') DEFAULT 'unknown'",
    "ALTER TABLE streams ADD COLUMN IF NOT EXISTS failure_count INT DEFAULT 0",
    "ALTER TABLE streams ADD COLUMN IF NOT EXISTS last_working DATETIME NULL",
    "ALTER TABLE streams ADD COLUMN IF NOT EXISTS last_restart DATETIME NULL",
    "ALTER TABLE streams ADD COLUMN IF NOT EXISTS content_type VARCHAR(100) NULL",
    "ALTER TABLE streams ADD COLUMN IF NOT EXISTS content_length INT NULL",
    "ALTER TABLE streams ADD COLUMN IF NOT EXISTS info_updated DATETIME NULL",
    "ALTER TABLE streams ADD COLUMN IF NOT EXISTS country_code CHAR(2) DEFAULT 'SY'",
    
    // For categories
    "ALTER TABLE streams ADD COLUMN IF NOT EXISTS category VARCHAR(50) DEFAULT 'General'",
    
    // Daily stats table
    "CREATE TABLE IF NOT EXISTS daily_stats (
        id INT AUTO_INCREMENT PRIMARY KEY,
        date DATE UNIQUE NOT NULL,
        total_streams INT DEFAULT 0,
        active_streams INT DEFAULT 0,
        total_views INT DEFAULT 0,
        online_streams INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    
    // User settings for auto
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS auto_settings JSON NULL",
    
    // Auto discovery sources
    "CREATE TABLE IF NOT EXISTS m3u_sources (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        url VARCHAR(500) NOT NULL,
        is_active BOOLEAN DEFAULT 1,
        last_fetch DATETIME NULL,
        streams_found INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    
    // Auto rules table
    "CREATE TABLE IF NOT EXISTS auto_rules (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        rule_type ENUM('country', 'category', 'keyword', 'regex') NOT NULL,
        pattern VARCHAR(255) NOT NULL,
        action ENUM('add', 'ignore', 'prioritize') DEFAULT 'add',
        priority INT DEFAULT 0,
        is_active BOOLEAN DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )"
];

foreach ($alter_queries as $query) {
    try {
        $db->exec($query);
        echo "✓ Executed: " . substr($query, 0, 50) . "...\n";
    } catch (Exception $e) {
        echo "✗ Error: " . $e->getMessage() . "\n";
    }
}

// Insert default M3U sources for Syria/Arab channels
$m3u_sources = [
    ['Arab IPTV', 'https://raw.githubusercontent.com/iptv-org/iptv/master/countries/sy.m3u'],
    ['Middle East', 'https://raw.githubusercontent.com/iptv-org/iptv/master/countries/ae.m3u'],
    ['Sports Arabic', 'https://raw.githubusercontent.com/iptv-org/iptv/master/categories/sports.m3u'],
    ['News Arabic', 'https://raw.githubusercontent.com/iptv-org/iptv/master/categories/news.m3u'],
    ['Entertainment', 'https://raw.githubusercontent.com/iptv-org/iptv/master/categories/entertainment.m3u']
];

$stmt = $db->prepare("INSERT IGNORE INTO m3u_sources (name, url) VALUES (?, ?)");
foreach ($m3u_sources as $source) {
    $stmt->execute($source);
}

// Insert auto rules
$rules = [
    ['Syria Channels', 'country', 'SY', 'prioritize', 100],
    ['Arabic News', 'keyword', 'news', 'add', 90],
    ['Sports Arabic', 'keyword', 'sports', 'add', 80],
    ['Quran Islamic', 'keyword', 'quran', 'add', 70],
    ['Movies Arabic', 'keyword', 'movie', 'add', 60],
    ['Kids Arabic', 'keyword', 'kids', 'add', 50]
];

$stmt = $db->prepare("INSERT IGNORE INTO auto_rules (name, rule_type, pattern, action, priority) VALUES (?, ?, ?, ?, ?)");
foreach ($rules as $rule) {
    $stmt->execute($rule);
}

echo "\n✅ Database setup complete for auto management!\n";
?>

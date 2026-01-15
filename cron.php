<?php
require_once 'config.php';

// Prevent web access
if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

class CronManager {
    private $db;
    
    public function __construct() {
        $this->db = getDB();
    }
    
    public function run() {
        $tasks = [
            'stream_health_check' => $this->healthCheck(),
            'update_statistics' => $this->updateStats(),
            'cleanup_logs' => $this->cleanupLogs(),
            'backup_database' => $this->backupDB(),
            'send_daily_report' => $this->dailyReport()
        ];
        
        return $tasks;
    }
    
    private function healthCheck() {
        require_once 'auto.php';
        $auto = new AutoStreamManager();
        return $auto->run('check');
    }
    
    private function updateStats() {
        // Update daily statistics
        $stmt = $this->db->prepare("
            INSERT INTO daily_stats (date, total_streams, active_streams, total_views, online_streams)
            SELECT 
                CURDATE(),
                COUNT(*) as total_streams,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_streams,
                SUM(total_views) as total_views,
                SUM(CASE WHEN health_status = 'online' THEN 1 ELSE 0 END) as online_streams
            FROM streams
            ON DUPLICATE KEY UPDATE
                total_streams = VALUES(total_streams),
                active_streams = VALUES(active_streams),
                total_views = VALUES(total_views),
                online_streams = VALUES(online_streams)
        ");
        $stmt->execute();
        
        return ['updated' => true, 'rows' => $stmt->rowCount()];
    }
    
    private function cleanupLogs() {
        // Delete old logs (older than 30 days)
        $stmt = $this->db->prepare("DELETE FROM stream_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $stmt->execute();
        $logs_deleted = $stmt->rowCount();
        
        $stmt = $this->db->prepare("DELETE FROM api_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $stmt->execute();
        $api_logs_deleted = $stmt->rowCount();
        
        // Cleanup failed streams
        $stmt = $this->db->prepare("
            UPDATE streams 
            SET is_active = 0 
            WHERE is_active = 1 
            AND health_status = 'offline' 
            AND last_working < DATE_SUB(NOW(), INTERVAL 3 DAY)
            AND failure_count > 10
        ");
        $stmt->execute();
        $streams_deactivated = $stmt->rowCount();
        
        return [
            'logs_deleted' => $logs_deleted,
            'api_logs_deleted' => $api_logs_deleted,
            'streams_deactivated' => $streams_deactivated
        ];
    }
    
    private function backupDB() {
        $backup_dir = '/var/backups';
        if (!is_dir($backup_dir)) {
            mkdir($backup_dir, 0755, true);
        }
        
        $filename = $backup_dir . '/backup-' . date('Y-m-d-H-i-s') . '.sql';
        
        $config = [
            'host' => getenv('DB_HOST'),
            'user' => getenv('DB_USER'),
            'pass' => getenv('DB_PASS'),
            'name' => getenv('DB_NAME')
        ];
        
        $command = sprintf(
            'mysqldump -h %s -u %s -p%s %s > %s 2>/dev/null',
            $config['host'],
            $config['user'],
            $config['pass'],
            $config['name'],
            $filename
        );
        
        exec($command, $output, $return_var);
        
        if ($return_var === 0 && filesize($filename) > 0) {
            // Keep only last 7 backups
            $backups = glob($backup_dir . '/backup-*.sql');
            if (count($backups) > 7) {
                usort($backups, function($a, $b) {
                    return filemtime($a) - filemtime($b);
                });
                
                for ($i = 0; $i < count($backups) - 7; $i++) {
                    unlink($backups[$i]);
                }
            }
            
            return ['success' => true, 'file' => $filename, 'size' => filesize($filename)];
        }
        
        return ['success' => false, 'error' => 'Backup failed'];
    }
    
    private function dailyReport() {
        $stmt = $this->db->query("
            SELECT 
                COUNT(*) as total_streams,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_streams,
                SUM(CASE WHEN health_status = 'online' THEN 1 ELSE 0 END) as online_streams,
                SUM(total_views) as total_views,
                SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as new_today,
                SUM(CASE WHEN auto_added = 1 THEN 1 ELSE 0 END) as auto_streams
            FROM streams
        ");
        $stats = $stmt->fetch();
        
        $report = "
📊 IPTV Daily Report
📅 Date: " . date('Y-m-d') . "

📈 Statistics:
• Total Streams: {$stats['total_streams']}
• Active Streams: {$stats['active_streams']}
• Online Now: {$stats['online_streams']}
• Total Views: {$stats['total_views']}
• New Today: {$stats['new_today']}
• Auto Streams: {$stats['auto_streams']}

⚡ Auto System:
• Auto Discover: " . (getenv('AUTO_DISCOVER') ? 'Enabled' : 'Disabled') . "
• Auto Restart: " . (getenv('AUTO_RESTART') ? 'Enabled' : 'Disabled') . "

🔧 System Status:
• Memory Usage: " . round(memory_get_usage(true) / 1024 / 1024, 2) . "MB
• Uptime: " . @file_get_contents('/proc/uptime') . " seconds
        ";
        
        // Send via Telegram if configured
        $bot_token = getenv('MONITOR_TELEGRAM_BOT');
        $chat_id = getenv('MONITOR_TELEGRAM_CHAT');
        
        if ($bot_token && $chat_id) {
            $this->sendTelegram($bot_token, $chat_id, $report);
        }
        
        // Log report
        file_put_contents('/var/log/streams/daily.log', $report, FILE_APPEND);
        
        return ['sent' => true, 'stats' => $stats];
    }
    
    private function sendTelegram($bot_token, $chat_id, $message) {
        $url = "https://api.telegram.org/bot{$bot_token}/sendMessage";
        $data = [
            'chat_id' => $chat_id,
            'text' => $message,
            'parse_mode' => 'Markdown'
        ];
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_TIMEOUT => 5
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}

// Run cron
$cron = new CronManager();
$results = $cron->run();

echo json_encode(['timestamp' => date('Y-m-d H:i:s'), 'tasks' => $results], JSON_PRETTY_PRINT);
?>

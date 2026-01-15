<?php
require_once 'config.php';

class AutoStreamManager {
    private $db;
    private $config;
    
    public function __construct() {
        $this->db = getDB();
        $this->config = [
            'auto_discover' => filter_var(getenv('AUTO_DISCOVER'), FILTER_VALIDATE_BOOLEAN),
            'auto_restart' => filter_var(getenv('AUTO_RESTART'), FILTER_VALIDATE_BOOLEAN),
            'check_interval' => (int)getenv('AUTO_CHECK_INTERVAL') ?: 60,
            'max_streams' => (int)getenv('AUTO_MAX_STREAMS') ?: 50,
            'm3u_urls' => explode(',', getenv('AUTO_M3U_URLS') ?: ''),
            'countries' => explode(',', getenv('AUTO_COUNTRIES') ?: 'SY'),
            'categories' => explode(',', getenv('AUTO_CATEGORIES') ?: 'News,Sports'),
            'timeout' => (int)getenv('STREAM_TIMEOUT') ?: 30,
        ];
    }
    
    // Main auto function
    public function run($action = 'check') {
        switch ($action) {
            case 'discover':
                return $this->discoverStreams();
            case 'check':
                return $this->checkStreams();
            case 'restart':
                return $this->restartDeadStreams();
            case 'cleanup':
                return $this->cleanupOldStreams();
            case 'update':
                return $this->updateAllStreams();
            default:
                return $this->checkStreams();
        }
    }
    
    // Discover new streams from M3U URLs
    public function discoverStreams() {
        if (!$this->config['auto_discover']) {
            return ['status' => 'disabled', 'message' => 'Auto discover is disabled'];
        }
        
        $discovered = 0;
        $errors = 0;
        
        foreach ($this->config['m3u_urls'] as $m3u_url) {
            if (empty($m3u_url)) continue;
            
            try {
                $streams = $this->parseM3U($m3u_url);
                foreach ($streams as $stream) {
                    if ($this->shouldAddStream($stream)) {
                        if ($this->addStream($stream)) {
                            $discovered++;
                            error_log("Discovered: {$stream['name']}");
                        }
                    }
                }
            } catch (Exception $e) {
                $errors++;
                error_log("Error parsing M3U {$m3u_url}: " . $e->getMessage());
            }
        }
        
        return [
            'status' => 'success',
            'discovered' => $discovered,
            'errors' => $errors,
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
    
    // Parse M3U playlist
    private function parseM3U($url) {
        $content = @file_get_contents($url, false, stream_context_create([
            'http' => ['timeout' => 10]
        ]));
        
        if (!$content) {
            throw new Exception("Failed to fetch M3U");
        }
        
        $lines = explode("\n", $content);
        $streams = [];
        $current = [];
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            if (strpos($line, '#EXTINF:') === 0) {
                // Parse EXTINF line
                preg_match('/#EXTINF:-?[0-9]+,(.+)/', $line, $matches);
                $current['name'] = $matches[1] ?? 'Unknown';
                
                // Extract group/category
                if (preg_match('/group-title="([^"]+)"/', $line, $groupMatch)) {
                    $current['category'] = $groupMatch[1];
                }
                
                // Extract logo
                if (preg_match('/tvg-logo="([^"]+)"/', $line, $logoMatch)) {
                    $current['logo'] = $logoMatch[1];
                }
                
                // Extract country
                if (preg_match('/tvg-country="([^"]+)"/', $line, $countryMatch)) {
                    $current['country'] = $countryMatch[1];
                }
            } elseif (!empty($line) && $line[0] !== '#') {
                // URL line
                $current['url'] = trim($line);
                
                if (!empty($current['name']) && !empty($current['url'])) {
                    $streams[] = $current;
                }
                $current = [];
            }
        }
        
        return $streams;
    }
    
    // Check if stream should be added
    private function shouldAddStream($stream) {
        // Check country filter
        if (!empty($this->config['countries'])) {
            $countryMatch = false;
            foreach ($this->config['countries'] as $country) {
                if (stripos($stream['name'] ?? '', $country) !== false || 
                    stripos($stream['country'] ?? '', $country) !== false) {
                    $countryMatch = true;
                    break;
                }
            }
            if (!$countryMatch) return false;
        }
        
        // Check category filter
        if (!empty($this->config['categories'])) {
            $categoryMatch = false;
            foreach ($this->config['categories'] as $category) {
                if (stripos($stream['name'] ?? '', $category) !== false ||
                    stripos($stream['category'] ?? '', $category) !== false) {
                    $categoryMatch = true;
                    break;
                }
            }
            if (!$categoryMatch) return false;
        }
        
        // Check if already exists
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM streams WHERE source_url = ? OR name = ?");
        $stmt->execute([$stream['url'], $stream['name']]);
        $exists = $stmt->fetchColumn() > 0;
        
        return !$exists;
    }
    
    // Add stream to database
    private function addStream($stream) {
        $admin_id = $this->getAdminId();
        if (!$admin_id) return false;
        
        $slug = $this->generateSlug($stream['name']);
        $output_url = generateStreamUrl($slug);
        
        $stmt = $this->db->prepare("INSERT INTO streams 
            (user_id, name, slug, source_url, output_url, protocol, category, country_code, is_active, auto_added) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 1)");
        
        $protocol = $this->detectProtocol($stream['url']);
        $category = $stream['category'] ?? 'Auto-Discovered';
        $country = $this->detectCountry($stream);
        
        return $stmt->execute([
            $admin_id,
            $stream['name'],
            $slug,
            $stream['url'],
            $output_url,
            $protocol,
            $category,
            $country
        ]);
    }
    
    // Check all streams health
    public function checkStreams() {
        $stmt = $this->db->prepare("SELECT id, name, source_url, is_active FROM streams WHERE is_active = 1");
        $stmt->execute();
        $streams = $stmt->fetchAll();
        
        $results = [
            'total' => count($streams),
            'online' => 0,
            'offline' => 0,
            'restarted' => 0,
            'details' => []
        ];
        
        foreach ($streams as $stream) {
            $status = $this->checkStream($stream['source_url']);
            $results['details'][$stream['id']] = [
                'name' => $stream['name'],
                'status' => $status ? 'online' : 'offline',
                'checked' => date('Y-m-d H:i:s')
            ];
            
            if ($status) {
                $results['online']++;
                
                // Update last working time
                $this->db->prepare("UPDATE streams SET last_working = NOW(), health_status = 'online' WHERE id = ?")
                    ->execute([$stream['id']]);
                    
            } else {
                $results['offline']++;
                
                // Update as offline
                $this->db->prepare("UPDATE streams SET health_status = 'offline', failure_count = failure_count + 1 WHERE id = ?")
                    ->execute([$stream['id']]);
                
                // Auto restart if enabled
                if ($this->config['auto_restart']) {
                    $this->restartStream($stream['id']);
                    $results['restarted']++;
                }
            }
        }
        
        return $results;
    }
    
    // Check single stream
    private function checkStream($url) {
        $ch = curl_init($url);
        
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => $this->config['timeout'],
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_USERAGENT => 'IPTV-Auto-Checker/1.0'
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $total_time = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        curl_close($ch);
        
        // Consider stream online if HTTP 200 or 302
        $online = ($http_code >= 200 && $http_code < 400) || $http_code == 302;
        
        // Log the check
        $this->logStreamCheck([
            'url' => $url,
            'http_code' => $http_code,
            'response_time' => $total_time,
            'status' => $online ? 'online' : 'offline'
        ]);
        
        return $online;
    }
    
    // Restart dead streams
    private function restartDeadStreams() {
        $stmt = $this->db->prepare("
            SELECT id, name, source_url 
            FROM streams 
            WHERE is_active = 1 
            AND health_status = 'offline' 
            AND failure_count >= 3
            AND (last_restart IS NULL OR last_restart < DATE_SUB(NOW(), INTERVAL 5 MINUTE))
        ");
        $stmt->execute();
        $streams = $stmt->fetchAll();
        
        $restarted = 0;
        foreach ($streams as $stream) {
            if ($this->restartStream($stream['id'])) {
                $restarted++;
                
                // Send alert
                $this->sendAlert("Stream Restarted: {$stream['name']}");
            }
        }
        
        return ['restarted' => $restarted, 'total' => count($streams)];
    }
    
    // Cleanup old/unused streams
    private function cleanupOldStreams() {
        // Remove streams with no views for 7 days
        $stmt = $this->db->prepare("
            DELETE FROM streams 
            WHERE auto_added = 1 
            AND total_views = 0 
            AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
            AND (last_working IS NULL OR last_working < DATE_SUB(NOW(), INTERVAL 3 DAY))
        ");
        $stmt->execute();
        $deleted = $stmt->rowCount();
        
        // Deactivate streams offline for 24 hours
        $stmt = $this->db->prepare("
            UPDATE streams 
            SET is_active = 0 
            WHERE is_active = 1 
            AND health_status = 'offline' 
            AND last_working < DATE_SUB(NOW(), INTERVAL 1 DAY)
        ");
        $stmt->execute();
        $deactivated = $stmt->rowCount();
        
        return ['deleted' => $deleted, 'deactivated' => $deactivated];
    }
    
    // Update all streams info
    private function updateAllStreams() {
        $stmt = $this->db->prepare("SELECT id, source_url FROM streams WHERE is_active = 1");
        $stmt->execute();
        $streams = $stmt->fetchAll();
        
        $updated = 0;
        foreach ($streams as $stream) {
            $info = $this->getStreamInfo($stream['source_url']);
            if ($info) {
                $this->updateStreamInfo($stream['id'], $info);
                $updated++;
            }
        }
        
        return ['updated' => $updated, 'total' => count($streams)];
    }
    
    // Helper functions
    private function getAdminId() {
        $stmt = $this->db->prepare("SELECT id FROM users WHERE username = 'admin' LIMIT 1");
        $stmt->execute();
        return $stmt->fetchColumn();
    }
    
    private function generateSlug($name) {
        $slug = preg_replace('/[^a-z0-9]/', '-', strtolower($name));
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');
        
        // Ensure unique slug
        $original = $slug;
        $counter = 1;
        while ($this->slugExists($slug)) {
            $slug = $original . '-' . $counter;
            $counter++;
        }
        
        return $slug;
    }
    
    private function slugExists($slug) {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM streams WHERE slug = ?");
        $stmt->execute([$slug]);
        return $stmt->fetchColumn() > 0;
    }
    
    private function detectProtocol($url) {
        if (strpos($url, 'rtmp://') === 0) return 'rtmp';
        if (strpos($url, 'rtsp://') === 0) return 'rtsp';
        if (strpos($url, 'udp://') === 0) return 'udp';
        if (strpos($url, '.m3u8') !== false) return 'm3u8';
        return 'http';
    }
    
    private function detectCountry($stream) {
        // Try to detect from name or country field
        $country_codes = ['SY', 'SA', 'AE', 'QA', 'EG', 'LB', 'JO', 'KW', 'BH', 'OM'];
        
        foreach ($country_codes as $code) {
            if (stripos($stream['name'] ?? '', $code) !== false) {
                return $code;
            }
        }
        
        return $stream['country'] ?? 'SY';
    }
    
    private function restartStream($stream_id) {
        // Update restart time
        $this->db->prepare("UPDATE streams SET last_restart = NOW(), failure_count = 0 WHERE id = ?")
            ->execute([$stream_id]);
        
        // Log restart
        logStreamEvent($stream_id, 'auto_restart', 'Stream automatically restarted');
        
        return true;
    }
    
    private function logStreamCheck($data) {
        $log = date('Y-m-d H:i:s') . " | " . json_encode($data) . "\n";
        file_put_contents('/var/log/streams/checks.log', $log, FILE_APPEND);
    }
    
    private function sendAlert($message) {
        $telegram_bot = getenv('MONITOR_TELEGRAM_BOT');
        $telegram_chat = getenv('MONITOR_TELEGRAM_CHAT');
        
        if ($telegram_bot && $telegram_chat) {
            $this->sendTelegramAlert($telegram_bot, $telegram_chat, $message);
        }
        
        // Also log to file
        error_log("ALERT: " . $message);
    }
    
    private function sendTelegramAlert($bot_token, $chat_id, $message) {
        $url = "https://api.telegram.org/bot{$bot_token}/sendMessage";
        $data = [
            'chat_id' => $chat_id,
            'text' => $message,
            'parse_mode' => 'HTML'
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
    
    private function getStreamInfo($url) {
        // Attempt to get stream information
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => false,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RANGE => '0-100000' // Get first 100KB
        ]);
        
        $response = curl_exec($ch);
        $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $content_length = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        curl_close($ch);
        
        return [
            'content_type' => $content_type,
            'content_length' => $content_length,
            'last_checked' => date('Y-m-d H:i:s')
        ];
    }
    
    private function updateStreamInfo($stream_id, $info) {
        $stmt = $this->db->prepare("
            UPDATE streams 
            SET content_type = ?, content_length = ?, info_updated = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$info['content_type'], $info['content_length'], $stream_id]);
    }
}

// CLI handler
if (php_sapi_name() === 'cli') {
    $action = $argv[1] ?? 'check';
    $manager = new AutoStreamManager();
    $result = $manager->run($action);
    
    echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
    
    // Log result
    file_put_contents('/var/log/streams/auto.log', 
        date('Y-m-d H:i:s') . " | {$action} | " . json_encode($result) . "\n", 
        FILE_APPEND
    );
}
?>

-- Create database and user
CREATE DATABASE IF NOT EXISTS iptv_panel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE iptv_panel;

-- Users table
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    api_key VARCHAR(64) UNIQUE,
    role ENUM('admin', 'user') DEFAULT 'user',
    max_streams INT DEFAULT 10,
    status ENUM('active', 'suspended', 'pending') DEFAULT 'pending',
    last_login DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_username (username),
    INDEX idx_status (status)
);

-- Streams table
CREATE TABLE streams (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) UNIQUE NOT NULL,
    source_url VARCHAR(500) NOT NULL,
    output_url VARCHAR(500),
    protocol ENUM('http', 'https', 'rtmp', 'rtsp', 'udp', 'm3u8') DEFAULT 'http',
    buffer_size INT DEFAULT 4096,
    timeout INT DEFAULT 30,
    max_connections INT DEFAULT 100,
    is_active BOOLEAN DEFAULT TRUE,
    last_check DATETIME,
    total_views INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_slug (slug),
    INDEX idx_user_id (user_id),
    INDEX idx_is_active (is_active)
);

-- Stream logs table
CREATE TABLE stream_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    stream_id INT NOT NULL,
    event_type VARCHAR(50),
    message TEXT,
    client_ip VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (stream_id) REFERENCES streams(id) ON DELETE CASCADE,
    INDEX idx_stream_id (stream_id),
    INDEX idx_created_at (created_at)
);

-- API access logs
CREATE TABLE api_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    endpoint VARCHAR(255),
    method VARCHAR(10),
    status_code INT,
    response_time FLOAT,
    client_ip VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_created_at (created_at)
);

-- Settings table
CREATE TABLE settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    setting_type ENUM('string', 'integer', 'boolean', 'json') DEFAULT 'string',
    is_public BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_setting_key (setting_key)
);

-- Insert default admin user (password will be hashed in PHP)
INSERT INTO users (username, password_hash, email, role, status) 
VALUES ('admin', '$2y$10$YourHashedPasswordHere', 'admin@iptv.local', 'admin', 'active');

-- Insert default settings
INSERT INTO settings (setting_key, setting_value, setting_type, is_public) VALUES
('site_name', 'IPTV Restream Panel', 'string', TRUE),
('max_streams_per_user', '10', 'integer', TRUE),
('stream_timeout', '30', 'integer', TRUE),
('enable_registration', 'false', 'boolean', TRUE),
('maintenance_mode', 'false', 'boolean', TRUE);

-- Create views for statistics
CREATE VIEW stream_stats AS
SELECT 
    s.id,
    s.name,
    s.slug,
    s.is_active,
    s.total_views,
    COUNT(DISTINCT sl.id) as total_events,
    MAX(sl.created_at) as last_event
FROM streams s
LEFT JOIN stream_logs sl ON s.id = sl.stream_id
GROUP BY s.id;

CREATE VIEW user_stats AS
SELECT 
    u.id,
    u.username,
    u.role,
    u.status,
    COUNT(DISTINCT s.id) as total_streams,
    SUM(s.total_views) as total_views,
    MAX(s.created_at) as last_stream_created
FROM users u
LEFT JOIN streams s ON u.id = s.user_id
GROUP BY u.id;

-- MySQL Configuration Optimization for Production
-- Run these commands to optimize MySQL for high connection usage

-- Increase max connections (adjust based on your server capacity)
SET GLOBAL max_connections = 500;

-- Set connection timeout
SET GLOBAL wait_timeout = 300;
SET GLOBAL interactive_timeout = 300;

-- Optimize connection handling
SET GLOBAL thread_cache_size = 16;
SET GLOBAL table_open_cache = 4000;
SET GLOBAL table_definition_cache = 2000;

-- Enable query cache (if available)
SET GLOBAL query_cache_size = 64M;
SET GLOBAL query_cache_type = 1;

-- Optimize for InnoDB
SET GLOBAL innodb_buffer_pool_size = 1G; -- Adjust based on available RAM
SET GLOBAL innodb_log_file_size = 256M;
SET GLOBAL innodb_flush_log_at_trx_commit = 2;

-- Connection monitoring
CREATE EVENT IF NOT EXISTS connection_monitor
ON SCHEDULE EVERY 1 MINUTE
DO
BEGIN
    INSERT INTO connection_logs (timestamp, connections, running_threads)
    SELECT NOW(), 
           (SELECT VARIABLE_VALUE FROM INFORMATION_SCHEMA.GLOBAL_STATUS WHERE VARIABLE_NAME = 'Threads_connected'),
           (SELECT VARIABLE_VALUE FROM INFORMATION_SCHEMA.GLOBAL_STATUS WHERE VARIABLE_NAME = 'Threads_running');
END;

-- Create connection monitoring table
CREATE TABLE IF NOT EXISTS connection_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    timestamp DATETIME,
    connections INT,
    running_threads INT,
    INDEX idx_timestamp (timestamp)
);

-- Show current connection status
SELECT 
    VARIABLE_NAME,
    VARIABLE_VALUE
FROM INFORMATION_SCHEMA.GLOBAL_STATUS 
WHERE VARIABLE_NAME IN (
    'Threads_connected',
    'Threads_running', 
    'Max_used_connections',
    'Max_connections',
    'Aborted_connects',
    'Connection_errors_max_connections'
);

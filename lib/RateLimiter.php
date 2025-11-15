<?php
namespace App;

/**
 * Rate Limiter
 * Prevents API abuse by limiting requests per IP/user
 */
class RateLimiter {
    private $conn;
    private $cacheTable = 'rate_limit_cache';

    /**
     * Constructor
     *
     * @param mysqli $dbConnection Database connection
     */
    public function __construct($dbConnection) {
        $this->conn = $dbConnection;
        $this->createTableIfNotExists();
    }

    /**
     * Create rate limit cache table if not exists
     */
    private function createTableIfNotExists() {
        $query = "
            CREATE TABLE IF NOT EXISTS {$this->cacheTable} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                identifier VARCHAR(255) NOT NULL,
                endpoint VARCHAR(255) NOT NULL,
                request_count INT DEFAULT 1,
                window_start DATETIME NOT NULL,
                INDEX idx_identifier (identifier),
                INDEX idx_endpoint (endpoint),
                INDEX idx_window (window_start)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ";

        $this->conn->query($query);
    }

    /**
     * Check if request is allowed
     *
     * @param string $identifier IP address or user ID
     * @param string $endpoint API endpoint
     * @param int $maxRequests Maximum requests allowed
     * @param int $windowSeconds Time window in seconds (default: 60)
     * @return array ['allowed' => bool, 'remaining' => int, 'reset_at' => timestamp]
     */
    public function check($identifier, $endpoint, $maxRequests = 60, $windowSeconds = 60) {
        // Clean old records first
        $this->cleanup();

        $windowStart = date('Y-m-d H:i:s', time() - $windowSeconds);

        // Get current request count
        $stmt = $this->conn->prepare("
            SELECT id, request_count, window_start
            FROM {$this->cacheTable}
            WHERE identifier = ? AND endpoint = ? AND window_start > ?
            LIMIT 1
        ");
        $stmt->bind_param("sss", $identifier, $endpoint, $windowStart);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            // Existing record found
            $row = $result->fetch_assoc();
            $requestCount = $row['request_count'];

            if ($requestCount >= $maxRequests) {
                // Rate limit exceeded
                return [
                    'allowed' => false,
                    'remaining' => 0,
                    'reset_at' => strtotime($row['window_start']) + $windowSeconds,
                    'retry_after' => (strtotime($row['window_start']) + $windowSeconds) - time()
                ];
            }

            // Increment count
            $updateStmt = $this->conn->prepare("
                UPDATE {$this->cacheTable}
                SET request_count = request_count + 1
                WHERE id = ?
            ");
            $updateStmt->bind_param("i", $row['id']);
            $updateStmt->execute();

            return [
                'allowed' => true,
                'remaining' => $maxRequests - $requestCount - 1,
                'reset_at' => strtotime($row['window_start']) + $windowSeconds
            ];
        } else {
            // First request in window
            $now = date('Y-m-d H:i:s');
            $insertStmt = $this->conn->prepare("
                INSERT INTO {$this->cacheTable} (identifier, endpoint, request_count, window_start)
                VALUES (?, ?, 1, ?)
            ");
            $insertStmt->bind_param("sss", $identifier, $endpoint, $now);
            $insertStmt->execute();

            return [
                'allowed' => true,
                'remaining' => $maxRequests - 1,
                'reset_at' => time() + $windowSeconds
            ];
        }
    }

    /**
     * Clean up old records
     *
     * @param int $olderThanSeconds Delete records older than this (default: 3600)
     */
    public function cleanup($olderThanSeconds = 3600) {
        $threshold = date('Y-m-d H:i:s', time() - $olderThanSeconds);
        $this->conn->query("DELETE FROM {$this->cacheTable} WHERE window_start < '{$threshold}'");
    }

    /**
     * Get client identifier (IP address with proxy support)
     *
     * @return string Client IP address
     */
    public static function getClientIdentifier() {
        // Check for proxy headers
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        }

        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            return $_SERVER['HTTP_X_REAL_IP'];
        }

        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    /**
     * Block request with rate limit headers
     *
     * @param array $limitInfo Limit information from check()
     */
    public static function blockRequest($limitInfo) {
        http_response_code(429);
        header('Content-Type: application/json');
        header('X-RateLimit-Limit: ' . ($limitInfo['remaining'] + 1));
        header('X-RateLimit-Remaining: 0');
        header('X-RateLimit-Reset: ' . $limitInfo['reset_at']);
        header('Retry-After: ' . $limitInfo['retry_after']);

        echo json_encode([
            'success' => false,
            'error' => 'Rate limit exceeded',
            'message' => 'Too many requests. Please try again later.',
            'retry_after' => $limitInfo['retry_after']
        ]);
        exit;
    }

    /**
     * Send rate limit headers with response
     *
     * @param array $limitInfo Limit information from check()
     */
    public static function sendHeaders($limitInfo) {
        header('X-RateLimit-Remaining: ' . $limitInfo['remaining']);
        header('X-RateLimit-Reset: ' . $limitInfo['reset_at']);
    }
}

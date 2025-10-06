<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Enhanced logging system for WooCommerce Percentage Shipping
 * 
 * @package WC_Percentage_Shipping
 * @since 1.3.0
 */
final class WC_Percentage_Shipping_Logger
{
    private const LOG_SOURCE = 'wc-percentage-shipping';
    private const MAX_LOG_SIZE = 1048576; // 1MB
    
    /**
     * Log levels
     */
    public const LEVEL_DEBUG = 'debug';
    public const LEVEL_INFO = 'info';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_ERROR = 'error';
    
    private static ?self $instance = null;
    private bool $debug_enabled = false;
    private array $log_buffer = [];
    private int $max_buffer_size = 100;
    
    private function __construct()
    {
        $options = get_option(PluginConfig::OPTION_NAME->value, []);
        $this->debug_enabled = ($options['debug_mode'] ?? 'no') === 'yes';
        
        // Register shutdown handler for buffered logs
        register_shutdown_function([$this, 'flush_buffer']);
    }
    
    public static function get_instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Log debug message
     * 
     * @param string $message Log message
     * @param array $context Additional context data
     * @return void
     */
    public static function debug(string $message, array $context = []): void
    {
        self::get_instance()->log(self::LEVEL_DEBUG, $message, $context);
    }
    
    /**
     * Log info message
     * 
     * @param string $message Log message
     * @param array $context Additional context data
     * @return void
     */
    public static function info(string $message, array $context = []): void
    {
        self::get_instance()->log(self::LEVEL_INFO, $message, $context);
    }
    
    /**
     * Log warning message
     * 
     * @param string $message Log message
     * @param array $context Additional context data
     * @return void
     */
    public static function warning(string $message, array $context = []): void
    {
        self::get_instance()->log(self::LEVEL_WARNING, $message, $context);
    }
    
    /**
     * Log error message
     * 
     * @param string $message Log message
     * @param array $context Additional context data
     * @return void
     */
    public static function error(string $message, array $context = []): void
    {
        self::get_instance()->log(self::LEVEL_ERROR, $message, $context);
    }
    
    /**
     * Log calculation details
     * 
     * @param array $package Package data
     * @param array $options Plugin options
     * @param float $result Calculated result
     * @param float $execution_time Execution time in milliseconds
     * @return void
     */
    public static function log_calculation(array $package, array $options, float $result, float $execution_time): void
    {
        $context = [
            'package_items' => count($package['contents'] ?? []),
            'options' => [
                'percentage' => $options['percentage'] ?? 10,
                'minimum_fee' => $options['minimum_fee'] ?? 0,
                'maximum_fee' => $options['maximum_fee'] ?? 0,
                'include_digital_products' => $options['include_digital_products'] ?? 'no',
            ],
            'result' => $result,
            'execution_time_ms' => $execution_time,
            'cache_hit' => isset($context['cache_hit']) ? $context['cache_hit'] : false,
        ];
        
        self::info('Shipping calculation completed', $context);
    }
    
    /**
     * Log performance metrics
     * 
     * @param string $operation Operation name
     * @param float $execution_time Execution time in milliseconds
     * @param array $metrics Additional metrics
     * @return void
     */
    public static function log_performance(string $operation, float $execution_time, array $metrics = []): void
    {
        $context = array_merge($metrics, [
            'operation' => $operation,
            'execution_time_ms' => $execution_time,
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
        ]);
        
        if ($execution_time > 100) { // Log as warning if > 100ms
            self::warning("Slow operation: {$operation}", $context);
        } else {
            self::debug("Performance: {$operation}", $context);
        }
    }
    
    /**
     * Log security events
     * 
     * @param string $event Security event type
     * @param array $details Event details
     * @return void
     */
    public static function log_security(string $event, array $details = []): void
    {
        $context = array_merge($details, [
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'timestamp' => current_time('mysql'),
        ]);
        
        self::warning("Security event: {$event}", $context);
    }
    
    /**
     * Internal log method
     * 
     * @param string $level Log level
     * @param string $message Log message
     * @param array $context Additional context
     * @return void
     */
    private function log(string $level, string $message, array $context = []): void
    {
        // Skip debug messages if debug mode is disabled
        if ($level === self::LEVEL_DEBUG && !$this->debug_enabled) {
            return;
        }
        
        $log_entry = $this->format_log_entry($level, $message, $context);
        
        // Buffer logs for performance
        $this->log_buffer[] = $log_entry;
        
        // Flush buffer if it's getting too large
        if (count($this->log_buffer) >= $this->max_buffer_size) {
            $this->flush_buffer();
        }
    }
    
    /**
     * Format log entry
     * 
     * @param string $level Log level
     * @param string $message Log message
     * @param array $context Additional context
     * @return array Formatted log entry
     */
    private function format_log_entry(string $level, string $message, array $context): array
    {
        return [
            'timestamp' => current_time('mysql'),
            'level' => strtoupper($level),
            'message' => $message,
            'context' => $context,
            'source' => self::LOG_SOURCE,
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
        ];
    }
    
    /**
     * Flush log buffer to WooCommerce logger
     * 
     * @return void
     */
    public function flush_buffer(): void
    {
        if (empty($this->log_buffer)) {
            return;
        }
        
        $logger = wc_get_logger();
        
        foreach ($this->log_buffer as $entry) {
            $formatted_message = sprintf(
                '[%s] %s: %s | Context: %s | Memory: %s',
                $entry['timestamp'],
                $entry['level'],
                $entry['message'],
                wp_json_encode($entry['context']),
                $this->format_bytes($entry['memory_usage'])
            );
            
            $logger->log(
                $this->map_level_to_wc_level($entry['level']),
                $formatted_message,
                ['source' => $entry['source']]
            );
        }
        
        $this->log_buffer = [];
    }
    
    /**
     * Map our log levels to WooCommerce log levels
     * 
     * @param string $level Our log level
     * @return string WooCommerce log level
     */
    private function map_level_to_wc_level(string $level): string
    {
        return match (strtolower($level)) {
            'debug' => 'debug',
            'info' => 'info',
            'warning' => 'notice',
            'error' => 'error',
            default => 'info'
        };
    }
    
    /**
     * Format bytes to human readable format
     * 
     * @param int $bytes Bytes to format
     * @return string Formatted string
     */
    private function format_bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= (1 << (10 * $pow));
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
    
    /**
     * Clear old log entries
     * 
     * @param int $days_old Number of days to keep logs
     * @return void
     */
    public static function cleanup_old_logs(int $days_old = 30): void
    {
        $logger = wc_get_logger();
        
        // Get log file path
        $log_file = WC_LOG_DIR . self::LOG_SOURCE . '-' . date('Y-m-d') . '.log';
        
        if (file_exists($log_file) && filesize($log_file) > self::MAX_LOG_SIZE) {
            // Rotate log file if it's too large
            $rotated_file = $log_file . '.' . time();
            rename($log_file, $rotated_file);
        }
        
        // Clean up old rotated files
        $pattern = WC_LOG_DIR . self::LOG_SOURCE . '-' . date('Y-m-d', strtotime("-{$days_old} days")) . '.log.*';
        $old_files = glob($pattern);
        
        foreach ($old_files as $file) {
            if (file_exists($file) && (time() - filemtime($file)) > ($days_old * DAY_IN_SECONDS)) {
                unlink($file);
            }
        }
    }
    
    /**
     * Get log statistics
     * 
     * @return array Log statistics
     */
    public static function get_log_stats(): array
    {
        $log_file = WC_LOG_DIR . self::LOG_SOURCE . '-' . date('Y-m-d') . '.log';
        
        if (!file_exists($log_file)) {
            return [
                'file_exists' => false,
                'file_size' => 0,
                'last_modified' => null,
                'entries_today' => 0,
            ];
        }
        
        $content = file_get_contents($log_file);
        $lines = explode("\n", $content);
        $entries_today = count(array_filter($lines, fn($line) => !empty(trim($line))));
        
        return [
            'file_exists' => true,
            'file_size' => filesize($log_file),
            'last_modified' => filemtime($log_file),
            'entries_today' => $entries_today,
        ];
    }
}

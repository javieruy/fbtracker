<?php
/**
 * Clase Logger para manejo centralizado de logs
 * 
 * Proporciona logging estructurado con diferentes niveles
 * y rotación automática de archivos
 */

class Logger {
    private static $instance = null;
    private $logPath;
    private $currentDate;
    private $logLevels = [
        'DEBUG' => 0,
        'INFO' => 1,
        'WARNING' => 2,
        'ERROR' => 3,
        'CRITICAL' => 4
    ];
    private $minLevel = 'DEBUG';
    
    private function __construct() {
        $this->logPath = LOG_CONFIG['path'];
        $this->currentDate = date('Y-m-d');
        
        if (!DEV_MODE) {
            $this->minLevel = 'INFO';
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Log a debug message
     */
    public function debug($message, array $context = []) {
        $this->log('DEBUG', $message, $context);
    }
    
    /**
     * Log an info message
     */
    public function info($message, array $context = []) {
        $this->log('INFO', $message, $context);
    }
    
    /**
     * Log a warning message
     */
    public function warning($message, array $context = []) {
        $this->log('WARNING', $message, $context);
    }
    
    /**
     * Log an error message
     */
    public function error($message, array $context = []) {
        $this->log('ERROR', $message, $context);
    }
    
    /**
     * Log a critical message
     */
    public function critical($message, array $context = []) {
        $this->log('CRITICAL', $message, $context);
    }
    
    /**
     * Log an exception
     */
    public function logException($exception, array $context = []) {
        $context['exception'] = [
            'class' => get_class($exception),
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString()
        ];
        
        $this->error($exception->getMessage(), $context);
    }
    
    /**
     * Log API request/response
     */
    public function logApiCall($service, $endpoint, $params, $response, $duration) {
        $this->info("API Call to $service", [
            'endpoint' => $endpoint,
            'params' => $params,
            'response_code' => $response['code'] ?? null,
            'duration_ms' => round($duration * 1000, 2),
            'response_size' => strlen(json_encode($response))
        ]);
        
        if (isset($response['error'])) {
            $this->error("API Error from $service", [
                'endpoint' => $endpoint,
                'error' => $response['error']
            ]);
        }
    }
    
    /**
     * Core logging method
     */
    private function log($level, $message, array $context = []) {
        // Check if we should log this level
        if ($this->logLevels[$level] < $this->logLevels[$this->minLevel]) {
            return;
        }
        
        // Rotate log if date changed
        if ($this->currentDate !== date('Y-m-d')) {
            $this->currentDate = date('Y-m-d');
            $this->rotateLogFiles();
        }
        
        // Build log entry
        $timestamp = date('Y-m-d H:i:s');
        $contextJson = !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        
        $logEntry = sprintf(
            "[%s] %s: %s %s\n",
            $timestamp,
            $level,
            $message,
            $contextJson
        );
        
        // Write to file
        $filename = $this->logPath . $this->currentDate . '.log';
        file_put_contents($filename, $logEntry, FILE_APPEND | LOCK_EX);
        
        // Also write to specific error log for ERROR and CRITICAL
        if (in_array($level, ['ERROR', 'CRITICAL'])) {
            $errorFile = $this->logPath . 'errors_' . $this->currentDate . '.log';
            file_put_contents($errorFile, $logEntry, FILE_APPEND | LOCK_EX);
        }
    }
    
    /**
     * Rotate old log files
     */
    private function rotateLogFiles() {
        $files = glob($this->logPath . '*.log');
        $maxFiles = LOG_CONFIG['max_files'];
        
        if (count($files) > $maxFiles) {
            // Sort by modification time
            usort($files, function($a, $b) {
                return filemtime($a) - filemtime($b);
            });
            
            // Delete oldest files
            $filesToDelete = array_slice($files, 0, count($files) - $maxFiles);
            foreach ($filesToDelete as $file) {
                unlink($file);
            }
        }
    }
    
    /**
     * Get recent log entries
     */
    public function getRecentLogs($level = null, $limit = 100) {
        $filename = $this->logPath . $this->currentDate . '.log';
        
        if (!file_exists($filename)) {
            return [];
        }
        
        $lines = file($filename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $lines = array_reverse($lines);
        
        $logs = [];
        foreach ($lines as $line) {
            if (count($logs) >= $limit) {
                break;
            }
            
            // Parse log line
            if (preg_match('/\[(.*?)\] (\w+): (.*)/', $line, $matches)) {
                $logEntry = [
                    'timestamp' => $matches[1],
                    'level' => $matches[2],
                    'message' => $matches[3]
                ];
                
                // Filter by level if specified
                if ($level === null || $logEntry['level'] === $level) {
                    $logs[] = $logEntry;
                }
            }
        }
        
        return $logs;
    }
    
    /**
     * Clear all logs
     */
    public function clearLogs() {
        $files = glob($this->logPath . '*.log');
        foreach ($files as $file) {
            unlink($file);
        }
        
        $this->info('Logs cleared');
    }
}

// Helper functions for quick logging
function logDebug($message, $context = []) {
    Logger::getInstance()->debug($message, $context);
}

function logInfo($message, $context = []) {
    Logger::getInstance()->info($message, $context);
}

function logWarning($message, $context = []) {
    Logger::getInstance()->warning($message, $context);
}

function logError($message, $context = []) {
    Logger::getInstance()->error($message, $context);
}

function logCritical($message, $context = []) {
    Logger::getInstance()->critical($message, $context);
}

function logException($exception, $context = []) {
    Logger::getInstance()->logException($exception, $context);
}
?>
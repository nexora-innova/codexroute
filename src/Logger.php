<?php

namespace GlpiPlugin\Codexroute;

use Session;

class Logger {
    
    private static $log_file = null;
    
    private static function getLogFile(): string {
        if (self::$log_file === null) {
            $log_dir = GLPI_LOG_DIR ?? (GLPI_ROOT . '/files/_log');
            self::$log_file = $log_dir . '/codexroute.log';
        }
        return self::$log_file;
    }
    
    public static function log(string $message, string $level = 'info', string $category = 'general'): void {
        $timestamp = date('Y-m-d H:i:s');
        $log_entry = "[$timestamp] [$level] [$category] $message\n";
        @file_put_contents(self::getLogFile(), $log_entry, FILE_APPEND);
        
        global $DB;
        if (isset($DB) && $DB->tableExists('glpi_plugin_codexroute_logs')) {
            try {
                $DB->insert('glpi_plugin_codexroute_logs', [
                    'level'      => $level,
                    'category'   => $category,
                    'message'    => $message,
                    'user_id'    => Session::getLoginUserID() ?? null,
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                    'uri'        => $_SERVER['REQUEST_URI'] ?? null
                ]);
            } catch (\Exception $e) {
            }
        }
    }
    
    public static function info(string $message, string $category = 'general'): void {
        self::log($message, 'info', $category);
    }
    
    public static function warning(string $message, string $category = 'general'): void {
        self::log($message, 'warning', $category);
    }
    
    public static function error(string $message, string $category = 'general'): void {
        self::log($message, 'error', $category);
    }
    
    public static function success(string $message, string $category = 'general'): void {
        self::log($message, 'success', $category);
    }
    
    public static function security(string $message, array $data = []): void {
        $message_with_data = $message;
        if (!empty($data)) {
            $message_with_data .= ' | Data: ' . json_encode($data);
        }
        self::log($message_with_data, 'warning', 'security');
    }
    
    public static function getLogs(string $type = 'all', int $limit = 100): array {
        $logs = [];
        $log_file = self::getLogFile();
        
        if (file_exists($log_file)) {
            $lines = file($log_file);
            $lines = array_slice($lines, -$limit);
            
            foreach ($lines as $line) {
                if (preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] \[(\w+)\] \[(\w+)\] (.+)/', $line, $matches)) {
                    $category = $matches[3];
                    
                    if ($type === 'all' || $type === $category) {
                        $logs[] = [
                            'time'     => $matches[1],
                            'level'    => $matches[2],
                            'category' => $category,
                            'message'  => trim($matches[4])
                        ];
                    }
                }
            }
        }
        
        return array_reverse($logs);
    }
    
    public static function getLogsFromDatabase(string $category = null, int $limit = 100): array {
        global $DB;
        
        $logs = [];
        
        if (!isset($DB) || !$DB->tableExists('glpi_plugin_codexroute_logs')) {
            return $logs;
        }
        
        try {
            $where = [];
            if ($category !== null) {
                $where['category'] = $category;
            }
            
            $result = $DB->request([
                'FROM'   => 'glpi_plugin_codexroute_logs',
                'WHERE'  => $where,
                'ORDER'  => 'date_creation DESC',
                'LIMIT'  => $limit
            ]);
            
            foreach ($result as $row) {
                $logs[] = [
                    'time'       => $row['date_creation'],
                    'level'      => $row['level'],
                    'category'   => $row['category'],
                    'message'    => $row['message'],
                    'user_id'    => $row['user_id'],
                    'ip_address' => $row['ip_address'],
                    'uri'        => $row['uri']
                ];
            }
        } catch (\Exception $e) {
        }
        
        return $logs;
    }
    
    public static function clearLogs(): bool {
        $log_file = self::getLogFile();
        
        if (file_exists($log_file)) {
            return @file_put_contents($log_file, '') !== false;
        }
        
        return true;
    }
}


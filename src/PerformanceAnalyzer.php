<?php

namespace GlpiPlugin\Codexroute;

class PerformanceAnalyzer {
    
    public static function analyzeApache(): array {
        $results = [
            'php'      => [],
            'database' => [],
            'warnings' => []
        ];
        
        $results['php'] = [
            'version'            => PHP_VERSION,
            'memory_limit'       => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'opcache_enabled'    => ini_get('opcache.enable'),
            'opcache_memory'     => ini_get('opcache.memory_consumption')
        ];
        
        $start = microtime(true);
        for ($i = 0; $i < 1000000; $i++) {
            $x = $i * 2;
        }
        $php_time = microtime(true) - $start;
        $results['php']['performance'] = $php_time;
        
        global $DB;
        try {
            $DB->connect();
            $db_start = microtime(true);
            
            try {
                $raw_result = $DB->doQuery("SELECT 1 as test");
                if ($raw_result) {
                    $DB->fetchAssoc($raw_result);
                }
            } catch (\Exception $e) {
                $result = $DB->request([
                    'SELECT' => ['id'],
                    'FROM'   => 'glpi_configs',
                    'LIMIT'  => 1
                ]);
            }
            
            $db_time = microtime(true) - $db_start;
            
            $results['database'] = [
                'connect_time'       => $db_time,
                'simple_query_time'  => $db_time
            ];
            
            if ($db_time > 0.1) {
                $results['warnings'][] = [
                    'component' => 'Base de Datos',
                    'issue'     => 'Latencia alta: ' . number_format($db_time * 1000, 2) . ' ms'
                ];
            }
        } catch (\Exception $e) {
            $results['warnings'][] = [
                'component' => 'Base de Datos',
                'issue'     => 'Error de conexión: ' . $e->getMessage()
            ];
        }
        
        $memory_mb = self::convertToBytes(ini_get('memory_limit')) / 1024 / 1024;
        if ($memory_mb < 256) {
            $results['warnings'][] = [
                'component' => 'PHP',
                'issue'     => "Memory limit bajo: {$memory_mb}MB (recomendado: 256MB+)"
            ];
        }
        
        if (!ini_get('opcache.enable')) {
            $results['warnings'][] = [
                'component' => 'PHP',
                'issue'     => 'OPcache deshabilitado. Se recomienda habilitarlo para mejorar rendimiento.'
            ];
        }
        
        return $results;
    }
    
    public static function analyzeMySQLConfig(): array {
        global $DB;
        
        $config = [];
        $recommendations = [];
        
        try {
            $DB->connect();
            
            $variables = [
                'innodb_buffer_pool_size',
                'innodb_log_file_size',
                'innodb_flush_log_at_trx_commit',
                'max_connections',
                'query_cache_type',
                'query_cache_size',
                'tmp_table_size',
                'max_heap_table_size',
                'join_buffer_size',
                'sort_buffer_size',
                'read_buffer_size',
                'read_rnd_buffer_size',
                'thread_cache_size',
                'table_open_cache'
            ];
            
            foreach ($variables as $var) {
                $result = $DB->doQuery("SHOW VARIABLES LIKE '$var'");
                if ($result) {
                    $row = $DB->fetchAssoc($result);
                    if ($row) {
                        $config[$var] = $row['Value'];
                    }
                }
            }
            
            if (isset($config['innodb_buffer_pool_size'])) {
                $buffer_pool_mb = $config['innodb_buffer_pool_size'] / 1024 / 1024;
                if ($buffer_pool_mb < 128) {
                    $recommendations[] = [
                        'variable'    => 'innodb_buffer_pool_size',
                        'current'     => $buffer_pool_mb . 'MB',
                        'recommended' => '128MB+',
                        'status'      => 'warning'
                    ];
                }
            }
            
            if (isset($config['innodb_flush_log_at_trx_commit']) && 
                $config['innodb_flush_log_at_trx_commit'] != 1) {
                $recommendations[] = [
                    'variable'    => 'innodb_flush_log_at_trx_commit',
                    'current'     => $config['innodb_flush_log_at_trx_commit'],
                    'recommended' => '1 (para durabilidad)',
                    'status'      => 'info'
                ];
            }
            
        } catch (\Exception $e) {
            $recommendations[] = [
                'variable' => 'error',
                'current'  => $e->getMessage(),
                'status'   => 'danger'
            ];
        }
        
        return [
            'config'          => $config,
            'recommendations' => $recommendations
        ];
    }
    
    public static function getPhpInfo(): array {
        return [
            'version'              => PHP_VERSION,
            'memory_limit'         => ini_get('memory_limit'),
            'max_execution_time'   => ini_get('max_execution_time'),
            'upload_max_filesize'  => ini_get('upload_max_filesize'),
            'post_max_size'        => ini_get('post_max_size'),
            'opcache_enabled'      => ini_get('opcache.enable'),
            'opcache_memory'       => ini_get('opcache.memory_consumption'),
            'extensions'           => get_loaded_extensions(),
            'sodium_available'     => extension_loaded('sodium'),
            'openssl_available'    => extension_loaded('openssl'),
            'mbstring_available'   => extension_loaded('mbstring'),
            'curl_available'       => extension_loaded('curl')
        ];
    }
    
    private static function convertToBytes(string $value): int {
        $value = trim($value);
        $last = strtolower($value[strlen($value) - 1]);
        $value = (int)$value;
        
        switch ($last) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }
        
        return $value;
    }
}


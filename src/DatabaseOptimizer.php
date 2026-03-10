<?php

namespace GlpiPlugin\Codexroute;

class DatabaseOptimizer {
    
    private static $transactional_tables = [
        'glpi_tickets', 'glpi_ticketfollowups', 'glpi_tickettasks', 'glpi_ticketvalidations',
        'glpi_logs', 'glpi_changes', 'glpi_problems', 'glpi_itilfollowups', 'glpi_itilsolutions',
        'glpi_documents', 'glpi_documents_items', 'glpi_computers', 'glpi_networkequipments',
        'glpi_printers', 'glpi_phones', 'glpi_monitors', 'glpi_peripherals', 'glpi_software',
        'glpi_contracts', 'glpi_suppliers', 'glpi_users', 'glpi_groups', 'glpi_projects',
        'glpi_projecttasks', 'glpi_knowbaseitems', 'glpi_knowbaseitems_revisions'
    ];
    
    private static $optimizable_tables = [
        'Computer' => [
            'table'       => 'glpi_computers',
            'file'        => 'front/computer.php',
            'description' => 'Computadoras',
            'icon'        => 'desktop'
        ],
        'Ticket' => [
            'table'       => 'glpi_tickets',
            'file'        => 'front/ticket.php',
            'description' => 'Tickets',
            'icon'        => 'ticket-alt'
        ],
        'Monitor' => [
            'table'       => 'glpi_monitors',
            'file'        => 'front/monitor.php',
            'description' => 'Monitores',
            'icon'        => 'tv'
        ],
        'User' => [
            'table'       => 'glpi_users',
            'file'        => 'front/user.php',
            'description' => 'Usuarios',
            'icon'        => 'user'
        ],
        'Printer' => [
            'table'       => 'glpi_printers',
            'file'        => 'front/printer.php',
            'description' => 'Impresoras',
            'icon'        => 'print'
        ],
        'NetworkEquipment' => [
            'table'       => 'glpi_networkequipments',
            'file'        => 'front/networkequipment.php',
            'description' => 'Equipos de Red',
            'icon'        => 'network-wired'
        ]
    ];
    
    public static function analyzeDatabase(): array {
        global $DB;
        
        $DB->connect();
        
        $slow_queries = [];
        $table_stats = [];
        $threshold = 0.1;
        
        foreach (self::$transactional_tables as $table) {
            try {
                if (!$DB->tableExists($table)) {
                    continue;
                }
                
                $start = microtime(true);
                $result = $DB->request([
                    'SELECT' => ['COUNT(*) as total'],
                    'FROM'   => $table,
                    'LIMIT'  => 1
                ]);
                $query_time = microtime(true) - $start;
                
                $table_info = $DB->request([
                    'SELECT' => [
                        'table_rows',
                        'ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb',
                        'ROUND((data_length / 1024 / 1024), 2) AS data_mb',
                        'ROUND((index_length / 1024 / 1024), 2) AS index_mb',
                        'ROUND((data_free / (data_length + index_length) * 100), 2) AS frag_percent'
                    ],
                    'FROM'  => 'information_schema.tables',
                    'WHERE' => [
                        'table_schema' => $DB->dbdefault,
                        'table_name'   => $table
                    ]
                ])->current();
                
                $table_stats[] = [
                    'table'        => $table,
                    'rows'         => $table_info['table_rows'] ?? 0,
                    'size_mb'      => $table_info['size_mb'] ?? 0,
                    'frag_percent' => $table_info['frag_percent'] ?? 0,
                    'query_time'   => $query_time
                ];
                
                if ($query_time > $threshold) {
                    $slow_queries[] = [
                        'table' => $table,
                        'query' => "SELECT COUNT(*) FROM $table",
                        'time'  => $query_time,
                        'rows'  => $table_info['table_rows'] ?? 0,
                        'type'  => 'count'
                    ];
                }
            } catch (\Exception $e) {
                error_log("[CodexRoute] Error analyzing table $table: " . $e->getMessage());
            }
        }
        
        return [
            'slow_queries'   => $slow_queries,
            'table_stats'    => $table_stats,
            'total_analyzed' => count($table_stats)
        ];
    }
    
    public static function profileSQL(): array {
        global $DB;
        
        $DB->connect();
        
        $results = [];
        
        foreach (self::$transactional_tables as $table) {
            try {
                if (!$DB->tableExists($table)) {
                    continue;
                }
                
                $start = microtime(true);
                $result = $DB->request([
                    'SELECT' => ['id'],
                    'FROM'   => $table,
                    'LIMIT'  => 100
                ]);
                $query_time = microtime(true) - $start;
                
                $count = 0;
                foreach ($result as $row) {
                    $count++;
                }
                
                if ($query_time > 0.5) {
                    $results[] = [
                        'table' => $table,
                        'time'  => $query_time,
                        'rows'  => $count,
                        'type'  => 'profile'
                    ];
                }
            } catch (\Exception $e) {
                error_log("[CodexRoute] Error profiling $table: " . $e->getMessage());
            }
        }
        
        return [
            'slow_queries'   => $results,
            'total_analyzed' => count(self::$transactional_tables)
        ];
    }
    
    public static function optimizeTableIndexes(string $table_name, string $table_db): array {
        global $DB;
        
        $DB->connect();
        
        $results = [
            'created' => [],
            'skipped' => [],
            'errors'  => []
        ];
        
        $indexes_to_create = [];
        
        switch ($table_db) {
            case 'glpi_computers':
                $indexes_to_create = [
                    'idx_entities_id'      => 'entities_id',
                    'idx_is_deleted'       => 'is_deleted',
                    'idx_is_template'      => 'is_template',
                    'idx_date_mod'         => 'date_mod',
                    'idx_locations_id'     => 'locations_id',
                    'idx_states_id'        => 'states_id',
                    'idx_manufacturers_id' => 'manufacturers_id'
                ];
                break;
                
            case 'glpi_tickets':
                $indexes_to_create = [
                    'idx_entities_id'  => 'entities_id',
                    'idx_is_deleted'   => 'is_deleted',
                    'idx_status'       => 'status',
                    'idx_date_mod'     => 'date_mod',
                    'idx_date'         => 'date',
                    'idx_priority'     => 'priority',
                    'idx_type'         => 'type'
                ];
                break;
                
            case 'glpi_users':
                $indexes_to_create = [
                    'idx_is_deleted' => 'is_deleted',
                    'idx_is_active'  => 'is_active',
                    'idx_date_mod'   => 'date_mod'
                ];
                break;
                
            default:
                $indexes_to_create = [
                    'idx_date_mod' => 'date_mod'
                ];
        }
        
        $existing_indexes = [];
        try {
            $idx_result = $DB->doQuery("SHOW INDEX FROM $table_db");
            while ($row = $DB->fetchAssoc($idx_result)) {
                $existing_indexes[] = $row['Key_name'];
            }
        } catch (\Exception $e) {
            $results['errors'][] = "Error getting indexes: " . $e->getMessage();
            return $results;
        }
        
        foreach ($indexes_to_create as $idx_name => $column) {
            if (in_array($idx_name, $existing_indexes)) {
                $results['skipped'][] = $idx_name;
                continue;
            }
            
            try {
                $sql = "ALTER TABLE `$table_db` ADD INDEX `$idx_name` (`$column`)";
                $DB->doQuery($sql);
                $results['created'][] = $idx_name;
            } catch (\Exception $e) {
                $results['errors'][] = "$idx_name: " . $e->getMessage();
            }
        }
        
        return $results;
    }
    
    public static function analyzeViews(): array {
        global $DB;
        
        $DB->connect();
        
        $views = [
            'Tickets' => [
                'file'  => 'front/ticket.php',
                'query' => "SELECT DISTINCT `glpi_tickets`.`id` AS id, `glpi_tickets`.`name`, 
                    `glpi_tickets`.`status`, `glpi_tickets`.`priority`, `glpi_tickets`.`date_mod`
                    FROM `glpi_tickets`
                    WHERE `glpi_tickets`.`is_deleted` = 0
                    ORDER BY `glpi_tickets`.`date_mod` DESC
                    LIMIT 50"
            ],
            'Computadoras' => [
                'file'  => 'front/computer.php',
                'query' => "SELECT DISTINCT `glpi_computers`.`id` AS id, `glpi_computers`.`name`
                    FROM `glpi_computers`
                    WHERE `glpi_computers`.`is_deleted` = 0
                    ORDER BY `glpi_computers`.`date_mod` DESC
                    LIMIT 50"
            ],
            'Usuarios' => [
                'file'  => 'front/user.php',
                'query' => "SELECT DISTINCT `glpi_users`.`id` AS id, `glpi_users`.`name`, 
                    `glpi_users`.`realname`, `glpi_users`.`firstname`
                    FROM `glpi_users`
                    WHERE `glpi_users`.`is_deleted` = 0 AND `glpi_users`.`is_active` = 1
                    ORDER BY `glpi_users`.`name` ASC
                    LIMIT 50"
            ]
        ];
        
        $results = [];
        
        foreach ($views as $name => $data) {
            try {
                $start = microtime(true);
                $result = $DB->query($data['query']);
                $query_time = microtime(true) - $start;
                
                $rows = $DB->numrows($result);
                
                $results[] = [
                    'name'       => $name,
                    'total_time' => $query_time,
                    'rows'       => $rows,
                    'file'       => $data['file']
                ];
            } catch (\Exception $e) {
                $results[] = [
                    'name'  => $name,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return ['views' => $results];
    }
    
    public static function getOptimizableTables(): array {
        return self::$optimizable_tables;
    }
    
    public static function getDatabaseStatus(): array {
        global $DB;
        
        try {
            $DB->connect();
            
            $start = microtime(true);
            $DB->doQuery("SELECT 1 as test");
            $query_time = microtime(true) - $start;
            
            return [
                'connected'         => true,
                'host'              => $DB->dbhost,
                'database'          => $DB->dbdefault,
                'simple_query_time' => $query_time,
                'status'            => $query_time > 0.1 ? 'warning' : 'ok'
            ];
        } catch (\Exception $e) {
            return [
                'connected' => false,
                'error'     => $e->getMessage(),
                'status'    => 'danger'
            ];
        }
    }
}


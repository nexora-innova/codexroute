<?php

namespace GlpiPlugin\Codexroute;

use CommonDBTM;
use Session;
use Html;

class Config extends CommonDBTM {
    
    public static $rightname = 'config';
    
    public static function getTypeName($nb = 0) {
        return __('CodexRoute Configuration', 'codexroute');
    }
    
    public static function getMenuName() {
        return __('CodexRoute', 'codexroute');
    }
    
    public static function getMenuContent() {
        // Verificar que el plugin esté instalado y activado antes de mostrar el menú
        $plugin = new \Plugin();
        if (!$plugin->isInstalled('codexroute') || !$plugin->isActivated('codexroute')) {
            // Si el plugin no está instalado o activado, no devolver menú
            return false;
        }
        
        $menu = [
            'title' => self::getMenuName(),
            'page'  => '/plugins/codexroute/front/config.form.php',
            'icon'  => 'ti ti-shield-lock',
        ];
        
        if (Session::haveRight('config', UPDATE)) {
            $menu['options']['config'] = [
                'title' => __('Configuration', 'codexroute'),
                'page'  => '/plugins/codexroute/front/config.form.php',
                'icon'  => 'ti ti-settings',
            ];
        }
        
        return $menu;
    }
    
    public static function canView() {
        return Session::haveRight('config', READ);
    }
    
    public static function canCreate() {
        return Session::haveRight('config', UPDATE);
    }
    
    public static function getValue($name, $default = null) {
        global $DB;
        
        $result = $DB->request([
            'SELECT' => ['value'],
            'FROM'   => 'glpi_plugin_codexroute_configs',
            'WHERE'  => ['name' => $name],
            'LIMIT'  => 1
        ]);
        
        if ($row = $result->current()) {
            return $row['value'];
        }
        
        return $default;
    }
    
    public static function setValue($name, $value) {
        global $DB;
        
        $existing = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_plugin_codexroute_configs',
            'WHERE'  => ['name' => $name],
            'LIMIT'  => 1
        ]);
        
        if ($row = $existing->current()) {
            return $DB->update('glpi_plugin_codexroute_configs', [
                'value' => $value,
                'date_mod' => date('Y-m-d H:i:s')
            ], ['id' => $row['id']]);
        } else {
            return $DB->insert('glpi_plugin_codexroute_configs', [
                'name'  => $name,
                'value' => $value
            ]);
        }
    }
    
    public static function getEncryptionConfig() {
        return [
            'strict_mode'          => self::getValue('strict_mode', false),
            'log_numeric_ids'      => self::getValue('log_numeric_ids', false),
            'cache_size'           => self::getValue('cache_size', 100),
            'cache_ttl'            => self::getValue('cache_ttl', 300),
            'simple_max_range'     => self::getValue('simple_max_range', 1000),
            'simple_timeout'       => self::getValue('simple_timeout', 1.0),
            'warn_simple'          => self::getValue('warn_simple', true),
            'block_after_attempts' => self::getValue('block_after_attempts', 20),
            'block_duration'       => self::getValue('block_duration', 3600),
            'min_length'           => self::getValue('min_length', 16),
            'max_length'           => self::getValue('max_length', 200),
            'log_security'         => self::getValue('log_security', true),
            'normalized_time'      => self::getValue('normalized_time', 0.05),
        ];
    }
    
    public static function saveEncryptionConfig(array $config) {
        foreach ($config as $key => $value) {
            self::setValue($key, is_bool($value) ? ($value ? '1' : '0') : $value);
        }
        return true;
    }
    
    public static function generateConfigFile(array $config) {
        $config_dir = GLPI_CONFIG_DIR ?? (GLPI_ROOT . '/config');
        $plugin_config_dir = $config_dir . '/codexroute';
        
        if (!is_dir($plugin_config_dir)) {
            @mkdir($plugin_config_dir, 0755, true);
        }
        
        $file_path = $plugin_config_dir . '/encryption_config.php';
        
        $content = "<?php\n";
        $content .= "/**\n * Configuración de Encriptación de IDs - CodexRoute\n * Generado: " . date('Y-m-d H:i:s') . "\n */\n\n";
        
        $descriptions = [
            'strict_mode'          => 'Modo estricto: Rechaza IDs numéricos sin encriptar',
            'log_numeric_ids'      => 'Registrar cuando se recibe un ID sin encriptar',
            'cache_size'           => 'Tamaño máximo del caché',
            'cache_ttl'            => 'TTL del caché en segundos',
            'simple_max_range'     => 'Rango máximo para método simple',
            'simple_timeout'       => 'Timeout para método simple en segundos',
            'warn_simple'          => 'Advertir cuando se usa método simple',
            'block_after_attempts' => 'Bloquear IP después de X intentos fallidos',
            'block_duration'       => 'Duración del bloqueo de IP en segundos',
            'min_length'           => 'Longitud mínima de ID encriptado',
            'max_length'           => 'Longitud máxima de ID encriptado',
            'log_security'         => 'Registrar eventos de seguridad',
            'normalized_time'      => 'Tiempo normalizado de respuesta en segundos',
        ];
        
        foreach ($config as $key => $value) {
            $constant_name = 'CODEXROUTE_' . strtoupper($key);
            $description = $descriptions[$key] ?? $key;
            
            $content .= "// $description\n";
            
            if (is_bool($value) || $value === '0' || $value === '1') {
                $bool_val = ($value === true || $value === '1') ? 'true' : 'false';
                $content .= "define('$constant_name', $bool_val);\n\n";
            } elseif (is_numeric($value)) {
                $content .= "define('$constant_name', $value);\n\n";
            } else {
                $escaped_value = addslashes($value);
                $content .= "define('$constant_name', '$escaped_value');\n\n";
            }
        }
        
        return file_put_contents($file_path, $content) !== false;
    }
    
    public static function configFileExists() {
        $config_dir = GLPI_CONFIG_DIR ?? (GLPI_ROOT . '/config');
        $file_path = $config_dir . '/codexroute/encryption_config.php';
        return file_exists($file_path);
    }
    
    public static function getAllowedRoutes() {
        $config_dir = GLPI_CONFIG_DIR ?? (GLPI_ROOT . '/config');
        $routes_file = $config_dir . '/codexroute/allowed_routes.php';
        
        if (file_exists($routes_file)) {
            $routes = include $routes_file;
            return is_array($routes) ? $routes : [];
        }
        
        return [];
    }
    
    public static function saveAllowedRoutes(array $routes) {
        $config_dir = GLPI_CONFIG_DIR ?? (GLPI_ROOT . '/config');
        $plugin_config_dir = $config_dir . '/codexroute';
        
        if (!is_dir($plugin_config_dir)) {
            @mkdir($plugin_config_dir, 0755, true);
        }
        
        $routes_file = $plugin_config_dir . '/allowed_routes.php';
        $content = "<?php\nreturn " . var_export($routes, true) . ";\n";
        
        return file_put_contents($routes_file, $content) !== false;
    }
    
    public static function addAllowedRoute($route) {
        $routes = self::getAllowedRoutes();
        if (!in_array($route, $routes)) {
            $routes[] = $route;
            return self::saveAllowedRoutes($routes);
        }
        return true;
    }
    
    public static function removeAllowedRoute($route) {
        $routes = self::getAllowedRoutes();
        $key = array_search($route, $routes);
        if ($key !== false) {
            unset($routes[$key]);
            $routes = array_values($routes);
            return self::saveAllowedRoutes($routes);
        }
        return true;
    }
    
    /**
     * Obtiene la definición de todos los tabs disponibles
     * @return array Array con información de cada tab
     */
    public static function getTabsDefinition() {
        return [
            'config' => [
                'key' => 'config',
                'title' => __('Configuración Encriptación', 'codexroute'),
                'icon' => 'ti ti-lock',
                'description' => __('Configurar parámetros de seguridad y encriptación', 'codexroute'),
                'step' => 1,
                'order' => 1,
            ],
            'routes' => [
                'key' => 'routes',
                'title' => __('Permisos de Rutas', 'codexroute'),
                'icon' => 'ti ti-route',
                'description' => __('Gestionar rutas problemáticas y permisos especiales', 'codexroute'),
                'step' => 2,
                'order' => 2,
            ],
            'apache' => [
                'key' => 'apache',
                'title' => __('Rendimiento Apache/PHP', 'codexroute'),
                'icon' => 'ti ti-server',
                'description' => __('Analizar configuración y rendimiento de Apache/PHP', 'codexroute'),
                'step' => 3,
                'order' => 3,
            ],
            'database' => [
                'key' => 'database',
                'title' => __('Rendimiento Base de Datos', 'codexroute'),
                'icon' => 'ti ti-database',
                'description' => __('Analizar y optimizar rendimiento de la base de datos', 'codexroute'),
                'step' => 4,
                'order' => 4,
            ],
        ];
    }
    
    /**
     * Obtiene el orden personalizado de tabs desde la configuración
     * @return array Array con el orden de los tabs
     */
    public static function getTabsOrder() {
        $order_json = self::getValue('tabs_order', null);
        
        if ($order_json !== null) {
            $order = json_decode($order_json, true);
            if (is_array($order) && count($order) === 5) {
                return $order;
            }
        }
        
        // Orden por defecto (lógico paso a paso)
        return ['config', 'routes', 'apache', 'database'];
    }
    
    /**
     * Guarda el orden personalizado de tabs
     * @param array $order Array con el orden de los tabs
     * @return bool
     */
    public static function saveTabsOrder(array $order) {
        // Validar que todos los tabs estén presentes
        $all_tabs = array_keys(self::getTabsDefinition());
        // Filtrar 'validation' si existe en el orden guardado (para compatibilidad con versiones anteriores)
        $order = array_filter($order, function($tab) use ($all_tabs) {
            return in_array($tab, $all_tabs);
        });
        $order = array_values($order);
        
        if (count($order) !== count($all_tabs) || count(array_intersect($order, $all_tabs)) !== count($all_tabs)) {
            return false;
        }
        
        return self::setValue('tabs_order', json_encode($order));
    }
    
    /**
     * Obtiene los tabs ordenados con información completa
     * @return array Array de tabs ordenados
     */
    public static function getOrderedTabs() {
        $tabs_def = self::getTabsDefinition();
        $order = self::getTabsOrder();
        
        $ordered_tabs = [];
        $step = 1;
        
        foreach ($order as $tab_key) {
            if (isset($tabs_def[$tab_key])) {
                $tab = $tabs_def[$tab_key];
                $tab['order'] = $step;
                $tab['step'] = $step;
                $ordered_tabs[] = $tab;
                $step++;
            }
        }
        
        return $ordered_tabs;
    }
    
    /**
     * Obtiene el estado de completitud de cada tab (para indicadores de progreso)
     * @return array Array con el estado de cada tab
     */
    public static function getTabsCompletionStatus() {
        $encryption_config = self::getEncryptionConfig();
        $allowed_routes = self::getAllowedRoutes();
        
        return [
            'config' => [
                'completed' => !empty($encryption_config['strict_mode']) || 
                              !empty($encryption_config['cache_size']) ||
                              self::getValue('tabs_order') !== null, // Si hay orden configurado, asumimos que se configuró
                'required' => true,
            ],
            'validation' => [
                'completed' => false, // Se completa cuando se ejecuta el análisis
                'required' => false,
            ],
            'routes' => [
                'completed' => count($allowed_routes) > 0,
                'required' => false,
            ],
            'apache' => [
                'completed' => false, // Se completa cuando se ejecuta el análisis
                'required' => false,
            ],
            'database' => [
                'completed' => false, // Se completa cuando se ejecuta el análisis
                'required' => false,
            ],
        ];
    }
}


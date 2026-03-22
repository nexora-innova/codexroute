<?php

/**
 * CodexRoute - Hook de Instalación/Desinstalación
 *
 * @copyright 2025-2026
 * @license   GPLv3 https://www.gnu.org/licenses/gpl-3.0.html
 */

function plugin_codexroute_install() {
    global $DB;

    $config_dir = GLPI_CONFIG_DIR ?? (GLPI_ROOT . '/config');
    $plugin_config_dir = $config_dir . '/codexroute';
    
    if (!is_dir($plugin_config_dir)) {
        if (!@mkdir($plugin_config_dir, 0755, true)) {
            return false;
        }
    }

    $encryption_config_file = $plugin_config_dir . '/encryption_config.php';
    if (!file_exists($encryption_config_file)) {
        $default_config = <<<'PHP'
<?php
/**
 * Configuración de Encriptación de IDs - CodexRoute
 */

define('CODEXROUTE_STRICT_MODE', false);
define('CODEXROUTE_LOG_NUMERIC_IDS', false);
define('CODEXROUTE_CACHE_SIZE', 100);
define('CODEXROUTE_CACHE_TTL', 300);
define('CODEXROUTE_SIMPLE_MAX_RANGE', 1000);
define('CODEXROUTE_SIMPLE_TIMEOUT', 1.0);
define('CODEXROUTE_WARN_SIMPLE', true);
define('CODEXROUTE_BLOCK_AFTER_ATTEMPTS', 20);
define('CODEXROUTE_BLOCK_DURATION', 3600);
define('CODEXROUTE_MIN_LENGTH', 16);
define('CODEXROUTE_MAX_LENGTH', 200);
define('CODEXROUTE_LOG_SECURITY', true);
define('CODEXROUTE_NORMALIZED_TIME', 0.05);
PHP;
        if (@file_put_contents($encryption_config_file, $default_config) === false) {
            return false;
        }
    }

    $routes_config_file_json = $plugin_config_dir . '/allowed_routes.json';
    $routes_config_file_php  = $plugin_config_dir . '/allowed_routes.php';
    if (!file_exists($routes_config_file_json)) {
        $initial_routes = [];
        if (file_exists($routes_config_file_php)) {
            $old = @include $routes_config_file_php;
            if (is_array($old)) {
                $initial_routes = $old;
            }
            @unlink($routes_config_file_php);
        }
        if (@file_put_contents($routes_config_file_json, json_encode($initial_routes, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) {
            return false;
        }
    }

    $migration = new Migration(PLUGIN_CODEXROUTE_VERSION);
    
    $default_charset = DBConnection::getDefaultCharset();
    $default_collation = DBConnection::getDefaultCollation();
    $default_key_sign = DBConnection::getDefaultPrimaryKeySignOption();
    
    if (!$DB->tableExists('glpi_plugin_codexroute_configs')) {
        $migration->displayMessage(sprintf(__('Creating table %s'), 'glpi_plugin_codexroute_configs'));
        $query = "CREATE TABLE IF NOT EXISTS `glpi_plugin_codexroute_configs` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `name` varchar(255) NOT NULL,
            `value` text DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";
        $DB->query($query) or die($DB->error());
    }

    if (!$DB->tableExists('glpi_plugin_codexroute_logs')) {
        $migration->displayMessage(sprintf(__('Creating table %s'), 'glpi_plugin_codexroute_logs'));
        $query = "CREATE TABLE IF NOT EXISTS `glpi_plugin_codexroute_logs` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `date_creation` datetime DEFAULT NULL,
            `level` varchar(50) DEFAULT NULL,
            `category` varchar(100) DEFAULT NULL,
            `message` longtext DEFAULT NULL,
            `user_id` int {$default_key_sign} DEFAULT NULL,
            `ip_address` varchar(45) DEFAULT NULL,
            `uri` varchar(500) DEFAULT NULL,
            `extra_data` longtext DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `date_creation` (`date_creation`),
            KEY `level` (`level`),
            KEY `category` (`category`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";
        $DB->query($query) or die($DB->error());
    }

    $migration->executeMigration();

    return true;
}

function plugin_codexroute_uninstall() {
    global $DB, $PLUGIN_HOOKS;

    $migration = new Migration(PLUGIN_CODEXROUTE_VERSION);
    
    if ($DB->tableExists('glpi_plugin_codexroute_configs')) {
        $migration->displayMessage(sprintf(__('Dropping table %s'), 'glpi_plugin_codexroute_configs'));
        $migration->dropTable('glpi_plugin_codexroute_configs');
    }

    if ($DB->tableExists('glpi_plugin_codexroute_logs')) {
        $migration->displayMessage(sprintf(__('Dropping table %s'), 'glpi_plugin_codexroute_logs'));
        $migration->dropTable('glpi_plugin_codexroute_logs');
    }

    $migration->executeMigration();

    if ($DB->tableExists('glpi_plugin_migrations')) {
        $migration->displayMessage(sprintf(__('Cleaning migration records for %s'), 'codexroute'));
        $DB->delete('glpi_plugin_migrations', [
            'plugin' => 'codexroute'
        ]);
    }
    
    // Limpiar referencias del plugin en la tabla glpi_plugins si existe
    if ($DB->tableExists('glpi_plugins')) {
        $migration->displayMessage(sprintf(__('Cleaning plugin records for %s'), 'codexroute'));
        $DB->delete('glpi_plugins', [
            'directory' => 'codexroute'
        ]);
    }

    // Limpiar hooks del menú para evitar que aparezca en el menú después de desinstalar
    if (isset($PLUGIN_HOOKS['menu_toadd']['codexroute'])) {
        unset($PLUGIN_HOOKS['menu_toadd']['codexroute']);
    }
    
    if (isset($PLUGIN_HOOKS['config_page']['codexroute'])) {
        unset($PLUGIN_HOOKS['config_page']['codexroute']);
    }
    
    if (isset($PLUGIN_HOOKS['add_css']['codexroute'])) {
        unset($PLUGIN_HOOKS['add_css']['codexroute']);
    }
    
    if (isset($PLUGIN_HOOKS['add_javascript']['codexroute'])) {
        unset($PLUGIN_HOOKS['add_javascript']['codexroute']);
    }
    
    if (isset($PLUGIN_HOOKS['csrf_compliant']['codexroute'])) {
        unset($PLUGIN_HOOKS['csrf_compliant']['codexroute']);
    }

    $config_dir = GLPI_CONFIG_DIR ?? (GLPI_ROOT . '/config');
    $plugin_config_dir = $config_dir . '/codexroute';
    
    if (is_dir($plugin_config_dir)) {
        $files = [
            $plugin_config_dir . '/encryption_config.php',
            $plugin_config_dir . '/allowed_routes.php',
            $plugin_config_dir . '/allowed_routes.json',
            $plugin_config_dir . '/blocked_routes.json',
        ];
        
        foreach ($files as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
        
        if (is_dir($plugin_config_dir) && count(glob($plugin_config_dir . '/*')) === 0) {
            @rmdir($plugin_config_dir);
        }
    }

    // Limpiar caché de GLPI si está disponible
    if (class_exists('Toolbox')) {
        if (method_exists('Toolbox', 'clearCache')) {
            Toolbox::clearCache();
        }
        // Limpiar caché de clases si está disponible
        if (method_exists('Toolbox', 'clearClassCache')) {
            Toolbox::clearClassCache();
        }
    }
    
    // Limpiar caché de opcache si está disponible
    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }
    
    // Limpiar caché de archivos compilados de GLPI
    $cache_dir = GLPI_CACHE_DIR ?? (GLPI_ROOT . '/files/_cache');
    if (is_dir($cache_dir)) {
        $cache_files = glob($cache_dir . '/*');
        if ($cache_files) {
            foreach ($cache_files as $cache_file) {
                if (is_file($cache_file) && strpos($cache_file, 'codexroute') !== false) {
                    @unlink($cache_file);
                }
            }
        }
    }
    
    // Limpiar caché de twig si está disponible
    $twig_cache = GLPI_ROOT . '/files/_cache/twig';
    if (is_dir($twig_cache)) {
        $twig_files = glob($twig_cache . '/*');
        if ($twig_files) {
            foreach ($twig_files as $twig_file) {
                if (is_file($twig_file) && strpos($twig_file, 'codexroute') !== false) {
                    @unlink($twig_file);
                }
            }
        }
    }

    return true;
}

function plugin_codexroute_upgrade($version) {
    global $DB;

    $migration = new Migration(PLUGIN_CODEXROUTE_VERSION);
    
    $default_charset = DBConnection::getDefaultCharset();
    $default_collation = DBConnection::getDefaultCollation();
    $default_key_sign = DBConnection::getDefaultPrimaryKeySignOption();
    
    switch ($version) {
        case '1.0.0':
            $migration->displayMessage(sprintf(__('Upgrading plugin to version %s'), PLUGIN_CODEXROUTE_VERSION));
            
            if (!$DB->tableExists('glpi_plugin_codexroute_configs')) {
                $migration->displayMessage(sprintf(__('Creating table %s'), 'glpi_plugin_codexroute_configs'));
                $query = "CREATE TABLE IF NOT EXISTS `glpi_plugin_codexroute_configs` (
                    `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
                    `name` varchar(255) NOT NULL,
                    `value` text DEFAULT NULL,
                    `date_mod` timestamp NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `name` (`name`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";
                $DB->query($query) or die($DB->error());
            }

            if (!$DB->tableExists('glpi_plugin_codexroute_logs')) {
                $migration->displayMessage(sprintf(__('Creating table %s'), 'glpi_plugin_codexroute_logs'));
                $query = "CREATE TABLE IF NOT EXISTS `glpi_plugin_codexroute_logs` (
                    `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
                    `date_creation` datetime DEFAULT NULL,
                    `level` varchar(50) DEFAULT NULL,
                    `category` varchar(100) DEFAULT NULL,
                    `message` longtext DEFAULT NULL,
                    `user_id` int {$default_key_sign} DEFAULT NULL,
                    `ip_address` varchar(45) DEFAULT NULL,
                    `uri` varchar(500) DEFAULT NULL,
                    `extra_data` longtext DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `date_creation` (`date_creation`),
                    KEY `level` (`level`),
                    KEY `category` (`category`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";
                $DB->query($query) or die($DB->error());
            }
            
            $migration->executeMigration();
            break;
    }

    return true;
}

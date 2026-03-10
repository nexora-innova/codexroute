<?php

/**
 * CodexRoute - Bootstrap para Tests Unitarios
 * 
 * Configuración inicial para ejecutar tests unitarios del plugin
 */

define('GLPI_ROOT', dirname(__FILE__) . '/../../');
define('GLPI_CONFIG_DIR', GLPI_ROOT . '/config');

include_once(GLPI_ROOT . '/inc/includes.php');

$_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
$_SESSION['glpilanguage'] = 'en_GB';

if (!defined('GLPI_TEST_MODE')) {
    define('GLPI_TEST_MODE', true);
}


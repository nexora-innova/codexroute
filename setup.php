<?php

/**
 * CodexRoute - Plugin de Seguridad y Rendimiento para GLPI
 *
 * @copyright 2025-2026
 * @license   GPLv3 https://www.gnu.org/licenses/gpl-3.0.html
 */

use GlpiPlugin\Codexroute\Config;
use GlpiPlugin\Codexroute\GlobalValidator;
use GlpiPlugin\Codexroute\LinkEncryptor;

define('PLUGIN_CODEXROUTE_VERSION', '1.0.0');
define('PLUGIN_CODEXROUTE_MIN_GLPI_VERSION', '10.0.0');
define('PLUGIN_CODEXROUTE_MAX_GLPI_VERSION', '10.0.99');

function plugin_init_codexroute() {
    global $PLUGIN_HOOKS;

    // Declarar compatibilidad con CSRF SIEMPRE, incluso si el plugin no está instalado/activado
    // Esto es necesario para que GLPI permita instalar/activar el plugin
    $PLUGIN_HOOKS['csrf_compliant']['codexroute'] = true;

    $plugin = new Plugin();
    
    // Solo registrar hooks si el plugin está instalado Y activado
    if (!$plugin->isInstalled('codexroute') || !$plugin->isActivated('codexroute')) {
        // Asegurar que no haya hooks residuales si el plugin no está activo
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
        return;
    }
    
    Plugin::registerClass('GlpiPlugin\Codexroute\Config');
    Plugin::registerClass('GlpiPlugin\Codexroute\IDEncryption');
    Plugin::registerClass('GlpiPlugin\Codexroute\RouteValidator');
    Plugin::registerClass('GlpiPlugin\Codexroute\PerformanceAnalyzer');
    Plugin::registerClass('GlpiPlugin\Codexroute\DatabaseOptimizer');
    Plugin::registerClass('GlpiPlugin\Codexroute\GlobalValidator');
    Plugin::registerClass('GlpiPlugin\Codexroute\LinkEncryptor');
    
    // Envolver en try-catch para evitar que errores desactiven el plugin automáticamente
    try {
        GlobalValidator::validate();
    } catch (\Throwable $e) {
        // Registrar el error pero no lanzar excepción para evitar desactivación del plugin
        error_log(sprintf(
            '[CodexRoute] ERROR en GlobalValidator::validate(): %s (File: %s, Line: %s)',
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));
    }
    
    try {
        LinkEncryptor::initialize();
    } catch (\Throwable $e) {
        // Registrar el error pero no lanzar excepción para evitar desactivación del plugin
        error_log(sprintf(
            '[CodexRoute] ERROR en LinkEncryptor::initialize(): %s (File: %s, Line: %s)',
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));
    }

    if (Session::haveRight('config', UPDATE)) {
        $PLUGIN_HOOKS['menu_toadd']['codexroute'] = [
            'config' => 'GlpiPlugin\Codexroute\Menu'
        ];
        
        $PLUGIN_HOOKS['config_page']['codexroute'] = 'front/config.form.php';
    }

    $PLUGIN_HOOKS['add_css']['codexroute'] = 'css/codexroute.css';
    $PLUGIN_HOOKS['add_javascript']['codexroute'] = 'js/codexroute.js';
}

function plugin_version_codexroute() {
    return [
        'name'           => 'CodexRoute',
        'version'        => PLUGIN_CODEXROUTE_VERSION,
        'author'         => '<a href="#">Security Team</a>',
        'license'        => 'GPLv3',
        'homepage'       => '',
        'requirements'   => [
            'glpi' => [
                'min' => PLUGIN_CODEXROUTE_MIN_GLPI_VERSION,
                'max' => PLUGIN_CODEXROUTE_MAX_GLPI_VERSION,
            ],
            'php'  => [
                'min' => '7.4',
            ]
        ]
    ];
}

function plugin_codexroute_check_prerequisites() {
    if (version_compare(GLPI_VERSION, PLUGIN_CODEXROUTE_MIN_GLPI_VERSION, 'lt')) {
        echo "Este plugin requiere GLPI >= " . PLUGIN_CODEXROUTE_MIN_GLPI_VERSION;
        return false;
    }
    if (version_compare(GLPI_VERSION, PLUGIN_CODEXROUTE_MAX_GLPI_VERSION, 'ge')) {
        echo "Este plugin requiere GLPI < " . PLUGIN_CODEXROUTE_MAX_GLPI_VERSION;
        return false;
    }
    return true;
}

function plugin_codexroute_check_config($verbose = false) {
    return true;
}



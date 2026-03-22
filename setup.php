<?php

/**
 * CodexRoute - Plugin de Seguridad y Rendimiento para GLPI
 *
 * @copyright 2025-2026
 * @license   GPLv3 https://www.gnu.org/licenses/gpl-3.0.html
 */

use Glpi\Plugin\Hooks;
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
    $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['codexroute'] = true;

    $plugin = new Plugin();
    
    // Solo registrar hooks si el plugin está instalado Y activado
    if (!$plugin->isInstalled('codexroute') || !$plugin->isActivated('codexroute')) {
        // Asegurar que no haya hooks residuales si el plugin no está activo
        if (isset($PLUGIN_HOOKS[Hooks::MENU_TOADD]['codexroute'])) {
            unset($PLUGIN_HOOKS[Hooks::MENU_TOADD]['codexroute']);
        }
        if (isset($PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['codexroute'])) {
            unset($PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['codexroute']);
        }
        if (isset($PLUGIN_HOOKS[Hooks::ADD_CSS]['codexroute'])) {
            unset($PLUGIN_HOOKS[Hooks::ADD_CSS]['codexroute']);
        }
        if (isset($PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['codexroute'])) {
            unset($PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['codexroute']);
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
        $PLUGIN_HOOKS[Hooks::MENU_TOADD]['codexroute'] = [
            'config' => 'GlpiPlugin\Codexroute\Menu'
        ];
        
        $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['codexroute'] = 'front/config.form.php';
    }

    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    if (str_contains($script, '/plugins/codexroute/front/config.form.php')) {
        $PLUGIN_HOOKS[Hooks::ADD_CSS]['codexroute'] = 'css/codexroute.css';
    }

    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['codexroute'] = 'js/codexroute.js';
}

function plugin_version_codexroute() {
    return [
        'name'           => 'CodexRoute',
        'version'        => PLUGIN_CODEXROUTE_VERSION,
        'author'         => '<a href="https://github.com/nexora-innova" target="_blank" rel="noopener noreferrer">NexoraInnova</a>',
        'license'        => 'GPLv3',
        'homepage'       => 'https://github.com/nexora-innova/codexroute',
        'requirements'   => [
            'glpi' => [
                'min' => PLUGIN_CODEXROUTE_MIN_GLPI_VERSION,
                'max' => PLUGIN_CODEXROUTE_MAX_GLPI_VERSION,
            ],
            'php'  => [
                'min' => '8.1',
            ]
        ]
    ];
}

function plugin_codexroute_check_prerequisites() {
    if (version_compare(GLPI_VERSION, PLUGIN_CODEXROUTE_MIN_GLPI_VERSION, 'lt')) {
        if (class_exists('Toolbox') && method_exists('Toolbox', 'logError')) {
            Toolbox::logError('CodexRoute requires GLPI >= ' . PLUGIN_CODEXROUTE_MIN_GLPI_VERSION);
        }
        return false;
    }
    if (version_compare(GLPI_VERSION, PLUGIN_CODEXROUTE_MAX_GLPI_VERSION, 'ge')) {
        if (class_exists('Toolbox') && method_exists('Toolbox', 'logError')) {
            Toolbox::logError('CodexRoute requires GLPI < ' . PLUGIN_CODEXROUTE_MAX_GLPI_VERSION);
        }
        return false;
    }
    return true;
}

function plugin_codexroute_check_config($verbose = false) {
    return true;
}



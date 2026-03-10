<?php

/**
 * CodexRoute - Panel de Administración
 */

include('../../../inc/includes.php');

use GlpiPlugin\Codexroute\IDEncryption;
use GlpiPlugin\Codexroute\Config;
use GlpiPlugin\Codexroute\RouteValidator;
use GlpiPlugin\Codexroute\PerformanceAnalyzer;
use GlpiPlugin\Codexroute\DatabaseOptimizer;
use Glpi\Application\View\TemplateRenderer;

if (class_exists('GlpiPlugin\Codexroute\IDEncryption') && isset($_GET['id']) && $_GET['id'] != "") {
    $raw_id = isset($GLOBALS['_UGET']['id']) ? $GLOBALS['_UGET']['id'] : $_GET['id'];
    $processed_id = $_GET['id'];
    
    $strict_mode = defined('CODEXROUTE_STRICT_MODE') 
        ? CODEXROUTE_STRICT_MODE 
        : true;
    
    if (is_numeric($raw_id) && $raw_id !== '') {
        if ((int)$raw_id < 0) {
            $_GET['id'] = (int)$raw_id;
        } elseif ($strict_mode && $raw_id !== '0') {
            error_log(sprintf(
                '[CodexRoute] STRICT_MODE_VIOLATION: Numeric ID %s rejected (User: %s, IP: %s)',
                $raw_id,
                Session::getLoginUserID() ?? 0,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ));
            http_response_code(403);
            Html::displayRightError("Access denied: Numeric IDs are not allowed");
            exit;
        } else {
            $_GET['id'] = (int)$raw_id;
        }
    } else {
        if (is_numeric($processed_id)) {
            $decrypted_id = (int)$processed_id;
        } else {
            $decrypted_id = IDEncryption::decryptAndValidate($raw_id, 'Config', READ);
        }
        
        if ($decrypted_id === false) {
            http_response_code(403);
            Html::displayRightError("Access denied: Invalid ID");
            exit;
        }
        
        if (!IDEncryption::validateAuthorization($decrypted_id, 'Config', READ)) {
            http_response_code(403);
            Html::displayRightError("Access denied");
            exit;
        }
        
        $_GET['id'] = $decrypted_id;
    }
}

Session::checkRight("config", UPDATE);

Html::header(__('CodexRoute - Panel de Administración', 'codexroute'), '', 'config', 'codexroute');

// Obtener orden de tabs y establecer tab por defecto
$ordered_tabs = Config::getOrderedTabs();
$default_tab = !empty($ordered_tabs) ? $ordered_tabs[0]['key'] : 'config';
$tab = $_GET['tab'] ?? $default_tab;

// Manejar guardado de orden de tabs
if (isset($_POST['save_tabs_order']) && isset($_POST['tabs_order'])) {
    // Verificar CSRF token
    Session::checkCSRF([
        'save_tabs_order',
        'tabs_order'
    ]);
    
    $tabs_order = json_decode($_POST['tabs_order'], true);
    if (is_array($tabs_order) && Config::saveTabsOrder($tabs_order)) {
        Session::addMessageAfterRedirect(__('Orden de tabs guardado correctamente', 'codexroute'), true, INFO);
        Html::redirect($_SERVER['PHP_SELF'] . '?tab=' . $tab);
    } else {
        Session::addMessageAfterRedirect(__('Error al guardar el orden de tabs', 'codexroute'), true, ERROR);
    }
}

$encryption_config = Config::getEncryptionConfig();
$allowed_routes = Config::getAllowedRoutes();
$tabs_completion = Config::getTabsCompletionStatus();

global $CFG_GLPI;
$root_doc = $CFG_GLPI['root_doc'] ?? '';
$plugin_pics = dirname(__DIR__) . '/pics';
$logo_file = 'codexroute.svg';
foreach (['codexroute.jpg', 'codexroute.jpeg', 'codexroute.png', 'codexroute.svg'] as $candidate) {
    if (file_exists($plugin_pics . '/' . $candidate)) {
        $logo_file = $candidate;
        break;
    }
}
$template_data = [
    'active_tab' => $tab,
    'encryption_config' => $encryption_config,
    'allowed_routes' => $allowed_routes,
    'ordered_tabs' => $ordered_tabs,
    'tabs_completion' => $tabs_completion,
    'plugin_logo_url' => $root_doc . '/plugins/codexroute/pics/' . $logo_file,
];

switch ($tab) {
    case 'routes':
        $problematic_routes = RouteValidator::getProblematicRoutes();
        foreach ($problematic_routes as &$route) {
            $route['allowed'] = in_array($route['file'], $allowed_routes);
        }
        $template_data['problematic_routes'] = $problematic_routes;
        break;
        
    case 'config':
        $template_data['config_file_exists'] = Config::configFileExists();
        break;
        
    case 'apache':
        $php_info = PerformanceAnalyzer::getPhpInfo();
        $memory_limit_str = $php_info['memory_limit'];
        $memory_limit_numeric = intval(preg_replace('/[^0-9]/', '', $memory_limit_str));
        if (stripos($memory_limit_str, 'G') !== false) {
            $memory_limit_numeric *= 1024;
        }
        $php_info['memory_limit_numeric'] = $memory_limit_numeric;
        $template_data['php_info'] = $php_info;
        break;
        
    case 'database':
        $db_status = DatabaseOptimizer::getDatabaseStatus();
        $template_data['db_status'] = $db_status;
        $template_data['optimizable_tables'] = DatabaseOptimizer::getOptimizableTables();
        break;
}

TemplateRenderer::getInstance()->display('@codexroute/pages/config.html.twig', $template_data);

Html::footer();

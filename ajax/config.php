<?php

/**
 * CodexRoute - Handler AJAX
 */

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);

// Limpiar cualquier buffer de salida existente
while (ob_get_level()) {
    ob_end_clean();
}
ob_start();

$AJAX_INCLUDE = true;
define('GLPI_KEEP_CSRF_TOKEN', true);

// Establecer header Accept para que GLPI devuelva JSON en caso de error CSRF
if (!isset($_SERVER['HTTP_ACCEPT']) || strpos($_SERVER['HTTP_ACCEPT'], 'application/json') === false) {
    $_SERVER['HTTP_ACCEPT'] = 'application/json, text/html, */*';
}

try {
    include('../../../inc/includes.php');
    
    if (ob_get_level()) {
        ob_clean();
    }
} catch (Exception $e) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => 'Error al incluir archivos: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

while (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: application/json; charset=utf-8');

use GlpiPlugin\Codexroute\Config;
use GlpiPlugin\Codexroute\RouteValidator;
use GlpiPlugin\Codexroute\PerformanceAnalyzer;
use GlpiPlugin\Codexroute\DatabaseOptimizer;
use GlpiPlugin\Codexroute\Logger;

if (!isset($_SESSION['glpiID']) || empty($_SESSION['glpiID'])) {
    echo json_encode([
        'success' => false,
        'message' => 'No hay sesión activa. Por favor, inicia sesión.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!Session::haveRight("config", UPDATE)) {
    echo json_encode([
        'success' => false,
        'message' => 'Acceso denegado. Se requieren permisos de configuración.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if (empty($action)) {
    echo json_encode([
        'success' => false,
        'message' => 'No se especificó una acción'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Validar CSRF token para acciones que modifican datos
$csrf_required_actions = [
    'save_encryption_config',
    'generate_config_file',
    'allow_route',
    'block_route',
    'save_tabs_order'
];

if (in_array($action, $csrf_required_actions)) {
    // Verificar que el token CSRF esté presente
    $csrf_token = $_POST['_glpi_csrf_token'] ?? $_GET['_glpi_csrf_token'] ?? null;
    
    if (empty($csrf_token)) {
        // Intentar obtener del header
        $headers = getallheaders();
        if ($headers) {
            $csrf_token = $headers['X-Glpi-Csrf-Token'] ?? $headers['x-glpi-csrf-token'] ?? null;
        }
    }
    
    if (empty($csrf_token)) {
        echo json_encode([
            'success' => false,
            'message' => 'Error de seguridad: Token CSRF no proporcionado'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Asegurar que el token esté en $_POST para que Session::validateCSRF lo encuentre
    if (!isset($_POST['_glpi_csrf_token']) && $csrf_token) {
        $_POST['_glpi_csrf_token'] = $csrf_token;
    }
    
    // Validar el token CSRF usando validateCSRF (solo valida, no genera salida)
    $csrf_data = [
        'action' => $action,
        '_glpi_csrf_token' => $csrf_token
    ];
    
    // Agregar otros campos que puedan estar en $_POST
    if (isset($_POST['config'])) {
        $csrf_data['config'] = $_POST['config'];
    }
    if (isset($_POST['file'])) {
        $csrf_data['file'] = $_POST['file'];
    }
    if (isset($_POST['tabs_order'])) {
        $csrf_data['tabs_order'] = $_POST['tabs_order'];
    }
    
    if (!Session::validateCSRF($csrf_data)) {
        echo json_encode([
            'success' => false,
            'message' => 'Error de seguridad: Token CSRF inválido o expirado. Por favor, recarga la página.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$response = ['success' => false, 'message' => 'Acción no válida'];

try {
    switch ($action) {
        // Casos de validación eliminados - ya no se necesitan con GlobalValidator
        // Casos de validación eliminados - ya no se necesitan con GlobalValidator
        // La validación ahora se hace globalmente a través de GlobalValidator::validate()
        // que se ejecuta automáticamente en cada petición
            
        case 'allow_route':
            $file = $_POST['file'] ?? '';
            if (empty($file)) {
                throw new Exception('Archivo no especificado');
            }
            
            if (Config::addAllowedRoute($file)) {
                Logger::success("Ruta permitida: $file", 'routes');
                $response = [
                    'success' => true,
                    'message' => "Ruta $file permitida exitosamente"
                ];
            } else {
                throw new Exception("Error al permitir la ruta $file");
            }
            break;
            
        case 'block_route':
            $file = $_POST['file'] ?? '';
            if (empty($file)) {
                throw new Exception('Archivo no especificado');
            }
            
            if (Config::removeAllowedRoute($file)) {
                Logger::success("Ruta bloqueada: $file", 'routes');
                $response = [
                    'success' => true,
                    'message' => "Ruta $file bloqueada exitosamente"
                ];
            } else {
                throw new Exception("Error al bloquear la ruta $file");
            }
            break;
            
        case 'save_encryption_config':
            $config_json = $_POST['config'] ?? '{}';
            
            // Decodificar si está codificado como URL
            $config_json = urldecode($config_json);
            
            // Si el JSON tiene comillas escapadas (\" en lugar de "), eliminar los escapes
            if (strpos($config_json, '\\"') !== false || strpos($config_json, '\\\\') !== false) {
                $config_json = stripslashes($config_json);
                // Si aún tiene escapes, intentar de nuevo
                if (strpos($config_json, '\\"') !== false) {
                    $config_json = stripslashes($config_json);
                }
            }
            
            $config = json_decode($config_json, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('JSON inválido: ' . json_last_error_msg());
            }
            
            // Guardar en la base de datos
            if (Config::saveEncryptionConfig($config)) {
                Logger::success('Configuración de encriptación guardada', 'config');
                
                // Si el archivo de configuración ya existe, actualizarlo automáticamente
                if (Config::configFileExists()) {
                    if (Config::generateConfigFile($config)) {
                        Logger::success('Archivo de configuración actualizado automáticamente', 'config');
                        $response = [
                            'success' => true,
                            'message' => 'Configuración guardada y archivo actualizado correctamente'
                        ];
                    } else {
                        // Si falla la actualización del archivo, aún así reportamos éxito en BD
                        Logger::warning('Configuración guardada en BD pero error al actualizar archivo', 'config');
                        $response = [
                            'success' => true,
                            'message' => 'Configuración guardada en base de datos. Error al actualizar archivo de configuración.'
                        ];
                    }
                } else {
                    // Si el archivo no existe, solo guardamos en BD
                    $response = [
                        'success' => true,
                        'message' => 'Configuración guardada correctamente. Genera el archivo de configuración cuando estés listo.'
                    ];
                }
            } else {
                throw new Exception('Error al guardar la configuración');
            }
            break;
            
        case 'generate_config_file':
            $config_json = $_POST['config'] ?? '{}';
            
            if (empty($config_json) || $config_json === '{}') {
                throw new Exception('No se recibió la configuración. Por favor, asegúrate de que el formulario tenga valores.');
            }
            
            // Decodificar si está codificado como URL
            $config_json = urldecode($config_json);
            
            // Si el JSON tiene comillas escapadas (\" en lugar de "), eliminar los escapes
            if (strpos($config_json, '\\"') !== false || strpos($config_json, '\\\\') !== false) {
                $config_json = stripslashes($config_json);
                // Si aún tiene escapes, intentar de nuevo
                if (strpos($config_json, '\\"') !== false) {
                    $config_json = stripslashes($config_json);
                }
            }
            
            $config = json_decode($config_json, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                $error_msg = 'JSON inválido';
                switch (json_last_error()) {
                    case JSON_ERROR_DEPTH:
                        $error_msg .= ': Profundidad máxima excedida';
                        break;
                    case JSON_ERROR_STATE_MISMATCH:
                        $error_msg .= ': Estado JSON inválido o mal formado';
                        break;
                    case JSON_ERROR_CTRL_CHAR:
                        $error_msg .= ': Carácter de control encontrado';
                        break;
                    case JSON_ERROR_SYNTAX:
                        $error_msg .= ': Error de sintaxis';
                        break;
                    case JSON_ERROR_UTF8:
                        $error_msg .= ': Caracteres UTF-8 mal formados';
                        break;
                    default:
                        $error_msg .= ': Error desconocido';
                }
                $error_msg .= '. JSON recibido: ' . substr($config_json, 0, 200);
                throw new Exception($error_msg);
            }
            
            if (empty($config) || !is_array($config)) {
                throw new Exception('La configuración recibida está vacía o no es un array válido');
            }
            
            if (Config::generateConfigFile($config)) {
                Logger::success('Archivo de configuración generado', 'config');
                $response = [
                    'success' => true,
                    'message' => 'Archivo de configuración generado correctamente'
                ];
            } else {
                throw new Exception('Error al generar el archivo de configuración. Verifica los permisos de escritura en el directorio de configuración.');
            }
            break;
            
        case 'analyze_apache':
            Logger::info('Analizando rendimiento Apache/PHP', 'performance');
            $results = PerformanceAnalyzer::analyzeApache();
            Logger::success('Análisis Apache/PHP completado', 'performance');
            
            $response = [
                'success' => true,
                'results' => $results
            ];
            break;
            
        case 'analyze_mysql_config':
            Logger::info('Analizando configuración MySQL', 'database');
            $results = PerformanceAnalyzer::analyzeMySQLConfig();
            Logger::success('Análisis MySQL completado', 'database');
            
            $response = [
                'success' => true,
                'results' => $results
            ];
            break;
            
        case 'analyze_database':
            Logger::info('Analizando base de datos', 'database');
            $results = DatabaseOptimizer::analyzeDatabase();
            Logger::success('Análisis de BD completado', 'database');
            
            $response = [
                'success' => true,
                'results' => $results
            ];
            break;
            
        case 'profile_sql':
            Logger::info('Ejecutando profiling SQL', 'database');
            $results = DatabaseOptimizer::profileSQL();
            Logger::success('Profiling SQL completado', 'database');
            
            $response = [
                'success' => true,
                'results' => $results
            ];
            break;
            
        case 'analyze_views':
            Logger::info('Analizando vistas principales', 'database');
            $results = DatabaseOptimizer::analyzeViews();
            Logger::success('Análisis de vistas completado', 'database');
            
            $response = [
                'success' => true,
                'results' => $results
            ];
            break;
            
        case 'optimize_table_indexes':
            $table_name = $_POST['table_name'] ?? '';
            $table_db = $_POST['table_db'] ?? '';
            
            if (empty($table_name) || empty($table_db)) {
                throw new Exception('Parámetros incompletos');
            }
            
            Logger::info("Optimizando índices de $table_name", 'database');
            $results = DatabaseOptimizer::optimizeTableIndexes($table_name, $table_db);
            Logger::success("Optimización de $table_name completada", 'database');
            
            $response = [
                'success' => true,
                'results' => $results
            ];
            break;
            
        case 'get_logs':
            $type = $_GET['type'] ?? 'all';
            $logs = Logger::getLogs($type);
            
            $response = [
                'success' => true,
                'logs'    => $logs
            ];
            break;
            
        // Caso 'get_progress' eliminado - ya no se necesita sin la pestaña de validación
            
        default:
            $response = [
                'success' => false,
                'message' => 'Acción no reconocida: ' . $action
            ];
    }
} catch (Exception $e) {
    Logger::error($e->getMessage(), 'error');
    $response = [
        'success' => false,
        'message' => $e->getMessage()
    ];
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;


<?php

namespace GlpiPlugin\Codexroute;

use Session;
use Html;

class GlobalValidator
{

    private static $initialized = false;
    private static $redirect_attempted = false;
    private static $decrypted_ids = [];
    private static $redirected_urls = [];
    private static $encrypted_id_map = []; // Mapeo de ID numérico -> ID encriptado
    private static $item_relations = []; // Mapeo de item_id -> ['items_id' => X, 'itemtype' => Y]

    public static function validate(): void
    {
        if (isCommandLine()) {
            return;
        }

        // Evitar ejecución múltiple en la misma petición
        if (self::$initialized) {
            return;
        }

        self::$initialized = true;

        // Cargar configuración anticipadamente para verificar si la encriptación está habilitada
        $config_dir_early = GLPI_CONFIG_DIR ?? (GLPI_ROOT . '/config');
        $config_file_early = $config_dir_early . '/codexroute/encryption_config.php';
        if (file_exists($config_file_early)) {
            include_once($config_file_early);
        }

        $encryption_enabled = defined('CODEXROUTE_ENCRYPTION_ENABLED') ? CODEXROUTE_ENCRYPTION_ENABLED : false;
        if (!$encryption_enabled) {
            return;
        }

        // Interceptar redirecciones ANTES de validar
        self::interceptRedirects();

        // Obtener información de la petición
        $script_name = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '';
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $script_basename = basename($script_name);

        $excluded_paths = [
            '/ajax/dashboard.php',
            '/ajax/common.tabs.php',
            '/ajax/search.php',
            '/ajax/autocompletion.php',
            '/ajax/dropdown.php',
            '/ajax/updatecurrenttab.php',
            '/ajax/getDropdownValue.php',
            '/ajax/getDropdownConnect.php',
            '/ajax/comments.php',
            '/ajax/actorinformation.php',
            '/ajax/impact.php',
            '/front/document.send.php',
            '/front/item_disk.form.php',
            '/front/item_softwareversion.form.php',
            '/front/item_operatingsystem.form.php',
            '/front/item_device.form.php',
            '/front/item_networkport.form.php',
            '/front/item_remotemanagement.form.php',
            '/plugins/codexroute/ajax/encrypt_id.php',
            '/plugins/fields/front/container.form.php',
        ];

        // Verificar si la ruta actual está en la lista de exclusiones
        $excluded = false;
        $matched_path = null;

        foreach ($excluded_paths as $excluded_path) {
            if (strpos($script_name, $excluded_path) !== false || strpos($request_uri, $excluded_path) !== false) {
                $excluded = true;
                $matched_path = $excluded_path;
                break;
            }
        }

        if (!$excluded && (strpos($script_name, '/plugins/') !== false && strpos($script_name, '/front/') !== false && preg_match('/\.form\.php$/', $script_basename))) {
            $excluded = true;
            $matched_path = 'plugins/*/front/*.form.php';
        }

        if (!$excluded) {
            if (preg_match('/^item_[^\/]+\.form\.php$/', $script_basename)) {
                $excluded = true;
                $matched_path = 'item_*.form.php pattern';
            } elseif (preg_match('/^(networkport|socket|remotemanagement|computerantivirus)\.form\.php$/', $script_basename)) {
                $excluded = true;
                $matched_path = 'related form pattern';
            }
        }

        if ($excluded) {
            // Aunque la ruta esté excluida, aún debemos desencriptar IDs encriptados
            // para que los formularios puedan funcionar correctamente
            $script_basename = basename($script_name);
            $is_item_form = preg_match('/^item_[^\/]+\.form\.php$/', $script_basename);
            $is_related_form = preg_match('/^(networkport|socket|remotemanagement|computerantivirus)\.form\.php$/', $script_basename);
            
            if ($is_item_form || $is_related_form) {
                // Para formularios relacionados, desencriptar IDs pero permitir acceso
                foreach ($_GET as $param_name => $param_value) {
                    $param_lower = strtolower($param_name);
                    if (in_array($param_lower, ['id', 'items_id', 'computers_id', 'monitors_id', 'printers_id', 'phones_id', 'peripherals_id', 'networks_id']) && !empty($param_value) && !is_numeric($param_value)) {
                        if (class_exists('GlpiPlugin\Codexroute\IDEncryption')) {
                            $decrypted = IDEncryption::decrypt($param_value);
                            if ($decrypted !== false && is_numeric($decrypted)) {
                                $_GET[$param_name] = $decrypted;
                                $_REQUEST[$param_name] = $decrypted;
                                if (isset($GLOBALS['_UGET'])) {
                                    $GLOBALS['_UGET'][$param_name] = $decrypted;
                                }
                            }
                        }
                    }
                }
                
                // Si tiene id pero falta items_id o itemtype, intentar obtenerlos desde la base de datos
                if (isset($_GET['id']) && !empty($_GET['id']) && is_numeric($_GET['id']) && (!isset($_GET['items_id']) || !isset($_GET['itemtype']))) {
                    try {
                        $item_class = 'Item_Disk';
                        if (class_exists($item_class)) {
                            $item = new $item_class();
                            if ($item->getFromDB($_GET['id'])) {
                                if (isset($item->fields['items_id']) && isset($item->fields['itemtype'])) {
                                    if (!isset($_GET['items_id']) || empty($_GET['items_id'])) {
                                        $_GET['items_id'] = (int)$item->fields['items_id'];
                                        $_REQUEST['items_id'] = $_GET['items_id'];
                                        if (isset($GLOBALS['_UGET'])) {
                                            $GLOBALS['_UGET']['items_id'] = $_GET['items_id'];
                                        }
                                    }
                                    if (!isset($_GET['itemtype']) || empty($_GET['itemtype'])) {
                                        $_GET['itemtype'] = $item->fields['itemtype'];
                                        $_REQUEST['itemtype'] = $_GET['itemtype'];
                                        if (isset($GLOBALS['_UGET'])) {
                                            $GLOBALS['_UGET']['itemtype'] = $_GET['itemtype'];
                                        }
                                    }
                                }
                            }
                        }
                    } catch (\Throwable $e) {
                        if (defined('CODEXROUTE_LOG_SECURITY') && CODEXROUTE_LOG_SECURITY) {
                            error_log(sprintf(
                                '[CodexRoute] Error loading item params in excluded form: %s',
                                $e->getMessage()
                            ));
                        }
                    }
                }
            }
            
            if (defined('CODEXROUTE_LOG_SECURITY') && CODEXROUTE_LOG_SECURITY) {
                error_log(sprintf(
                    '[CodexRoute] DEBUG: Path EXCLUDED: %s (Script: %s, URI: %s)',
                    $matched_path,
                    $script_name,
                    $request_uri
                ));
            }
            return; // Salir sin validar estrictamente
        } else {
            // Log cuando NO se excluye (útil para debugging)
            if (defined('CODEXROUTE_LOG_SECURITY') && CODEXROUTE_LOG_SECURITY) {
                error_log(sprintf(
                    '[CodexRoute] DEBUG: Path NOT EXCLUDED, will validate (Script: %s, URI: %s)',
                    $script_name,
                    $request_uri
                ));
            }
        }

        // Lista de parámetros de ID que deben ser procesados (IDs de entidades de GLPI)
        $valid_id_params = [
            'id',
            'docid',
            'itemid',
            'items_id',
            'tickets_id',
            'computers_id',
            'monitors_id',
            'printers_id',
            'networks_id',
            'phones_id',
            'peripherals_id',
            'software_id',
            'entities_id',
            'users_id',
            'groups_id',
            'profiles_id',
            'locations_id',
            'states_id',
            'manufacturers_id',
            'suppliers_id',
            'contacts_id',
            'contracts_id',
            'licenses_id',
            'cartridges_id',
            'consumables_id',
            'changes_id',
            'problems_id',
            'projects_id',
            'notificationtemplates_id',
            'knowbaseitems_id',
            'reminders_id',
            'rssfeeds_id',
            'calendars_id',
            'slas_id',
            'olts_id',
            'networkequipments_id',
            'passivedcequipments_id',
            'enclosures_id',
            'pdus_id',
            'clusters_id',
            'domains_id',
            'appliances_id',
            'databases_id',
            'cables_id',
            'dashboards_id',
        ];

        // Detectar parámetros de ID válidos en $_GET y $_POST
        $id_params = [];

        // Procesar parámetros de $_GET
        foreach ($_GET as $param_name => $param_value) {
            if (
                in_array(strtolower($param_name), array_map('strtolower', $valid_id_params))
                && !empty($param_value)
                && $param_value !== ''
            ) {
                $id_params[$param_name] = $param_value;
            }
        }

        // Procesar parámetros de $_POST
        foreach ($_POST as $param_name => $param_value) {
            if (
                in_array(strtolower($param_name), array_map('strtolower', $valid_id_params))
                && !empty($param_value)
                && $param_value !== ''
            ) {
                // Si el parámetro ya está en $id_params desde $_GET, no duplicar
                if (!isset($id_params[$param_name])) {
                    $id_params[$param_name] = $param_value;
                }
            }
        }

        // Si no hay parámetros de ID válidos, no hacer nada
        if (empty($id_params)) {
            if (defined('CODEXROUTE_LOG_SECURITY') && CODEXROUTE_LOG_SECURITY) {
                error_log(sprintf(
                    '[CodexRoute] DEBUG: No valid ID parameters found in request (User: %s, IP: %s, URI: %s)',
                    Session::getLoginUserID() ?? 0,
                    $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    $_SERVER['REQUEST_URI'] ?? 'unknown'
                ));
            }
            return;
        }

        // Crear una clave única para esta petición
        $request_key = md5($request_uri . serialize($id_params));

        // Si ya procesamos esta petición exacta, no procesar de nuevo
        if (isset(self::$redirected_urls[$request_key])) {
            return;
        }

        // Cargar configuración
        $config_dir = GLPI_CONFIG_DIR ?? (GLPI_ROOT . '/config');
        $config_file = $config_dir . '/codexroute/encryption_config.php';

        if (file_exists($config_file)) {
            include_once($config_file);
        }

        $strict_mode = defined('CODEXROUTE_STRICT_MODE')
            ? CODEXROUTE_STRICT_MODE
            : false;

        // Procesar cada parámetro de ID encontrado
        foreach ($id_params as $param_name => $param_value) {
            self::processIdParameter($param_name, $param_value, $strict_mode, $request_key);
        }
    }

    /**
     * Procesa un parámetro de ID individual (id, docid, itemid, etc.)
     */
    private static function processIdParameter(string $param_name, $raw_id, bool $strict_mode, string $request_key): void
    {
        // Verificar si la ruta actual está excluida antes de procesar
        $script_name = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '';
        $script_basename = basename($script_name);
        
        $excluded_paths = [
            '/ajax/dashboard.php',
            '/ajax/common.tabs.php',
            '/ajax/search.php',
            '/ajax/autocompletion.php',
            '/ajax/dropdown.php',
            '/ajax/updatecurrenttab.php',
            '/ajax/getDropdownValue.php',
            '/ajax/getDropdownConnect.php',
            '/ajax/comments.php',
            '/ajax/actorinformation.php',
            '/ajax/impact.php',
            '/front/document.send.php',
            '/front/item_disk.form.php',
            '/front/item_softwareversion.form.php',
            '/front/item_operatingsystem.form.php',
            '/front/item_device.form.php',
            '/front/item_networkport.form.php',
            '/front/item_remotemanagement.form.php',
            '/plugins/codexroute/ajax/encrypt_id.php',
            '/plugins/fields/front/container.form.php',
        ];

        $excluded = false;
        foreach ($excluded_paths as $excluded_path) {
            if (strpos($script_name, $excluded_path) !== false) {
                $excluded = true;
                break;
            }
        }

        if (!$excluded && strpos($script_name, '/plugins/') !== false && strpos($script_name, '/front/') !== false && preg_match('/\.form\.php$/', $script_basename)) {
            $excluded = true;
        }

        if (!$excluded) {
            if (preg_match('/^item_[^\/]+\.form\.php$/', $script_basename)) {
                $excluded = true;
            } elseif (preg_match('/^(networkport|socket|remotemanagement|computerantivirus)\.form\.php$/', $script_basename)) {
                $excluded = true;
            }
        }
        
        if ($excluded) {
            // Ruta excluida, permitir IDs numéricos sin validación estricta
            return;
        }
        
        if (is_string($raw_id) && strlen($raw_id) > 100) {
            error_log(sprintf(
                '[CodexRoute] WARNING: ID too long (%d chars), possible duplication, truncating',
                strlen($raw_id)
            ));

            // Truncar a 80 caracteres (seguro para IDs encriptados típicos)
            $raw_id = substr($raw_id, 0, 80);

            // Actualizar en las superglobales
            if (isset($_GET[$param_name])) {
                $_GET[$param_name] = $raw_id;
            }
            if (isset($_POST[$param_name])) {
                $_POST[$param_name] = $raw_id;
            }
            $_REQUEST[$param_name] = $raw_id;
        }

        try {
            // Obtener el valor del parámetro desde $_GET, $_POST o $GLOBALS['_UGET']
            // Priorizar $_GET sobre $_POST para mantener compatibilidad
            if (isset($GLOBALS['_UGET'][$param_name])) {
                $raw_id = $GLOBALS['_UGET'][$param_name];
            } elseif (isset($_GET[$param_name])) {
                $raw_id = $_GET[$param_name];
            } elseif (isset($_POST[$param_name])) {
                $raw_id = $_POST[$param_name];
            }
        } catch (\Throwable $e) {
            // Si hay un error al acceder a las superglobales, registrar y salir silenciosamente
            error_log(sprintf(
                '[CodexRoute] ERROR al acceder a parámetros en processIdParameter(): %s',
                $e->getMessage()
            ));
            return;
        }

        // Si el ID no es numérico, intentar desencriptarlo (siempre, independientemente del modo estricto)
        if (!is_numeric($raw_id) && $raw_id !== '' && $raw_id !== null && $raw_id !== '0') {
            if (class_exists('GlpiPlugin\Codexroute\IDEncryption')) {
                // Verificar que el ID encriptado no sea demasiado largo (puede indicar duplicación o corrupción)
                $max_length = defined('CODEXROUTE_MAX_LENGTH')
                    ? CODEXROUTE_MAX_LENGTH
                    : 200;

                if (strlen($raw_id) > $max_length) {
                    // El ID parece estar duplicado o corrupto, intentar usar solo la primera parte
                    $raw_id = substr($raw_id, 0, $max_length);

                    if (defined('CODEXROUTE_LOG_SECURITY') && CODEXROUTE_LOG_SECURITY) {
                        error_log(sprintf(
                            '[CodexRoute] WARNING: Encrypted ID too long (%d chars), truncated to %d chars (User: %s, IP: %s)',
                            strlen($_GET[$param_name] ?? ''),
                            strlen($raw_id),
                            Session::getLoginUserID() ?? 0,
                            $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                        ));
                    }
                }

                // Intentar desencriptar con manejo robusto de errores
                // Usar try-catch para capturar cualquier excepción durante la desencriptación
                $decrypted_id = false;
                $decrypt_error = null;

                try {
                    $decrypted_id = IDEncryption::decrypt($raw_id);
                } catch (\Exception $e) {
                    $decrypt_error = $e->getMessage();
                    if (defined('CODEXROUTE_LOG_SECURITY') && CODEXROUTE_LOG_SECURITY) {
                        error_log(sprintf(
                            '[CodexRoute] WARNING: Exception during decryption (User: %s, IP: %s, Param: %s, Error: %s)',
                            Session::getLoginUserID() ?? 0,
                            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                            $param_name,
                            $decrypt_error
                        ));
                    }
                } catch (\Throwable $e) {
                    $decrypt_error = $e->getMessage();
                    if (defined('CODEXROUTE_LOG_SECURITY') && CODEXROUTE_LOG_SECURITY) {
                        error_log(sprintf(
                            '[CodexRoute] WARNING: Throwable during decryption (User: %s, IP: %s, Param: %s, Error: %s)',
                            Session::getLoginUserID() ?? 0,
                            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                            $param_name,
                            $decrypt_error
                        ));
                    }
                }

                // Si la desencriptación falla, rechazar
                if ($decrypted_id === false || !is_numeric($decrypted_id) || (int)$decrypted_id <= 0) {
                    // Registrar el error para diagnóstico
                    if (defined('CODEXROUTE_LOG_SECURITY') && CODEXROUTE_LOG_SECURITY) {
                        error_log(sprintf(
                            '[CodexRoute] ERROR: Failed to decrypt ID (User: %s, IP: %s, Param: %s, Raw ID length: %d, Error: %s)',
                            Session::getLoginUserID() ?? 0,
                            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                            $param_name,
                            strlen($raw_id),
                            $decrypt_error ?? 'Unknown'
                        ));
                    }

                    self::logBlockedRoute($_SERVER['SCRIPT_NAME'] ?? '');

                    http_response_code(403);

                    if (class_exists('Html')) {
                        Html::displayRightError(__('Acceso denegado: ID inválido', 'codexroute'));
                    } else {
                        header('Content-Type: text/html; charset=utf-8');
                        echo '<!DOCTYPE html><html><head><title>Acceso Denegado</title></head><body>';
                        echo '<h1>403 - Acceso Denegado</h1>';
                        echo '<p>ID inválido.</p>';
                        echo '</body></html>';
                    }

                    exit;
                }

                $decrypted_id = (int)$decrypted_id;

                // Guardar el mapeo de ID numérico -> ID encriptado para usar en redirecciones
                self::$encrypted_id_map[$decrypted_id] = $raw_id;

                // Establecer el ID desencriptado inmediatamente para que GLPI pueda usarlo
                // Establecer en $_GET, $_POST y $_REQUEST según corresponda
                if (isset($_GET[$param_name])) {
                    $_GET[$param_name] = $decrypted_id;
                }
                if (isset($_POST[$param_name])) {
                    $_POST[$param_name] = $decrypted_id;
                }
                $_REQUEST[$param_name] = $decrypted_id;

                if (isset($GLOBALS['_UGET'])) {
                    $GLOBALS['_UGET'][$param_name] = $decrypted_id;
                }
                if (isset($GLOBALS['_UPOST'])) {
                    $GLOBALS['_UPOST'][$param_name] = $decrypted_id;
                }

                // Marcar este ID como desencriptado para evitar que se procese de nuevo como numérico
                self::$decrypted_ids[$decrypted_id] = true;

                // NO validar autorización aquí - dejar que GLPI maneje los permisos completamente
                // Esto evita problemas de timing cuando se accede rápidamente a múltiples registros
                // GLPI tiene su propio sistema robusto de permisos que manejará esto correctamente

                if (defined('CODEXROUTE_LOG_SECURITY') && CODEXROUTE_LOG_SECURITY) {
                    $itemtype = self::detectItemTypeFromRequest();
                    error_log(sprintf(
                        '[CodexRoute] DEBUG: Decrypted ID %d from encrypted ID %s (User: %s, ItemType: %s, Param: %s) - Letting GLPI handle permissions',
                        $decrypted_id,
                        substr($raw_id, 0, 50) . (strlen($raw_id) > 50 ? '...' : ''),
                        Session::getLoginUserID() ?? 0,
                        $itemtype,
                        $param_name
                    ));
                }
            }

            return;
        }

        // Si el ID es numérico, aplicar lógica de modo estricto
        if (is_numeric($raw_id) && $raw_id !== '' && $raw_id !== '0' && (int)$raw_id > 0) {
            // Verificar si este ID numérico fue establecido después de desencriptar
            // Esto evita bucles cuando se desencripta y luego se detecta como numérico
            if (isset(self::$decrypted_ids[(int)$raw_id])) {
                return;
            }

            if (!$strict_mode) {
                // Si el modo estricto no está activado, permitir IDs numéricos
                if (defined('CODEXROUTE_LOG_SECURITY') && CODEXROUTE_LOG_SECURITY) {
                    error_log(sprintf(
                        '[CodexRoute] DEBUG: Strict mode is OFF, allowing numeric ID %s (User: %s, IP: %s, Param: %s, URI: %s)',
                        $raw_id,
                        Session::getLoginUserID() ?? 0,
                        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                        $param_name,
                        $_SERVER['REQUEST_URI'] ?? 'unknown'
                    ));
                }
                return;
            }

            // MODO ESTRICTO: Rechazar IDs numéricos sin encriptar SOLO en GET
            // En POST (formularios), permitir IDs numéricos porque los formularios pueden enviarlos
            $request_method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

            // Si es POST, permitir IDs numéricos (vienen de formularios)
            // Pero encriptarlos y guardarlos en el mapeo para redirecciones
            if ($request_method === 'POST') {
                $numeric_id = (int)$raw_id;
                
                // Guardar items_id y itemtype si están presentes (para formularios item_*.form.php)
                if ($param_name === 'items_id' && isset($_POST['itemtype'])) {
                    self::$item_relations[$numeric_id] = [
                        'items_id' => $numeric_id,
                        'itemtype' => $_POST['itemtype']
                    ];
                }
                
                // Encriptar el ID y guardarlo en el mapeo para usar en redirecciones
                if (class_exists('GlpiPlugin\Codexroute\IDEncryption')) {
                    $encrypted_id = IDEncryption::encrypt($numeric_id);
                    if ($encrypted_id !== false && $encrypted_id !== (string)$numeric_id && strlen($encrypted_id) <= 100) {
                        self::$encrypted_id_map[$numeric_id] = $encrypted_id;
                        
                        if (defined('CODEXROUTE_LOG_SECURITY') && CODEXROUTE_LOG_SECURITY) {
                            error_log(sprintf(
                                '[CodexRoute] STRICT_MODE: Encrypted numeric ID %s in POST for redirects (User: %s, Param: %s)',
                                $numeric_id,
                                Session::getLoginUserID() ?? 0,
                                $param_name
                            ));
                        }
                    }
                }
                
                if (defined('CODEXROUTE_LOG_SECURITY') && CODEXROUTE_LOG_SECURITY) {
                    error_log(sprintf(
                        '[CodexRoute] STRICT_MODE: Allowing numeric ID %s in POST request (User: %s, IP: %s, Param: %s, URI: %s)',
                        $raw_id,
                        Session::getLoginUserID() ?? 0,
                        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                        $param_name,
                        $_SERVER['REQUEST_URI'] ?? 'unknown'
                    ));
                }
                return; // Permitir IDs numéricos en POST
            }

            // Verificar si la ruta está permitida (excepciones)
            $script_name_check = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '';
            $request_uri_check = $_SERVER['REQUEST_URI'] ?? '';
            $script_basename_check = basename($script_name_check);

            // Verificar si es el endpoint de encriptación (debe poder recibir IDs numéricos)
            $is_encrypt_endpoint_check = (
                strpos($script_name_check, '/plugins/codexroute/ajax/encrypt_id.php') !== false
                || strpos($request_uri_check, '/plugins/codexroute/ajax/encrypt_id.php') !== false
                || strpos($request_uri_check, 'encrypt_id.php') !== false
                || strpos($script_name_check, 'encrypt_id.php') !== false
                || $script_basename_check === 'encrypt_id.php'
            );

            if ($is_encrypt_endpoint_check) {
                return;
            }

            // Excluir formularios de items relacionados (item_*.form.php)
            // Estos formularios reciben items_id en GET y deben poder procesarlo
            if (preg_match('/item_[^\/]+\.form\.php$/', $script_basename_check)) {
                if (defined('CODEXROUTE_LOG_SECURITY') && CODEXROUTE_LOG_SECURITY) {
                    error_log(sprintf(
                        '[CodexRoute] STRICT_MODE: Allowing numeric ID %s in item form %s (User: %s)',
                        $raw_id,
                        $script_basename_check,
                        Session::getLoginUserID() ?? 0
                    ));
                }
                return;
            }

            $is_allowed    = false;
            $allowed_routes = class_exists('GlpiPlugin\Codexroute\Config')
                ? \GlpiPlugin\Codexroute\Config::getAllowedRoutes()
                : self::readAllowedRoutesJson();

            if (is_array($allowed_routes)) {
                $is_allowed = in_array($script_basename_check, $allowed_routes, true)
                    || in_array($script_name_check, $allowed_routes, true);
            }

            // Si la ruta está permitida, permitir el acceso
            if ($is_allowed) {
                if (defined('CODEXROUTE_LOG_SECURITY') && CODEXROUTE_LOG_SECURITY) {
                    error_log(sprintf(
                        '[CodexRoute] STRICT_MODE: Route %s is allowed, allowing numeric ID %s (User: %s, IP: %s)',
                        $script_name_check,
                        $raw_id,
                        Session::getLoginUserID() ?? 0,
                        $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                    ));
                }
                return;
            }

            // Si la ruta no está permitida y el modo estricto está activado, RECHAZAR la solicitud
            if (defined('CODEXROUTE_LOG_SECURITY') && CODEXROUTE_LOG_SECURITY) {
                error_log(sprintf(
                    '[CodexRoute] STRICT_MODE_VIOLATION: Numeric ID %s rejected (User: %s, IP: %s, URI: %s, Param: %s)',
                    $raw_id,
                    Session::getLoginUserID() ?? 0,
                    $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    $_SERVER['REQUEST_URI'] ?? 'unknown',
                    $param_name
                ));
            }

            self::logBlockedRoute($script_name);

            http_response_code(403);

            try {
                if (class_exists('Html')) {
                    Html::displayRightError(__('Acceso denegado: Los IDs numéricos no están permitidos en modo estricto', 'codexroute'));
                } else {
                    header('Content-Type: text/html; charset=utf-8');
                    echo '<!DOCTYPE html><html><head><title>Acceso Denegado</title></head><body>';
                    echo '<h1>403 - Acceso Denegado</h1>';
                    echo '<p>Los IDs numéricos no están permitidos en modo estricto. Por favor, use IDs encriptados.</p>';
                    echo '</body></html>';
                }
            } catch (\Throwable $e) {
                // Si hay un error al mostrar el mensaje, solo registrar y continuar
                error_log(sprintf(
                    '[CodexRoute] ERROR al mostrar mensaje de error (modo estricto): %s',
                    $e->getMessage()
                ));
            }

            exit;
        }
    }

    private static function detectItemTypeFromRequest(): string
    {
        // Primero intentar obtener el itemtype desde los parámetros (útil para peticiones AJAX)
        if (isset($_GET['_itemtype']) && !empty($_GET['_itemtype'])) {
            $itemtype = $_GET['_itemtype'];
            if (class_exists($itemtype)) {
                return $itemtype;
            }
        }

        if (isset($_REQUEST['_itemtype']) && !empty($_REQUEST['_itemtype'])) {
            $itemtype = $_REQUEST['_itemtype'];
            if (class_exists($itemtype)) {
                return $itemtype;
            }
        }

        // Si no está en los parámetros, intentar detectarlo desde el nombre del archivo
        $script_name = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '';
        $basename = basename($script_name, '.php');

        if (strpos($basename, '.form') !== false) {
            $basename = str_replace('.form', '', $basename);
        }

        if (empty($basename)) {
            return 'Unknown';
        }

        $type_map = [
            'computer'     => 'Computer',
            'monitor'      => 'Monitor',
            'software'     => 'Software',
            'networkequipment' => 'NetworkEquipment',
            'peripheral'   => 'Peripheral',
            'printer'      => 'Printer',
            'cartridge'    => 'Cartridge',
            'consumable'   => 'Consumable',
            'phone'        => 'Phone',
            'rack'         => 'Rack',
            'enclosure'    => 'Enclosure',
            'pdu'          => 'PDU',
            'passivedcequipment' => 'PassiveDCEquipment',
            'cable'        => 'Cable',
            'simcard'      => 'Simcard',
            'user'         => 'User',
            'group'        => 'Group',
            'entity'       => 'Entity',
            'location'     => 'Location',
            'state'        => 'State',
            'manufacturer' => 'Manufacturer',
            'model'        => 'Model',
            'contact'      => 'Contact',
            'supplier'     => 'Supplier',
            'contract'     => 'Contract',
            'document'     => 'Document',
            'ticket'       => 'Ticket',
            'problem'      => 'Problem',
            'change'       => 'Change',
            'project'      => 'Project',
            'projecttask'  => 'ProjectTask',
            'knowbaseitem' => 'KnowbaseItem',
            'reminder'     => 'Reminder',
            'rssfeed'     => 'RSSFeed',
            'sla'          => 'SLA',
            'ola'          => 'OLA',
        ];

        $basename_lower = strtolower($basename);

        if (isset($type_map[$basename_lower])) {
            return $type_map[$basename_lower];
        }

        return ucfirst($basename);
    }

    /**
     * Intercepta redirecciones y reemplaza IDs numéricos con encriptados
     */
    public static function interceptRedirects(): void
    {
        // Usar output buffering para interceptar headers antes de enviarlos
        if (!ob_get_level()) {
            ob_start();
        }

        // Registrar función para ejecutar al final del script, justo antes de enviar headers
        register_shutdown_function(function () {
            if (empty(self::$encrypted_id_map)) {
                return;
            }

            // Obtener todos los headers enviados
            $headers = headers_list();
            $location_header = null;

            // Buscar el header Location
            foreach ($headers as $header) {
                if (stripos($header, 'Location:') === 0) {
                    $location_header = $header;
                    break;
                }
            }

            if ($location_header !== null) {
                $location = trim(substr($location_header, 9)); // Remover "Location: "
                
                // Log para debugging
                error_log(sprintf(
                    '[CodexRoute] Intercepting redirect: %s',
                    $location
                ));
                
                $new_location = self::encryptIdsInUrl($location);
                $original_new_location = $new_location;
                $new_location = self::addMissingItemParams($new_location);

                // Log para debugging
                error_log(sprintf(
                    '[CodexRoute] Redirect after encryption: %s -> %s (Original: %s)',
                    $location,
                    $new_location,
                    $original_new_location
                ));

                if ($new_location !== $location || $new_location !== $original_new_location) {
                    // Reemplazar el header Location
                    header_remove('Location');
                    header('Location: ' . $new_location, true);

                    error_log(sprintf(
                        '[CodexRoute] Redirect intercepted and updated: %s -> %s',
                        $location,
                        $new_location
                    ));
                } else {
                    error_log(sprintf(
                        '[CodexRoute] Redirect NOT changed: %s (encrypted_id_map has %d entries)',
                        $location,
                        count(self::$encrypted_id_map)
                    ));
                }
            }
        });
    }

    /**
     * Agrega items_id y itemtype faltantes a URLs de formularios item_*.form.php
     */
    private static function addMissingItemParams(string $url): string
    {
        $url_parts = parse_url($url);
        if (!isset($url_parts['path'])) {
            return $url;
        }
        
        $script_name = basename($url_parts['path']);
        
        // Solo procesar formularios item_*.form.php
        if (!preg_match('/^item_[^\/]+\.form\.php$/', $script_name)) {
            return $url;
        }
        
        // Parsear parámetros de la URL
        $params = [];
        if (isset($url_parts['query'])) {
            parse_str($url_parts['query'], $params);
        }
        
        // Si ya tiene items_id y itemtype, no hacer nada
        if (isset($params['items_id']) && isset($params['itemtype']) && !empty($params['items_id']) && !empty($params['itemtype'])) {
            return $url;
        }
        
        // Si tiene id, intentar obtener items_id y itemtype
        if (isset($params['id']) && !empty($params['id'])) {
            $item_id = $params['id'];
            $decrypted_item_id = null;
            
            // Intentar desencriptar si es necesario
            if (class_exists('GlpiPlugin\Codexroute\IDEncryption')) {
                $decrypted_item_id = IDEncryption::decrypt($item_id);
            }
            
            // Si no se pudo desencriptar, asumir que es numérico
            if ($decrypted_item_id === false) {
                if (is_numeric($item_id)) {
                    $decrypted_item_id = (int)$item_id;
                } else {
                    return $url;
                }
            } else {
                $decrypted_item_id = (int)$decrypted_item_id;
            }
            
            // Intentar obtener desde el mapeo guardado primero
            $items_id = null;
            $itemtype = null;
            
            if (isset(self::$item_relations[$decrypted_item_id])) {
                $items_id = self::$item_relations[$decrypted_item_id]['items_id'];
                $itemtype = self::$item_relations[$decrypted_item_id]['itemtype'];
            }
            
            // Si no está en el mapeo, intentar cargar desde la base de datos
            if ($items_id === null || $itemtype === null) {
                try {
                    // Detectar la clase del item desde el nombre del script
                    $item_class_name = str_replace(['item_', '.form.php'], '', $script_name);
                    $item_class_name = str_replace('_', '', ucwords($item_class_name, '_'));
                    $item_class = 'Item_' . $item_class_name;
                    
                    if (!class_exists($item_class)) {
                        // Intentar con el nombre completo
                        $item_class = 'Item_Disk';
                    }
                    
                    if (class_exists($item_class)) {
                        $item = new $item_class();
                        if ($item->getFromDB($decrypted_item_id)) {
                            if (isset($item->fields['items_id']) && isset($item->fields['itemtype'])) {
                                $items_id = (int)$item->fields['items_id'];
                                $itemtype = $item->fields['itemtype'];
                                
                                // Guardar en el mapeo para futuras referencias
                                self::$item_relations[$decrypted_item_id] = [
                                    'items_id' => $items_id,
                                    'itemtype' => $itemtype
                                ];
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    if (defined('CODEXROUTE_LOG_SECURITY') && CODEXROUTE_LOG_SECURITY) {
                        error_log(sprintf(
                            '[CodexRoute] Error loading item for params: %s (Class: %s, ID: %s)',
                            $e->getMessage(),
                            $item_class ?? 'unknown',
                            $decrypted_item_id
                        ));
                    }
                }
            }
            
            // Si tenemos items_id y itemtype, agregarlos a la URL
            if ($items_id !== null && $itemtype !== null) {
                // Encriptar items_id si es necesario
                if (class_exists('GlpiPlugin\Codexroute\IDEncryption')) {
                    if (isset(self::$encrypted_id_map[$items_id])) {
                        $encrypted_items_id = self::$encrypted_id_map[$items_id];
                    } else {
                        $encrypted_items_id = IDEncryption::encrypt($items_id);
                        if ($encrypted_items_id !== false && $encrypted_items_id !== (string)$items_id) {
                            self::$encrypted_id_map[$items_id] = $encrypted_items_id;
                        } else {
                            $encrypted_items_id = (string)$items_id;
                        }
                    }
                } else {
                    $encrypted_items_id = (string)$items_id;
                }
                
                // Agregar parámetros faltantes
                $params['items_id'] = $encrypted_items_id;
                $params['itemtype'] = $itemtype;
                
                // Reconstruir URL
                $new_query = http_build_query($params);
                $new_url = $url_parts['path'];
                if (!empty($new_query)) {
                    $new_url .= '?' . $new_query;
                }
                if (isset($url_parts['fragment'])) {
                    $new_url .= '#' . $url_parts['fragment'];
                }
                
                if (defined('CODEXROUTE_LOG_SECURITY') && CODEXROUTE_LOG_SECURITY) {
                    error_log(sprintf(
                        '[CodexRoute] Added missing params to URL: %s -> %s',
                        $url,
                        $new_url
                    ));
                }
                
                return $new_url;
            }
        }
        
        return $url;
    }

    /**
     * Encripta IDs numéricos en una URL usando el mapeo guardado o encriptándolos sobre la marcha
     */
    private static function encryptIdsInUrl(string $url): string
    {
        // Lista completa de parámetros de ID que deben ser procesados
        $id_params = [
            'id', 'docid', 'itemid', 'items_id', 'notificationtemplates_id',
            'computers_id', 'monitors_id', 
            'printers_id', 'phones_id', 'peripherals_id', 'networks_id',
            'tickets_id', 'users_id', 'entities_id', 'locations_id', 'states_id',
            'groups_id', 'profiles_id', 'manufacturers_id', 'suppliers_id',
            'contacts_id', 'contracts_id', 'licenses_id', 'cartridges_id',
            'consumables_id', 'changes_id', 'problems_id', 'projects_id'
        ];

        // Primero, intentar usar el mapeo guardado
        if (!empty(self::$encrypted_id_map)) {
            foreach (self::$encrypted_id_map as $numeric_id => $encrypted_id) {
                // Reemplazar cada parámetro de ID
                foreach ($id_params as $param_name) {
                    // Reemplazar ?param=NUMERO o &param=NUMERO
                    $url = preg_replace(
                        '/([?&])' . preg_quote($param_name, '/') . '=' . preg_quote((string)$numeric_id, '/') . '([^&"\'#]*)/',
                        '$1' . $param_name . '=' . $encrypted_id . '$2',
                        $url
                    );
                }
            }
        }

        // Si aún hay IDs numéricos en la URL, encriptarlos sobre la marcha
        if (class_exists('GlpiPlugin\Codexroute\IDEncryption')) {
            foreach ($id_params as $param_name) {
                // Buscar ?param=NUMERO o &param=NUMERO en la URL
                if (preg_match('/([?&])' . preg_quote($param_name, '/') . '=(\d{1,10})([^&"\'#]*)/', $url, $matches)) {
                    $numeric_id = (int)$matches[2];
                    if ($numeric_id > 0) {
                        // Verificar si ya está encriptado (no numérico o ya en el mapeo)
                        if (isset(self::$encrypted_id_map[$numeric_id])) {
                            // Ya está en el mapeo, usar el valor encriptado
                            $encrypted_id = self::$encrypted_id_map[$numeric_id];
                            $url = preg_replace(
                                '/([?&])' . preg_quote($param_name, '/') . '=' . preg_quote((string)$numeric_id, '/') . '([^&"\'#]*)/',
                                '$1' . $param_name . '=' . $encrypted_id . '$2',
                                $url
                            );
                            error_log(sprintf(
                                '[CodexRoute] Using cached encrypted ID %s for param %s in URL',
                                $numeric_id,
                                $param_name
                            ));
                            continue;
                        }
                        
                        // Encriptar el ID sobre la marcha
                        error_log(sprintf(
                            '[CodexRoute] Encrypting ID %s on-the-fly for param %s in URL: %s',
                            $numeric_id,
                            $param_name,
                            $url
                        ));
                        
                        $encrypted_id = IDEncryption::encrypt($numeric_id);
                        if ($encrypted_id !== false && $encrypted_id !== (string)$numeric_id && strlen($encrypted_id) <= 100) {
                            // Guardar en el mapeo para futuras referencias
                            self::$encrypted_id_map[$numeric_id] = $encrypted_id;
                            
                            // Reemplazar en la URL
                            $old_url = $url;
                            $url = preg_replace(
                                '/([?&])' . preg_quote($param_name, '/') . '=' . preg_quote((string)$numeric_id, '/') . '([^&"\'#]*)/',
                                '$1' . $param_name . '=' . $encrypted_id . '$2',
                                $url
                            );
                            
                            error_log(sprintf(
                                '[CodexRoute] Encrypted ID %s in redirect URL on-the-fly (Param: %s, URL: %s -> %s)',
                                $numeric_id,
                                $param_name,
                                $old_url,
                                $url
                            ));
                        } else {
                            error_log(sprintf(
                                '[CodexRoute] ERROR: Failed to encrypt ID %s (Param: %s, Result: %s, Length: %d)',
                                $numeric_id,
                                $param_name,
                                $encrypted_id === false ? 'false' : ($encrypted_id === (string)$numeric_id ? 'same' : $encrypted_id),
                                $encrypted_id !== false ? strlen($encrypted_id) : 0
                            ));
                        }
                    }
                }
            }
        }

        return $url;
    }

    /**
     * Lee el archivo de rutas permitidas en formato JSON (fallback cuando Config no está disponible).
     */
    private static function readAllowedRoutesJson(): array
    {
        $config_dir = GLPI_CONFIG_DIR ?? (GLPI_ROOT . '/config');

        $json_file = $config_dir . '/codexroute/allowed_routes.json';
        if (file_exists($json_file)) {
            $content = @file_get_contents($json_file);
            if ($content !== false && $content !== '') {
                $routes = json_decode($content, true);
                return is_array($routes) ? $routes : [];
            }
        }

        $php_file = $config_dir . '/codexroute/allowed_routes.php';
        if (file_exists($php_file)) {
            $routes = @include $php_file;
            return is_array($routes) ? $routes : [];
        }

        return [];
    }

    /**
     * Registra en un archivo JSON las rutas que generaron un error 403.
     * Evita duplicados y limita el log a 200 entradas únicas.
     */
    public static function logBlockedRoute(string $script_name): void
    {
        if (empty($script_name)) {
            return;
        }

        $file = basename($script_name);
        if (empty($file)) {
            return;
        }

        $config_dir = GLPI_CONFIG_DIR ?? (GLPI_ROOT . '/config');
        $log_file   = $config_dir . '/codexroute/blocked_routes.json';
        $log_dir    = dirname($log_file);

        if (!is_dir($log_dir)) {
            @mkdir($log_dir, 0755, true);
        }

        $fp = @fopen($log_file, 'c+');
        if (!$fp) {
            return;
        }

        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return;
        }

        $content  = stream_get_contents($fp);
        $log      = (!empty($content)) ? (json_decode($content, true) ?? []) : [];
        $existing = array_column($log, 'file');

        if (!in_array($file, $existing, true)) {
            $log[] = [
                'file'      => $file,
                'path'      => $script_name,
                'timestamp' => time(),
                'detected'  => true,
            ];

            if (count($log) > 200) {
                $log = array_slice($log, -200);
            }

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($log, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }

        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

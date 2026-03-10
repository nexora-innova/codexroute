<?php

namespace GlpiPlugin\Codexroute;

class LinkEncryptor
{

    private static $initialized = false;
    private static $output_buffer_started = false;

    public static function initialize(): void
    {
        if (self::$initialized) {
            return;
        }

        if (isCommandLine()) {
            return;
        }

        $script_name = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '';
        if (strpos($script_name, '/plugins/codexroute/ajax/') !== false) {
            return;
        }

        $config_dir = GLPI_CONFIG_DIR ?? (GLPI_ROOT . '/config');
        $config_file = $config_dir . '/codexroute/encryption_config.php';

        if (file_exists($config_file)) {
            include_once($config_file);
        }

        self::$initialized = true;
        self::startOutputBuffering();
    }

    private static function startOutputBuffering(): void
    {
        if (self::$output_buffer_started) {
            return;
        }

        if (ob_get_level() === 0) {
            ob_start([self::class, 'processOutput']);
            self::$output_buffer_started = true;
        } else {
            ob_start([self::class, 'processOutput']);
            self::$output_buffer_started = true;
        }
    }

    public static function processOutput(string $buffer): string
    {
        // Salida temprana si el buffer es muy pequeño (probablemente no es HTML completo)
        if (strlen($buffer) < 100) {
            return $buffer;
        }

        $trimmed = trim($buffer);
        if ($trimmed !== '' && (($trimmed[0] === '{' || $trimmed[0] === '[') && json_decode($trimmed) !== null)) {
            return $buffer;
        }

        // No procesar si el Content-Type es application/json
        $headers = headers_list();
        foreach ($headers as $header) {
            if (stripos($header, 'Content-Type:') === 0 && stripos($header, 'application/json') !== false) {
                return $buffer;
            }
        }

        if (!class_exists('GlpiPlugin\Codexroute\IDEncryption')) {
            return $buffer;
        }

        $config_dir = GLPI_CONFIG_DIR ?? (GLPI_ROOT . '/config');
        $config_file = $config_dir . '/codexroute/encryption_config.php';

        if (file_exists($config_file)) {
            include_once($config_file);
        }

        $routes_file = $config_dir . '/codexroute/allowed_routes.php';
        $allowed_routes = [];

        if (file_exists($routes_file)) {
            $allowed_routes = include $routes_file;
            if (!is_array($allowed_routes)) {
                $allowed_routes = [];
            }
        }

        // Patrones mejorados para capturar IDs en URLs con diferentes formatos:
        // - device.form.php?id=1
        // - device.form.php?itemtype=DeviceBattery&id=1
        // - document.send.php?docid=2&items_id=1
        // - device.form.php?id=1&other=value
        // IMPORTANTE: Solo capturar IDs numéricos (\d+), NO IDs ya encriptados
        // El patrón \d+ asegura que solo capture números, no IDs encriptados
        $id_params = ['id', 'docid', 'itemid', 'items_id', 'notificationtemplates_id', 'computers_id', 'monitors_id', 'printers_id', 'phones_id', 'peripherals_id', 'networks_id'];

        $patterns = [];

        // Generar patrones para cada parámetro de ID
        foreach ($id_params as $param_name) {
            // Capturar ?param= o &param= en URLs .form.php (solo IDs numéricos, máximo 10 dígitos)
            $patterns[] = '/(href|action)=["\']([^"\']*\.form\.php[^"\']*[?&]' . preg_quote($param_name, '/') . '=)(\d{1,10})([^&"\']*)["\']/i';
            // Capturar ?param= o &param= en cualquier URL (solo IDs numéricos, máximo 10 dígitos)
            $patterns[] = '/(href|action)=["\']([^"\']*[?&]' . preg_quote($param_name, '/') . '=)(\d{1,10})([^&"\']*)["\']/i';
            // Capturar en JavaScript location.href o window.location (solo IDs numéricos, máximo 10 dígitos)
            $patterns[] = '/(location\.href|window\.location)=["\']([^"\']*[?&]' . preg_quote($param_name, '/') . '=)(\d{1,10})([^&"\']*)["\']/i';
        }
        
        // NOTA: Los patrones generales ya capturan items_id correctamente
        // Los patrones adicionales específicos pueden causar conflictos y HTML mal formado
        // Si necesitamos patrones adicionales, deben ser más específicos y procesarse al final

        // Procesar patrones en orden, evitando procesar el mismo enlace múltiples veces
        $processed_positions = [];
        
        foreach ($patterns as $pattern) {
            $buffer = preg_replace_callback($pattern, function ($matches) use ($allowed_routes, $id_params, &$processed_positions) {
                $attr = $matches[1];
                $url_base = $matches[2];
                $id = $matches[3];
                $url_suffix = $matches[4];
                
                $full_url = $url_base . $id . $url_suffix;
                
                // Verificar si ya procesamos esta posición para evitar duplicados
                $match_position = $matches[0];
                if (isset($processed_positions[$match_position])) {
                    return $matches[0]; // Ya procesado, no modificar
                }
                
                // Log para debugging (para items_id y computers_id)
                if (strpos($url_base, 'items_id=') !== false || strpos($url_base, 'computers_id=') !== false) {
                    error_log(sprintf(
                        '[CodexRoute] LinkEncryptor: Processing URL with ID param: %s (ID: %s, Full match: %s)',
                        $full_url,
                        $id,
                        $match_position
                    ));
                }
                
                if (strpos($full_url, 'common.tabs.php') !== false) {
                    return $matches[0];
                }
                
                if (strpos($full_url, '_glpi_tab') !== false) {
                    return $matches[0];
                }

                $param_name = 'id';
                foreach ($id_params as $param) {
                    if (strpos($url_base, $param . '=') !== false) {
                        $param_name = $param;
                        break;
                    }
                }

                if (!is_numeric($id) || strlen($id) > 10) {
                    return $matches[0];
                }

                if ((int)$id <= 0) {
                    return $matches[0];
                }

                $url_parts = parse_url($url_base);
                $script_name = basename($url_parts['path'] ?? '');

                if (in_array($script_name, $allowed_routes)) {
                    return $matches[0];
                }

                if (class_exists('GlpiPlugin\Codexroute\IDEncryption')) {
                    $encrypted_id = IDEncryption::encrypt($id);

                    // Validar que el ID encriptado sea válido y no demasiado largo
                    if ($encrypted_id !== $id && $encrypted_id !== false && strlen($encrypted_id) <= 100) {
                        // Determinar el tipo de comilla usado en el atributo original
                        $quote_char = '"';
                        if (preg_match('/' . preg_quote($attr, '/') . '=(["\'])/', $matches[0], $quote_match)) {
                            $quote_char = $quote_match[1];
                        }
                        
                        // Reconstruir el atributo correctamente
                        // Asegurarse de que la URL esté completa y bien formada
                        $complete_url = $url_base . $encrypted_id . $url_suffix;
                        
                        // Verificar que la URL no esté vacía o mal formada
                        if (empty(trim($complete_url)) || strpos($complete_url, ' ') !== false) {
                            // URL contiene espacios o está vacía, no procesar para evitar HTML mal formado
                            return $matches[0];
                        }
                        
                        // Reconstruir el atributo HTML correctamente
                        $new_url = $attr . '=' . $quote_char . $complete_url . $quote_char;
                        
                        // Marcar como procesado
                        $processed_positions[$match_position] = true;
                        
                        // Log para debugging (solo para items_id)
                        if (strpos($url_base, 'items_id=') !== false || strpos($url_base, 'computers_id=') !== false) {
                            error_log(sprintf(
                                '[CodexRoute] LinkEncryptor: Encrypted ID param in URL: %s -> %s',
                                $full_url,
                                $complete_url
                            ));
                        }

                        // Verificar que no haya múltiples parámetros del mismo tipo en la URL resultante
                        $param_count = substr_count($new_url, $param_name . '=');
                        if ($param_count > 1) {
                            // Extraer la URL y reconstruirla sin duplicados
                            if (preg_match('/href=["\']([^"\']+)["\']/', $new_url, $url_matches)) {
                                $full_url = $url_matches[1];
                                $url_parts = parse_url($full_url);
                                if (isset($url_parts['query'])) {
                                    parse_str($url_parts['query'], $params);
                                    // Eliminar todos los parámetros duplicados y agregar solo uno
                                    unset($params[$param_name]);
                                    $params[$param_name] = $encrypted_id;
                                    $new_query = http_build_query($params);
                                    $new_url = $attr . '="' . $url_parts['path'] . '?' . $new_query . '"';
                                }
                            }
                        }

                        // Reemplazar el ID numérico con el encriptado, preservando el resto de la URL
                        return $new_url;
                    } elseif ($encrypted_id !== false && strlen($encrypted_id) > 100) {
                        // Rechazar IDs encriptados demasiado largos (posible duplicación)
                        return $matches[0];
                    }
                }

                return $matches[0];
            }, $buffer);
        }

        $buffer = self::injectJavaScript($buffer);

        return $buffer;
    }

    private static function injectJavaScript(string $buffer): string
    {
        if (strpos($buffer, '</body>') === false && strpos($buffer, '</html>') === false) {
            return $buffer;
        }

        // Verificar si el script ya fue inyectado para evitar duplicados
        if (strpos($buffer, 'codexrouteLinkEncryptorInitialized') !== false) {
            return $buffer;
        }

        $js_code = <<<'JS'
<script>
(function() {
    if (typeof window.codexrouteLinkEncryptorInitialized !== 'undefined') {
        return;
    }
    window.codexrouteLinkEncryptorInitialized = true;
    
    const ID_PARAMS = ['id', 'docid', 'itemid', 'items_id', 'notificationtemplates_id', 'computers_id', 'monitors_id', 'printers_id', 'phones_id', 'peripherals_id', 'networks_id'];
    
    function encryptIdInUrl(url, callback) {
        if (!url || typeof url !== 'string') {
            if (callback) callback(url);
            return url;
        }
        
        let paramName = null;
        let numericMatch = null;
        let hasEncrypted = false;
        
        for (let i = 0; i < ID_PARAMS.length; i++) {
            const param = ID_PARAMS[i];
            const paramRegex = new RegExp('[?&]' + param + '=([A-Za-z0-9_-]+)([^&"\']*)');
            const encryptedMatch = url.match(paramRegex);
            
                if (encryptedMatch) {
                const encryptedId = encryptedMatch[1];
                const isNumeric = /^\d+$/.test(encryptedId);
                const isValidLength = encryptedId.length >= 20 && encryptedId.length <= 100;
                
                if (!isNumeric && isValidLength) {
                    hasEncrypted = true;
                    if (callback) callback(url);
                    return url;
                }
                if (!isNumeric && encryptedId.length > 100) {
                    const truncatedId = encryptedId.substring(0, 80);
                    const newUrl = url.replace(new RegExp('([?&])' + param + '=[A-Za-z0-9_-]+'), '$1' + param + '=' + truncatedId);
                    if (callback) callback(newUrl);
                    return newUrl;
                }
                if (!isNumeric && encryptedId.length > 50) {
                    hasEncrypted = true;
                    if (callback) callback(url);
                    return url;
                }
            }
            
            if (!hasEncrypted) {
                const numericRegex = new RegExp('([?&])' + param + '=(\\d{1,10})([^&"\']*)');
                const match = url.match(numericRegex);
                
                if (match) {
                    paramName = param;
                    numericMatch = match;
                    break;
                }
            }
        }
        
        if (hasEncrypted) {
            if (callback) callback(url);
            return url;
        }
        
        if (!numericMatch || !paramName) {
            if (callback) callback(url);
            return url;
        }
        
        const id = numericMatch[2];
        if (!id || id === '0') {
            if (callback) callback(url);
            return url;
        }
        if (id.length > 80 || !/^\d+$/.test(id)) {
            if (callback) callback(url);
            return url;
        }
        
        function replaceAllNumericIdsInUrl(u, numId, encId) {
            let out = u;
            for (let j = 0; j < ID_PARAMS.length; j++) {
                const r = new RegExp('([?&])' + ID_PARAMS[j] + '=' + numId.replace(/[.*+?^${}()|[\]\\\\]/g, '\\\\$&') + '([^&"\']*)', 'g');
                out = out.replace(r, '$1' + ID_PARAMS[j] + '=' + encId + '$2');
            }
            return out;
        }
        
        if (window.codexrouteEncryptionCache && window.codexrouteEncryptionCache.has(id)) {
            const cachedEncrypted = window.codexrouteEncryptionCache.get(id);
            const newUrl = replaceAllNumericIdsInUrl(url, id, cachedEncrypted);
            if (callback) callback(newUrl);
            return newUrl;
        }
        
        const xhr = new XMLHttpRequest();
        
        let ajaxUrl = '/plugins/codexroute/ajax/encrypt_id.php';
        
        if (typeof GLPI_PLUGINS_PATH !== 'undefined' && GLPI_PLUGINS_PATH && GLPI_PLUGINS_PATH.codexroute) {
            ajaxUrl = GLPI_PLUGINS_PATH.codexroute + '/ajax/encrypt_id.php';
        } else if (typeof CFG_GLPI !== 'undefined' && CFG_GLPI && CFG_GLPI.root_doc) {
            const rootDoc = CFG_GLPI.root_doc;
            ajaxUrl = rootDoc + (rootDoc.endsWith('/') ? '' : '/') + 'plugins/codexroute/ajax/encrypt_id.php';
        } else {
            const currentPath = window.location.pathname;
            if (currentPath.indexOf('/front/') !== -1 || currentPath.indexOf('/ajax/') !== -1) {
                const pathParts = currentPath.split('/').filter(p => p && p !== 'front' && p !== 'ajax');
                if (pathParts.length > 0) {
                    const basePath = '/' + pathParts[0];
                    ajaxUrl = basePath + '/plugins/codexroute/ajax/encrypt_id.php';
                }
            }
        }
        
        if (!ajaxUrl.startsWith('/')) {
            ajaxUrl = '/' + ajaxUrl;
        }
        
        xhr.open('POST', ajaxUrl, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        
        const csrfToken = document.querySelector('[name="_glpi_csrf_token"]')?.value || '';
        const formData = 'id=' + encodeURIComponent(id) + '&_glpi_csrf_token=' + encodeURIComponent(csrfToken);
        
        xhr.onload = function() {
            if (xhr.status === 200) {
                if (!xhr.responseText || xhr.responseText.trim() === '') {
                    if (callback) callback(url);
                    return;
                }
                
                try {
                    const response = JSON.parse(xhr.responseText.trim());
                    
                    if (response.success && response.encrypted_id) {
                        if (response.already_encrypted) {
                            if (callback) callback(url);
                            return;
                        }
                        
                        if (response.encrypted_id.length > 100) {
                            if (callback) callback(url);
                            return;
                        }
                        
                        if (response.encrypted_id === id) {
                            if (callback) callback(url);
                            return;
                        }
                        
                        if (window.codexrouteEncryptionCache) {
                            window.codexrouteEncryptionCache.set(id, response.encrypted_id);
                        }
                        
                        const newUrl = replaceAllNumericIdsInUrl(url, id, response.encrypted_id);
                        
                        if (callback) callback(newUrl);
                    } else {
                        if (callback) callback(url);
                    }
                } catch(e) {
                    if (callback) callback(url);
                }
            } else {
                if (callback) callback(url);
            }
        };
        
        xhr.onerror = function() {
            if (callback) callback(url);
        };
        
        xhr.send(formData);
        
        return url;
    }
    
    // Variables globales para evitar redeclaraciones
    if (!window.codexrouteEncryptionCache) {
        window.codexrouteEncryptionCache = new Map();
    }
    if (!window.codexrouteProcessingLinks) {
        window.codexrouteProcessingLinks = false;
    }
    if (!window.codexrouteProcessedLinks) {
        window.codexrouteProcessedLinks = new WeakSet();
    }
    
    function shouldExcludeLink(url, element) {
        if (!url) return true;
        
        if (url.indexOf('common.tabs.php') !== -1) {
            return true;
        }
        
        if (url.indexOf('_glpi_tab') !== -1) {
            return true;
        }
        
        return false;
    }
    
    function hasNumericId(url) {
        for (let i = 0; i < ID_PARAMS.length; i++) {
            const param = ID_PARAMS[i];
            const match = url.match(new RegExp('[?&]' + param + '=(\\d{1,10})([^&"\']*)'));
            if (match) {
                return { param: param, id: match[1] };
            }
        }
        return null;
    }
    
    function hasEncryptedId(url) {
        for (let i = 0; i < ID_PARAMS.length; i++) {
            const param = ID_PARAMS[i];
            const match = url.match(new RegExp('[?&]' + param + '=([A-Za-z0-9_-]+)([^&"\']*)'));
            if (match) {
                const id = match[1];
                if (!/^\d+$/.test(id) && id.length >= 20 && id.length <= 100) {
                    return true;
                }
            }
        }
        return false;
    }
    
    function processAllLinks() {
        if (window.codexrouteProcessingLinks) {
            return;
        }
        window.codexrouteProcessingLinks = true;
        
        const links = document.querySelectorAll('a[href], form[action]');
        let processed = 0;
        const total = links.length;
        
        function processNext() {
            if (processed >= total) {
                window.codexrouteProcessingLinks = false;
                return;
            }
            
            const batchSize = 10;
            let batchProcessed = 0;
            
            while (batchProcessed < batchSize && processed < total) {
                const element = links[processed++];
                batchProcessed++;
                
                if (window.codexrouteProcessedLinks.has(element)) {
                    continue;
                }
                
                const attr = element.tagName === 'A' ? 'href' : 'action';
                const url = element.getAttribute(attr);
                
                if (!url || shouldExcludeLink(url, element)) {
                    window.codexrouteProcessedLinks.add(element);
                    continue;
                }
                
                if (hasEncryptedId(url)) {
                    window.codexrouteProcessedLinks.add(element);
                    continue;
                }
                
                const numericId = hasNumericId(url);
                if (numericId) {
                    encryptIdInUrl(url, function(encryptedUrl) {
                        if (encryptedUrl !== url && encryptedUrl.length < 500) {
                            element.setAttribute(attr, encryptedUrl);
                            if (element.tagName === 'A') {
                                element.href = encryptedUrl;
                            }
                        }
                        window.codexrouteProcessedLinks.add(element);
                    });
                } else {
                    window.codexrouteProcessedLinks.add(element);
                }
            }
            
            if (processed < total) {
                setTimeout(processNext, 10);
            } else {
                window.codexrouteProcessingLinks = false;
            }
        }
        
        processNext();
    }
    
    document.addEventListener('DOMContentLoaded', function() {
        // Esperar un poco para que la página se renderice antes de procesar enlaces
        setTimeout(function() {
            processAllLinks();
        }, 100);
        
        // Observar cambios dinámicos en el DOM (para contenido cargado vía AJAX)
        // Usar debounce para evitar procesamiento excesivo
        let observerTimeout = null;
        const observer = new MutationObserver(function(mutations) {
            // Debounce: esperar 100ms antes de procesar
            if (observerTimeout) {
                clearTimeout(observerTimeout);
            }
            observerTimeout = setTimeout(function() {
                processAllLinks();
            }, 100);
        });
        
        observer.observe(document.body, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['href', 'action']
        });
        
        // También procesar enlaces cuando se cambian atributos href/action
        // Usar debounce para evitar procesamiento excesivo y conflictos
        let attributeObserverTimeout = null;
        const attributeObserver = new MutationObserver(function(mutations) {
            // Debounce: esperar 200ms antes de procesar para evitar conflictos
            if (attributeObserverTimeout) {
                clearTimeout(attributeObserverTimeout);
            }
            attributeObserverTimeout = setTimeout(function() {
                try {
                    mutations.forEach(function(mutation) {
                        if (mutation.type === 'attributes' && (mutation.attributeName === 'href' || mutation.attributeName === 'action')) {
                            const element = mutation.target;
                            // Solo procesar elementos <a> o <form>, evitar otros elementos
                            if (!element || (element.tagName !== 'A' && element.tagName !== 'FORM')) {
                                return;
                            }
                            const url = element.getAttribute(mutation.attributeName);
                            if (url && !shouldExcludeLink(url, element) && !hasEncryptedId(url)) {
                                const numericId = hasNumericId(url);
                                if (numericId) {
                                    encryptIdInUrl(url, function(encryptedUrl) {
                                        if (encryptedUrl !== url && encryptedUrl.length < 500) {
                                            try {
                                                element.setAttribute(mutation.attributeName, encryptedUrl);
                                                if (element.tagName === 'A') {
                                                    element.href = encryptedUrl;
                                                }
                                            } catch(err) {
                                                // Ignorar errores al modificar atributos para evitar conflictos
                                            }
                                        }
                                    });
                                }
                            }
                        }
                    });
                } catch(err) {
                    // Ignorar errores en el observer para evitar conflictos con otras librerías
                }
            }, 200);
        });
        
        // Observar solo cuando el body esté disponible
        if (document.body) {
            attributeObserver.observe(document.body, {
                attributes: true,
                attributeFilter: ['href', 'action'],
                subtree: true
            });
        }
        
        // Interceptar clics en enlaces - usar capture phase para interceptar antes que otros handlers
        document.addEventListener('click', function(e) {
            const link = e.target.closest('a[href]');
            if (!link) {
                return;
            }
            
            let url = link.getAttribute('href');
            if (!url || url === '#' || url === 'javascript:void(0)' || url.indexOf('javascript:') === 0) {
                return;
            }
            
            if (shouldExcludeLink(url, link)) {
                return;
            }
            
            if (hasEncryptedId(url)) {
                return;
            }
            
            const numericId = hasNumericId(url);
            if (numericId) {
                const id = numericId.id;
                
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                
                const navigateToUrl = function(targetUrl) {
                    window.location.assign(targetUrl);
                };
                
                if (window.codexrouteEncryptionCache && window.codexrouteEncryptionCache.has(id)) {
                    const encryptedId = window.codexrouteEncryptionCache.get(id);
                    const encryptedUrl = replaceAllNumericIdsInUrl(url, id, encryptedId);
                    navigateToUrl(encryptedUrl);
                    return false;
                }
                
                encryptIdInUrl(url, function(encryptedUrl) {
                    if (encryptedUrl && encryptedUrl !== url) {
                        navigateToUrl(encryptedUrl);
                    } else {
                        navigateToUrl(url);
                    }
                });
                return false;
            }
        }, true); // true = use capture phase
        
        // NO interceptar formularios - dejar que GLPI maneje los formularios normalmente
        // El GlobalValidator ya maneja la encriptación/desencriptación de IDs en POST
    });
})();
</script>
JS;

        // Inyectar solo en el último </body> o </html> para evitar duplicados
        if (strpos($buffer, '</body>') !== false) {
            $lastBodyPos = strrpos($buffer, '</body>');
            return substr_replace($buffer, $js_code . '</body>', $lastBodyPos, strlen('</body>'));
        } elseif (strpos($buffer, '</html>') !== false) {
            $lastHtmlPos = strrpos($buffer, '</html>');
            return substr_replace($buffer, $js_code . '</html>', $lastHtmlPos, strlen('</html>'));
        }

        return $buffer;
    }
}

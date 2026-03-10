/**
 * CodexRoute - JavaScript del Panel de Administración y Encriptación de Enlaces
 */

(function() {
    'use strict';

    const AJAX_URL = '../ajax/config.php';

    // =====================================================================
    // MÓDULO DE ENCRIPTACIÓN DE ENLACES
    // =====================================================================

    const ID_PARAMS = [
        'id', 'docid', 'itemid', 'items_id', 'notificationtemplates_id',
        'computers_id', 'monitors_id', 'printers_id', 'phones_id',
        'peripherals_id', 'networks_id', 'tickets_id', 'users_id',
        'entities_id', 'knowbaseitems_id'
    ];

    if (typeof window.codexrouteLinkEncryptorInitialized === 'undefined' || !window.codexrouteLinkEncryptorInitialized) {
        window.codexrouteLinkEncryptorInitialized = true;

        if (!window.codexrouteEncryptionCache) {
            window.codexrouteEncryptionCache = new Map();
        }

        function getEncryptAjaxUrl() {
            if (typeof CFG_GLPI !== 'undefined' && CFG_GLPI && CFG_GLPI.root_doc !== undefined) {
                var root = CFG_GLPI.root_doc || '';
                return root + (root.endsWith('/') ? '' : '/') + 'plugins/codexroute/ajax/encrypt_id.php';
            }
            return '/plugins/codexroute/ajax/encrypt_id.php';
        }

        function isExcludedUrl(url) {
            if (!url) return true;
            if (url.indexOf('common.tabs.php') !== -1) return true;
            if (url.indexOf('_glpi_tab') !== -1) return true;
            if (url === '#' || url.indexOf('javascript:') === 0) return true;
            return false;
        }

        function findNumericIdParam(url) {
            for (var i = 0; i < ID_PARAMS.length; i++) {
                var p = ID_PARAMS[i];
                var re = new RegExp('[?&]' + p + '=(\\d{1,10})(?=&|$|#|"|\')', '');
                var m = url.match(re);
                if (m && parseInt(m[1], 10) > 0) {
                    return { param: p, id: m[1] };
                }
            }
            return null;
        }

        function urlHasEncryptedId(url) {
            for (var i = 0; i < ID_PARAMS.length; i++) {
                var re = new RegExp('[?&]' + ID_PARAMS[i] + '=([A-Za-z0-9_-]{16,100})(?=&|$|#)');
                var m = url.match(re);
                if (m && !/^\d+$/.test(m[1])) {
                    return true;
                }
            }
            return false;
        }

        function replaceAllSameId(url, numericId, encryptedId) {
            var result = url;
            for (var i = 0; i < ID_PARAMS.length; i++) {
                var re = new RegExp('([?&])' + ID_PARAMS[i] + '=' + numericId + '(?=&|$|#)', 'g');
                result = result.replace(re, '$1' + ID_PARAMS[i] + '=' + encryptedId);
            }
            return result;
        }

        function encryptIdAsync(numericId, callback) {
            if (window.codexrouteEncryptionCache.has(numericId)) {
                callback(window.codexrouteEncryptionCache.get(numericId));
                return;
            }

            var xhr = new XMLHttpRequest();
            xhr.open('POST', getEncryptAjaxUrl(), true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

            var csrfMeta = document.querySelector('meta[property="glpi:csrf_token"]');
            var csrfInput = document.querySelector('[name="_glpi_csrf_token"]');
            var csrf = (csrfMeta ? csrfMeta.content : '') || (csrfInput ? csrfInput.value : '');
            if (csrf) {
                xhr.setRequestHeader('X-Glpi-Csrf-Token', csrf);
            }
            var body = 'id=' + encodeURIComponent(numericId) + '&_glpi_csrf_token=' + encodeURIComponent(csrf);

            xhr.onload = function() {
                if (xhr.status === 200 && xhr.responseText) {
                    try {
                        var resp = JSON.parse(xhr.responseText.trim());
                        if (resp.success && resp.encrypted_id && !resp.already_encrypted &&
                            resp.encrypted_id !== numericId && resp.encrypted_id.length <= 100) {
                            window.codexrouteEncryptionCache.set(numericId, resp.encrypted_id);
                            callback(resp.encrypted_id);
                            return;
                        }
                    } catch(e) { /* ignora */ }
                }
                callback(null);
            };
            xhr.onerror = function() { callback(null); };
            xhr.send(body);
        }

        function processLinksOnPage() {
            var links = document.querySelectorAll('a[href]');
            for (var i = 0; i < links.length; i++) {
                (function(link) {
                    if (link.dataset.codexrouteProcessed) return;
                    link.dataset.codexrouteProcessed = '1';

                    var href = link.getAttribute('href');
                    if (isExcludedUrl(href) || urlHasEncryptedId(href)) return;

                    var found = findNumericIdParam(href);
                    if (!found) return;

                    encryptIdAsync(found.id, function(encId) {
                        if (encId) {
                            var currentHref = link.getAttribute('href');
                            var newHref = replaceAllSameId(currentHref, found.id, encId);
                            link.setAttribute('href', newHref);
                        }
                    });
                })(links[i]);
            }
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() {
                setTimeout(processLinksOnPage, 150);
                setupObserver();
            });
        } else {
            setTimeout(processLinksOnPage, 150);
            setupObserver();
        }

        function setupObserver() {
            var debounceTimer = null;
            var observer = new MutationObserver(function() {
                if (debounceTimer) clearTimeout(debounceTimer);
                debounceTimer = setTimeout(processLinksOnPage, 200);
            });

            var target = document.body || document.documentElement;
            if (target) {
                observer.observe(target, { childList: true, subtree: true });
            }
        }

        document.addEventListener('click', function(e) {
            var link = e.target.closest ? e.target.closest('a[href]') : null;
            if (!link) return;

            var href = link.getAttribute('href');
            if (isExcludedUrl(href) || urlHasEncryptedId(href)) return;

            var found = findNumericIdParam(href);
            if (!found) return;

            e.preventDefault();
            e.stopPropagation();

            encryptIdAsync(found.id, function(encId) {
                if (encId) {
                    var newHref = replaceAllSameId(href, found.id, encId);
                    window.location.assign(newHref);
                } else {
                    window.location.assign(href);
                }
            });
        }, true);
    }

    function showLoader(text) {
        const loader = document.getElementById('codexroute-loader');
        const loaderText = document.getElementById('codexroute-loader-text');
        if (loader && loaderText) {
            loaderText.textContent = text || 'Procesando...';
            loader.style.display = 'flex';
        }
    }

    function hideLoader() {
        const loader = document.getElementById('codexroute-loader');
        if (loader) {
            loader.style.display = 'none';
        }
    }

    function showMessage(title, message, type) {
        // Usar las funciones de toast de GLPI directamente
        const options = {
            delay: 5000,
            animated: true
        };
        
        switch(type) {
            case 'success':
                if (typeof glpi_toast_success === 'function') {
                    glpi_toast_success(message, title, options);
                } else if (typeof glpi_toast === 'function') {
                    glpi_toast(title, message, 'bg-success text-white border-0', options);
                }
                break;
                
            case 'error':
                if (typeof glpi_toast_error === 'function') {
                    glpi_toast_error(message, title, options);
                } else if (typeof glpi_toast === 'function') {
                    glpi_toast(title, message, 'bg-danger text-white border-0', options);
                }
                break;
                
            case 'warning':
                if (typeof glpi_toast_warning === 'function') {
                    glpi_toast_warning(message, title, options);
                } else if (typeof glpi_toast === 'function') {
                    glpi_toast(title, message, 'bg-warning text-white border-0', options);
                }
                break;
                
            case 'info':
            default:
                if (typeof glpi_toast_info === 'function') {
                    glpi_toast_info(message, title, options);
                } else if (typeof glpi_toast === 'function') {
                    glpi_toast(title, message, 'bg-info text-white border-0', options);
                }
                break;
        }
    }

    function getCsrfToken() {
        const meta = document.querySelector('meta[property="glpi:csrf_token"]');
        return meta ? meta.content : '';
    }

    function executeAction(action, params) {
        params = params || {};
        
        const actionTexts = {
            'analyze_apache': 'Analizando rendimiento Apache/PHP...',
            'analyze_database': 'Analizando base de datos...',
            'profile_sql': 'Ejecutando profiling SQL...',
            'analyze_views': 'Analizando vistas...',
            'analyze_mysql_config': 'Analizando configuración MySQL...'
        };
        
        showLoader(actionTexts[action] || 'Procesando...');
        
        const xhr = new XMLHttpRequest();
        xhr.open('POST', AJAX_URL, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        
        const csrfToken = getCsrfToken();
        if (csrfToken) {
            xhr.setRequestHeader('X-Glpi-Csrf-Token', csrfToken);
        }
        
        let formData = 'action=' + encodeURIComponent(action);
        if (csrfToken) {
            formData += '&_glpi_csrf_token=' + encodeURIComponent(csrfToken);
        }
        Object.keys(params).forEach(function(key) {
            formData += '&' + encodeURIComponent(key) + '=' + encodeURIComponent(params[key]);
        });
        
        xhr.onload = function() {
            hideLoader();
            if (xhr.status === 200) {
                try {
                    const data = JSON.parse(xhr.responseText);
                    handleActionResponse(action, data);
                } catch (e) {
                    console.error('Error parsing response:', e);
                    showMessage('Error', 'Error al procesar la respuesta del servidor', 'error');
                }
            } else {
                showMessage('Error', 'Error HTTP: ' + xhr.status, 'error');
            }
        };
        
        xhr.onerror = function() {
            hideLoader();
            showMessage('Error', 'Error de conexión al servidor', 'error');
        };
        
        xhr.send(formData);
    }

    function handleActionResponse(action, data) {
        if (!data.success) {
            showMessage('Error', data.message || 'Error desconocido', 'error');
            return;
        }
        
        switch(action) {
                
            case 'analyze_apache':
                displayApacheResults(data.results);
                break;
                
            case 'analyze_database':
            case 'profile_sql':
            case 'analyze_views':
                displayDatabaseResults(data.results);
                break;
                
            default:
                if (data.message) {
                    showMessage('Información', data.message, 'info');
                }
        }
    }

    function displayApacheResults(results) {
        const container = document.getElementById('apache-results-content');
        const resultsDiv = document.getElementById('apache-results');
        
        if (!container || !resultsDiv) return;
        
        resultsDiv.style.display = 'block';
        
        let html = '<div class="codexroute-stats-grid">';
        
        if (results.php) {
            html += '<div class="codexroute-stat-card">';
            html += '<div class="codexroute-stat-value">' + (results.php.performance * 1000).toFixed(2) + ' ms</div>';
            html += '<div class="codexroute-stat-label">Rendimiento PHP</div>';
            html += '</div>';
        }
        
        if (results.database) {
            html += '<div class="codexroute-stat-card">';
            html += '<div class="codexroute-stat-value">' + (results.database.connect_time * 1000).toFixed(2) + ' ms</div>';
            html += '<div class="codexroute-stat-label">Latencia BD</div>';
            html += '</div>';
        }
        
        html += '</div>';
        
        if (results.warnings && results.warnings.length > 0) {
            html += '<div class="codexroute-alert warning"><strong>Advertencias:</strong><ul>';
            results.warnings.forEach(function(warning) {
                html += '<li>' + escapeHtml(warning.component) + ': ' + escapeHtml(warning.issue) + '</li>';
            });
            html += '</ul></div>';
        } else {
            html += '<div class="codexroute-alert success"><strong>Sin advertencias.</strong> El rendimiento es óptimo.</div>';
        }
        
        container.innerHTML = html;
    }

    function displayDatabaseResults(results) {
        const container = document.getElementById('database-results-content');
        const resultsDiv = document.getElementById('database-results');
        
        if (!container || !resultsDiv) return;
        
        resultsDiv.style.display = 'block';
        
        let html = '';
        
        if (results.slow_queries && results.slow_queries.length > 0) {
            html += '<div class="codexroute-alert warning"><strong>Consultas Lentas Detectadas:</strong> ' + results.slow_queries.length + '</div>';
            html += '<div class="codexroute-table-container"><table class="codexroute-table"><thead><tr>';
            html += '<th>Tabla</th><th>Tiempo</th><th>Filas</th>';
            html += '</tr></thead><tbody>';
            
            results.slow_queries.forEach(function(q) {
                html += '<tr>';
                html += '<td><code>' + escapeHtml(q.table) + '</code></td>';
                html += '<td style="color: #dc3545;">' + (q.time * 1000).toFixed(2) + ' ms</td>';
                html += '<td>' + (q.rows || 0) + '</td>';
                html += '</tr>';
            });
            
            html += '</tbody></table></div>';
        } else {
            html += '<div class="codexroute-alert success">No se detectaron consultas lentas.</div>';
        }
        
        if (results.table_stats && results.table_stats.length > 0) {
            html += '<div class="card mb-3" style="margin-top: 20px;">';
            html += '<div class="card-header d-flex justify-content-between align-items-center">';
            html += '<h3 class="card-title mb-0"><i class="ti ti-table"></i> Estadísticas por Tabla</h3>';
            html += '<div class="d-flex gap-2">';
            html += '<div class="input-group input-group-sm" style="max-width: 250px;">';
            html += '<span class="input-group-text"><i class="ti ti-search"></i></span>';
            html += '<input type="text" class="form-control" id="table-stats-search" placeholder="Buscar tabla..." onkeyup="filterTableStats()">';
            html += '</div>';
            html += '</div>';
            html += '</div>';
            html += '<div class="card-body">';
            html += '<div class="table-responsive">';
            html += '<table class="table table-hover" id="table-stats-table">';
            html += '<thead><tr>';
            html += '<th style="width: 4%; min-width: 40px;">#</th>';
            html += '<th style="width: 40%;">Tabla</th>';
            html += '<th style="width: 18%;" class="text-center">Filas</th>';
            html += '<th style="width: 18%;" class="text-center">Tamaño (MB)</th>';
            html += '<th style="width: 20%; min-width: 120px;" class="text-center">Fragmentación (%)</th>';
            html += '</tr></thead><tbody>';
            
            results.table_stats.forEach(function(stat, index) {
                const fragPercent = parseFloat(stat.frag_percent) || 0;
                const fragColor = fragPercent > 10 ? '#dc3545' : (fragPercent > 5 ? '#ffc107' : '#28a745');
                const fragBadgeClass = fragPercent > 10 ? 'danger' : (fragPercent > 5 ? 'warning' : 'success');
                
                html += '<tr class="table-stat-row" data-table="' + escapeHtml(stat.table).toLowerCase() + '">';
                html += '<td class="text-muted">' + (index + 1) + '</td>';
                html += '<td>';
                html += '<div class="d-flex align-items-center">';
                html += '<i class="ti ti-database text-primary me-2"></i>';
                html += '<code class="text-primary">' + escapeHtml(stat.table) + '</code>';
                html += '</div>';
                html += '</td>';
                html += '<td class="text-center">';
                html += '<span class="fw-semibold">' + (stat.rows || 0).toLocaleString() + '</span>';
                html += '</td>';
                html += '<td class="text-center">';
                html += '<span class="fw-semibold">' + (parseFloat(stat.size_mb) || 0).toFixed(2) + '</span>';
                html += '</td>';
                html += '<td class="text-center" style="min-width: 120px;">';
                html += '<span class="badge text-white d-inline-flex align-items-center" style="background-color: ' + fragColor + '; white-space: nowrap; font-size: 0.75rem; padding: 0.4em 0.7em; font-weight: 500;">';
                html += '<i class="ti ti-' + (fragPercent > 10 ? 'alert-triangle' : (fragPercent > 5 ? 'alert-circle' : 'check')) + ' me-1" style="font-size: 0.85em;"></i>';
                html += '<span>' + fragPercent.toFixed(2) + '%</span>';
                html += '</span>';
                html += '</td>';
                html += '</tr>';
            });
            
            html += '</tbody></table>';
            html += '</div>';
            html += '<div class="mt-3 pt-3 border-top">';
            html += '<div class="d-flex flex-column flex-lg-row justify-content-between align-items-start align-items-lg-center gap-3">';
            html += '<div class="flex-grow-1" style="min-width: 0; writing-mode: horizontal-tb !important; text-orientation: mixed !important; direction: ltr !important;">';
            html += '<div class="text-muted small mb-1" id="table-stats-footer-text" style="writing-mode: horizontal-tb !important; text-orientation: mixed !important; direction: ltr !important; white-space: nowrap !important; display: inline-block !important; width: 100%; max-width: 100%; overflow: visible !important;">';
            html += '<span id="table-stats-count" style="display: inline !important; white-space: normal !important;">' + results.table_stats.length + '</span> ';
            html += '<span style="display: inline !important; white-space: normal !important;">de</span> ';
            html += '<strong style="display: inline !important; white-space: normal !important;">' + results.table_stats.length + '</strong> ';
            html += '<span style="display: inline !important; white-space: normal !important;">tablas</span>';
            html += '</div>';
            html += '<div id="table-stats-pagination-info" class="text-muted small" style="display: none; writing-mode: horizontal-tb !important; text-orientation: mixed !important; direction: ltr !important; white-space: nowrap !important; display: inline-block !important; width: 100%; max-width: 100%; overflow: visible !important;">';
            html += '<span style="display: inline !important; white-space: normal !important;">Mostrando</span> ';
            html += '<span id="table-stats-page-start" style="display: inline !important; white-space: normal !important;">1</span>';
            html += '<span style="display: inline !important; white-space: normal !important;">-</span>';
            html += '<span id="table-stats-page-end" style="display: inline !important; white-space: normal !important;">' + results.table_stats.length + '</span> ';
            html += '<span style="display: inline !important; white-space: normal !important;">de</span> ';
            html += '<strong id="table-stats-total-filtered" style="display: inline !important; white-space: normal !important;">' + results.table_stats.length + '</strong>';
            html += '</div>';
            html += '</div>';
            html += '<div class="flex-shrink-0" style="writing-mode: horizontal-tb !important; text-orientation: mixed !important; direction: ltr !important;">';
            html += '<nav aria-label="Paginación de estadísticas de tablas" style="writing-mode: horizontal-tb !important; text-orientation: mixed !important; direction: ltr !important;">';
            html += '<ul class="pagination pagination-sm mb-0" id="table-stats-pagination" style="writing-mode: horizontal-tb !important; text-orientation: mixed !important; direction: ltr !important;">';
            html += '</ul>';
            html += '</nav>';
            html += '</div>';
            html += '</div>';
            html += '</div>';
            html += '</div>';
            
            // Inicializar funciones de filtrado y paginación después de insertar el HTML
            setTimeout(function() {
                initializeTableStats();
            }, 100);
        }
        
        if (results.views && results.views.length > 0) {
            html += '<h4 style="margin-top: 20px;">Análisis de Vistas</h4>';
            html += '<div class="codexroute-table-container"><table class="codexroute-table"><thead><tr>';
            html += '<th>Vista</th><th>Tiempo</th><th>Filas</th>';
            html += '</tr></thead><tbody>';
            
            results.views.forEach(function(view) {
                const timeMs = view.total_time * 1000;
                const timeColor = timeMs > 5000 ? '#dc3545' : (timeMs > 1000 ? '#ffc107' : '#28a745');
                html += '<tr>';
                html += '<td>' + escapeHtml(view.name) + '</td>';
                html += '<td style="color: ' + timeColor + ';">' + timeMs.toFixed(2) + ' ms</td>';
                html += '<td>' + (view.rows || 0) + '</td>';
                html += '</tr>';
            });
            
            html += '</tbody></table></div>';
        }
        
        container.innerHTML = html;
    }

    // Funciones de validación eliminadas - ya no se necesitan con GlobalValidator
    // La validación ahora se hace globalmente a través de GlobalValidator::validate()

    function allowRoute(file) {
        if (typeof glpi_confirm === 'function') {
            glpi_confirm({
                title: __('Confirmar acción', 'codexroute'),
                message: __('¿Permitir la ruta:', 'codexroute') + ' <strong>' + escapeHtml(file) + '</strong>?',
                confirm_callback: function() {
                    executeAction('allow_route', { file: file });
                },
                confirm_label: __('Permitir', 'codexroute'),
                cancel_label: __('Cancelar', 'codexroute')
            });
        } else {
            if (confirm('¿Permitir la ruta: ' + file + '?')) {
                executeAction('allow_route', { file: file });
            }
        }
    }

    function blockRoute(file) {
        if (typeof glpi_confirm === 'function') {
            glpi_confirm({
                title: __('Confirmar acción', 'codexroute'),
                message: __('¿Bloquear la ruta:', 'codexroute') + ' <strong>' + escapeHtml(file) + '</strong>?',
                confirm_callback: function() {
                    executeAction('block_route', { file: file });
                },
                confirm_label: __('Bloquear', 'codexroute'),
                cancel_label: __('Cancelar', 'codexroute')
            });
        } else {
            if (confirm('¿Bloquear la ruta: ' + file + '?')) {
                executeAction('block_route', { file: file });
            }
        }
    }

    function optimizeTable(tableName, tableDb) {
        if (typeof glpi_confirm === 'function') {
            glpi_confirm({
                title: __('Confirmar optimización', 'codexroute'),
                message: __('¿Optimizar los índices de', 'codexroute') + ' <strong>' + escapeHtml(tableName) + '</strong>?<br><br>' + 
                         '<small class="text-muted">' + __('Esto puede tardar varios minutos.', 'codexroute') + '</small>',
                confirm_callback: function() {
                    executeAction('optimize_table_indexes', { table_name: tableName, table_db: tableDb });
                },
                confirm_label: __('Optimizar', 'codexroute'),
                cancel_label: __('Cancelar', 'codexroute')
            });
        } else {
            if (confirm('¿Optimizar los índices de ' + tableName + '?\n\nEsto puede tardar varios minutos.')) {
                executeAction('optimize_table_indexes', { table_name: tableName, table_db: tableDb });
            }
        }
    }

    function saveEncryptionConfig() {
        const form = document.getElementById('encryption-config-form');
        if (!form) return;
        
        const config = {};
        const inputs = form.querySelectorAll('input');
        
        inputs.forEach(function(input) {
            if (input.type === 'checkbox') {
                config[input.name] = input.checked;
            } else if (input.type === 'number') {
                config[input.name] = parseFloat(input.value) || parseInt(input.value) || 0;
            } else {
                config[input.name] = input.value;
            }
        });
        
        showLoader('Guardando configuración...');
        
        const xhr = new XMLHttpRequest();
        xhr.open('POST', AJAX_URL, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.setRequestHeader('Accept', 'application/json');
        
        const csrfToken = getCsrfToken();
        if (!csrfToken) {
            showMessage('Error', 'No se pudo obtener el token CSRF. Por favor, recarga la página.', 'error');
            hideLoader();
            return;
        }
        
        xhr.setRequestHeader('X-Glpi-Csrf-Token', csrfToken);
        
        let postData = 'action=save_encryption_config&config=' + encodeURIComponent(JSON.stringify(config));
        postData += '&_glpi_csrf_token=' + encodeURIComponent(csrfToken);
        
        xhr.onload = function() {
            hideLoader();
            if (xhr.status === 200) {
                try {
                    const data = JSON.parse(xhr.responseText);
                    if (data.success) {
                        showMessage('Éxito', 'Configuración guardada correctamente', 'success');
                        setTimeout(function() { location.reload(); }, 1500);
                    } else {
                        showMessage('Error', data.message || 'Error al guardar', 'error');
                    }
                } catch (e) {
                    console.error('Error parsing response:', e, xhr.responseText);
                    showMessage('Error', 'Error al procesar respuesta: ' + e.message, 'error');
                }
            } else if (xhr.status === 403) {
                try {
                    const data = JSON.parse(xhr.responseText);
                    showMessage('Error', data.message || 'Acceso denegado. Por favor, recarga la página.', 'error');
                } catch (e) {
                    showMessage('Error', 'Acceso denegado (403). Por favor, recarga la página e intenta de nuevo.', 'error');
                }
            } else {
                showMessage('Error', 'Error HTTP: ' + xhr.status, 'error');
            }
        };
        
        xhr.onerror = function() {
            hideLoader();
            showMessage('Error', 'Error de conexión al servidor', 'error');
        };
        
        xhr.send(postData);
    }

    function generateConfigFile() {
        const form = document.getElementById('encryption-config-form');
        if (!form) {
            showMessage('Error', 'No se encontró el formulario', 'error');
            return;
        }
        
        const config = {};
        const inputs = form.querySelectorAll('input[name]');
        
        // Capturar todos los campos con nombre
        inputs.forEach(function(input) {
            const name = input.name;
            if (!name) return; // Saltar campos sin nombre
            
            // Ignorar campos ocultos del sistema (CSRF, etc.)
            if (name.startsWith('_') || name === 'csrf_token' || name === '_glpi_csrf_token') {
                return;
            }
            
            if (input.type === 'checkbox') {
                config[name] = input.checked;
            } else if (input.type === 'number') {
                const value = input.value.trim();
                if (value === '') {
                    config[name] = 0;
                } else {
                    const numValue = parseFloat(value);
                    config[name] = isNaN(numValue) ? 0 : numValue;
                }
            } else if (input.type === 'text' || input.type === 'hidden') {
                config[name] = input.value || '';
            }
        });
        
        // Validar que tenemos al menos algunos campos
        if (Object.keys(config).length === 0) {
            showMessage('Error', 'No se encontraron campos de configuración', 'error');
            return;
        }
        
        // Validar JSON antes de enviar
        let configJson;
        try {
            configJson = JSON.stringify(config);
        } catch (e) {
            showMessage('Error', 'Error al serializar la configuración: ' + e.message, 'error');
            return;
        }
        
        showLoader('Generando archivo de configuración...');
        
        const xhr = new XMLHttpRequest();
        xhr.open('POST', AJAX_URL, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        
        const csrfToken = getCsrfToken();
        if (csrfToken) {
            xhr.setRequestHeader('X-Glpi-Csrf-Token', csrfToken);
        }
        
        let postData = 'action=generate_config_file&config=' + encodeURIComponent(configJson);
        if (csrfToken) {
            postData += '&_glpi_csrf_token=' + encodeURIComponent(csrfToken);
        }
        
        xhr.onerror = function() {
            hideLoader();
            showMessage('Error', 'Error de conexión al servidor', 'error');
        };
        
        xhr.onload = function() {
            hideLoader();
            if (xhr.status === 200) {
                try {
                    const responseText = xhr.responseText.trim();
                    if (!responseText) {
                        showMessage('Error', 'Respuesta vacía del servidor', 'error');
                        return;
                    }
                    
                    const data = JSON.parse(responseText);
                    if (data.success) {
                        showMessage('Éxito', 'Archivo de configuración generado correctamente', 'success');
                        // Actualizar el botón para mostrar que el archivo existe
                        const btnGenerate = document.getElementById('btn-generate-config');
                        if (btnGenerate) {
                            btnGenerate.innerHTML = '<i class="ti ti-file-code"></i> Regenerar Archivo de Configuración';
                        }
                        // Recargar después de 1.5 segundos para actualizar el estado
                        setTimeout(function() { location.reload(); }, 1500);
                    } else {
                        showMessage('Error', data.message || 'Error al generar archivo', 'error');
                    }
                } catch (e) {
                    console.error('Error parsing response:', e);
                    console.error('Response text:', xhr.responseText);
                    showMessage('Error', 'Error al procesar respuesta: ' + e.message + '. Respuesta: ' + xhr.responseText.substring(0, 100), 'error');
                }
            } else {
                showMessage('Error', 'Error HTTP: ' + xhr.status, 'error');
            }
        };
        
        xhr.send(postData);
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Funciones para Estadísticas por Tabla
    let tableStatsAllRows = [];
    let tableStatsCurrentPage = 1;
    const tableStatsItemsPerPage = 20;
    let tableStatsAllVisibleRows = [];
    let tableStatsInitialized = false;

    function initializeTableStats() {
        if (tableStatsInitialized) return;
        
        try {
            const table = document.querySelector('#table-stats-table');
            if (!table) {
                setTimeout(initializeTableStats, 100);
                return;
            }
            
            const tbody = table.querySelector('tbody');
            if (!tbody) {
                setTimeout(initializeTableStats, 100);
                return;
            }
            
            tableStatsAllRows = Array.from(tbody.querySelectorAll('.table-stat-row'));
            
            if (tableStatsAllRows.length === 0) {
                setTimeout(initializeTableStats, 200);
                return;
            }
            
            tableStatsInitialized = true;
            filterTableStats();
        } catch (error) {
            console.error('Error inicializando estadísticas de tablas:', error);
            setTimeout(initializeTableStats, 200);
        }
    }

    function filterTableStats() {
        if (!tableStatsInitialized) {
            initializeTableStats();
            return;
        }
        
        const searchInput = document.getElementById('table-stats-search');
        if (!searchInput) return;
        
        const searchTerm = searchInput.value.toLowerCase();
        
        requestAnimationFrame(() => {
            const tbody = document.querySelector('#table-stats-table tbody');
            if (tbody) {
                tbody.style.display = 'none';
            }
            
            tableStatsAllVisibleRows = [];
            
            for (let i = 0; i < tableStatsAllRows.length; i++) {
                const row = tableStatsAllRows[i];
                const table = (row.dataset.table || '').toLowerCase();
                
                const matchesSearch = searchTerm === '' || table.includes(searchTerm);
                
                if (matchesSearch) {
                    tableStatsAllVisibleRows.push(row);
                }
            }
            
            tableStatsCurrentPage = 1;
            updateTableStatsPagination();
            displayTableStatsPage();
            
            if (tbody) {
                tbody.style.display = '';
            }
        });
    }

    function displayTableStatsPage() {
        const start = (tableStatsCurrentPage - 1) * tableStatsItemsPerPage;
        const end = start + tableStatsItemsPerPage;
        
        requestAnimationFrame(() => {
            for (let i = 0; i < tableStatsAllVisibleRows.length; i++) {
                const row = tableStatsAllVisibleRows[i];
                if (i >= start && i < end) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            }
            
            const pageStart = tableStatsAllVisibleRows.length > 0 ? start + 1 : 0;
            const pageEnd = Math.min(end, tableStatsAllVisibleRows.length);
            
            const countEl = document.getElementById('table-stats-count');
            const startEl = document.getElementById('table-stats-page-start');
            const endEl = document.getElementById('table-stats-page-end');
            const totalEl = document.getElementById('table-stats-total-filtered');
            const infoEl = document.getElementById('table-stats-pagination-info');
            
            if (countEl) countEl.textContent = tableStatsAllVisibleRows.length;
            if (startEl) startEl.textContent = pageStart;
            if (endEl) endEl.textContent = pageEnd;
            if (totalEl) totalEl.textContent = tableStatsAllVisibleRows.length;
            
            if (infoEl) {
                if (tableStatsAllVisibleRows.length > tableStatsItemsPerPage) {
                    infoEl.style.display = 'block';
                } else {
                    infoEl.style.display = 'none';
                }
            }
        });
    }

    function updateTableStatsPagination() {
        const totalPages = Math.ceil(tableStatsAllVisibleRows.length / tableStatsItemsPerPage);
        const pagination = document.getElementById('table-stats-pagination');
        if (!pagination) return;
        
        pagination.innerHTML = '';
        
        if (totalPages <= 1) {
            return;
        }
        
        const prevLi = document.createElement('li');
        prevLi.className = `page-item ${tableStatsCurrentPage === 1 ? 'disabled' : ''}`;
        prevLi.innerHTML = `<a class="page-link" href="#" onclick="goToTableStatsPage(${tableStatsCurrentPage - 1}); return false;">
            <i class="ti ti-chevron-left"></i> <span class="d-none d-sm-inline">Anterior</span>
        </a>`;
        pagination.appendChild(prevLi);
        
        const maxVisible = 5;
        let startPage = Math.max(1, tableStatsCurrentPage - Math.floor(maxVisible / 2));
        let endPage = Math.min(totalPages, startPage + maxVisible - 1);
        
        if (endPage - startPage < maxVisible - 1) {
            startPage = Math.max(1, endPage - maxVisible + 1);
        }
        
        if (startPage > 1) {
            const firstLi = document.createElement('li');
            firstLi.className = 'page-item';
            firstLi.innerHTML = `<a class="page-link" href="#" onclick="goToTableStatsPage(1); return false;">1</a>`;
            pagination.appendChild(firstLi);
            
            if (startPage > 2) {
                const dotsLi = document.createElement('li');
                dotsLi.className = 'page-item disabled';
                dotsLi.innerHTML = `<span class="page-link">...</span>`;
                pagination.appendChild(dotsLi);
            }
        }
        
        for (let i = startPage; i <= endPage; i++) {
            const li = document.createElement('li');
            li.className = `page-item ${i === tableStatsCurrentPage ? 'active' : ''}`;
            li.innerHTML = `<a class="page-link" href="#" onclick="goToTableStatsPage(${i}); return false;">${i}</a>`;
            pagination.appendChild(li);
        }
        
        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                const dotsLi = document.createElement('li');
                dotsLi.className = 'page-item disabled';
                dotsLi.innerHTML = `<span class="page-link">...</span>`;
                pagination.appendChild(dotsLi);
            }
            
            const lastLi = document.createElement('li');
            lastLi.className = 'page-item';
            lastLi.innerHTML = `<a class="page-link" href="#" onclick="goToTableStatsPage(${totalPages}); return false;">${totalPages}</a>`;
            pagination.appendChild(lastLi);
        }
        
        const nextLi = document.createElement('li');
        nextLi.className = `page-item ${tableStatsCurrentPage === totalPages ? 'disabled' : ''}`;
        nextLi.innerHTML = `<a class="page-link" href="#" onclick="goToTableStatsPage(${tableStatsCurrentPage + 1}); return false;">
            <span class="d-none d-sm-inline">Siguiente</span> <i class="ti ti-chevron-right"></i>
        </a>`;
        pagination.appendChild(nextLi);
    }

    function goToTableStatsPage(page) {
        const totalPages = Math.ceil(tableStatsAllVisibleRows.length / tableStatsItemsPerPage);
        if (page < 1 || page > totalPages) return;
        tableStatsCurrentPage = page;
        displayTableStatsPage();
        updateTableStatsPagination();
    }

    window.executeAction = executeAction;
    window.allowRoute = allowRoute;
    window.blockRoute = blockRoute;
    window.optimizeTable = optimizeTable;
    window.saveEncryptionConfig = saveEncryptionConfig;
    window.generateConfigFile = generateConfigFile;
    window.showLoader = showLoader;
    window.hideLoader = hideLoader;
    window.filterTableStats = filterTableStats;
    window.goToTableStatsPage = goToTableStatsPage;

})();


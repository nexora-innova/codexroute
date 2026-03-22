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

    let codexrouteLoaderTriggerButton = null;

    function codexrouteResolveTriggerButton(el) {
        if (!el || el.nodeType !== 1) {
            return null;
        }
        if (el.tagName === 'BUTTON' || (el.tagName === 'INPUT' && el.type === 'submit')) {
            return el;
        }
        return el.closest('button');
    }

    function codexrouteApplyTriggerButtonLoading(el) {
        const b = codexrouteResolveTriggerButton(el);
        if (!b || b.dataset.codexrouteBtnLoading === '1') {
            return null;
        }
        b.dataset.codexrouteBtnLoading = '1';
        b.dataset.codexrouteBtnHtml = b.innerHTML;
        b.setAttribute('aria-busy', 'true');
        b.disabled = true;
        const spin = document.createElement('span');
        spin.className = 'spinner-border spinner-border-sm me-2 align-middle';
        spin.setAttribute('role', 'status');
        spin.setAttribute('aria-hidden', 'true');
        b.insertBefore(spin, b.firstChild);
        return b;
    }

    function codexrouteRestoreTriggerButton(b) {
        if (!b || b.dataset.codexrouteBtnLoading !== '1') {
            return;
        }
        b.disabled = false;
        b.removeAttribute('aria-busy');
        if (b.dataset.codexrouteBtnHtml !== undefined) {
            b.innerHTML = b.dataset.codexrouteBtnHtml;
            delete b.dataset.codexrouteBtnHtml;
        }
        delete b.dataset.codexrouteBtnLoading;
    }

    function showLoader(opts) {
        if (typeof opts === 'string') {
            opts = {};
        }
        opts = opts || {};
        hideLoader();
        let btn = opts.triggerButton ? codexrouteResolveTriggerButton(opts.triggerButton) : null;
        if (!btn && opts.triggerButtonId) {
            btn = document.getElementById(opts.triggerButtonId);
        }
        if (btn) {
            codexrouteLoaderTriggerButton = codexrouteApplyTriggerButtonLoading(btn);
        }
    }

    function hideLoader() {
        if (codexrouteLoaderTriggerButton) {
            codexrouteRestoreTriggerButton(codexrouteLoaderTriggerButton);
            codexrouteLoaderTriggerButton = null;
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

    function executeAction(action, params, triggerButton) {
        params = params || {};
        
        const csrfToken = getCsrfToken();
        const actionsRequiringCsrf = ['optimize_table_indexes'];
        if (actionsRequiringCsrf.indexOf(action) !== -1 && !csrfToken) {
            showMessage('Error', __('No se pudo obtener el token CSRF. Por favor, recarga la página.', 'codexroute'), 'error');
            return;
        }
        
        showLoader({ triggerButton: triggerButton });
        
        const xhr = new XMLHttpRequest();
        xhr.open('POST', AJAX_URL, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.setRequestHeader('Accept', 'application/json, text/html, */*');
        
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

            case 'allow_route':
            case 'block_route':
            case 'allow_all_blocked_routes':
                showMessage('Éxito', data.message || 'Operación completada', 'success');
                setTimeout(function() {
                    window.location.href = window.location.pathname + '?tab=routes';
                }, 900);
                break;

            case 'scan_routes':
                showMessage('Éxito', 'Análisis completado', 'success');
                setTimeout(function() {
                    window.location.href = window.location.pathname + '?tab=routes';
                }, 900);
                break;
                
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

        resultsDiv.classList.remove('d-none');

        let html = '<div class="table-responsive-md border-bottom">';
        html += '<table class="table table-hover table-card mb-0"><tbody>';

        if (results.php) {
            html += '<tr><th class="w-25 text-muted fw-normal">Rendimiento PHP</th><td>' +
                (results.php.performance * 1000).toFixed(2) + ' ms</td></tr>';
        }

        if (results.database) {
            html += '<tr><th class="text-muted fw-normal">Latencia BD</th><td>' +
                (results.database.connect_time * 1000).toFixed(2) + ' ms</td></tr>';
        }

        html += '</tbody></table></div>';

        if (results.warnings && results.warnings.length > 0) {
            html += '<div class="alert alert-warning m-3" role="alert"><strong>Advertencias:</strong><ul class="mb-0 mt-2">';
            results.warnings.forEach(function(warning) {
                html += '<li>' + escapeHtml(warning.component) + ': ' + escapeHtml(warning.issue) + '</li>';
            });
            html += '</ul></div>';
        } else {
            html += '<div class="alert alert-success m-3" role="alert">' +
                '<strong>Sin advertencias.</strong> El rendimiento es óptimo.</div>';
        }

        container.innerHTML = html;
    }

    function displayDatabaseResults(results) {
        const container = document.getElementById('database-results-content');
        const resultsDiv = document.getElementById('database-results');

        if (!container || !resultsDiv) return;

        resultsDiv.classList.remove('d-none');

        let html = '';

        if (results.slow_queries && results.slow_queries.length > 0) {
            html += '<div class="alert alert-warning mx-3 mt-3 mb-0" role="alert"><strong>Consultas lentas:</strong> ' +
                results.slow_queries.length + '</div>';
            html += '<div class="search_page codexroute-embedded-search mx-3 mb-3"><div class="search-container disable-overflow-y">';
            html += '<div class="card card-sm mt-0 search-card"><div class="card-header d-flex justify-content-between search-header pe-0 py-2">';
            html += '<h3 class="card-title mb-0 fs-5">Consultas lentas</h3></div>';
            html += '<div class="table-responsive-lg"><table class="search-results table card-table table-hover table-striped mb-0"><thead><tr>';
            html += '<th>Tabla</th><th>Tiempo</th><th>Filas</th>';
            html += '</tr></thead><tbody>';

            results.slow_queries.forEach(function(q) {
                html += '<tr>';
                html += '<td><span class="text-break">' + escapeHtml(q.table) + '</span></td>';
                html += '<td class="text-danger fw-semibold">' + (q.time * 1000).toFixed(2) + ' ms</td>';
                html += '<td>' + (q.rows || 0) + '</td>';
                html += '</tr>';
            });

            html += '</tbody></table></div></div></div></div>';
        } else {
            html += '<div class="alert alert-success m-3" role="alert">No se detectaron consultas lentas.</div>';
        }

        if (results.table_stats && results.table_stats.length > 0) {
            tableStatsInitialized = false;
            tableStatsAllRows = [];
            const listLimitRaw = parseInt(window.codexrouteGlpiListLimit, 10);
            const listLimit = Number.isFinite(listLimitRaw) && listLimitRaw > 0 ? listLimitRaw : 20;
            const statsPagerInner = typeof window.codexrouteBuildSearchPagerInnerHtml === 'function'
                ? window.codexrouteBuildSearchPagerInnerHtml(
                    'codexroute-table-stats',
                    'codexroute-limit-table-stats',
                    listLimit,
                    'table-stats-pagination'
                )
                : '';

            html += '<div class="search_page codexroute-embedded-search mx-3 mb-3"><div class="search-container disable-overflow-y">';
            html += '<div class="card card-sm mt-0 search-card">';
            html += '<div class="card-header d-flex justify-content-between search-header pe-0 flex-wrap align-items-center gap-2 py-2">';
            html += '<h3 class="card-title mb-0 fs-5">Estadísticas por Tabla</h3>';
            html += '<div class="input-group input-group-sm flex-grow-1 flex-sm-grow-0 ms-lg-auto" style="min-width: 12rem; max-width: 100%;">';
            html += '<span class="input-group-text" aria-hidden="true"><i class="ti ti-search"></i></span>';
            html += '<input type="text" class="form-control" id="table-stats-search" placeholder="Buscar tabla..." onkeyup="filterTableStats()">';
            html += '</div></div>';
            html += '<div class="table-responsive-lg">';
            html += '<table class="search-results table card-table table-hover table-striped mb-0" id="table-stats-table">';
            html += '<thead><tr>';
            html += '<th style="width: 4%; min-width: 40px;">#</th>';
            html += '<th>Tabla</th>';
            html += '<th class="text-center">Filas</th>';
            html += '<th class="text-center">Tamaño (MB)</th>';
            html += '<th class="text-center">Fragmentación (%)</th>';
            html += '</tr></thead><tbody>';

            results.table_stats.forEach(function(stat, index) {
                const fragPercent = parseFloat(stat.frag_percent) || 0;
                const fragBg = fragPercent > 10 ? 'danger' : (fragPercent > 5 ? 'warning' : 'success');
                const fragBadgeExtra = fragPercent > 5 && fragPercent <= 10 ? ' text-dark' : '';
                const fragIcon = fragPercent > 10 ? 'alert-triangle' : (fragPercent > 5 ? 'alert-circle' : 'check');

                html += '<tr class="table-stat-row" data-table="' + escapeHtml(stat.table).toLowerCase() + '">';
                html += '<td class="text-muted">' + (index + 1) + '</td>';
                html += '<td><span class="text-break fw-medium">' + escapeHtml(stat.table) + '</span></td>';
                html += '<td class="text-center"><span class="fw-semibold">' + (stat.rows || 0).toLocaleString() + '</span></td>';
                html += '<td class="text-center"><span class="fw-semibold">' + (parseFloat(stat.size_mb) || 0).toFixed(2) + '</span></td>';
                html += '<td class="text-center text-nowrap">';
                html += '<span class="badge bg-' + fragBg + fragBadgeExtra + ' d-inline-flex align-items-center gap-1">';
                html += '<i class="ti ti-' + fragIcon + '"></i><span>' + fragPercent.toFixed(2) + '%</span></span>';
                html += '</td></tr>';
            });

            html += '</tbody></table></div>';
            html += '<div class="card-footer search-footer">' + statsPagerInner + '</div>';
            html += '</div></div></div>';

            setTimeout(function() {
                initializeTableStats();
            }, 100);
        }

        if (results.views && results.views.length > 0) {
            html += '<div class="search_page codexroute-embedded-search mx-3 mb-3"><div class="search-container disable-overflow-y">';
            html += '<div class="card card-sm mt-0 search-card"><div class="card-header d-flex justify-content-between search-header pe-0 py-2">';
            html += '<h3 class="card-title mb-0 fs-5">Análisis de Vistas</h3></div>';
            html += '<div class="table-responsive-lg"><table class="search-results table card-table table-hover table-striped mb-0"><thead><tr>';
            html += '<th>Vista</th><th>Tiempo</th><th>Filas</th>';
            html += '</tr></thead><tbody>';

            results.views.forEach(function(view) {
                const timeMs = view.total_time * 1000;
                const timeClass = timeMs > 5000 ? 'text-danger' : (timeMs > 1000 ? 'text-warning' : 'text-success');
                html += '<tr>';
                html += '<td><span class="text-break">' + escapeHtml(view.name) + '</span></td>';
                html += '<td class="fw-semibold ' + timeClass + '">' + timeMs.toFixed(2) + ' ms</td>';
                html += '<td>' + (view.rows || 0) + '</td>';
                html += '</tr>';
            });

            html += '</tbody></table></div></div></div></div>';
        }

        container.innerHTML = html;
    }

    // Funciones de validación eliminadas - ya no se necesitan con GlobalValidator
    // La validación ahora se hace globalmente a través de GlobalValidator::validate()

    function allowRoute(file, triggerButton) {
        if (!confirm('¿Permitir la ruta "' + file + '"?')) {
            return;
        }
        executeAction('allow_route', { file: file }, triggerButton);
    }

    function blockRoute(file, triggerButton) {
        if (!confirm('¿Bloquear/remover la ruta "' + file + '"?')) {
            return;
        }
        executeAction('block_route', { file: file }, triggerButton);
    }

    function scanRoutes(triggerButton) {
        executeAction('scan_routes', {}, triggerButton);
    }

    function allowAllBlockedRoutes(triggerButton) {
        if (!confirm('¿Permitir todas las rutas bloqueadas detectadas?')) {
            return;
        }
        executeAction('allow_all_blocked_routes', {}, triggerButton);
    }

    function optimizeTable(tableName, tableDb, triggerButton) {
        const run = function() {
            executeAction('optimize_table_indexes', { table_name: tableName, table_db: tableDb }, triggerButton);
        };
        if (typeof glpi_confirm === 'function') {
            glpi_confirm({
                id: 'codexroute_modal_confirm_optimize_table',
                title: __('Confirmar optimización', 'codexroute'),
                message: __('¿Optimizar los índices de', 'codexroute') + ' <strong>' + escapeHtml(tableName) + '</strong>?<br><br>' + 
                         '<small class="text-muted">' + __('Esto puede tardar varios minutos.', 'codexroute') + '</small>',
                confirm_callback: run,
                confirm_label: __('Optimizar', 'codexroute'),
                cancel_label: __('Cancelar', 'codexroute')
            });
        } else {
            if (confirm('¿Optimizar los índices de ' + tableName + '?\n\nEsto puede tardar varios minutos.')) {
                run();
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
        
        const csrfToken = getCsrfToken();
        if (!csrfToken) {
            showMessage('Error', 'No se pudo obtener el token CSRF. Por favor, recarga la página.', 'error');
            return;
        }

        showLoader({ triggerButtonId: 'btn-save-config' });

        const xhr = new XMLHttpRequest();
        xhr.open('POST', AJAX_URL, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.setRequestHeader('Accept', 'application/json');
        
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
        
        showLoader({ triggerButtonId: 'btn-generate-config' });
        
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
        const limit = typeof window.codexrouteGetPageSizeForTable === 'function'
            ? window.codexrouteGetPageSizeForTable('table-stats-table')
            : 20;
        const start = (tableStatsCurrentPage - 1) * limit;
        const end = start + limit;
        const total = tableStatsAllVisibleRows.length;

        requestAnimationFrame(() => {
            for (let i = 0; i < tableStatsAllVisibleRows.length; i++) {
                const row = tableStatsAllVisibleRows[i];
                row.style.display = (i >= start && i < end) ? '' : 'none';
            }

            if (typeof window.codexrouteFillPageInfos === 'function') {
                if (total === 0) {
                    window.codexrouteFillPageInfos('codexroute-table-stats', 0, 0, 0);
                } else {
                    const pageStart = start + 1;
                    const pageEnd = Math.min(end, total);
                    window.codexrouteFillPageInfos('codexroute-table-stats', pageStart, pageEnd, total);
                }
            }
        });
    }

    function updateTableStatsPagination() {
        if (typeof window.codexrouteGetPageSizeForTable !== 'function' ||
            typeof window.codexrouteRenderGlpiPager !== 'function') {
            return;
        }
        const limit = window.codexrouteGetPageSizeForTable('table-stats-table');
        const total = tableStatsAllVisibleRows.length;
        const totalPages = Math.max(1, Math.ceil(total / limit));
        if (tableStatsCurrentPage > totalPages) {
            tableStatsCurrentPage = totalPages;
        }
        window.codexrouteRenderGlpiPager(
            'table-stats-pagination',
            tableStatsCurrentPage,
            totalPages,
            limit,
            'goToTableStatsPage'
        );
    }

    function goToTableStatsPage(page) {
        const limit = typeof window.codexrouteGetPageSizeForTable === 'function'
            ? window.codexrouteGetPageSizeForTable('table-stats-table')
            : 20;
        const totalPages = Math.max(1, Math.ceil(tableStatsAllVisibleRows.length / limit));
        if (page < 1 || page > totalPages) {
            return;
        }
        tableStatsCurrentPage = page;
        displayTableStatsPage();
        updateTableStatsPagination();
    }

    function initCodexrouteDatabaseResultsLimitListener() {
        const wrap = document.getElementById('database-results');
        if (!wrap || wrap.dataset.codexrouteStatsLimitBound) {
            return;
        }
        wrap.dataset.codexrouteStatsLimitBound = '1';
        wrap.addEventListener('change', function(ev) {
            if (!ev.target.classList.contains('search-limit-dropdown')) {
                return;
            }
            const card = ev.target.closest('.search-card');
            if (!card || !card.querySelector('#table-stats-table')) {
                return;
            }
            const lim = parseInt(ev.target.value, 10) || 20;
            const totalPages = Math.max(1, Math.ceil(tableStatsAllVisibleRows.length / lim));
            if (tableStatsCurrentPage > totalPages) {
                tableStatsCurrentPage = totalPages;
            }
            updateTableStatsPagination();
            displayTableStatsPage();
        });
    }

    function initCodexrouteAdminTabs() {
        const jsonEl = document.getElementById('codexroute-admin-tabs-json');
        const root = document.getElementById('codexroute-admin-root');
        if (!jsonEl || !root) {
            return;
        }
        let cfg;
        try {
            cfg = JSON.parse(jsonEl.textContent);
        } catch (err) {
            return;
        }
        if (!cfg.tabs_order || !Array.isArray(cfg.tabs_order)) {
            return;
        }
        let currentTabsOrder = cfg.tabs_order.slice();
        const originalTabsOrder = cfg.tabs_order.slice();
        const activeTab = cfg.active_tab || currentTabsOrder[0] || 'config';

        function toggleTabsOrderPanel() {
            const panel = document.getElementById('tabs-order-panel');
            const main = document.getElementById('codexroute-main-layout');
            if (!panel || !main) {
                return;
            }
            const opening = panel.classList.contains('d-none');
            if (opening) {
                panel.classList.remove('d-none');
                main.classList.add('d-none');
            } else {
                panel.classList.add('d-none');
                main.classList.remove('d-none');
            }
        }

        function moveTabUp(tabKey) {
            const index = currentTabsOrder.indexOf(tabKey);
            if (index > 0) {
                const tmp = currentTabsOrder[index - 1];
                currentTabsOrder[index - 1] = currentTabsOrder[index];
                currentTabsOrder[index] = tmp;
                updateTabsOrderDisplay();
            }
        }

        function moveTabDown(tabKey) {
            const index = currentTabsOrder.indexOf(tabKey);
            if (index < currentTabsOrder.length - 1) {
                const tmp = currentTabsOrder[index + 1];
                currentTabsOrder[index + 1] = currentTabsOrder[index];
                currentTabsOrder[index] = tmp;
                updateTabsOrderDisplay();
            }
        }

        function updateTabsOrderDisplay() {
            const orderList = document.getElementById('tabs-order-list');
            if (!orderList) {
                return;
            }
            const tabs = Array.from(orderList.children);
            currentTabsOrder.forEach(function(tabKey, index) {
                const tabElement = tabs.find(function(el) {
                    return el.dataset.tabKey === tabKey;
                });
                if (tabElement) {
                    orderList.appendChild(tabElement);
                    const upBtn = tabElement.querySelector('.codexroute-tab-move-up');
                    const downBtn = tabElement.querySelector('.codexroute-tab-move-down');
                    if (upBtn) {
                        upBtn.disabled = (index === 0);
                    }
                    if (downBtn) {
                        downBtn.disabled = (index === currentTabsOrder.length - 1);
                    }
                }
            });
        }

        function saveTabsOrderPost() {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = window.location.pathname + '?tab=' + encodeURIComponent(activeTab);
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'tabs_order';
            input.value = JSON.stringify(currentTabsOrder);
            form.appendChild(input);
            const submitInput = document.createElement('input');
            submitInput.type = 'hidden';
            submitInput.name = 'save_tabs_order';
            submitInput.value = '1';
            form.appendChild(submitInput);
            const csrfToken = document.querySelector('input[name="_glpi_csrf_token"]');
            if (csrfToken) {
                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = '_glpi_csrf_token';
                csrfInput.value = csrfToken.value;
                form.appendChild(csrfInput);
            }
            document.body.appendChild(form);
            form.submit();
        }

        function resetTabsOrderToOriginal() {
            currentTabsOrder = originalTabsOrder.slice();
            updateTabsOrderDisplay();
        }

        root.addEventListener('click', function(ev) {
            const toggleEl = ev.target.closest('.codexroute-toggle-order');
            if (toggleEl) {
                ev.preventDefault();
                toggleTabsOrderPanel();
                const menu = toggleEl.closest('.dropdown-menu');
                if (menu) {
                    const dd = menu.closest('.dropdown');
                    const tgl = dd ? dd.querySelector('[data-bs-toggle="dropdown"]') : null;
                    if (tgl && typeof bootstrap !== 'undefined' && bootstrap.Dropdown) {
                        const instance = bootstrap.Dropdown.getInstance(tgl);
                        if (instance) {
                            instance.hide();
                        }
                    }
                }
                return;
            }
            const up = ev.target.closest('.codexroute-tab-move-up');
            if (up && !up.disabled) {
                ev.preventDefault();
                moveTabUp(up.getAttribute('data-codexroute-tab'));
                return;
            }
            const down = ev.target.closest('.codexroute-tab-move-down');
            if (down && !down.disabled) {
                ev.preventDefault();
                moveTabDown(down.getAttribute('data-codexroute-tab'));
                return;
            }
            if (ev.target.closest('.codexroute-save-tabs-order')) {
                ev.preventDefault();
                saveTabsOrderPost();
                return;
            }
            if (ev.target.closest('.codexroute-reset-tabs-order')) {
                ev.preventDefault();
                resetTabsOrderToOriginal();
            }
        });
    }

    function initCodexrouteMobileTabSelect() {
        const sel = document.getElementById('codexroute-mobile-tab-select');
        if (!sel) {
            return;
        }
        sel.addEventListener('change', function() {
            const v = sel.value;
            if (v) {
                window.location.href = window.location.pathname + '?tab=' + encodeURIComponent(v);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            initCodexrouteAdminTabs();
            initCodexrouteMobileTabSelect();
            initCodexrouteDatabaseResultsLimitListener();
        });
    } else {
        initCodexrouteAdminTabs();
        initCodexrouteMobileTabSelect();
        initCodexrouteDatabaseResultsLimitListener();
    }

    window.executeAction = executeAction;
    window.allowRoute = allowRoute;
    window.blockRoute = blockRoute;
    window.scanRoutes = scanRoutes;
    window.allowAllBlockedRoutes = allowAllBlockedRoutes;
    window.optimizeTable = optimizeTable;
    window.saveEncryptionConfig = saveEncryptionConfig;
    window.generateConfigFile = generateConfigFile;
    window.showLoader = showLoader;
    window.hideLoader = hideLoader;
    window.filterTableStats = filterTableStats;
    window.goToTableStatsPage = goToTableStatsPage;

})();


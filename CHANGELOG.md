# Changelog

## [1.0.0] - 2025-03-21

### Cambios

- Alineación con estándares GLPI 10: hooks vía `Glpi\Plugin\Hooks`, requisito PHP ≥ 8.1, metadatos de autor y repositorio NexoraInnova.
- Patrón de arranque seguro: `GLPI_ROOT` con `dirname(__DIR__, n)` en `front/config.form.php`, `ajax/*.php` e `install/index.php`.
- CSS del panel de administración solo en `front/config.form.php`; el JavaScript sigue cargándose de forma global para el cifrado de enlaces.
- Internacionalización de mensajes de error y respuestas AJAX (inglés como `msgid`, traducciones `en_GB` / `es_ES`).
- Panel de configuración: iconos Tabler coherentes, sin JavaScript inline ejecutable (datos JSON + `codexroute.js`).
- Catálogo: entradas de capturas en `plugin.xml` (subir `pics/screenshot-admin-config.png` al repositorio para la segunda URL).

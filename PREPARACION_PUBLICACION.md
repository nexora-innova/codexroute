# Checklist: preparación para publicar CodexRoute

Lista de tareas para dejar el plugin listo para distribución y/o GLPI Marketplace.

---

## 1. plugin.xml — formato GLPI Plugins directory

El XML debe respetar el formato que pide GLPI. Resumen:

| Campo | Formato | Nuestro estado |
|-------|--------|----------------|
| `<name>`, `<key>`, `<state>` | Texto (stable/unstable/beta/alpha) | OK |
| `<logo>` | **URL pública** al logo; recomendado **40×40 px** | OK: URL a GitHub raw (`pics/codexroute.jpg` o .png/.svg) |
| `<description>` short/long | `<en>`, `<es>` (long puede ser Markdown) | OK |
| `<homepage>`, `<download>`, `<issues>`, `<readme>` | URLs completas | OK |
| `<authors>` / `<author>` | Nombre | OK |
| `<versions>` / `<version>` | `<num>`, `<compatibility>~10.0`, `<download_url>` | OK |
| `<langs>` / `<lang>` | en_GB, es_ES | OK |
| `<license>` | "GPL v2+" o "GPL v3" | OK: "GPL v3" |
| `<tags>` por idioma | `<tag>...</tag>` | OK |
| `<screenshots>` | Opcional: URLs de capturas | Vacío (opcional) |

**Logo:** Para el directorio, `<logo>` debe ser una **URL**. Tamaño recomendado **40×40 píxeles**. Puedes usar **PNG, JPEG (.jpg) o SVG**. Sube tu logo como `pics/codexroute.jpg` (o .png/.svg) en el repo; la URL en el XML debe coincidir con la extensión del archivo.

---

## 2. Traducciones (locales) — compilar .po → .mo

| Tarea | Estado | Acción |
|-------|--------|--------|
| Compilar .po → .mo | Por hacer | Ver **COMPILAR_TRADUCCIONES.md**: `php tools/po2mo.php` (sin instalar nada) o `compile_translations.bat` / `compile_translations.sh` |
| Archivos .mo en el paquete | Por hacer | Incluir `locales/en_GB.mo` y `locales/es_ES.mo` en el ZIP/tar.gz |

Sin `.mo`, el plugin funciona pero las cadenas traducidas no se aplican.

---

## 3. Repositorio y empaquetado

| Tarea | Estado | Acción |
|-------|--------|--------|
| .gitignore | OK | Ya existe en la raíz del plugin |
| Archivos a excluir del ZIP/tar.gz | OK | No incluir: `.git`, `.env`. Incluir: `locales/*.mo`, `pics/codexroute.jpg` (o .png/.svg, 40×40) |
| Crear release en GitHub | Pendiente | Tag `1.0.0`, adjuntar `codexroute-1.0.0.tar.gz` (o ZIP) con la carpeta `codexroute/` |

---

## 4. Código y seguridad

| Tarea | Estado |
|-------|--------|
| Sin rutas de debug en código | OK |
| error_log condicionales | OK |
| Config fuera del plugin (GLPI_CONFIG_DIR) | OK |
| CSRF | OK |

---

## 5. Documentación

| Tarea | Estado |
|-------|--------|
| README.md | OK |
| LICENSE (GPL v3) | OK |
| Changelog en README | OK |
| Estructura en README | OK (pics/ incluido) |

---

## 6. Requisitos GLPI / Marketplace

| Requisito | Estado |
|-----------|--------|
| setup.php con versión y requisitos | OK |
| hook.php | OK |
| plugin.xml con name, key, logo, description, versions, compatibility | OK (falta solo rellenar URLs y download_url) |
| Compatibilidad ~10.0 | OK |
| Namespace GlpiPlugin\Codexroute | OK |
| No escribir en carpeta del plugin | OK (config en GLPI_CONFIG_DIR) |
| README + LICENSE | OK |

---

## 7. Resumen de pasos mínimos antes de publicar

1. Sustituir en `plugin.xml` todas las URLs que contengan `TU_USUARIO` por tu usuario/org real.
2. Compilar traducciones (generar `.mo` en `locales/`) e incluir esos archivos en el paquete.
3. Crear en GitHub el tag `1.0.0` y una release con el archivo `codexroute-1.0.0.tar.gz` (o ZIP).
4. Poner en `plugin.xml` la `download_url` que apunte a ese archivo de la release.
5. (Recomendado) Añadir `.gitignore` y revisar que el ZIP/tar no incluya `.git`, `.env` ni datos sensibles.

Después de esto, el plugin estará listo para compartir (por ejemplo en el marketplace de GLPI si cumples sus criterios de inclusión).

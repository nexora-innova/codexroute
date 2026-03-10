# Herramientas de Desarrollo - CodexRoute

Este directorio contiene scripts útiles para el desarrollo y mantenimiento del plugin.

## Scripts Disponibles

### extract_locales.php

Extrae todas las cadenas de traducción del plugin que usan `__()` y genera un archivo `.pot`.

**Uso:**
```bash
php tools/extract_locales.php
```

**Genera:** `locales/codexroute.pot`

---

### validate_plugin.php

Valida que el plugin cumpla con los requisitos básicos de GLPI antes de publicar.

**Uso:**
```bash
php tools/validate_plugin.php
```

**Verifica:**
- Archivos requeridos presentes
- Directorios requeridos presentes
- Imagen del plugin
- Traducciones compiladas
- URLs en plugin.xml

---

## Notas

- Todos los scripts requieren acceso a GLPI (incluyen `inc/includes.php`)
- Ejecutar desde la línea de comandos o desde el navegador (con permisos adecuados)
- Los scripts están protegidos con `index.php` para evitar acceso directo


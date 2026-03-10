# Cómo compilar los archivos .po a .mo

GLPI usa archivos **.mo** (compilados) para mostrar las traducciones. Los **.po** son solo la fuente.

## Opción 1: Script PHP (sin instalar nada)

Desde la raíz del plugin:

```bash
php tools/po2mo.php
```

Genera `locales/en_GB.mo` y `locales/es_ES.mo`. No necesitas gettext instalado.

## Opción 2: Windows (gettext)

1. Instala gettext para Windows (por ejemplo desde [gettext-iconv Windows](https://mlocati.github.io/articles/gettext-iconv-windows.html)) y agrega `msgfmt.exe` al PATH.
2. En la carpeta del plugin, ejecuta:

```cmd
compile_translations.bat
```

## Opción 3: Linux / macOS (gettext)

```bash
./compile_translations.sh
```

(Requiere `msgfmt` instalado: `apt install gettext` / `brew install gettext`.)

---

Después de compilar, incluye los archivos **.mo** en el paquete (ZIP/tar.gz) que subas a GitHub Releases.

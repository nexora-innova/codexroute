#!/bin/bash

# Script para compilar traducciones del plugin CodexRoute
# Requiere: gettext (msgfmt)

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOCALES_DIR="$SCRIPT_DIR/locales"

echo "Compilando traducciones de CodexRoute..."
echo ""

# Verificar si msgfmt está disponible
if ! command -v msgfmt &> /dev/null; then
    echo "ERROR: msgfmt no está instalado."
    echo "Instalar gettext:"
    echo "  - Linux: sudo apt-get install gettext (Debian/Ubuntu)"
    echo "  - Linux: sudo yum install gettext (RHEL/CentOS)"
    echo "  - Mac: brew install gettext"
    echo "  - Windows: Descargar de https://mlocati.github.io/articles/gettext-iconv-windows.html"
    exit 1
fi

# Compilar en_GB.po
if [ -f "$LOCALES_DIR/en_GB.po" ]; then
    echo "Compilando en_GB.po..."
    msgfmt "$LOCALES_DIR/en_GB.po" -o "$LOCALES_DIR/en_GB.mo"
    if [ $? -eq 0 ]; then
        echo "en_GB.mo compilado correctamente"
    else
        echo "Error al compilar en_GB.po"
        exit 1
    fi
else
    echo "en_GB.po no encontrado"
fi

# Compilar es_ES.po (si existe y no está compilado)
if [ -f "$LOCALES_DIR/es_ES.po" ] && [ ! -f "$LOCALES_DIR/es_ES.mo" ]; then
    echo "Compilando es_ES.po..."
    msgfmt "$LOCALES_DIR/es_ES.po" -o "$LOCALES_DIR/es_ES.mo"
    if [ $? -eq 0 ]; then
        echo "es_ES.mo compilado correctamente"
    else
        echo "Error al compilar es_ES.po"
    fi
fi

echo ""
echo "Compilación completada!"
echo ""
echo "Archivos compilados:"
ls -lh "$LOCALES_DIR"/*.mo 2>/dev/null || echo "No se encontraron archivos .mo"


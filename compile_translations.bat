@echo off
REM Script para compilar traducciones del plugin CodexRoute (Windows)
REM Requiere: gettext (msgfmt.exe)

set SCRIPT_DIR=%~dp0
set LOCALES_DIR=%SCRIPT_DIR%locales

echo Compilando traducciones de CodexRoute...
echo.

REM Verificar si msgfmt está disponible
where msgfmt.exe >nul 2>&1
if %ERRORLEVEL% NEQ 0 (
    echo ERROR: msgfmt.exe no esta instalado o no esta en el PATH.
    echo.
    echo Instalar gettext:
    echo   - Descargar de: https://mlocati.github.io/articles/gettext-iconv-windows.html
    echo   - O usar Chocolatey: choco install gettext
    echo   - Agregar al PATH: C:\Program Files\gettext-iconv\bin
    exit /b 1
)

REM Compilar en_GB.po
if exist "%LOCALES_DIR%\en_GB.po" (
    echo Compilando en_GB.po...
    msgfmt.exe "%LOCALES_DIR%\en_GB.po" -o "%LOCALES_DIR%\en_GB.mo"
    if %ERRORLEVEL% EQU 0 (
        echo [OK] en_GB.mo compilado correctamente
    ) else (
        echo [ERROR] Error al compilar en_GB.po
        exit /b 1
    )
) else (
    echo [WARNING] en_GB.po no encontrado
)

REM Compilar es_ES.po (si existe y no está compilado)
if exist "%LOCALES_DIR%\es_ES.po" (
    if not exist "%LOCALES_DIR%\es_ES.mo" (
        echo Compilando es_ES.po...
        msgfmt.exe "%LOCALES_DIR%\es_ES.po" -o "%LOCALES_DIR%\es_ES.mo"
        if %ERRORLEVEL% EQU 0 (
            echo [OK] es_ES.mo compilado correctamente
        ) else (
            echo [ERROR] Error al compilar es_ES.po
        )
    )
)

echo.
echo Compilacion completada!
echo.
echo Archivos compilados:
dir /b "%LOCALES_DIR%\*.mo" 2>nul
if %ERRORLEVEL% NEQ 0 (
    echo No se encontraron archivos .mo
)

pause


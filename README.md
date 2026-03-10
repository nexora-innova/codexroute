# CodexRoute - Plugin de Seguridad y Rendimiento para GLPI

## Descripción

CodexRoute es un plugin completo de seguridad y optimización de rendimiento para GLPI. Proporciona las siguientes funcionalidades:

### 🔒 Seguridad

- **Encriptación de IDs**: Protege contra vulnerabilidades IDOR (Insecure Direct Object Reference) encriptando los IDs en URLs
- **Validación de Rutas**: Valida automáticamente los parámetros ID en las rutas del sistema
- **Protección contra Ataques**: Rate limiting, bloqueo de IPs, detección de patrones sospechosos
- **Modo Estricto**: Rechaza IDs numéricos sin encriptar en producción

### ⚡ Rendimiento

- **Análisis Apache/PHP**: Diagnostica la configuración del servidor y PHP
- **Análisis de Base de Datos**: Detecta consultas lentas y problemas de rendimiento
- **Optimización de Tablas**: Crea índices optimizados para las tablas principales
- **Profiling SQL**: Analiza el rendimiento de las consultas en tiempo real

## Requisitos

- GLPI >= 10.0.0
- PHP >= 7.4
- Extensión Sodium u OpenSSL (recomendado para mejor encriptación)

## Instalación

1. Descarga o clona este repositorio en la carpeta `plugins/` de tu instalación de GLPI
2. Renombra la carpeta a `codexroute` (si es necesario)
3. Accede a GLPI como administrador
4. Ve a **Configuración > Plugins**
5. Busca "CodexRoute" y haz clic en **Instalar**
6. Una vez instalado, haz clic en **Habilitar**

## Configuración

Después de la instalación, accede al panel de administración:

**Configuración > CodexRoute**

### Pestañas Disponibles

#### 1. Validación de IDs
- Analiza todas las rutas que usan parámetros ID
- Muestra el progreso de validación
- Permite aplicar validación automática a archivos pendientes

#### 2. Permisos de Rutas
- Gestiona rutas problemáticas que requieren permisos especiales
- Permite/bloquea rutas específicas
- Configura excepciones para PATH_INFO

#### 3. Configuración de Encriptación
- **Modo Estricto**: Rechaza IDs numéricos
- **Registro de IDs Numéricos**: Logging para auditoría
- **Configuración de Caché**: Tamaño y TTL
- **Protección contra Ataques**: Límites de intentos y bloqueo

#### 4. Rendimiento Apache/PHP
- Versión de PHP y extensiones disponibles
- Límites de memoria y ejecución
- Estado de OPcache
- Análisis de rendimiento

#### 5. Rendimiento de Base de Datos
- Estado de conexión
- Análisis de consultas lentas
- Optimización de índices por tabla
- Profiling SQL

## Uso de la API de Encriptación

### Encriptar un ID

```php
use GlpiPlugin\Codexroute\IDEncryption;

$encrypted_id = IDEncryption::encrypt($id);
```

### Desencriptar un ID

```php
use GlpiPlugin\Codexroute\IDEncryption;

$decrypted_id = IDEncryption::decrypt($encrypted_id);
```

### Desencriptar y Validar Autorización

```php
use GlpiPlugin\Codexroute\IDEncryption;

$decrypted_id = IDEncryption::decryptAndValidate($encrypted_id, 'Ticket', READ);
if ($decrypted_id === false) {
    // ID inválido o sin autorización
    exit;
}
```

## Estructura del Plugin

```
codexroute/
├── ajax/
│   └── config.php          # Handler AJAX
├── css/
│   └── codexroute.css      # Estilos del panel
├── front/
│   └── config.form.php     # Panel de administración
├── install/                 # Scripts de instalación
├── js/
│   └── codexroute.js       # JavaScript del panel
├── locales/
│   ├── es_ES.mo
│   └── es_ES.po
├── pics/                    # Iconos e imágenes
├── src/
│   ├── Config.php          # Configuración del plugin
│   ├── DatabaseOptimizer.php
│   ├── IDEncryption.php    # Clase de encriptación
│   ├── Logger.php          # Sistema de logs
│   ├── Menu.php            # Menú del plugin
│   ├── PerformanceAnalyzer.php
│   └── RouteValidator.php  # Validador de rutas
├── templates/               # Templates Twig
├── hook.php                 # Hooks de instalación
├── index.php               # Archivo de protección
├── LICENSE                 # Licencia GPLv3
├── README.md               # Este archivo
└── setup.php               # Configuración principal
```

## Seguridad

### Métodos de Encriptación

El plugin usa el siguiente orden de preferencia para encriptación:

1. **Sodium** (recomendado): XChaCha20-Poly1305 AEAD
2. **OpenSSL**: AES-256-CBC
3. **Simple**: XOR con hash SHA-256 (fallback)

### Protecciones Implementadas

- Rate limiting para prevenir ataques de fuerza bruta
- Bloqueo temporal de IPs después de múltiples intentos fallidos
- Normalización de tiempos de respuesta (previene timing attacks)
- Validación de formato de IDs encriptados
- Caché con TTL para mejorar rendimiento

## Licencia

Este plugin está licenciado bajo GPLv3. Ver archivo LICENSE para más detalles.

## Soporte

Para reportar bugs o solicitar funcionalidades, crea un issue en el repositorio.

## Changelog

### v1.0.0
- Versión inicial
- Encriptación de IDs con Sodium/OpenSSL/Simple
- Panel de administración completo
- Análisis de rendimiento Apache/PHP
- Análisis y optimización de base de datos
- Sistema de logging


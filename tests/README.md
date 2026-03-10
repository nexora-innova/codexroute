# Tests Unitarios - CodexRoute

Este directorio contiene los tests unitarios del plugin CodexRoute.

## Estructura

```
tests/
├── bootstrap.php          # Configuración inicial para tests
├── Unit/                  # Tests unitarios
│   ├── ConfigTest.php     # Tests para clase Config
│   └── IDEncryptionTest.php # Tests para clase IDEncryption
└── index.php              # Protección de acceso directo
```

## Requisitos

- PHPUnit 9.5+ o 10.0+
- GLPI instalado y configurado
- Base de datos de pruebas (opcional, según tests)

## Instalación de PHPUnit

```bash
# Si usas Composer
composer install --dev

# O instalar PHPUnit globalmente
composer global require phpunit/phpunit
```

## Ejecutar Tests

### Desde el directorio del plugin:

```bash
# Ejecutar todos los tests
vendor/bin/phpunit

# O si PHPUnit está instalado globalmente
phpunit

# Ejecutar tests específicos
vendor/bin/phpunit tests/Unit/ConfigTest.php

# Con cobertura de código
vendor/bin/phpunit --coverage-html coverage/
```

### Desde GLPI (si está configurado):

```bash
cd /path/to/glpi
php bin/console glpi:test:unit --plugin codexroute
```

## Escribir Nuevos Tests

1. Crear archivo en `tests/Unit/` o `tests/Integration/`
2. Extender `PHPUnit\Framework\TestCase`
3. Usar namespace `GlpiPlugin\Codexroute\Tests\Unit`
4. Agregar anotación `@group codexroute`

Ejemplo:

```php
<?php

namespace GlpiPlugin\Codexroute\Tests\Unit;

use PHPUnit\Framework\TestCase;
use GlpiPlugin\Codexroute\MiClase;

class MiClaseTest extends TestCase
{
    public function testMetodo()
    {
        $resultado = MiClase::metodo();
        $this->assertNotNull($resultado);
    }
}
```

## Notas

- Los tests deben ser independientes y no depender de orden de ejecución
- Usar mocks para dependencias externas
- Limpiar datos de prueba después de cada test si es necesario
- Los tests de integración pueden requerir base de datos de pruebas


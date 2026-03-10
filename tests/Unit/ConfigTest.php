<?php

/**
 * CodexRoute - Tests Unitarios para la clase Config
 * 
 * @group codexroute
 */

namespace GlpiPlugin\Codexroute\Tests\Unit;

use PHPUnit\Framework\TestCase;
use GlpiPlugin\Codexroute\Config;

class ConfigTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists('GlpiPlugin\Codexroute\Config')) {
            $this->markTestSkipped('Clase Config no disponible');
        }
    }

    public function testGetTypeName()
    {
        $this->assertNotEmpty(Config::getTypeName());
    }

    public function testGetMenuName()
    {
        $this->assertNotEmpty(Config::getMenuName());
    }

    public function testCanView()
    {
        $this->assertIsBool(Config::canView());
    }

    public function testCanCreate()
    {
        $this->assertIsBool(Config::canCreate());
    }
}


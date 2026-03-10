<?php

/**
 * CodexRoute - Tests Unitarios para la clase IDEncryption
 * 
 * @group codexroute
 */

namespace GlpiPlugin\Codexroute\Tests\Unit;

use PHPUnit\Framework\TestCase;
use GlpiPlugin\Codexroute\IDEncryption;

class IDEncryptionTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists('GlpiPlugin\Codexroute\IDEncryption')) {
            $this->markTestSkipped('Clase IDEncryption no disponible');
        }
    }

    public function testEncryptDecrypt()
    {
        $original_id = 123;
        
        $encrypted = IDEncryption::encrypt($original_id);
        $this->assertNotEmpty($encrypted);
        $this->assertNotEquals($original_id, $encrypted);
        
        $decrypted = IDEncryption::decrypt($encrypted);
        $this->assertEquals($original_id, $decrypted);
    }

    public function testEncryptReturnsString()
    {
        $encrypted = IDEncryption::encrypt(456);
        $this->assertIsString($encrypted);
    }

    public function testDecryptInvalidId()
    {
        $result = IDEncryption::decrypt('invalid_encrypted_id');
        $this->assertFalse($result);
    }
}


<?php

declare(strict_types=1);

namespace Tests\App\Services;

use App\Services\SpanishIdValidator;
use CodeIgniter\Test\CIUnitTestCase;

final class SpanishIdValidatorTest extends CIUnitTestCase
{
    /**
     * @dataProvider validDniProvider
     * @param string $dni
     * @return void
     */

    /**
     * Con espacios/guiones también debería valer
     */
    public function testValidDni(): void
    {
        $this->assertTrue(SpanishIdValidator::isValid('12345678Z'));
        $this->assertTrue(SpanishIdValidator::isValid('50812326J'));

        $this->assertTrue(SpanishIdValidator::isValid('12 345 678Z'));
        $this->assertTrue(SpanishIdValidator::isValid('12345678-Z'));
    }

    /**
     * DNI con letra incorrecta o formato inválido no debe ser válido
     */
    public function testInvalidDni(): void
    {
        $this->assertFalse(SpanishIdValidator::isValid('50812326A'));
        $this->assertFalse(SpanishIdValidator::isValid('12345678A'));

        $this->assertFalse(SpanishIdValidator::isValid('1234567L'));
        $this->assertFalse(SpanishIdValidator::isValid('123456789L'));
    }

    /**
     * Valida NIEs típicos
     */
    public function testValidNie(): void
    {
        $this->assertTrue(SpanishIdValidator::isValid('X1234567L'));
        $this->assertTrue(SpanishIdValidator::isValid('Y1234567X'));
        $this->assertTrue(SpanishIdValidator::isValid('Z1234567R'));
    }

    /**
     * NIEs inválidos por formato o letra de control incorrecta
     */
    public function testInvalidNie(): void
    {
        $this->assertFalse(SpanishIdValidator::isValid('X123456L'));
        $this->assertFalse(SpanishIdValidator::isValid('A1234567L'));
        $this->assertFalse(SpanishIdValidator::isValid('X1234567A'));
    }

    /**
     * Valida CIFs típicos
     */
    public function testValidCif(): void
    {
        $this->assertTrue(SpanishIdValidator::isValid('B99286320'));
        $this->assertTrue(SpanishIdValidator::isValid('A58818501'));
        $this->assertTrue(SpanishIdValidator::isValid('G1234567D'));
    }

    /**
     * CIFs inválidos por formato o control incorrecto
     * Control incorrecto: la última letra/dígito no coincide
     * Formato incorrecto: letra inicial no válida o longitud incorrecta
     * Formato incorrecto: muy corto/largo
     */
    public function testInvalidCif(): void
    {
        // Control incorrecto
        $this->assertFalse(SpanishIdValidator::isValid('B99286321'));
        $this->assertFalse(SpanishIdValidator::isValid('A58818500'));

        // Formato incorrecto
        $this->assertFalse(SpanishIdValidator::isValid('X99286320')); // letra inicial no CIF
        $this->assertFalse(SpanishIdValidator::isValid('B1234567'));  // muy corto
        $this->assertFalse(SpanishIdValidator::isValid('B123456789')); // muy largo
    }

    /**
     * Valores totalmente inválidos
     */
    public function testTotallyInvalidValues(): void
    {
        $this->assertFalse(SpanishIdValidator::isValid(''));
        $this->assertFalse(SpanishIdValidator::isValid('LO QUE SEA'));
        $this->assertFalse(SpanishIdValidator::isValid('B12345678'));
    }
}

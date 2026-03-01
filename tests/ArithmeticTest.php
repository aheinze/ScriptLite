<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

use ScriptLite\Engine;
use PHPUnit\Framework\TestCase;

final class ArithmeticTest extends TestCase
{
    private Engine $engine;

    protected function setUp(): void
    {
        $this->engine = new Engine();
    }

    // ═══════════════════ Arithmetic & Operator Precedence ═══════════════════

    public function testBasicArithmetic(): void
    {
        self::assertSame(3, $this->engine->eval('1 + 2'));
        self::assertSame(6, $this->engine->eval('2 * 3'));
        self::assertSame(2.5, $this->engine->eval('5 / 2'));
        self::assertSame(-1, $this->engine->eval('3 - 4'));
    }

    public function testOperatorPrecedence(): void
    {
        // Multiplication before addition
        self::assertSame(14, $this->engine->eval('2 + 3 * 4'));

        // Parentheses override precedence
        self::assertSame(20, $this->engine->eval('(2 + 3) * 4'));

        // Mixed precedence chain: 2 + 12 - 3 + 1 = 12
        self::assertSame(12, $this->engine->eval('2 + 3 * 4 - 6 / 2 + 1'));

        // Nested parentheses
        self::assertSame(36, $this->engine->eval('(2 + (3 + 1)) * (4 + 2)'));
    }

    public function testUnaryMinus(): void
    {
        self::assertSame(-5, $this->engine->eval('-5'));
        self::assertSame(5, $this->engine->eval('-(-5)'));
        self::assertSame(-3, $this->engine->eval('-(1 + 2)'));
    }

    // ═══════════════════ Variable Declarations ═══════════════════

    public function testVarDeclaration(): void
    {
        self::assertSame(42, $this->engine->eval('var x = 42; x'));
    }

    public function testLetDeclaration(): void
    {
        self::assertSame(10, $this->engine->eval('let a = 10; a'));
    }

    public function testConstDeclaration(): void
    {
        self::assertSame(99, $this->engine->eval('const PI = 99; PI'));
    }

    public function testConstReassignmentThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('constant variable');
        $this->engine->eval('const x = 1; x = 2;');
    }

    public function testCompoundAssignment(): void
    {
        self::assertSame(15, $this->engine->eval('var x = 10; x += 5; x'));
        self::assertSame(7, $this->engine->eval('var x = 10; x -= 3; x'));
        self::assertSame(20, $this->engine->eval('var x = 4; x *= 5; x'));
        self::assertSame(5, $this->engine->eval('var x = 10; x /= 2; x'));
    }
}

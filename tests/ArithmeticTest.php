<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

class ArithmeticTest extends ScriptLiteTestCase
{

    // ═══════════════════ Arithmetic & Operator Precedence ═══════════════════

    public function testBasicArithmetic(): void
    {
        $this->assertBothBackends('1 + 2', 3);
        $this->assertBothBackends('2 * 3', 6);
        $this->assertBothBackends('5 / 2', 2.5);
        $this->assertBothBackends('3 - 4', -1);
    }

    public function testOperatorPrecedence(): void
    {
        // Multiplication before addition
        $this->assertBothBackends('2 + 3 * 4', 14);

        // Parentheses override precedence
        $this->assertBothBackends('(2 + 3) * 4', 20);

        // Mixed precedence chain: 2 + 12 - 3 + 1 = 12
        $this->assertBothBackends('2 + 3 * 4 - 6 / 2 + 1', 12);

        // Nested parentheses
        $this->assertBothBackends('(2 + (3 + 1)) * (4 + 2)', 36);
    }

    public function testUnaryMinus(): void
    {
        $this->assertBothBackends('-5', -5);
        $this->assertBothBackends('-(-5)', 5);
        $this->assertBothBackends('-(1 + 2)', -3);
    }

    // ═══════════════════ Variable Declarations ═══════════════════

    public function testVarDeclaration(): void
    {
        $this->assertBothBackends('var x = 42; x', 42);
    }

    public function testLetDeclaration(): void
    {
        $this->assertBothBackends('let a = 10; a', 10);
    }

    public function testConstDeclaration(): void
    {
        $this->assertBothBackends('const PI = 99; PI', 99);
    }

    public function testConstReassignmentThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('constant variable');
        $this->engine->eval('const x = 1; x = 2;');
    }

    public function testCompoundAssignment(): void
    {
        $this->assertBothBackends('var x = 10; x += 5; x', 15);
        $this->assertBothBackends('var x = 10; x -= 3; x', 7);
        $this->assertBothBackends('var x = 4; x *= 5; x', 20);
        $this->assertBothBackends('var x = 10; x /= 2; x', 5);
    }
}

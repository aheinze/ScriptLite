<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

class OperatorsTest extends ScriptLiteTestCase
{

    // ═══════════════════ Ternary Operator ═══════════════════

    public function testTernaryBasic(): void
    {
        $this->assertBothBackends('true ? 1 : 2', 1);
        $this->assertBothBackends('false ? 1 : 2', 2);
    }

    public function testTernaryWithExpressions(): void
    {
        $this->assertBothBackends('(3 > 2) ? 5 * 2 : 5 + 2', 10);
        $this->assertBothBackends('(3 < 2) ? 5 * 2 : 5 + 2', 7);
    }

    public function testTernaryNested(): void
    {
        // Right-associative: a ? b : c ? d : e  ===  a ? b : (c ? d : e)
        $this->assertBothBackends('false ? 1 : false ? 2 : 3', 3);
        $this->assertBothBackends('true ? 1 : true ? 2 : 3', 1);
        $this->assertBothBackends('false ? 1 : true ? 2 : 3', 2);
    }

    public function testTernaryInFunction(): void
    {
        $this->assertBothBackends('
            function abs(x) {
                return x >= 0 ? x : -x;
            }
            abs(-42);
        ', 42);
    }

    public function testTernaryWithVariables(): void
    {
        $this->assertBothBackends('
            var mode = "dark";
            var bg = mode === "dark" ? "black" : "white";
            bg;
        ', 'black');
    }

    public function testTernaryStringEquality(): void
    {
        $this->assertBothBackends('"a" === "a" ? "yes" : "no"', 'yes');
        $this->assertBothBackends('"a" === "b" ? "yes" : "no"', 'no');
    }

    // ═══════════════════ typeof Operator ═══════════════════

    public function testTypeofPrimitives(): void
    {
        self::assertSame('number', $this->engine->eval('typeof 42'));
        self::assertSame('number', $this->engine->eval('typeof 3.14'));
        self::assertSame('string', $this->engine->eval('typeof "hello"'));
        self::assertSame('boolean', $this->engine->eval('typeof true'));
        self::assertSame('boolean', $this->engine->eval('typeof false'));
        self::assertSame('undefined', $this->engine->eval('typeof undefined'));
        self::assertSame('object', $this->engine->eval('typeof null'));
    }

    public function testTypeofFunction(): void
    {
        $result = $this->engine->eval('
            function foo() {}
            typeof foo;
        ');
        self::assertSame('function', $result);
    }

    public function testTypeofFunctionExpression(): void
    {
        $result = $this->engine->eval('
            var f = function() { return 1; };
            typeof f;
        ');
        self::assertSame('function', $result);
    }

    public function testTypeofInCondition(): void
    {
        $result = $this->engine->eval('
            var x = 42;
            typeof x === "number" ? "is number" : "not number";
        ');
        self::assertSame('is number', $result);
    }

    public function testTypeofWithExpression(): void
    {
        self::assertSame('number', $this->engine->eval('typeof (1 + 2)'));
        self::assertSame('string', $this->engine->eval('typeof ("a" + "b")'));
    }
}

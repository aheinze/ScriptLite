<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

use ScriptLite\Engine;
use PHPUnit\Framework\TestCase;

final class OperatorsTest extends TestCase
{
    private Engine $engine;

    protected function setUp(): void
    {
        $this->engine = new Engine();
    }

    // ═══════════════════ Ternary Operator ═══════════════════

    public function testTernaryBasic(): void
    {
        self::assertSame(1, $this->engine->eval('true ? 1 : 2'));
        self::assertSame(2, $this->engine->eval('false ? 1 : 2'));
    }

    public function testTernaryWithExpressions(): void
    {
        self::assertSame(10, $this->engine->eval('(3 > 2) ? 5 * 2 : 5 + 2'));
        self::assertSame(7, $this->engine->eval('(3 < 2) ? 5 * 2 : 5 + 2'));
    }

    public function testTernaryNested(): void
    {
        // Right-associative: a ? b : c ? d : e  ===  a ? b : (c ? d : e)
        self::assertSame(3, $this->engine->eval('false ? 1 : false ? 2 : 3'));
        self::assertSame(1, $this->engine->eval('true ? 1 : true ? 2 : 3'));
        self::assertSame(2, $this->engine->eval('false ? 1 : true ? 2 : 3'));
    }

    public function testTernaryInFunction(): void
    {
        $result = $this->engine->eval('
            function abs(x) {
                return x >= 0 ? x : -x;
            }
            abs(-42);
        ');
        self::assertSame(42, $result);
    }

    public function testTernaryWithVariables(): void
    {
        $result = $this->engine->eval('
            var mode = "dark";
            var bg = mode === "dark" ? "black" : "white";
            bg;
        ');
        self::assertSame('black', $result);
    }

    public function testTernaryStringEquality(): void
    {
        self::assertSame('yes', $this->engine->eval('"a" === "a" ? "yes" : "no"'));
        self::assertSame('no', $this->engine->eval('"a" === "b" ? "yes" : "no"'));
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

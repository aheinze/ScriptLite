<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

use ScriptLite\Engine;
use PHPUnit\Framework\TestCase;

final class TemplateLiteralTest extends TestCase
{
    private Engine $engine;

    protected function setUp(): void
    {
        $this->engine = new Engine();
    }

    // ═══════════════════ No interpolation ═══════════════════

    public function testSimpleTemplate(): void
    {
        self::assertSame('hello world', $this->engine->eval('`hello world`'));
    }

    public function testEmptyTemplate(): void
    {
        self::assertSame('', $this->engine->eval('``'));
    }

    // ═══════════════════ Basic interpolation ═══════════════════

    public function testSingleInterpolation(): void
    {
        self::assertSame('hello Alice', $this->engine->eval('var name = "Alice"; `hello ${name}`'));
    }

    public function testInterpolationAtStart(): void
    {
        self::assertSame('Alice is here', $this->engine->eval('var name = "Alice"; `${name} is here`'));
    }

    public function testInterpolationAtEnd(): void
    {
        self::assertSame('name is Alice', $this->engine->eval('var name = "Alice"; `name is ${name}`'));
    }

    public function testMultipleInterpolations(): void
    {
        self::assertSame('Alice is 30 years old', $this->engine->eval('
            var name = "Alice";
            var age = 30;
            `${name} is ${age} years old`
        '));
    }

    public function testAdjacentInterpolations(): void
    {
        self::assertSame('AB', $this->engine->eval('var a = "A"; var b = "B"; `${a}${b}`'));
    }

    public function testOnlyInterpolation(): void
    {
        self::assertSame('hello', $this->engine->eval('`${"hello"}`'));
    }

    // ═══════════════════ Expression interpolation ═══════════════════

    public function testExpressionInterpolation(): void
    {
        self::assertSame('2 + 3 = 5', $this->engine->eval('`2 + 3 = ${2 + 3}`'));
    }

    public function testFunctionCallInterpolation(): void
    {
        self::assertSame('result: 6', $this->engine->eval('
            function double(x) { return x * 2; }
            `result: ${double(3)}`
        '));
    }

    public function testTernaryInterpolation(): void
    {
        self::assertSame('yes', $this->engine->eval('var x = true; `${x ? "yes" : "no"}`'));
    }

    public function testMethodCallInterpolation(): void
    {
        self::assertSame('HELLO', $this->engine->eval('`${"hello".toUpperCase()}`'));
    }

    // ═══════════════════ Type coercion ═══════════════════

    public function testNumberCoercion(): void
    {
        self::assertSame('count: 42', $this->engine->eval('`count: ${42}`'));
    }

    public function testBooleanCoercion(): void
    {
        self::assertSame('value: true', $this->engine->eval('`value: ${true}`'));
        self::assertSame('value: false', $this->engine->eval('`value: ${false}`'));
    }

    public function testNullCoercion(): void
    {
        self::assertSame('value: null', $this->engine->eval('`value: ${null}`'));
    }

    // ═══════════════════ Nested braces ═══════════════════

    public function testObjectInsideInterpolation(): void
    {
        self::assertSame('val: 1', $this->engine->eval('
            var obj = {a: 1};
            `val: ${obj.a}`
        '));
    }

    // ═══════════════════ Multiline ═══════════════════

    public function testMultiline(): void
    {
        $result = $this->engine->eval("`line1\nline2`");
        self::assertSame("line1\nline2", $result);
    }

    // ═══════════════════ With other operators ═══════════════════

    public function testTemplatePlusString(): void
    {
        self::assertSame('hello world!', $this->engine->eval('`hello ` + "world!"'));
    }

    public function testStringPlusTemplate(): void
    {
        self::assertSame('hello world!', $this->engine->eval('"hello " + `world!`'));
    }

    // ═══════════════════ With globals ═══════════════════

    public function testWithGlobals(): void
    {
        self::assertSame('Hello Alice!', $this->engine->eval('`Hello ${name}!`', ['name' => 'Alice']));
    }

    // ═══════════════════ Transpiler path ═══════════════════

    public function testTranspiledSimple(): void
    {
        $engine = new Engine();
        $php = $engine->transpile('`hello world`');
        self::assertSame('hello world', $engine->evalTranspiled($php));
    }

    public function testTranspiledInterpolation(): void
    {
        $engine = new Engine();
        $php = $engine->transpile('var name = "Alice"; `hello ${name}`');
        self::assertSame('hello Alice', $engine->evalTranspiled($php));
    }

    public function testTranspiledMultipleInterpolations(): void
    {
        $engine = new Engine();
        $php = $engine->transpile('var name = "Alice"; var age = 30; `${name} is ${age} years old`');
        self::assertSame('Alice is 30 years old', $engine->evalTranspiled($php));
    }

    public function testTranspiledExpression(): void
    {
        $engine = new Engine();
        $php = $engine->transpile('`2 + 3 = ${2 + 3}`');
        self::assertSame('2 + 3 = 5', $engine->evalTranspiled($php));
    }

    public function testTranspiledWithGlobals(): void
    {
        $engine = new Engine();
        $php = $engine->transpile('`Hello ${name}!`', ['name' => '']);
        self::assertSame('Hello Bob!', $engine->evalTranspiled($php, ['name' => 'Bob']));
    }

    public function testTranspiledAdjacentInterpolations(): void
    {
        $engine = new Engine();
        $php = $engine->transpile('var a = "X"; var b = "Y"; `${a}${b}`');
        self::assertSame('XY', $engine->evalTranspiled($php));
    }

    public function testTranspiledBooleanCoercion(): void
    {
        $engine = new Engine();
        $php = $engine->transpile('`value: ${true}`');
        self::assertSame('value: true', $engine->evalTranspiled($php));
    }

    public function testTranspiledNullCoercion(): void
    {
        $engine = new Engine();
        $php = $engine->transpile('`value: ${null}`');
        self::assertSame('value: null', $engine->evalTranspiled($php));
    }
}

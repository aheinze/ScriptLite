<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

use ScriptLite\Engine;

class TemplateLiteralTest extends ScriptLiteTestCase
{

    // ═══════════════════ No interpolation ═══════════════════

    public function testSimpleTemplate(): void
    {
        $this->assertBothBackends('`hello world`', 'hello world');
    }

    public function testEmptyTemplate(): void
    {
        $this->assertBothBackends('``', '');
    }

    // ═══════════════════ Basic interpolation ═══════════════════

    public function testSingleInterpolation(): void
    {
        $this->assertBothBackends('var name = "Alice"; `hello ${name}`', 'hello Alice');
    }

    public function testInterpolationAtStart(): void
    {
        $this->assertBothBackends('var name = "Alice"; `${name} is here`', 'Alice is here');
    }

    public function testInterpolationAtEnd(): void
    {
        $this->assertBothBackends('var name = "Alice"; `name is ${name}`', 'name is Alice');
    }

    public function testMultipleInterpolations(): void
    {
        $this->assertBothBackends('
            var name = "Alice";
            var age = 30;
            `${name} is ${age} years old`
        ', 'Alice is 30 years old');
    }

    public function testAdjacentInterpolations(): void
    {
        $this->assertBothBackends('var a = "A"; var b = "B"; `${a}${b}`', 'AB');
    }

    public function testOnlyInterpolation(): void
    {
        $this->assertBothBackends('`${"hello"}`', 'hello');
    }

    // ═══════════════════ Expression interpolation ═══════════════════

    public function testExpressionInterpolation(): void
    {
        $this->assertBothBackends('`2 + 3 = ${2 + 3}`', '2 + 3 = 5');
    }

    public function testFunctionCallInterpolation(): void
    {
        $this->assertBothBackends('
            function double(x) { return x * 2; }
            `result: ${double(3)}`
        ', 'result: 6');
    }

    public function testTernaryInterpolation(): void
    {
        $this->assertBothBackends('var x = true; `${x ? "yes" : "no"}`', 'yes');
    }

    public function testMethodCallInterpolation(): void
    {
        $this->assertBothBackends('`${"hello".toUpperCase()}`', 'HELLO');
    }

    // ═══════════════════ Type coercion ═══════════════════

    public function testNumberCoercion(): void
    {
        $this->assertBothBackends('`count: ${42}`', 'count: 42');
    }

    public function testBooleanCoercion(): void
    {
        $this->assertBothBackends('`value: ${true}`', 'value: true');
        $this->assertBothBackends('`value: ${false}`', 'value: false');
    }

    public function testNullCoercion(): void
    {
        $this->assertBothBackends('`value: ${null}`', 'value: null');
    }

    // ═══════════════════ Nested braces ═══════════════════

    public function testObjectInsideInterpolation(): void
    {
        $this->assertBothBackends('
            var obj = {a: 1};
            `val: ${obj.a}`
        ', 'val: 1');
    }

    // ═══════════════════ Multiline ═══════════════════

    public function testMultiline(): void
    {
        $this->assertBothBackends("`line1\nline2`", "line1\nline2");
    }

    // ═══════════════════ With other operators ═══════════════════

    public function testTemplatePlusString(): void
    {
        $this->assertBothBackends('`hello ` + "world!"', 'hello world!');
    }

    public function testStringPlusTemplate(): void
    {
        $this->assertBothBackends('"hello " + `world!`', 'hello world!');
    }

    // ═══════════════════ With globals ═══════════════════

    public function testWithGlobals(): void
    {
        $this->assertBothBackends('`Hello ${name}!`', 'Hello Alice!', ['name' => 'Alice']);
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

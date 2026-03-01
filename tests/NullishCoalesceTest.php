<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

use ScriptLite\Engine;
use PHPUnit\Framework\TestCase;

final class NullishCoalesceTest extends TestCase
{
    private Engine $engine;

    protected function setUp(): void
    {
        $this->engine = new Engine();
    }

    // ═══════════════════ Basic behavior ═══════════════════

    public function testNullFallback(): void
    {
        self::assertSame(42, $this->engine->eval('null ?? 42'));
    }

    public function testUndefinedFallback(): void
    {
        self::assertSame(42, $this->engine->eval('undefined ?? 42'));
    }

    public function testNonNullPassthrough(): void
    {
        self::assertSame(1, $this->engine->eval('1 ?? 42'));
    }

    public function testStringPassthrough(): void
    {
        self::assertSame('hello', $this->engine->eval('"hello" ?? "default"'));
    }

    // ═══════════════════ Falsy but non-nullish ═══════════════════

    public function testZeroIsNotNullish(): void
    {
        self::assertSame(0, $this->engine->eval('0 ?? 42'));
    }

    public function testEmptyStringIsNotNullish(): void
    {
        self::assertSame('', $this->engine->eval('"" ?? "default"'));
    }

    public function testFalseIsNotNullish(): void
    {
        self::assertFalse($this->engine->eval('false ?? true'));
    }

    // ═══════════════════ vs || comparison ═══════════════════

    public function testOrCoercesZero(): void
    {
        // || treats 0 as falsy → falls through to 42
        self::assertSame(42, $this->engine->eval('0 || 42'));
    }

    public function testNullishPreservesZero(): void
    {
        // ?? treats 0 as non-nullish → keeps 0
        self::assertSame(0, $this->engine->eval('0 ?? 42'));
    }

    public function testOrCoercesEmptyString(): void
    {
        self::assertSame('default', $this->engine->eval('"" || "default"'));
    }

    public function testNullishPreservesEmptyString(): void
    {
        self::assertSame('', $this->engine->eval('"" ?? "default"'));
    }

    // ═══════════════════ With variables ═══════════════════

    public function testVariableNull(): void
    {
        self::assertSame(10, $this->engine->eval('var x = null; x ?? 10'));
    }

    public function testVariableUndefined(): void
    {
        self::assertSame(10, $this->engine->eval('var x; x ?? 10'));
    }

    public function testVariableWithValue(): void
    {
        self::assertSame(5, $this->engine->eval('var x = 5; x ?? 10'));
    }

    // ═══════════════════ Chained ═══════════════════

    public function testChained(): void
    {
        self::assertSame(3, $this->engine->eval('null ?? undefined ?? 3'));
    }

    public function testChainedFirstWins(): void
    {
        self::assertSame(1, $this->engine->eval('1 ?? 2 ?? 3'));
    }

    // ═══════════════════ With optional chaining ═══════════════════

    public function testWithOptionalChaining(): void
    {
        self::assertSame('default', $this->engine->eval('var obj = null; obj?.name ?? "default"'));
    }

    public function testWithOptionalChainingValue(): void
    {
        self::assertSame('Alice', $this->engine->eval('var obj = {name: "Alice"}; obj?.name ?? "default"'));
    }

    // ═══════════════════ Transpiler path ═══════════════════

    public function testTranspilerNullFallback(): void
    {
        $php = $this->engine->transpile('x ?? 42', ['x' => null]);
        self::assertSame(42, $this->engine->evalTranspiled($php, ['x' => null]));
    }

    public function testTranspilerNonNullPassthrough(): void
    {
        $php = $this->engine->transpile('x ?? 42', ['x' => null]);
        self::assertSame(0, $this->engine->evalTranspiled($php, ['x' => 0]));
    }

    public function testTranspilerChained(): void
    {
        $php = $this->engine->transpile('a ?? b ?? 99', ['a' => null, 'b' => null]);
        self::assertSame(99, $this->engine->evalTranspiled($php, ['a' => null, 'b' => null]));
        self::assertSame(7, $this->engine->evalTranspiled($php, ['a' => null, 'b' => 7]));
        self::assertSame(3, $this->engine->evalTranspiled($php, ['a' => 3, 'b' => 7]));
    }
}

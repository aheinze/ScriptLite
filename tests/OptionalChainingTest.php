<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

use ScriptLite\Engine;
use PHPUnit\Framework\TestCase;

final class OptionalChainingTest extends TestCase
{
    private Engine $engine;

    protected function setUp(): void
    {
        $this->engine = new Engine();
    }

    // ═══════════════════ Basic property access ═══════════════════

    public function testOptionalOnObject(): void
    {
        self::assertSame(42, $this->engine->eval('var obj = {x: 42}; obj?.x'));
    }

    public function testOptionalOnNull(): void
    {
        self::assertNull($this->engine->eval('var obj = null; obj?.x'));
    }

    public function testOptionalOnUndefined(): void
    {
        self::assertNull($this->engine->eval('var obj = undefined; obj?.x'));
    }

    // ═══════════════════ Chained optional ═══════════════════

    public function testDoubleOptionalChain(): void
    {
        self::assertSame(1, $this->engine->eval('var a = {b: {c: 1}}; a?.b?.c'));
    }

    public function testDoubleOptionalFirstNull(): void
    {
        self::assertNull($this->engine->eval('var a = null; a?.b?.c'));
    }

    public function testDoubleOptionalMiddleNull(): void
    {
        self::assertNull($this->engine->eval('var a = {b: null}; a?.b?.c'));
    }

    // ═══════════════════ Mixed with regular access ═══════════════════

    public function testOptionalThenRegular(): void
    {
        self::assertSame(5, $this->engine->eval('var a = {b: {c: 5}}; a?.b.c'));
    }

    public function testRegularThenOptional(): void
    {
        self::assertNull($this->engine->eval('var a = {b: null}; a.b?.c'));
    }

    // ═══════════════════ With expressions ═══════════════════

    public function testOptionalInTernary(): void
    {
        self::assertSame('none', $this->engine->eval('var obj = null; obj?.x ? "yes" : "none"'));
    }

    public function testOptionalWithFallback(): void
    {
        self::assertSame(99, $this->engine->eval('var obj = null; obj?.x || 99'));
    }

    public function testOptionalInConcat(): void
    {
        self::assertSame('hello world', $this->engine->eval('
            var obj = {name: "world"};
            "hello " + (obj?.name || "unknown")
        '));
    }

    // ═══════════════════ Nested objects ═══════════════════

    public function testDeepChain(): void
    {
        self::assertSame(10, $this->engine->eval('
            var data = {a: {b: {c: {d: 10}}}};
            data?.a?.b?.c?.d
        '));
    }

    public function testDeepChainBreaksEarly(): void
    {
        self::assertNull($this->engine->eval('
            var data = {a: {b: null}};
            data?.a?.b?.c?.d
        '));
    }

    // ═══════════════════ With method calls ═══════════════════

    public function testOptionalBeforeMethodCall(): void
    {
        self::assertSame(3, $this->engine->eval('
            var arr = {items: [1, 2, 3]};
            arr?.items.length
        '));
    }

    // ═══════════════════ Transpiler path ═══════════════════

    public function testTranspilerOptionalOnObject(): void
    {
        $php = $this->engine->transpile('obj?.x', ['obj' => null]);
        self::assertSame(42, $this->engine->evalTranspiled($php, ['obj' => ['x' => 42]]));
    }

    public function testTranspilerOptionalOnNull(): void
    {
        $php = $this->engine->transpile('obj?.x', ['obj' => null]);
        self::assertNull($this->engine->evalTranspiled($php, ['obj' => null]));
    }

    public function testTranspilerChained(): void
    {
        $php = $this->engine->transpile('a?.b?.c', ['a' => null]);
        self::assertSame(7, $this->engine->evalTranspiled($php, ['a' => ['b' => ['c' => 7]]]));
        self::assertNull($this->engine->evalTranspiled($php, ['a' => null]));
        self::assertNull($this->engine->evalTranspiled($php, ['a' => ['b' => null]]));
    }
}

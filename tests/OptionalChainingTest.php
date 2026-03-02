<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

class OptionalChainingTest extends ScriptLiteTestCase
{

    // ═══════════════════ Basic property access ═══════════════════

    public function testOptionalOnObject(): void
    {
        $this->assertBothBackends('var obj = {x: 42}; obj?.x', 42);
    }

    public function testOptionalOnNull(): void
    {
        $this->assertBothBackends('var obj = null; obj?.x', null);
    }

    public function testOptionalOnUndefined(): void
    {
        $this->assertBothBackends('var obj = undefined; obj?.x', null);
    }

    // ═══════════════════ Chained optional ═══════════════════

    public function testDoubleOptionalChain(): void
    {
        $this->assertBothBackends('var a = {b: {c: 1}}; a?.b?.c', 1);
    }

    public function testDoubleOptionalFirstNull(): void
    {
        $this->assertBothBackends('var a = null; a?.b?.c', null);
    }

    public function testDoubleOptionalMiddleNull(): void
    {
        $this->assertBothBackends('var a = {b: null}; a?.b?.c', null);
    }

    // ═══════════════════ Mixed with regular access ═══════════════════

    public function testOptionalThenRegular(): void
    {
        $this->assertBothBackends('var a = {b: {c: 5}}; a?.b.c', 5);
    }

    public function testRegularThenOptional(): void
    {
        $this->assertBothBackends('var a = {b: null}; a.b?.c', null);
    }

    // ═══════════════════ With expressions ═══════════════════

    public function testOptionalInTernary(): void
    {
        $this->assertBothBackends('var obj = null; obj?.x ? "yes" : "none"', 'none');
    }

    public function testOptionalWithFallback(): void
    {
        $this->assertBothBackends('var obj = null; obj?.x || 99', 99);
    }

    public function testOptionalInConcat(): void
    {
        $this->assertBothBackends('
            var obj = {name: "world"};
            "hello " + (obj?.name || "unknown")
        ', 'hello world');
    }

    // ═══════════════════ Nested objects ═══════════════════

    public function testDeepChain(): void
    {
        $this->assertBothBackends('
            var data = {a: {b: {c: {d: 10}}}};
            data?.a?.b?.c?.d
        ', 10);
    }

    public function testDeepChainBreaksEarly(): void
    {
        $this->assertBothBackends('
            var data = {a: {b: null}};
            data?.a?.b?.c?.d
        ', null);
    }

    // ═══════════════════ With method calls ═══════════════════

    public function testOptionalBeforeMethodCall(): void
    {
        $this->assertBothBackends('
            var arr = {items: [1, 2, 3]};
            arr?.items.length
        ', 3);
    }
}

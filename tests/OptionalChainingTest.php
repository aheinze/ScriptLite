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

    // ═══════════════════ Optional computed access ?.[] ═══════════════════

    public function testOptionalComputedOnObject(): void
    {
        $this->assertBothBackends('var obj = {x: 42}; obj?.["x"]', 42);
    }

    public function testOptionalComputedOnNull(): void
    {
        $this->assertBothBackends('var obj = null; obj?.["x"]', null);
    }

    public function testOptionalComputedDynamic(): void
    {
        $this->assertBothBackends('var obj = {x: 42}; var k = "x"; obj?.[k]', 42);
    }

    // ═══════════════════ Optional call ?.() ═══════════════════

    public function testOptionalCallOnFunction(): void
    {
        $this->assertBothBackends('var fn = function() { return 42; }; fn?.()', 42);
    }

    public function testOptionalCallOnNull(): void
    {
        $this->assertBothBackends('var fn = null; fn?.()', null);
    }

    public function testOptionalCallOnUndefined(): void
    {
        $this->assertBothBackends('var fn = undefined; fn?.()', null);
    }

    public function testOptionalCallWithArgs(): void
    {
        $this->assertBothBackends('var fn = function(a, b) { return a + b; }; fn?.(1, 2)', 3);
    }

    // ═══════════════════ Deep chain short-circuiting ═══════════════════

    public function testDeepChainShortCircuits(): void
    {
        $this->assertBothBackends('var a = null; a?.b.c', null);
    }

    public function testDeepChainShortCircuitsCall(): void
    {
        $this->assertBothBackends('var a = null; a?.b.c()', null);
    }

    public function testDeepChainShortCircuitsComputed(): void
    {
        $this->assertBothBackends('var a = null; a?.b["c"]', null);
    }

    public function testDeepChainSuccess(): void
    {
        $this->assertBothBackends('
            var a = {b: {c: {d: function() { return 99; }}}};
            a?.b.c.d()
        ', 99);
    }

    public function testMixedOptionalChains(): void
    {
        $this->assertBothBackends('var a = null; a?.b?.c.d', null);
        $this->assertBothBackends('var a = {b: null}; a?.b?.c.d', null);
    }

    public function testOptionalCallInChain(): void
    {
        $this->assertBothBackends('var a = null; a?.b()?.c', null);
        $this->assertBothBackends('
            var a = {b: function() { return {c: 10}; }};
            a?.b()?.c
        ', 10);
    }

    public function testOptionalMethodOnNull(): void
    {
        $this->assertBothBackends('var obj = null; obj?.toString()', null);
    }
}

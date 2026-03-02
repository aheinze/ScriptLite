<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

class NullishCoalesceTest extends ScriptLiteTestCase
{

    // ═══════════════════ Basic behavior ═══════════════════

    public function testNullFallback(): void
    {
        $this->assertBothBackends('null ?? 42', 42);
    }

    public function testUndefinedFallback(): void
    {
        $this->assertBothBackends('undefined ?? 42', 42);
    }

    public function testNonNullPassthrough(): void
    {
        $this->assertBothBackends('1 ?? 42', 1);
    }

    public function testStringPassthrough(): void
    {
        $this->assertBothBackends('"hello" ?? "default"', 'hello');
    }

    // ═══════════════════ Falsy but non-nullish ═══════════════════

    public function testZeroIsNotNullish(): void
    {
        $this->assertBothBackends('0 ?? 42', 0);
    }

    public function testEmptyStringIsNotNullish(): void
    {
        $this->assertBothBackends('"" ?? "default"', '');
    }

    public function testFalseIsNotNullish(): void
    {
        $this->assertBothBackends('false ?? true', false);
    }

    // ═══════════════════ vs || comparison ═══════════════════

    public function testOrCoercesZero(): void
    {
        // || treats 0 as falsy → falls through to 42
        $this->assertBothBackends('0 || 42', 42);
    }

    public function testNullishPreservesZero(): void
    {
        // ?? treats 0 as non-nullish → keeps 0
        $this->assertBothBackends('0 ?? 42', 0);
    }

    public function testOrCoercesEmptyString(): void
    {
        $this->assertBothBackends('"" || "default"', 'default');
    }

    public function testNullishPreservesEmptyString(): void
    {
        $this->assertBothBackends('"" ?? "default"', '');
    }

    // ═══════════════════ With variables ═══════════════════

    public function testVariableNull(): void
    {
        $this->assertBothBackends('var x = null; x ?? 10', 10);
    }

    public function testVariableUndefined(): void
    {
        $this->assertBothBackends('var x; x ?? 10', 10);
    }

    public function testVariableWithValue(): void
    {
        $this->assertBothBackends('var x = 5; x ?? 10', 5);
    }

    // ═══════════════════ Chained ═══════════════════

    public function testChained(): void
    {
        $this->assertBothBackends('null ?? undefined ?? 3', 3);
    }

    public function testChainedFirstWins(): void
    {
        $this->assertBothBackends('1 ?? 2 ?? 3', 1);
    }

    // ═══════════════════ With optional chaining ═══════════════════

    public function testWithOptionalChaining(): void
    {
        $this->assertBothBackends('var obj = null; obj?.name ?? "default"', 'default');
    }

    public function testWithOptionalChainingValue(): void
    {
        $this->assertBothBackends('var obj = {name: "Alice"}; obj?.name ?? "default"', 'Alice');
    }
}

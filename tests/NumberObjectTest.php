<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

class NumberObjectTest extends ScriptLiteTestCase
{

    // ═══════════════════ Number.isInteger ═══════════════════

    public function testNumberIsInteger(): void
    {
        $this->assertBothBackends('Number.isInteger(5)', true);
        $this->assertBothBackends('Number.isInteger(0)', true);
        $this->assertBothBackends('Number.isInteger(-3)', true);
    }

    public function testNumberIsIntegerFalse(): void
    {
        $this->assertBothBackends('Number.isInteger(3.5)', false);
        $this->assertBothBackends('Number.isInteger("5")', false);
        $this->assertBothBackends('Number.isInteger(true)', false);
        $this->assertBothBackends('Number.isInteger(null)', false);
    }

    // ═══════════════════ Number.isFinite ═══════════════════

    public function testNumberIsFinite(): void
    {
        $this->assertBothBackends('Number.isFinite(42)', true);
        $this->assertBothBackends('Number.isFinite(0)', true);
        $this->assertBothBackends('Number.isFinite(-99.5)', true);
    }

    public function testNumberIsFiniteFalse(): void
    {
        $this->assertBothBackends('Number.isFinite(1/0)', false);
        $this->assertBothBackends('Number.isFinite("42")', false);
        $this->assertBothBackends('Number.isFinite(null)', false);
    }

    // ═══════════════════ Number.isNaN ═══════════════════

    public function testNumberIsNaN(): void
    {
        $this->assertBothBackends('Number.isNaN(0/0)', true);
    }

    public function testNumberIsNaNStrict(): void
    {
        // Number.isNaN does NOT coerce — unlike global isNaN
        $this->assertBothBackends('Number.isNaN("hello")', false);
        $this->assertBothBackends('Number.isNaN(undefined)', false);
        $this->assertBothBackends('Number.isNaN(42)', false);
    }

    // ═══════════════════ Number.parseInt / Number.parseFloat ═══════════════════

    public function testNumberParseInt(): void
    {
        $this->assertBothBackends('Number.parseInt("42")', 42);
        $this->assertBothBackends('Number.parseInt("42.9")', 42);
        $this->assertBothBackends('Number.parseInt("-10")', -10);
    }

    public function testNumberParseIntRadix(): void
    {
        $this->assertBothBackends('Number.parseInt("ff", 16)', 255);
        $this->assertBothBackends('Number.parseInt("111", 2)', 7);
        $this->assertBothBackends('Number.parseInt("10", 8)', 8);
    }

    public function testNumberParseIntHexPrefix(): void
    {
        $this->assertBothBackends('Number.parseInt("0xff", 16)', 255);
    }

    public function testNumberParseIntNaN(): void
    {
        $result = $this->engine->eval('Number.parseInt("hello")');
        self::assertNan($result);
    }

    public function testNumberParseFloat(): void
    {
        $this->assertBothBackends('Number.parseFloat("3.14")', 3.14);
        $this->assertBothBackends('Number.parseFloat("42")', 42);
    }

    public function testNumberParseFloatLeadingText(): void
    {
        // parseFloat extracts leading number
        $this->assertBothBackends('Number.parseFloat("3.14abc")', 3.14);
    }

    public function testNumberParseFloatNaN(): void
    {
        $result = $this->engine->eval('Number.parseFloat("abc")');
        self::assertNan($result);
    }

    // ═══════════════════ Number Constants ═══════════════════

    public function testNumberMaxSafeInteger(): void
    {
        $this->assertBothBackends('Number.MAX_SAFE_INTEGER', 9007199254740991);
    }

    public function testNumberMinSafeInteger(): void
    {
        $this->assertBothBackends('Number.MIN_SAFE_INTEGER', -9007199254740991);
    }

    public function testNumberEpsilon(): void
    {
        $result = $this->engine->eval('Number.EPSILON');
        self::assertIsFloat($result);
        self::assertTrue($result > 0 && $result < 0.001);
    }

    public function testNumberPositiveInfinity(): void
    {
        $this->assertBothBackends('Number.POSITIVE_INFINITY', INF);
    }

    public function testNumberNegativeInfinity(): void
    {
        $this->assertBothBackends('Number.NEGATIVE_INFINITY', -INF);
    }

    public function testNumberNaN(): void
    {
        self::assertNan($this->engine->eval('Number.NaN'));
    }

    // ═══════════════════ Global parseInt / parseFloat ═══════════════════

    public function testGlobalParseInt(): void
    {
        $this->assertBothBackends('parseInt("42")', 42);
        $this->assertBothBackends('parseInt("ff", 16)', 255);
    }

    public function testGlobalParseFloat(): void
    {
        $this->assertBothBackends('parseFloat("3.14")', 3.14);
    }

    // ═══════════════════ Global isNaN / isFinite ═══════════════════

    public function testGlobalIsNaN(): void
    {
        // Global isNaN coerces to number first
        $this->assertBothBackends('isNaN("hello")', true);
        // VM-only: transpiler maps undefined to null, Ops::toNumber(null)=0 not NAN
        $this->assertVm('isNaN(undefined)', true);
        $this->assertBothBackends('isNaN(42)', false);
        $this->assertBothBackends('isNaN("42")', false);
    }

    public function testGlobalIsFinite(): void
    {
        $this->assertBothBackends('isFinite(42)', true);
        $this->assertBothBackends('isFinite("42")', true);
        $this->assertBothBackends('isFinite(1/0)', false);
        $this->assertBothBackends('isFinite("hello")', false);
    }
}

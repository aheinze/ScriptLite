<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

use ScriptLite\Engine;
use PHPUnit\Framework\TestCase;

final class NumberObjectTest extends TestCase
{
    private Engine $engine;

    protected function setUp(): void
    {
        $this->engine = new Engine();
    }

    // ═══════════════════ Number.isInteger ═══════════════════

    public function testNumberIsInteger(): void
    {
        self::assertTrue($this->engine->eval('Number.isInteger(5)'));
        self::assertTrue($this->engine->eval('Number.isInteger(0)'));
        self::assertTrue($this->engine->eval('Number.isInteger(-3)'));
    }

    public function testNumberIsIntegerFalse(): void
    {
        self::assertFalse($this->engine->eval('Number.isInteger(3.5)'));
        self::assertFalse($this->engine->eval('Number.isInteger("5")'));
        self::assertFalse($this->engine->eval('Number.isInteger(true)'));
        self::assertFalse($this->engine->eval('Number.isInteger(null)'));
    }

    // ═══════════════════ Number.isFinite ═══════════════════

    public function testNumberIsFinite(): void
    {
        self::assertTrue($this->engine->eval('Number.isFinite(42)'));
        self::assertTrue($this->engine->eval('Number.isFinite(0)'));
        self::assertTrue($this->engine->eval('Number.isFinite(-99.5)'));
    }

    public function testNumberIsFiniteFalse(): void
    {
        self::assertFalse($this->engine->eval('Number.isFinite(1/0)'));
        self::assertFalse($this->engine->eval('Number.isFinite("42")'));
        self::assertFalse($this->engine->eval('Number.isFinite(null)'));
    }

    // ═══════════════════ Number.isNaN ═══════════════════

    public function testNumberIsNaN(): void
    {
        self::assertTrue($this->engine->eval('Number.isNaN(0/0)'));
    }

    public function testNumberIsNaNStrict(): void
    {
        // Number.isNaN does NOT coerce — unlike global isNaN
        self::assertFalse($this->engine->eval('Number.isNaN("hello")'));
        self::assertFalse($this->engine->eval('Number.isNaN(undefined)'));
        self::assertFalse($this->engine->eval('Number.isNaN(42)'));
    }

    // ═══════════════════ Number.parseInt / Number.parseFloat ═══════════════════

    public function testNumberParseInt(): void
    {
        self::assertSame(42, $this->engine->eval('Number.parseInt("42")'));
        self::assertSame(42, $this->engine->eval('Number.parseInt("42.9")'));
        self::assertSame(-10, $this->engine->eval('Number.parseInt("-10")'));
    }

    public function testNumberParseIntRadix(): void
    {
        self::assertSame(255, $this->engine->eval('Number.parseInt("ff", 16)'));
        self::assertSame(7, $this->engine->eval('Number.parseInt("111", 2)'));
        self::assertSame(8, $this->engine->eval('Number.parseInt("10", 8)'));
    }

    public function testNumberParseIntHexPrefix(): void
    {
        self::assertSame(255, $this->engine->eval('Number.parseInt("0xff", 16)'));
    }

    public function testNumberParseIntNaN(): void
    {
        $result = $this->engine->eval('Number.parseInt("hello")');
        self::assertNan($result);
    }

    public function testNumberParseFloat(): void
    {
        self::assertSame(3.14, $this->engine->eval('Number.parseFloat("3.14")'));
        self::assertSame(42, $this->engine->eval('Number.parseFloat("42")'));
    }

    public function testNumberParseFloatLeadingText(): void
    {
        // parseFloat extracts leading number
        self::assertSame(3.14, $this->engine->eval('Number.parseFloat("3.14abc")'));
    }

    public function testNumberParseFloatNaN(): void
    {
        $result = $this->engine->eval('Number.parseFloat("abc")');
        self::assertNan($result);
    }

    // ═══════════════════ Number Constants ═══════════════════

    public function testNumberMaxSafeInteger(): void
    {
        self::assertSame(9007199254740991, $this->engine->eval('Number.MAX_SAFE_INTEGER'));
    }

    public function testNumberMinSafeInteger(): void
    {
        self::assertSame(-9007199254740991, $this->engine->eval('Number.MIN_SAFE_INTEGER'));
    }

    public function testNumberEpsilon(): void
    {
        $result = $this->engine->eval('Number.EPSILON');
        self::assertIsFloat($result);
        self::assertTrue($result > 0 && $result < 0.001);
    }

    public function testNumberPositiveInfinity(): void
    {
        self::assertSame(INF, $this->engine->eval('Number.POSITIVE_INFINITY'));
    }

    public function testNumberNegativeInfinity(): void
    {
        self::assertSame(-INF, $this->engine->eval('Number.NEGATIVE_INFINITY'));
    }

    public function testNumberNaN(): void
    {
        self::assertNan($this->engine->eval('Number.NaN'));
    }

    // ═══════════════════ Global parseInt / parseFloat ═══════════════════

    public function testGlobalParseInt(): void
    {
        self::assertSame(42, $this->engine->eval('parseInt("42")'));
        self::assertSame(255, $this->engine->eval('parseInt("ff", 16)'));
    }

    public function testGlobalParseFloat(): void
    {
        self::assertSame(3.14, $this->engine->eval('parseFloat("3.14")'));
    }

    // ═══════════════════ Global isNaN / isFinite ═══════════════════

    public function testGlobalIsNaN(): void
    {
        // Global isNaN coerces to number first
        self::assertTrue($this->engine->eval('isNaN("hello")'));
        self::assertTrue($this->engine->eval('isNaN(undefined)'));
        self::assertFalse($this->engine->eval('isNaN(42)'));
        self::assertFalse($this->engine->eval('isNaN("42")'));
    }

    public function testGlobalIsFinite(): void
    {
        self::assertTrue($this->engine->eval('isFinite(42)'));
        self::assertTrue($this->engine->eval('isFinite("42")'));
        self::assertFalse($this->engine->eval('isFinite(1/0)'));
        self::assertFalse($this->engine->eval('isFinite("hello")'));
    }
}

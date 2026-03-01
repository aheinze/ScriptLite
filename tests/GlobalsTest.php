<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

use ScriptLite\Engine;
use PHPUnit\Framework\TestCase;

final class GlobalsTest extends TestCase
{
    private Engine $engine;

    protected function setUp(): void
    {
        $this->engine = new Engine();
    }

    // ═══════════════════ Array Static Methods ═══════════════════

    public function testArrayIsArray(): void
    {
        self::assertTrue($this->engine->eval('Array.isArray([1, 2, 3])'));
        self::assertTrue($this->engine->eval('Array.isArray([])'));
    }

    public function testArrayIsArrayFalse(): void
    {
        self::assertFalse($this->engine->eval('Array.isArray({})'));
        self::assertFalse($this->engine->eval('Array.isArray("hello")'));
        self::assertFalse($this->engine->eval('Array.isArray(42)'));
        self::assertFalse($this->engine->eval('Array.isArray(null)'));
    }

    public function testArrayFrom(): void
    {
        $result = $this->engine->eval('
            var original = [1, 2, 3];
            var copy = Array.from(original);
            original.push(4);
            copy;
        ');
        self::assertSame([1, 2, 3], $result); // copy is independent
    }

    public function testArrayOf(): void
    {
        $result = $this->engine->eval('Array.of(1, 2, 3)');
        self::assertSame([1, 2, 3], $result);
    }

    public function testArrayOfSingle(): void
    {
        $result = $this->engine->eval('Array.of(5)');
        self::assertSame([5], $result);
    }

    // ═══════════════════ Namespace Globals (console, Math) ═══════════════════

    public function testConsoleLogNamespace(): void
    {
        $this->engine->eval('console.log("hello", "world")');
        self::assertSame("hello world\n", $this->engine->getOutput());
    }

    public function testMathFloorNamespace(): void
    {
        self::assertSame(3, $this->engine->eval('Math.floor(3.7)'));
    }

    public function testMathCeilNamespace(): void
    {
        self::assertSame(4, $this->engine->eval('Math.ceil(3.2)'));
    }

    public function testMathAbsNamespace(): void
    {
        self::assertSame(5, $this->engine->eval('Math.abs(-5)'));
    }

    public function testMathMaxNamespace(): void
    {
        self::assertSame(10, $this->engine->eval('Math.max(3, 10)'));
    }

    public function testMathMinNamespace(): void
    {
        self::assertSame(3, $this->engine->eval('Math.min(3, 10)'));
    }

    public function testMathRound(): void
    {
        self::assertSame(4, $this->engine->eval('Math.round(3.7)'));
        self::assertSame(3, $this->engine->eval('Math.round(3.2)'));
    }

    public function testMathPI(): void
    {
        $result = $this->engine->eval('Math.PI');
        self::assertEqualsWithDelta(3.14159, $result, 0.001);
    }
}

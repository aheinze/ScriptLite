<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

use ScriptLite\Engine;
use PHPUnit\Framework\TestCase;

final class StringMethodsTest extends TestCase
{
    private Engine $engine;

    protected function setUp(): void
    {
        $this->engine = new Engine();
    }

    // ═══════════════════ charAt / charCodeAt ═══════════════════

    public function testCharAt(): void
    {
        self::assertSame('h', $this->engine->eval('"hello".charAt(0)'));
        self::assertSame('e', $this->engine->eval('"hello".charAt(1)'));
        self::assertSame('o', $this->engine->eval('"hello".charAt(4)'));
    }

    public function testCharAtOutOfBounds(): void
    {
        self::assertSame('', $this->engine->eval('"hello".charAt(10)'));
        self::assertSame('', $this->engine->eval('"hello".charAt(-1)'));
    }

    public function testCharCodeAt(): void
    {
        self::assertSame(104, $this->engine->eval('"hello".charCodeAt(0)')); // 'h'
        self::assertSame(65, $this->engine->eval('"A".charCodeAt(0)'));
    }

    public function testCharCodeAtOutOfBounds(): void
    {
        $result = $this->engine->eval('"hello".charCodeAt(99)');
        self::assertNan($result);
    }

    // ═══════════════════ indexOf / lastIndexOf / includes ═══════════════════

    public function testIndexOf(): void
    {
        self::assertSame(2, $this->engine->eval('"hello world".indexOf("llo")'));
        self::assertSame(-1, $this->engine->eval('"hello".indexOf("xyz")'));
    }

    public function testIndexOfFromIndex(): void
    {
        self::assertSame(6, $this->engine->eval('"hello hello".indexOf("hello", 1)'));
    }

    public function testLastIndexOf(): void
    {
        self::assertSame(6, $this->engine->eval('"hello hello".lastIndexOf("hello")'));
        self::assertSame(-1, $this->engine->eval('"hello".lastIndexOf("xyz")'));
    }

    public function testIncludes(): void
    {
        self::assertTrue($this->engine->eval('"hello world".includes("world")'));
        self::assertFalse($this->engine->eval('"hello world".includes("xyz")'));
    }

    public function testIncludesFromIndex(): void
    {
        self::assertFalse($this->engine->eval('"hello".includes("hel", 1)'));
        self::assertTrue($this->engine->eval('"hello".includes("ell", 1)'));
    }

    // ═══════════════════ startsWith / endsWith ═══════════════════

    public function testStartsWith(): void
    {
        self::assertTrue($this->engine->eval('"hello world".startsWith("hello")'));
        self::assertFalse($this->engine->eval('"hello world".startsWith("world")'));
    }

    public function testStartsWithPosition(): void
    {
        self::assertTrue($this->engine->eval('"hello world".startsWith("world", 6)'));
    }

    public function testEndsWith(): void
    {
        self::assertTrue($this->engine->eval('"hello world".endsWith("world")'));
        self::assertFalse($this->engine->eval('"hello world".endsWith("hello")'));
    }

    public function testEndsWithEndPosition(): void
    {
        self::assertTrue($this->engine->eval('"hello world".endsWith("hello", 5)'));
    }

    // ═══════════════════ slice / substring ═══════════════════

    public function testSlice(): void
    {
        self::assertSame('llo', $this->engine->eval('"hello".slice(2)'));
        self::assertSame('ell', $this->engine->eval('"hello".slice(1, 4)'));
    }

    public function testSliceNegative(): void
    {
        self::assertSame('lo', $this->engine->eval('"hello".slice(-2)'));
        self::assertSame('ell', $this->engine->eval('"hello".slice(1, -1)'));
    }

    public function testSubstring(): void
    {
        self::assertSame('ello', $this->engine->eval('"hello".substring(1)'));
        self::assertSame('ell', $this->engine->eval('"hello".substring(1, 4)'));
    }

    public function testSubstringSwapsIfStartGreaterThanEnd(): void
    {
        self::assertSame('ell', $this->engine->eval('"hello".substring(4, 1)'));
    }

    // ═══════════════════ toUpperCase / toLowerCase ═══════════════════

    public function testToUpperCase(): void
    {
        self::assertSame('HELLO', $this->engine->eval('"hello".toUpperCase()'));
        self::assertSame('HELLO WORLD', $this->engine->eval('"Hello World".toUpperCase()'));
    }

    public function testToLowerCase(): void
    {
        self::assertSame('hello', $this->engine->eval('"HELLO".toLowerCase()'));
        self::assertSame('hello world', $this->engine->eval('"Hello World".toLowerCase()'));
    }

    // ═══════════════════ trim / trimStart / trimEnd ═══════════════════

    public function testTrim(): void
    {
        self::assertSame('hello', $this->engine->eval('"  hello  ".trim()'));
    }

    public function testTrimStart(): void
    {
        self::assertSame('hello  ', $this->engine->eval('"  hello  ".trimStart()'));
    }

    public function testTrimEnd(): void
    {
        self::assertSame('  hello', $this->engine->eval('"  hello  ".trimEnd()'));
    }

    // ═══════════════════ split ═══════════════════

    public function testSplit(): void
    {
        $result = $this->engine->eval('"a,b,c".split(",")');
        self::assertSame(['a', 'b', 'c'], $result);
    }

    public function testSplitWithLimit(): void
    {
        $result = $this->engine->eval('"a,b,c,d".split(",", 2)');
        self::assertSame(['a', 'b'], $result);
    }

    public function testSplitEmptySeparator(): void
    {
        $result = $this->engine->eval('"abc".split("")');
        self::assertSame(['a', 'b', 'c'], $result);
    }

    public function testSplitNoArgs(): void
    {
        $result = $this->engine->eval('"hello world".split()');
        self::assertSame(['hello world'], $result);
    }

    // ═══════════════════ replace ═══════════════════

    public function testReplace(): void
    {
        self::assertSame('hxllo', $this->engine->eval('"hello".replace("e", "x")'));
    }

    public function testReplaceFirstOnly(): void
    {
        // JS replace() only replaces first occurrence
        self::assertSame('hxllo hello', $this->engine->eval('"hello hello".replace("e", "x")'));
    }

    public function testReplaceNotFound(): void
    {
        self::assertSame('hello', $this->engine->eval('"hello".replace("xyz", "abc")'));
    }

    // ═══════════════════ repeat ═══════════════════

    public function testRepeat(): void
    {
        self::assertSame('abcabcabc', $this->engine->eval('"abc".repeat(3)'));
        self::assertSame('', $this->engine->eval('"abc".repeat(0)'));
    }

    // ═══════════════════ padStart / padEnd ═══════════════════

    public function testPadStart(): void
    {
        self::assertSame('00005', $this->engine->eval('"5".padStart(5, "0")'));
        self::assertSame('     5', $this->engine->eval('"5".padStart(6)'));
    }

    public function testPadEnd(): void
    {
        self::assertSame('50000', $this->engine->eval('"5".padEnd(5, "0")'));
        self::assertSame('hello...', $this->engine->eval('"hello".padEnd(8, ".")'));
    }

    public function testPadStartNoOpWhenLongEnough(): void
    {
        self::assertSame('hello', $this->engine->eval('"hello".padStart(3, "x")'));
    }

    // ═══════════════════ concat ═══════════════════

    public function testConcat(): void
    {
        self::assertSame('hello world', $this->engine->eval('"hello".concat(" ", "world")'));
        self::assertSame('abc', $this->engine->eval('"a".concat("b", "c")'));
    }

    // ═══════════════════ Chaining ═══════════════════

    public function testMethodChaining(): void
    {
        self::assertSame('HELLO', $this->engine->eval('"  hello  ".trim().toUpperCase()'));
    }

    public function testSplitMapChain(): void
    {
        $result = $this->engine->eval('
            "1,2,3".split(",").map(function(x) { return x + "!"; })
        ');
        self::assertSame(['1!', '2!', '3!'], $result);
    }

    // ═══════════════════ String.fromCharCode ═══════════════════

    public function testStringFromCharCode(): void
    {
        self::assertSame('A', $this->engine->eval('String.fromCharCode(65)'));
        self::assertSame('ABC', $this->engine->eval('String.fromCharCode(65, 66, 67)'));
    }
}

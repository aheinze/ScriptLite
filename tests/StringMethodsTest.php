<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

class StringMethodsTest extends ScriptLiteTestCase
{

    // ═══════════════════ charAt / charCodeAt ═══════════════════

    public function testCharAt(): void
    {
        $this->assertBothBackends('"hello".charAt(0)', 'h');
        $this->assertBothBackends('"hello".charAt(1)', 'e');
        $this->assertBothBackends('"hello".charAt(4)', 'o');
    }

    public function testCharAtOutOfBounds(): void
    {
        $this->assertBothBackends('"hello".charAt(10)', '');
        $this->assertBothBackends('"hello".charAt(-1)', '');
    }

    public function testCharCodeAt(): void
    {
        $this->assertBothBackends('"hello".charCodeAt(0)', 104); // 'h'
        $this->assertBothBackends('"A".charCodeAt(0)', 65);
    }

    public function testCharCodeAtOutOfBounds(): void
    {
        $result = $this->engine->eval('"hello".charCodeAt(99)');
        self::assertNan($result);
    }

    // ═══════════════════ indexOf / lastIndexOf / includes ═══════════════════

    public function testIndexOf(): void
    {
        $this->assertBothBackends('"hello world".indexOf("llo")', 2);
        $this->assertBothBackends('"hello".indexOf("xyz")', -1);
    }

    public function testIndexOfFromIndex(): void
    {
        $this->assertBothBackends('"hello hello".indexOf("hello", 1)', 6);
    }

    public function testLastIndexOf(): void
    {
        $this->assertBothBackends('"hello hello".lastIndexOf("hello")', 6);
        $this->assertBothBackends('"hello".lastIndexOf("xyz")', -1);
    }

    public function testIncludes(): void
    {
        $this->assertBothBackends('"hello world".includes("world")', true);
        $this->assertBothBackends('"hello world".includes("xyz")', false);
    }

    public function testIncludesFromIndex(): void
    {
        $this->assertBothBackends('"hello".includes("hel", 1)', false);
        $this->assertBothBackends('"hello".includes("ell", 1)', true);
    }

    // ═══════════════════ startsWith / endsWith ═══════════════════

    public function testStartsWith(): void
    {
        $this->assertBothBackends('"hello world".startsWith("hello")', true);
        $this->assertBothBackends('"hello world".startsWith("world")', false);
    }

    public function testStartsWithPosition(): void
    {
        $this->assertBothBackends('"hello world".startsWith("world", 6)', true);
    }

    public function testEndsWith(): void
    {
        $this->assertBothBackends('"hello world".endsWith("world")', true);
        $this->assertBothBackends('"hello world".endsWith("hello")', false);
    }

    public function testEndsWithEndPosition(): void
    {
        $this->assertBothBackends('"hello world".endsWith("hello", 5)', true);
    }

    // ═══════════════════ slice / substring ═══════════════════

    public function testSlice(): void
    {
        $this->assertBothBackends('"hello".slice(2)', 'llo');
        $this->assertBothBackends('"hello".slice(1, 4)', 'ell');
    }

    public function testSliceNegative(): void
    {
        $this->assertBothBackends('"hello".slice(-2)', 'lo');
        $this->assertBothBackends('"hello".slice(1, -1)', 'ell');
    }

    public function testSubstring(): void
    {
        $this->assertBothBackends('"hello".substring(1)', 'ello');
        $this->assertBothBackends('"hello".substring(1, 4)', 'ell');
    }

    public function testSubstringSwapsIfStartGreaterThanEnd(): void
    {
        $this->assertBothBackends('"hello".substring(4, 1)', 'ell');
    }

    // ═══════════════════ toUpperCase / toLowerCase ═══════════════════

    public function testToUpperCase(): void
    {
        $this->assertBothBackends('"hello".toUpperCase()', 'HELLO');
        $this->assertBothBackends('"Hello World".toUpperCase()', 'HELLO WORLD');
    }

    public function testToLowerCase(): void
    {
        $this->assertBothBackends('"HELLO".toLowerCase()', 'hello');
        $this->assertBothBackends('"Hello World".toLowerCase()', 'hello world');
    }

    // ═══════════════════ trim / trimStart / trimEnd ═══════════════════

    public function testTrim(): void
    {
        $this->assertBothBackends('"  hello  ".trim()', 'hello');
    }

    public function testTrimStart(): void
    {
        $this->assertBothBackends('"  hello  ".trimStart()', 'hello  ');
    }

    public function testTrimEnd(): void
    {
        $this->assertBothBackends('"  hello  ".trimEnd()', '  hello');
    }

    // ═══════════════════ split ═══════════════════

    public function testSplit(): void
    {
        $this->assertBothBackends('"a,b,c".split(",")', ['a', 'b', 'c']);
    }

    public function testSplitWithLimit(): void
    {
        $this->assertBothBackends('"a,b,c,d".split(",", 2)', ['a', 'b']);
    }

    public function testSplitEmptySeparator(): void
    {
        $this->assertBothBackends('"abc".split("")', ['a', 'b', 'c']);
    }

    public function testSplitNoArgs(): void
    {
        $this->assertBothBackends('"hello world".split()', ['hello world']);
    }

    // ═══════════════════ replace ═══════════════════

    public function testReplace(): void
    {
        $this->assertBothBackends('"hello".replace("e", "x")', 'hxllo');
    }

    public function testReplaceFirstOnly(): void
    {
        // JS replace() only replaces first occurrence
        $this->assertBothBackends('"hello hello".replace("e", "x")', 'hxllo hello');
    }

    public function testReplaceNotFound(): void
    {
        $this->assertBothBackends('"hello".replace("xyz", "abc")', 'hello');
    }

    // ═══════════════════ repeat ═══════════════════

    public function testRepeat(): void
    {
        $this->assertBothBackends('"abc".repeat(3)', 'abcabcabc');
        $this->assertBothBackends('"abc".repeat(0)', '');
    }

    // ═══════════════════ padStart / padEnd ═══════════════════

    public function testPadStart(): void
    {
        $this->assertBothBackends('"5".padStart(5, "0")', '00005');
        $this->assertBothBackends('"5".padStart(6)', '     5');
    }

    public function testPadEnd(): void
    {
        $this->assertBothBackends('"5".padEnd(5, "0")', '50000');
        $this->assertBothBackends('"hello".padEnd(8, ".")', 'hello...');
    }

    public function testPadStartNoOpWhenLongEnough(): void
    {
        $this->assertBothBackends('"hello".padStart(3, "x")', 'hello');
    }

    // ═══════════════════ concat ═══════════════════

    public function testConcat(): void
    {
        $this->assertBothBackends('"hello".concat(" ", "world")', 'hello world');
        $this->assertBothBackends('"a".concat("b", "c")', 'abc');
    }

    // ═══════════════════ Chaining ═══════════════════

    public function testMethodChaining(): void
    {
        $this->assertBothBackends('"  hello  ".trim().toUpperCase()', 'HELLO');
    }

    public function testSplitMapChain(): void
    {
        $this->assertBothBackends('
            "1,2,3".split(",").map(function(x) { return x + "!"; })
        ', ['1!', '2!', '3!']);
    }

    // ═══════════════════ String.fromCharCode ═══════════════════

    public function testStringFromCharCode(): void
    {
        $this->assertBothBackends('String.fromCharCode(65)', 'A');
        $this->assertBothBackends('String.fromCharCode(65, 66, 67)', 'ABC');
    }
}

<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

use ScriptLite\Engine;
use PHPUnit\Framework\TestCase;

final class RegexTest extends TestCase
{
    private Engine $engine;

    protected function setUp(): void
    {
        $this->engine = new Engine();
    }

    // ═══════════════════ Regex Literals ═══════════════════

    public function testTypeofRegex(): void
    {
        self::assertSame('object', $this->engine->eval('typeof /abc/'));
    }

    public function testRegexToString(): void
    {
        self::assertSame('/abc/gi', $this->engine->eval('"" + /abc/gi'));
    }

    public function testRegexSource(): void
    {
        self::assertSame('abc', $this->engine->eval('/abc/gi.source'));
    }

    public function testRegexFlags(): void
    {
        self::assertSame('gi', $this->engine->eval('/abc/gi.flags'));
    }

    public function testRegexGlobal(): void
    {
        self::assertTrue($this->engine->eval('/abc/g.global'));
        self::assertFalse($this->engine->eval('/abc/.global'));
    }

    public function testRegexIgnoreCase(): void
    {
        self::assertTrue($this->engine->eval('/abc/i.ignoreCase'));
        self::assertFalse($this->engine->eval('/abc/.ignoreCase'));
    }

    // ═══════════════════ RegExp Constructor ═══════════════════

    public function testRegExpConstructor(): void
    {
        self::assertSame('object', $this->engine->eval('typeof new RegExp("abc", "i")'));
    }

    public function testRegExpConstructorTest(): void
    {
        self::assertTrue($this->engine->eval('new RegExp("abc", "i").test("ABC")'));
    }

    public function testRegExpConstructorNoFlags(): void
    {
        self::assertTrue($this->engine->eval('new RegExp("hello").test("hello world")'));
    }

    // ═══════════════════ test() ═══════════════════

    public function testTestMethod(): void
    {
        self::assertTrue($this->engine->eval('/\\d+/.test("hello123")'));
        self::assertFalse($this->engine->eval('/\\d+/.test("hello")'));
    }

    public function testTestCaseInsensitive(): void
    {
        self::assertTrue($this->engine->eval('/hello/i.test("HELLO WORLD")'));
        self::assertFalse($this->engine->eval('/hello/.test("HELLO WORLD")'));
    }

    // ═══════════════════ exec() ═══════════════════

    public function testExecBasic(): void
    {
        $result = $this->engine->eval('/\\d+/.exec("abc123def")');
        self::assertSame('123', $result[0]);
    }

    public function testExecWithGroups(): void
    {
        $result = $this->engine->eval('/(\\d+)-(\\d+)/.exec("abc123-456def")');
        self::assertSame('123-456', $result[0]);
        self::assertSame('123', $result[1]);
        self::assertSame('456', $result[2]);
    }

    public function testExecIndex(): void
    {
        $result = $this->engine->eval('var m = /\\d+/.exec("abc123"); m.index');
        self::assertSame(3, $result);
    }

    public function testExecNoMatch(): void
    {
        $result = $this->engine->eval('/\\d+/.exec("hello")');
        self::assertNull($result);
    }

    // ═══════════════════ String.match() ═══════════════════

    public function testStringMatchNoGlobal(): void
    {
        $result = $this->engine->eval('"abc123def".match(/\\d+/)');
        self::assertSame('123', $result[0]);
    }

    public function testStringMatchGlobal(): void
    {
        $result = $this->engine->eval('"abc123def456".match(/\\d+/g)');
        self::assertSame(['123', '456'], $result);
    }

    public function testStringMatchNoMatch(): void
    {
        $result = $this->engine->eval('"hello".match(/\\d+/)');
        self::assertNull($result);
    }

    // ═══════════════════ String.search() ═══════════════════

    public function testStringSearch(): void
    {
        self::assertSame(3, $this->engine->eval('"abc123".search(/\\d+/)'));
    }

    public function testStringSearchNotFound(): void
    {
        self::assertSame(-1, $this->engine->eval('"hello".search(/\\d+/)'));
    }

    // ═══════════════════ String.replace() with regex ═══════════════════

    public function testStringReplaceRegex(): void
    {
        self::assertSame('abcXdef', $this->engine->eval('"abc123def".replace(/\\d+/, "X")'));
    }

    public function testStringReplaceRegexGlobal(): void
    {
        self::assertSame('abcXdefX', $this->engine->eval('"abc123def456".replace(/\\d+/g, "X")'));
    }

    public function testStringReplaceRegexFirstOnly(): void
    {
        // Without g flag, only first match is replaced
        self::assertSame('abcXdef456', $this->engine->eval('"abc123def456".replace(/\\d+/, "X")'));
    }

    // ═══════════════════ String.split() with regex ═══════════════════

    public function testStringSplitRegex(): void
    {
        $result = $this->engine->eval('"a1b2c3".split(/\\d/)');
        self::assertSame(['a', 'b', 'c', ''], $result);
    }

    public function testStringSplitRegexMultiChar(): void
    {
        $result = $this->engine->eval('"hello   world  foo".split(/\\s+/)');
        self::assertSame(['hello', 'world', 'foo'], $result);
    }

    // ═══════════════════ String.matchAll() ═══════════════════

    public function testStringMatchAll(): void
    {
        $result = $this->engine->eval('
            var matches = "test1 test2".matchAll(/test(\\d)/g);
            matches.length;
        ');
        self::assertSame(2, $result);
    }

    public function testStringMatchAllEntries(): void
    {
        $result = $this->engine->eval('
            var matches = "a1b2".matchAll(/[a-z](\\d)/g);
            matches[0][0];
        ');
        self::assertSame('a1', $result);
    }

    // ═══════════════════ Regex in expressions ═══════════════════

    public function testRegexAfterEqual(): void
    {
        self::assertTrue($this->engine->eval('var r = /abc/; r.test("abc")'));
    }

    public function testRegexAfterReturn(): void
    {
        self::assertTrue($this->engine->eval('
            function getPattern() { return /\\d+/; }
            getPattern().test("123");
        '));
    }

    public function testRegexInCondition(): void
    {
        self::assertSame('yes', $this->engine->eval('
            /\\d+/.test("abc123") ? "yes" : "no"
        '));
    }

    public function testDivisionStillWorks(): void
    {
        // After a number/identifier, `/` should be division, not regex
        self::assertSame(5, $this->engine->eval('10 / 2'));
        self::assertSame(3, $this->engine->eval('var x = 9; x / 3'));
    }

    // ═══════════════════ Existing tests still pass ═══════════════════

    public function testStringReplaceStringStillWorks(): void
    {
        self::assertSame('hxllo', $this->engine->eval('"hello".replace("e", "x")'));
    }

    public function testStringSplitStringStillWorks(): void
    {
        $result = $this->engine->eval('"a,b,c".split(",")');
        self::assertSame(['a', 'b', 'c'], $result);
    }
}

<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

class RegexTest extends ScriptLiteTestCase
{

    // ═══════════════════ Regex Literals ═══════════════════

    public function testTypeofRegex(): void
    {
        $this->assertBothBackends('typeof /abc/', 'object');
    }

    public function testRegexToString(): void
    {
        $this->assertBothBackends('"" + /abc/gi', '/abc/gi');
    }

    public function testRegexSource(): void
    {
        $this->assertBothBackends('/abc/gi.source', 'abc');
    }

    public function testRegexFlags(): void
    {
        $this->assertBothBackends('/abc/gi.flags', 'gi');
    }

    public function testRegexGlobal(): void
    {
        $this->assertBothBackends('/abc/g.global', true);
        $this->assertBothBackends('/abc/.global', false);
    }

    public function testRegexIgnoreCase(): void
    {
        $this->assertBothBackends('/abc/i.ignoreCase', true);
        $this->assertBothBackends('/abc/.ignoreCase', false);
    }

    // ═══════════════════ RegExp Constructor ═══════════════════

    public function testRegExpConstructor(): void
    {
        $this->assertBothBackends('typeof new RegExp("abc", "i")', 'object');
    }

    public function testRegExpConstructorTest(): void
    {
        $this->assertBothBackends('new RegExp("abc", "i").test("ABC")', true);
    }

    public function testRegExpConstructorNoFlags(): void
    {
        $this->assertBothBackends('new RegExp("hello").test("hello world")', true);
    }

    // ═══════════════════ test() ═══════════════════

    public function testTestMethod(): void
    {
        $this->assertBothBackends('/\\d+/.test("hello123")', true);
        $this->assertBothBackends('/\\d+/.test("hello")', false);
    }

    public function testTestCaseInsensitive(): void
    {
        $this->assertBothBackends('/hello/i.test("HELLO WORLD")', true);
        $this->assertBothBackends('/hello/.test("HELLO WORLD")', false);
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
        $this->assertBothBackends('var m = /\\d+/.exec("abc123"); m.index', 3);
    }

    public function testExecNoMatch(): void
    {
        $this->assertBothBackends('/\\d+/.exec("hello")', null);
    }

    // ═══════════════════ String.match() ═══════════════════

    public function testStringMatchNoGlobal(): void
    {
        $result = $this->engine->eval('"abc123def".match(/\\d+/)');
        self::assertSame('123', $result[0]);
    }

    public function testStringMatchGlobal(): void
    {
        $this->assertBothBackends('"abc123def456".match(/\\d+/g)', ['123', '456']);
    }

    public function testStringMatchNoMatch(): void
    {
        $this->assertBothBackends('"hello".match(/\\d+/)', null);
    }

    // ═══════════════════ String.search() ═══════════════════

    public function testStringSearch(): void
    {
        $this->assertBothBackends('"abc123".search(/\\d+/)', 3);
    }

    public function testStringSearchNotFound(): void
    {
        $this->assertBothBackends('"hello".search(/\\d+/)', -1);
    }

    // ═══════════════════ String.replace() with regex ═══════════════════

    public function testStringReplaceRegex(): void
    {
        $this->assertBothBackends('"abc123def".replace(/\\d+/, "X")', 'abcXdef');
    }

    public function testStringReplaceRegexGlobal(): void
    {
        $this->assertBothBackends('"abc123def456".replace(/\\d+/g, "X")', 'abcXdefX');
    }

    public function testStringReplaceRegexFirstOnly(): void
    {
        // Without g flag, only first match is replaced
        $this->assertBothBackends('"abc123def456".replace(/\\d+/, "X")', 'abcXdef456');
    }

    // ═══════════════════ String.split() with regex ═══════════════════

    public function testStringSplitRegex(): void
    {
        $this->assertBothBackends('"a1b2c3".split(/\\d/)', ['a', 'b', 'c', '']);
    }

    public function testStringSplitRegexMultiChar(): void
    {
        $this->assertBothBackends('"hello   world  foo".split(/\\s+/)', ['hello', 'world', 'foo']);
    }

    // ═══════════════════ String.matchAll() ═══════════════════

    public function testStringMatchAll(): void
    {
        $this->assertBothBackends('
            var matches = "test1 test2".matchAll(/test(\\d)/g);
            matches.length;
        ', 2);
    }

    public function testStringMatchAllEntries(): void
    {
        $this->assertBothBackends('
            var matches = "a1b2".matchAll(/[a-z](\\d)/g);
            matches[0][0];
        ', 'a1');
    }

    // ═══════════════════ Regex in expressions ═══════════════════

    public function testRegexAfterEqual(): void
    {
        $this->assertBothBackends('var r = /abc/; r.test("abc")', true);
    }

    public function testRegexAfterReturn(): void
    {
        $this->assertBothBackends('
            function getPattern() { return /\\d+/; }
            getPattern().test("123");
        ', true);
    }

    public function testRegexInCondition(): void
    {
        $this->assertBothBackends('
            /\\d+/.test("abc123") ? "yes" : "no"
        ', 'yes');
    }

    public function testDivisionStillWorks(): void
    {
        // After a number/identifier, `/` should be division, not regex
        $this->assertBothBackends('10 / 2', 5);
        $this->assertBothBackends('var x = 9; x / 3', 3);
    }

    // ═══════════════════ Existing tests still pass ═══════════════════

    public function testStringReplaceStringStillWorks(): void
    {
        $this->assertBothBackends('"hello".replace("e", "x")', 'hxllo');
    }

    public function testStringSplitStringStillWorks(): void
    {
        $this->assertBothBackends('"a,b,c".split(",")', ['a', 'b', 'c']);
    }
}

<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

use ScriptLite\Engine;
use PHPUnit\Framework\TestCase;

final class SwitchTest extends TestCase
{
    private Engine $engine;

    protected function setUp(): void
    {
        $this->engine = new Engine();
    }

    // ═══════════════════ Basic matching ═══════════════════

    public function testBasicCaseMatch(): void
    {
        $code = '
            var result = "";
            var x = 2;
            switch (x) {
                case 1:
                    result = "one";
                    break;
                case 2:
                    result = "two";
                    break;
                case 3:
                    result = "three";
                    break;
            }
            result
        ';
        self::assertSame('two', $this->engine->eval($code));
    }

    public function testFirstCaseMatch(): void
    {
        $code = '
            var result = "";
            switch (1) {
                case 1:
                    result = "one";
                    break;
                case 2:
                    result = "two";
                    break;
            }
            result
        ';
        self::assertSame('one', $this->engine->eval($code));
    }

    // ═══════════════════ Default ═══════════════════

    public function testDefaultCase(): void
    {
        $code = '
            var result = "";
            switch (99) {
                case 1:
                    result = "one";
                    break;
                default:
                    result = "other";
                    break;
            }
            result
        ';
        self::assertSame('other', $this->engine->eval($code));
    }

    public function testDefaultNotReachedWhenMatched(): void
    {
        $code = '
            var result = "";
            switch (1) {
                case 1:
                    result = "one";
                    break;
                default:
                    result = "other";
                    break;
            }
            result
        ';
        self::assertSame('one', $this->engine->eval($code));
    }

    // ═══════════════════ Fall-through ═══════════════════

    public function testFallThrough(): void
    {
        $code = '
            var result = "";
            switch (1) {
                case 1:
                    result = result + "a";
                case 2:
                    result = result + "b";
                case 3:
                    result = result + "c";
                    break;
            }
            result
        ';
        // Matches case 1, falls through to 2 and 3
        self::assertSame('abc', $this->engine->eval($code));
    }

    public function testFallThroughToDefault(): void
    {
        $code = '
            var result = "";
            switch (2) {
                case 1:
                    result = result + "a";
                    break;
                case 2:
                    result = result + "b";
                default:
                    result = result + "c";
            }
            result
        ';
        self::assertSame('bc', $this->engine->eval($code));
    }

    // ═══════════════════ No match, no default ═══════════════════

    public function testNoMatchNoDefault(): void
    {
        $code = '
            var result = "unchanged";
            switch (99) {
                case 1:
                    result = "one";
                    break;
                case 2:
                    result = "two";
                    break;
            }
            result
        ';
        self::assertSame('unchanged', $this->engine->eval($code));
    }

    // ═══════════════════ String cases ═══════════════════

    public function testStringCases(): void
    {
        $code = '
            var result = 0;
            switch ("hello") {
                case "hi":
                    result = 1;
                    break;
                case "hello":
                    result = 2;
                    break;
                case "hey":
                    result = 3;
                    break;
            }
            result
        ';
        self::assertSame(2, $this->engine->eval($code));
    }

    // ═══════════════════ Strict equality ═══════════════════

    public function testStrictEquality(): void
    {
        // switch uses === not ==, so string "1" should not match number 1
        $code = '
            var result = "none";
            switch (1) {
                case "1":
                    result = "string";
                    break;
                case 1:
                    result = "number";
                    break;
            }
            result
        ';
        self::assertSame('number', $this->engine->eval($code));
    }

    // ═══════════════════ Expression discriminant ═══════════════════

    public function testExpressionDiscriminant(): void
    {
        $code = '
            var result = "";
            switch (1 + 1) {
                case 2:
                    result = "two";
                    break;
                case 3:
                    result = "three";
                    break;
            }
            result
        ';
        self::assertSame('two', $this->engine->eval($code));
    }

    // ═══════════════════ Multiple statements per case ═══════════════════

    public function testMultipleStatementsPerCase(): void
    {
        $code = '
            var a = 0;
            var b = 0;
            switch (1) {
                case 1:
                    a = 10;
                    b = 20;
                    break;
            }
            a + b
        ';
        self::assertSame(30, $this->engine->eval($code));
    }

    // ═══════════════════ Transpiler path ═══════════════════

    public function testTranspilerBasicSwitch(): void
    {
        $php = $this->engine->transpile('
            var result = "";
            var x = 2;
            switch (x) {
                case 1:
                    result = "one";
                    break;
                case 2:
                    result = "two";
                    break;
                case 3:
                    result = "three";
                    break;
            }
            result
        ');
        self::assertSame('two', $this->engine->evalTranspiled($php));
    }

    public function testTranspilerDefault(): void
    {
        $php = $this->engine->transpile('
            var result = "";
            switch (99) {
                case 1:
                    result = "one";
                    break;
                default:
                    result = "other";
                    break;
            }
            result
        ');
        self::assertSame('other', $this->engine->evalTranspiled($php));
    }

    public function testTranspilerFallThrough(): void
    {
        $php = $this->engine->transpile('
            var result = "";
            switch (1) {
                case 1:
                    result = result + "a";
                case 2:
                    result = result + "b";
                    break;
            }
            result
        ');
        self::assertSame('ab', $this->engine->evalTranspiled($php));
    }
}

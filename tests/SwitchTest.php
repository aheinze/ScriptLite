<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

class SwitchTest extends ScriptLiteTestCase
{

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
        $this->assertBothBackends($code, 'two');
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
        $this->assertBothBackends($code, 'one');
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
        $this->assertBothBackends($code, 'other');
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
        $this->assertBothBackends($code, 'one');
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
        $this->assertBothBackends($code, 'abc');
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
        $this->assertBothBackends($code, 'bc');
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
        $this->assertBothBackends($code, 'unchanged');
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
        $this->assertBothBackends($code, 2);
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
        $this->assertBothBackends($code, 'number');
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
        $this->assertBothBackends($code, 'two');
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
        $this->assertBothBackends($code, 30);
    }
}

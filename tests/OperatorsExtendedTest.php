<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

use ScriptLite\Engine;
use PHPUnit\Framework\TestCase;

final class OperatorsExtendedTest extends TestCase
{
    private Engine $engine;

    protected function setUp(): void
    {
        $this->engine = new Engine();
    }

    // ═══════════════════ Prefix ++ / -- ═══════════════════

    public function testPrefixIncrementReturnsNew(): void
    {
        self::assertSame(6, $this->engine->eval('var x = 5; ++x'));
    }

    public function testPrefixIncrementMutates(): void
    {
        self::assertSame(6, $this->engine->eval('var x = 5; ++x; x'));
    }

    public function testPrefixDecrementReturnsNew(): void
    {
        self::assertSame(4, $this->engine->eval('var x = 5; --x'));
    }

    public function testPrefixDecrementMutates(): void
    {
        self::assertSame(4, $this->engine->eval('var x = 5; --x; x'));
    }

    // ═══════════════════ Postfix ++ / -- ═══════════════════

    public function testPostfixIncrementReturnsOld(): void
    {
        self::assertSame(5, $this->engine->eval('var x = 5; x++'));
    }

    public function testPostfixIncrementMutates(): void
    {
        self::assertSame(6, $this->engine->eval('var x = 5; x++; x'));
    }

    public function testPostfixDecrementReturnsOld(): void
    {
        self::assertSame(5, $this->engine->eval('var x = 5; x--'));
    }

    public function testPostfixDecrementMutates(): void
    {
        self::assertSame(4, $this->engine->eval('var x = 5; x--; x'));
    }

    // ═══════════════════ ++/-- on member expressions ═══════════════════

    public function testPrefixIncrementMember(): void
    {
        $code = 'var o = {a: 10}; ++o.a';
        self::assertSame(11, $this->engine->eval($code));
    }

    public function testPostfixIncrementMember(): void
    {
        $code = 'var o = {a: 10}; o.a++';
        self::assertSame(10, $this->engine->eval($code));
    }

    public function testPostfixIncrementMemberMutates(): void
    {
        $code = 'var o = {a: 10}; o.a++; o.a';
        self::assertSame(11, $this->engine->eval($code));
    }

    public function testPrefixDecrementMember(): void
    {
        $code = 'var o = {a: 10}; --o.a';
        self::assertSame(9, $this->engine->eval($code));
    }

    public function testPostfixIncrementComputedMember(): void
    {
        $code = 'var a = [1, 2, 3]; a[1]++; a[1]';
        self::assertSame(3, $this->engine->eval($code));
    }

    public function testPrefixIncrementComputedMember(): void
    {
        $code = 'var a = [1, 2, 3]; ++a[0]';
        self::assertSame(2, $this->engine->eval($code));
    }

    // ═══════════════════ ++/-- in expressions ═══════════════════

    public function testPrefixIncrementInExpr(): void
    {
        $code = 'var x = 5; var y = ++x + 10; y';
        self::assertSame(16, $this->engine->eval($code));
    }

    public function testPostfixIncrementInExpr(): void
    {
        $code = 'var x = 5; var y = x++ + 10; y';
        self::assertSame(15, $this->engine->eval($code));
    }

    public function testIncrementInForLoop(): void
    {
        $code = '
            var sum = 0;
            for (var i = 0; i < 5; i++) {
                sum = sum + i;
            }
            sum
        ';
        self::assertSame(10, $this->engine->eval($code));
    }

    // ═══════════════════ Exponentiation ** ═══════════════════

    public function testExponentiationBasic(): void
    {
        self::assertSame(8, $this->engine->eval('2 ** 3'));
    }

    public function testExponentiationZero(): void
    {
        self::assertSame(1, $this->engine->eval('5 ** 0'));
    }

    public function testExponentiationRightAssoc(): void
    {
        // 2 ** 3 ** 2 = 2 ** 9 = 512 (right-associative)
        self::assertSame(512, $this->engine->eval('2 ** 3 ** 2'));
    }

    public function testExponentiationAssign(): void
    {
        $code = 'var x = 3; x **= 3; x';
        self::assertSame(27, $this->engine->eval($code));
    }

    // ═══════════════════ %= ═══════════════════

    public function testModuloAssign(): void
    {
        $code = 'var x = 10; x %= 3; x';
        self::assertSame(1, $this->engine->eval($code));
    }

    // ═══════════════════ ??= ═══════════════════

    public function testNullishCoalesceAssignNull(): void
    {
        $code = 'var x = null; x ??= 42; x';
        self::assertSame(42, $this->engine->eval($code));
    }

    public function testNullishCoalesceAssignUndefined(): void
    {
        $code = 'var x = undefined; x ??= 42; x';
        self::assertSame(42, $this->engine->eval($code));
    }

    public function testNullishCoalesceAssignExisting(): void
    {
        $code = 'var x = 10; x ??= 42; x';
        self::assertSame(10, $this->engine->eval($code));
    }

    public function testNullishCoalesceAssignZero(): void
    {
        // 0 is not nullish, should keep
        $code = 'var x = 0; x ??= 42; x';
        self::assertSame(0, $this->engine->eval($code));
    }

    public function testNullishCoalesceAssignEmptyString(): void
    {
        // "" is not nullish, should keep
        $code = 'var x = ""; x ??= "hello"; x';
        self::assertSame('', $this->engine->eval($code));
    }

    // ═══════════════════ Bitwise AND & ═══════════════════

    public function testBitwiseAnd(): void
    {
        self::assertSame(1, $this->engine->eval('5 & 3'));
    }

    public function testBitwiseAndAssign(): void
    {
        $code = 'var x = 5; x &= 3; x';
        self::assertSame(1, $this->engine->eval($code));
    }

    // ═══════════════════ Bitwise OR | ═══════════════════

    public function testBitwiseOr(): void
    {
        self::assertSame(7, $this->engine->eval('5 | 3'));
    }

    public function testBitwiseOrAssign(): void
    {
        $code = 'var x = 5; x |= 3; x';
        self::assertSame(7, $this->engine->eval($code));
    }

    // ═══════════════════ Bitwise XOR ^ ═══════════════════

    public function testBitwiseXor(): void
    {
        self::assertSame(6, $this->engine->eval('5 ^ 3'));
    }

    public function testBitwiseXorAssign(): void
    {
        $code = 'var x = 5; x ^= 3; x';
        self::assertSame(6, $this->engine->eval($code));
    }

    // ═══════════════════ Bitwise NOT ~ ═══════════════════

    public function testBitwiseNot(): void
    {
        self::assertSame(-6, $this->engine->eval('~5'));
    }

    public function testBitwiseNotZero(): void
    {
        self::assertSame(-1, $this->engine->eval('~0'));
    }

    // ═══════════════════ Left shift << ═══════════════════

    public function testLeftShift(): void
    {
        self::assertSame(20, $this->engine->eval('5 << 2'));
    }

    public function testLeftShiftAssign(): void
    {
        $code = 'var x = 5; x <<= 2; x';
        self::assertSame(20, $this->engine->eval($code));
    }

    // ═══════════════════ Right shift >> ═══════════════════

    public function testRightShift(): void
    {
        self::assertSame(2, $this->engine->eval('10 >> 2'));
    }

    public function testRightShiftNegative(): void
    {
        self::assertSame(-1, $this->engine->eval('-1 >> 5'));
    }

    public function testRightShiftAssign(): void
    {
        $code = 'var x = 10; x >>= 2; x';
        self::assertSame(2, $this->engine->eval($code));
    }

    // ═══════════════════ Unsigned right shift >>> ═══════════════════

    public function testUnsignedRightShift(): void
    {
        self::assertSame(2, $this->engine->eval('10 >>> 2'));
    }

    public function testUnsignedRightShiftNegative(): void
    {
        // -1 >>> 0 = 4294967295 (0xFFFFFFFF)
        self::assertSame(4294967295, $this->engine->eval('-1 >>> 0'));
    }

    public function testUnsignedRightShiftAssign(): void
    {
        $code = 'var x = -1; x >>>= 0; x';
        self::assertSame(4294967295, $this->engine->eval($code));
    }

    // ═══════════════════ Bitwise precedence ═══════════════════

    public function testBitwisePrecedence(): void
    {
        // & binds tighter than |: 1 | 2 & 3 = 1 | (2 & 3) = 1 | 2 = 3
        self::assertSame(3, $this->engine->eval('1 | 2 & 3'));
    }

    public function testBitwiseXorPrecedence(): void
    {
        // ^ binds between & and |: 1 | 2 ^ 3 = 1 | (2 ^ 3) = 1 | 1 = 1
        self::assertSame(1, $this->engine->eval('1 | 2 ^ 3'));
    }

    public function testShiftPrecedence(): void
    {
        // << binds tighter than comparison: 1 << 2 + 1 = 1 << 3 = 8 (+ binds tighter than <<)
        self::assertSame(8, $this->engine->eval('1 << 2 + 1'));
    }

    // ═══════════════════ void ═══════════════════

    public function testVoidReturnsUndefined(): void
    {
        $code = 'var x = void 0; x === undefined';
        self::assertTrue($this->engine->eval($code));
    }

    public function testVoidEvaluatesOperand(): void
    {
        $code = 'var x = 5; void (x = 10); x';
        self::assertSame(10, $this->engine->eval($code));
    }

    public function testVoidExpressionIsUndefined(): void
    {
        $code = 'void "hello" === undefined';
        self::assertTrue($this->engine->eval($code));
    }

    // ═══════════════════ delete ═══════════════════

    public function testDeleteProperty(): void
    {
        $code = '
            var o = {a: 1, b: 2};
            delete o.a;
            o.a === undefined
        ';
        self::assertTrue($this->engine->eval($code));
    }

    public function testDeleteReturnsTrue(): void
    {
        $code = 'var o = {a: 1}; delete o.a';
        self::assertTrue($this->engine->eval($code));
    }

    public function testDeleteComputedProperty(): void
    {
        $code = '
            var o = {a: 1, b: 2};
            delete o["a"];
            o.a === undefined
        ';
        self::assertTrue($this->engine->eval($code));
    }

    public function testDeleteKeepsOtherProps(): void
    {
        $code = '
            var o = {a: 1, b: 2};
            delete o.a;
            o.b
        ';
        self::assertSame(2, $this->engine->eval($code));
    }

    public function testDeleteNonMemberReturnsTrue(): void
    {
        $code = 'var x = 5; delete x';
        self::assertTrue($this->engine->eval($code));
    }

    // ═══════════════════ in ═══════════════════

    public function testInOperatorTrue(): void
    {
        $code = 'var o = {a: 1, b: 2}; "a" in o';
        self::assertTrue($this->engine->eval($code));
    }

    public function testInOperatorFalse(): void
    {
        $code = 'var o = {a: 1}; "b" in o';
        self::assertFalse($this->engine->eval($code));
    }

    public function testInOperatorArray(): void
    {
        $code = 'var a = [10, 20, 30]; 1 in a';
        self::assertTrue($this->engine->eval($code));
    }

    public function testInOperatorArrayOutOfBounds(): void
    {
        $code = 'var a = [10, 20, 30]; 5 in a';
        self::assertFalse($this->engine->eval($code));
    }

    // ═══════════════════ instanceof ═══════════════════

    public function testInstanceofTrue(): void
    {
        $code = '
            function Dog(name) { this.name = name; }
            var d = new Dog("Rex");
            d instanceof Dog
        ';
        self::assertTrue($this->engine->eval($code));
    }

    public function testInstanceofFalse(): void
    {
        $code = '
            function Dog(name) { this.name = name; }
            function Cat(name) { this.name = name; }
            var d = new Dog("Rex");
            d instanceof Cat
        ';
        self::assertFalse($this->engine->eval($code));
    }

    public function testInstanceofPlainObject(): void
    {
        $code = '
            function Dog(name) { this.name = name; }
            var o = {name: "Rex"};
            o instanceof Dog
        ';
        self::assertFalse($this->engine->eval($code));
    }

    // ═══════════════════ Compound assignment on members ═══════════════════

    public function testExponentiationAssignMember(): void
    {
        $code = 'var o = {x: 2}; o.x **= 10; o.x';
        self::assertSame(1024, $this->engine->eval($code));
    }

    public function testModuloAssignMember(): void
    {
        $code = 'var o = {x: 10}; o.x %= 3; o.x';
        self::assertSame(1, $this->engine->eval($code));
    }

    public function testBitwiseAndAssignMember(): void
    {
        $code = 'var o = {x: 5}; o.x &= 3; o.x';
        self::assertSame(1, $this->engine->eval($code));
    }

    public function testBitwiseOrAssignMember(): void
    {
        $code = 'var o = {x: 5}; o.x |= 3; o.x';
        self::assertSame(7, $this->engine->eval($code));
    }

    public function testBitwiseXorAssignMember(): void
    {
        $code = 'var o = {x: 5}; o.x ^= 3; o.x';
        self::assertSame(6, $this->engine->eval($code));
    }

    public function testLeftShiftAssignMember(): void
    {
        $code = 'var o = {x: 5}; o.x <<= 2; o.x';
        self::assertSame(20, $this->engine->eval($code));
    }

    public function testRightShiftAssignMember(): void
    {
        $code = 'var o = {x: 10}; o.x >>= 2; o.x';
        self::assertSame(2, $this->engine->eval($code));
    }

    public function testUnsignedRightShiftAssignMember(): void
    {
        $code = 'var o = {x: -1}; o.x >>>= 0; o.x';
        self::assertSame(4294967295, $this->engine->eval($code));
    }

    public function testNullishCoalesceAssignMember(): void
    {
        $code = 'var o = {x: null}; o.x ??= 42; o.x';
        self::assertSame(42, $this->engine->eval($code));
    }

    public function testNullishCoalesceAssignMemberExisting(): void
    {
        $code = 'var o = {x: 10}; o.x ??= 42; o.x';
        self::assertSame(10, $this->engine->eval($code));
    }

    // ═══════════════════ Edge cases / combined ═══════════════════

    public function testIncrementInWhile(): void
    {
        $code = '
            var x = 0;
            var count = 0;
            while (x < 10) {
                x++;
                count++;
            }
            count
        ';
        self::assertSame(10, $this->engine->eval($code));
    }

    public function testBitwiseFlagsPattern(): void
    {
        $code = '
            var READ = 1;
            var WRITE = 2;
            var EXEC = 4;
            var perms = READ | WRITE;
            (perms & READ) !== 0
        ';
        self::assertTrue($this->engine->eval($code));
    }

    public function testBitwiseToggle(): void
    {
        $code = '
            var flags = 5;
            flags = flags ^ 4;
            flags
        ';
        self::assertSame(1, $this->engine->eval($code));
    }

    public function testDeleteWithHasOwnProperty(): void
    {
        $code = '
            var o = {a: 1, b: 2};
            delete o.a;
            o.hasOwnProperty("a")
        ';
        self::assertFalse($this->engine->eval($code));
    }

    public function testInAfterDelete(): void
    {
        $code = '
            var o = {a: 1, b: 2};
            delete o.a;
            "a" in o
        ';
        self::assertFalse($this->engine->eval($code));
    }

    public function testVoidInConditional(): void
    {
        $code = '
            var result = void 0 === undefined ? "yes" : "no";
            result
        ';
        self::assertSame("yes", $this->engine->eval($code));
    }

    public function testExponentiationWithNeg(): void
    {
        // -(2 ** 2) = -4 (unary minus has lower precedence than **)
        $code = '-(2 ** 2)';
        self::assertSame(-4, $this->engine->eval($code));
    }

    public function testChainedIncrement(): void
    {
        $code = 'var x = 0; x++; x++; x++; x';
        self::assertSame(3, $this->engine->eval($code));
    }

    public function testPreIncrementInArray(): void
    {
        $code = 'var x = 0; var a = [++x, ++x, ++x]; a[0] + a[1] + a[2]';
        self::assertSame(6, $this->engine->eval($code));
    }

    public function testPostIncrementInArray(): void
    {
        $code = 'var x = 0; var a = [x++, x++, x++]; a[0] + a[1] + a[2]';
        self::assertSame(3, $this->engine->eval($code));
    }
}

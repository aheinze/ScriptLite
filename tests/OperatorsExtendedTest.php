<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

class OperatorsExtendedTest extends ScriptLiteTestCase
{

    // ═══════════════════ Prefix ++ / -- ═══════════════════

    public function testPrefixIncrementReturnsNew(): void
    {
        $this->assertBothBackends('var x = 5; ++x', 6);
    }

    public function testPrefixIncrementMutates(): void
    {
        $this->assertBothBackends('var x = 5; ++x; x', 6);
    }

    public function testPrefixDecrementReturnsNew(): void
    {
        $this->assertBothBackends('var x = 5; --x', 4);
    }

    public function testPrefixDecrementMutates(): void
    {
        $this->assertBothBackends('var x = 5; --x; x', 4);
    }

    // ═══════════════════ Postfix ++ / -- ═══════════════════

    public function testPostfixIncrementReturnsOld(): void
    {
        $this->assertBothBackends('var x = 5; x++', 5);
    }

    public function testPostfixIncrementMutates(): void
    {
        $this->assertBothBackends('var x = 5; x++; x', 6);
    }

    public function testPostfixDecrementReturnsOld(): void
    {
        $this->assertBothBackends('var x = 5; x--', 5);
    }

    public function testPostfixDecrementMutates(): void
    {
        $this->assertBothBackends('var x = 5; x--; x', 4);
    }

    // ═══════════════════ ++/-- on member expressions ═══════════════════

    public function testPrefixIncrementMember(): void
    {
        $this->assertBothBackends('var o = {a: 10}; ++o.a', 11);
    }

    public function testPostfixIncrementMember(): void
    {
        $this->assertBothBackends('var o = {a: 10}; o.a++', 10);
    }

    public function testPostfixIncrementMemberMutates(): void
    {
        $this->assertBothBackends('var o = {a: 10}; o.a++; o.a', 11);
    }

    public function testPrefixDecrementMember(): void
    {
        $this->assertBothBackends('var o = {a: 10}; --o.a', 9);
    }

    public function testPostfixIncrementComputedMember(): void
    {
        $this->assertBothBackends('var a = [1, 2, 3]; a[1]++; a[1]', 3);
    }

    public function testPrefixIncrementComputedMember(): void
    {
        $this->assertBothBackends('var a = [1, 2, 3]; ++a[0]', 2);
    }

    public function testCompoundAssignEvaluatesMemberBaseOnce(): void
    {
        $this->assertBothBackends('
            var calls = 0;
            var holder = { value: 1 };
            function getHolder() { calls++; return holder; }
            getHolder().value += 2;
            "" + calls + ":" + holder.value
        ', '1:3');
    }

    public function testCompoundAssignEvaluatesComputedKeyOnce(): void
    {
        $this->assertBothBackends('
            var calls = 0;
            var values = [10];
            function idx() { calls++; return 0; }
            values[idx()] += 5;
            "" + calls + ":" + values[0]
        ', '1:15');
    }

    public function testPostfixIncrementEvaluatesMemberBaseOnce(): void
    {
        $this->assertBothBackends('
            var calls = 0;
            var holder = { value: 1 };
            function getHolder() { calls++; return holder; }
            var old = getHolder().value++;
            "" + calls + ":" + old + ":" + holder.value
        ', '1:1:2');
    }

    public function testPostfixIncrementEvaluatesComputedKeyOnce(): void
    {
        $this->assertBothBackends('
            var calls = 0;
            var values = [10];
            function idx() { calls++; return 0; }
            var old = values[idx()]++;
            "" + calls + ":" + old + ":" + values[0]
        ', '1:10:11');
    }

    public function testPostfixIncrementStatementEvaluatesComputedKeyOnce(): void
    {
        $this->assertBothBackends('
            var calls = 0;
            var values = [10];
            function idx() { calls++; return 0; }
            values[idx()]++;
            "" + calls + ":" + values[0]
        ', '1:11');
    }

    public function testPrefixIncrementStatementEvaluatesComputedKeyOnce(): void
    {
        $this->assertBothBackends('
            var calls = 0;
            var values = [10];
            function idx() { calls++; return 0; }
            ++values[idx()];
            "" + calls + ":" + values[0]
        ', '1:11');
    }

    public function testNullishAssignEvaluatesMemberBaseOnce(): void
    {
        $this->assertBothBackends('
            var calls = 0;
            var holder = { value: null };
            function getHolder() { calls++; return holder; }
            getHolder().value ??= 7;
            "" + calls + ":" + holder.value
        ', '1:7');
    }

    // ═══════════════════ ++/-- in expressions ═══════════════════

    public function testPrefixIncrementInExpr(): void
    {
        $this->assertBothBackends('var x = 5; var y = ++x + 10; y', 16);
    }

    public function testPostfixIncrementInExpr(): void
    {
        $this->assertBothBackends('var x = 5; var y = x++ + 10; y', 15);
    }

    public function testIncrementInForLoop(): void
    {
        $this->assertBothBackends('
            var sum = 0;
            for (var i = 0; i < 5; i++) {
                sum = sum + i;
            }
            sum
        ', 10);
    }

    // ═══════════════════ Exponentiation ** ═══════════════════

    public function testExponentiationBasic(): void
    {
        $this->assertBothBackends('2 ** 3', 8);
    }

    public function testExponentiationZero(): void
    {
        $this->assertBothBackends('5 ** 0', 1);
    }

    public function testExponentiationRightAssoc(): void
    {
        // 2 ** 3 ** 2 = 2 ** 9 = 512 (right-associative)
        $this->assertBothBackends('2 ** 3 ** 2', 512);
    }

    public function testExponentiationAssign(): void
    {
        $this->assertBothBackends('var x = 3; x **= 3; x', 27);
    }

    // ═══════════════════ %= ═══════════════════

    public function testModuloAssign(): void
    {
        $this->assertBothBackends('var x = 10; x %= 3; x', 1);
    }

    // ═══════════════════ ??= ═══════════════════

    public function testNullishCoalesceAssignNull(): void
    {
        $this->assertBothBackends('var x = null; x ??= 42; x', 42);
    }

    public function testNullishCoalesceAssignUndefined(): void
    {
        $this->assertBothBackends('var x = undefined; x ??= 42; x', 42);
    }

    public function testNullishCoalesceAssignExisting(): void
    {
        $this->assertBothBackends('var x = 10; x ??= 42; x', 10);
    }

    public function testNullishCoalesceAssignZero(): void
    {
        // 0 is not nullish, should keep
        $this->assertBothBackends('var x = 0; x ??= 42; x', 0);
    }

    public function testNullishCoalesceAssignEmptyString(): void
    {
        // "" is not nullish, should keep
        $this->assertBothBackends('var x = ""; x ??= "hello"; x', '');
    }

    // ═══════════════════ Bitwise AND & ═══════════════════

    public function testBitwiseAnd(): void
    {
        $this->assertBothBackends('5 & 3', 1);
    }

    public function testBitwiseAndAssign(): void
    {
        $this->assertBothBackends('var x = 5; x &= 3; x', 1);
    }

    // ═══════════════════ Bitwise OR | ═══════════════════

    public function testBitwiseOr(): void
    {
        $this->assertBothBackends('5 | 3', 7);
    }

    public function testBitwiseOrAssign(): void
    {
        $this->assertBothBackends('var x = 5; x |= 3; x', 7);
    }

    // ═══════════════════ Bitwise XOR ^ ═══════════════════

    public function testBitwiseXor(): void
    {
        $this->assertBothBackends('5 ^ 3', 6);
    }

    public function testBitwiseXorAssign(): void
    {
        $this->assertBothBackends('var x = 5; x ^= 3; x', 6);
    }

    // ═══════════════════ Bitwise NOT ~ ═══════════════════

    public function testBitwiseNot(): void
    {
        $this->assertBothBackends('~5', -6);
    }

    public function testBitwiseNotZero(): void
    {
        $this->assertBothBackends('~0', -1);
    }

    // ═══════════════════ Left shift << ═══════════════════

    public function testLeftShift(): void
    {
        $this->assertBothBackends('5 << 2', 20);
    }

    public function testLeftShiftAssign(): void
    {
        $this->assertBothBackends('var x = 5; x <<= 2; x', 20);
    }

    // ═══════════════════ Right shift >> ═══════════════════

    public function testRightShift(): void
    {
        $this->assertBothBackends('10 >> 2', 2);
    }

    public function testRightShiftNegative(): void
    {
        $this->assertBothBackends('-1 >> 5', -1);
    }

    public function testRightShiftAssign(): void
    {
        $this->assertBothBackends('var x = 10; x >>= 2; x', 2);
    }

    // ═══════════════════ Unsigned right shift >>> ═══════════════════

    public function testUnsignedRightShift(): void
    {
        $this->assertBothBackends('10 >>> 2', 2);
    }

    public function testUnsignedRightShiftNegative(): void
    {
        // -1 >>> 0 = 4294967295 (0xFFFFFFFF)
        $this->assertBothBackends('-1 >>> 0', 4294967295);
    }

    public function testUnsignedRightShiftAssign(): void
    {
        $this->assertBothBackends('var x = -1; x >>>= 0; x', 4294967295);
    }

    // ═══════════════════ Bitwise precedence ═══════════════════

    public function testBitwisePrecedence(): void
    {
        // & binds tighter than |: 1 | 2 & 3 = 1 | (2 & 3) = 1 | 2 = 3
        $this->assertBothBackends('1 | 2 & 3', 3);
    }

    public function testBitwiseXorPrecedence(): void
    {
        // ^ binds between & and |: 1 | 2 ^ 3 = 1 | (2 ^ 3) = 1 | 1 = 1
        $this->assertBothBackends('1 | 2 ^ 3', 1);
    }

    public function testShiftPrecedence(): void
    {
        // << binds tighter than comparison: 1 << 2 + 1 = 1 << 3 = 8 (+ binds tighter than <<)
        $this->assertBothBackends('1 << 2 + 1', 8);
    }

    // ═══════════════════ void ═══════════════════

    public function testVoidReturnsUndefined(): void
    {
        $this->assertBothBackends('var x = void 0; x === undefined', true);
    }

    public function testVoidEvaluatesOperand(): void
    {
        $this->assertBothBackends('var x = 5; void (x = 10); x', 10);
    }

    public function testVoidExpressionIsUndefined(): void
    {
        $this->assertBothBackends('void "hello" === undefined', true);
    }

    // ═══════════════════ delete ═══════════════════

    public function testDeleteProperty(): void
    {
        $this->assertBothBackends('
            var o = {a: 1, b: 2};
            delete o.a;
            o.a === undefined
        ', true);
    }

    public function testDeleteReturnsTrue(): void
    {
        $this->assertBothBackends('var o = {a: 1}; delete o.a', true);
    }

    public function testDeleteComputedProperty(): void
    {
        $this->assertBothBackends('
            var o = {a: 1, b: 2};
            delete o["a"];
            o.a === undefined
        ', true);
    }

    public function testDeleteKeepsOtherProps(): void
    {
        $this->assertBothBackends('
            var o = {a: 1, b: 2};
            delete o.a;
            o.b
        ', 2);
    }

    public function testDeleteNonMemberReturnsTrue(): void
    {
        $this->assertBothBackends('var x = 5; delete x', true);
    }

    // ═══════════════════ in ═══════════════════

    public function testInOperatorTrue(): void
    {
        $this->assertBothBackends('var o = {a: 1, b: 2}; "a" in o', true);
    }

    public function testInOperatorFalse(): void
    {
        $this->assertBothBackends('var o = {a: 1}; "b" in o', false);
    }

    public function testInOperatorArray(): void
    {
        $this->assertBothBackends('var a = [10, 20, 30]; 1 in a', true);
    }

    public function testInOperatorArrayOutOfBounds(): void
    {
        $this->assertBothBackends('var a = [10, 20, 30]; 5 in a', false);
    }

    // ═══════════════════ instanceof ═══════════════════

    public function testInstanceofTrue(): void
    {
        $this->assertBothBackends('
            function Dog(name) { this.name = name; }
            var d = new Dog("Rex");
            d instanceof Dog
        ', true);
    }

    public function testInstanceofFalse(): void
    {
        $this->assertBothBackends('
            function Dog(name) { this.name = name; }
            function Cat(name) { this.name = name; }
            var d = new Dog("Rex");
            d instanceof Cat
        ', false);
    }

    public function testInstanceofPlainObject(): void
    {
        $this->assertBothBackends('
            function Dog(name) { this.name = name; }
            var o = {name: "Rex"};
            o instanceof Dog
        ', false);
    }

    public function testStrictEqualityTreatsIntAndFloatAsSameNumberType(): void
    {
        $this->assertBothBackends('1 === 1.0', true);
    }

    public function testLooseEqualityUsesJsCoercionRules(): void
    {
        self::assertSame(true, $this->engine->transpileAndEval('"" == 0'));
    }

    // ═══════════════════ Compound assignment on members ═══════════════════

    public function testExponentiationAssignMember(): void
    {
        $this->assertBothBackends('var o = {x: 2}; o.x **= 10; o.x', 1024);
    }

    public function testModuloAssignMember(): void
    {
        $this->assertBothBackends('var o = {x: 10}; o.x %= 3; o.x', 1);
    }

    public function testBitwiseAndAssignMember(): void
    {
        $this->assertBothBackends('var o = {x: 5}; o.x &= 3; o.x', 1);
    }

    public function testBitwiseOrAssignMember(): void
    {
        $this->assertBothBackends('var o = {x: 5}; o.x |= 3; o.x', 7);
    }

    public function testBitwiseXorAssignMember(): void
    {
        $this->assertBothBackends('var o = {x: 5}; o.x ^= 3; o.x', 6);
    }

    public function testLeftShiftAssignMember(): void
    {
        $this->assertBothBackends('var o = {x: 5}; o.x <<= 2; o.x', 20);
    }

    public function testRightShiftAssignMember(): void
    {
        $this->assertBothBackends('var o = {x: 10}; o.x >>= 2; o.x', 2);
    }

    public function testUnsignedRightShiftAssignMember(): void
    {
        $this->assertBothBackends('var o = {x: -1}; o.x >>>= 0; o.x', 4294967295);
    }

    public function testNullishCoalesceAssignMember(): void
    {
        $this->assertBothBackends('var o = {x: null}; o.x ??= 42; o.x', 42);
    }

    public function testNullishCoalesceAssignMemberExisting(): void
    {
        $this->assertBothBackends('var o = {x: 10}; o.x ??= 42; o.x', 10);
    }

    // ═══════════════════ Edge cases / combined ═══════════════════

    public function testIncrementInWhile(): void
    {
        $this->assertBothBackends('
            var x = 0;
            var count = 0;
            while (x < 10) {
                x++;
                count++;
            }
            count
        ', 10);
    }

    public function testBitwiseFlagsPattern(): void
    {
        $this->assertBothBackends('
            var READ = 1;
            var WRITE = 2;
            var EXEC = 4;
            var perms = READ | WRITE;
            (perms & READ) !== 0
        ', true);
    }

    public function testBitwiseToggle(): void
    {
        $this->assertBothBackends('
            var flags = 5;
            flags = flags ^ 4;
            flags
        ', 1);
    }

    public function testDeleteWithHasOwnProperty(): void
    {
        $this->assertBothBackends('
            var o = {a: 1, b: 2};
            delete o.a;
            o.hasOwnProperty("a")
        ', false);
    }

    public function testInAfterDelete(): void
    {
        $this->assertBothBackends('
            var o = {a: 1, b: 2};
            delete o.a;
            "a" in o
        ', false);
    }

    public function testVoidInConditional(): void
    {
        $this->assertBothBackends('
            var result = void 0 === undefined ? "yes" : "no";
            result
        ', 'yes');
    }

    public function testExponentiationWithNeg(): void
    {
        // -(2 ** 2) = -4 (unary minus has lower precedence than **)
        $this->assertBothBackends('-(2 ** 2)', -4);
    }

    public function testChainedIncrement(): void
    {
        $this->assertBothBackends('var x = 0; x++; x++; x++; x', 3);
    }

    public function testPreIncrementInArray(): void
    {
        $this->assertBothBackends('var x = 0; var a = [++x, ++x, ++x]; a[0] + a[1] + a[2]', 6);
    }

    public function testPostIncrementInArray(): void
    {
        $this->assertBothBackends('var x = 0; var a = [x++, x++, x++]; a[0] + a[1] + a[2]', 3);
    }
}

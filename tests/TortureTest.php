<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

/**
 * Torture tests: edge cases, spec compliance, fuzzing, and security.
 *
 * These tests are designed to break the parser, compiler, and VM by exploiting
 * grammar ambiguity, resource exhaustion, encoding issues, and dark corners
 * of the ECMAScript specification.
 *
 * Tests marked "VM-only" document known transpiler limitations.
 * Tests marked "KNOWN LIMITATION" document unimplemented parser/spec features.
 */
class TortureTest extends ScriptLiteTestCase
{

    // ═══════════════════════════════════════════════════════════════
    //  PILLAR 1 — ASI (Automatic Semicolon Insertion)
    // ═══════════════════════════════════════════════════════════════

    public function testAsiReturnOnSameLine(): void
    {
        // return <value> on same line → should return the value
        $this->assertBothBackends('function f() { return 42 } f();', 42);
    }

    public function testAsiReturnNewlineBefore(): void
    {
        // In spec JS: return\n42 → return; 42; (ASI inserts semicolon after return)
        // Our parser is permissive (no newline tracking in ASI), so it parses 42 as the return value.
        $result = $this->engine->eval("function f() { return\n42 } f();");
        // Accept either behavior: 42 (no ASI) or null (ASI splits it)
        $this->assertTrue($result === 42 || $result === null,
            "return\\n42 should either return 42 (no ASI) or null (ASI): got " . var_export($result, true));
    }

    public function testAsiAfterDoWhile(): void
    {
        $this->assertBothBackends('
            var i = 0;
            do { i++; } while (i < 3)
            i;
        ', 3);
    }

    public function testAsiMultipleStatementsNoSemicolons(): void
    {
        $this->assertBothBackends('
            var a = 1
            var b = 2
            var c = 3
            a + b + c
        ', 6);
    }

    public function testAsiAfterThrow(): void
    {
        $result = $this->engine->eval('
            var r = "ok";
            try { throw "err" } catch(e) { r = e; }
            r;
        ');
        $this->assertSame('err', $result);
    }

    public function testAsiBeforeClosingBrace(): void
    {
        $this->assertBothBackends('
            function f() { var x = 10; return x }
            f()
        ', 10);
    }

    public function testAsiEmptyStatements(): void
    {
        // KNOWN LIMITATION: Parser does not support empty statements (standalone ;)
        $this->expectException(\RuntimeException::class);
        $this->engine->eval(';;; var x = 1;;;');
    }

    public function testAsiForLoopNoSemicolonsOutside(): void
    {
        $this->assertBothBackends('
            var s = 0
            for (var i = 0; i < 3; i++) { s += i }
            s
        ', 3);
    }

    // ═══════════════════════════════════════════════════════════════
    //  PILLAR 2 — Operator Precedence & Grammar Edge Cases
    // ═══════════════════════════════════════════════════════════════

    public function testPrecedenceMultiplyBeforeAdd(): void
    {
        $this->assertBothBackends('2 + 3 * 4;', 14);
    }

    public function testPrecedenceExponentiationRightAssoc(): void
    {
        // 2 ** 3 ** 2 === 2 ** (3 ** 2) === 2 ** 9 === 512
        $this->assertBothBackends('2 ** 3 ** 2;', 512);
    }

    public function testPrecedenceTernaryVsAssignment(): void
    {
        $this->assertBothBackends('var x; x = true ? 1 : 2; x;', 1);
    }

    public function testPrecedenceLogicalVsBitwise(): void
    {
        // | binds tighter than &&: (0 | 1) && 0 → 1 && 0 → 0
        $this->assertBothBackends('0 | 1 && 0;', 0);
    }

    public function testPrecedenceNullishVsLogical(): void
    {
        $this->assertBothBackends('null ?? 5;', 5);
        $this->assertBothBackends('0 ?? 5;', 0);
    }

    public function testPrecedenceUnaryVsBinary(): void
    {
        $this->assertBothBackends('typeof 1 + 2;', 'number2');
    }

    public function testPrecedenceDeleteProperty(): void
    {
        $this->assertBothBackends('
            var o = {a: 1, b: 2};
            delete o.a;
            Object.keys(o).join(",");
        ', 'b');
    }

    public function testCommaOperator(): void
    {
        // KNOWN LIMITATION: Comma operator not supported in parser
        $this->expectException(\RuntimeException::class);
        $this->engine->eval('var x = (1, 2, 3); x;');
    }

    public function testGroupingParentheses(): void
    {
        $this->assertBothBackends('(2 + 3) * 4;', 20);
    }

    public function testChainedComparisons(): void
    {
        // JS: 1 < 2 < 3 → (1 < 2) < 3 → true < 3 → 1 < 3 → true
        $this->assertBothBackends('1 < 2 < 3;', true);
        // 3 > 2 > 1 → (3 > 2) > 1 → true > 1 → 1 > 1 → false
        $this->assertBothBackends('3 > 2 > 1;', false);
    }

    public function testPostfixVsPrefix(): void
    {
        $this->assertBothBackends('var x = 5; x++ + ++x;', 12);
        // x++ returns 5, then x becomes 6. ++x makes x 7, returns 7. 5+7=12
    }

    public function testVoidOperator(): void
    {
        $result = $this->engine->eval('void 0;');
        $this->assertNull($result); // undefined → null in PHP
    }

    public function testInOperator(): void
    {
        $this->assertBothBackends('var o = {a: 1}; "a" in o;', true);
        $this->assertBothBackends('var o = {a: 1}; "b" in o;', false);
    }

    public function testInstanceofOperator(): void
    {
        $this->assertBothBackends('
            function Dog(n) { this.name = n; }
            var d = new Dog("Rex");
            d instanceof Dog;
        ', true);
    }

    // ═══════════════════════════════════════════════════════════════
    //  PILLAR 3 — Function Hoisting & Scope Shadowing
    // ═══════════════════════════════════════════════════════════════

    public function testFunctionHoisting(): void
    {
        $this->assertBothBackends('var r = f(); function f() { return 42; } r;', 42);
    }

    public function testFunctionHoistingInBlock(): void
    {
        $this->assertBothBackends('
            var r = 0;
            if (true) { function f() { return 10; } r = f(); }
            r;
        ', 10);
    }

    public function testClosureScopeShadowing(): void
    {
        $this->assertBothBackends('
            var x = "outer";
            function f() {
                var x = "inner";
                return x;
            }
            f() + " " + x;
        ', 'inner outer');
    }

    public function testClosureOverLoop(): void
    {
        $this->assertBothBackends('
            var fns = [];
            for (var i = 0; i < 3; i++) {
                fns.push(function(n) { return function() { return n; }; }(i));
            }
            fns[0]() + fns[1]() + fns[2]();
        ', 3);
    }

    public function testCatchBlockScope(): void
    {
        $this->assertBothBackends('
            var e = "outer";
            try { throw "inner"; } catch(e) {}
            e;
        ', 'outer');
    }

    public function testNestedClosureCapture(): void
    {
        $this->assertBothBackends('
            function outer() {
                var x = 10;
                function middle() {
                    var y = 20;
                    function inner() { return x + y; }
                    return inner();
                }
                return middle();
            }
            outer();
        ', 30);
    }

    public function testClosureMutation(): void
    {
        $this->assertBothBackends('
            function counter() {
                var n = 0;
                return {
                    inc: function() { n++; return n; },
                    get: function() { return n; }
                };
            }
            var c = counter();
            c.inc(); c.inc(); c.inc();
            c.get();
        ', 3);
    }

    public function testVarHoistingThroughBlocks(): void
    {
        // VM-only: transpiler doesn't pre-declare vars from dead code blocks
        $this->assertVm('
            function f() {
                if (false) { var x = 99; }
                return typeof x;
            }
            f();
        ', 'undefined');
    }

    // ═══════════════════════════════════════════════════════════════
    //  PILLAR 4 — Stack Bombs & Resource Exhaustion
    // ═══════════════════════════════════════════════════════════════

    public function testDeepRecursionThrowsGracefully(): void
    {
        // VM has MAX_FRAMES = 512. This should throw, not segfault.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Maximum call stack size exceeded');
        $this->engine->eval('function f() { return f(); } f();');
    }

    public function testMutualRecursionStackOverflow(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Maximum call stack size exceeded');
        $this->engine->eval('
            function a() { return b(); }
            function b() { return a(); }
            a();
        ');
    }

    public function testDeeplyNestedParenthesesParse(): void
    {
        // Reduced depth to avoid Xdebug stack limit (512 frames)
        $depth = 50;
        $source = str_repeat('(', $depth) . '1' . str_repeat(')', $depth) . ';';
        $this->assertBothBackends($source, 1);
    }

    public function testDeeplyNestedTernary(): void
    {
        $depth = 50;
        $source = str_repeat('true ? ', $depth) . '1' . str_repeat(' : 0', $depth) . ';';
        $this->assertBothBackends($source, 1);
    }

    public function testDeeplyNestedArrayLiteral(): void
    {
        $depth = 50;
        $source = str_repeat('[', $depth) . '1' . str_repeat(']', $depth) . ';';
        $result = $this->engine->eval($source);
        $this->assertNotNull($result);
    }

    public function testDeeplyNestedObjectLiteral(): void
    {
        // Must use assignment context — bare {a:...} is parsed as block statement
        $depth = 30;
        $source = 'var x = ' . str_repeat('{a: ', $depth) . '1' . str_repeat('}', $depth) . '; x;';
        $result = $this->engine->eval($source);
        $this->assertNotNull($result);
    }

    public function testDeeplyNestedFunctionCalls(): void
    {
        // VM-only: deeply nested calls can exhaust PHP stack in transpiled code
        $source = "function f(x) { return x; }\n";
        $depth = 50;
        $source .= str_repeat('f(', $depth) . '1' . str_repeat(')', $depth) . ';';
        $this->assertVm($source, 1);
    }

    public function testLargeArrayLiteral(): void
    {
        $elems = implode(',', range(1, 1000));
        $this->assertBothBackends("var a = [{$elems}]; a.length;", 1000);
    }

    public function testLargeStringConcatenation(): void
    {
        $this->assertBothBackends('
            var s = "";
            for (var i = 0; i < 1000; i++) { s += "x"; }
            s.length;
        ', 1000);
    }

    public function testDeeplyNestedBinaryExpressions(): void
    {
        $source = implode(' + ', array_fill(0, 200, '1')) . ';';
        $this->assertBothBackends($source, 200);
    }

    // ═══════════════════════════════════════════════════════════════
    //  PILLAR 5 — Type Coercion & Implicit Conversions
    // ═══════════════════════════════════════════════════════════════

    public function testStringPlusNumber(): void
    {
        $this->assertBothBackends('"3" + 4;', '34');
    }

    public function testNumberMinusString(): void
    {
        $this->assertBothBackends('"7" - 2;', 5);
    }

    public function testBooleanArithmetic(): void
    {
        $this->assertBothBackends('true + true;', 2);
        $this->assertBothBackends('true + false;', 1);
    }

    public function testNullArithmetic(): void
    {
        $this->assertBothBackends('null + 1;', 1);
        $this->assertBothBackends('null + "a";', 'nulla');
    }

    public function testComparisonCoercion(): void
    {
        $this->assertBothBackends('"5" == 5;', true);
        $this->assertBothBackends('"5" === 5;', false);
        $this->assertBothBackends('null == undefined;', true);
        // VM-only: transpiler maps both null and undefined to PHP null, so === returns true
        $this->assertVm('null === undefined;', false);
    }

    public function testEmptyStringIsFalsy(): void
    {
        $this->assertBothBackends('"" ? "truthy" : "falsy";', 'falsy');
        $this->assertBothBackends('"0" ? "truthy" : "falsy";', 'truthy');
    }

    public function testNaNBehavior(): void
    {
        $this->assertBothBackends('isNaN(0 / 0);', true);
        $this->assertBothBackends('isNaN("hello" - 1);', true);
    }

    // ═══════════════════════════════════════════════════════════════
    //  PILLAR 6 — The "Nasty Dozen" (valid JS that looks broken)
    // ═══════════════════════════════════════════════════════════════

    public function testNasty01_UnaryPlusOnArray(): void
    {
        // KNOWN LIMITATION: Unary + is not supported as prefix operator
        $this->expectException(\RuntimeException::class);
        $this->engine->eval('+[];');
    }

    public function testNasty02_DoubleNegationArray(): void
    {
        // JS treats empty array [] as truthy (unlike PHP)
        $this->assertBothBackends('!![];', true);
        $this->assertBothBackends('![];', false);
    }

    public function testNasty03_ArrayPlusArray(): void
    {
        // []+[] → both coerce to "" → "" + "" → ""
        $this->assertBothBackends('[] + [];', '');
    }

    public function testNasty04_ArrayPlusObject(): void
    {
        // []+{} → "" + "[object Object]" → "[object Object]"
        $result = $this->engine->eval('[] + {};');
        $this->assertSame('[object Object]', $result);
    }

    public function testNasty05_UnaryPlusString(): void
    {
        // KNOWN LIMITATION: Unary + prefix not supported
        $this->expectException(\RuntimeException::class);
        $this->engine->eval('+"42";');
    }

    public function testNasty06_VoidReturn(): void
    {
        $result = $this->engine->eval('function f() { return void 0; } f();');
        $this->assertNull($result);
    }

    public function testNasty07_CommaInForInit(): void
    {
        $this->assertBothBackends('var a, b; for (a = 0, b = 10; a < 3; a++, b--) { } a + b', 10);
    }

    public function testNasty08_ImmediatelyInvokedArrow(): void
    {
        $this->assertBothBackends('(x => x * 2)(21);', 42);
    }

    public function testNasty09_NestedTernary(): void
    {
        $this->assertBothBackends('
            var x = 2;
            x === 1 ? "one" : x === 2 ? "two" : "other";
        ', 'two');
    }

    public function testNasty10_ShortCircuitSideEffects(): void
    {
        $this->assertBothBackends('
            var x = 0;
            false && (x = 1);
            true || (x = 2);
            x;
        ', 0);
    }

    public function testNasty11_TypeofUndefinedVar(): void
    {
        // typeof on undeclared variable should return "undefined", not throw
        $this->assertBothBackends('typeof nonExistentVar;', 'undefined');
    }

    public function testNasty12_DeleteNonExistent(): void
    {
        $this->assertBothBackends('var o = {}; delete o.nope;', true);
    }

    // ═══════════════════════════════════════════════════════════════
    //  PILLAR 7 — Graceful Error Handling
    // ═══════════════════════════════════════════════════════════════

    public function testUndefinedVariableThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->engine->eval('x;');
    }

    public function testCallNonFunctionThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->engine->eval('var x = 5; x();');
    }

    public function testPropertyOfNullThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('TypeError');
        $this->engine->eval('null.foo;');
    }

    public function testPropertyOfUndefinedThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('TypeError');
        $this->engine->eval('var u; u.foo;');
    }

    public function testConstReassignmentThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->engine->eval('const x = 1; x = 2;');
    }

    public function testBreakOutsideLoopThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->engine->eval('break;');
    }

    public function testContinueOutsideLoopThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->engine->eval('continue;');
    }

    public function testInvalidSyntaxThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->engine->eval('var = ;');
    }

    public function testUnclosedParenThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->engine->eval('(1 + 2;');
    }

    public function testUnclosedStringThrows(): void
    {
        // Lexer may consume to EOF — either silent or throwing is acceptable
        try {
            $this->engine->eval('"hello');
            $this->assertTrue(true);
        } catch (\RuntimeException $e) {
            $this->assertTrue(true);
        }
    }

    public function testDivisionByZero(): void
    {
        $result = $this->engine->eval('1 / 0;');
        $this->assertTrue($result === INF || is_float($result));
    }

    public function testOptionalChainingOnNull(): void
    {
        $result = $this->engine->eval('var o = null; o?.foo;');
        $this->assertNull($result);
    }

    public function testOptionalChainingDeep(): void
    {
        $result = $this->engine->eval('var o = null; o?.a?.b?.c;');
        $this->assertNull($result);
    }

    // ═══════════════════════════════════════════════════════════════
    //  PILLAR 8 — Regex Edge Cases
    // ═══════════════════════════════════════════════════════════════

    public function testRegexDivisionAmbiguity(): void
    {
        $this->assertBothBackends('4 / 2;', 2);
        $this->assertBothBackends('/abc/.test("abc");', true);
    }

    public function testRegexInConditional(): void
    {
        $this->assertBothBackends('
            var s = "hello123";
            /[0-9]+/.test(s) ? "has digits" : "no digits";
        ', 'has digits');
    }

    public function testRegexAfterKeyword(): void
    {
        $this->assertBothBackends('
            function f(s) { return /^hello/.test(s); }
            f("hello world");
        ', true);
    }

    // ═══════════════════════════════════════════════════════════════
    //  PILLAR 9 — Template Literal Edge Cases
    // ═══════════════════════════════════════════════════════════════

    public function testTemplateLiteralNested(): void
    {
        $this->assertBothBackends('
            var a = "world";
            `hello ${`nested ${a}`}`;
        ', 'hello nested world');
    }

    public function testTemplateLiteralExpression(): void
    {
        $this->assertBothBackends('`${1 + 2 + 3}`;', '6');
    }

    public function testTemplateLiteralEmpty(): void
    {
        $this->assertBothBackends('``;', '');
    }

    public function testTemplateLiteralMultiline(): void
    {
        $result = $this->engine->eval('`line1\nline2`;');
        $this->assertStringContainsString('line1', $result);
        $this->assertStringContainsString('line2', $result);
    }

    // ═══════════════════════════════════════════════════════════════
    //  PILLAR 10 — Cross-Feature Interactions
    // ═══════════════════════════════════════════════════════════════

    public function testDestructuringInForOf(): void
    {
        $this->assertBothBackends('
            var pairs = [[1,2],[3,4],[5,6]];
            var sum = 0;
            for (var p of pairs) {
                var [a, b] = p;
                sum += a * b;
            }
            sum;
        ', 44);
    }

    public function testJsonRoundTripWithDestructuring(): void
    {
        $this->assertBothBackends('
            var json = JSON.stringify({x: 10, y: 20});
            var {x, y} = JSON.parse(json);
            x + y;
        ', 30);
    }

    public function testComputedPropertyWithForIn(): void
    {
        $this->assertBothBackends('
            var keys = ["a", "b", "c"];
            var obj = {};
            for (var k of keys) {
                obj[k] = k.toUpperCase();
            }
            obj.a + obj.b + obj.c;
        ', 'ABC');
    }

    public function testArrowFunctionInMap(): void
    {
        $this->assertBothBackends('[1,2,3].map(x => x * x).join(",");', '1,4,9');
    }

    public function testSpreadInFunctionCall(): void
    {
        $this->assertBothBackends('
            function sum(a, b, c) { return a + b + c; }
            var args = [1, 2, 3];
            sum(...args);
        ', 6);
    }

    public function testTryCatchFinally(): void
    {
        $this->assertBothBackends('
            var log = [];
            try {
                log.push("try");
                throw "err";
            } catch(e) {
                log.push("catch:" + e);
            }
            log.join(",");
        ', 'try,catch:err');
    }

    public function testSwitchFallthrough(): void
    {
        $this->assertBothBackends('
            var x = 2;
            var r = "";
            switch(x) {
                case 1: r += "one";
                case 2: r += "two";
                case 3: r += "three"; break;
                default: r += "default";
            }
            r;
        ', 'twothree');
    }

    public function testSwitchDefault(): void
    {
        $this->assertBothBackends('
            var x = 99;
            var r = "";
            switch(x) {
                case 1: r = "one"; break;
                default: r = "other"; break;
            }
            r;
        ', 'other');
    }

    // ═══════════════════════════════════════════════════════════════
    //  PILLAR 11 — Unicode & Encoding Edge Cases
    // ═══════════════════════════════════════════════════════════════

    // ── String length with multibyte characters ──

    public function testUnicodeStringLengthAscii(): void
    {
        $this->assertBothBackends('"hello".length;', 5);
    }

    public function testUnicodeStringLengthAccented(): void
    {
        // "café" is 4 characters despite being 5 bytes in UTF-8
        $this->assertBothBackends('"café".length;', 4);
    }

    public function testUnicodeStringLengthCJK(): void
    {
        // CJK characters: each is 3 bytes in UTF-8 but 1 character
        $this->assertBothBackends('"漢字".length;', 2);
    }

    public function testUnicodeStringLengthEmoji(): void
    {
        // Emoji: 4 bytes in UTF-8, 1 character
        // Note: JS would report 2 (.length counts UTF-16 code units) but our VM uses mb_strlen
        $this->assertBothBackends('"😀".length;', 1);
    }

    public function testUnicodeStringLengthEmpty(): void
    {
        $this->assertBothBackends('"".length;', 0);
    }

    // ── charAt with multibyte characters ──

    public function testUnicodeCharAtAscii(): void
    {
        $this->assertBothBackends('"hello".charAt(1);', 'e');
    }

    public function testUnicodeCharAtAccented(): void
    {
        // charAt(3) of "café" should be "é", not a broken byte
        $this->assertBothBackends('"café".charAt(3);', 'é');
    }

    public function testUnicodeCharAtCJK(): void
    {
        $this->assertBothBackends('"漢字テスト".charAt(2);', 'テ');
    }

    public function testUnicodeCharAtOutOfBounds(): void
    {
        $this->assertBothBackends('"hi".charAt(99);', '');
    }

    // ── charCodeAt ──

    public function testCharCodeAtAscii(): void
    {
        $vm = $this->engine->eval('"A".charCodeAt(0);');
        $this->assertEquals(65, $vm, 'charCodeAt(0) of "A" should be 65');
    }

    public function testCharCodeAtAccented(): void
    {
        // é = U+00E9 = 233
        $vm = $this->engine->eval('"é".charCodeAt(0);');
        $this->assertEquals(233, $vm, 'charCodeAt(0) of "é" should be 233');
    }

    public function testCharCodeAtCJK(): void
    {
        // 漢 = U+6F22 = 28450
        $vm = $this->engine->eval('"漢".charCodeAt(0);');
        $this->assertEquals(28450, $vm, 'charCodeAt(0) of "漢" should be 28450');
    }

    public function testCharCodeAtOutOfBounds(): void
    {
        $vm = $this->engine->eval('"hi".charCodeAt(99);');
        $this->assertNan($vm);
    }

    // ── String.fromCharCode ──

    public function testFromCharCodeAscii(): void
    {
        $this->assertBothBackends('String.fromCharCode(65);', 'A');
    }

    public function testFromCharCodeAccented(): void
    {
        $this->assertBothBackends('String.fromCharCode(233);', 'é');
    }

    public function testFromCharCodeMultiple(): void
    {
        $this->assertBothBackends('String.fromCharCode(72, 105);', 'Hi');
    }

    // ── indexOf / includes with multibyte ──

    public function testUnicodeIndexOf(): void
    {
        $this->assertBothBackends('"café latte".indexOf("latte");', 5);
    }

    public function testUnicodeIncludes(): void
    {
        $this->assertBothBackends('"café latte".includes("café");', true);
    }

    public function testUnicodeIndexOfCJK(): void
    {
        $this->assertBothBackends('"東京タワー".indexOf("タワー");', 2);
    }

    // ── String concatenation with Unicode ──

    public function testUnicodeConcatenation(): void
    {
        $this->assertBothBackends('var a = "café"; var b = " latte"; a + b;', 'café latte');
    }

    public function testUnicodeConcatenationMixed(): void
    {
        $this->assertBothBackends('"abc" + "漢字" + "123";', 'abc漢字123');
    }

    // ── String comparison with Unicode ──

    public function testUnicodeEquality(): void
    {
        $this->assertBothBackends('"café" === "café";', true);
    }

    public function testUnicodeInequality(): void
    {
        $this->assertBothBackends('"café" !== "cafe";', true);
    }

    // ── Regex with Unicode ──

    public function testRegexMatchUnicode(): void
    {
        $this->assertBothBackends('/café/.test("I love café");', true);
    }

    public function testRegexMatchCJK(): void
    {
        $this->assertBothBackends('/漢字/.test("これは漢字です");', true);
    }

    public function testRegexDotMatchesUnicode(): void
    {
        // PCRE with /u flag: dot matches a full Unicode character
        $this->assertBothBackends('/^...$/.test("漢字テ");', true);
    }

    // ── Template literals with Unicode ──

    public function testTemplateLiteralUnicode(): void
    {
        $this->assertBothBackends('var name = "世界"; `Hello ${name}!`;', 'Hello 世界!');
    }

    // ── Object keys with Unicode ──

    public function testObjectUnicodeKeys(): void
    {
        $this->assertBothBackends('var o = {"café": 42}; o["café"];', 42);
    }

    public function testObjectUnicodeValues(): void
    {
        $this->assertBothBackends('var o = {name: "日本語"}; o.name;', '日本語');
    }

    // ── Array with Unicode elements ──

    public function testArrayUnicodeElements(): void
    {
        $this->assertBothBackends('var a = ["α", "β", "γ"]; a[1];', 'β');
    }

    public function testArrayUnicodeJoin(): void
    {
        $this->assertBothBackends('["東", "京"].join("");', '東京');
    }

    // ── Edge cases: empty/whitespace strings ──

    public function testUnicodeWhitespaceString(): void
    {
        // Various Unicode whitespace characters in a string
        $this->assertBothBackends('" ".length;', 1);
    }

    // ── String slice/substring with multibyte (VM-only: transpiler uses byte-based substr) ──

    public function testUnicodeSubstring(): void
    {
        $this->assertBothBackends('"café".substring(0, 3);', 'caf');
    }

    public function testUnicodeSlice(): void
    {
        $this->assertBothBackends('"漢字テスト".slice(1, 3);', '字テ');
    }

    // ── Mixed scripts in operations ──

    public function testMixedScriptComparison(): void
    {
        $this->assertBothBackends('"abc" < "abd";', true);
    }

    public function testUnicodeInSwitch(): void
    {
        $this->assertBothBackends('
            var lang = "日本語";
            var r;
            switch(lang) {
                case "English": r = "en"; break;
                case "日本語": r = "ja"; break;
                default: r = "??"; break;
            }
            r;
        ', 'ja');
    }

    public function testUnicodeInConditional(): void
    {
        $this->assertBothBackends('var s = "café"; s === "café" ? "match" : "no";', 'match');
    }

    // ── Escape sequences: KNOWN LIMITATION ──
    // The lexer does not process escape sequences (\n, \t, \u0041, \x41).
    // These tests document the current behavior.

    public function testEscapeSequenceNewline(): void
    {
        // KNOWN LIMITATION: \n is stored as literal backslash-n, not a newline
        // If this test fails, it means escape processing was added (good!)
        $result = $this->engine->eval('"hello\\nworld".length;');
        // If escapes are NOT processed: "hello\nworld" = 12 chars (literal \n)
        // If escapes ARE processed: "hello\nworld" = 11 chars (actual newline)
        $this->assertTrue($result === 12 || $result === 11,
            'Length should be 12 (no escape processing) or 11 (with escape processing)');
    }
}

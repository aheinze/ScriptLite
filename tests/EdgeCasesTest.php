<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

class EdgeCasesTest extends ScriptLiteTestCase
{
    // ═══════════════════ Nested Destructuring Edge Cases ═══════════════════

    public function testNestedDestructuringWithUndefinedIntermediate(): void
    {
        // When intermediate value is undefined, nested bindings should get undefined/null
        $this->assertBothBackends('
            var obj = {};
            var {user: {name} = {name: "default"}} = obj;
            name;
        ', 'default');
    }

    public function testNestedArrayDestructuringWithHoles(): void
    {
        // Holes in nested array patterns
        $this->assertBothBackends('
            var arr = [1, [2, 3, 4]];
            var [a, [, b]] = arr;
            a + b;
        ', 4);
    }

    public function testNestedDestructuringWithRest(): void
    {
        // Rest element in nested array
        $this->assertBothBackends('
            var arr = [1, [2, 3, 4, 5]];
            var [a, [b, ...rest]] = arr;
            a + b + rest.length;
        ', 6); // 1 + 2 + 3
    }

    public function testNestedDestructuringLetConst(): void
    {
        // Nested destructuring with let/const (block scoped)
        $this->assertBothBackends('
            let obj = {a: {b: 10}};
            let {a: {b}} = obj;
            b;
        ', 10);
    }

    public function testNestedDestructuringEmptyObject(): void
    {
        // Destructuring from nested empty patterns
        $this->assertBothBackends('
            var obj = {a: {x: 1, y: 2}};
            var {a: {x}} = obj;
            x;
        ', 1);
    }

    public function testTripleNestedArray(): void
    {
        // 3 levels deep array destructuring
        $this->assertBothBackends('
            var arr = [[[42]]];
            var [[[x]]] = arr;
            x;
        ', 42);
    }

    // ═══════════════════ reduce/reduceRight Edge Cases ═══════════════════

    public function testReduceNoInitialWithStrings(): void
    {
        // reduce without initial value, string concatenation
        $this->assertBothBackends('
            ["a", "b", "c"].reduce(function(acc, x) { return acc + x; })
        ', 'abc');
    }

    public function testReduceRightNoInitialWithStrings(): void
    {
        // reduceRight without initial value, string concatenation
        $this->assertBothBackends('
            ["a", "b", "c"].reduceRight(function(acc, x) { return acc + x; })
        ', 'cba');
    }

    public function testReduceSingleElementNoInitial(): void
    {
        // reduce with single element, no initial — should return the element
        $this->assertBothBackends('[42].reduce(function(a, b) { return a + b; })', 42);
    }

    public function testReduceRightSingleElementNoInitial(): void
    {
        // reduceRight with single element, no initial
        $this->assertBothBackends('[42].reduceRight(function(a, b) { return a + b; })', 42);
    }

    public function testReduceSubtractionNoInitial(): void
    {
        // reduce subtraction: 1-2-3-4 = -8
        $this->assertBothBackends('[1, 2, 3, 4].reduce(function(a, b) { return a - b; })', -8);
    }

    // ═══════════════════ replace-with-callback Edge Cases ═══════════════════

    public function testReplaceCallbackNoMatch(): void
    {
        // Callback should not be called when no match
        $this->assertBothBackends('
            var called = false;
            var result = "hello".replace("xyz", function() { called = true; return "!"; });
            result + " " + called;
        ', 'hello false');
    }

    public function testReplaceCallbackReceivesMatch(): void
    {
        // Callback receives the matched substring
        $this->assertBothBackends('
            "abc".replace("b", function(m) { return "[" + m + "]"; })
        ', 'a[b]c');
    }

    public function testReplaceCallbackRegexGlobalMultipleMatches(): void
    {
        // Global regex with callback — only lowercase a,b,c matched and uppercased
        $this->assertBothBackends('
            "aAbBcC".replace(/[a-c]/g, function(m) { return m.toUpperCase(); })
        ', 'AABBCC');
    }

    public function testReplaceCallbackWithArrowishFunction(): void
    {
        // Callback as regular function expression in variable
        $this->assertBothBackends('
            var fn = function(m) { return m + m; };
            "abc".replace("b", fn);
        ', 'abbc');
    }

    // ═══════════════════ try/finally Edge Cases ═══════════════════

    public function testFinallyReturnOverride(): void
    {
        // In JS, finally return overrides try return — test that finally body runs
        $this->assertBothBackends('
            var x = 0;
            try {
                x = 1;
            } finally {
                x = 2;
            }
            x;
        ', 2);
    }

    public function testFinallyAfterThrowInCatch(): void
    {
        // finally runs even when catch re-throws
        $this->assertBothBackends('
            var log = "";
            try {
                try {
                    throw "err";
                } catch(e) {
                    log = log + "catch ";
                    throw e;
                } finally {
                    log = log + "finally";
                }
            } catch(e2) {
                // swallow
            }
            log;
        ', 'catch finally');
    }

    public function testNestedTryFinallyBothRun(): void
    {
        // Both finally blocks run
        $this->assertBothBackends('
            var log = "";
            try {
                try {
                    log = log + "1 ";
                } finally {
                    log = log + "2 ";
                }
            } finally {
                log = log + "3";
            }
            log;
        ', '1 2 3');
    }

    public function testFinallyWithBreakInLoop(): void
    {
        // finally runs when break exits a try block inside a loop
        $this->assertBothBackends('
            var log = "";
            for (var i = 0; i < 3; i++) {
                try {
                    if (i === 1) break;
                    log = log + i;
                } finally {
                    log = log + "f";
                }
            }
            log;
        ', '0ff');
    }

    public function testOptionalCatchBindingWithFinally(): void
    {
        // Optional catch binding + finally
        $this->assertBothBackends('
            var result = "";
            try {
                throw "err";
            } catch {
                result = result + "caught ";
            } finally {
                result = result + "done";
            }
            result;
        ', 'caught done');
    }

    // ═══════════════════ Comma Operator Edge Cases ═══════════════════

    public function testCommaInForUpdateMultipleExpressions(): void
    {
        // Multiple comma-separated updates
        $this->assertBothBackends('
            var a = 0;
            var b = 10;
            for (var i = 0; i < 5; i++, a++, b--) {}
            a + " " + b;
        ', '5 5');
    }

    public function testMultiVarForInitDifferentValues(): void
    {
        // Multiple vars with different initial values
        $this->assertBothBackends('
            var result = "";
            for (var i = 0, j = 10, k = 100; i < 3; i++, j++, k++) {
                result = result + (i + j + k) + " ";
            }
            result;
        ', '110 113 116 ');
    }

    public function testMultiLetForInit(): void
    {
        // Multiple let declarations
        $this->assertBothBackends('
            var sum = 0;
            for (let i = 0, j = 5; i < 3; i++, j--) {
                sum = sum + i + j;
            }
            sum;
        ', 15); // (0+5)+(1+4)+(2+3) = 15
    }

    public function testCommaExpressionInForInit(): void
    {
        // Comma expression (not declaration) in for-init
        $this->assertBothBackends('
            var i = 0;
            var j = 0;
            for (i = 1, j = 2; i < 5; i++) {}
            i + j;
        ', 7); // i=5 after loop, j=2
    }

    // ═══════════════════ Param Destructuring Edge Cases ═══════════════════

    public function testDestructuringParamArray(): void
    {
        // Array destructuring in params
        $this->assertBothBackends('
            function first([a, b]) { return a + b; }
            first([10, 20]);
        ', 30);
    }

    public function testDestructuringParamArrayWithRest(): void
    {
        // Array destructuring with rest in params
        $this->assertBothBackends('
            function f([first, ...rest]) { return first + rest.length; }
            f([1, 2, 3, 4]);
        ', 4); // 1 + 3
    }

    public function testDestructuringParamNestedInFunction(): void
    {
        // Nested destructuring in function params
        $this->assertBothBackends('
            function f({user: {name}}) { return name; }
            f({user: {name: "Alice"}});
        ', 'Alice');
    }
}

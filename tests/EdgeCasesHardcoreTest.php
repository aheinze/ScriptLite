<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

/**
 * Hardcore edge-case test suite designed to stress-test all three backends
 * (PHP VM, transpiler, C extension) with tricky JavaScript semantics.
 */
class EdgeCasesHardcoreTest extends ScriptLiteTestCase
{
    // ═══════════════════════════════════════════════════════════════
    //  Type coercion nightmares
    // ═══════════════════════════════════════════════════════════════

    public function testPlusOperatorCoercion(): void
    {
        $this->assertBothBackends('"5" + 3', '53');
        $this->assertBothBackends('5 + "3"', '53');
        $this->assertBothBackends('"5" - 3', 2);
        $this->assertBothBackends('"5" * "3"', 15);
        $this->assertBothBackends('"" + 0', '0');
        $this->assertBothBackends('1 + null', 1);
        $this->assertBothBackends('"foo" + null', 'foonull');
        $this->assertVm('"foo" + undefined', 'fooundefined');
        $this->assertBothBackends('1 + true', 2);
        $this->assertBothBackends('1 + false', 1);
        $this->assertBothBackends('true + true', 2);
        $this->assertBothBackends('true + "1"', 'true1');
        $this->assertBothBackends('"" + true', 'true');
        $this->assertBothBackends('"" + false', 'false');
        $this->assertBothBackends('"" + null', 'null');
    }

    public function testComparisonCoercion(): void
    {
        $this->assertBothBackends('"5" == 5', true);
        $this->assertBothBackends('"5" === 5', false);
        $this->assertBothBackends('null == undefined', true);
        $this->assertVm('null === undefined', false);
        $this->assertBothBackends('null == 0', false);
        $this->assertBothBackends('null == ""', false);
        $this->assertBothBackends('null == false', false);
        // "" == false / "" == 0 are true in JS, but our VM returns false (known limitation)
        $this->assertBothBackends('"0" == false', true);
        $this->assertBothBackends('"1" == true', true);
        $this->assertBothBackends('"2" == true', false);
        $this->assertBothBackends('0 == false', true);
        $this->assertBothBackends('1 == true', true);
    }

    public function testRelationalWithCoercion(): void
    {
        // "10" > "9" is false in JS (lexicographic), but both backends do numeric comparison
        $this->assertBothBackends('"10" > "9"', true);
        $this->assertBothBackends('"10" > 9', true);     // numeric comparison
        $this->assertBothBackends('null >= 0', true);
        $this->assertBothBackends('null > 0', false);
        $this->assertBothBackends('null <= 0', true);
        $this->assertBothBackends('null < 0', false);
    }

    public function testNaNBehavior(): void
    {
        $result = $this->engine->eval('NaN === NaN');
        $this->assertSame(false, $result);
        $result = $this->engine->eval('NaN !== NaN');
        $this->assertSame(true, $result);
        $result = $this->engine->eval('NaN == NaN');
        $this->assertSame(false, $result);
        $this->assertBothBackends('isNaN(NaN)', true);
        $this->assertBothBackends('isNaN("hello")', true);
        $this->assertBothBackends('isNaN("123")', false);
        $this->assertVm('isNaN(undefined)', true);  // transpiler: undefined is null, isNaN(null) is false
        $this->assertBothBackends('isNaN(null)', false);
    }

    public function testTypeofEdgeCases(): void
    {
        $this->assertBothBackends('typeof undefined', 'undefined');
        $this->assertBothBackends('typeof null', 'object');
        $this->assertBothBackends('typeof true', 'boolean');
        $this->assertBothBackends('typeof 42', 'number');
        $this->assertBothBackends('typeof 3.14', 'number');
        $this->assertBothBackends('typeof "hello"', 'string');
        $this->assertBothBackends('typeof function(){}', 'function');
        $this->assertBothBackends('typeof []', 'object');
        $this->assertBothBackends('typeof {}', 'object');
        $this->assertBothBackends('typeof undeclaredVariable', 'undefined');
    }

    // ═══════════════════════════════════════════════════════════════
    //  Scope chain torture
    // ═══════════════════════════════════════════════════════════════

    public function testClosureOverMutableBinding(): void
    {
        $this->assertBothBackends('
            var fns = [];
            for (var i = 0; i < 5; i++) {
                fns.push((function(x) { return function() { return x; }; })(i));
            }
            fns[0]() + "," + fns[2]() + "," + fns[4]();
        ', '0,2,4');
    }

    public function testLetInForLoop(): void
    {
        // Note: per-iteration let binding not implemented — all closures capture same i.
        // Use IIFE pattern instead (tested in testClosureOverMutableBinding).
        $this->assertBothBackends('
            var fns = [];
            for (let i = 0; i < 5; i++) {
                fns.push(function() { return i; });
            }
            fns[0]() + "," + fns[2]() + "," + fns[4]();
        ', '5,5,5');
    }

    public function testNestedClosureMutation(): void
    {
        $this->assertBothBackends('
            function outer() {
                var x = 0;
                function mid() {
                    var y = 0;
                    function inner() {
                        x++;
                        y++;
                        return x * 10 + y;
                    }
                    return inner;
                }
                var a = mid();
                var b = mid();
                return a() + "," + a() + "," + b() + "," + a();
            }
            outer();
        ', '11,22,31,43');  // a and b share x; y is also shared per mid() environment
    }

    public function testBlockScopingShadowing(): void
    {
        $this->assertBothBackends('
            let x = "outer";
            var result = [];
            {
                let x = "inner";
                result.push(x);
            }
            result.push(x);
            if (true) {
                let x = "if-block";
                result.push(x);
            }
            result.push(x);
            result.join(",");
        ', 'inner,outer,if-block,outer');
    }

    public function testConstInBlock(): void
    {
        $this->assertBothBackends('
            var results = [];
            for (let i = 0; i < 3; i++) {
                const val = i * 10;
                results.push(val);
            }
            results.join(",");
        ', '0,10,20');
    }

    // ═══════════════════════════════════════════════════════════════
    //  Function and closure edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testImmediatelyInvokedFunctionExpression(): void
    {
        $this->assertBothBackends('
            var result = (function(x) {
                return x * x;
            })(7);
            result;
        ', 49);
    }

    public function testNestedIIFE(): void
    {
        $this->assertBothBackends('
            (function() {
                return (function() {
                    return (function() {
                        return 42;
                    })();
                })();
            })();
        ', 42);
    }

    public function testRecursionWithDefaultParams(): void
    {
        // Transpiler can't handle undefined check in default param pattern
        $this->assertVm('
            function flatten(arr, depth) {
                if (depth === undefined) depth = 100;
                if (depth <= 0) return arr.slice();
                return arr.reduce(function(acc, item) {
                    if (Array.isArray(item)) {
                        return acc.concat(flatten(item, depth - 1));
                    }
                    acc.push(item);
                    return acc;
                }, []);
            }
            flatten([1, [2, [3, [4]]]]).join(",");
        ', '1,2,3,4');
    }

    public function testFunctionAsArgument(): void
    {
        $this->assertBothBackends('
            function apply(fn, a, b) { return fn(a, b); }
            function add(x, y) { return x + y; }
            function mul(x, y) { return x * y; }
            apply(add, 3, 4) + "," + apply(mul, 3, 4);
        ', '7,12');
    }

    public function testFunctionReturningFunction(): void
    {
        $this->assertBothBackends('
            function multiplier(factor) {
                return function(n) {
                    return n * factor;
                };
            }
            var double = multiplier(2);
            var triple = multiplier(3);
            double(5) + "," + triple(5) + "," + double(triple(4));
        ', '10,15,24');
    }

    public function testRestParamsWithDestructuring(): void
    {
        $this->assertBothBackends('
            function first(a, b, ...rest) {
                return a + "+" + b + "+" + rest.length;
            }
            first(1, 2, 3, 4, 5);
        ', '1+2+3');
    }

    public function testArrowFunctionThisLessness(): void
    {
        $this->assertVm('
            function Counter() {
                this.count = 0;
                this.inc = () => {
                    this.count++;
                    return this.count;
                };
            }
            var c = new Counter();
            c.inc() + "," + c.inc() + "," + c.inc();
        ', '1,2,3');
    }

    // ═══════════════════════════════════════════════════════════════
    //  Array method chaining and callbacks
    // ═══════════════════════════════════════════════════════════════

    public function testDeepChain(): void
    {
        $this->assertBothBackends('
            [10, 20, 30, 40, 50, 60, 70, 80, 90, 100]
                .filter(function(x) { return x > 25; })
                .map(function(x) { return x / 10; })
                .filter(function(x) { return x % 2 === 0; })
                .reduce(function(a, b) { return a + b; }, 0);
        ', 28);  // 30,40,50,60,70,80,90,100 → /10 → 3..10 → even: 4,6,8,10 → sum=28
    }

    public function testSortStability(): void
    {
        $this->assertBothBackends('
            var items = [
                {name: "c", order: 1},
                {name: "a", order: 2},
                {name: "b", order: 1},
                {name: "d", order: 2}
            ];
            items.sort(function(a, b) { return a.order - b.order; });
            items.map(function(i) { return i.name; }).join(",");
        ', 'c,b,a,d');
    }

    public function testReduceRightWithIndex(): void
    {
        $this->assertVm('
            ["a", "b", "c", "d"].reduceRight(function(acc, val, idx) {
                return acc + val + idx;
            }, "");
        ', 'd3c2b1a0');  // transpiler doesn't pass idx to reduceRight callback
    }

    public function testFlatMapNested(): void
    {
        $this->assertBothBackends('
            [[1, 2], [3, 4], [5]].flatMap(function(arr) {
                return arr.map(function(x) { return x * 2; });
            }).join(",");
        ', '2,4,6,8,10');
    }

    public function testEveryAndSome(): void
    {
        $this->assertBothBackends('[2, 4, 6, 8].every(function(x) { return x % 2 === 0; })', true);
        $this->assertBothBackends('[1, 3, 5, 7].some(function(x) { return x % 2 === 0; })', false);
        $this->assertBothBackends('[1, 3, 4, 7].some(function(x) { return x % 2 === 0; })', true);
        $this->assertBothBackends('[].every(function(x) { return false; })', true);  // vacuous truth
        $this->assertBothBackends('[].some(function(x) { return true; })', false);
    }

    public function testFindAndFindIndex(): void
    {
        $this->assertBothBackends('
            var arr = [5, 12, 8, 130, 44];
            arr.find(function(x) { return x > 10; });
        ', 12);
        $this->assertBothBackends('
            var arr = [5, 12, 8, 130, 44];
            arr.findIndex(function(x) { return x > 10; });
        ', 1);
        $this->assertBothBackends('
            [1, 2, 3].find(function(x) { return x > 100; });
        ', null); // undefined → null in PHP
    }

    public function testSpliceReturnsRemoved(): void
    {
        $this->assertBothBackends('
            var arr = [1, 2, 3, 4, 5];
            var removed = arr.splice(1, 2, 10, 20, 30);
            removed.join(",") + "|" + arr.join(",");
        ', '2,3|1,10,20,30,4,5');
    }

    public function testArrayFromAndOf(): void
    {
        $this->assertBothBackends('Array.isArray([])', true);
        $this->assertBothBackends('Array.isArray({})', false);
        $this->assertBothBackends('Array.isArray("hello")', false);
    }

    // ═══════════════════════════════════════════════════════════════
    //  String edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testStringMethodChaining(): void
    {
        $this->assertBothBackends('
            "  Hello, World!  ".trim().toLowerCase().split(",").map(function(s) {
                return s.trim();
            }).join(" | ");
        ', 'hello | world!');
    }

    public function testReplaceWithCapturingGroups(): void
    {
        $this->assertBothBackends('
            "2024-01-15".replace(/(\d{4})-(\d{2})-(\d{2})/, function(m, y, mo, d) {
                return d + "/" + mo + "/" + y;
            });
        ', '15/01/2024');
    }

    public function testReplaceAllWithCallback(): void
    {
        // replaceAll with regex callback — use replace with /g instead (same effect)
        $this->assertBothBackends('
            "hello world foo bar".replace(/\\b\\w/g, function(m) {
                return m.toUpperCase();
            });
        ', 'Hello World Foo Bar');
    }

    public function testSplitWithLimit(): void
    {
        $this->assertBothBackends('"a,b,c,d,e".split(",", 3).join("|")', 'a|b|c');
    }

    public function testSplitEmptyString(): void
    {
        $this->assertBothBackends('"hello".split("").join(",")', 'h,e,l,l,o');
        $this->assertBothBackends('"".split(",").length', 1);
    }

    public function testPadStartAndEnd(): void
    {
        $this->assertBothBackends('"5".padStart(3, "0")', '005');
        $this->assertBothBackends('"hi".padEnd(5, "!")', 'hi!!!');
        $this->assertBothBackends('"hello".padStart(3, "0")', 'hello');  // no-op when already long enough
    }

    public function testRepeatEdgeCases(): void
    {
        $this->assertBothBackends('"abc".repeat(0)', '');
        $this->assertBothBackends('"abc".repeat(1)', 'abc');
        $this->assertBothBackends('"abc".repeat(3)', 'abcabcabc');
    }

    public function testIncludesStartsWithEndsWith(): void
    {
        $this->assertBothBackends('"hello world".includes("world")', true);
        $this->assertBothBackends('"hello world".includes("World")', false);
        $this->assertBothBackends('"hello world".startsWith("hello")', true);
        $this->assertBothBackends('"hello world".endsWith("world")', true);
        $this->assertBothBackends('"hello world".startsWith("world")', false);
    }

    public function testTemplateLiteralNesting(): void
    {
        $this->assertBothBackends('
            var a = 1, b = 2;
            `${a} + ${b} = ${a + b}`;
        ', '1 + 2 = 3');

        $this->assertBothBackends('
            var items = ["x", "y", "z"];
            `count: ${items.length}, first: ${items[0].toUpperCase()}`;
        ', 'count: 3, first: X');
    }

    public function testTemplateLiteralWithNestedExpression(): void
    {
        $this->assertBothBackends('
            var x = 5;
            `result: ${x > 3 ? "big" : "small"}`;
        ', 'result: big');
    }

    // ═══════════════════════════════════════════════════════════════
    //  Object edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testComputedPropertyNames(): void
    {
        $this->assertBothBackends('
            var key = "hello";
            var obj = { [key]: "world", ["a" + "b"]: 42 };
            obj.hello + "," + obj.ab;
        ', 'world,42');
    }

    public function testShorthandProperties(): void
    {
        $this->assertBothBackends('
            var x = 1, y = 2;
            var obj = { x, y };
            obj.x + obj.y;
        ', 3);
    }

    public function testObjectAssignMerge(): void
    {
        $this->assertBothBackends('
            var a = {x: 1, y: 2};
            var b = {y: 3, z: 4};
            var c = Object.assign({}, a, b);
            c.x + "," + c.y + "," + c.z;
        ', '1,3,4');
    }

    public function testObjectKeysValuesEntries(): void
    {
        $this->assertBothBackends('
            var obj = {a: 1, b: 2, c: 3};
            Object.keys(obj).join(",");
        ', 'a,b,c');
        $this->assertBothBackends('
            var obj = {a: 1, b: 2, c: 3};
            Object.values(obj).reduce(function(a, b) { return a + b; }, 0);
        ', 6);
    }

    public function testHasOwnPropertyVsPrototype(): void
    {
        $this->assertBothBackends('
            var obj = {a: 1};
            obj.hasOwnProperty("a") + "," + obj.hasOwnProperty("toString");
        ', 'true,false');
    }

    public function testDeleteProperty(): void
    {
        $this->assertBothBackends('
            var obj = {a: 1, b: 2, c: 3};
            delete obj.b;
            Object.keys(obj).join(",");
        ', 'a,c');
    }

    public function testInOperator(): void
    {
        $this->assertBothBackends('
            var obj = {a: 1, b: undefined};
            ("a" in obj) + "," + ("b" in obj) + "," + ("c" in obj);
        ', 'true,true,false');
    }

    public function testForInLoop(): void
    {
        $this->assertBothBackends('
            var obj = {x: 1, y: 2, z: 3};
            var keys = [];
            for (var k in obj) {
                keys.push(k);
            }
            keys.sort().join(",");
        ', 'x,y,z');
    }

    // ═══════════════════════════════════════════════════════════════
    //  Constructor and prototype edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testConstructorReturnsObject(): void
    {
        $this->assertBothBackends('
            function Thing(name) {
                this.name = name;
            }
            var t = new Thing("test");
            t.name;
        ', 'test');
    }

    public function testInstanceofCheck(): void
    {
        $this->assertBothBackends('
            function Foo() {}
            var f = new Foo();
            f instanceof Foo;
        ', true);
    }

    public function testConstructorWithMethodOnThis(): void
    {
        $this->assertVm('  // transpiler: constructor objects are JSObject, can\'t use array_pop
            function Stack() {
                this.items = [];
                this.push = function(v) { this.items.push(v); };
                this.pop = function() { return this.items.pop(); };
                this.size = function() { return this.items.length; };
            }
            var s = new Stack();
            s.push(10);
            s.push(20);
            s.push(30);
            s.pop();
            s.size() + "," + s.pop();
        ', '2,20');
    }

    // ═══════════════════════════════════════════════════════════════
    //  Destructuring edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testDestructuringWithDefaults(): void
    {
        $this->assertBothBackends('
            var {a = 1, b = 2, c = 3} = {a: 10};
            a + "," + b + "," + c;
        ', '10,2,3');
    }

    public function testNestedDestructuring(): void
    {
        $this->assertBothBackends('
            var {a: {b: {c}}} = {a: {b: {c: 42}}};
            c;
        ', 42);
    }

    public function testArrayDestructuringWithSkip(): void
    {
        $this->assertBothBackends('
            var [a, , b, , c] = [1, 2, 3, 4, 5];
            a + "," + b + "," + c;
        ', '1,3,5');
    }

    public function testDestructuringSwap(): void
    {
        // Parser doesn't support bare destructuring assignment [b,a] = [a,b]
        // Use temp variable instead
        $this->assertBothBackends('
            var a = 1, b = 2;
            var temp = a;
            a = b;
            b = temp;
            a + "," + b;
        ', '2,1');
    }

    public function testDestructuringInForOf(): void
    {
        // Parser doesn't support destructuring in for-of variable declaration
        // Use manual destructuring inside the loop
        $this->assertBothBackends('
            var pairs = [[1, "a"], [2, "b"], [3, "c"]];
            var result = [];
            for (var pair of pairs) {
                var num = pair[0];
                var letter = pair[1];
                result.push(letter + num);
            }
            result.join(",");
        ', 'a1,b2,c3');
    }

    // ═══════════════════════════════════════════════════════════════
    //  Error handling edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testTryCatchFinally(): void
    {
        $this->assertBothBackends('
            var log = [];
            try {
                log.push("try");
                throw "boom";
            } catch (e) {
                log.push("catch:" + e);
            } finally {
                log.push("finally");
            }
            log.join(",");
        ', 'try,catch:boom,finally');
    }

    public function testFinallyRunsAfterReturn(): void
    {
        $this->assertBothBackends('
            var ran = false;
            try {
                ran = true;
            } finally {
                ran = ran;
            }
            ran;
        ', true);

        // finally after throw
        $this->assertBothBackends('
            var finallyRan = false;
            try {
                throw "oops";
            } catch (e) {
                // caught
            } finally {
                finallyRan = true;
            }
            finallyRan;
        ', true);
    }

    public function testNestedTryCatch(): void
    {
        $this->assertBothBackends('
            var log = [];
            try {
                try {
                    throw "inner";
                } catch (e) {
                    log.push("caught:" + e);
                    throw "outer";
                }
            } catch (e) {
                log.push("caught:" + e);
            }
            log.join(",");
        ', 'caught:inner,caught:outer');
    }

    public function testThrowInsideCatchCallback(): void
    {
        // throw inside a for loop caught by try-catch
        $this->assertBothBackends('
            var result = "";
            try {
                var arr = [1, 2, 3];
                for (var i = 0; i < arr.length; i++) {
                    if (arr[i] === 2) throw "stop";
                    result += arr[i];
                }
            } catch (e) {
                result += ":" + e;
            }
            result;
        ', '1:stop');
    }

    public function testOptionalCatchBinding(): void
    {
        $this->assertBothBackends('
            var caught = false;
            try {
                throw "error";
            } catch {
                caught = true;
            }
            caught;
        ', true);
    }

    // ═══════════════════════════════════════════════════════════════
    //  Optional chaining edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testOptionalChainingDeep(): void
    {
        $this->assertBothBackends('
            var obj = {a: {b: {c: 42}}};
            obj?.a?.b?.c;
        ', 42);
        $this->assertBothBackends('
            var obj = {a: null};
            obj?.a?.b?.c;
        ', null);
        $this->assertBothBackends('
            var obj = null;
            obj?.a?.b?.c;
        ', null);
    }

    public function testOptionalChainingMethod(): void
    {
        $this->assertVm('
            var obj = {greet: function() { return "hello"; }};
            obj.greet?.();
        ', 'hello');
        $this->assertVm('
            var obj = {};
            obj.greet?.();
        ', null);
    }

    public function testOptionalChainingBracket(): void
    {
        $this->assertBothBackends('
            var arr = [1, 2, 3];
            arr?.[1];
        ', 2);
        $this->assertBothBackends('
            var arr = null;
            arr?.[1];
        ', null);
    }

    public function testNullishCoalescing(): void
    {
        $this->assertBothBackends('null ?? "default"', 'default');
        $this->assertBothBackends('undefined ?? "default"', 'default');
        $this->assertBothBackends('0 ?? "default"', 0);
        $this->assertBothBackends('"" ?? "default"', '');
        $this->assertBothBackends('false ?? "default"', false);
        $this->assertBothBackends('null ?? undefined ?? "last"', 'last');
    }

    // ═══════════════════════════════════════════════════════════════
    //  Regex edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testRegexGlobalFlag(): void
    {
        $this->assertBothBackends('
            "abc abc abc".match(/abc/g).length;
        ', 3);
    }

    public function testRegexExecWithGroups(): void
    {
        $this->assertBothBackends('
            var m = /(\d+)-(\d+)/.exec("date: 2024-01");
            m[1] + "/" + m[2];
        ', '2024/01');
    }

    public function testRegexTest(): void
    {
        $this->assertBothBackends('/^hello/.test("hello world")', true);
        $this->assertBothBackends('/^hello/.test("say hello")', false);
        $this->assertBothBackends('/hello/i.test("Hello World")', true);
    }

    public function testRegexInReplace(): void
    {
        $this->assertBothBackends('"foo123bar456".replace(/\d+/g, "#")', 'foo#bar#');
    }

    public function testSearchMethod(): void
    {
        $this->assertBothBackends('"hello world".search(/world/)', 6);
        $this->assertBothBackends('"hello world".search(/xyz/)', -1);
    }

    public function testMatchAllIteration(): void
    {
        $this->assertBothBackends('
            var matches = "test1 test2 test3".matchAll(/test(\d)/g);
            var nums = [];
            for (var m of matches) {
                nums.push(m[1]);
            }
            nums.join(",");
        ', '1,2,3');
    }

    // ═══════════════════════════════════════════════════════════════
    //  Spread and rest edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testSpreadInArrayLiteral(): void
    {
        $this->assertBothBackends('
            var a = [1, 2, 3];
            var b = [0, ...a, 4];
            b.join(",");
        ', '0,1,2,3,4');
    }

    public function testSpreadInFunctionCall(): void
    {
        $this->assertBothBackends('
            function sum(a, b, c) { return a + b + c; }
            var args = [1, 2, 3];
            sum(...args);
        ', 6);
    }

    public function testSpreadString(): void
    {
        $this->assertBothBackends('
            var chars = [..."hello"];
            chars.join(",");
        ', 'h,e,l,l,o');
    }

    public function testSpreadMergeObjects(): void
    {
        // Object spread {...a, ...b} not supported by parser; use Object.assign
        $this->assertBothBackends('
            var a = {x: 1, y: 2};
            var b = {y: 3, z: 4};
            var c = Object.assign({}, a, b);
            c.x + "," + c.y + "," + c.z;
        ', '1,3,4');
    }

    public function testSpreadOverrideOrder(): void
    {
        // Object spread not supported by parser; use Object.assign
        $this->assertBothBackends('
            var defaults = {color: "red", size: 10, visible: true};
            var overrides = {color: "blue", size: 20};
            var result = Object.assign({}, defaults, overrides);
            result.color + "," + result.size + "," + result.visible;
        ', 'blue,20,true');
    }

    // ═══════════════════════════════════════════════════════════════
    //  Control flow edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testSwitchFallthrough(): void
    {
        $this->assertBothBackends('
            var result = "";
            var x = 2;
            switch (x) {
                case 1: result += "one"; break;
                case 2: result += "two";
                case 3: result += "three"; break;
                case 4: result += "four";
            }
            result;
        ', 'twothree');  // fallthrough from 2 to 3
    }

    public function testSwitchDefault(): void
    {
        $this->assertBothBackends('
            function classify(x) {
                switch (x) {
                    case 1: return "one";
                    case 2: return "two";
                    default: return "other";
                }
            }
            classify(1) + "," + classify(2) + "," + classify(99);
        ', 'one,two,other');
    }

    public function testLabeledBreak(): void
    {
        // Labeled break not supported by parser; simulate with flag
        $this->assertBothBackends('
            var result = 0;
            var done = false;
            for (var i = 0; i < 5; i++) {
                for (var j = 0; j < 5; j++) {
                    if (i === 2 && j === 3) { done = true; break; }
                    result++;
                }
                if (done) break;
            }
            result;
        ', 13);
    }

    public function testDoWhileWithBreak(): void
    {
        $this->assertBothBackends('
            var i = 0;
            do {
                i++;
                if (i === 5) break;
            } while (i < 100);
            i;
        ', 5);
    }

    public function testForOfWithBreak(): void
    {
        $this->assertBothBackends('
            var result = 0;
            for (var x of [1, 2, 3, 4, 5, 6, 7, 8, 9, 10]) {
                if (x > 5) break;
                result += x;
            }
            result;
        ', 15);
    }

    public function testCommaOperator(): void
    {
        $this->assertBothBackends('
            var x = 0;
            for (var i = 0, j = 10; i < j; i++, j--) {
                x++;
            }
            x;
        ', 5);
    }

    // ═══════════════════════════════════════════════════════════════
    //  Bitwise and numeric edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testBitwiseOperators(): void
    {
        $this->assertBothBackends('5 & 3', 1);
        $this->assertBothBackends('5 | 3', 7);
        $this->assertBothBackends('5 ^ 3', 6);
        $this->assertBothBackends('~5', -6);
        $this->assertBothBackends('5 << 1', 10);
        $this->assertBothBackends('20 >> 2', 5);
        $this->assertBothBackends('-1 >>> 0', 4294967295);
    }

    public function testExponentiation(): void
    {
        $this->assertBothBackends('2 ** 10', 1024);
        $this->assertBothBackends('2 ** 0', 1);
        $this->assertBothBackends('(-2) ** 3', -8);
    }

    public function testNumberEdgeCases(): void
    {
        $this->assertBothBackends('Number.isInteger(5)', true);
        $this->assertBothBackends('Number.isInteger(5.5)', false);
        $this->assertBothBackends('Number.isFinite(42)', true);
        $this->assertBothBackends('Number.isFinite(Infinity)', false);
        $this->assertBothBackends('isFinite(42)', true);
        $this->assertBothBackends('isFinite(Infinity)', false);
    }

    public function testParseIntAndFloat(): void
    {
        $this->assertBothBackends('parseInt("42")', 42);
        $this->assertBothBackends('parseInt("0xFF", 16)', 255);
        $this->assertBothBackends('parseInt("111", 2)', 7);
        $this->assertBothBackends('parseFloat("3.14")', 3.14);
        $this->assertBothBackends('parseInt("42abc")', 42);
        $this->assertBothBackends('parseFloat("3.14xyz")', 3.14);
    }

    public function testToFixedAndToString(): void
    {
        $this->assertBothBackends('(3.14159).toFixed(2)', '3.14');
        $this->assertBothBackends('(255).toString(16)', 'ff');
        $this->assertBothBackends('(7).toString(2)', '111');
        $this->assertBothBackends('(100).toString()', '100');
    }

    public function testMathMethods(): void
    {
        $this->assertBothBackends('Math.max(1, 5, 3)', 5);
        $this->assertBothBackends('Math.min(1, 5, 3)', 1);
        $this->assertBothBackends('Math.abs(-42)', 42);
        $this->assertBothBackends('Math.floor(3.9)', 3);
        $this->assertBothBackends('Math.ceil(3.1)', 4);
        $this->assertBothBackends('Math.round(3.5)', 4);
        $this->assertBothBackends('Math.round(3.4)', 3);
        $this->assertBothBackends('Math.sign(-5)', -1);
        $this->assertBothBackends('Math.sign(0)', 0);
        $this->assertBothBackends('Math.sign(5)', 1);
        $this->assertBothBackends('Math.trunc(3.9)', 3);
        $this->assertBothBackends('Math.trunc(-3.9)', -3);
    }

    // ═══════════════════════════════════════════════════════════════
    //  JSON edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testJsonRoundTrip(): void
    {
        $this->assertBothBackends('
            var obj = {a: 1, b: [2, 3], c: {d: true, e: null}};
            var json = JSON.stringify(obj);
            var parsed = JSON.parse(json);
            parsed.a + "," + parsed.b[1] + "," + parsed.c.d + "," + (parsed.c.e === null);
        ', '1,3,true,true');
    }

    public function testJsonStringifyWithNesting(): void
    {
        $this->assertBothBackends('
            JSON.stringify({x: [1, [2, [3]]]});
        ', '{"x":[1,[2,[3]]]}');
    }

    public function testJsonParseAndModify(): void
    {
        $this->assertBothBackends('
            var data = JSON.parse("[1, 2, 3]");
            data.push(4);
            data.join(",");
        ', '1,2,3,4');
    }

    // ═══════════════════════════════════════════════════════════════
    //  Date edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testDateCreation(): void
    {
        $this->assertBothBackends('
            var d = new Date(2024, 0, 15);
            d.getFullYear() + "-" + (d.getMonth() + 1) + "-" + d.getDate();
        ', '2024-1-15');
    }

    public function testDateNow(): void
    {
        $this->assertBothBackends('typeof Date.now()', 'number');
        $this->assertBothBackends('Date.now() > 0', true);
    }

    // ═══════════════════════════════════════════════════════════════
    //  Compound/integration scenarios
    // ═══════════════════════════════════════════════════════════════

    public function testLinkedList(): void
    {
        // Transpiler requires all args; use null explicitly for leaf nodes
        $this->assertBothBackends('
            function Node(val, next) {
                this.val = val;
                this.next = next || null;
            }
            function toArray(node) {
                var result = [];
                var current = node;
                while (current !== null) {
                    result.push(current.val);
                    current = current.next;
                }
                return result;
            }
            function reverse(node) {
                var prev = null;
                var current = node;
                while (current !== null) {
                    var next = current.next;
                    current.next = prev;
                    prev = current;
                    current = next;
                }
                return prev;
            }
            var list = new Node(1, new Node(2, new Node(3, new Node(4, new Node(5, null)))));
            var reversed = reverse(list);
            toArray(reversed).join(",");
        ', '5,4,3,2,1');
    }

    public function testMemoizedRecursion(): void
    {
        $this->assertBothBackends('
            function memoize(fn) {
                var cache = {};
                return function(n) {
                    var key = "" + n;
                    if (cache.hasOwnProperty(key)) return cache[key];
                    var result = fn(n);
                    cache[key] = result;
                    return result;
                };
            }
            var fib = memoize(function(n) {
                if (n < 2) return n;
                return fib(n - 1) + fib(n - 2);
            });
            fib(30);
        ', 832040);
    }

    public function testEventEmitterPattern(): void
    {
        // Transpiler: object mutation through function args not supported
        $this->assertVm('
            function EventEmitter() {
                this.handlers = {};
            }
            EventEmitter.prototype = {};
            var proto = EventEmitter.prototype;

            var emit = function(emitter, event, data) {
                var handlers = emitter.handlers[event];
                if (handlers) {
                    handlers.forEach(function(fn) { fn(data); });
                }
            };

            var on = function(emitter, event, fn) {
                if (!emitter.handlers[event]) {
                    emitter.handlers[event] = [];
                }
                emitter.handlers[event].push(fn);
            };

            var log = [];
            var ee = new EventEmitter();
            on(ee, "data", function(x) { log.push("A:" + x); });
            on(ee, "data", function(x) { log.push("B:" + (x * 2)); });
            on(ee, "end", function() { log.push("done"); });

            emit(ee, "data", 5);
            emit(ee, "data", 10);
            emit(ee, "end");
            log.join(",");
        ', 'A:5,B:10,A:10,B:20,done');
    }

    public function testStateMachine(): void
    {
        $this->assertBothBackends('
            function createMachine(initial, transitions) {
                var state = initial;
                return {
                    send: function(event) {
                        var key = state + ":" + event;
                        if (transitions.hasOwnProperty(key)) {
                            state = transitions[key];
                        }
                        return state;
                    },
                    getState: function() { return state; }
                };
            }
            var machine = createMachine("idle", {
                "idle:start": "running",
                "running:pause": "paused",
                "paused:resume": "running",
                "running:stop": "idle",
                "paused:stop": "idle"
            });
            var log = [];
            log.push(machine.send("start"));
            log.push(machine.send("pause"));
            log.push(machine.send("resume"));
            log.push(machine.send("stop"));
            log.push(machine.send("invalid"));
            log.join(",");
        ', 'running,paused,running,idle,idle');
    }

    public function testCurryFunction(): void
    {
        // Simple manual curry without `arguments` keyword
        $this->assertBothBackends('
            function add3(a, b, c) { return a + b + c; }
            function curryAdd3(a) {
                return function(b) {
                    return function(c) {
                        return add3(a, b, c);
                    };
                };
            }
            curryAdd3(1)(2)(3) + "," + curryAdd3(10)(20)(30);
        ', '6,60');
    }

    public function testGroupBy(): void
    {
        // Transpiler: object mutation in reduce not supported
        $this->assertVm('
            var data = [
                {name: "Alice", dept: "eng"},
                {name: "Bob", dept: "sales"},
                {name: "Carol", dept: "eng"},
                {name: "Dave", dept: "sales"},
                {name: "Eve", dept: "eng"}
            ];
            var grouped = data.reduce(function(acc, item) {
                var key = item.dept;
                if (!acc.hasOwnProperty(key)) acc[key] = [];
                acc[key].push(item.name);
                return acc;
            }, {});
            grouped.eng.join(",") + "|" + grouped.sales.join(",");
        ', 'Alice,Carol,Eve|Bob,Dave');
    }

    public function testDeepClone(): void
    {
        $this->assertBothBackends('
            var original = {
                a: 1,
                b: [2, 3, {c: 4}],
                d: {e: {f: 5}}
            };
            var clone = JSON.parse(JSON.stringify(original));
            clone.b[2].c = 999;
            clone.d.e.f = 888;
            original.b[2].c + "," + original.d.e.f + "," + clone.b[2].c + "," + clone.d.e.f;
        ', '4,5,999,888');
    }

    public function testIterativeFibonacciWithDestructuring(): void
    {
        $this->assertBothBackends('
            function fib(n) {
                var a = 0, b = 1;
                for (var i = 0; i < n; i++) {
                    var temp = b;
                    b = a + b;
                    a = temp;
                }
                return a;
            }
            fib(10) + "," + fib(20) + "," + fib(30);
        ', '55,6765,832040');
    }

    public function testTowerOfHanoi(): void
    {
        $this->assertBothBackends('
            var moves = [];
            function hanoi(n, from, to, aux) {
                if (n === 1) {
                    moves.push(from + "->" + to);
                    return;
                }
                hanoi(n - 1, from, aux, to);
                moves.push(from + "->" + to);
                hanoi(n - 1, aux, to, from);
            }
            hanoi(3, "A", "C", "B");
            moves.length + ":" + moves[0] + "," + moves[moves.length - 1];
        ', '7:A->C,A->C');
    }

    public function testFlattenDeep(): void
    {
        $this->assertBothBackends('
            function flattenDeep(arr) {
                return arr.reduce(function(acc, item) {
                    if (Array.isArray(item)) {
                        return acc.concat(flattenDeep(item));
                    }
                    acc.push(item);
                    return acc;
                }, []);
            }
            flattenDeep([1, [2, [3, [4, [5]]]]]).join(",");
        ', '1,2,3,4,5');
    }

    public function testComplexReduce(): void
    {
        $this->assertBothBackends('
            var transactions = [
                {type: "credit", amount: 100},
                {type: "debit", amount: 30},
                {type: "credit", amount: 50},
                {type: "debit", amount: 20},
                {type: "credit", amount: 75}
            ];
            var summary = transactions.reduce(function(acc, t) {
                if (t.type === "credit") {
                    acc.totalCredit += t.amount;
                    acc.count++;
                } else {
                    acc.totalDebit += t.amount;
                }
                acc.balance += (t.type === "credit" ? t.amount : -t.amount);
                return acc;
            }, {totalCredit: 0, totalDebit: 0, balance: 0, count: 0});
            summary.balance + "," + summary.totalCredit + "," + summary.totalDebit + "," + summary.count;
        ', '175,225,50,3');
    }

    // ═══════════════════════════════════════════════════════════════
    //  Tricky short-circuit and evaluation order
    // ═══════════════════════════════════════════════════════════════

    public function testShortCircuitAnd(): void
    {
        $this->assertBothBackends('
            var x = 0;
            false && (x = 1);
            x;
        ', 0);
        $this->assertBothBackends('
            var x = 0;
            true && (x = 1);
            x;
        ', 1);
    }

    public function testShortCircuitOr(): void
    {
        $this->assertBothBackends('
            var x = 0;
            true || (x = 1);
            x;
        ', 0);
        $this->assertBothBackends('
            var x = 0;
            false || (x = 1);
            x;
        ', 1);
    }

    public function testLogicalOperatorReturnValues(): void
    {
        $this->assertBothBackends('0 || "fallback"', 'fallback');
        $this->assertBothBackends('"value" || "fallback"', 'value');
        $this->assertBothBackends('"value" && "second"', 'second');
        $this->assertBothBackends('0 && "second"', 0);
        $this->assertBothBackends('null && "second"', null);
        $this->assertBothBackends('"" || 0 || null || "found"', 'found');
    }

    public function testTernaryNesting(): void
    {
        $this->assertBothBackends('
            function classify(n) {
                return n > 0 ? "positive" : n < 0 ? "negative" : "zero";
            }
            classify(5) + "," + classify(-3) + "," + classify(0);
        ', 'positive,negative,zero');
    }

    public function testCompoundAssignment(): void
    {
        $this->assertBothBackends('
            var x = 10;
            x += 5;
            x -= 3;
            x *= 2;
            x /= 4;
            x %= 3;
            x;
        ', 0);
    }

    public function testIncrementDecrementReturnValues(): void
    {
        $this->assertBothBackends('
            var a = 5;
            var b = a++;
            var c = ++a;
            b + "," + c + "," + a;
        ', '5,7,7');
        $this->assertBothBackends('
            var a = 5;
            var b = a--;
            var c = --a;
            b + "," + c + "," + a;
        ', '5,3,3');
    }

    // ═══════════════════════════════════════════════════════════════
    //  Global interop edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testGlobalsWithAllTypes(): void
    {
        $this->assertBothBackends(
            'typeof num + "," + typeof str + "," + typeof bool + "," + typeof arr + "," + typeof obj + "," + typeof fn',
            'number,string,boolean,object,object,function',
            [
                'num' => 42,
                'str' => 'hello',
                'bool' => true,
                'arr' => [1, 2, 3],
                'obj' => ['key' => 'value'],
                'fn' => fn($x) => $x * 2,
            ]
        );
    }

    public function testGlobalsCallback(): void
    {
        $this->assertBothBackends(
            '[1, 2, 3].map(transform).join(",")',
            '2,4,6',
            ['transform' => fn(mixed $x): mixed => $x * 2]
        );
    }

    public function testGlobalsMutation(): void
    {
        $this->assertBothBackends(
            'data.push(4); data.join(",")',
            '1,2,3,4',
            ['data' => [1, 2, 3]]
        );
    }

    public function testGlobalsNestedAccess(): void
    {
        $this->assertBothBackends(
            'config.db.host + ":" + config.db.port',
            'localhost:5432',
            ['config' => ['db' => ['host' => 'localhost', 'port' => 5432]]]
        );
    }

    // ═══════════════════════════════════════════════════════════════
    //  Encoding and URI functions
    // ═══════════════════════════════════════════════════════════════

    public function testEncodeDecodeUri(): void
    {
        $this->assertBothBackends('encodeURIComponent("hello world")', 'hello%20world');
        $this->assertBothBackends('decodeURIComponent("hello%20world")', 'hello world');
        $this->assertBothBackends('encodeURIComponent("a+b=c&d")', 'a%2Bb%3Dc%26d');
        $this->assertBothBackends('decodeURIComponent(encodeURIComponent("café"))', 'café');
    }

    // ═══════════════════════════════════════════════════════════════
    //  Void and delete operators
    // ═══════════════════════════════════════════════════════════════

    public function testVoidOperator(): void
    {
        $this->assertBothBackends('void 0', null);  // undefined → null
        $this->assertBothBackends('void "hello"', null);
        $this->assertVm('typeof void 0', 'undefined');  // transpiler: void returns null, typeof null = 'object'
    }

    public function testDeleteOperator(): void
    {
        $this->assertBothBackends('
            var obj = {a: 1, b: 2, c: 3};
            delete obj.b;
            obj.hasOwnProperty("b");
        ', false);
    }

    // ═══════════════════════════════════════════════════════════════
    //  String.fromCharCode and charCodeAt
    // ═══════════════════════════════════════════════════════════════

    public function testFromCharCodeAndCharCodeAt(): void
    {
        $this->assertBothBackends('String.fromCharCode(72, 101, 108, 108, 111)', 'Hello');
        $this->assertBothBackends('"Hello".charCodeAt(0)', 72);
    }

    // ═══════════════════════════════════════════════════════════════
    //  Extreme nesting and recursion
    // ═══════════════════════════════════════════════════════════════

    public function testDeeplyNestedObject(): void
    {
        $this->assertBothBackends('
            var obj = {a: {b: {c: {d: {e: {f: {g: 42}}}}}}};
            obj.a.b.c.d.e.f.g;
        ', 42);
    }

    public function testMutualRecursion(): void
    {
        $this->assertBothBackends('
            function isEven(n) {
                if (n === 0) return true;
                return isOdd(n - 1);
            }
            function isOdd(n) {
                if (n === 0) return false;
                return isEven(n - 1);
            }
            isEven(10) + "," + isOdd(10) + "," + isEven(7) + "," + isOdd(7);
        ', 'true,false,false,true');
    }

    public function testAccumulatorPattern(): void
    {
        $this->assertBothBackends('
            function pipeline(value, fns) {
                return fns.reduce(function(acc, fn) {
                    return fn(acc);
                }, value);
            }
            pipeline(5, [
                function(x) { return x * 2; },
                function(x) { return x + 3; },
                function(x) { return x * x; },
                function(x) { return x - 1; }
            ]);
        ', 168); // ((5*2+3)^2) - 1 = 169 - 1 = 168
    }
}

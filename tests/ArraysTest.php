<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

use ScriptLite\Engine;
use PHPUnit\Framework\TestCase;

final class ArraysTest extends TestCase
{
    private Engine $engine;

    protected function setUp(): void
    {
        $this->engine = new Engine();
    }

    // ═══════════════════ Arrays ═══════════════════

    public function testArrayLiteral(): void
    {
        self::assertSame([1, 2, 3], $this->engine->eval('[1, 2, 3]'));
    }

    public function testEmptyArray(): void
    {
        self::assertSame([], $this->engine->eval('[]'));
    }

    public function testArrayWithExpressions(): void
    {
        self::assertSame([3, 6, 9], $this->engine->eval('[1+2, 2*3, 3*3]'));
    }

    public function testArrayIndexAccess(): void
    {
        $result = $this->engine->eval('
            var arr = [10, 20, 30];
            arr[1];
        ');
        self::assertSame(20, $result);
    }

    public function testArrayIndexAssignment(): void
    {
        $result = $this->engine->eval('
            var arr = [1, 2, 3];
            arr[1] = 99;
            arr;
        ');
        self::assertSame([1, 99, 3], $result);
    }

    public function testArrayLength(): void
    {
        $result = $this->engine->eval('
            var arr = [10, 20, 30, 40];
            arr.length;
        ');
        self::assertSame(4, $result);
    }

    public function testArrayPush(): void
    {
        $result = $this->engine->eval('
            var arr = [1, 2];
            arr.push(3);
            arr.push(4);
            arr;
        ');
        self::assertSame([1, 2, 3, 4], $result);
    }

    public function testArrayPop(): void
    {
        $result = $this->engine->eval('
            var arr = [1, 2, 3];
            var last = arr.pop();
            last;
        ');
        self::assertSame(3, $result);
    }

    public function testArrayPopMutates(): void
    {
        $result = $this->engine->eval('
            var arr = [1, 2, 3];
            arr.pop();
            arr.length;
        ');
        self::assertSame(2, $result);
    }

    public function testArrayInForLoop(): void
    {
        $result = $this->engine->eval('
            var arr = [10, 20, 30];
            var sum = 0;
            for (var i = 0; i < arr.length; i = i + 1) {
                sum = sum + arr[i];
            }
            sum;
        ');
        self::assertSame(60, $result);
    }

    public function testArrayJoin(): void
    {
        $result = $this->engine->eval('
            var arr = [1, 2, 3];
            arr.join("-");
        ');
        self::assertSame('1-2-3', $result);
    }

    public function testArrayConcat(): void
    {
        $result = $this->engine->eval('
            var a = [1, 2];
            var b = [3, 4];
            a.concat(b);
        ');
        self::assertSame([1, 2, 3, 4], $result);
    }

    public function testArrayIndexOf(): void
    {
        self::assertSame(2, $this->engine->eval('[10, 20, 30].indexOf(30)'));
        self::assertSame(-1, $this->engine->eval('[10, 20, 30].indexOf(99)'));
    }

    public function testArrayIncludes(): void
    {
        self::assertTrue($this->engine->eval('[1, 2, 3].includes(2)'));
        self::assertFalse($this->engine->eval('[1, 2, 3].includes(5)'));
    }

    public function testArraySlice(): void
    {
        $result = $this->engine->eval('[1, 2, 3, 4, 5].slice(1, 4)');
        self::assertSame([2, 3, 4], $result);
    }

    public function testNestedArrays(): void
    {
        $result = $this->engine->eval('
            var matrix = [[1, 2], [3, 4]];
            matrix[1][0];
        ');
        self::assertSame(3, $result);
    }

    public function testArrayStringCoercion(): void
    {
        $result = $this->engine->eval('"items: " + [1, 2, 3]');
        self::assertSame('items: 1,2,3', $result);
    }

    public function testArrayBuildWithPush(): void
    {
        $result = $this->engine->eval('
            var arr = [];
            for (var i = 0; i < 5; i = i + 1) {
                arr.push(i * i);
            }
            arr;
        ');
        self::assertSame([0, 1, 4, 9, 16], $result);
    }

    public function testArrayPassedToFunction(): void
    {
        $result = $this->engine->eval('
            function sum(arr) {
                var total = 0;
                for (var i = 0; i < arr.length; i = i + 1) {
                    total = total + arr[i];
                }
                return total;
            }
            sum([1, 2, 3, 4, 5]);
        ');
        self::assertSame(15, $result);
    }

    public function testTypeofArray(): void
    {
        self::assertSame('object', $this->engine->eval('typeof [1, 2, 3]'));
    }

    public function testStringLength(): void
    {
        self::assertSame(5, $this->engine->eval('"hello".length'));
    }

    public function testArrayTrailingComma(): void
    {
        self::assertSame([1, 2, 3], $this->engine->eval('[1, 2, 3,]'));
    }

    public function testArrayMethodChaining(): void
    {
        $result = $this->engine->eval('[1, 2, 3].concat([4, 5]).length');
        self::assertSame(5, $result);
    }

    // ═══════════════════ Array Callback Methods ═══════════════════

    public function testArrayForEach(): void
    {
        $result = $this->engine->eval('
            var arr = [1, 2, 3];
            var sum = 0;
            arr.forEach(function(el) { sum = sum + el; });
            sum;
        ');
        self::assertSame(6, $result);
    }

    public function testArrayForEachWithIndex(): void
    {
        $result = $this->engine->eval('
            var arr = [10, 20, 30];
            var indices = [];
            arr.forEach(function(el, i) { indices.push(i); });
            indices;
        ');
        self::assertSame([0, 1, 2], $result);
    }

    public function testArrayMap(): void
    {
        $result = $this->engine->eval('[1, 2, 3].map(function(x) { return x * 2; })');
        self::assertSame([2, 4, 6], $result);
    }

    public function testArrayMapWithIndex(): void
    {
        $result = $this->engine->eval('[10, 20, 30].map(function(x, i) { return i; })');
        self::assertSame([0, 1, 2], $result);
    }

    public function testArrayFilter(): void
    {
        $result = $this->engine->eval('[1, 2, 3, 4, 5, 6].filter(function(x) { return x % 2 === 0; })');
        self::assertSame([2, 4, 6], $result);
    }

    public function testArrayFilterWithIndex(): void
    {
        $result = $this->engine->eval('[10, 20, 30, 40].filter(function(x, i) { return i > 1; })');
        self::assertSame([30, 40], $result);
    }

    public function testArrayFind(): void
    {
        $result = $this->engine->eval('[1, 2, 3, 4].find(function(x) { return x > 2; })');
        self::assertSame(3, $result);
    }

    public function testArrayFindNotFound(): void
    {
        $result = $this->engine->eval('[1, 2, 3].find(function(x) { return x > 10; })');
        self::assertNull($result); // undefined → null via toPhp
    }

    public function testArrayFindIndex(): void
    {
        self::assertSame(2, $this->engine->eval('[1, 2, 3, 4].findIndex(function(x) { return x > 2; })'));
        self::assertSame(-1, $this->engine->eval('[1, 2, 3].findIndex(function(x) { return x > 10; })'));
    }

    public function testArrayReduce(): void
    {
        $result = $this->engine->eval('[1, 2, 3, 4].reduce(function(acc, x) { return acc + x; }, 0)');
        self::assertSame(10, $result);
    }

    public function testArrayReduceNoInitialValue(): void
    {
        $result = $this->engine->eval('[1, 2, 3, 4].reduce(function(acc, x) { return acc + x; })');
        self::assertSame(10, $result);
    }

    public function testArrayReduceBuildString(): void
    {
        $result = $this->engine->eval('
            [1, 2, 3].reduce(function(acc, x) { return acc + "-" + x; }, "start")
        ');
        self::assertSame('start-1-2-3', $result);
    }

    public function testArrayEvery(): void
    {
        self::assertTrue($this->engine->eval('[2, 4, 6].every(function(x) { return x % 2 === 0; })'));
        self::assertFalse($this->engine->eval('[2, 3, 6].every(function(x) { return x % 2 === 0; })'));
    }

    public function testArraySome(): void
    {
        self::assertTrue($this->engine->eval('[1, 2, 3].some(function(x) { return x > 2; })'));
        self::assertFalse($this->engine->eval('[1, 2, 3].some(function(x) { return x > 5; })'));
    }

    public function testArraySort(): void
    {
        $result = $this->engine->eval('
            var arr = [3, 1, 2];
            arr.sort(function(a, b) { return a - b; });
            arr;
        ');
        self::assertSame([1, 2, 3], $result);
    }

    public function testArraySortDescending(): void
    {
        $result = $this->engine->eval('
            var arr = [1, 3, 2];
            arr.sort(function(a, b) { return b - a; });
            arr;
        ');
        self::assertSame([3, 2, 1], $result);
    }

    public function testArraySortDefault(): void
    {
        // Default sort is lexicographic
        $result = $this->engine->eval('
            var arr = [10, 9, 2, 1];
            arr.sort();
            arr;
        ');
        self::assertSame([1, 10, 2, 9], $result);
    }

    public function testArraySplice(): void
    {
        $result = $this->engine->eval('
            var arr = [1, 2, 3, 4, 5];
            var removed = arr.splice(1, 2);
            arr;
        ');
        self::assertSame([1, 4, 5], $result);
    }

    public function testArraySpliceInsert(): void
    {
        $result = $this->engine->eval('
            var arr = [1, 2, 5];
            arr.splice(2, 0, 3, 4);
            arr;
        ');
        self::assertSame([1, 2, 3, 4, 5], $result);
    }

    public function testArrayFlat(): void
    {
        $result = $this->engine->eval('[[1, 2], [3, 4], [5]].flat()');
        self::assertSame([1, 2, 3, 4, 5], $result);
    }

    public function testArrayFill(): void
    {
        $result = $this->engine->eval('
            var arr = [1, 2, 3, 4];
            arr.fill(0, 1, 3);
            arr;
        ');
        self::assertSame([1, 0, 0, 4], $result);
    }

    public function testMapFilterChain(): void
    {
        $result = $this->engine->eval('
            [1, 2, 3, 4, 5, 6]
                .filter(function(x) { return x % 2 === 0; })
                .map(function(x) { return x * 10; });
        ');
        self::assertSame([20, 40, 60], $result);
    }

    public function testMapReduceChain(): void
    {
        $result = $this->engine->eval('
            [1, 2, 3, 4, 5]
                .map(function(x) { return x * x; })
                .reduce(function(acc, x) { return acc + x; }, 0);
        ');
        self::assertSame(55, $result);
    }

    public function testCallbackWithClosure(): void
    {
        $result = $this->engine->eval('
            var multiplier = 3;
            [1, 2, 3].map(function(x) { return x * multiplier; });
        ');
        self::assertSame([3, 6, 9], $result);
    }

    public function testEveryEmptyArray(): void
    {
        self::assertTrue($this->engine->eval('[].every(function(x) { return false; })'));
    }

    public function testSomeEmptyArray(): void
    {
        self::assertFalse($this->engine->eval('[].some(function(x) { return true; })'));
    }
}

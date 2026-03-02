<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

class ArraysTest extends ScriptLiteTestCase
{

    // ═══════════════════ Arrays ═══════════════════

    public function testArrayLiteral(): void
    {
        $this->assertBothBackends('[1, 2, 3]', [1, 2, 3]);
    }

    public function testEmptyArray(): void
    {
        $this->assertBothBackends('[]', []);
    }

    public function testArrayWithExpressions(): void
    {
        $this->assertBothBackends('[1+2, 2*3, 3*3]', [3, 6, 9]);
    }

    public function testArrayIndexAccess(): void
    {
        $this->assertBothBackends('
            var arr = [10, 20, 30];
            arr[1];
        ', 20);
    }

    public function testArrayIndexAssignment(): void
    {
        $this->assertBothBackends('
            var arr = [1, 2, 3];
            arr[1] = 99;
            arr;
        ', [1, 99, 3]);
    }

    public function testArrayLength(): void
    {
        $this->assertBothBackends('
            var arr = [10, 20, 30, 40];
            arr.length;
        ', 4);
    }

    public function testArrayPush(): void
    {
        $this->assertBothBackends('
            var arr = [1, 2];
            arr.push(3);
            arr.push(4);
            arr;
        ', [1, 2, 3, 4]);
    }

    public function testArrayPop(): void
    {
        $this->assertBothBackends('
            var arr = [1, 2, 3];
            var last = arr.pop();
            last;
        ', 3);
    }

    public function testArrayPopMutates(): void
    {
        $this->assertBothBackends('
            var arr = [1, 2, 3];
            arr.pop();
            arr.length;
        ', 2);
    }

    public function testArrayInForLoop(): void
    {
        $this->assertBothBackends('
            var arr = [10, 20, 30];
            var sum = 0;
            for (var i = 0; i < arr.length; i = i + 1) {
                sum = sum + arr[i];
            }
            sum;
        ', 60);
    }

    public function testArrayJoin(): void
    {
        $this->assertBothBackends('
            var arr = [1, 2, 3];
            arr.join("-");
        ', '1-2-3');
    }

    public function testArrayConcat(): void
    {
        $this->assertBothBackends('
            var a = [1, 2];
            var b = [3, 4];
            a.concat(b);
        ', [1, 2, 3, 4]);
    }

    public function testArrayIndexOf(): void
    {
        $this->assertBothBackends('[10, 20, 30].indexOf(30)', 2);
        $this->assertBothBackends('[10, 20, 30].indexOf(99)', -1);
    }

    public function testArrayIncludes(): void
    {
        $this->assertBothBackends('[1, 2, 3].includes(2)', true);
        $this->assertBothBackends('[1, 2, 3].includes(5)', false);
    }

    public function testArraySlice(): void
    {
        $this->assertBothBackends('[1, 2, 3, 4, 5].slice(1, 4)', [2, 3, 4]);
    }

    public function testNestedArrays(): void
    {
        $this->assertBothBackends('
            var matrix = [[1, 2], [3, 4]];
            matrix[1][0];
        ', 3);
    }

    public function testArrayStringCoercion(): void
    {
        $this->assertBothBackends('"items: " + [1, 2, 3]', 'items: 1,2,3');
    }

    public function testArrayBuildWithPush(): void
    {
        $this->assertBothBackends('
            var arr = [];
            for (var i = 0; i < 5; i = i + 1) {
                arr.push(i * i);
            }
            arr;
        ', [0, 1, 4, 9, 16]);
    }

    public function testArrayPassedToFunction(): void
    {
        $this->assertBothBackends('
            function sum(arr) {
                var total = 0;
                for (var i = 0; i < arr.length; i = i + 1) {
                    total = total + arr[i];
                }
                return total;
            }
            sum([1, 2, 3, 4, 5]);
        ', 15);
    }

    public function testTypeofArray(): void
    {
        $this->assertBothBackends('typeof [1, 2, 3]', 'object');
    }

    public function testStringLength(): void
    {
        $this->assertBothBackends('"hello".length', 5);
    }

    public function testArrayTrailingComma(): void
    {
        $this->assertBothBackends('[1, 2, 3,]', [1, 2, 3]);
    }

    public function testArrayMethodChaining(): void
    {
        $this->assertBothBackends('[1, 2, 3].concat([4, 5]).length', 5);
    }

    // ═══════════════════ Array Callback Methods ═══════════════════

    public function testArrayForEach(): void
    {
        $this->assertBothBackends('
            var arr = [1, 2, 3];
            var sum = 0;
            arr.forEach(function(el) { sum = sum + el; });
            sum;
        ', 6);
    }

    public function testArrayForEachWithIndex(): void
    {
        $this->assertBothBackends('
            var arr = [10, 20, 30];
            var indices = [];
            arr.forEach(function(el, i) { indices.push(i); });
            indices;
        ', [0, 1, 2]);
    }

    public function testArrayMap(): void
    {
        $this->assertBothBackends('[1, 2, 3].map(function(x) { return x * 2; })', [2, 4, 6]);
    }

    public function testArrayMapWithIndex(): void
    {
        $this->assertBothBackends('[10, 20, 30].map(function(x, i) { return i; })', [0, 1, 2]);
    }

    public function testArrayFilter(): void
    {
        $this->assertBothBackends('[1, 2, 3, 4, 5, 6].filter(function(x) { return x % 2 === 0; })', [2, 4, 6]);
    }

    public function testArrayFilterWithIndex(): void
    {
        $this->assertBothBackends('[10, 20, 30, 40].filter(function(x, i) { return i > 1; })', [30, 40]);
    }

    public function testArrayFind(): void
    {
        $this->assertBothBackends('[1, 2, 3, 4].find(function(x) { return x > 2; })', 3);
    }

    public function testArrayFindNotFound(): void
    {
        $this->assertBothBackends('[1, 2, 3].find(function(x) { return x > 10; })', null);
    }

    public function testArrayFindIndex(): void
    {
        $this->assertBothBackends('[1, 2, 3, 4].findIndex(function(x) { return x > 2; })', 2);
        $this->assertBothBackends('[1, 2, 3].findIndex(function(x) { return x > 10; })', -1);
    }

    public function testArrayReduce(): void
    {
        $this->assertBothBackends('[1, 2, 3, 4].reduce(function(acc, x) { return acc + x; }, 0)', 10);
    }

    public function testArrayReduceNoInitialValue(): void
    {
        $this->assertBothBackends('[1, 2, 3, 4].reduce(function(acc, x) { return acc + x; })', 10);
    }

    public function testArrayReduceBuildString(): void
    {
        $this->assertBothBackends('
            [1, 2, 3].reduce(function(acc, x) { return acc + "-" + x; }, "start")
        ', 'start-1-2-3');
    }

    public function testArrayEvery(): void
    {
        $this->assertBothBackends('[2, 4, 6].every(function(x) { return x % 2 === 0; })', true);
        $this->assertBothBackends('[2, 3, 6].every(function(x) { return x % 2 === 0; })', false);
    }

    public function testArraySome(): void
    {
        $this->assertBothBackends('[1, 2, 3].some(function(x) { return x > 2; })', true);
        $this->assertBothBackends('[1, 2, 3].some(function(x) { return x > 5; })', false);
    }

    public function testArraySort(): void
    {
        $this->assertBothBackends('
            var arr = [3, 1, 2];
            arr.sort(function(a, b) { return a - b; });
            arr;
        ', [1, 2, 3]);
    }

    public function testArraySortDescending(): void
    {
        $this->assertBothBackends('
            var arr = [1, 3, 2];
            arr.sort(function(a, b) { return b - a; });
            arr;
        ', [3, 2, 1]);
    }

    public function testArraySortDefault(): void
    {
        // Default sort is lexicographic
        $this->assertBothBackends('
            var arr = [10, 9, 2, 1];
            arr.sort();
            arr;
        ', [1, 10, 2, 9]);
    }

    public function testArraySplice(): void
    {
        $this->assertBothBackends('
            var arr = [1, 2, 3, 4, 5];
            var removed = arr.splice(1, 2);
            arr;
        ', [1, 4, 5]);
    }

    public function testArraySpliceInsert(): void
    {
        $this->assertBothBackends('
            var arr = [1, 2, 5];
            arr.splice(2, 0, 3, 4);
            arr;
        ', [1, 2, 3, 4, 5]);
    }

    public function testArrayFlat(): void
    {
        $this->assertBothBackends('[[1, 2], [3, 4], [5]].flat()', [1, 2, 3, 4, 5]);
    }

    public function testArrayFill(): void
    {
        $this->assertBothBackends('
            var arr = [1, 2, 3, 4];
            arr.fill(0, 1, 3);
            arr;
        ', [1, 0, 0, 4]);
    }

    public function testMapFilterChain(): void
    {
        $this->assertBothBackends('
            [1, 2, 3, 4, 5, 6]
                .filter(function(x) { return x % 2 === 0; })
                .map(function(x) { return x * 10; });
        ', [20, 40, 60]);
    }

    public function testMapReduceChain(): void
    {
        $this->assertBothBackends('
            [1, 2, 3, 4, 5]
                .map(function(x) { return x * x; })
                .reduce(function(acc, x) { return acc + x; }, 0);
        ', 55);
    }

    public function testCallbackWithClosure(): void
    {
        $this->assertBothBackends('
            var multiplier = 3;
            [1, 2, 3].map(function(x) { return x * multiplier; });
        ', [3, 6, 9]);
    }

    public function testEveryEmptyArray(): void
    {
        $this->assertBothBackends('[].every(function(x) { return false; })', true);
    }

    public function testSomeEmptyArray(): void
    {
        $this->assertBothBackends('[].some(function(x) { return true; })', false);
    }
}

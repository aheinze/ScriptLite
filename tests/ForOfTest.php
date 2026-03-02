<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

class ForOfTest extends ScriptLiteTestCase
{

    // ── for...of ──

    public function testForOfArray(): void
    {
        $this->assertBothBackends('
            var result = 0;
            var arr = [1, 2, 3, 4, 5];
            for (var x of arr) {
                result += x;
            }
            result;
        ', 15);
    }

    public function testForOfWithLet(): void
    {
        $this->assertBothBackends('
            var sum = 0;
            for (let n of [10, 20, 30]) {
                sum += n;
            }
            sum;
        ', 60);
    }

    public function testForOfStrings(): void
    {
        $this->assertBothBackends('
            var items = ["hello", "world"];
            var result = "";
            for (var s of items) {
                result += s + " ";
            }
            result.trim();
        ', 'hello world');
    }

    public function testForOfWithBreak(): void
    {
        $this->assertBothBackends('
            var sum = 0;
            for (var x of [1, 2, 3, 4, 5]) {
                if (x > 3) break;
                sum += x;
            }
            sum;
        ', 6);
    }

    public function testForOfWithContinue(): void
    {
        $this->assertBothBackends('
            var sum = 0;
            for (var x of [1, 2, 3, 4, 5]) {
                if (x % 2 === 0) continue;
                sum += x;
            }
            sum;
        ', 9);
    }

    public function testForOfEmpty(): void
    {
        $this->assertBothBackends('
            var sum = 0;
            for (var x of []) {
                sum += x;
            }
            sum;
        ', 0);
    }

    public function testForOfNested(): void
    {
        $this->assertBothBackends('
            var matrix = [[1, 2], [3, 4]];
            var sum = 0;
            for (var row of matrix) {
                for (var val of row) {
                    sum += val;
                }
            }
            sum;
        ', 10);
    }

    // ── for...in ──

    public function testForInObject(): void
    {
        $this->assertBothBackends('
            var obj = {a: 1, b: 2, c: 3};
            var keys = [];
            for (var k in obj) {
                keys.push(k);
            }
            keys.join(",");
        ', 'a,b,c');
    }

    public function testForInValues(): void
    {
        $this->assertBothBackends('
            var obj = {x: 10, y: 20};
            var sum = 0;
            for (var key in obj) {
                sum += obj[key];
            }
            sum;
        ', 30);
    }
}

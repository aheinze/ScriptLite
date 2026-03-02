<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

class DestructuringTest extends ScriptLiteTestCase
{

    // ── Array destructuring ──

    public function testArrayBasic(): void
    {
        $this->assertBothBackends('
            var arr = [1, 2, 3];
            var [a, b, c] = arr;
            a + b + c;
        ', 6);
    }

    public function testArrayPartial(): void
    {
        $this->assertBothBackends('
            var [x, y] = [10, 20, 30];
            x + y;
        ', 30);
    }

    public function testArrayWithRest(): void
    {
        $this->assertBothBackends('
            var [first, ...rest] = [1, 2, 3, 4, 5];
            first + rest.length;
        ', 5);
    }

    public function testArrayDefault(): void
    {
        $this->assertBothBackends('
            var [a, b = 99] = [42];
            a + b;
        ', 141);
    }

    public function testArrayFromFunction(): void
    {
        $this->assertBothBackends('
            function getPair() { return [3, 7]; }
            var [a, b] = getPair();
            a * b;
        ', 21);
    }

    // ── Object destructuring ──

    public function testObjectBasic(): void
    {
        $this->assertBothBackends('
            var obj = {name: "Alice", age: 30};
            var {name, age} = obj;
            name + " is " + age;
        ', 'Alice is 30');
    }

    public function testObjectRename(): void
    {
        $this->assertBothBackends('
            var {name: n, age: a} = {name: "Bob", age: 25};
            n + " " + a;
        ', 'Bob 25');
    }

    public function testObjectDefault(): void
    {
        $this->assertBothBackends('
            var {x, y = 10} = {x: 5};
            x + y;
        ', 15);
    }

    public function testObjectFromFunction(): void
    {
        $this->assertBothBackends('
            function getConfig() { return {host: "localhost", port: 3000}; }
            var {host, port} = getConfig();
            host + ":" + port;
        ', 'localhost:3000');
    }

    // ── Shorthand object properties ──

    public function testShorthandProperty(): void
    {
        $this->assertBothBackends('
            var x = 1;
            var y = 2;
            var obj = {x, y};
            obj.x + obj.y;
        ', 3);
    }

    public function testShorthandMixed(): void
    {
        $this->assertBothBackends('
            var name = "Alice";
            var obj = {name, age: 30};
            obj.name + " " + obj.age;
        ', 'Alice 30');
    }

    // ── Computed property names ──

    public function testComputedPropertyName(): void
    {
        $this->assertBothBackends('
            var key = "name";
            var obj = {[key]: "Alice"};
            obj.name;
        ', 'Alice');
    }

    public function testComputedPropertyExpression(): void
    {
        $this->assertBothBackends('
            var prefix = "get";
            var obj = {[prefix + "Name"]: "Bob"};
            obj.getName;
        ', 'Bob');
    }
}

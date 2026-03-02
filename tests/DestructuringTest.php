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

    // ── Multi-var declarations ──

    public function testMultiVarDeclaration(): void
    {
        $this->assertBothBackends('var a = 1, b = 2, c = 3; a + b + c', 6);
    }

    public function testMultiLetDeclaration(): void
    {
        $this->assertBothBackends('let x = 10, y = 20; x + y', 30);
    }

    public function testMultiVarForInit(): void
    {
        $this->assertBothBackends('
            var sum = 0;
            for (var i = 0, j = 10; i < 5; i++, j--) {
                sum = sum + i + j;
            }
            sum
        ', 50);
    }

    public function testMultiLetForInit(): void
    {
        $this->assertBothBackends('
            var result = 0;
            for (let i = 0, j = 100; i < 3; i++, j -= 10) {
                result = result + j;
            }
            result
        ', 270);
    }

    public function testCommaInForUpdate(): void
    {
        $this->assertBothBackends('
            var a = 0, b = 0;
            for (var i = 0; i < 3; i++, a++, b += 2) {}
            a + b
        ', 9);
    }

    // ── Destructuring in function parameters ──

    public function testObjectDestructuringParam(): void
    {
        $this->assertBothBackends('
            function greet({name, age}) {
                return name + " is " + age;
            }
            greet({name: "Alice", age: 30})
        ', 'Alice is 30');
    }

    public function testArrayDestructuringParam(): void
    {
        $this->assertBothBackends('
            function sum([a, b, c]) {
                return a + b + c;
            }
            sum([10, 20, 30])
        ', 60);
    }

    public function testDestructuringParamWithDefault(): void
    {
        $this->assertBothBackends('
            function f({x = 1, y = 2}) {
                return x + y;
            }
            f({x: 10})
        ', 12);
    }

    public function testDestructuringParamWithRename(): void
    {
        $this->assertBothBackends('
            function f({name: n, age: a}) {
                return n + " " + a;
            }
            f({name: "Bob", age: 25})
        ', 'Bob 25');
    }

    public function testDestructuringParamMixed(): void
    {
        $this->assertBothBackends('
            function f(x, {a, b}, y) {
                return x + a + b + y;
            }
            f(1, {a: 2, b: 3}, 4)
        ', 10);
    }

    public function testDestructuringParamCallback(): void
    {
        $this->assertBothBackends('
            var items = [{name: "a", val: 1}, {name: "b", val: 2}];
            var sum = 0;
            items.forEach(function({val}) { sum = sum + val; });
            sum
        ', 3);
    }

    // ── Nested destructuring ──

    public function testNestedObjectDestructuring(): void
    {
        $this->assertBothBackends('
            var obj = {user: {name: "Alice", age: 30}};
            var {user: {name, age}} = obj;
            name + " " + age;
        ', 'Alice 30');
    }

    public function testNestedArrayDestructuring(): void
    {
        $this->assertBothBackends('
            var arr = [1, [2, 3], 4];
            var [a, [b, c], d] = arr;
            a + b + c + d;
        ', 10);
    }

    public function testNestedObjectInArray(): void
    {
        $this->assertBothBackends('
            var arr = [1, {x: 10, y: 20}];
            var [a, {x, y}] = arr;
            a + x + y;
        ', 31);
    }

    public function testNestedArrayInObject(): void
    {
        $this->assertBothBackends('
            var obj = {coords: [10, 20]};
            var {coords: [x, y]} = obj;
            x + y;
        ', 30);
    }

    public function testNestedWithDefaults(): void
    {
        $this->assertBothBackends('
            var obj = {user: {name: "Bob"}};
            var {user: {name, age = 25}} = obj;
            name + " " + age;
        ', 'Bob 25');
    }

    public function testDeeplyNested(): void
    {
        $this->assertBothBackends('
            var data = {a: {b: {c: 42}}};
            var {a: {b: {c}}} = data;
            c;
        ', 42);
    }
}

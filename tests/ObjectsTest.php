<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

class ObjectsTest extends ScriptLiteTestCase
{

    // ═══════════════════ Object Literals ═══════════════════

    public function testObjectLiteralEmpty(): void
    {
        $this->assertBothBackends('var o = ({}); typeof o', 'object');
    }

    public function testObjectLiteralSimple(): void
    {
        $this->assertBothBackends('
            var o = ({test: 123, name: "hello"});
            o;
        ', ['test' => 123, 'name' => 'hello']);
    }

    public function testObjectPropertyAccessDot(): void
    {
        $this->assertBothBackends('
            var obj = ({x: 42, y: "world"});
            obj.x;
        ', 42);
    }

    public function testObjectPropertyAccessBracket(): void
    {
        $this->assertBothBackends('
            var obj = ({name: "Alice"});
            obj["name"];
        ', 'Alice');
    }

    public function testObjectPropertyAccessDynamic(): void
    {
        $this->assertBothBackends('
            var obj = ({a: 1, b: 2, c: 3});
            var key = "b";
            obj[key];
        ', 2);
    }

    public function testObjectPropertyAssignment(): void
    {
        $this->assertBothBackends('
            var obj = ({x: 1});
            obj.x = 10;
            obj.y = 20;
            obj.x + obj.y;
        ', 30);
    }

    public function testObjectPropertyAssignmentBracket(): void
    {
        $this->assertBothBackends('
            var obj = ({});
            obj["key"] = "value";
            obj["key"];
        ', 'value');
    }

    public function testObjectCompoundAssignment(): void
    {
        $this->assertBothBackends('
            var obj = ({count: 10});
            obj.count += 5;
            obj.count;
        ', 15);
    }

    public function testObjectNestedAccess(): void
    {
        $this->assertBothBackends('
            var obj = ({inner: ({value: 42})});
            obj.inner.value;
        ', 42);
    }

    public function testObjectUndefinedProperty(): void
    {
        $this->assertBothBackends('
            var obj = ({a: 1});
            obj.b;
        ', null);
    }

    public function testObjectTypeof(): void
    {
        $this->assertBothBackends('typeof ({})', 'object');
        $this->assertBothBackends('typeof ({a: 1})', 'object');
    }

    public function testObjectStringCoercion(): void
    {
        $this->assertVm('"val: " + ({})', 'val: [object Object]');
    }

    public function testObjectStringKeys(): void
    {
        $this->assertBothBackends('
            var obj = ({"first-name": "John", "last-name": "Doe"});
            obj["first-name"] + " " + obj["last-name"];
        ', 'John Doe');
    }

    public function testObjectTrailingComma(): void
    {
        $this->assertBothBackends('
            var obj = ({a: 1, b: 2,});
            obj.a + obj.b;
        ', 3);
    }

    public function testObjectPassedToFunction(): void
    {
        $this->assertBothBackends('
            function getFullName(person) {
                return person.first + " " + person.last;
            }
            getFullName(({first: "Jane", last: "Smith"}));
        ', 'Jane Smith');
    }

    public function testObjectMutationInFunction(): void
    {
        $this->assertBothBackends('
            function setAge(person, age) {
                person.age = age;
            }
            var p = ({name: "Bob"});
            setAge(p, 30);
            p.age;
        ', 30);
    }

    public function testObjectInArray(): void
    {
        $this->assertBothBackends('
            var items = [({id: 1, name: "a"}), ({id: 2, name: "b"})];
            items[1].name;
        ', 'b');
    }

    public function testObjectWithArrayValue(): void
    {
        $this->assertBothBackends('
            var obj = ({tags: [1, 2, 3]});
            obj.tags.length;
        ', 3);
    }

    public function testObjectHasOwnProperty(): void
    {
        $this->assertBothBackends('
            var obj = ({a: 1, b: 2});
            var has = obj.hasOwnProperty("a");
            has;
        ', true);
    }

    public function testObjectHasOwnPropertyFalse(): void
    {
        $this->assertBothBackends('
            var obj = ({x: 10});
            obj.hasOwnProperty("y");
        ', false);
    }

    public function testObjectWithFunctionValue(): void
    {
        $this->assertBothBackends('
            var obj = ({
                value: 42,
                getValue: function() { return 42; }
            });
            obj.getValue();
        ', 42);
    }

    public function testObjectVarInitializer(): void
    {
        // Object in var initializer (no parens needed since it's expression context)
        $this->assertBothBackends('
            var config = {host: "localhost", port: 8080};
            config.host + ":" + config.port;
        ', 'localhost:8080');
    }

    public function testObjectAsReturnValue(): void
    {
        $this->assertBothBackends('
            function makePoint(x, y) {
                return {x: x, y: y};
            }
            var p = makePoint(3, 4);
            p.x + p.y;
        ', 7);
    }

    public function testObjectDuplicateKeyLastWins(): void
    {
        $this->assertBothBackends('
            var obj = ({a: 1, a: 2});
            obj.a;
        ', 2);
    }

    // ═══════════════════ Object Static Methods ═══════════════════

    public function testObjectKeys(): void
    {
        $this->assertBothBackends('
            var obj = {a: 1, b: 2, c: 3};
            Object.keys(obj);
        ', ['a', 'b', 'c']);
    }

    public function testObjectValues(): void
    {
        $this->assertBothBackends('
            var obj = {x: 10, y: 20};
            Object.values(obj);
        ', [10, 20]);
    }

    public function testObjectEntries(): void
    {
        $this->assertBothBackends('
            var obj = {name: "Alice", age: 30};
            Object.entries(obj);
        ', [['name', 'Alice'], ['age', 30]]);
    }

    public function testObjectAssign(): void
    {
        $this->assertVm('
            var target = {a: 1};
            var source = {b: 2, c: 3};
            Object.assign(target, source);
            target;
        ', ['a' => 1, 'b' => 2, 'c' => 3]);
    }

    public function testObjectAssignOverwrite(): void
    {
        $this->assertVm('
            var target = {a: 1, b: 2};
            Object.assign(target, {b: 99, c: 3});
            target;
        ', ['a' => 1, 'b' => 99, 'c' => 3]);
    }

    public function testObjectAssignMultipleSources(): void
    {
        $this->assertVm('
            var obj = {};
            Object.assign(obj, {a: 1}, {b: 2});
            obj;
        ', ['a' => 1, 'b' => 2]);
    }

    public function testObjectKeysWithForEach(): void
    {
        $this->assertBothBackends('
            var obj = {x: 10, y: 20, z: 30};
            var sum = 0;
            Object.keys(obj).forEach(function(key) {
                sum = sum + obj[key];
            });
            sum;
        ', 60);
    }

    public function testObjectKeysEmpty(): void
    {
        $this->assertBothBackends('Object.keys({})', []);
    }

    public function testObjectIs(): void
    {
        $this->assertBothBackends('Object.is(0/0, 0/0)', true);
        $this->assertBothBackends('Object.is({}, {})', false);
    }

    public function testObjectCreate(): void
    {
        $this->assertBothBackends('
            var proto = {greeting: "hi"};
            var obj = Object.create(proto);
            obj.greeting;
        ', 'hi');
    }

    public function testObjectFreezeReturnsSameObject(): void
    {
        $this->assertBothBackends('
            var obj = {a: 1};
            var frozen = Object.freeze(obj);
            frozen.a = 2;
            obj.a;
        ', 2);
    }

    public function testObjectStaticMethodAliases(): void
    {
        $this->assertBothBackends('
            var keys = Object.keys;
            keys({a: 1, b: 2});
        ', ['a', 'b']);

        $this->assertBothBackends('
            var make = Object.create;
            var obj = make({answer: 42});
            obj.answer;
        ', 42);

        $this->assertBothBackends('
            var same = Object.is;
            same(0/0, 0/0);
        ', true);

        $this->assertBothBackends('
            var freeze = Object.freeze;
            var obj = {value: 1};
            var frozen = freeze(obj);
            frozen.value = 2;
            obj.value;
        ', 2);
    }
}

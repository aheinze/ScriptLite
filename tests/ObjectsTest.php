<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

use ScriptLite\Engine;
use PHPUnit\Framework\TestCase;

final class ObjectsTest extends TestCase
{
    private Engine $engine;

    protected function setUp(): void
    {
        $this->engine = new Engine();
    }

    // ═══════════════════ Object Literals ═══════════════════

    public function testObjectLiteralEmpty(): void
    {
        $result = $this->engine->eval('var o = ({}); typeof o');
        self::assertSame('object', $result);
    }

    public function testObjectLiteralSimple(): void
    {
        $result = $this->engine->eval('
            var o = ({test: 123, name: "hello"});
            o;
        ');
        self::assertSame(['test' => 123, 'name' => 'hello'], $result);
    }

    public function testObjectPropertyAccessDot(): void
    {
        $result = $this->engine->eval('
            var obj = ({x: 42, y: "world"});
            obj.x;
        ');
        self::assertSame(42, $result);
    }

    public function testObjectPropertyAccessBracket(): void
    {
        $result = $this->engine->eval('
            var obj = ({name: "Alice"});
            obj["name"];
        ');
        self::assertSame('Alice', $result);
    }

    public function testObjectPropertyAccessDynamic(): void
    {
        $result = $this->engine->eval('
            var obj = ({a: 1, b: 2, c: 3});
            var key = "b";
            obj[key];
        ');
        self::assertSame(2, $result);
    }

    public function testObjectPropertyAssignment(): void
    {
        $result = $this->engine->eval('
            var obj = ({x: 1});
            obj.x = 10;
            obj.y = 20;
            obj.x + obj.y;
        ');
        self::assertSame(30, $result);
    }

    public function testObjectPropertyAssignmentBracket(): void
    {
        $result = $this->engine->eval('
            var obj = ({});
            obj["key"] = "value";
            obj["key"];
        ');
        self::assertSame('value', $result);
    }

    public function testObjectCompoundAssignment(): void
    {
        $result = $this->engine->eval('
            var obj = ({count: 10});
            obj.count += 5;
            obj.count;
        ');
        self::assertSame(15, $result);
    }

    public function testObjectNestedAccess(): void
    {
        $result = $this->engine->eval('
            var obj = ({inner: ({value: 42})});
            obj.inner.value;
        ');
        self::assertSame(42, $result);
    }

    public function testObjectUndefinedProperty(): void
    {
        $result = $this->engine->eval('
            var obj = ({a: 1});
            obj.b;
        ');
        self::assertNull($result); // undefined → null via toPhp
    }

    public function testObjectTypeof(): void
    {
        self::assertSame('object', $this->engine->eval('typeof ({})'));
        self::assertSame('object', $this->engine->eval('typeof ({a: 1})'));
    }

    public function testObjectStringCoercion(): void
    {
        $result = $this->engine->eval('"val: " + ({})');
        self::assertSame('val: [object Object]', $result);
    }

    public function testObjectStringKeys(): void
    {
        $result = $this->engine->eval('
            var obj = ({"first-name": "John", "last-name": "Doe"});
            obj["first-name"] + " " + obj["last-name"];
        ');
        self::assertSame('John Doe', $result);
    }

    public function testObjectTrailingComma(): void
    {
        $result = $this->engine->eval('
            var obj = ({a: 1, b: 2,});
            obj.a + obj.b;
        ');
        self::assertSame(3, $result);
    }

    public function testObjectPassedToFunction(): void
    {
        $result = $this->engine->eval('
            function getFullName(person) {
                return person.first + " " + person.last;
            }
            getFullName(({first: "Jane", last: "Smith"}));
        ');
        self::assertSame('Jane Smith', $result);
    }

    public function testObjectMutationInFunction(): void
    {
        $result = $this->engine->eval('
            function setAge(person, age) {
                person.age = age;
            }
            var p = ({name: "Bob"});
            setAge(p, 30);
            p.age;
        ');
        self::assertSame(30, $result);
    }

    public function testObjectInArray(): void
    {
        $result = $this->engine->eval('
            var items = [({id: 1, name: "a"}), ({id: 2, name: "b"})];
            items[1].name;
        ');
        self::assertSame('b', $result);
    }

    public function testObjectWithArrayValue(): void
    {
        $result = $this->engine->eval('
            var obj = ({tags: [1, 2, 3]});
            obj.tags.length;
        ');
        self::assertSame(3, $result);
    }

    public function testObjectHasOwnProperty(): void
    {
        $this->engine->eval('
            var obj = ({a: 1, b: 2});
            var r1 = obj.hasOwnProperty("a");
            var r2 = obj.hasOwnProperty("c");
        ');
        // hasOwnProperty returns bool, test via console output
        $result = $this->engine->eval('
            var obj = ({a: 1, b: 2});
            var has = obj.hasOwnProperty("a");
            has;
        ');
        self::assertTrue($result);
    }

    public function testObjectHasOwnPropertyFalse(): void
    {
        $result = $this->engine->eval('
            var obj = ({x: 10});
            obj.hasOwnProperty("y");
        ');
        self::assertFalse($result);
    }

    public function testObjectWithFunctionValue(): void
    {
        $result = $this->engine->eval('
            var obj = ({
                value: 42,
                getValue: function() { return 42; }
            });
            obj.getValue();
        ');
        self::assertSame(42, $result);
    }

    public function testObjectVarInitializer(): void
    {
        // Object in var initializer (no parens needed since it's expression context)
        $result = $this->engine->eval('
            var config = {host: "localhost", port: 8080};
            config.host + ":" + config.port;
        ');
        self::assertSame('localhost:8080', $result);
    }

    public function testObjectAsReturnValue(): void
    {
        $result = $this->engine->eval('
            function makePoint(x, y) {
                return {x: x, y: y};
            }
            var p = makePoint(3, 4);
            p.x + p.y;
        ');
        self::assertSame(7, $result);
    }

    public function testObjectDuplicateKeyLastWins(): void
    {
        $result = $this->engine->eval('
            var obj = ({a: 1, a: 2});
            obj.a;
        ');
        self::assertSame(2, $result);
    }

    // ═══════════════════ Object Static Methods ═══════════════════

    public function testObjectKeys(): void
    {
        $result = $this->engine->eval('
            var obj = {a: 1, b: 2, c: 3};
            Object.keys(obj);
        ');
        self::assertSame(['a', 'b', 'c'], $result);
    }

    public function testObjectValues(): void
    {
        $result = $this->engine->eval('
            var obj = {x: 10, y: 20};
            Object.values(obj);
        ');
        self::assertSame([10, 20], $result);
    }

    public function testObjectEntries(): void
    {
        $result = $this->engine->eval('
            var obj = {name: "Alice", age: 30};
            Object.entries(obj);
        ');
        self::assertSame([['name', 'Alice'], ['age', 30]], $result);
    }

    public function testObjectAssign(): void
    {
        $result = $this->engine->eval('
            var target = {a: 1};
            var source = {b: 2, c: 3};
            Object.assign(target, source);
            target;
        ');
        self::assertSame(['a' => 1, 'b' => 2, 'c' => 3], $result);
    }

    public function testObjectAssignOverwrite(): void
    {
        $result = $this->engine->eval('
            var target = {a: 1, b: 2};
            Object.assign(target, {b: 99, c: 3});
            target;
        ');
        self::assertSame(['a' => 1, 'b' => 99, 'c' => 3], $result);
    }

    public function testObjectAssignMultipleSources(): void
    {
        $result = $this->engine->eval('
            var obj = {};
            Object.assign(obj, {a: 1}, {b: 2});
            obj;
        ');
        self::assertSame(['a' => 1, 'b' => 2], $result);
    }

    public function testObjectKeysWithForEach(): void
    {
        $result = $this->engine->eval('
            var obj = {x: 10, y: 20, z: 30};
            var sum = 0;
            Object.keys(obj).forEach(function(key) {
                sum = sum + obj[key];
            });
            sum;
        ');
        self::assertSame(60, $result);
    }

    public function testObjectKeysEmpty(): void
    {
        $result = $this->engine->eval('Object.keys({})');
        self::assertSame([], $result);
    }
}

<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

class JsonTest extends ScriptLiteTestCase
{

    public function testStringifyNumber(): void
    {
        $this->assertBothBackends('JSON.stringify(42);', '42');
    }

    public function testStringifyString(): void
    {
        $this->assertBothBackends('JSON.stringify("hello");', '"hello"');
    }

    public function testStringifyArray(): void
    {
        $this->assertBothBackends('JSON.stringify([1, 2, 3]);', '[1,2,3]');
    }

    public function testStringifyObject(): void
    {
        $this->assertBothBackends('JSON.stringify({a: 1, b: 2});', '{"a":1,"b":2}');
    }

    public function testStringifyNull(): void
    {
        $this->assertBothBackends('JSON.stringify(null);', 'null');
    }

    public function testStringifyBoolean(): void
    {
        $this->assertBothBackends('JSON.stringify(true);', 'true');
    }

    public function testStringifyNested(): void
    {
        $this->assertBothBackends('JSON.stringify({arr: [1, 2], nested: {x: true}});', '{"arr":[1,2],"nested":{"x":true}}');
    }

    public function testParseNumber(): void
    {
        $this->assertBothBackends('JSON.parse("42");', 42);
    }

    public function testParseString(): void
    {
        $this->assertBothBackends("JSON.parse('\"hello\"');", 'hello');
    }

    public function testParseArray(): void
    {
        $this->assertBothBackends('
            var arr = JSON.parse("[1, 2, 3]");
            arr[0] + arr[1] + arr[2];
        ', 6);
    }

    public function testParseObject(): void
    {
        $this->assertBothBackends('
            var obj = JSON.parse(\'{"name": "Alice", "age": 30}\');
            obj.name + " is " + obj.age;
        ', 'Alice is 30');
    }

    public function testRoundTrip(): void
    {
        $this->assertBothBackends('
            var data = {items: [1, 2, 3], active: true};
            var json = JSON.stringify(data);
            var parsed = JSON.parse(json);
            parsed.items[1] + (parsed.active ? 10 : 0);
        ', 12);
    }
}

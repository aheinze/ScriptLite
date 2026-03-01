<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

use ScriptLite\Engine;
use PHPUnit\Framework\TestCase;

final class ConstructorsTest extends TestCase
{
    private Engine $engine;

    protected function setUp(): void
    {
        $this->engine = new Engine();
    }

    // ═══════════════════ new, this, and Constructors ═══════════════════

    public function testNewWithConstructorFunction(): void
    {
        $result = $this->engine->eval('
            function Person(name, age) {
                this.name = name;
                this.age = age;
            }
            var p = new Person("Alice", 30);
            p.name;
        ');
        self::assertSame('Alice', $result);
    }

    public function testNewReturnsObject(): void
    {
        $result = $this->engine->eval('
            function Point(x, y) {
                this.x = x;
                this.y = y;
            }
            var p = new Point(3, 4);
            p.x + p.y;
        ');
        self::assertSame(7, $result);
    }

    public function testNewTypeofObject(): void
    {
        $result = $this->engine->eval('
            function Foo() {
                this.val = 1;
            }
            typeof new Foo();
        ');
        self::assertSame('object', $result);
    }

    public function testNewConstructorReturnsThisImplicitly(): void
    {
        $result = $this->engine->eval('
            function Box(w, h) {
                this.width = w;
                this.height = h;
            }
            var b = new Box(10, 20);
            b.width * b.height;
        ');
        self::assertSame(200, $result);
    }

    public function testNewConstructorExplicitReturnPrimitive(): void
    {
        // If constructor returns a primitive, `new` should still return `this`
        $result = $this->engine->eval('
            function Thing(v) {
                this.val = v;
                return 42;
            }
            var t = new Thing(99);
            t.val;
        ');
        self::assertSame(99, $result);
    }

    public function testNewConstructorExplicitReturnObject(): void
    {
        // If constructor returns an object, `new` should return that object
        $result = $this->engine->eval('
            function Maker() {
                this.a = 1;
                return {b: 2};
            }
            var m = new Maker();
            m.b;
        ');
        self::assertSame(2, $result);
    }

    public function testNewWithoutArguments(): void
    {
        $result = $this->engine->eval('
            function Counter() {
                this.count = 0;
            }
            var c = new Counter;
            c.count;
        ');
        self::assertSame(0, $result);
    }

    public function testNewMultipleInstances(): void
    {
        $result = $this->engine->eval('
            function Dog(name) {
                this.name = name;
            }
            var a = new Dog("Rex");
            var b = new Dog("Max");
            a.name + " " + b.name;
        ');
        self::assertSame('Rex Max', $result);
    }

    // ═══════════════════ Date Object ═══════════════════

    public function testDateNow(): void
    {
        $result = $this->engine->eval('Date.now()');
        self::assertIsNumeric($result);
        // Should be close to current time in ms
        self::assertEqualsWithDelta(microtime(true) * 1000, $result, 1000);
    }

    public function testNewDateNoArgs(): void
    {
        $result = $this->engine->eval('
            var d = new Date();
            typeof d;
        ');
        self::assertSame('object', $result);
    }

    public function testNewDateGetTime(): void
    {
        $result = $this->engine->eval('
            var d = new Date();
            var t = d.getTime();
            typeof t;
        ');
        self::assertSame('number', $result);
    }

    public function testNewDateFromTimestamp(): void
    {
        $result = $this->engine->eval('
            var d = new Date(0);
            d.getTime();
        ');
        self::assertSame(0, $result);
    }

    public function testDateGetFullYear(): void
    {
        $result = $this->engine->eval('
            var d = new Date(1704067200000);
            d.getFullYear();
        ');
        // 1704067200000 = 2024-01-01T00:00:00Z
        self::assertSame(2024, $result);
    }

    public function testDateGetMonth(): void
    {
        $result = $this->engine->eval('
            var d = new Date(1704067200000);
            d.getMonth();
        ');
        // January = 0
        self::assertSame(0, $result);
    }

    public function testDateGetDate(): void
    {
        $result = $this->engine->eval('
            var d = new Date(1704067200000);
            d.getDate();
        ');
        self::assertSame(1, $result);
    }

    public function testDateGetDay(): void
    {
        $result = $this->engine->eval('
            var d = new Date(1704067200000);
            d.getDay();
        ');
        // 2024-01-01 is a Monday = 1
        self::assertSame(1, $result);
    }

    public function testDateGetHoursMinutesSeconds(): void
    {
        // 1704070800000 = 2024-01-01T01:00:00Z
        $result = $this->engine->eval('
            var d = new Date(1704070800000);
            d.getHours() + ":" + d.getMinutes() + ":" + d.getSeconds();
        ');
        self::assertSame('1:0:0', $result);
    }

    public function testDateGetMilliseconds(): void
    {
        $result = $this->engine->eval('
            var d = new Date(1704067200123);
            d.getMilliseconds();
        ');
        self::assertSame(123, $result);
    }

    public function testDateToISOString(): void
    {
        $result = $this->engine->eval('
            var d = new Date(1704067200000);
            d.toISOString();
        ');
        self::assertSame('2024-01-01T00:00:00.000Z', $result);
    }

    public function testDateToISOStringWithMs(): void
    {
        $result = $this->engine->eval('
            var d = new Date(1704067200456);
            d.toISOString();
        ');
        self::assertSame('2024-01-01T00:00:00.456Z', $result);
    }

    public function testDateValueOf(): void
    {
        $result = $this->engine->eval('
            var d = new Date(1704067200000);
            d.valueOf();
        ');
        self::assertSame(1704067200000, $result);
    }

    public function testDateSetTime(): void
    {
        $result = $this->engine->eval('
            var d = new Date(0);
            d.setTime(1704067200000);
            d.getFullYear();
        ');
        self::assertSame(2024, $result);
    }

    public function testDateFromYearMonth(): void
    {
        $result = $this->engine->eval('
            var d = new Date(2024, 0, 15);
            d.getFullYear() + "-" + d.getMonth() + "-" + d.getDate();
        ');
        self::assertSame('2024-0-15', $result);
    }

    public function testDateParse(): void
    {
        $result = $this->engine->eval('Date.parse("2024-01-01")');
        self::assertIsNumeric($result);
        // Should be a valid timestamp in ms
        self::assertTrue($result > 0);
    }

    public function testDateToString(): void
    {
        $result = $this->engine->eval('
            var d = new Date(1704067200000);
            d.toString();
        ');
        self::assertStringContainsString('2024', $result);
        self::assertStringContainsString('GMT', $result);
    }

    public function testDateToLocaleDateString(): void
    {
        $result = $this->engine->eval('
            var d = new Date(1704067200000);
            d.toLocaleDateString();
        ');
        // 2024-01-01 → "1/1/2024"
        self::assertSame('1/1/2024', $result);
    }

    public function testDateStringCoercion(): void
    {
        $result = $this->engine->eval('
            var d = new Date(1704067200000);
            "Date: " + d;
        ');
        self::assertStringContainsString('2024', $result);
    }

    public function testDateMethodChaining(): void
    {
        // Creating date and calling method in one expression
        $result = $this->engine->eval('new Date(0).getTime()');
        self::assertSame(0, $result);
    }
}

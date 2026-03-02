<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

class ConstructorsTest extends ScriptLiteTestCase
{

    // ═══════════════════ new, this, and Constructors ═══════════════════

    public function testNewWithConstructorFunction(): void
    {
        $this->assertBothBackends('
            function Person(name, age) {
                this.name = name;
                this.age = age;
            }
            var p = new Person("Alice", 30);
            p.name;
        ', 'Alice');
    }

    public function testNewReturnsObject(): void
    {
        $this->assertBothBackends('
            function Point(x, y) {
                this.x = x;
                this.y = y;
            }
            var p = new Point(3, 4);
            p.x + p.y;
        ', 7);
    }

    public function testNewTypeofObject(): void
    {
        $this->assertBothBackends('
            function Foo() {
                this.val = 1;
            }
            typeof new Foo();
        ', 'object');
    }

    public function testNewConstructorReturnsThisImplicitly(): void
    {
        $this->assertBothBackends('
            function Box(w, h) {
                this.width = w;
                this.height = h;
            }
            var b = new Box(10, 20);
            b.width * b.height;
        ', 200);
    }

    public function testNewConstructorExplicitReturnPrimitive(): void
    {
        // If constructor returns a primitive, `new` should still return `this`
        $this->assertBothBackends('
            function Thing(v) {
                this.val = v;
                return 42;
            }
            var t = new Thing(99);
            t.val;
        ', 99);
    }

    public function testNewConstructorExplicitReturnObject(): void
    {
        // If constructor returns an object, `new` should return that object
        $this->assertBothBackends('
            function Maker() {
                this.a = 1;
                return {b: 2};
            }
            var m = new Maker();
            m.b;
        ', 2);
    }

    public function testNewWithoutArguments(): void
    {
        $this->assertBothBackends('
            function Counter() {
                this.count = 0;
            }
            var c = new Counter;
            c.count;
        ', 0);
    }

    public function testNewMultipleInstances(): void
    {
        $this->assertBothBackends('
            function Dog(name) {
                this.name = name;
            }
            var a = new Dog("Rex");
            var b = new Dog("Max");
            a.name + " " + b.name;
        ', 'Rex Max');
    }

    public function testPrototypeMethodLookupOnConstructedObject(): void
    {
        self::assertSame('woof', $this->engine->transpileAndEval('
            function Dog() {}
            Dog.prototype.bark = function() {
                return "woof";
            };
            var d = new Dog();
            d.bark();
        '));
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
        $this->assertBothBackends('
            var d = new Date();
            typeof d;
        ', 'object');
    }

    public function testNewDateGetTime(): void
    {
        $this->assertBothBackends('
            var d = new Date();
            var t = d.getTime();
            typeof t;
        ', 'number');
    }

    public function testNewDateFromTimestamp(): void
    {
        $this->assertBothBackends('
            var d = new Date(0);
            d.getTime();
        ', 0);
    }

    public function testDateGetFullYear(): void
    {
        $this->assertBothBackends('
            var d = new Date(1704067200000);
            d.getFullYear();
        ', 2024);
    }

    public function testDateGetMonth(): void
    {
        $this->assertBothBackends('
            var d = new Date(1704067200000);
            d.getMonth();
        ', 0);
    }

    public function testDateGetDate(): void
    {
        $this->assertBothBackends('
            var d = new Date(1704067200000);
            d.getDate();
        ', 1);
    }

    public function testDateGetDay(): void
    {
        $this->assertBothBackends('
            var d = new Date(1704067200000);
            d.getDay();
        ', 1);
    }

    public function testDateGetHoursMinutesSeconds(): void
    {
        // 1704070800000 = 2024-01-01T01:00:00Z
        $this->assertBothBackends('
            var d = new Date(1704070800000);
            d.getHours() + ":" + d.getMinutes() + ":" + d.getSeconds();
        ', '1:0:0');
    }

    public function testDateGetMilliseconds(): void
    {
        $this->assertBothBackends('
            var d = new Date(1704067200123);
            d.getMilliseconds();
        ', 123);
    }

    public function testDateToISOString(): void
    {
        $this->assertBothBackends('
            var d = new Date(1704067200000);
            d.toISOString();
        ', '2024-01-01T00:00:00.000Z');
    }

    public function testDateToISOStringWithMs(): void
    {
        $this->assertBothBackends('
            var d = new Date(1704067200456);
            d.toISOString();
        ', '2024-01-01T00:00:00.456Z');
    }

    public function testDateValueOf(): void
    {
        $this->assertBothBackends('
            var d = new Date(1704067200000);
            d.valueOf();
        ', 1704067200000);
    }

    public function testDateSetTime(): void
    {
        $this->assertBothBackends('
            var d = new Date(0);
            d.setTime(1704067200000);
            d.getFullYear();
        ', 2024);
    }

    public function testDateFromYearMonth(): void
    {
        $this->assertBothBackends('
            var d = new Date(2024, 0, 15);
            d.getFullYear() + "-" + d.getMonth() + "-" + d.getDate();
        ', '2024-0-15');
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
        $this->assertBothBackends('
            var d = new Date(1704067200000);
            d.toLocaleDateString();
        ', '1/1/2024');
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
        $this->assertBothBackends('new Date(0).getTime()', 0);
    }
}

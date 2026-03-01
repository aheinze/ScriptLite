<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

use ScriptLite\Engine;
use ScriptLite\Vm\VmException;
use PHPUnit\Framework\TestCase;

final class StringsAndEdgeCasesTest extends TestCase
{
    private Engine $engine;

    protected function setUp(): void
    {
        $this->engine = new Engine();
    }

    // ═══════════════════ String Operations ═══════════════════

    public function testStringConcatenation(): void
    {
        self::assertSame('hello world', $this->engine->eval('"hello" + " " + "world"'));
    }

    public function testStringNumberCoercion(): void
    {
        self::assertSame('Number: 42', $this->engine->eval('"Number: " + 42'));
    }

    // ═══════════════════ Edge Cases ═══════════════════

    public function testBooleanLogic(): void
    {
        self::assertTrue($this->engine->eval('true'));
        self::assertFalse($this->engine->eval('false'));
        self::assertTrue($this->engine->eval('!false'));
        self::assertFalse($this->engine->eval('!true'));
    }

    public function testComparison(): void
    {
        self::assertTrue($this->engine->eval('1 < 2'));
        self::assertFalse($this->engine->eval('2 < 1'));
        self::assertTrue($this->engine->eval('2 >= 2'));
        self::assertTrue($this->engine->eval('3 > 2'));
    }

    public function testLogicalOperators(): void
    {
        // Short-circuit AND: first falsy wins
        self::assertSame(0, $this->engine->eval('0 && 5'));

        // Short-circuit OR: first truthy wins
        self::assertSame(5, $this->engine->eval('0 || 5'));

        // Truthy AND returns right
        self::assertSame(5, $this->engine->eval('1 && 5'));
    }

    public function testFunctionHoisting(): void
    {
        // Function declarations should be hoisted (callable before declaration in source)
        $result = $this->engine->eval('
            var result = greet();
            function greet() { return 42; }
            result;
        ');
        self::assertSame(42, $result);
    }

    public function testConsoleLog(): void
    {
        $this->engine->eval('console_log("hello", 42, true)');
        self::assertSame("hello 42 true\n", $this->engine->getOutput());
    }

    public function testDeepRecursionDoesNotCrash(): void
    {
        // Test that we can handle moderate recursion depth
        $result = $this->engine->eval('
            function countdown(n) {
                if (n <= 0) { return 0; }
                return countdown(n - 1);
            }
            countdown(200);
        ');
        self::assertSame(0, $result);
    }

    public function testStackOverflowDetection(): void
    {
        $this->expectException(VmException::class);
        $this->expectExceptionMessage('Maximum call stack');
        $this->engine->eval('
            function infinite() { return infinite(); }
            infinite();
        ');
    }

    public function testUndefinedVariableThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is not defined');
        $this->engine->eval('nonExistent');
    }

    public function testCompiledScriptReuse(): void
    {
        $compiled = $this->engine->compile('1 + 2 + 3');
        self::assertSame(6, $this->engine->run($compiled));
        self::assertSame(6, $this->engine->run($compiled)); // reuse
    }

    public function testComplexExpressionPrecedence(): void
    {
        // 2 + 3 * 4 ** ... complex chain
        self::assertSame(5, $this->engine->eval('10 / 2'));
        self::assertSame(7, $this->engine->eval('1 + 2 * 3'));
        self::assertSame(10, $this->engine->eval('(1 + 2) * 3 + 1'));
    }

    public function testNestedFunctionCalls(): void
    {
        $result = $this->engine->eval('
            function double(x) { return x * 2; }
            function addOne(x) { return x + 1; }
            addOne(double(addOne(double(3))));
        ');
        // double(3)=6, addOne(6)=7, double(7)=14, addOne(14)=15
        self::assertSame(15, $result);
    }

    public function testMultipleStatements(): void
    {
        $result = $this->engine->eval('
            var a = 1;
            var b = 2;
            var c = 3;
            a + b + c;
        ');
        self::assertSame(6, $result);
    }
}

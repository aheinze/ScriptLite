<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

use ScriptLite\Vm\VmException;

class StringsAndEdgeCasesTest extends ScriptLiteTestCase
{

    // ═══════════════════ String Operations ═══════════════════

    public function testStringConcatenation(): void
    {
        $this->assertBothBackends('"hello" + " " + "world"', 'hello world');
    }

    public function testStringNumberCoercion(): void
    {
        $this->assertBothBackends('"Number: " + 42', 'Number: 42');
    }

    // ═══════════════════ Edge Cases ═══════════════════

    public function testBooleanLogic(): void
    {
        $this->assertBothBackends('true', true);
        $this->assertBothBackends('false', false);
        $this->assertBothBackends('!false', true);
        $this->assertBothBackends('!true', false);
    }

    public function testComparison(): void
    {
        $this->assertBothBackends('1 < 2', true);
        $this->assertBothBackends('2 < 1', false);
        $this->assertBothBackends('2 >= 2', true);
        $this->assertBothBackends('3 > 2', true);
    }

    public function testLogicalOperators(): void
    {
        // Short-circuit AND: first falsy wins
        $this->assertBothBackends('0 && 5', 0);

        // Short-circuit OR: first truthy wins
        $this->assertBothBackends('0 || 5', 5);

        // Truthy AND returns right
        $this->assertBothBackends('1 && 5', 5);
    }

    public function testFunctionHoisting(): void
    {
        // Function declarations should be hoisted (callable before declaration in source)
        $this->assertBothBackends('
            var result = greet();
            function greet() { return 42; }
            result;
        ', 42);
    }

    public function testConsoleLog(): void
    {
        $this->engine->eval('console_log("hello", 42, true)');
        self::assertSame("hello 42 true\n", $this->engine->getOutput());
    }

    public function testDeepRecursionDoesNotCrash(): void
    {
        // Test that we can handle moderate recursion depth
        $this->assertBothBackends('
            function countdown(n) {
                if (n <= 0) { return 0; }
                return countdown(n - 1);
            }
            countdown(200);
        ', 0);
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
        $this->assertBothBackends('10 / 2', 5);
        $this->assertBothBackends('1 + 2 * 3', 7);
        $this->assertBothBackends('(1 + 2) * 3 + 1', 10);
    }

    public function testNestedFunctionCalls(): void
    {
        $this->assertBothBackends('
            function double(x) { return x * 2; }
            function addOne(x) { return x + 1; }
            addOne(double(addOne(double(3))));
        ', 15);
    }

    public function testMultipleStatements(): void
    {
        $this->assertBothBackends('
            var a = 1;
            var b = 2;
            var c = 3;
            a + b + c;
        ', 6);
    }
}

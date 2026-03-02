<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

class TryCatchTest extends ScriptLiteTestCase
{

    // ═══════════════════ Basic throw/catch ═══════════════════

    public function testThrowStringCatch(): void
    {
        $code = '
            var result = "";
            try {
                throw "boom";
            } catch (e) {
                result = e;
            }
            result
        ';
        $this->assertBothBackends($code, 'boom');
    }

    public function testThrowNumberCatch(): void
    {
        $code = '
            var result = 0;
            try {
                throw 42;
            } catch (e) {
                result = e;
            }
            result
        ';
        $this->assertBothBackends($code, 42);
    }

    public function testThrowBooleanCatch(): void
    {
        $code = '
            var result = "";
            try {
                throw true;
            } catch (e) {
                result = e;
            }
            result
        ';
        $this->assertBothBackends($code, true);
    }

    // ═══════════════════ Catch parameter binding ═══════════════════

    public function testCatchBindsExceptionToParam(): void
    {
        $code = '
            var msg = "";
            try {
                throw "error message";
            } catch (err) {
                msg = err;
            }
            msg
        ';
        $this->assertBothBackends($code, 'error message');
    }

    // ═══════════════════ Code after try/catch runs ═══════════════════

    public function testCodeAfterTryCatchRuns(): void
    {
        $code = '
            var result = "before";
            try {
                throw "err";
            } catch (e) {
                result = "caught";
            }
            result = result + " after";
            result
        ';
        $this->assertBothBackends($code, 'caught after');
    }

    // ═══════════════════ No throw → catch skipped ═══════════════════

    public function testNoThrowCatchSkipped(): void
    {
        $code = '
            var result = "try";
            try {
                result = result + " body";
            } catch (e) {
                result = "caught";
            }
            result
        ';
        $this->assertBothBackends($code, 'try body');
    }

    // ═══════════════════ Cross-frame unwinding ═══════════════════

    public function testThrowInsideFunctionCaughtByOuter(): void
    {
        $code = '
            function boom() {
                throw "from function";
            }
            var result = "";
            try {
                boom();
            } catch (e) {
                result = e;
            }
            result
        ';
        $this->assertBothBackends($code, 'from function');
    }

    public function testThrowInsideNestedFunctionCalls(): void
    {
        $code = '
            function inner() { throw "deep"; }
            function outer() { inner(); }
            var result = "";
            try {
                outer();
            } catch (e) {
                result = e;
            }
            result
        ';
        $this->assertBothBackends($code, 'deep');
    }

    // ═══════════════════ Nested try/catch ═══════════════════

    public function testNestedTryCatchInnerHandles(): void
    {
        $code = '
            var result = "";
            try {
                try {
                    throw "inner error";
                } catch (e) {
                    result = "inner: " + e;
                }
            } catch (e) {
                result = "outer: " + e;
            }
            result
        ';
        $this->assertBothBackends($code, 'inner: inner error');
    }

    public function testNestedTryCatchOuterHandles(): void
    {
        $code = '
            var result = "";
            try {
                try {
                    throw "inner error";
                } catch (e) {
                    throw "rethrown: " + e;
                }
            } catch (e) {
                result = e;
            }
            result
        ';
        $this->assertBothBackends($code, 'rethrown: inner error');
    }

    // ═══════════════════ Throw in catch (re-throw) ═══════════════════

    public function testReThrow(): void
    {
        $code = '
            var result = "";
            try {
                try {
                    throw "original";
                } catch (e) {
                    throw e;
                }
            } catch (e) {
                result = e;
            }
            result
        ';
        $this->assertBothBackends($code, 'original');
    }

    // ═══════════════════ Throw expression types ═══════════════════

    public function testThrowExpression(): void
    {
        $code = '
            var result = "";
            var msg = "computed";
            try {
                throw msg + " error";
            } catch (e) {
                result = e;
            }
            result
        ';
        $this->assertBothBackends($code, 'computed error');
    }

    // ═══════════════════ Stack state after catch ═══════════════════

    public function testStackCleanAfterCatch(): void
    {
        $code = '
            var a = 1;
            var b = 2;
            try {
                var c = a + b;
                throw "err";
            } catch (e) {
                // stack should be clean
            }
            a + b
        ';
        $this->assertBothBackends($code, 3);
    }

    // ═══════════════════ Uncaught throw → runtime error ═══════════════════

    public function testUncaughtThrowRaisesException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->engine->eval('throw "uncaught"');
    }
}

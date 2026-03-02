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

    // ═══════════════════ try...finally ═══════════════════

    public function testTryFinallyNoThrow(): void
    {
        $this->assertBothBackends('
            var result = "";
            try {
                result = "try";
            } finally {
                result = result + " finally";
            }
            result
        ', 'try finally');
    }

    public function testTryFinallyWithThrow(): void
    {
        $this->assertBothBackends('
            var result = "";
            try {
                try {
                    throw "boom";
                } finally {
                    result = "finally ran";
                }
            } catch (e) {}
            result
        ', 'finally ran');
    }

    public function testTryFinallyRethrows(): void
    {
        $this->assertBothBackends('
            var result = "";
            try {
                try {
                    throw "error";
                } finally {
                    result = "finally";
                }
            } catch (e) {
                result = result + " caught:" + e;
            }
            result
        ', 'finally caught:error');
    }

    public function testTryCatchFinally(): void
    {
        $this->assertBothBackends('
            var result = "";
            try {
                throw "err";
            } catch (e) {
                result = "caught:" + e;
            } finally {
                result = result + " finally";
            }
            result
        ', 'caught:err finally');
    }

    public function testTryCatchFinallyNoThrow(): void
    {
        $this->assertBothBackends('
            var result = "";
            try {
                result = "try";
            } catch (e) {
                result = "caught";
            } finally {
                result = result + " finally";
            }
            result
        ', 'try finally');
    }

    public function testFinallyRunsWhenCatchThrows(): void
    {
        $this->assertBothBackends('
            var result = "";
            try {
                try {
                    throw "first";
                } catch (e) {
                    throw "second";
                } finally {
                    result = "finally";
                }
            } catch (e) {
                result = result + " caught:" + e;
            }
            result
        ', 'finally caught:second');
    }

    public function testFinallyReturnValue(): void
    {
        $this->assertBothBackends('
            var x = 0;
            try {
                x = 1;
            } finally {
                x = x + 10;
            }
            x
        ', 11);
    }

    public function testNestedTryFinally(): void
    {
        $this->assertBothBackends('
            var log = "";
            try {
                try {
                    throw "err";
                } finally {
                    log = log + "inner ";
                }
            } catch (e) {
                log = log + "caught ";
            } finally {
                log = log + "outer";
            }
            log
        ', 'inner caught outer');
    }

    public function testFinallyWithFunctionThrow(): void
    {
        $this->assertBothBackends('
            var result = "";
            function boom() { throw "from fn"; }
            try {
                try {
                    boom();
                } finally {
                    result = "finally";
                }
            } catch (e) {
                result = result + " caught:" + e;
            }
            result
        ', 'finally caught:from fn');
    }

    // ═══════════════════ Optional catch binding ═══════════════════

    public function testOptionalCatchBinding(): void
    {
        $this->assertBothBackends('
            var result = "none";
            try {
                throw "err";
            } catch {
                result = "caught";
            }
            result
        ', 'caught');
    }

    public function testOptionalCatchBindingNoThrow(): void
    {
        $this->assertBothBackends('
            var result = "try";
            try {
                result = "ok";
            } catch {
                result = "caught";
            }
            result
        ', 'ok');
    }

    public function testOptionalCatchBindingWithFinally(): void
    {
        $this->assertBothBackends('
            var result = "";
            try {
                throw "err";
            } catch {
                result = "caught";
            } finally {
                result = result + " finally";
            }
            result
        ', 'caught finally');
    }
}

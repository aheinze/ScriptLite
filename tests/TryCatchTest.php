<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

use ScriptLite\Engine;
use PHPUnit\Framework\TestCase;

final class TryCatchTest extends TestCase
{
    private Engine $engine;

    protected function setUp(): void
    {
        $this->engine = new Engine();
    }

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
        self::assertSame('boom', $this->engine->eval($code));
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
        self::assertSame(42, $this->engine->eval($code));
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
        self::assertSame(true, $this->engine->eval($code));
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
        self::assertSame('error message', $this->engine->eval($code));
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
        self::assertSame('caught after', $this->engine->eval($code));
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
        self::assertSame('try body', $this->engine->eval($code));
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
        self::assertSame('from function', $this->engine->eval($code));
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
        self::assertSame('deep', $this->engine->eval($code));
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
        self::assertSame('inner: inner error', $this->engine->eval($code));
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
        self::assertSame('rethrown: inner error', $this->engine->eval($code));
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
        self::assertSame('original', $this->engine->eval($code));
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
        self::assertSame('computed error', $this->engine->eval($code));
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
        self::assertSame(3, $this->engine->eval($code));
    }

    // ═══════════════════ Uncaught throw → runtime error ═══════════════════

    public function testUncaughtThrowRaisesException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->engine->eval('throw "uncaught"');
    }

    // ═══════════════════ Transpiler path ═══════════════════

    public function testTranspilerBasicThrowCatch(): void
    {
        $php = $this->engine->transpile('
            var result = "";
            try {
                throw "boom";
            } catch (e) {
                result = e;
            }
            result
        ');
        self::assertSame('boom', $this->engine->evalTranspiled($php));
    }

    public function testTranspilerNoThrow(): void
    {
        $php = $this->engine->transpile('
            var result = "ok";
            try {
                result = result + " tried";
            } catch (e) {
                result = "caught";
            }
            result
        ');
        self::assertSame('ok tried', $this->engine->evalTranspiled($php));
    }

    public function testTranspilerThrowInsideFunction(): void
    {
        $php = $this->engine->transpile('
            function fail() { throw "fn error"; }
            var result = "";
            try {
                fail();
            } catch (e) {
                result = e;
            }
            result
        ');
        self::assertSame('fn error', $this->engine->evalTranspiled($php));
    }

    public function testTranspilerNestedRethrow(): void
    {
        $php = $this->engine->transpile('
            var result = "";
            try {
                try {
                    throw "inner";
                } catch (e) {
                    throw "re: " + e;
                }
            } catch (e) {
                result = e;
            }
            result
        ');
        self::assertSame('re: inner', $this->engine->evalTranspiled($php));
    }
}

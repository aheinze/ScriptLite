<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

use ScriptLite\Compiler\CompiledScript;
use ScriptLite\Engine;

final class EngineBackendUnificationTest extends ScriptLiteTestCase
{
    public function testCompileRunWorksForVmAndTranspiler(): void
    {
        $vmCompiled = $this->engine->compile('1 + 2 + 3', Engine::BACKEND_VM);
        self::assertInstanceOf(CompiledScript::class, $vmCompiled);
        self::assertSame(6, $this->engine->run($vmCompiled));

        $trCompiled = $this->engine->compile('1 + 2 + 3', Engine::BACKEND_TRANSPILER);
        self::assertIsString($trCompiled);
        self::assertSame(6, $this->engine->run($trCompiled));
    }

    public function testCompileRunTranspilerSupportsGlobalsInClosures(): void
    {
        $source = '
            var fn = function() { return acc + 1; };
            fn();
        ';

        $trCompiled = $this->engine->compile($source, Engine::BACKEND_TRANSPILER, ['acc' => 2]);
        self::assertSame(3, $this->engine->run($trCompiled, ['acc' => 2]));
    }

    public function testEvalSupportsExplicitTranspilerBackend(): void
    {
        $result = $this->engine->eval('x * 2', ['x' => 5], Engine::BACKEND_TRANSPILER);
        self::assertSame(10, $result);
    }

    public function testExplicitNativeBackendSelection(): void
    {
        if (!extension_loaded('scriptlite')) {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Native backend requested');
            $this->engine->eval('40 + 2', [], Engine::BACKEND_NATIVE);
            return;
        }

        $compiled = $this->engine->compile('40 + 2', Engine::BACKEND_NATIVE);
        self::assertInstanceOf(\ScriptLiteExt\CompiledScript::class, $compiled);
        self::assertSame(42, $this->engine->run($compiled));
    }

    public function testExplicitExtensionEngineSelectionWhenAvailable(): void
    {
        if (!extension_loaded('scriptlite') || !class_exists(\ScriptLiteExt\Engine::class, false)) {
            self::markTestSkipped('Requires ScriptLiteExt\\Engine.');
        }

        $engine = new Engine(true);
        $compiled = $engine->compile('40 + 2', Engine::BACKEND_NATIVE);
        self::assertInstanceOf(\ScriptLiteExt\CompiledScript::class, $compiled);
        self::assertSame(42, $engine->run($compiled));
        self::assertSame(42, $engine->eval('40 + 2', [], Engine::BACKEND_NATIVE));
    }

    public function testUnsupportedBackendThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported backend');
        $this->engine->eval('1 + 1', [], 'invalid-backend');
    }

    public function testNativeReduceConcatCanRunRepeatedlyWithoutCorruption(): void
    {
        if (!extension_loaded('scriptlite')) {
            self::markTestSkipped('Requires scriptlite extension.');
        }

        $engine = new Engine(true);
        $source = '
            function flattenDeep(arr) {
                return arr.reduce(function(acc, item) {
                    if (Array.isArray(item)) {
                        return acc.concat(flattenDeep(item));
                    }
                    acc.push(item);
                    return acc;
                }, []);
            }
            flattenDeep([1, [2, [3, [4, [5]]]]]).join(",");
        ';

        self::assertSame('1,2,3,4,5', $engine->eval($source));
        self::assertSame('1,2,3,4,5', $engine->eval($source));
        self::assertSame('1,2,3,4,5', $engine->transpileAndEval($source));
    }

    public function testNativeTryFinallyFailureDoesNotCorruptSubsequentRuns(): void
    {
        if (!extension_loaded('scriptlite')) {
            self::markTestSkipped('Requires scriptlite extension.');
        }

        $engine = new Engine(true);
        $source = '
            var log = [];
            function test() {
                try {
                    log.push("try");
                    return "result";
                } finally {
                    log.push("finally");
                }
            }
            var r = test();
            r + "|" + log.join(",");
        ';

        try {
            $engine->eval($source);
        } catch (\Throwable) {
            // This script currently fails semantically in VM/native, but
            // must never poison subsequent executions in-process.
        }

        self::assertSame(2, $engine->eval('1 + 1'));
        self::assertSame(4, $engine->eval('2 + 2'));
    }
}

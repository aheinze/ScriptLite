<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

use ScriptLite\Ast\Parser;
use ScriptLite\Engine;

final class NativeParserAstCompatibilityTest extends ScriptLiteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('scriptlite')) {
            self::markTestSkipped('Requires scriptlite extension.');
        }
    }

    /**
     * @return list<array{source: string, globals?: array<string, mixed>}>
     */
    private function parserCases(): array
    {
        return [
            ['source' => 'switch (2) { case 1: "a"; break; case 2: "b"; default: "c"; }'],
            ['source' => 'switch ("2") { case 2: "n"; break; case "2": "s"; }'],
            ['source' => 'try { throw "x"; } catch (e) { e + "!"; }'],
            ['source' => 'try { throw 1; } catch { 7; }'],
            ['source' => 'try { "ok"; } finally { 1; }'],
            ['source' => 'var s = 0; for (var x of [1, 2, 3]) { s += x; } s;'],
            ['source' => 'var o = {a: 1, b: 2}; var k = ""; for (var x in o) { k += x; } k;'],
            ['source' => 'var o = null; o?.x ?? 42;'],
            ['source' => 'var o = {a: {b: 3}}; o?.a?.b ?? 0;'],
            ['source' => 'var x = null; x ??= 5; x;'],
            ['source' => '`hello ${"world"}`'],
            ['source' => '`sum=${1+2}`'],
        ];
    }

    public function testNativeParserMatchesVmResults(): void
    {
        foreach ($this->parserCases() as $case) {
            $source = $case['source'];
            $globals = $case['globals'] ?? [];
            $vm = $this->engine->eval($source, $globals, Engine::BACKEND_VM);
            $native = $this->engine->eval($source, $globals, Engine::BACKEND_NATIVE);
            self::assertSame($vm, $native, "Native parser mismatch for: {$source}");
        }
    }

    public function testPhpParserAstCompilesInNativeCompiler(): void
    {
        foreach ($this->parserCases() as $case) {
            $source = $case['source'];
            $globals = $case['globals'] ?? [];
            $vm = $this->engine->eval($source, $globals, Engine::BACKEND_VM);

            $program = (new Parser($source))->parse();
            $compiled = (new \ScriptLiteExt\Compiler())->compile($program);
            $nativeVmResult = (new \ScriptLiteExt\VirtualMachine())->execute($compiled, $globals);

            self::assertSame($vm, $nativeVmResult, "Native compiler mismatch for PHP AST: {$source}");
        }
    }
}

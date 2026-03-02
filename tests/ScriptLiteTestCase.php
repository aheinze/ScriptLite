<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

use PHPUnit\Framework\TestCase;
use ScriptLite\Engine;

/**
 * Shared base class for ScriptLite tests.
 *
 * Convention:
 * - assertBothBackends() — default, tests both VM and transpiler
 * - assertVm() — when transpiler has a known limitation
 * - $this->engine->eval() directly — for exception tests and complex assertion patterns
 */
abstract class ScriptLiteTestCase extends TestCase
{
    protected Engine $engine;

    protected function setUp(): void
    {
        $this->engine = new Engine();
    }

    protected function assertBothBackends(string $source, mixed $expected, array $globals = []): void
    {
        $vm = $this->engine->eval($source, $globals);
        $this->assertSame($expected, $vm, "VM failed for: {$source}");

        $transpiled = $this->engine->transpileAndEval($source, $globals);
        $this->assertSame($expected, $transpiled, "Transpiler failed for: {$source}");
    }

    protected function assertVm(string $source, mixed $expected, array $globals = []): void
    {
        $vm = $this->engine->eval($source, $globals);
        $this->assertSame($expected, $vm, "VM failed for: {$source}");
    }
}

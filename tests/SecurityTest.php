<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

use ScriptLite\Engine;

final class SecurityTest extends ScriptLiteTestCase
{
    private const ENV_KEY = 'SCRIPTLITE_SHOULD_NOT_BE_SET';

    protected function tearDown(): void
    {
        putenv(self::ENV_KEY);
    }

    public function testTranspilerDoesNotInvokePhpFunctionStringAsConstructor(): void
    {
        $this->assertTranspilerRejectsPhpFunctionString(
            'new ("put" + "env")("' . self::ENV_KEY . '=1")'
        );
    }

    public function testTranspilerRejectsReportedSystemConstructorString(): void
    {
        $this->assertTranspilerRejectsPhpFunctionString(
            'new ("sys" + "tem")()'
        );
    }

    public function testTranspilerDoesNotInvokePhpFunctionStringAsCallee(): void
    {
        $this->assertTranspilerRejectsPhpFunctionString(
            '("put" + "env")("' . self::ENV_KEY . '=1")'
        );
    }

    public function testTranspilerDoesNotInvokePhpFunctionStringAsObjectMethod(): void
    {
        $this->assertTranspilerRejectsPhpFunctionString(
            '({ f: "put" + "env" }).f("' . self::ENV_KEY . '=1")'
        );
    }

    public function testTranspilerDoesNotInvokePhpFunctionStringAsArrayCallback(): void
    {
        $this->assertTranspilerRejectsPhpFunctionString(
            '["' . self::ENV_KEY . '=1"].map("put" + "env")'
        );
    }

    public function testTranspilerDoesNotInvokeReassignedLocalFunctionBinding(): void
    {
        $this->assertTranspilerRejectsPhpFunctionString(
            'var f = function() { return 1; }; f = "put" + "env"; f("' . self::ENV_KEY . '=1")'
        );
    }

    public function testTranspilerDoesNotTrustFunctionAssignmentFromUnexecutedBranch(): void
    {
        putenv(self::ENV_KEY);

        try {
            $this->engine->eval(
                'if (false) { f = function() { return 1; }; } f("' . self::ENV_KEY . '=1")',
                ['f' => 'putenv'],
                Engine::BACKEND_TRANSPILER
            );
            self::fail('Transpiler executed a PHP callable string after unsafe branch inference.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('TypeError', $e->getMessage());
        }

        self::assertFalse(getenv(self::ENV_KEY));
    }

    public function testReplaceTreatsPhpFunctionStringAsReplacementText(): void
    {
        putenv(self::ENV_KEY);

        $source = '"' . self::ENV_KEY . '=1".replace(/.*/, "put" + "env")';

        self::assertSame('putenv', $this->engine->eval($source, [], Engine::BACKEND_VM));
        self::assertSame('putenv', $this->engine->eval($source, [], Engine::BACKEND_TRANSPILER));
        self::assertFalse(getenv(self::ENV_KEY));
    }

    public function testTranspilerStillInvokesExplicitPhpClosureGlobals(): void
    {
        $result = $this->engine->eval(
            'transform(20) + fn(2)',
            [
                'transform' => fn(int $value): int => $value + 1,
                'fn' => fn(int $value): int => $value * 10,
            ],
            Engine::BACKEND_TRANSPILER
        );

        self::assertSame(41, $result);
    }

    private function assertTranspilerRejectsPhpFunctionString(string $source): void
    {
        putenv(self::ENV_KEY);

        try {
            $this->engine->eval($source, [], Engine::BACKEND_TRANSPILER);
            self::fail('Transpiler executed a PHP callable string instead of rejecting it.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('TypeError', $e->getMessage());
        }

        self::assertFalse(getenv(self::ENV_KEY));
    }
}

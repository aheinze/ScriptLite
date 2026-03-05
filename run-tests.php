#!/usr/bin/env php
<?php

declare(strict_types=1);

final class TestRunner
{
    private string $rootDir;
    private string $extDir;
    private string $phpBin;
    private string $phpunitBin;
    private string $extensionSo;
    private bool $rebuildExtension = false;
    private bool $skipPhpt = false;
    private bool $allowLoadedExtension = false;
    /** @var list<string> */
    private array $phpunitArgs = [];

    public static function main(array $argv): int
    {
        $runner = new self();
        return $runner->run($argv);
    }

    private function __construct()
    {
        $this->rootDir = __DIR__;
        $this->extDir = $this->rootDir . '/ext/scriptlite';
        $this->phpBin = PHP_BINARY;
        $this->phpunitBin = $this->rootDir . '/vendor/bin/phpunit';
        $this->extensionSo = $this->extDir . '/modules/scriptlite.so';
    }

    private function run(array $argv): int
    {
        if (!$this->parseArgs($argv)) {
            $this->printUsage();
            return 64;
        }

        if (!is_file($this->phpunitBin)) {
            $this->stderr('Missing PHPUnit binary at vendor/bin/phpunit. Run `composer install` first.');
            return 2;
        }

        if (!$this->allowLoadedExtension && $this->isScriptLiteLoadedInDefaultRuntime()) {
            $this->stderr('scriptlite is already loaded in your default PHP runtime.');
            $this->stderr('Cannot guarantee pure PHP-library mode in this run.');
            $this->stderr('Use a PHP runtime/config without scriptlite or pass --allow-loaded-extension.');
            return 2;
        }

        $phpunitPhpLibCmd = array_merge([$this->phpBin, $this->phpunitBin], $this->phpunitArgs);
        if ($this->runPhase('PHP library (no extension)', $phpunitPhpLibCmd, $this->rootDir) !== 0) {
            return 1;
        }

        if ($this->rebuildExtension || !is_file($this->extensionSo)) {
            if ($this->buildExtension() !== 0) {
                return 1;
            }
        }

        if (!is_file($this->extensionSo)) {
            $this->stderr('C extension .so not found after build step: ' . $this->extensionSo);
            return 2;
        }

        $phpunitExtCmd = array_merge(
            [$this->phpBin, '-d', 'extension=' . $this->extensionSo, $this->phpunitBin],
            $this->phpunitArgs
        );
        if ($this->runPhase('C extension backend', $phpunitExtCmd, $this->rootDir) !== 0) {
            return 1;
        }

        if (!$this->skipPhpt) {
            $phptFiles = glob($this->extDir . '/tests/*.phpt');
            if ($phptFiles === false) {
                $phptFiles = [];
            }
            if ($phptFiles !== []) {
                $testsArg = 'TESTS=' . implode(' ', array_map(
                    static fn(string $path): string => 'tests/' . basename($path),
                    $phptFiles
                ));
                if ($this->runPhase('C extension PHPT suite', ['make', 'test', $testsArg], $this->extDir) !== 0) {
                    return 1;
                }
            } else {
                $this->stdout('==> [skip] C extension PHPT suite (no .phpt files found)');
            }
        }

        $this->stdout('');
        $this->stdout('All test phases passed.');
        return 0;
    }

    private function buildExtension(): int
    {
        $this->stdout('==> [build] Rebuilding C extension');

        $jobs = $this->detectCpuCount();
        $steps = [
            ['phpize'],
            ['./configure', '--enable-scriptlite'],
            ['make', '-j' . (string) $jobs],
        ];

        foreach ($steps as $cmd) {
            if ($this->runCommand($cmd, $this->extDir) !== 0) {
                return 1;
            }
        }

        return 0;
    }

    private function isScriptLiteLoadedInDefaultRuntime(): bool
    {
        $check = $this->captureCommand(
            [$this->phpBin, '-r', 'echo extension_loaded("scriptlite") ? "1" : "0";'],
            $this->rootDir
        );

        if ($check['exit'] !== 0) {
            return false;
        }
        return trim($check['stdout']) === '1';
    }

    private function runPhase(string $name, array $cmd, string $cwd): int
    {
        $this->stdout('');
        $this->stdout('==> [run] ' . $name);
        return $this->runCommand($cmd, $cwd);
    }

    private function runCommand(array $cmd, string $cwd): int
    {
        $command = 'cd ' . escapeshellarg($cwd) . ' && ' . $this->escapeCommand($cmd);
        passthru($command, $exitCode);
        return (int) $exitCode;
    }

    /**
     * @return array{exit:int,stdout:string}
     */
    private function captureCommand(array $cmd, string $cwd): array
    {
        $command = 'cd ' . escapeshellarg($cwd) . ' && ' . $this->escapeCommand($cmd);
        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        return [
            'exit' => $exitCode,
            'stdout' => implode("\n", $output),
        ];
    }

    private function escapeCommand(array $cmd): string
    {
        return implode(' ', array_map(
            static fn(string $part): string => escapeshellarg($part),
            $cmd
        ));
    }

    private function detectCpuCount(): int
    {
        $count = (int) trim((string) shell_exec('nproc 2>/dev/null'));
        if ($count > 0) {
            return $count;
        }
        $fromEnv = (int) getenv('NUMBER_OF_PROCESSORS');
        if ($fromEnv > 0) {
            return $fromEnv;
        }
        return 4;
    }

    private function parseArgs(array $argv): bool
    {
        array_shift($argv); // script path
        $passthrough = false;

        foreach ($argv as $arg) {
            if ($passthrough) {
                $this->phpunitArgs[] = $arg;
                continue;
            }

            if ($arg === '--') {
                $passthrough = true;
                continue;
            }

            if ($arg === '--rebuild') {
                $this->rebuildExtension = true;
                continue;
            }

            if ($arg === '--skip-phpt') {
                $this->skipPhpt = true;
                continue;
            }

            if ($arg === '--allow-loaded-extension') {
                $this->allowLoadedExtension = true;
                continue;
            }

            if ($arg === '--help' || $arg === '-h') {
                return false;
            }

            $this->phpunitArgs[] = $arg;
        }

        return true;
    }

    private function printUsage(): void
    {
        $this->stdout('Usage: php run-tests.php [--rebuild] [--skip-phpt] [--allow-loaded-extension] [-- <phpunit args>]');
        $this->stdout('');
        $this->stdout('Examples:');
        $this->stdout('  php run-tests.php');
        $this->stdout('  php run-tests.php --rebuild');
        $this->stdout('  php run-tests.php -- --filter EngineBackendUnificationTest');
    }

    private function stdout(string $line): void
    {
        fwrite(STDOUT, $line . PHP_EOL);
    }

    private function stderr(string $line): void
    {
        fwrite(STDERR, $line . PHP_EOL);
    }
}

exit(TestRunner::main($argv));

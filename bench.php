<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use ScriptLite\Engine;

// ────────────────────────────────────────────────────────────────
// Benchmark: a non-trivial JS program that exercises every major
// subsystem — lexer, parser, compiler, VM dispatch, closures,
// objects, arrays, strings, regex, math, callbacks, recursion.
// ────────────────────────────────────────────────────────────────

$script = <<<'JS'

// ── Utility helpers ──
function range(n) {
    var arr = [];
    for (var i = 0; i < n; i = i + 1) {
        arr.push(i);
    }
    return arr;
}

// ── 1. Sieve of Eratosthenes (loops, arrays, index access) ──
function sieve(limit) {
    var flags = [];
    for (var i = 0; i < limit; i = i + 1) {
        flags.push(true);
    }
    flags[0] = false;
    flags[1] = false;
    for (var p = 2; p * p < limit; p = p + 1) {
        if (flags[p]) {
            for (var m = p * p; m < limit; m = m + p) {
                flags[m] = false;
            }
        }
    }
    var primes = [];
    for (var i = 0; i < limit; i = i + 1) {
        if (flags[i]) {
            primes.push(i);
        }
    }
    return primes;
}

var primes = sieve(1000);

// ── 2. Fibonacci with memoisation (recursion, objects, closures) ──
var makeFib = function() {
    var cache = {0: 0, 1: 1};
    var count = 0;
    function fib(n) {
        count = count + 1;
        if (cache.hasOwnProperty("" + n)) {
            return cache["" + n];
        }
        var result = fib(n - 1) + fib(n - 2);
        cache["" + n] = result;
        return result;
    }
    return {fib: fib, getCount: function() { return count; }};
};

var fibObj = makeFib();
var fib30 = fibObj.fib(30);

// ── 3. Quicksort (recursion, array methods, closures, callbacks) ──
function quicksort(arr) {
    if (arr.length <= 1) {
        return arr;
    }
    var pivot = arr[Math.floor(arr.length / 2)];
    var left = arr.filter(function(x) { return x < pivot; });
    var mid = arr.filter(function(x) { return x === pivot; });
    var right = arr.filter(function(x) { return x > pivot; });
    return quicksort(left).concat(mid).concat(quicksort(right));
}

var unsorted = [64, 25, 12, 22, 11, 90, 45, 78, 33, 56, 1, 99, 7, 42, 88, 15, 63, 29, 71, 50];
var sorted = quicksort(unsorted);

// ── 4. Matrix multiplication (nested loops, nested arrays) ──
function matMul(a, b) {
    var rows = a.length;
    var cols = b[0].length;
    var inner = b.length;
    var result = [];
    for (var i = 0; i < rows; i = i + 1) {
        var row = [];
        for (var j = 0; j < cols; j = j + 1) {
            var sum = 0;
            for (var k = 0; k < inner; k = k + 1) {
                sum = sum + a[i][k] * b[k][j];
            }
            row.push(sum);
        }
        result.push(row);
    }
    return result;
}

var matA = [[1, 2, 3], [4, 5, 6], [7, 8, 9]];
var matB = [[9, 8, 7], [6, 5, 4], [3, 2, 1]];
var matC = matMul(matA, matB);
// Multiply a few times to generate work
for (var mi = 0; mi < 20; mi = mi + 1) {
    matC = matMul(matC, matB);
}

// ── 5. String processing (string methods, regex, split/join/replace) ──
var text = "The quick brown fox jumps over the lazy dog. The dog barked loudly.";
var words = text.split(" ");
var uppercased = words.map(function(w) { return w.toUpperCase(); });
var joined = uppercased.join("-");
var replaced = text.replace(/the/gi, "A");
var matchResult = text.match(/\b\w{4}\b/g);
var searchIdx = text.search(/fox/);

// ── 6. Functional pipeline (map, filter, reduce chains) ──
var pipeline = range(200)
    .map(function(x) { return x * x; })
    .filter(function(x) { return x % 3 === 0; })
    .map(function(x) { return x + 1; })
    .reduce(function(acc, x) { return acc + x; }, 0);

// ── 7. Object builder pattern (objects, methods, this, new) ──
function Vec(x, y) {
    this.x = x;
    this.y = y;
}

var vectors = [];
for (var vi = 0; vi < 100; vi = vi + 1) {
    vectors.push(new Vec(vi, vi * 2));
}
var dotProducts = vectors.map(function(v) {
    return v.x * v.x + v.y * v.y;
});
var totalMagnitude = dotProducts.reduce(function(a, b) { return a + b; }, 0);

// ── 8. String parsing with regex (regex exec, groups, loops) ──
var csv = "name:Alice,age:30;name:Bob,age:25;name:Carol,age:35";
var records = csv.split(";");
var parsed = records.map(function(rec) {
    var fields = rec.split(",");
    var obj = {};
    fields.forEach(function(f) {
        var parts = f.split(":");
        obj[parts[0]] = parts[1];
    });
    return obj;
});

// ── 9. Reduce to build complex structure ──
var histogram = "aabbbccccdddddeeeee".split("").reduce(function(acc, ch) {
    if (acc.hasOwnProperty(ch)) {
        acc[ch] = acc[ch] + 1;
    } else {
        acc[ch] = 1;
    }
    return acc;
}, {});

// ── 10. Recursive tree building + traversal ──
function makeTree(depth) {
    if (depth <= 0) {
        return {value: 1, left: null, right: null};
    }
    return {
        value: depth,
        left: makeTree(depth - 1),
        right: makeTree(depth - 1)
    };
}

function sumTree(node) {
    if (node === null) {
        return 0;
    }
    return node.value + sumTree(node.left) + sumTree(node.right);
}

var tree = makeTree(8);
var treeSum = sumTree(tree);

// ── Collect results ──
var results = {
    primeCount: primes.length,
    lastPrime: primes[primes.length - 1],
    fib30: fib30,
    fibCalls: fibObj.getCount(),
    sortedFirst: sorted[0],
    sortedLast: sorted[sorted.length - 1],
    matC00: matC[0][0],
    wordCount: words.length,
    replaced: replaced.slice(0, 20),
    matchCount: matchResult.length,
    searchIdx: searchIdx,
    pipeline: pipeline,
    totalMagnitude: totalMagnitude,
    parsedCount: parsed.length,
    histogramA: histogram.a,
    histogramE: histogram.e,
    treeSum: treeSum
};

results;
JS;

$engine = new Engine();

// ── Warmup ──
$compiled = $engine->compile($script);
$engine->run($compiled);

// ── Benchmark ──
$iterations = 50;


// Phase 1: Compile
$t0 = hrtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $engine->compile($script);
}
$compileNs = hrtime(true) - $t0;

// Phase 2: Execute
$t0 = hrtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $result = $engine->run($compiled);
}
$executeNs = hrtime(true) - $t0;

// Phase 3: Full eval (compile + execute)
$t0 = hrtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $engine->eval($script);
}
$totalNs = hrtime(true) - $t0;

// Phase 4: Cached bytecode — serialize compiled script, then unserialize + execute
$serialized = serialize($compiled);
$serializedSize = strlen($serialized);
unserialize($serialized); // warmup

$t0 = hrtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $cached = unserialize($serialized);
    $result = $engine->run($cached);
}
$cachedNs = hrtime(true) - $t0;

// Phase 5: File-cached bytecode — simulate loading from disk (APCu / file cache)
$cacheFile = sys_get_temp_dir() . '/scriptlite_bench_cache.bin';
file_put_contents($cacheFile, $serialized);
clearstatcache();
// warmup OS page cache
file_get_contents($cacheFile);

$t0 = hrtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $cached = unserialize(file_get_contents($cacheFile));
    $result = $engine->run($cached);
}
$fileCachedNs = hrtime(true) - $t0;
@unlink($cacheFile);

// Phase 6: Isolate unserialize cost
$t0 = hrtime(true);
for ($i = 0; $i < $iterations; $i++) {
    unserialize($serialized);
}
$unserializeNs = hrtime(true) - $t0;

// Phase 7: Transpile JS → PHP source, then eval
$transpiled = $engine->transpile($script);
$transpiledSize = strlen($transpiled);
// Warmup transpiled
$engine->evalTranspiled($transpiled);

// 7a: Transpile time
$t0 = hrtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $engine->transpile($script);
}
$transpileNs = hrtime(true) - $t0;

// 7b: Execute transpiled
$t0 = hrtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $trResult = $engine->evalTranspiled($transpiled);
}
$trExecNs = hrtime(true) - $t0;

// 7c: Transpile + execute (full)
$t0 = hrtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $engine->evalTranspiled($engine->transpile($script));
}
$trFullNs = hrtime(true) - $t0;

// Phase 8: File-based transpiled execution (worker-safe, no eval leak)
$trFile = $engine->saveTranspiled($transpiled, sys_get_temp_dir() . '/scriptlite_bench_tr.php');
// Warmup: include once so OPcache compiles it
include $trFile;

$t0 = hrtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $trResult = include $trFile;
}
$trFileNs = hrtime(true) - $t0;

// 8b: runTranspiled() (temp file per call — measures file I/O overhead)
$engine->runTranspiled($transpiled); // warmup
$t0 = hrtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $engine->runTranspiled($transpiled);
}
$trTmpNs = hrtime(true) - $t0;
@unlink($trFile);

// ════════════════════════════════════════════════════════════════
// Phase 9: PHP Object Interop — pass real class instances as globals
// ════════════════════════════════════════════════════════════════

class Account {
    public string $owner;
    public float $balance;
    /** @var string[] */
    public array $log = [];

    public function __construct(string $owner, float $balance) {
        $this->owner = $owner;
        $this->balance = $balance;
    }

    public function deposit(float $amount): float {
        $this->balance += $amount;
        $this->log[] = '+' . $amount;
        return $this->balance;
    }

    public function withdraw(float $amount): float {
        if ($amount > $this->balance) {
            $this->log[] = 'DENIED:' . $amount;
            return $this->balance;
        }
        $this->balance -= $amount;
        $this->log[] = '-' . $amount;
        return $this->balance;
    }

    public function transfer(Account $to, float $amount): bool {
        if ($amount > $this->balance) return false;
        $this->withdraw($amount);
        $to->deposit($amount);
        return true;
    }

    public function getLogSize(): int {
        return count($this->log);
    }

    public function getSummary(): string {
        return $this->owner . ':' . $this->balance;
    }
}

$objScript = <<<'JS'
// Read properties
var ownerName = acc.owner;
var startBal  = acc.balance;

// Call methods — args are auto-coerced from JS float to PHP float/int
acc.deposit(100);
acc.deposit(250);
acc.withdraw(75);
acc.withdraw(50);

// Loop: do 100 deposit/withdraw cycles to generate work
for (var i = 0; i < 100; i = i + 1) {
    acc.deposit(10);
    acc.withdraw(5);
}

// Read mutated state
var endBal  = acc.balance;
var logSize = acc.getLogSize();
var summary = acc.getSummary();

// Transfer between two PHP objects
acc.transfer(acc2, 50);

var results = {
    ownerName: ownerName,
    startBal: startBal,
    endBal: acc.balance,
    acc2Bal: acc2.balance,
    logSize: acc.getLogSize(),
    summary: acc.getSummary()
};
results;
JS;

// Warmup
$objAcc  = new Account('Alice', 1000);
$objAcc2 = new Account('Bob', 500);
$objGlobals = ['acc' => $objAcc, 'acc2' => $objAcc2];
$engine->eval($objScript, $objGlobals);

// 9a: VM with PHP objects
$t0 = hrtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $objAcc  = new Account('Alice', 1000);
    $objAcc2 = new Account('Bob', 500);
    $objResult = $engine->eval($objScript, ['acc' => $objAcc, 'acc2' => $objAcc2]);
}
$objVmNs = hrtime(true) - $t0;

// 9b: Transpiled with PHP objects
$objTranspiled = $engine->transpile($objScript, $objGlobals);
$objAcc  = new Account('Alice', 1000);
$objAcc2 = new Account('Bob', 500);
$engine->evalTranspiled($objTranspiled, ['acc' => $objAcc, 'acc2' => $objAcc2]); // warmup

$t0 = hrtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $objAcc  = new Account('Alice', 1000);
    $objAcc2 = new Account('Bob', 500);
    $objTrResult = $engine->evalTranspiled($objTranspiled, ['acc' => $objAcc, 'acc2' => $objAcc2]);
}
$objTrNs = hrtime(true) - $t0;

// 9c: Native PHP equivalent
function php_object_benchmark(): array {
    $acc  = new Account('Alice', 1000);
    $acc2 = new Account('Bob', 500);

    $ownerName = $acc->owner;
    $startBal  = $acc->balance;

    $acc->deposit(100);
    $acc->deposit(250);
    $acc->withdraw(75);
    $acc->withdraw(50);

    for ($i = 0; $i < 100; $i++) {
        $acc->deposit(10);
        $acc->withdraw(5);
    }

    $endBal  = $acc->balance;
    $logSize = $acc->getLogSize();
    $summary = $acc->getSummary();

    $acc->transfer($acc2, 50);

    return [
        'ownerName' => $ownerName,
        'startBal'  => $startBal,
        'endBal'    => $acc->balance,
        'acc2Bal'   => $acc2->balance,
        'logSize'   => $acc->getLogSize(),
        'summary'   => $acc->getSummary(),
    ];
}

php_object_benchmark(); // warmup

$t0 = hrtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $phpObjResult = php_object_benchmark();
}
$phpObjNs = hrtime(true) - $t0;


$objVmMs    = $objVmNs / 1e6 / $iterations;
$objTrMs    = $objTrNs / 1e6 / $iterations;
$phpObjMs   = $phpObjNs / 1e6 / $iterations;

// ── Memory measurements (in-process, using memory_reset_peak_usage) ──
// PHP 8.2+ lets us reset the peak counter between measurements.
function measurePeakMemory(callable $fn): int
{
    $fn(); // warmup
    gc_collect_cycles();
    memory_reset_peak_usage();
    $m0 = memory_get_peak_usage();
    $fn();
    $m1 = memory_get_peak_usage();
    gc_collect_cycles();
    return max(0, $m1 - $m0);
}

$memCompile   = measurePeakMemory(fn() => $engine->compile($script));
$memVmExec    = measurePeakMemory(fn() => $engine->run($compiled));
$memTranspile = measurePeakMemory(fn() => $engine->transpile($script));
$memTrExec    = measurePeakMemory(fn() => $engine->evalTranspiled($transpiled));
$memNative    = measurePeakMemory(fn() => php_benchmark());
$memObjVm     = measurePeakMemory(fn() => $engine->eval($objScript, ['acc' => new Account('Alice', 1000), 'acc2' => new Account('Bob', 500)]));
$memObjTr     = measurePeakMemory(fn() => $engine->evalTranspiled($objTranspiled, ['acc' => new Account('Alice', 1000), 'acc2' => new Account('Bob', 500)]));
$memObjNative = measurePeakMemory(fn() => php_object_benchmark());

// ── Report ──
$compileMs      = $compileNs / 1e6 / $iterations;
$executeMs      = $executeNs / 1e6 / $iterations;
$totalMs        = $totalNs / 1e6 / $iterations;
$cachedMs       = $cachedNs / 1e6 / $iterations;
$fileCachedMs   = $fileCachedNs / 1e6 / $iterations;
$unserializeMs  = $unserializeNs / 1e6 / $iterations;
$transpileMs    = $transpileNs / 1e6 / $iterations;
$trExecMs       = $trExecNs / 1e6 / $iterations;
$trFullMs       = $trFullNs / 1e6 / $iterations;
$trFileMs       = $trFileNs / 1e6 / $iterations;
$trTmpMs        = $trTmpNs / 1e6 / $iterations;

// ════════════════════════════════════════════════════════════════
// Native PHP — identical algorithms, idiomatic PHP
// ════════════════════════════════════════════════════════════════

function php_benchmark(): array
{
    // 1. Sieve
    $flags = array_fill(0, 1000, true);
    $flags[0] = $flags[1] = false;
    for ($p = 2; $p * $p < 1000; $p++) {
        if ($flags[$p]) {
            for ($m = $p * $p; $m < 1000; $m += $p) {
                $flags[$m] = false;
            }
        }
    }
    $primes = [];
    for ($i = 0; $i < 1000; $i++) {
        if ($flags[$i]) $primes[] = $i;
    }

    // 2. Fibonacci with memo
    $cache = [0 => 0, 1 => 1];
    $fibCount = 0;
    $fib = function (int $n) use (&$fib, &$cache, &$fibCount): int {
        $fibCount++;
        if (isset($cache[$n])) return $cache[$n];
        $r = $fib($n - 1) + $fib($n - 2);
        $cache[$n] = $r;
        return $r;
    };
    $fib30 = $fib(30);

    // 3. Quicksort
    $qsort = function (array $arr) use (&$qsort): array {
        if (count($arr) <= 1) return $arr;
        $pivot = $arr[(int) floor(count($arr) / 2)];
        $left  = array_values(array_filter($arr, fn($x) => $x < $pivot));
        $mid   = array_values(array_filter($arr, fn($x) => $x === $pivot));
        $right = array_values(array_filter($arr, fn($x) => $x > $pivot));
        return array_merge($qsort($left), $mid, $qsort($right));
    };
    $sorted = $qsort([64,25,12,22,11,90,45,78,33,56,1,99,7,42,88,15,63,29,71,50]);

    // 4. Matrix multiplication
    $matMul = function (array $a, array $b): array {
        $rows = count($a); $cols = count($b[0]); $inner = count($b);
        $result = [];
        for ($i = 0; $i < $rows; $i++) {
            $row = [];
            for ($j = 0; $j < $cols; $j++) {
                $sum = 0;
                for ($k = 0; $k < $inner; $k++) $sum += $a[$i][$k] * $b[$k][$j];
                $row[] = $sum;
            }
            $result[] = $row;
        }
        return $result;
    };
    $matC = $matMul([[1,2,3],[4,5,6],[7,8,9]], [[9,8,7],[6,5,4],[3,2,1]]);
    for ($mi = 0; $mi < 20; $mi++) $matC = $matMul($matC, [[9,8,7],[6,5,4],[3,2,1]]);

    // 5. String processing
    $text = "The quick brown fox jumps over the lazy dog. The dog barked loudly.";
    $words = explode(' ', $text);
    $uppercased = array_map('strtoupper', $words);
    $joined = implode('-', $uppercased);
    $replaced = preg_replace('/the/i', 'A', $text);
    preg_match_all('/\b\w{4}\b/', $text, $matches);
    $matchCount = count($matches[0]);
    $searchIdx = preg_match('/fox/', $text, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : -1;

    // 6. Functional pipeline
    $pipeline = array_reduce(
        array_map(fn($x) => $x + 1,
            array_filter(
                array_map(fn($x) => $x * $x, range(0, 199)),
                fn($x) => $x % 3 === 0
            )
        ),
        fn($acc, $x) => $acc + $x,
        0
    );

    // 7. Vectors
    $vectors = [];
    for ($vi = 0; $vi < 100; $vi++) $vectors[] = ['x' => $vi, 'y' => $vi * 2];
    $dots = array_map(fn($v) => $v['x'] * $v['x'] + $v['y'] * $v['y'], $vectors);
    $totalMag = array_sum($dots);

    // 8. CSV parsing
    $csv = "name:Alice,age:30;name:Bob,age:25;name:Carol,age:35";
    $parsed = array_map(function ($rec) {
        $obj = [];
        foreach (explode(',', $rec) as $f) {
            [$k, $v] = explode(':', $f);
            $obj[$k] = $v;
        }
        return $obj;
    }, explode(';', $csv));

    // 9. Histogram
    $histogram = array_count_values(str_split("aabbbccccdddddeeeee"));

    // 10. Recursive tree
    $makeTree = function (int $d) use (&$makeTree): ?array {
        if ($d <= 0) return ['value' => 1, 'left' => null, 'right' => null];
        return ['value' => $d, 'left' => $makeTree($d - 1), 'right' => $makeTree($d - 1)];
    };
    $sumTree = function (?array $n) use (&$sumTree): int {
        if ($n === null) return 0;
        return $n['value'] + $sumTree($n['left']) + $sumTree($n['right']);
    };
    $tree = $makeTree(8);
    $treeSum = $sumTree($tree);

    return [
        'primeCount' => count($primes), 'lastPrime' => end($primes),
        'fib30' => $fib30, 'fibCalls' => $fibCount,
        'sortedFirst' => $sorted[0], 'sortedLast' => end($sorted),
        'pipeline' => $pipeline, 'treeSum' => $treeSum,
        'parsedCount' => count($parsed),
        'histogramA' => $histogram['a'], 'histogramE' => $histogram['e'],
    ];
}

// Warmup native
php_benchmark();

$t0 = hrtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $phpResult = php_benchmark();
}
$phpNs = hrtime(true) - $t0;
$phpMs = $phpNs / 1e6 / $iterations;

// ── Report ──
$ratio = $totalMs / $phpMs;
$cachedRatio = $cachedMs / $phpMs;
$compileSavings = $compileMs / $cachedMs * 100 - 100; // how much faster cache is than compile
$cacheVsEval = $totalMs / $cachedMs;

$w = 66;
$line = str_repeat('═', $w);
$sep  = "╠{$line}╣";

echo "╔{$line}╗\n";
echo "║" . str_pad('ScriptLite Engine Benchmark', $w, ' ', STR_PAD_BOTH) . "║\n";
echo "{$sep}\n";
echo "║  Script: 10 workloads (sieve, fib, quicksort, matrix mul,      ║\n";
echo "║    string/regex ops, functional pipeline, constructors,         ║\n";
echo "║    CSV parsing, histogram, recursive tree)                      ║\n";
printf("║  Iterations: %-52d║\n", $iterations);
echo "{$sep}\n";
echo "║                                                                  ║\n";
echo "║  1) ScriptLite — full eval (compile + execute each time):            ║\n";
printf("║       Compile (lex+parse+bytecode):    %8.2f ms               ║\n", $compileMs);
printf("║       Execute (VM only):               %8.2f ms               ║\n", $executeMs);
printf("║       Total:                           %8.2f ms               ║\n", $totalMs);
echo "║                                                                  ║\n";
echo "║  2) ScriptLite — in-memory cached bytecode (compile once, reuse):    ║\n";
printf("║       Execute (VM only, pre-compiled):  %8.2f ms               ║\n", $executeMs);
echo "║                                                                  ║\n";
echo "║  3) ScriptLite — serialized bytecode cache (unserialize + execute):  ║\n";
printf("║       Unserialize from memory:         %8.2f ms               ║\n", $unserializeMs);
printf("║       Unserialize + execute (memory):  %8.2f ms               ║\n", $cachedMs);
printf("║       Unserialize + execute (file):    %8.2f ms               ║\n", $fileCachedMs);
printf("║       Bytecode blob size:              %6.1f KB                ║\n", $serializedSize / 1024);
echo "║                                                                  ║\n";
echo "║  4) ScriptLite — transpiled to PHP (eval):                            ║\n";
printf("║       Transpile (lex+parse+codegen):   %8.2f ms               ║\n", $transpileMs);
printf("║       Execute (eval, in-memory):       %8.2f ms               ║\n", $trExecMs);
printf("║       Total (transpile + eval):        %8.2f ms               ║\n", $trFullMs);
printf("║       Transpiled source size:          %6.1f KB                ║\n", $transpiledSize / 1024);
echo "║                                                                  ║\n";
echo "║  5) ScriptLite — transpiled to PHP (file, worker-safe):             ║\n";
printf("║       Execute (include cached file):   %8.2f ms               ║\n", $trFileMs);
printf("║       Execute (temp file per call):    %8.2f ms               ║\n", $trTmpMs);
echo "║                                                                  ║\n";
echo "║  6) Native PHP (same algorithms):                               ║\n";
printf("║       Execution time:                  %8.2f ms               ║\n", $phpMs);
echo "║                                                                  ║\n";
echo "║  7) PHP Object Interop (property r/w, method calls, mutation):  ║\n";
printf("║       VM (compile + execute):          %8.2f ms               ║\n", $objVmMs);
printf("║       Transpiled (eval):               %8.2f ms               ║\n", $objTrMs);
printf("║       Native PHP:                      %8.2f ms               ║\n", $phpObjMs);
printf("║       VM vs native:                    %8.1fx overhead        ║\n", $objVmMs / $phpObjMs);
printf("║       Transpiled vs native:            %8.1fx overhead        ║\n", $objTrMs / $phpObjMs);
echo "║                                                                  ║\n";
echo "{$sep}\n";
echo "║  Comparisons:                                                    ║\n";
printf("║    Full eval (VM) vs native PHP:       %8.1fx overhead        ║\n", $ratio);
printf("║    VM-only vs native PHP:              %8.1fx overhead        ║\n", $executeMs / $phpMs);
printf("║    Transpiled exec vs native PHP:      %8.1fx overhead        ║\n", $trExecMs / $phpMs);
printf("║    Transpiled exec vs VM exec:         %8.2fx speedup         ║\n", $executeMs / $trExecMs);
printf("║    Full eval vs cached (memory):       %8.2fx slower          ║\n", $cacheVsEval);
printf("║    Compile vs unserialize:             %8.2fx vs %.2f ms      ║\n", $compileMs / $unserializeMs, $unserializeMs);
echo "{$sep}\n";
echo "║  Memory (peak allocation per single run):                         ║\n";
echo "║                                                                  ║\n";
echo "║    Algorithms workload:                                          ║\n";
printf("║      Compile (lex+parse+bytecode):   %8.1f KB                 ║\n", $memCompile / 1024);
printf("║      VM execute:                     %8.1f KB                 ║\n", $memVmExec / 1024);
printf("║      Transpile (codegen):            %8.1f KB                 ║\n", $memTranspile / 1024);
printf("║      Transpiled execute:             %8.1f KB                 ║\n", $memTrExec / 1024);
printf("║      Native PHP:                     %8.1f KB                 ║\n", $memNative / 1024);
echo "║                                                                  ║\n";
echo "║    Object interop workload:                                      ║\n";
printf("║      VM (compile + execute):         %8.1f KB                 ║\n", $memObjVm / 1024);
printf("║      Transpiled (eval):              %8.1f KB                 ║\n", $memObjTr / 1024);
printf("║      Native PHP:                     %8.1f KB                 ║\n", $memObjNative / 1024);
echo "║                                                                  ║\n";
printf("║    Bytecode blob (serialized):       %8.1f KB                 ║\n", $serializedSize / 1024);
printf("║    Transpiled source:                %8.1f KB                 ║\n", $transpiledSize / 1024);
echo "{$sep}\n";
echo "║  Result verification:                                           ║\n";
printf("║    Primes < 1000:  %-4d (last: %d)                            ║\n", $result['primeCount'], $result['lastPrime']);
printf("║    fib(30):        %-7d (%d calls with memo)                ║\n", $result['fib30'], $result['fibCalls']);
printf("║    Sorted:         [%d ... %d]                                 ║\n", $result['sortedFirst'], $result['sortedLast']);
printf("║    Pipeline sum:   %-10d                                     ║\n", $result['pipeline']);
printf("║    Tree sum:       %-10d                                     ║\n", $result['treeSum']);
printf("║    Parsed records: %-4d                                        ║\n", $result['parsedCount']);
printf("║    Histogram a/e:  %d/%d                                        ║\n", $result['histogramA'], $result['histogramE']);
echo "{$sep}\n";
echo "║  Result verification (object interop):                           ║\n";
printf("║    Owner:    %-52s║\n", $objResult['ownerName']);
printf("║    Start:    %-52s║\n", $objResult['startBal']);
printf("║    End bal:  %-52s║\n", $objResult['endBal']);
printf("║    Acc2 bal: %-52s║\n", $objResult['acc2Bal']);
printf("║    Log size: %-52s║\n", $objResult['logSize']);
printf("║    Summary:  %-52s║\n", $objResult['summary']);
echo "{$sep}\n";
echo "║  Cross-check (VM vs native PHP vs transpiled):                   ║\n";
$match = ($result['primeCount'] === $phpResult['primeCount']
    && $result['fib30'] === $phpResult['fib30']
    && $result['sortedFirst'] === $phpResult['sortedFirst']
    && $result['sortedLast'] === $phpResult['sortedLast']
    && $result['treeSum'] === $phpResult['treeSum']
    && $result['parsedCount'] === $phpResult['parsedCount']);
$trMatch = ($trResult['primeCount'] === $result['primeCount']
    && $trResult['fib30'] === $result['fib30']
    && $trResult['sortedFirst'] === $result['sortedFirst']
    && $trResult['sortedLast'] === $result['sortedLast']
    && $trResult['treeSum'] === $result['treeSum']
    && $trResult['parsedCount'] === $result['parsedCount']);
$objMatch = ($objResult['ownerName'] === $phpObjResult['ownerName']
    && (float) $objResult['endBal'] === (float) $phpObjResult['endBal']
    && (float) $objResult['acc2Bal'] === (float) $phpObjResult['acc2Bal']
    && $objResult['logSize'] === $phpObjResult['logSize']);
$objTrMatch = ($objTrResult['ownerName'] === $phpObjResult['ownerName']
    && (float) $objTrResult['endBal'] === (float) $phpObjResult['endBal']
    && (float) $objTrResult['acc2Bal'] === (float) $phpObjResult['acc2Bal']
    && $objTrResult['logSize'] === $phpObjResult['logSize']);
$allOk = $match && $trMatch && $objMatch && $objTrMatch;
echo "║    " . ($allOk ? 'All modes match (algorithms + object interop)!' : 'MISMATCH DETECTED') . str_repeat(' ', $allOk ? 26 : 44) . "║\n";
echo "╚{$line}╝\n";

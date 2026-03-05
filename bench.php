<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use ScriptLite\Engine;

$hasNative = extension_loaded('scriptlite') && class_exists(\ScriptLiteExt\Engine::class, false);
$iterations = 30;

// ── JS benchmark scripts ──

$benchmarks = [
    'fibonacci(25)' => <<<'JS'
        function fib(n) { if (n < 2) return n; return fib(n - 1) + fib(n - 2); }
        fib(25);
        JS,
    'sieve(5000)' => <<<'JS'
        function sieve(limit) {
            var flags = [];
            for (var i = 0; i < limit; i++) flags.push(true);
            flags[0] = false; flags[1] = false;
            for (var p = 2; p * p < limit; p++) {
                if (flags[p]) { for (var m = p * p; m < limit; m += p) flags[m] = false; }
            }
            var primes = [];
            for (var i = 0; i < limit; i++) { if (flags[i]) primes.push(i); }
            return primes.length;
        }
        sieve(5000);
        JS,
    'quicksort(200)' => <<<'JS'
        function quicksort(arr) {
            if (arr.length <= 1) return arr;
            var pivot = arr[Math.floor(arr.length / 2)];
            var left = arr.filter(function(x) { return x < pivot; });
            var mid = arr.filter(function(x) { return x === pivot; });
            var right = arr.filter(function(x) { return x > pivot; });
            return quicksort(left).concat(mid).concat(quicksort(right));
        }
        var arr = [];
        for (var i = 0; i < 200; i++) arr.push(Math.floor(Math.random() * 1000));
        quicksort(arr).length;
        JS,
    'string ops' => <<<'JS'
        var text = "The quick brown fox jumps over the lazy dog";
        var result = "";
        for (var i = 0; i < 100; i++) {
            result = text.toUpperCase().split(" ").reverse().join("-").toLowerCase().slice(0, 30);
        }
        result;
        JS,
    'closures(5k)' => <<<'JS'
        function makeCounter() {
            var count = 0;
            return { inc: function() { count++; return count; }, get: function() { return count; } };
        }
        var c = makeCounter();
        for (var i = 0; i < 5000; i++) c.inc();
        c.get();
        JS,
    'objects+vectors' => <<<'JS'
        function Vec(x, y) { this.x = x; this.y = y; }
        var vectors = [];
        for (var i = 0; i < 500; i++) vectors.push(new Vec(i, i * 2));
        var sum = vectors.map(function(v) { return v.x * v.x + v.y * v.y; })
                       .reduce(function(a, b) { return a + b; }, 0);
        sum;
        JS,
    'tree(depth=10)' => <<<'JS'
        function makeTree(d) {
            if (d <= 0) return { v: 1, l: null, r: null };
            return { v: d, l: makeTree(d-1), r: makeTree(d-1) };
        }
        function sumTree(n) { if (n === null) return 0; return n.v + sumTree(n.l) + sumTree(n.r); }
        sumTree(makeTree(10));
        JS,
    'matrix(3x3x50)' => <<<'JS'
        function matMul(a, b) {
            var rows = a.length, cols = b[0].length, inner = b.length;
            var result = [];
            for (var i = 0; i < rows; i++) {
                var row = [];
                for (var j = 0; j < cols; j++) {
                    var sum = 0;
                    for (var k = 0; k < inner; k++) sum += a[i][k] * b[k][j];
                    row.push(sum);
                }
                result.push(row);
            }
            return result;
        }
        var m = [[1,2,3],[4,5,6],[7,8,9]];
        var b = [[9,8,7],[6,5,4],[3,2,1]];
        for (var i = 0; i < 50; i++) m = matMul(m, b);
        m[0][0];
        JS,
    'pipeline(500)' => <<<'JS'
        var arr = [];
        for (var i = 0; i < 500; i++) arr.push(i);
        arr.map(function(x) { return x * x; })
           .filter(function(x) { return x % 3 === 0; })
           .map(function(x) { return x + 1; })
           .reduce(function(a, x) { return a + x; }, 0);
        JS,
    'regex(200iter)' => <<<'JS'
        var text = "The quick brown fox jumps over the lazy dog. Pack my box with five dozen liquor jugs.";
        var result = 0;
        for (var i = 0; i < 200; i++) {
            var m = text.match(/\b\w{4,5}\b/g);
            result += m.length;
            text.replace(/[aeiou]/gi, "*");
            text.search(/fox|dog|box/);
        }
        result;
        JS,
];

// ── Combined workload script (for execution mode comparison) ──

$combinedScript = <<<'JS'
function range(n) { var arr = []; for (var i = 0; i < n; i = i + 1) arr.push(i); return arr; }

function sieve(limit) {
    var flags = [];
    for (var i = 0; i < limit; i = i + 1) flags.push(true);
    flags[0] = false; flags[1] = false;
    for (var p = 2; p * p < limit; p = p + 1) {
        if (flags[p]) { for (var m = p * p; m < limit; m = m + p) flags[m] = false; }
    }
    var primes = [];
    for (var i = 0; i < limit; i = i + 1) { if (flags[i]) primes.push(i); }
    return primes;
}
var primes = sieve(1000);

var makeFib = function() {
    var cache = {0: 0, 1: 1}; var count = 0;
    function fib(n) {
        count = count + 1;
        if (cache.hasOwnProperty("" + n)) return cache["" + n];
        var result = fib(n - 1) + fib(n - 2);
        cache["" + n] = result;
        return result;
    }
    return {fib: fib, getCount: function() { return count; }};
};
var fibObj = makeFib();
var fib30 = fibObj.fib(30);

function quicksort(arr) {
    if (arr.length <= 1) return arr;
    var pivot = arr[Math.floor(arr.length / 2)];
    var left = arr.filter(function(x) { return x < pivot; });
    var mid = arr.filter(function(x) { return x === pivot; });
    var right = arr.filter(function(x) { return x > pivot; });
    return quicksort(left).concat(mid).concat(quicksort(right));
}
var sorted = quicksort([64, 25, 12, 22, 11, 90, 45, 78, 33, 56, 1, 99, 7, 42, 88, 15, 63, 29, 71, 50]);

function matMul(a, b) {
    var rows = a.length, cols = b[0].length, inner = b.length, result = [];
    for (var i = 0; i < rows; i = i + 1) {
        var row = [];
        for (var j = 0; j < cols; j = j + 1) {
            var sum = 0;
            for (var k = 0; k < inner; k = k + 1) sum = sum + a[i][k] * b[k][j];
            row.push(sum);
        }
        result.push(row);
    }
    return result;
}
var matB = [[9, 8, 7], [6, 5, 4], [3, 2, 1]];
var matC = matMul([[1, 2, 3], [4, 5, 6], [7, 8, 9]], matB);
for (var mi = 0; mi < 20; mi = mi + 1) matC = matMul(matC, matB);

var text = "The quick brown fox jumps over the lazy dog. The dog barked loudly.";
var words = text.split(" ");
var uppercased = words.map(function(w) { return w.toUpperCase(); });
var joined = uppercased.join("-");
var replaced = text.replace(/the/gi, "A");
var matchResult = text.match(/\b\w{4}\b/g);
var searchIdx = text.search(/fox/);

var pipeline = range(200)
    .map(function(x) { return x * x; })
    .filter(function(x) { return x % 3 === 0; })
    .map(function(x) { return x + 1; })
    .reduce(function(acc, x) { return acc + x; }, 0);

function Vec(x, y) { this.x = x; this.y = y; }
var vectors = [];
for (var vi = 0; vi < 100; vi = vi + 1) vectors.push(new Vec(vi, vi * 2));
var totalMagnitude = vectors.map(function(v) { return v.x * v.x + v.y * v.y; })
    .reduce(function(a, b) { return a + b; }, 0);

var csv = "name:Alice,age:30;name:Bob,age:25;name:Carol,age:35";
var parsed = csv.split(";").map(function(rec) {
    var obj = {};
    rec.split(",").forEach(function(f) { var parts = f.split(":"); obj[parts[0]] = parts[1]; });
    return obj;
});

var histogram = "aabbbccccdddddeeeee".split("").reduce(function(acc, ch) {
    if (acc.hasOwnProperty(ch)) { acc[ch] = acc[ch] + 1; } else { acc[ch] = 1; }
    return acc;
}, {});

function makeTree(depth) {
    if (depth <= 0) return {value: 1, left: null, right: null};
    return {value: depth, left: makeTree(depth - 1), right: makeTree(depth - 1)};
}
function sumTree(node) {
    if (node === null) return 0;
    return node.value + sumTree(node.left) + sumTree(node.right);
}
var treeSum = sumTree(makeTree(8));

var results = {
    primeCount: primes.length, lastPrime: primes[primes.length - 1],
    fib30: fib30, fibCalls: fibObj.getCount(),
    sortedFirst: sorted[0], sortedLast: sorted[sorted.length - 1],
    pipeline: pipeline, totalMagnitude: totalMagnitude,
    parsedCount: parsed.length,
    histogramA: histogram.a, histogramE: histogram.e,
    treeSum: treeSum
};
results;
JS;

// ── Object interop script ──

class Account {
    public string $owner;
    public float $balance;
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
        if ($amount > $this->balance) { $this->log[] = 'DENIED:' . $amount; return $this->balance; }
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
    public function getLogSize(): int { return count($this->log); }
    public function getSummary(): string { return $this->owner . ':' . $this->balance; }
}

$objScript = <<<'JS'
acc.deposit(100); acc.deposit(250); acc.withdraw(75); acc.withdraw(50);
for (var i = 0; i < 100; i = i + 1) { acc.deposit(10); acc.withdraw(5); }
acc.transfer(acc2, 50);
({endBal: acc.balance, acc2Bal: acc2.balance, logSize: acc.getLogSize(), summary: acc.getSummary()});
JS;

// ── Native PHP baseline ──

function php_benchmark(): array {
    $flags = array_fill(0, 1000, true);
    $flags[0] = $flags[1] = false;
    for ($p = 2; $p * $p < 1000; $p++) {
        if ($flags[$p]) { for ($m = $p * $p; $m < 1000; $m += $p) $flags[$m] = false; }
    }
    $primes = [];
    for ($i = 0; $i < 1000; $i++) { if ($flags[$i]) $primes[] = $i; }

    $cache = [0 => 0, 1 => 1]; $fibCount = 0;
    $fib = function (int $n) use (&$fib, &$cache, &$fibCount): int {
        $fibCount++;
        if (isset($cache[$n])) return $cache[$n];
        return $cache[$n] = $fib($n - 1) + $fib($n - 2);
    };
    $fib30 = $fib(30);

    $qsort = function (array $arr) use (&$qsort): array {
        if (count($arr) <= 1) return $arr;
        $pivot = $arr[(int) floor(count($arr) / 2)];
        return array_merge(
            $qsort(array_values(array_filter($arr, fn($x) => $x < $pivot))),
            array_values(array_filter($arr, fn($x) => $x === $pivot)),
            $qsort(array_values(array_filter($arr, fn($x) => $x > $pivot)))
        );
    };
    $sorted = $qsort([64,25,12,22,11,90,45,78,33,56,1,99,7,42,88,15,63,29,71,50]);

    $matMul = function (array $a, array $b): array {
        $rows = count($a); $cols = count($b[0]); $inner = count($b); $result = [];
        for ($i = 0; $i < $rows; $i++) {
            $row = [];
            for ($j = 0; $j < $cols; $j++) {
                $sum = 0; for ($k = 0; $k < $inner; $k++) $sum += $a[$i][$k] * $b[$k][$j];
                $row[] = $sum;
            }
            $result[] = $row;
        }
        return $result;
    };
    $matC = $matMul([[1,2,3],[4,5,6],[7,8,9]], [[9,8,7],[6,5,4],[3,2,1]]);
    for ($mi = 0; $mi < 20; $mi++) $matC = $matMul($matC, [[9,8,7],[6,5,4],[3,2,1]]);

    $text = "The quick brown fox jumps over the lazy dog. The dog barked loudly.";
    $words = explode(' ', $text);
    array_map('strtoupper', $words);
    preg_replace('/the/i', 'A', $text);
    preg_match_all('/\b\w{4}\b/', $text, $matches);

    $pipeline = array_reduce(
        array_map(fn($x) => $x + 1,
            array_filter(array_map(fn($x) => $x * $x, range(0, 199)), fn($x) => $x % 3 === 0)),
        fn($acc, $x) => $acc + $x, 0);

    $vectors = [];
    for ($vi = 0; $vi < 100; $vi++) $vectors[] = ['x' => $vi, 'y' => $vi * 2];
    array_sum(array_map(fn($v) => $v['x'] * $v['x'] + $v['y'] * $v['y'], $vectors));

    $parsed = array_map(function ($rec) {
        $obj = [];
        foreach (explode(',', $rec) as $f) { [$k, $v] = explode(':', $f); $obj[$k] = $v; }
        return $obj;
    }, explode(';', "name:Alice,age:30;name:Bob,age:25;name:Carol,age:35"));

    $histogram = array_count_values(str_split("aabbbccccdddddeeeee"));

    $makeTree = function (int $d) use (&$makeTree): ?array {
        if ($d <= 0) return ['value' => 1, 'left' => null, 'right' => null];
        return ['value' => $d, 'left' => $makeTree($d - 1), 'right' => $makeTree($d - 1)];
    };
    $sumTree = function (?array $n) use (&$sumTree): int {
        if ($n === null) return 0;
        return $n['value'] + $sumTree($n['left']) + $sumTree($n['right']);
    };
    $treeSum = $sumTree($makeTree(8));

    return [
        'primeCount' => count($primes), 'fib30' => $fib30,
        'sortedFirst' => $sorted[0], 'sortedLast' => end($sorted),
        'pipeline' => $pipeline, 'treeSum' => $treeSum,
        'parsedCount' => count($parsed),
    ];
}

function php_object_benchmark(): array {
    $acc = new Account('Alice', 1000);
    $acc2 = new Account('Bob', 500);
    $acc->deposit(100); $acc->deposit(250); $acc->withdraw(75); $acc->withdraw(50);
    for ($i = 0; $i < 100; $i++) { $acc->deposit(10); $acc->withdraw(5); }
    $acc->transfer($acc2, 50);
    return ['endBal' => $acc->balance, 'acc2Bal' => $acc2->balance, 'logSize' => $acc->getLogSize()];
}

// ── Benchmark harness ──

function benchExec(Engine $engine, string $source, int $iters): array {
    $compiled = $engine->compile($source);
    $engine->run($compiled);
    $t0 = hrtime(true);
    for ($i = 0; $i < $iters; $i++) $result = $engine->run($compiled);
    return ['ms' => (hrtime(true) - $t0) / 1e6 / $iters, 'result' => $result];
}

function benchTranspile(Engine $engine, string $source, int $iters): array {
    $tr = $engine->transpile($source);
    $engine->runTranspiled($tr);
    $t0 = hrtime(true);
    for ($i = 0; $i < $iters; $i++) $result = $engine->runTranspiled($tr);
    return ['ms' => (hrtime(true) - $t0) / 1e6 / $iters, 'result' => $result];
}

function measurePeakMemory(callable $fn): int {
    $fn();
    gc_collect_cycles();
    memory_reset_peak_usage();
    $m0 = memory_get_peak_usage();
    $fn();
    $m1 = memory_get_peak_usage();
    gc_collect_cycles();
    return max(0, $m1 - $m0);
}

// ════════════════════════════════════════════════════════════════
// Run benchmarks
// ════════════════════════════════════════════════════════════════

$phpEngine = new Engine(false);
$nativeEngine = $hasNative ? new Engine(true) : null;
$vmEngine = new Engine(false); // For combined workload (tests serialization)

// Per-benchmark data
$rows = [];
$totals = ['vm' => 0, 'tr' => 0, 'native' => 0];

foreach ($benchmarks as $name => $source) {
    $vm = benchExec($phpEngine, $source, $iterations);
    $tr = benchTranspile($phpEngine, $source, $iterations);
    $row = ['name' => $name, 'vm' => $vm['ms'], 'tr' => $tr['ms'], 'native' => null];
    $totals['vm'] += $vm['ms'];
    $totals['tr'] += $tr['ms'];

    if ($nativeEngine) {
        $native = benchExec($nativeEngine, $source, $iterations);
        $row['native'] = $native['ms'];
        $totals['native'] += $native['ms'];

        if ($vm['result'] !== $native['result'] && !is_float($vm['result'])) {
            $row['mismatch'] = true;
        }
    }
    $rows[] = $row;
}

// Combined workload execution modes
$compiled = $vmEngine->compile($combinedScript);
$vmEngine->run($compiled);
$transpiled = $vmEngine->transpile($combinedScript);
$vmEngine->evalTranspiled($transpiled);
php_benchmark();

$modes = [];
$n = 50;

$t = hrtime(true); for ($i = 0; $i < $n; $i++) $vmEngine->compile($combinedScript);
$modes['compile'] = (hrtime(true) - $t) / 1e6 / $n;

$t = hrtime(true); for ($i = 0; $i < $n; $i++) $result = $vmEngine->run($compiled);
$modes['vm_exec'] = (hrtime(true) - $t) / 1e6 / $n;

$serialized = serialize($compiled);
unserialize($serialized);
$t = hrtime(true); for ($i = 0; $i < $n; $i++) $vmEngine->run(unserialize($serialized));
$modes['cached'] = (hrtime(true) - $t) / 1e6 / $n;

$t = hrtime(true); for ($i = 0; $i < $n; $i++) $vmEngine->evalTranspiled($transpiled);
$trResult = $vmEngine->evalTranspiled($transpiled);
$modes['tr_exec'] = (hrtime(true) - $t) / 1e6 / $n;

$trFile = $vmEngine->saveTranspiled($transpiled, sys_get_temp_dir() . '/scriptlite_bench_tr.php');
include $trFile;
$t = hrtime(true); for ($i = 0; $i < $n; $i++) include $trFile;
$modes['tr_file'] = (hrtime(true) - $t) / 1e6 / $n;
@unlink($trFile);

$t = hrtime(true); for ($i = 0; $i < $n; $i++) php_benchmark();
$phpResult = php_benchmark();
$modes['native'] = (hrtime(true) - $t) / 1e6 / $n;

// Object interop
$objAcc = new Account('Alice', 1000); $objAcc2 = new Account('Bob', 500);
$vmEngine->eval($objScript, ['acc' => $objAcc, 'acc2' => $objAcc2]);

$t = hrtime(true);
for ($i = 0; $i < $n; $i++) {
    $objAcc = new Account('Alice', 1000); $objAcc2 = new Account('Bob', 500);
    $objResult = $vmEngine->eval($objScript, ['acc' => $objAcc, 'acc2' => $objAcc2]);
}
$modes['obj_vm'] = (hrtime(true) - $t) / 1e6 / $n;

$objTranspiled = $vmEngine->transpile($objScript, ['acc' => $objAcc, 'acc2' => $objAcc2]);
$objAcc = new Account('Alice', 1000); $objAcc2 = new Account('Bob', 500);
$vmEngine->evalTranspiled($objTranspiled, ['acc' => $objAcc, 'acc2' => $objAcc2]);

$t = hrtime(true);
for ($i = 0; $i < $n; $i++) {
    $objAcc = new Account('Alice', 1000); $objAcc2 = new Account('Bob', 500);
    $objTrResult = $vmEngine->evalTranspiled($objTranspiled, ['acc' => $objAcc, 'acc2' => $objAcc2]);
}
$modes['obj_tr'] = (hrtime(true) - $t) / 1e6 / $n;

php_object_benchmark();
$t = hrtime(true);
for ($i = 0; $i < $n; $i++) $phpObjResult = php_object_benchmark();
$modes['obj_php'] = (hrtime(true) - $t) / 1e6 / $n;

// Memory
$memVm     = measurePeakMemory(fn() => $vmEngine->run($compiled));
$memTr     = measurePeakMemory(fn() => $vmEngine->evalTranspiled($transpiled));
$memNative = measurePeakMemory(fn() => php_benchmark());

// Verify
$match = ($result['primeCount'] === $phpResult['primeCount']
    && $result['fib30'] === $phpResult['fib30']
    && $result['treeSum'] === $phpResult['treeSum']);
$trMatch = ($trResult['primeCount'] === $result['primeCount']
    && $trResult['fib30'] === $result['fib30']
    && $trResult['treeSum'] === $result['treeSum']);
$objMatch = ((float) $objResult['endBal'] === (float) $phpObjResult['endBal']
    && (float) $objResult['acc2Bal'] === (float) $phpObjResult['acc2Bal']);
$objTrMatch = ((float) $objTrResult['endBal'] === (float) $phpObjResult['endBal']
    && (float) $objTrResult['acc2Bal'] === (float) $phpObjResult['acc2Bal']);
$allOk = $match && $trMatch && $objMatch && $objTrMatch;

// ════════════════════════════════════════════════════════════════
// Output
// ════════════════════════════════════════════════════════════════

echo "\n";
echo "  ScriptLite Benchmark\n";
echo "  PHP " . PHP_VERSION . " | {$iterations} iterations | C extension (ScriptLiteExt): " . ($hasNative ? 'loaded' : 'not loaded') . "\n";
echo "\n";

// ── KPIs ──
echo "  KEY RESULTS\n";
echo "  " . str_repeat('─', 60) . "\n";

if ($hasNative) {
    $vmVsNative = $totals['vm'] / $totals['native'];
    $trVsNative = $totals['tr'] / $totals['native'];
    printf("  C ext vs PHP VM:     %5.0fx faster   (%.1fms vs %.1fms)\n",
        $vmVsNative, $totals['native'], $totals['vm']);
    printf("  C ext vs Transpiler: %5.1fx faster   (%.1fms vs %.1fms)\n",
        $trVsNative, $totals['native'], $totals['tr']);
}
printf("  Transpiler vs VM:    %5.1fx faster   (%.1fms vs %.1fms)\n",
    $totals['vm'] / $totals['tr'], $totals['tr'], $totals['vm']);
printf("  VM vs native PHP:    %5.0fx overhead (%.1fms vs %.1fms)\n",
    $modes['vm_exec'] / $modes['native'], $modes['vm_exec'], $modes['native']);
printf("  Transpiler vs PHP:   %5.1fx overhead (%.1fms vs %.1fms)\n",
    $modes['tr_exec'] / $modes['native'], $modes['tr_exec'], $modes['native']);
echo "  Correctness:         " . ($allOk ? 'ALL MATCH' : 'MISMATCH') . "\n";
echo "\n";

// ── Per-benchmark table ──
echo "  PER-BENCHMARK BREAKDOWN (exec time, pre-compiled)\n";
echo "  " . str_repeat('─', 76) . "\n";

if ($hasNative) {
    printf("  %-18s %10s %10s %10s %10s %10s\n",
        '', 'PHP VM', 'Transpiler', 'C Ext', 'C/VM', 'C/Tr');
    echo "  " . str_repeat('─', 76) . "\n";

    foreach ($rows as $row) {
        $sVm = $row['vm'] / $row['native'];
        $sTr = $row['tr'] / $row['native'];
        printf("  %-18s %8.2fms %8.2fms %8.2fms %7.0fx %7.1fx\n",
            $row['name'], $row['vm'], $row['tr'], $row['native'], $sVm, $sTr);
        if (!empty($row['mismatch'])) echo "  *** RESULT MISMATCH\n";
    }

    echo "  " . str_repeat('─', 76) . "\n";
    printf("  %-18s %8.1fms %8.1fms %8.1fms %7.0fx %7.1fx\n",
        'TOTAL',
        $totals['vm'], $totals['tr'], $totals['native'],
        $totals['vm'] / $totals['native'],
        $totals['tr'] / $totals['native']);
} else {
    printf("  %-18s %10s %10s\n", '', 'PHP VM', 'Transpiler');
    echo "  " . str_repeat('─', 46) . "\n";

    foreach ($rows as $row) {
        printf("  %-18s %8.2fms %8.2fms\n", $row['name'], $row['vm'], $row['tr']);
    }

    echo "  " . str_repeat('─', 46) . "\n";
    printf("  %-18s %8.1fms %8.1fms\n", 'TOTAL', $totals['vm'], $totals['tr']);
}

echo "\n";

// ── Execution modes ──
echo "  EXECUTION MODES (combined 10-workload script, {$n} iterations)\n";
echo "  " . str_repeat('─', 60) . "\n";
printf("  VM (compile+execute):          %8.2f ms\n", $modes['compile'] + $modes['vm_exec']);
printf("  VM (pre-compiled):             %8.2f ms\n", $modes['vm_exec']);
printf("  VM (unserialize+execute):      %8.2f ms\n", $modes['cached']);
printf("  Transpiler (eval):             %8.2f ms\n", $modes['tr_exec']);
printf("  Transpiler (cached file):      %8.2f ms\n", $modes['tr_file']);
printf("  Native PHP:                    %8.2f ms\n", $modes['native']);
echo "\n";

// ── Object interop ──
echo "  PHP OBJECT INTEROP (property r/w, method calls, 200 ops)\n";
echo "  " . str_repeat('─', 60) . "\n";
printf("  VM:           %8.2f ms  (%5.0fx vs native)\n", $modes['obj_vm'], $modes['obj_vm'] / $modes['obj_php']);
printf("  Transpiler:   %8.2f ms  (%5.1fx vs native)\n", $modes['obj_tr'], $modes['obj_tr'] / $modes['obj_php']);
printf("  Native PHP:   %8.2f ms\n", $modes['obj_php']);
echo "\n";

// ── Memory ──
printf("  MEMORY (peak per run)     VM: %.0f KB   Transpiler: %.0f KB   PHP: %.0f KB\n",
    $memVm / 1024, $memTr / 1024, $memNative / 1024);
printf("  BYTECODE SIZE             Serialized: %.1f KB   Transpiled: %.1f KB\n",
    strlen($serialized) / 1024, strlen($transpiled) / 1024);
echo "\n";

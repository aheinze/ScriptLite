<?php

declare(strict_types=1);

namespace ScriptLite\Transpiler\Runtime;

/**
 * Static runtime operators for transpiled JS -> PHP code.
 *
 * Correctness beats micro-optimizations here. These helpers exist because
 * PHP's native operators diverge from ECMAScript in important edge cases.
 */
final class Ops
{
    private const CONSTRUCTOR_MARKER = '__scriptlite_ctor';

    /**
     * JS ToBoolean: determines truthiness with JS semantics.
     */
    public static function toBoolean(mixed $v): bool
    {
        return $v !== false
            && $v !== 0
            && $v !== 0.0
            && $v !== ''
            && $v !== null
            && (!is_float($v) || !is_nan($v));
    }

    /**
     * Values that ScriptLite is allowed to invoke from transpiled code.
     *
     * PHP considers strings like "system" and arrays like [ClassName, "method"]
     * callable. Those are PHP internals, not ScriptLite functions, so never use
     * is_callable() for sandboxed JS calls.
     */
    public static function isFunction(mixed $value): bool
    {
        return $value instanceof JSFunction || $value instanceof \Closure;
    }

    public static function assertFunction(mixed $value): mixed
    {
        if (!self::isFunction($value)) {
            throw new \RuntimeException('TypeError: value is not a function');
        }

        return $value;
    }

    /**
     * JS function call with an explicit callable allow-list.
     *
     * @param list<mixed> $args
     */
    public static function call(mixed $callee, array $args): mixed
    {
        return self::assertFunction($callee)(...$args);
    }

    /**
     * JS ToNumber: coerce any value to int|float.
     */
    public static function toNumber(mixed $v): int|float
    {
        if (is_int($v) || is_float($v)) {
            return $v;
        }
        if (is_bool($v)) {
            return $v ? 1 : 0;
        }
        if ($v === null) {
            return 0;
        }
        if (is_string($v)) {
            $t = trim($v);
            if ($t === '') {
                return 0;
            }
            return is_numeric($t) ? $t + 0 : NAN;
        }
        return NAN;
    }

    /**
     * JS ToInt32: the operand conversion of the bitwise operators.
     *
     * NaN and ±Infinity become 0 and everything else wraps modulo 2^32 into the
     * signed 32-bit range. Never uses a raw (int) cast on a value that may not
     * fit, because PHP 8.5 warns on those.
     *
     * Shared by the VM and the transpiler runtime.
     */
    public static function toInt32(mixed $v): int
    {
        if (is_int($v)) {
            if ($v >= -0x80000000 && $v <= 0x7FFFFFFF) {
                return $v;
            }
            $v &= 0xFFFFFFFF;
            return $v > 0x7FFFFFFF ? $v - 0x100000000 : $v;
        }

        $n = is_float($v) ? $v : (float) self::toNumber($v);
        if (is_nan($n) || is_infinite($n)) {
            return 0;
        }
        if ($n >= -2147483648.0 && $n < 2147483648.0) {
            return (int) $n;
        }

        // Integral doubles are exact under fmod, so this is the true modulo 2^32.
        $n = fmod($n < 0 ? ceil($n) : floor($n), 4294967296.0);
        if ($n < 0) {
            $n += 4294967296.0;
        }
        $i = (int) $n;
        return $i > 0x7FFFFFFF ? $i - 0x100000000 : $i;
    }

    /**
     * JS ToUint32 (operand of >>> and of shift counts).
     */
    public static function toUint32(mixed $v): int
    {
        return self::toInt32($v) & 0xFFFFFFFF;
    }

    /**
     * JS ToIntegerOrInfinity as a PHP int: for index, position and count arguments.
     *
     * NaN becomes 0, finite values truncate toward zero, and ±Infinity (or any
     * magnitude beyond 2^53) clamps to ±2^53. That is past every real string or
     * array length, so callers that clamp to the length get the JS result.
     */
    public static function toInteger(mixed $v): int
    {
        if (is_int($v)) {
            return $v;
        }

        $n = is_float($v) ? $v : (float) self::toNumber($v);
        if (is_nan($n)) {
            return 0;
        }
        if ($n >= 9007199254740992.0) {
            return 9007199254740992;
        }
        if ($n <= -9007199254740992.0) {
            return -9007199254740992;
        }
        return (int) $n;
    }

    /**
     * Result of a JS sort comparator as -1/0/1 (NaN counts as 0, fractions keep their sign).
     */
    public static function compareResult(mixed $v): int
    {
        $n = is_int($v) || is_float($v) ? $v : self::toNumber($v);
        return $n > 0 ? 1 : ($n < 0 ? -1 : 0);
    }

    /**
     * Number -> string for the cases PHP spells differently (NaN, Infinity).
     */
    public static function numberToString(int|float $v): string
    {
        if (is_float($v)) {
            if (is_nan($v)) {
                return 'NaN';
            }
            if (is_infinite($v)) {
                return $v > 0 ? 'Infinity' : '-Infinity';
            }
        }
        return (string) $v;
    }

    /**
     * Throw a JS RangeError (same exception shape as the other runtime errors).
     */
    public static function rangeError(string $message): never
    {
        throw new \RuntimeException('RangeError: ' . $message);
    }

    /**
     * JS ToString / ToPrimitive -> string.
     *
     * Safe conversion for any PHP type — never throws.
     */
    public static function toString(mixed $v): string
    {
        if (is_string($v)) {
            return $v;
        }
        if ($v === null) {
            return 'null';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_int($v) || is_float($v)) {
            return self::numberToString($v);
        }
        if ($v instanceof JSFunction || $v instanceof \Closure) {
            return 'function() { [native code] }';
        }
        if ($v instanceof TrDate) {
            return (string) $v;
        }
        if ($v instanceof JSObject) {
            return '[object Object]';
        }
        if (is_array($v)) {
            if (($v['__re'] ?? false) === true) {
                return '/' . ($v['source'] ?? '') . '/' . ($v['flags'] ?? '');
            }

            return implode(',', array_map([self::class, 'toString'], $v));
        }
        if (is_object($v) && method_exists($v, '__toString')) {
            return (string) $v;
        }
        if (is_object($v)) {
            return '[object Object]';
        }

        return (string) $v;
    }

    /**
     * JS String.prototype.slice(): handles negative indices per ES spec.
     */
    public static function strSlice(string $s, int $start, ?int $end = null): string
    {
        $len = mb_strlen($s, 'UTF-8');
        if ($start < 0) {
            $start = max(0, $len + $start);
        }
        if ($end === null) {
            return mb_substr($s, $start, null, 'UTF-8');
        }
        if ($end < 0) {
            $end = max(0, $len + $end);
        }
        return mb_substr($s, $start, max(0, $end - $start), 'UTF-8');
    }

    /**
     * JS + operator: ToPrimitive then string-concat or numeric-add.
     */
    public static function add(mixed $a, mixed $b): mixed
    {
        // Fast path: both numeric (covers ~80% of calls in typical code)
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return $a + $b;
        }

        $a = self::toPrimitive($a);
        $b = self::toPrimitive($b);

        return (is_string($a) || is_string($b))
            ? self::toString($a) . self::toString($b)
            : self::toNumber($a) + self::toNumber($b);
    }

    /**
     * JS / operator: returns NAN/INF instead of throwing DivisionByZeroError.
     */
    public static function div(mixed $a, mixed $b): int|float
    {
        $a = self::toNumber($a);
        $b = self::toNumber($b);
        if ($b == 0) {
            if ($a == 0 || is_nan($a)) {
                return NAN;
            }
            return $a > 0 ? INF : -INF;
        }
        return $a / $b;
    }

    /**
     * JS % operator: returns NAN instead of throwing DivisionByZeroError.
     */
    public static function mod(mixed $a, mixed $b): int|float
    {
        $a = self::toNumber($a);
        $b = self::toNumber($b);
        if ($b == 0 || is_nan($a) || is_nan($b)) {
            return NAN;
        }
        if (is_int($a) && is_int($b)) {
            return $a % $b;
        }
        return fmod((float) $a, (float) $b);
    }

    /**
     * JS strict equality (===).
     *
     * Important differences from PHP:
     * - JS has a single Number type, so 1 === 1.0 is true
     * - object/function equality is by identity
     * - NaN is never equal to anything, including itself
     *
     * Note: this engine still represents JS arrays as PHP arrays, so array
     * identity remains an approximation unless arrays are boxed too.
     */
    public static function strictEquals(mixed $a, mixed $b): bool
    {
        // Fast path: both ints (no NaN possible)
        if (is_int($a) && is_int($b)) {
            return $a === $b;
        }

        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return !is_nan((float) $a) && !is_nan((float) $b) && ((float) $a == (float) $b);
        }

        if (is_string($a) && is_string($b)) {
            return $a === $b;
        }

        if (is_bool($a) && is_bool($b)) {
            return $a === $b;
        }

        if ($a === null || $b === null) {
            return $a === null && $b === null;
        }

        if (self::isObjectLike($a) || self::isObjectLike($b)) {
            return $a === $b;
        }

        return $a === $b;
    }

    /**
     * JS loose equality (==).
     *
     * This intentionally follows the ES coercion model, not PHP's.
     */
    public static function looseEquals(mixed $a, mixed $b): bool
    {
        if (self::strictEquals($a, $b)) {
            return true;
        }

        // This engine currently collapses undefined -> null in the transpiler
        // path, so null/null is the shared nullish case.
        if ($a === null || $b === null) {
            return false;
        }

        if (is_bool($a)) {
            return self::looseEquals(self::toNumber($a), $b);
        }
        if (is_bool($b)) {
            return self::looseEquals($a, self::toNumber($b));
        }

        if (is_string($a) && (is_int($b) || is_float($b))) {
            return self::numericEquals(self::toNumber($a), $b);
        }
        if ((is_int($a) || is_float($a)) && is_string($b)) {
            return self::numericEquals($a, self::toNumber($b));
        }

        if (self::isObjectLike($a) && !self::isObjectLike($b)) {
            return self::looseEquals(self::toPrimitive($a), $b);
        }
        if (!self::isObjectLike($a) && self::isObjectLike($b)) {
            return self::looseEquals($a, self::toPrimitive($b));
        }

        return false;
    }

    /**
     * JS `in`: own properties + prototype chain for JS objects.
     */
    public static function hasProperty(mixed $target, mixed $key): bool
    {
        $k = self::toPropertyKey($key);

        if ($target instanceof JSObject) {
            return self::hasObjectProperty($target, $k);
        }

        if ($target instanceof JSFunction) {
            return array_key_exists($k, $target->properties);
        }

        if (is_array($target)) {
            return array_key_exists($k, $target);
        }

        if ($target instanceof \ArrayAccess) {
            return $target->offsetExists($k);
        }

        return false;
    }

    /**
     * JS Object.prototype.hasOwnProperty.
     */
    public static function hasOwn(mixed $target, mixed $key): bool
    {
        $k = self::toPropertyKey($key);

        if ($target instanceof JSObject) {
            return array_key_exists($k, $target->properties);
        }

        if ($target instanceof JSFunction) {
            return array_key_exists($k, $target->properties);
        }

        if (is_array($target)) {
            return array_key_exists($k, $target);
        }

        if ($target instanceof \ArrayAccess) {
            return $target->offsetExists($k);
        }

        return false;
    }

    /**
     * JS property read with prototype lookup for boxed objects.
     */
    public static function getProp(mixed $target, mixed $key): mixed
    {
        return self::getNamedProp($target, self::toPropertyKey($key));
    }

    /**
     * Computed read obj[key] for keys that are not a PHP int/string (NaN, 1.5, true, ...).
     */
    public static function getIndex(mixed $target, mixed $key): mixed
    {
        if (is_string($target)) {
            $k = self::arrayKey($key);
            return is_int($k) && $k >= 0 && $k < strlen($target) ? $target[$k] : null;
        }

        return self::getProp($target, $key);
    }

    /**
     * Fast path for dot-property reads where the key is already a JS string.
     */
    public static function getNamedProp(mixed $target, string $key): mixed
    {
        if ($target instanceof JSObject) {
            return self::getObjectProperty($target, $key);
        }

        if ($target instanceof JSFunction) {
            return array_key_exists($key, $target->properties)
                ? $target->properties[$key]
                : null;
        }

        if (is_array($target)) {
            return array_key_exists($key, $target)
                ? $target[$key]
                : null;
        }

        if ($target instanceof \ArrayAccess) {
            return $target->offsetExists($key) ? $target[$key] : null;
        }

        if (is_object($target) && isset($target->{$key})) {
            return $target->{$key};
        }

        return null;
    }

    /**
     * JS property write with JS key coercion.
     */
    public static function setProp(mixed $target, mixed $key, mixed $value): mixed
    {
        return self::setNamedProp($target, self::toPropertyKey($key), $value);
    }

    /**
     * Fast path for dot-property writes where the key is already a JS string.
     */
    public static function setNamedProp(mixed $target, string $key, mixed $value): mixed
    {
        if ($target instanceof JSObject) {
            $target->properties[$key] = $value;
            return $value;
        }

        if ($target instanceof JSFunction) {
            $target->properties[$key] = $value;
            return $value;
        }

        if (is_array($target)) {
            $target[$key] = $value;
            return $value;
        }

        if ($target instanceof \ArrayAccess) {
            $target[$key] = $value;
            return $value;
        }

        if (is_object($target)) {
            $target->{$key} = $value;
        }

        return $value;
    }

    /**
     * JS ++/-- on a computed property, evaluating the key once.
     */
    public static function updateProp(mixed &$target, mixed $key, bool $increment, bool $prefix): mixed
    {
        return self::updateNamedProp($target, self::toPropertyKey($key), $increment, $prefix);
    }

    /**
     * JS ++/-- on a named property, evaluating the property name once.
     */
    public static function updateNamedProp(mixed &$target, string $key, bool $increment, bool $prefix): mixed
    {
        $old = match (true) {
            $target instanceof JSObject => self::getObjectProperty($target, $key),
            $target instanceof JSFunction => $target->properties[$key] ?? null,
            is_array($target) => array_key_exists($key, $target) ? $target[$key] : null,
            $target instanceof \ArrayAccess => $target->offsetExists($key) ? $target[$key] : null,
            is_object($target) => isset($target->{$key}) ? $target->{$key} : null,
            default => null,
        };

        $new = self::toNumber($old) + ($increment ? 1 : -1);

        if ($target instanceof JSObject) {
            $target->properties[$key] = $new;
        } elseif ($target instanceof JSFunction) {
            $target->properties[$key] = $new;
        } elseif (is_array($target)) {
            $target[$key] = $new;
        } elseif ($target instanceof \ArrayAccess) {
            $target[$key] = $new;
        } elseif (is_object($target)) {
            $target->{$key} = $new;
        }

        return $prefix ? $new : $old;
    }

    /**
     * Create a JS object literal with JS property-key coercion.
     *
     * @param list<array{0:mixed,1:mixed}> $entries
     */
    public static function objectLiteral(array $entries): JSObject
    {
        $object = new JSObject();
        foreach ($entries as [$key, $value]) {
            $object->properties[self::toPropertyKey($key)] = $value;
        }
        return $object;
    }

    /**
     * JS Object.keys().
     *
     * @return list<string>
     */
    public static function keys(mixed $target): array
    {
        if ($target instanceof JSObject) {
            return array_keys($target->properties);
        }

        if (is_array($target)) {
            return array_map('strval', array_keys($target));
        }

        return [];
    }

    /**
     * JS Object.values().
     *
     * @return list<mixed>
     */
    public static function values(mixed $target): array
    {
        if ($target instanceof JSObject) {
            return array_values($target->properties);
        }

        if (is_array($target)) {
            return array_values($target);
        }

        return [];
    }

    /**
     * JS Object.entries().
     *
     * @return list<array{0:string,1:mixed}>
     */
    public static function entries(mixed $target): array
    {
        if ($target instanceof JSObject) {
            $entries = [];
            foreach ($target->properties as $key => $value) {
                $entries[] = [$key, $value];
            }
            return $entries;
        }

        if (is_array($target)) {
            $entries = [];
            foreach ($target as $key => $value) {
                $entries[] = [(string) $key, $value];
            }
            return $entries;
        }

        return [];
    }

    /**
     * JS Object.assign().
     */
    public static function objectAssign(mixed $target, mixed ...$sources): mixed
    {
        if ($target instanceof JSObject) {
            foreach ($sources as $source) {
                foreach (self::entries($source) as [$key, $value]) {
                    $target->properties[$key] = $value;
                }
            }
            return $target;
        }

        if (is_array($target)) {
            foreach ($sources as $source) {
                foreach (self::entries($source) as [$key, $value]) {
                    $target[$key] = $value;
                }
            }
            return $target;
        }

        return $target;
    }

    /**
     * Keys used by `for...in` (own enumerable keys, then prototype chain).
     *
     * @return list<string>
     */
    public static function forInKeys(mixed $target): array
    {
        if ($target instanceof JSObject) {
            return self::collectObjectKeys($target, true);
        }

        return self::keys($target);
    }

    /**
     * JS `instanceof`, prototype-chain based.
     */
    public static function instanceOf(mixed $value, mixed $constructor): bool
    {
        if (!$value instanceof JSObject || !$constructor instanceof JSFunction) {
            return false;
        }

        $needle = $constructor->getPrototype();
        for ($proto = $value->getPrototype(); $proto !== null; $proto = $proto->getPrototype()) {
            if ($proto === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * JS `new`.
     *
     * If the constructor returns a non-object, the newly created object wins.
     * If it returns an object, that explicit object is returned unchanged.
     *
     * @param list<mixed> $args
     */
    public static function construct(mixed $callee, array $args): mixed
    {
        if ($callee instanceof JSFunction) {
            $fallback = new JSObject([], $callee->getPrototype());
            $result = $callee(...$args);
            if ($result instanceof JSObject && array_key_exists(self::CONSTRUCTOR_MARKER, $result->properties)) {
                unset($result->properties[self::CONSTRUCTOR_MARKER]);
                $result->prototype = $callee->getPrototype();
                return $result;
            }
            if (self::isObjectLike($result)) {
                return $result;
            }
            return $fallback;
        }

        if ($callee instanceof \Closure) {
            $result = $callee(...$args);
            if (self::isObjectLike($result)) {
                return $result;
            }
            return new JSObject();
        }

        throw new \RuntimeException('TypeError: value is not a constructor');
    }

    /**
     * Constructor-return check: arrays and objects count as objects.
     */
    public static function isObjectLike(mixed $value): bool
    {
        return is_array($value) || is_object($value);
    }

    /**
     * Runtime `.length` accessor with JS object semantics.
     */
    public static function getLength(mixed $value): mixed
    {
        if (is_array($value)) {
            return count($value);
        }
        if (is_string($value)) {
            return mb_strlen($value, 'UTF-8');
        }
        if ($value instanceof JSObject) {
            return self::getObjectProperty($value, 'length');
        }
        if ($value instanceof \Countable) {
            return count($value);
        }

        return null;
    }

    /**
     * JS RegExp.prototype.exec(): returns match array with `index` property.
     */
    public static function regexExec(string $pcre, string $str): ?array
    {
        if (!preg_match($pcre, $str, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $result = [];
        foreach ($m as $k => $v) {
            if (is_int($k)) {
                $result[$k] = $v[0];
            }
        }
        $result['index'] = $m[0][1];
        return $result;
    }

    /**
     * Create a regex array from pattern and flags (for `new RegExp(pattern, flags)`).
     */
    public static function createRegex(string $pattern, string $flags = ''): array
    {
        $pcreFlags = '';
        if (str_contains($flags, 'i')) {
            $pcreFlags .= 'i';
        }
        if (str_contains($flags, 's')) {
            $pcreFlags .= 's';
        }
        if (str_contains($flags, 'm')) {
            $pcreFlags .= 'm';
        }
        $pcre = '/' . str_replace('/', '\\/', $pattern) . '/' . $pcreFlags . 'u';
        return [
            '__re' => true,
            'pcre' => $pcre,
            'source' => $pattern,
            'flags' => $flags,
            'g' => str_contains($flags, 'g'),
            'global' => str_contains($flags, 'g'),
            'ignoreCase' => str_contains($flags, 'i'),
        ];
    }

    /**
     * JS parseInt with optional radix support.
     */
    public static function parseInt(mixed $str, mixed $radix = null): int|float
    {
        $s = trim(is_string($str) ? $str : self::toString($str));
        if ($s === '') {
            return NAN;
        }
        // JS: radix = ToInt32(radix); 0 (also NaN/undefined) means "auto", i.e. 10 or 16 for 0x.
        $r = $radix === null ? 0 : self::toInt32($radix);
        if ($r !== 0 && $r !== 10) {
            if ($r < 2 || $r > 36) {
                return NAN;
            }
            if ($r === 16 && (str_starts_with($s, '0x') || str_starts_with($s, '0X'))) {
                $s = substr($s, 2);
            }
            $result = @intval($s, $r);
            if ($result === 0 && !preg_match('/^[+-]?0+$/', $s)) {
                $chars = '0-9a-' . chr(ord('a') + min($r, 36) - 11);
                if ($r <= 10) {
                    $chars = '0-' . chr(ord('0') + $r - 1);
                }
                if (!preg_match('/^[+-]?[' . $chars . ']/i', $s)) {
                    return NAN;
                }
            }
            return $result;
        }
        if (str_starts_with($s, '0x') || str_starts_with($s, '0X')) {
            return intval($s, 16);
        }
        // Leading decimal digits only ("1e3" → 1, "1.9" → 1), like JS. Digit runs too long
        // for an int become a float instead of an (int) cast that PHP 8.5 warns about.
        if (!preg_match('/^([+-]?)(\d+)/', $s, $m)) {
            return NAN;
        }
        $digits = ltrim($m[2], '0');
        if (strlen($digits) > 18) {
            return (float) ($m[1] . $digits);
        }
        return (int) ($m[1] . ($digits === '' ? '0' : $digits));
    }

    /**
     * JS Number.prototype.toFixed(digits).
     */
    public static function toFixed(mixed $n, mixed $digits = null): string
    {
        $d = $digits === null ? 0 : self::toInteger($digits);
        if ($d < 0 || $d > 100) {
            self::rangeError('toFixed() digits argument must be between 0 and 100');
        }
        $x = (float) self::toNumber($n);
        if (!is_finite($x) || abs($x) >= 1e21) {
            return self::numberToString($x);
        }
        if ($d > 52) {
            return number_format($x, $d, '.', ''); // beyond sprintf()'s precision limit
        }

        // sprintf() is exactly rounded but breaks exact ties to even (12.5 -> "12"); JS picks the
        // larger magnitude (12.5 -> "13"). x is an exact tie at $d digits iff x = m * 2^e with odd m
        // and e === -($d + 1); m and e come straight from the IEEE 754 bits.
        if ($x == 0.0) {
            $x = 0.0; // -0 prints as "0"
        }
        $neg = $x < 0;
        $ax = abs($x);
        $bits = unpack('J', pack('E', $ax))[1];
        $expBits = ($bits >> 52) & 0x7FF;
        $m = $bits & 0xFFFFFFFFFFFFF;
        $e = -1074;
        if ($expBits !== 0) {
            $m |= 1 << 52;
            $e = $expBits - 1075;
        }
        $tie = false;
        if ($m !== 0) {
            while (($m & 1) === 0) {
                $m >>= 1;
                $e++;
            }
            $tie = $e === -($d + 1);
        }

        if (!$tie) {
            return ($neg ? '-' : '') . sprintf("%.{$d}F", $ax);
        }

        // Exact tie: with $d + 1 decimals the digits are exact and end in '5'; drop it, round up.
        $s = substr(sprintf('%.' . ($d + 1) . 'F', $ax), 0, $d === 0 ? -2 : -1);
        for ($i = strlen($s) - 1; $i >= 0; $i--) {
            if ($s[$i] === '.') {
                continue;
            }
            if ($s[$i] === '9') {
                $s[$i] = '0';
                continue;
            }
            $s[$i] = chr(ord($s[$i]) + 1);
            break;
        }
        if ($i < 0) {
            $s = '1' . $s;
        }
        return ($neg ? '-' : '') . $s;
    }

    /**
     * JS Number.prototype.toPrecision(digits); null digits means undefined (plain ToString).
     */
    public static function toPrecision(mixed $n, mixed $digits = null): string
    {
        $x = is_int($n) || is_float($n) ? $n : self::toNumber($n);
        if ($digits === null || (is_float($x) && !is_finite($x))) {
            return self::numberToString($x);
        }
        $p = self::toInteger($digits);
        if ($p < 1 || $p > 100) {
            self::rangeError('toPrecision() argument must be between 1 and 100');
        }
        return rtrim(rtrim(sprintf("%.{$p}g", (float) $x), '0'), '.');
    }

    /**
     * JS Number.prototype.toExponential(fractionDigits).
     */
    public static function toExponential(float $n, mixed $digits = null): string
    {
        $d = $digits === null ? 6 : self::toInteger($digits);
        if (!is_finite($n)) {
            return self::numberToString($n);
        }
        if ($d < 0 || $d > 100) {
            self::rangeError('toExponential() argument must be between 0 and 100');
        }
        $s = sprintf("%.{$d}e", $n);
        // PHP uses e+01 format, JS uses e+1 — normalize
        return preg_replace('/e([+-])0*(\d)/', 'e$1$2', $s);
    }

    /**
     * JS Number.prototype.toString(radix) for an explicit radix.
     */
    public static function numberToRadix(mixed $n, mixed $radix): string
    {
        $r = self::toInteger($radix);
        if ($r < 2 || $r > 36) {
            self::rangeError('toString() radix must be between 2 and 36');
        }
        $x = is_int($n) || is_float($n) ? $n : self::toNumber($n);
        if (is_float($x)) {
            if (!is_finite($x)) {
                return self::numberToString($x);
            }
            // Integer part only (fractional digits in other bases are not supported).
            $x = $x > -9.2233720368547758E18 && $x < 9.2233720368547758E18
                ? (int) $x
                : sprintf('%.0f', $x);
        }
        if (is_int($x)) {
            if ($r === 10) {
                return (string) $x;
            }
            return ($x < 0 ? '-' : '') . base_convert((string) abs($x), 10, $r);
        }
        return ($x[0] === '-' ? '-' : '') . base_convert(ltrim($x, '-'), 10, $r);
    }

    /** Longest string the string builders may produce (V8's limit), instead of exhausting memory. */
    private const MAX_STRING_LENGTH = 536870888;

    /**
     * JS String.prototype.repeat(count).
     */
    public static function strRepeat(string $s, mixed $count): string
    {
        $n = self::toInteger($count);
        if ($n < 0 || $n >= 9007199254740992) {
            self::rangeError('Invalid count value: ' . self::toString($count));
        }
        if ($n === 0 || $s === '') {
            return '';
        }
        if ($n > intdiv(self::MAX_STRING_LENGTH, strlen($s))) {
            self::rangeError('Invalid string length');
        }
        return str_repeat($s, $n);
    }

    /**
     * Target length for padStart()/padEnd(): ToIntegerOrInfinity, bounded like a JS engine.
     */
    public static function padLength(mixed $length): int
    {
        $n = self::toInteger($length);
        if ($n > self::MAX_STRING_LENGTH) {
            self::rangeError('Invalid string length');
        }
        return $n;
    }

    /**
     * Result of Math.floor/ceil/round/trunc: an int when it fits, else the float (NaN, ±Infinity, huge).
     */
    public static function integral(int|float $n): int|float
    {
        if (is_float($n) && $n > -9007199254740992.0 && $n < 9007199254740992.0) {
            return (int) $n;
        }
        return $n;
    }

    /**
     * JS Math.trunc().
     */
    public static function mathTrunc(mixed $n): int|float
    {
        $x = is_int($n) || is_float($n) ? $n : self::toNumber($n);
        if (is_int($x)) {
            return $x;
        }
        return self::integral($x < 0 ? ceil($x) : floor($x));
    }

    /**
     * JS Math.clz32().
     */
    public static function clz32(mixed $n): int
    {
        $u = self::toUint32($n);
        if ($u === 0) {
            return 32;
        }
        $count = 0;
        while (($u & 0x80000000) === 0) {
            $u <<= 1;
            $count++;
        }
        return $count;
    }

    /**
     * JS Array.prototype.join(separator): null/undefined elements become '', everything else ToString.
     */
    public static function join(mixed $arr, mixed $separator = null): string
    {
        $sep = $separator === null ? ',' : self::toString($separator);
        $parts = [];
        foreach (is_array($arr) ? $arr : [] as $v) {
            $parts[] = $v === null ? '' : self::toString($v);
        }
        return implode($sep, $parts);
    }

    /**
     * JS Array.prototype.slice(start, end) for PHP lists.
     */
    public static function arraySlice(array $arr, mixed $start = null, mixed $end = null): array
    {
        $len = count($arr);
        $s = self::toInteger($start);
        $s = $s < 0 ? max(0, $len + $s) : min($s, $len);
        $e = $end === null ? $len : self::toInteger($end);
        $e = $e < 0 ? max(0, $len + $e) : min($e, $len);
        return $e > $s ? array_slice($arr, $s, $e - $s) : [];
    }

    /**
     * JS Array.prototype.fill(value, start, end) — fills in place within the current length.
     */
    public static function arrayFill(array &$arr, mixed $value = null, mixed $start = null, mixed $end = null): array
    {
        $len = count($arr);
        $s = self::toInteger($start);
        $s = $s < 0 ? max(0, $len + $s) : min($s, $len);
        $e = $end === null ? $len : self::toInteger($end);
        $e = $e < 0 ? max(0, $len + $e) : min($e, $len);
        for ($i = $s; $i < $e; $i++) {
            $arr[$i] = $value;
        }
        return $arr;
    }

    /**
     * arrayFill() for temporaries that cannot be passed by reference.
     */
    public static function arrayFilled(array $arr, mixed $value = null, mixed $start = null, mixed $end = null): array
    {
        return self::arrayFill($arr, $value, $start, $end);
    }

    /**
     * JS .at() — negative-index-aware element access for strings and arrays.
     */
    public static function at(mixed $target, int $index): mixed
    {
        if (is_string($target)) {
            $len = mb_strlen($target, 'UTF-8');
            if ($index < 0) {
                $index += $len;
            }
            if ($index < 0 || $index >= $len) {
                return null;
            }
            return mb_substr($target, $index, 1, 'UTF-8');
        }
        if (is_array($target)) {
            $len = count($target);
            if ($index < 0) {
                $index += $len;
            }
            return $target[$index] ?? null;
        }
        return null;
    }

    /**
     * JS Object.is() — same-value equality (distinguishes +0/-0 and NaN===NaN).
     */
    public static function objectIs(mixed $a, mixed $b): bool
    {
        if (is_float($a) && is_float($b)) {
            if (is_nan($a) && is_nan($b)) {
                return true;
            }
            if ($a === 0.0 && $b === 0.0) {
                return (1 / $a) === (1 / $b);
            }
        }
        return $a === $b;
    }

    /**
     * JS Object.create() — create object with prototype.
     */
    public static function objectCreate(mixed $proto): JSObject
    {
        $obj = new JSObject();
        if ($proto instanceof JSObject) {
            $obj->prototype = $proto;
        }
        return $obj;
    }

    public static function jsonStringify(mixed $value): string|false
    {
        return json_encode(
            self::jsonNormalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    public static function jsonParse(string $json): mixed
    {
        return self::jsonHydrate(
            json_decode($json, true, 512, JSON_THROW_ON_ERROR)
        );
    }

    private static function numericEquals(int|float $a, int|float $b): bool
    {
        return !is_nan((float) $a) && !is_nan((float) $b) && ((float) $a == (float) $b);
    }

    private static function toPrimitive(mixed $value): mixed
    {
        if (is_scalar($value) || $value === null) {
            return $value;
        }

        return self::toString($value);
    }

    /**
     * Normalize a computed key for direct PHP array/ArrayAccess indexing.
     *
     * Ints and strings pass through untouched (hot path). Everything else uses
     * the JS property key, so 1.0 → 1, NaN → "NaN" and 1.5 → "1.5" instead of
     * PHP's implicit float→int truncation (which warns for NaN/Infinity on 8.5).
     */
    public static function arrayKey(mixed $key): int|string
    {
        if (is_int($key) || is_string($key)) {
            return $key;
        }
        if (is_float($key) && $key == floor($key) && $key > -9007199254740992.0 && $key < 9007199254740992.0) {
            return (int) $key;
        }

        return self::toString($key);
    }

    public static function toPropertyKey(mixed $key): string
    {
        if (is_int($key) || is_string($key)) {
            return (string) $key;
        }

        return self::toString($key);
    }

    private static function jsonNormalize(mixed $value): mixed
    {
        if (is_float($value) && !is_finite($value)) {
            return null; // JSON has no NaN/Infinity: JSON.stringify() writes null
        }

        if ($value instanceof JSObject) {
            $normalized = [];
            foreach ($value->properties as $key => $item) {
                $normalized[$key] = self::jsonNormalize($item);
            }
            return $normalized;
        }

        if ($value instanceof JSFunction || $value instanceof \Closure) {
            return null;
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::jsonNormalize($item);
            }
        }

        return $value;
    }

    private static function jsonHydrate(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::jsonHydrate($item);
            }
            return $value;
        }

        $object = new JSObject();
        foreach ($value as $key => $item) {
            $object->properties[(string) $key] = self::jsonHydrate($item);
        }
        return $object;
    }

    private static function hasObjectProperty(JSObject $target, string $key): bool
    {
        for ($object = $target; $object !== null; $object = $object->prototype) {
            if (array_key_exists($key, $object->properties)) {
                return true;
            }
        }

        return false;
    }

    private static function getObjectProperty(JSObject $target, string $key): mixed
    {
        for ($object = $target; $object !== null; $object = $object->prototype) {
            if (array_key_exists($key, $object->properties)) {
                return $object->properties[$key];
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function collectObjectKeys(JSObject $target, bool $includePrototype): array
    {
        $keys = [];
        $seen = [];

        for ($object = $target; $object !== null; $object = $includePrototype ? $object->prototype : null) {
            foreach ($object->properties as $key => $_) {
                if (!isset($seen[$key])) {
                    $keys[] = $key;
                    $seen[$key] = true;
                }
            }

            if (!$includePrototype) {
                break;
            }
        }

        return $keys;
    }
}

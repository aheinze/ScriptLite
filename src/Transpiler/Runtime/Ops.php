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
            return (string) $v;
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
        $s = is_string($str) ? trim($str) : (string) $str;
        if ($s === '') {
            return NAN;
        }
        if ($radix !== null && $radix !== 0 && $radix !== 10) {
            $r = (int) $radix;
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
        if (!is_numeric($s)) {
            if (preg_match('/^([+-]?\d+)/', $s, $m)) {
                return (int) $m[1];
            }
            return NAN;
        }
        return (int) $s;
    }

    /**
     * JS Number.prototype.toPrecision(digits).
     */
    public static function toPrecision(float $n, int $digits): string
    {
        if ($digits <= 0) {
            return (string) $n;
        }
        return rtrim(rtrim(sprintf("%.{$digits}g", $n), '0'), '.');
    }

    /**
     * JS Number.prototype.toExponential(fractionDigits).
     */
    public static function toExponential(float $n, mixed $digits = null): string
    {
        $d = $digits === null ? 6 : (int) $digits;
        $s = sprintf("%.{$d}e", $n);
        // PHP uses e+01 format, JS uses e+1 — normalize
        return preg_replace('/e([+-])0*(\d)/', 'e$1$2', $s);
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

    private static function toPropertyKey(mixed $key): string
    {
        if (is_int($key) || is_string($key)) {
            return (string) $key;
        }

        return self::toString($key);
    }

    private static function jsonNormalize(mixed $value): mixed
    {
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

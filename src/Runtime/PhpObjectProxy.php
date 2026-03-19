<?php

declare(strict_types=1);

namespace ScriptLite\Runtime;

use ReflectionMethod;

/**
 * Wraps a real PHP object for use inside the JS engine.
 *
 * VM path:  getProperty()/setProperty() detect this type and proxy to the real object.
 * Transpiler path: implements ArrayAccess so $proxy['key'] reads properties/calls methods.
 *
 * Property reads  → $obj->prop
 * Property writes → $obj->prop = value
 * Method calls    → $proxy['method'] returns a closure that calls $obj->method(...)
 *
 * Arguments are auto-coerced to match PHP type hints (JS numbers are floats,
 * but PHP methods may expect int/string/bool).
 *
 * Return values from methods and property reads are wrapped:
 * - PHP objects → PhpObjectProxy (so chained access works)
 * - PHP arrays  → JsArray or JsObject for the VM path; left as-is for transpiler
 */
final class PhpObjectProxy implements \ArrayAccess
{
    /** @var array<string, NativeFunction> */
    private array $vmMethodCache = [];

    /** @var array<string, \Closure> */
    private array $transpilerMethodCache = [];

    /** @var array<string, array<int, array{builtin:?string, unwrapProxy:bool}>> */
    private static array $signatureCache = [];

    public function __construct(
        public readonly object $target,
    ) {}

    // ── VM integration ──

    public function get(string $key): mixed
    {
        if (isset($this->vmMethodCache[$key])) {
            return $this->vmMethodCache[$key];
        }

        if (method_exists($this->target, $key)) {
            $target = $this->target;
            return $this->vmMethodCache[$key] = new NativeFunction($key, static function (mixed ...$args) use ($target, $key): mixed {
                $args = self::coerceArgs($target, $key, $args);
                return self::wrapForVm($target->$key(...$args));
            });
        }

        if (property_exists($this->target, $key)) {
            return self::wrapForVm($this->target->$key);
        }

        return JsUndefined::Value;
    }

    public function set(string $key, mixed $value): void
    {
        $this->target->$key = $value;
    }

    // ── ArrayAccess (transpiler path: $proxy['key']) ──

    public function offsetExists(mixed $offset): bool
    {
        return property_exists($this->target, (string) $offset)
            || method_exists($this->target, (string) $offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        $key = (string) $offset;

        if (isset($this->transpilerMethodCache[$key])) {
            return $this->transpilerMethodCache[$key];
        }

        if (method_exists($this->target, $key)) {
            $target = $this->target;
            return $this->transpilerMethodCache[$key] = static function (mixed ...$args) use ($target, $key): mixed {
                $args = self::coerceArgs($target, $key, $args);
                return self::wrapForTranspiler($target->$key(...$args));
            };
        }

        return self::wrapForTranspiler($this->target->$key ?? null);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->target->{(string) $offset} = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->target->{(string) $offset});
    }

    // ── Value wrapping ──

    /**
     * Wrap a value returned from a PHP object for the VM path.
     *
     * PHP arrays become JsArray/JsObject so the VM's getProperty() handles them.
     * PHP objects become PhpObjectProxy so chained .prop access works.
     */
    private static function wrapForVm(mixed $value): mixed
    {
        if ($value instanceof \Closure) {
            return new NativeFunction('(php)', $value);
        }
        if (is_object($value)) {
            return new self($value);
        }
        if (is_array($value)) {
            if (array_is_list($value)) {
                return new JsArray(array_map(fn($v) => self::wrapForVm($v), $value));
            }
            $props = [];
            foreach ($value as $k => $v) {
                $props[(string) $k] = self::wrapForVm($v);
            }
            return new JsObject($props);
        }
        return $value;
    }

    /**
     * Wrap a value returned from a PHP object for the transpiler path.
     *
     * Only PHP objects need wrapping (into PhpObjectProxy with ArrayAccess).
     * Arrays are left as-is since the transpiler already handles them natively.
     */
    private static function wrapForTranspiler(mixed $value): mixed
    {
        if ($value instanceof \Closure) {
            return $value;
        }
        if (is_object($value)) {
            return new self($value);
        }
        return $value;
    }

    // ── Argument coercion ──

    private static function coerceArgs(object $target, string $method, array $args): array
    {
        $cacheKey = get_class($target) . '::' . $method;
        $params = self::$signatureCache[$cacheKey] ??= self::buildSignaturePlan($target, $method);

        foreach ($params as $i => $param) {
            if (!array_key_exists($i, $args)) {
                break;
            }
            if ($param['builtin'] !== null) {
                $args[$i] = match ($param['builtin']) {
                    'int'    => (int) $args[$i],
                    'float'  => (float) $args[$i],
                    'string' => (string) $args[$i],
                    'bool'   => (bool) $args[$i],
                    'array'  => (array) $args[$i],
                    default  => $args[$i],
                };
            } elseif ($param['unwrapProxy'] && $args[$i] instanceof self) {
                // Unwrap proxy when PHP method expects a typed object
                $args[$i] = $args[$i]->target;
            }
        }

        return $args;
    }

    /**
     * @return array<int, array{builtin:?string, unwrapProxy:bool}>
     */
    private static function buildSignaturePlan(object $target, string $method): array
    {
        $ref = new ReflectionMethod($target, $method);
        $plan = [];

        foreach ($ref->getParameters() as $param) {
            $type = $param->getType();
            if (!$type instanceof \ReflectionNamedType) {
                $plan[] = ['builtin' => null, 'unwrapProxy' => false];
                continue;
            }

            $plan[] = [
                'builtin' => $type->isBuiltin() ? $type->getName() : null,
                'unwrapProxy' => !$type->isBuiltin(),
            ];
        }

        return $plan;
    }
}

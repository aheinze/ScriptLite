<?php

declare(strict_types=1);

namespace ScriptLite\Runtime;

/**
 * Runtime representation of a JavaScript array.
 *
 * Wraps a PHP array with JS-compatible property access and methods.
 * Objects in PHP are reference-handled, so closures that capture a JsArray
 * see mutations — exactly the semantics we need for bound methods like push/pop.
 */
final class JsArray
{
    /** @var mixed[] */
    public array $elements;

    /** @var array<string, mixed> Ad-hoc named properties (e.g. 'index', 'input' on regex match results) */
    public array $properties = [];

    /** @var array<string, NativeFunction> Cached method wrappers to avoid per-access allocation */
    private array $methodCache = [];
    private ?\Closure $cachedInvoker = null;

    /** @param mixed[] $elements */
    public function __construct(array $elements = [])
    {
        $this->elements = array_values($elements);
    }

    /**
     * Get a property or element by key.
     *
     * @param \Closure|null $invoker  fn(callable, array): mixed — VM re-entrant caller for JS callbacks
     */
    public function get(mixed $key, ?\Closure $invoker = null): mixed
    {
        if ($key === 'length') {
            return count($this->elements);
        }

        // Method lookup — returns a cached NativeFunction bound to this array
        if (is_string($key)) {
            if ($invoker !== $this->cachedInvoker) {
                $this->methodCache = [];
                $this->cachedInvoker = $invoker;
            }

            if (isset($this->methodCache[$key])) {
                return $this->methodCache[$key];
            }

            $method = $this->buildMethod($key, $invoker);
            if ($method !== null) {
                $this->methodCache[$key] = $method;
                return $method;
            }
        }

        // Check ad-hoc named properties (e.g. 'index', 'input' on regex match results)
        if (is_string($key) && isset($this->properties[$key])) {
            return $this->properties[$key];
        }

        $idx = $this->toIndex($key);
        if ($idx !== null && $idx >= 0 && $idx < count($this->elements)) {
            return $this->elements[$idx];
        }

        return JsUndefined::Value;
    }

    /**
     * Set an element by index.
     */
    public function set(mixed $key, mixed $value): void
    {
        $idx = $this->toIndex($key);
        if ($idx === null || $idx < 0) {
            return;
        }

        // Extend array with undefined if index is beyond current length
        while (count($this->elements) <= $idx) {
            $this->elements[] = JsUndefined::Value;
        }

        $this->elements[$idx] = $value;
    }

    private function buildMethod(string $name, ?\Closure $invoker = null): ?NativeFunction
    {
        return match ($name) {
            // ── Mutators ──
            'push' => new NativeFunction('push', function () {
                foreach (func_get_args() as $arg) {
                    $this->elements[] = $arg;
                }
                return count($this->elements);
            }),
            'pop' => new NativeFunction('pop', function () {
                if (empty($this->elements)) {
                    return JsUndefined::Value;
                }
                return array_pop($this->elements);
            }),
            'shift' => new NativeFunction('shift', function () {
                if (empty($this->elements)) {
                    return JsUndefined::Value;
                }
                return array_shift($this->elements);
            }),
            'unshift' => new NativeFunction('unshift', function () {
                $args = func_get_args();
                array_unshift($this->elements, ...$args);
                return count($this->elements);
            }),
            'splice' => new NativeFunction('splice', function (mixed $start = 0, mixed $deleteCount = null) {
                $rest = array_slice(func_get_args(), 2);
                $len = count($this->elements);
                $s = (int) $start;
                if ($s < 0) {
                    $s = max(0, $len + $s);
                }
                $dc = $deleteCount === null || $deleteCount === JsUndefined::Value
                    ? $len - $s
                    : max(0, (int) $deleteCount);
                $removed = array_splice($this->elements, $s, $dc, $rest);
                return new self($removed);
            }),
            'reverse' => new NativeFunction('reverse', function () {
                $this->elements = array_reverse($this->elements);
                return $this;
            }),
            'sort' => new NativeFunction('sort', function (mixed $compareFn = null) use ($invoker) {
                if ($compareFn === null || $compareFn === JsUndefined::Value) {
                    usort($this->elements, fn($a, $b) => strcmp((string) $a, (string) $b));
                } elseif ($invoker !== null) {
                    usort($this->elements, fn($a, $b) => (int) $invoker($compareFn, [$a, $b]));
                }
                return $this;
            }),
            'fill' => new NativeFunction('fill', function (mixed $value, mixed $start = 0, mixed $end = null) {
                $len = count($this->elements);
                $s = (int) $start;
                if ($s < 0) {
                    $s = max(0, $len + $s);
                }
                $e = $end === null || $end === JsUndefined::Value ? $len : (int) $end;
                if ($e < 0) {
                    $e = max(0, $len + $e);
                }
                for ($i = $s; $i < $e && $i < $len; $i++) {
                    $this->elements[$i] = $value;
                }
                return $this;
            }),

            // ── Accessors ──
            'join' => new NativeFunction('join', function (mixed $separator = ',') {
                $parts = [];
                foreach ($this->elements as $el) {
                    if ($el === null || $el === JsUndefined::Value) {
                        $parts[] = '';
                    } else {
                        $parts[] = (string) $el;
                    }
                }
                return implode(is_string($separator) ? $separator : ',', $parts);
            }),
            'indexOf' => new NativeFunction('indexOf', function (mixed $search) {
                foreach ($this->elements as $i => $el) {
                    if ($el === $search) {
                        return $i;
                    }
                }
                return -1;
            }),
            'includes' => new NativeFunction('includes', function (mixed $search) {
                foreach ($this->elements as $el) {
                    if ($el === $search) {
                        return true;
                    }
                }
                return false;
            }),
            'slice' => new NativeFunction('slice', function (mixed $start = 0, mixed $end = null) {
                $len = count($this->elements);
                $s = (int) $start;
                if ($s < 0) {
                    $s = max(0, $len + $s);
                }
                $e = $end === null || $end === JsUndefined::Value ? $len : (int) $end;
                if ($e < 0) {
                    $e = max(0, $len + $e);
                }
                return new self(array_slice($this->elements, $s, max(0, $e - $s)));
            }),
            'concat' => new NativeFunction('concat', function () {
                $result = $this->elements;
                foreach (func_get_args() as $arg) {
                    if ($arg instanceof self) {
                        $result = array_merge($result, $arg->elements);
                    } else {
                        $result[] = $arg;
                    }
                }
                return new self($result);
            }),
            'flat' => new NativeFunction('flat', function (mixed $depth = 1) {
                return new self($this->flattenElements($this->elements, (int) $depth));
            }),

            // ── Callback-based (require VM invoker for re-entrant execution) ──
            'forEach' => $invoker !== null ? new NativeFunction('forEach', function (mixed $fn) use ($invoker) {
                foreach ($this->elements as $i => $el) {
                    $invoker($fn, [$el, $i]);
                }
                return JsUndefined::Value;
            }) : null,
            'map' => $invoker !== null ? new NativeFunction('map', function (mixed $fn) use ($invoker) {
                $result = [];
                foreach ($this->elements as $i => $el) {
                    $result[] = $invoker($fn, [$el, $i]);
                }
                return new self($result);
            }) : null,
            'filter' => $invoker !== null ? new NativeFunction('filter', function (mixed $fn) use ($invoker) {
                $result = [];
                foreach ($this->elements as $i => $el) {
                    if ($invoker($fn, [$el, $i])) {
                        $result[] = $el;
                    }
                }
                return new self($result);
            }) : null,
            'find' => $invoker !== null ? new NativeFunction('find', function (mixed $fn) use ($invoker) {
                foreach ($this->elements as $i => $el) {
                    if ($invoker($fn, [$el, $i])) {
                        return $el;
                    }
                }
                return JsUndefined::Value;
            }) : null,
            'findIndex' => $invoker !== null ? new NativeFunction('findIndex', function (mixed $fn) use ($invoker) {
                foreach ($this->elements as $i => $el) {
                    if ($invoker($fn, [$el, $i])) {
                        return $i;
                    }
                }
                return -1;
            }) : null,
            'reduce' => $invoker !== null ? new NativeFunction('reduce', function (mixed $fn, mixed $initial = null) use ($invoker) {
                $acc = $initial;
                $startIdx = 0;
                if ($acc === null || $acc === JsUndefined::Value) {
                    if (empty($this->elements)) {
                        throw new \RuntimeException('TypeError: Reduce of empty array with no initial value');
                    }
                    $acc = $this->elements[0];
                    $startIdx = 1;
                }
                for ($i = $startIdx; $i < count($this->elements); $i++) {
                    $acc = $invoker($fn, [$acc, $this->elements[$i], $i]);
                }
                return $acc;
            }) : null,
            'every' => $invoker !== null ? new NativeFunction('every', function (mixed $fn) use ($invoker) {
                foreach ($this->elements as $i => $el) {
                    if (!$invoker($fn, [$el, $i])) {
                        return false;
                    }
                }
                return true;
            }) : null,
            'some' => $invoker !== null ? new NativeFunction('some', function (mixed $fn) use ($invoker) {
                foreach ($this->elements as $i => $el) {
                    if ($invoker($fn, [$el, $i])) {
                        return true;
                    }
                }
                return false;
            }) : null,

            default => null,
        };
    }

    /** @return mixed[] */
    private function flattenElements(array $elements, int $depth): array
    {
        $result = [];
        foreach ($elements as $el) {
            if ($el instanceof self && $depth > 0) {
                $result = array_merge($result, $this->flattenElements($el->elements, $depth - 1));
            } else {
                $result[] = $el;
            }
        }
        return $result;
    }

    private function toIndex(mixed $key): ?int
    {
        if (is_int($key)) {
            return $key;
        }
        if (is_float($key) && $key == (int) $key) {
            return (int) $key;
        }
        if (is_string($key) && is_numeric($key)) {
            return (int) $key;
        }
        return null;
    }
}

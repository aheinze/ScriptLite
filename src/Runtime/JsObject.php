<?php

declare(strict_types=1);

namespace ScriptLite\Runtime;

/**
 * Runtime representation of a JavaScript plain object.
 *
 * Wraps an associative array with JS-compatible property access.
 * Objects in PHP are reference-handled, so closures that capture a JsObject
 * see mutations — exactly the semantics we need.
 */
final class JsObject
{
    /** @var array<string, mixed> */
    public array $properties;

    /** Prototype object for Object.create()/prototype-chain reads */
    public ?self $prototype = null;

    /** Constructor function that created this object (for instanceof) */
    public ?JsClosure $constructor;

    /** @param array<string, mixed> $properties */
    public function __construct(array $properties = [], ?JsClosure $constructor = null)
    {
        $this->properties = $properties;
        $this->constructor = $constructor;
    }

    /** @var array<string, NativeFunction> Cached method wrappers */
    private array $methodCache = [];

    /**
     * Get a property by key.
     *
     * @param \Closure|null $invoker  fn(callable, array): mixed — VM re-entrant caller for JS callbacks
     */
    public function get(mixed $key, ?\Closure $invoker = null): mixed
    {
        $k = (string) $key;

        // Method lookup — cached
        if (isset($this->methodCache[$k])) {
            return $this->methodCache[$k];
        }

        $method = $this->buildMethod($k);
        if ($method !== null) {
            $this->methodCache[$k] = $method;
            return $method;
        }

        if (array_key_exists($k, $this->properties)) {
            return $this->properties[$k];
        }

        if ($this->prototype !== null) {
            return $this->prototype->get($k, $invoker);
        }

        return JsUndefined::Value;
    }

    /**
     * Set a property by key.
     */
    public function set(mixed $key, mixed $value): void
    {
        $this->properties[(string) $key] = $value;
    }

    private function buildMethod(string $name): ?NativeFunction
    {
        return match ($name) {
            'hasOwnProperty' => new NativeFunction('hasOwnProperty', function (mixed $key) {
                return array_key_exists((string) $key, $this->properties);
            }),
            default => null,
        };
    }
}

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

    /** Constructor function that created this object (for instanceof) */
    public ?JsClosure $constructor;

    /** @param array<string, mixed> $properties */
    public function __construct(array $properties = [], ?JsClosure $constructor = null)
    {
        $this->properties = $properties;
        $this->constructor = $constructor;
    }

    /**
     * Get a property by key.
     *
     * @param \Closure|null $invoker  fn(callable, array): mixed — VM re-entrant caller for JS callbacks
     */
    public function get(mixed $key, ?\Closure $invoker = null): mixed
    {
        $k = (string) $key;

        // Method lookup
        $method = $this->getMethod($k, $invoker);
        if ($method !== null) {
            return $method;
        }

        if (array_key_exists($k, $this->properties)) {
            return $this->properties[$k];
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

    private function getMethod(string $name, ?\Closure $invoker = null): ?NativeFunction
    {
        return match ($name) {
            'hasOwnProperty' => new NativeFunction('hasOwnProperty', function (mixed $key) {
                return array_key_exists((string) $key, $this->properties);
            }),
            default => null,
        };
    }
}

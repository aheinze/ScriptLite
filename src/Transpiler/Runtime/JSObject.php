<?php

declare(strict_types=1);

namespace ScriptLite\Transpiler\Runtime;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Object model for transpiled JS.
 *
 * This is intentionally not a PHP array:
 * - object identity is preserved across closures and function calls
 * - property reads can walk the prototype chain
 * - own keys remain enumerable without conflating arrays and objects
 */
final class JSObject implements \ArrayAccess, Countable, IteratorAggregate
{
    /** @var array<string, mixed> */
    public array $properties = [];

    public ?self $prototype = null;

    public function __construct(array $properties = [], ?self $prototype = null)
    {
        $this->prototype = $prototype;
        $this->properties = $properties;
    }

    public function getPrototype(): ?self
    {
        return $this->prototype;
    }

    public function setPrototype(?self $prototype): void
    {
        $this->prototype = $prototype;
    }

    public function hasOwn(string $key): bool
    {
        return array_key_exists($key, $this->properties);
    }

    public function has(string $key): bool
    {
        if ($this->hasOwn($key)) {
            return true;
        }

        return $this->prototype?->has($key) ?? false;
    }

    public function get(string $key): mixed
    {
        if ($this->hasOwn($key)) {
            return $this->properties[$key];
        }

        return $this->prototype?->get($key);
    }

    public function set(string $key, mixed $value): mixed
    {
        $this->properties[$key] = $value;
        return $value;
    }

    public function delete(string $key): void
    {
        unset($this->properties[$key]);
    }

    /**
     * @return list<string>
     */
    public function keys(bool $includePrototype = false): array
    {
        $keys = array_keys($this->properties);

        if (!$includePrototype || $this->prototype === null) {
            return $keys;
        }

        $seen = array_fill_keys($keys, true);
        foreach ($this->prototype->keys(true) as $key) {
            if (!isset($seen[$key])) {
                $keys[] = $key;
                $seen[$key] = true;
            }
        }

        return $keys;
    }

    /**
     * @return list<mixed>
     */
    public function values(bool $includePrototype = false): array
    {
        $values = [];
        foreach ($this->keys($includePrototype) as $key) {
            $values[] = $this->get($key);
        }
        return $values;
    }

    /**
     * @return list<array{0:string,1:mixed}>
     */
    public function entries(bool $includePrototype = false): array
    {
        $entries = [];
        foreach ($this->keys($includePrototype) as $key) {
            $entries[] = [$key, $this->get($key)];
        }
        return $entries;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->properties;
    }

    public function offsetExists(mixed $offset): bool
    {
        $key = (string) $offset;

        // Fast path: no prototype chain
        if ($this->prototype === null) {
            return array_key_exists($key, $this->properties);
        }

        for ($object = $this; $object !== null; $object = $object->prototype) {
            if (array_key_exists($key, $object->properties)) {
                return true;
            }
        }

        return false;
    }

    public function offsetGet(mixed $offset): mixed
    {
        $key = (string) $offset;

        // Fast path: no prototype chain (common case for data objects)
        if ($this->prototype === null) {
            return $this->properties[$key] ?? null;
        }

        for ($object = $this; $object !== null; $object = $object->prototype) {
            if (array_key_exists($key, $object->properties)) {
                return $object->properties[$key];
            }
        }

        return null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->set((string) $offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->delete((string) $offset);
    }

    public function count(): int
    {
        return count($this->properties);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->properties);
    }
}

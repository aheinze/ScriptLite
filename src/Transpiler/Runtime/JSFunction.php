<?php

declare(strict_types=1);

namespace ScriptLite\Transpiler\Runtime;

/**
 * Invokable function object for transpiled JS.
 *
 * PHP closures cannot carry arbitrary properties, but JS functions can.
 * Wrapping them in an invokable object gives us `fn.prototype`, constructor
 * metadata, and stable object identity.
 */
final class JSFunction implements \ArrayAccess
{
    /** @var array<string, mixed> */
    public array $properties = [];

    public function __construct(
        private readonly \Closure $callable,
        ?JSObject $prototype = null,
    ) {
        $this->properties['prototype'] = $prototype ?? new JSObject();
    }

    public function __invoke(mixed ...$args): mixed
    {
        return ($this->callable)(...$args);
    }

    public function getPrototype(): JSObject
    {
        $prototype = $this->properties['prototype'] ?? null;
        if (!$prototype instanceof JSObject) {
            $prototype = new JSObject();
            $this->properties['prototype'] = $prototype;
        }

        return $prototype;
    }

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists((string) $offset, $this->properties);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->properties[(string) $offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->properties[(string) $offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->properties[(string) $offset]);
    }
}

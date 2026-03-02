<?php

declare(strict_types=1);

namespace ScriptLite\Runtime;

use RuntimeException;

/**
 * Lexical environment implementing the JS scope chain.
 *
 * Each Environment holds:
 * - A map of variable names → values (the "record")
 * - A set tracking which variables are const
 * - A pointer to the parent (enclosing) environment
 *
 * Variable resolution walks up the chain (like JS's [[Scope]]).
 * Closures capture the Environment at their creation point, creating
 * the correct lexical scoping behavior.
 *
 * Why not use PHP arrays directly? We need the parent chain for proper
 * scope resolution, and the const tracking prevents mutation of const bindings.
 */
final class Environment
{
    /** @var array<string, mixed> */
    private array $values = [];

    /** @var array<string, true> */
    private array $constBindings = [];

    public function __construct(
        private readonly ?self $parent = null,
    ) {}

    /**
     * Define a new variable in THIS scope (not parent).
     */
    public function define(string $name, mixed $value, bool $isConst = false): void
    {
        $this->values[$name] = $value;
        if ($isConst) {
            $this->constBindings[$name] = true;
        }
    }

    /**
     * Look up a variable by walking the scope chain.
     */
    public function get(string $name): mixed
    {
        if (array_key_exists($name, $this->values)) {
            return $this->values[$name];
        }

        if ($this->parent !== null) {
            return $this->parent->get($name);
        }

        throw new RuntimeException("ReferenceError: {$name} is not defined");
    }

    /**
     * Look up a variable, returning a sentinel if not found (for typeof).
     */
    public function has(string $name): bool
    {
        if (array_key_exists($name, $this->values)) {
            return true;
        }
        return $this->parent?->has($name) ?? false;
    }

    /**
     * Set an existing variable (walks scope chain).
     */
    public function set(string $name, mixed $value): void
    {
        if (array_key_exists($name, $this->values)) {
            if (isset($this->constBindings[$name])) {
                throw new RuntimeException("TypeError: Assignment to constant variable '{$name}'");
            }
            $this->values[$name] = $value;
            return;
        }

        if ($this->parent !== null) {
            $this->parent->set($name, $value);
            return;
        }

        throw new RuntimeException("ReferenceError: {$name} is not defined");
    }

    /**
     * Return the parent scope (for PopScope).
     */
    public function getParent(): self
    {
        assert($this->parent !== null, 'Cannot pop the global scope');
        return $this->parent;
    }

    /**
     * Create a child scope.
     */
    public function extend(): self
    {
        return new self(parent: $this);
    }
}

<?php

declare(strict_types=1);

namespace ScriptLite\Runtime;

/**
 * Runtime representation of a JavaScript RegExp object.
 */
final class JsRegex
{
    public int $lastIndex = 0;

    public function __construct(
        public readonly string $pattern,
        public readonly string $flags,
    ) {}

    public function toPcre(): string
    {
        $modifiers = '';
        if (str_contains($this->flags, 'i')) {
            $modifiers .= 'i';
        }
        if (str_contains($this->flags, 'm')) {
            $modifiers .= 'm';
        }
        if (str_contains($this->flags, 's')) {
            $modifiers .= 's';
        }
        $modifiers .= 'u'; // always use unicode mode

        // Delimiter: use chr(1) to avoid conflicts with pattern content
        return "\x01{$this->pattern}\x01{$modifiers}";
    }

    public function isGlobal(): bool
    {
        return str_contains($this->flags, 'g');
    }

    public function get(string $key): mixed
    {
        return match ($key) {
            'source' => $this->pattern,
            'flags' => $this->flags,
            'global' => $this->isGlobal(),
            'ignoreCase' => str_contains($this->flags, 'i'),
            'multiline' => str_contains($this->flags, 'm'),
            'lastIndex' => $this->lastIndex,
            'test' => new NativeFunction('test', function (mixed $str) {
                $s = (string) $str;
                $pcre = $this->toPcre();
                if ($this->isGlobal()) {
                    $result = preg_match($pcre, $s, $m, 0, $this->lastIndex);
                    if ($result === 1) {
                        $this->lastIndex = (int) strpos($s, $m[0], $this->lastIndex) + strlen($m[0]);
                    } else {
                        $this->lastIndex = 0;
                    }
                    return $result === 1;
                }
                return preg_match($pcre, $s) === 1;
            }),
            'exec' => new NativeFunction('exec', function (mixed $str) {
                $s = (string) $str;
                $pcre = $this->toPcre();
                $offset = $this->isGlobal() ? $this->lastIndex : 0;

                if (preg_match($pcre, $s, $matches, PREG_OFFSET_CAPTURE, $offset) !== 1) {
                    if ($this->isGlobal()) {
                        $this->lastIndex = 0;
                    }
                    return null;
                }

                $index = $matches[0][1];
                $matchValues = array_map(fn($m) => $m[0], $matches);

                if ($this->isGlobal()) {
                    $this->lastIndex = $index + strlen($matches[0][0]);
                }

                $result = new JsArray($matchValues);
                $result->properties['index'] = $index;
                $result->properties['input'] = $s;
                return $result;
            }),
            'toString' => new NativeFunction('toString', fn() => $this->__toString()),
            default => JsUndefined::Value,
        };
    }

    public function __toString(): string
    {
        return "/{$this->pattern}/{$this->flags}";
    }
}

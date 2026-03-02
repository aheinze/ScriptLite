<?php

declare(strict_types=1);

namespace ScriptLite\Transpiler\Runtime;

/**
 * Date object for transpiled JS → PHP code.
 *
 * Implements ArrayAccess so transpiled property access (e.g. $d['getTime'])
 * returns callable closures. This mirrors how JS Date objects expose methods.
 */
final class TrDate implements \ArrayAccess
{
    private int $timestamp; // milliseconds since epoch (UTC)

    public function __construct(mixed ...$args)
    {
        if (count($args) === 0) {
            $this->timestamp = (int) (microtime(true) * 1000);
        } elseif (count($args) === 1) {
            $this->timestamp = (int) $args[0];
        } else {
            // new Date(year, month, day?, hours?, minutes?, seconds?, ms?)
            $year = (int) $args[0];
            $month = (int) ($args[1] ?? 0);
            $day = (int) ($args[2] ?? 1);
            $hours = (int) ($args[3] ?? 0);
            $minutes = (int) ($args[4] ?? 0);
            $seconds = (int) ($args[5] ?? 0);
            $ms = (int) ($args[6] ?? 0);
            $ts = mktime($hours, $minutes, $seconds, $month + 1, $day, $year);
            $this->timestamp = ($ts * 1000) + $ms;
        }
    }

    public function offsetExists(mixed $offset): bool
    {
        return is_string($offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        $ts = $this->timestamp;
        $sec = intdiv($ts, 1000);
        $ms = $ts % 1000;

        return match ($offset) {
            'getTime' => fn() => $ts,
            'valueOf' => fn() => $ts,
            'getFullYear' => fn() => (int) gmdate('Y', $sec),
            'getMonth' => fn() => (int) gmdate('n', $sec) - 1,
            'getDate' => fn() => (int) gmdate('j', $sec),
            'getDay' => fn() => (int) gmdate('w', $sec),
            'getHours' => fn() => (int) gmdate('G', $sec),
            'getMinutes' => fn() => (int) gmdate('i', $sec),
            'getSeconds' => fn() => (int) gmdate('s', $sec),
            'getMilliseconds' => fn() => $ms,
            'toISOString' => fn() => gmdate('Y-m-d\TH:i:s', $sec) . '.' . str_pad((string) $ms, 3, '0', STR_PAD_LEFT) . 'Z',
            'toString' => fn() => gmdate('D M d Y H:i:s', $sec) . ' GMT+0000 (Coordinated Universal Time)',
            'toLocaleDateString' => fn() => gmdate('n/j/Y', $sec),
            'setTime' => function (int $newTs) {
                $this->timestamp = $newTs;
                return $newTs;
            },
            '__constructor' => 'Date',
            default => null,
        };
    }

    public function offsetSet(mixed $offset, mixed $value): void {}

    public function offsetUnset(mixed $offset): void {}

    public function __toString(): string
    {
        $sec = intdiv($this->timestamp, 1000);
        return gmdate('D M d Y H:i:s', $sec) . ' GMT+0000 (Coordinated Universal Time)';
    }
}

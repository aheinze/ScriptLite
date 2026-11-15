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
    /** Milliseconds since epoch (UTC); null is an Invalid Date (NaN time value). */
    private ?int $timestamp;

    public function __construct(mixed ...$args)
    {
        if (count($args) === 0) {
            $this->timestamp = (int) (microtime(true) * 1000);
        } elseif (count($args) === 1) {
            $this->timestamp = is_string($args[0]) ? (int) $args[0] : self::timeClip($args[0]);
        } else {
            // new Date(year, month, day?, hours?, minutes?, seconds?, ms?)
            $parts = [];
            foreach ([0, 1, 2, 3, 4, 5, 6] as $k) {
                $n = (float) Ops::toNumber($args[$k] ?? ($k === 2 ? 1 : 0));
                if (!is_finite($n)) {
                    $this->timestamp = null; // any NaN/Infinity component → Invalid Date, as in JS
                    return;
                }
                $parts[] = Ops::toInteger($n);
            }
            [$year, $month, $day, $hours, $minutes, $seconds, $ms] = $parts;
            $ts = mktime($hours, $minutes, $seconds, $month + 1, $day, $year);
            $this->timestamp = ($ts * 1000) + $ms;
        }
    }

    /** JS TimeClip: NaN, ±Infinity and anything beyond ±8.64e15 ms is an Invalid Date. */
    private static function timeClip(mixed $value): ?int
    {
        $n = (float) Ops::toNumber($value);
        return is_finite($n) && abs($n) <= 8.64e15 ? (int) $n : null;
    }

    public function offsetExists(mixed $offset): bool
    {
        return is_string($offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        $ts = $this->timestamp;
        if ($ts === null) {
            return match ($offset) {
                'toISOString' => fn() => Ops::rangeError('Invalid time value'),
                'toString', 'toLocaleDateString' => fn() => 'Invalid Date',
                'setTime' => function (mixed $newTs) {
                    return ($this->timestamp = self::timeClip($newTs)) ?? NAN;
                },
                '__constructor' => 'Date',
                'getTime', 'valueOf', 'getFullYear', 'getMonth', 'getDate', 'getDay', 'getHours',
                'getMinutes', 'getSeconds', 'getMilliseconds' => fn() => NAN,
                default => null,
            };
        }
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
            'setTime' => function (mixed $newTs) {
                return ($this->timestamp = self::timeClip($newTs)) ?? NAN;
            },
            '__constructor' => 'Date',
            default => null,
        };
    }

    public function offsetSet(mixed $offset, mixed $value): void {}

    public function offsetUnset(mixed $offset): void {}

    public function __toString(): string
    {
        if ($this->timestamp === null) {
            return 'Invalid Date';
        }
        $sec = intdiv($this->timestamp, 1000);
        return gmdate('D M d Y H:i:s', $sec) . ' GMT+0000 (Coordinated Universal Time)';
    }
}

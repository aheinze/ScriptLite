<?php

declare(strict_types=1);

namespace ScriptLite\Runtime;

use ScriptLite\Transpiler\Runtime\Ops;

/**
 * Runtime representation of a JavaScript Date object.
 *
 * Wraps a millisecond timestamp with JS-compatible Date instance methods.
 */
final class JsDate
{
    /** @var float Milliseconds since Unix epoch */
    private float $timestamp;

    public function __construct(float $timestamp)
    {
        $this->timestamp = self::timeClip($timestamp);
    }

    /** JS TimeClip: NaN, ±Infinity and anything beyond ±8.64e15 ms is an Invalid Date (NaN). */
    private static function timeClip(float $ms): float
    {
        return is_finite($ms) && abs($ms) <= 8.64e15 ? $ms : NAN;
    }

    private function isValid(): bool
    {
        return !is_nan($this->timestamp);
    }

    public function getTimestamp(): float
    {
        return $this->timestamp;
    }

    /**
     * Get a Date instance method by name.
     */
    public function get(mixed $key): mixed
    {
        return match ((string) $key) {
            'getTime' => new NativeFunction('getTime', fn() => $this->timestamp),
            'getFullYear' => new NativeFunction('getFullYear', fn() => $this->isValid() ? (int) gmdate('Y', $this->unixSeconds()) : NAN),
            'getMonth' => new NativeFunction('getMonth', fn() => $this->isValid() ? (int) gmdate('n', $this->unixSeconds()) - 1 : NAN),
            'getDate' => new NativeFunction('getDate', fn() => $this->isValid() ? (int) gmdate('j', $this->unixSeconds()) : NAN),
            'getDay' => new NativeFunction('getDay', fn() => $this->isValid() ? (int) gmdate('w', $this->unixSeconds()) : NAN),
            'getHours' => new NativeFunction('getHours', fn() => $this->isValid() ? (int) gmdate('G', $this->unixSeconds()) : NAN),
            'getMinutes' => new NativeFunction('getMinutes', fn() => $this->isValid() ? (int) gmdate('i', $this->unixSeconds()) : NAN),
            'getSeconds' => new NativeFunction('getSeconds', fn() => $this->isValid() ? (int) gmdate('s', $this->unixSeconds()) : NAN),
            'getMilliseconds' => new NativeFunction('getMilliseconds', fn() => $this->isValid() ? (int) fmod($this->timestamp, 1000) : NAN),
            'toISOString' => new NativeFunction('toISOString', function () {
                if (!$this->isValid()) {
                    Ops::rangeError('Invalid time value');
                }
                $ms = (int) fmod(abs($this->timestamp), 1000);
                return gmdate('Y-m-d\TH:i:s', $this->unixSeconds()) . sprintf('.%03dZ', $ms);
            }),
            'toString' => new NativeFunction('toString', fn() => $this->toDateString()),
            'toLocaleDateString' => new NativeFunction('toLocaleDateString', fn() => $this->isValid() ? gmdate('n/j/Y', $this->unixSeconds()) : 'Invalid Date'),
            'valueOf' => new NativeFunction('valueOf', fn() => $this->timestamp),
            'setTime' => new NativeFunction('setTime', function (mixed $ms) {
                $this->timestamp = self::timeClip((float) Ops::toNumber($ms));
                return $this->timestamp;
            }),
            default => JsUndefined::Value,
        };
    }

    public function toDateString(): string
    {
        if (!$this->isValid()) {
            return 'Invalid Date';
        }
        return gmdate('D M d Y H:i:s', $this->unixSeconds()) . ' GMT+0000 (UTC)';
    }

    private function unixSeconds(): int
    {
        return (int) ($this->timestamp / 1000);
    }
}

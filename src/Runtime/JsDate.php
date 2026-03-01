<?php

declare(strict_types=1);

namespace ScriptLite\Runtime;

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
        $this->timestamp = $timestamp;
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
            'getFullYear' => new NativeFunction('getFullYear', fn() => (int) gmdate('Y', $this->unixSeconds())),
            'getMonth' => new NativeFunction('getMonth', fn() => (int) gmdate('n', $this->unixSeconds()) - 1),
            'getDate' => new NativeFunction('getDate', fn() => (int) gmdate('j', $this->unixSeconds())),
            'getDay' => new NativeFunction('getDay', fn() => (int) gmdate('w', $this->unixSeconds())),
            'getHours' => new NativeFunction('getHours', fn() => (int) gmdate('G', $this->unixSeconds())),
            'getMinutes' => new NativeFunction('getMinutes', fn() => (int) gmdate('i', $this->unixSeconds())),
            'getSeconds' => new NativeFunction('getSeconds', fn() => (int) gmdate('s', $this->unixSeconds())),
            'getMilliseconds' => new NativeFunction('getMilliseconds', fn() => (int) fmod($this->timestamp, 1000)),
            'toISOString' => new NativeFunction('toISOString', function () {
                $ms = (int) fmod(abs($this->timestamp), 1000);
                return gmdate('Y-m-d\TH:i:s', $this->unixSeconds()) . sprintf('.%03dZ', $ms);
            }),
            'toString' => new NativeFunction('toString', fn() => $this->toDateString()),
            'toLocaleDateString' => new NativeFunction('toLocaleDateString', fn() => gmdate('n/j/Y', $this->unixSeconds())),
            'valueOf' => new NativeFunction('valueOf', fn() => $this->timestamp),
            'setTime' => new NativeFunction('setTime', function (mixed $ms) {
                $this->timestamp = (float) $ms;
                return $this->timestamp;
            }),
            default => JsUndefined::Value,
        };
    }

    public function toDateString(): string
    {
        return gmdate('D M d Y H:i:s', $this->unixSeconds()) . ' GMT+0000 (UTC)';
    }

    private function unixSeconds(): int
    {
        return (int) ($this->timestamp / 1000);
    }
}

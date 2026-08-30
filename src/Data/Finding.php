<?php

namespace SergeyBruhin\PostgresTools\Data;

/**
 * One result of an environment probe. Commands abort on FAIL, print WARN, and stay quiet
 * about OK unless the user asked for the full report.
 */
final class Finding
{
    public const OK   = 'ok';
    public const WARN = 'warn';
    public const FAIL = 'fail';

    public function __construct(
        public readonly string  $level,
        public readonly string  $message,
        public readonly ?string $hint = null,
    ) {}

    public static function ok(string $message, ?string $hint = null): self
    {
        return new self(self::OK, $message, $hint);
    }

    public static function warn(string $message, ?string $hint = null): self
    {
        return new self(self::WARN, $message, $hint);
    }

    public static function fail(string $message, ?string $hint = null): self
    {
        return new self(self::FAIL, $message, $hint);
    }

    public function isFail(): bool
    {
        return $this->level === self::FAIL;
    }

    public function isWarn(): bool
    {
        return $this->level === self::WARN;
    }
}

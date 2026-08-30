<?php

namespace SergeyBruhin\PostgresTools\Services;

/**
 * Matches table names against the patterns pg_dump accepts for -t / -T.
 *
 * pg_dump uses shell-style wildcards; we accept SQL's % as well, since that is what people
 * reach for when writing an exclusion list in .env.
 */
final class TablePattern
{
    /** @param array<string> $patterns */
    public static function matchesAny(string $table, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (self::matches($table, $pattern)) {
                return true;
            }
        }

        return false;
    }

    public static function matches(string $table, string $pattern): bool
    {
        $pattern = trim($pattern);

        if ($pattern === '') {
            return false;
        }

        // A schema-qualified pattern only constrains tables in that schema; we only ever
        // dump "public", so strip the prefix rather than failing to match.
        if (str_starts_with($pattern, 'public.')) {
            $pattern = substr($pattern, 7);
        }

        return fnmatch(str_replace('%', '*', $pattern), $table);
    }
}

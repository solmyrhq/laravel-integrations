<?php

declare(strict_types=1);

namespace Integrations\Support;

use JsonException;

use function Safe\json_decode;

final class JsonEquivalence
{
    /**
     * Whether two JSON strings decode to the same data, ignoring whitespace and
     * object key order. List order and value types are compared.
     */
    public static function areEquivalent(string $first, string $second): bool
    {
        if ($first === $second) {
            return true;
        }

        try {
            return self::canonicalise(json_decode($first, true)) === self::canonicalise(json_decode($second, true));
        } catch (JsonException) {
            return false;
        }
    }

    private static function canonicalise(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::canonicalise(...), $value);

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}

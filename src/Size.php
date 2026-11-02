<?php

declare(strict_types=1);

namespace Clearcut\Video;

use InvalidArgumentException;

/**
 * A size as a person writes it in a config file — `32MB`, `4GB`, or plain
 * bytes. Binary units, as the service reads them: 1 MB is 1024 KB.
 */
final class Size
{
    private const UNITS = ['' => 1, 'B' => 1, 'KB' => 1024, 'MB' => 1024 ** 2, 'GB' => 1024 ** 3];

    /**
     * Refused rather than guessed: `32M` read as 32 bytes would make every
     * chunk too small to be useful, far from the typo.
     */
    public static function bytes(int|string $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        $raw = strtoupper(str_replace(' ', '', $value));
        if (! preg_match('/^(\d+)(B|KB|MB|GB)?$/', $raw, $m)) {
            throw new InvalidArgumentException("Not a size like 32MB or 4GB: {$value}");
        }

        return (int) $m[1] * self::UNITS[$m[2] ?? ''];
    }
}

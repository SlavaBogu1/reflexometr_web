<?php

declare(strict_types=1);

namespace Reflexometr\Support;

final class Stats
{
    /** @param array<int,float> $values */
    public static function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $count = count($values);
        $mid = intdiv($count, 2);
        if ($count % 2 === 0) {
            return ($values[$mid - 1] + $values[$mid]) / 2;
        }
        return $values[$mid];
    }

    private function __construct()
    {
    }
}

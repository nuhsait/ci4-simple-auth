<?php

namespace Nuhsait\Ci4SimpleAuth\IdentityNumber\Countries;

use Nuhsait\Ci4SimpleAuth\IdentityNumber\IdentityNumberInterface;

/**
 * Turkish citizen ID (T.C. Kimlik No) and foreigner ID (YKN).
 * Both are 11 digits and use the same checksum algorithm.
 */
class Tr implements IdentityNumberInterface
{
    public function label(): string
    {
        return 'Turkey (T.C. Kimlik No / YKN)';
    }

    public function length(): int
    {
        return 11;
    }

    public function normalize(string $value): string
    {
        return preg_replace('/\s+/', '', $value);
    }

    public function isValid(string $value): bool
    {
        if (preg_match('/^[1-9]\d{10}$/', $value) !== 1) {
            return false;
        }

        $d = array_map('intval', str_split($value));

        $odd  = $d[0] + $d[2] + $d[4] + $d[6] + $d[8];
        $even = $d[1] + $d[3] + $d[5] + $d[7];

        if (((($odd * 7) - $even) % 10 + 10) % 10 !== $d[9]) {
            return false;
        }

        return array_sum(array_slice($d, 0, 10)) % 10 === $d[10];
    }
}

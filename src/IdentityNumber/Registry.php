<?php

namespace Nuhsait\Ci4SimpleAuth\IdentityNumber;

use InvalidArgumentException;
use Nuhsait\Ci4SimpleAuth\IdentityNumber\Countries\Tr;

/**
 * Supported countries. To add a country, write its class and register it here.
 */
class Registry
{
    /**
     * @var array<string, class-string<IdentityNumberInterface>>
     */
    public const COUNTRIES = [
        'tr' => Tr::class,
    ];

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_keys(self::COUNTRIES);
    }

    public static function has(string $code): bool
    {
        return isset(self::COUNTRIES[strtolower($code)]);
    }

    public static function get(string $code): IdentityNumberInterface
    {
        $code = strtolower($code);

        if (! isset(self::COUNTRIES[$code])) {
            throw new InvalidArgumentException('Unsupported country code: ' . $code);
        }

        $class = self::COUNTRIES[$code];

        return new $class();
    }
}

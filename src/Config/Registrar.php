<?php

namespace Nuhsait\Ci4SimpleAuth\Config;

use Nuhsait\Ci4SimpleAuth\Filters\BasicAuth;
use Nuhsait\Ci4SimpleAuth\Validation\SimpleAuthRules;

/**
 * CI4 discovers this class automatically and merges it into the app's configs.
 */
class Registrar
{
    public static function Filters(): array
    {
        return [
            'aliases' => [
                'simpleauth' => BasicAuth::class,
            ],
        ];
    }

    public static function Validation(): array
    {
        return [
            'ruleSets' => [
                SimpleAuthRules::class,
            ],
        ];
    }
}

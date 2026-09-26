<?php

namespace Nuhsait\Ci4SimpleAuth;

use Nuhsait\Ci4SimpleAuth\Config\SimpleAuth;
use Nuhsait\Ci4SimpleAuth\IdentityNumber\Registry;

/**
 * Shared rules for the selectable identity fields.
 * The model, validation and the Basic Auth filter all normalize through here.
 */
final class IdentityFields
{
    /**
     * Order in which setup asks for the fields.
     */
    public const ALL = ['phone', 'identity_number', 'email', 'username'];

    /**
     * E.164: a + followed by at most 15 digits.
     */
    public const PHONE_LENGTH  = 16;
    public const PHONE_PATTERN = '/^\+[1-9]\d{7,14}$/';

    public const USERNAME_PATTERN = '/^[a-z]+$/';

    public static function normalize(string $field, string $value, SimpleAuth $config): string
    {
        $value = trim($value);

        return match ($field) {
            'phone'           => preg_replace('/[\s\-()]/', '', $value),
            'identity_number' => Registry::get((string) $config->identityCountry)->normalize($value),
            'email'           => mb_strtolower($value),
            'username'        => strtolower($value),
            default           => $value,
        };
    }
}

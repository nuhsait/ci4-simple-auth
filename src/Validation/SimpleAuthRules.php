<?php

namespace Nuhsait\Ci4SimpleAuth\Validation;

use Nuhsait\Ci4SimpleAuth\IdentityFields;
use Nuhsait\Ci4SimpleAuth\IdentityNumber\Registry;

/**
 * Added to Config\Validation::$ruleSets automatically by the Registrar.
 * Error messages live in Language/{locale}/Validation.php.
 */
class SimpleAuthRules
{
    public function simpleauth_phone(?string $str = null): bool
    {
        return preg_match(IdentityFields::PHONE_PATTERN, (string) $str) === 1;
    }

    public function simpleauth_username(?string $str = null): bool
    {
        return preg_match(IdentityFields::USERNAME_PATTERN, (string) $str) === 1;
    }

    public function simpleauth_identity_number(?string $str = null): bool
    {
        $country = (string) config('SimpleAuth')->identityCountry;

        return Registry::has($country) && Registry::get($country)->isValid((string) $str);
    }

    /**
     * bcrypt ignores everything after 72 bytes, so longer passwords are rejected.
     */
    public function simpleauth_password(?string $str = null): bool
    {
        $length = strlen((string) $str);

        return $length > 0 && $length <= 72;
    }
}

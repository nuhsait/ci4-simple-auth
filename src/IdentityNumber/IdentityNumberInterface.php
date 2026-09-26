<?php

namespace Nuhsait\Ci4SimpleAuth\IdentityNumber;

/**
 * Country specific identity number rules.
 */
interface IdentityNumberInterface
{
    /**
     * Description shown in the setup command.
     */
    public function label(): string;

    /**
     * Column length in the migration.
     */
    public function length(): int;

    /**
     * Cleans the value before saving and querying.
     */
    public function normalize(string $value): string;

    /**
     * Checks whether a normalized value is valid.
     */
    public function isValid(string $value): bool;
}

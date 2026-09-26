<?php

namespace Nuhsait\Ci4SimpleAuth\Entities;

use CodeIgniter\Entity\Entity;
use DateTimeInterface;

/**
 * A row of auth_user.
 *
 * Set the password with `$user->password = '...'`; the model hashes it on save.
 * `password` and `password_hash` never appear in toArray() or JSON output.
 */
class AuthUser extends Entity
{
    /**
     * Fields hidden from any output.
     */
    protected const HIDDEN = ['password', 'password_hash'];

    protected $dates = ['created_at', 'updated_at'];

    protected $casts = [
        'id' => '?integer',
    ];

    public function toArray(bool $onlyChanged = false, bool $cast = true, bool $recursive = false): array
    {
        return array_diff_key(parent::toArray($onlyChanged, $cast, $recursive), array_flip(self::HIDDEN));
    }

    /**
     * Dates stay Time objects in PHP and become ISO 8601 strings in JSON.
     */
    public function jsonSerialize(): array
    {
        return array_map(
            static fn ($value) => $value instanceof DateTimeInterface ? $value->format(DATE_ATOM) : $value,
            $this->toArray(),
        );
    }
}

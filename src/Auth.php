<?php

namespace Nuhsait\Ci4SimpleAuth;

use Nuhsait\Ci4SimpleAuth\Entities\AuthUser;

/**
 * Holds the authenticated user for the current request.
 * Access: auth()->user()
 */
class Auth
{
    private ?AuthUser $user = null;

    /**
     * password_hash is never kept here.
     */
    public function setUser(?AuthUser $user): void
    {
        if ($user !== null) {
            $user = clone $user;
            unset($user->password_hash);
        }

        $this->user = $user;
    }

    public function user(): ?AuthUser
    {
        return $this->user;
    }

    public function id(): ?int
    {
        return $this->user?->id;
    }

    public function check(): bool
    {
        return $this->user !== null;
    }
}

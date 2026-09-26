<?php

namespace Nuhsait\Ci4SimpleAuth;

use CodeIgniter\Cache\CacheInterface;

/**
 * Per-IP failed attempt counter.
 *
 * The first failed attempt opens a window as long as the cooldown.
 * If the limit is reached within the window, the IP is locked for the cooldown.
 * A successful login resets the counter.
 */
class LoginThrottle
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly int $maxAttempts,
        private readonly int $cooldownSeconds,
    ) {
    }

    /**
     * Seconds until the lock expires, 0 if not locked.
     */
    public function lockedFor(string $ip): int
    {
        $until = $this->cache->get($this->lockKey($ip));

        return is_int($until) ? max(0, $until - time()) : 0;
    }

    public function recordFailure(string $ip): void
    {
        $now   = time();
        $entry = $this->cache->get($this->failKey($ip));

        if (! is_array($entry) || $entry['expires'] <= $now) {
            $entry = ['count' => 0, 'expires' => $now + $this->cooldownSeconds];
        }

        $entry['count']++;

        if ($entry['count'] >= $this->maxAttempts) {
            $this->cache->save($this->lockKey($ip), $now + $this->cooldownSeconds, $this->cooldownSeconds);
            $this->cache->delete($this->failKey($ip));

            return;
        }

        // The window starts at the first attempt, so it is stored only for the remaining time.
        $this->cache->save($this->failKey($ip), $entry, $entry['expires'] - $now);
    }

    public function reset(string $ip): void
    {
        $this->cache->delete($this->failKey($ip));
    }

    private function failKey(string $ip): string
    {
        return 'simpleauth_fail_' . sha1($ip);
    }

    private function lockKey(string $ip): string
    {
        return 'simpleauth_lock_' . sha1($ip);
    }
}

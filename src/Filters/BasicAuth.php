<?php

namespace Nuhsait\Ci4SimpleAuth\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Nuhsait\Ci4SimpleAuth\Config\SimpleAuth;
use Nuhsait\Ci4SimpleAuth\LoginThrottle;
use Nuhsait\Ci4SimpleAuth\Models\User;

/**
 * HTTP Basic Auth filter. Alias: 'simpleauth' (registered automatically by the Registrar).
 *
 * Credentials are verified on every request. On success the user is
 * available through auth()->user().
 */
class BasicAuth implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        /** @var IncomingRequest $request */
        /** @var SimpleAuth $config */
        $config = config('SimpleAuth');
        $config->assertConfigured();

        $response = service('response');

        // The password travels almost in plain text on every request, so HTTP is refused.
        if (! $request->isSecure()) {
            return $this->error($response, 403, 'HTTPS is required.');
        }

        $ip       = $request->getIPAddress();
        $throttle = new LoginThrottle(service('cache'), $config->maxAttempts, $config->cooldownMinutes * 60);

        $lockedFor = $throttle->lockedFor($ip);

        if ($lockedFor > 0) {
            return $this->error($response, 429, 'Too many failed attempts. Please try again later.')
                ->setHeader('Retry-After', (string) $lockedFor);
        }

        $header = $request->getHeaderLine('Authorization');

        // A missing header is not an attempt (e.g. a browser's first request), so it is not counted.
        if ($header === '') {
            return $this->challenge($response, $config);
        }

        $credentials = $this->parseHeader($header);

        if ($credentials === null) {
            $throttle->recordFailure($ip);

            return $this->challenge($response, $config);
        }

        [$identity, $password] = $credentials;

        $user = model(User::class)->findForLogin($identity);

        // A dummy hash is verified when the user does not exist so both paths take the same time.
        // Otherwise the response time would reveal which users exist.
        $hash  = $user?->password_hash ?? $this->dummyHash($config->hashCost);
        $valid = password_verify($password, $hash) && $user !== null;

        if (! $valid) {
            $throttle->recordFailure($ip);

            return $this->challenge($response, $config);
        }

        $throttle->reset($ip);
        service('simpleauth')->setUser($user);

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function parseHeader(string $header): ?array
    {
        if (stripos($header, 'Basic ') !== 0) {
            return null;
        }

        $decoded = base64_decode(trim(substr($header, 6)), true);

        // The password may contain ':' but the identity may not, so split on the first ':'.
        if ($decoded === false || ! str_contains($decoded, ':')) {
            return null;
        }

        return explode(':', $decoded, 2);
    }

    private function challenge(ResponseInterface $response, SimpleAuth $config): ResponseInterface
    {
        $realm = addcslashes($config->realm, '"\\');

        return $this->error($response, 401, 'Authentication required.')
            ->setHeader('WWW-Authenticate', 'Basic realm="' . $realm . '", charset="UTF-8"');
    }

    private function error(ResponseInterface $response, int $status, string $message): ResponseInterface
    {
        $response->setStatusCode($status);

        return $response->setJSON([
            'status'  => $status,
            'error'   => $response->getReasonPhrase(),
            'message' => $message,
        ]);
    }

    /**
     * A well-formed bcrypt hash that matches no password.
     * password_verify still runs the full bcrypt computation for it.
     */
    private function dummyHash(int $cost): string
    {
        return sprintf('$2y$%02d$%s', $cost, str_repeat('a', 53));
    }
}

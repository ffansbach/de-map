<?php

declare(strict_types=1);

namespace FreifunkKarte\Admin;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Fails closed: with a password configured every request needs it (HTTP Basic - serve the UI over
 * HTTPS or on a trusted network); without one only the machine itself is let in.
 */
final class Auth
{
    public function __construct(private readonly AdminConfig $config)
    {
    }

    /**
     * @return ResponseInterface|null the answer to give instead of the page, or null to let the request through
     */
    public function check(ServerRequestInterface $request): ?ResponseInterface
    {
        if (!$this->config->requiresLogin()) {
            $address = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');

            return in_array($address, ['127.0.0.1', '::1'], true) ? null : $this->refuse(
                403,
                'The admin UI has no password set, so it only answers on the machine it runs on. Set a password with `bin/parser admin:password` and put the hash in config/admin.local.json.',
            );
        }

        [$user, $password] = $this->credentials($request->getHeaderLine('Authorization'));

        $userOk = hash_equals((string) $this->config->user, $user);
        // always verify, so that a wrong user name is not faster to refuse than a wrong password
        $passwordOk = password_verify($password, (string) $this->config->passwordHash);

        if ($userOk && $passwordOk) {
            return null;
        }

        if (($user !== '' || $password !== '') && $this->config->failedLoginDelayMs > 0) {
            // slows guessing down
            usleep($this->config->failedLoginDelayMs * 1000);
        }

        return $this->refuse(401, 'Login required.', ['WWW-Authenticate' => 'Basic realm="parser", charset="UTF-8"']);
    }

    /**
     * @return array{string, string}
     */
    private function credentials(string $header): array
    {
        if (preg_match('/^Basic\s+(.+)$/i', $header, $matches) !== 1) {
            return ['', ''];
        }

        $decoded = base64_decode($matches[1], true);
        $parts = $decoded === false ? [] : explode(':', $decoded, 2);

        return [$parts[0] ?? '', $parts[1] ?? ''];
    }

    /**
     * @param array<string, string> $headers
     */
    private function refuse(int $status, string $message, array $headers = []): ResponseInterface
    {
        return new Response($status, $headers + ['Content-Type' => 'text/plain; charset=utf-8'], $message . "\n");
    }
}

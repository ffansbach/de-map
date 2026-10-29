<?php

declare(strict_types=1);

namespace FreifunkKarte\Publish;

/**
 * Signs an upload (protocol v1, see web/upload.php): HMAC-SHA256 over the method, the path, a
 * timestamp, a nonce and the hash of the body. The path is part of it, so that a signature for one
 * endpoint is worthless for another; the nonce makes every request usable once.
 */
final class RequestSigner
{
    public function __construct(private readonly string $secret)
    {
    }

    /**
     * @return array<string, string> the headers to send
     */
    public function headers(string $path, string $bodySha256, ?int $timestamp = null, ?string $nonce = null): array
    {
        $timestamp ??= time();
        $nonce ??= bin2hex(random_bytes(16));

        return [
            'X-FFK-Timestamp' => (string) $timestamp,
            'X-FFK-Nonce' => $nonce,
            'X-FFK-Content-SHA256' => $bodySha256,
            'X-FFK-Signature' => 'v1=' . hash_hmac('sha256', self::canonical($path, (string) $timestamp, $nonce, $bodySha256), $this->secret),
        ];
    }

    public static function canonical(string $path, string $timestamp, string $nonce, string $bodySha256): string
    {
        return implode("\n", ['FFK-UPLOAD-V1', 'POST', $path, $timestamp, $nonce, $bodySha256]);
    }
}

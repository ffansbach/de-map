<?php

declare(strict_types=1);

namespace FreifunkKarte\Admin;

use RuntimeException;

/**
 * config/admin.local.json (gitignored):
 *
 *   {"db": "var/parser.sqlite", "user": "admin", "passwordHash": "$2y$...", "timezone": "Europe/Berlin"}
 *
 * Without a password hash the UI only answers requests from the machine it runs on.
 * `bin/parser admin:password` makes the hash.
 */
final readonly class AdminConfig
{
    public function __construct(
        public string $dbFile = 'var/parser.sqlite',
        public ?string $user = null,
        public ?string $passwordHash = null,
        public string $timezone = 'Europe/Berlin',
        public string $title = 'Freifunk Karte - parser',
        public int $failedLoginDelayMs = 300,
    ) {
    }

    public static function fromFile(string $file): self
    {
        if (!is_file($file)) {
            return new self();
        }

        $data = json_decode((string) file_get_contents($file), true);

        if (!is_array($data)) {
            throw new RuntimeException($file . ' is not valid JSON');
        }

        $text = static fn (string $key, ?string $default): ?string => is_string($data[$key] ?? null) && $data[$key] !== '' ? $data[$key] : $default;
        $timezone = $text('timezone', 'Europe/Berlin') ?? 'Europe/Berlin';

        if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            throw new RuntimeException('Unknown time zone "' . $timezone . '" in ' . $file);
        }

        return new self(
            $text('db', 'var/parser.sqlite') ?? 'var/parser.sqlite',
            $text('user', null),
            $text('passwordHash', null),
            $timezone,
            $text('title', 'Freifunk Karte - parser') ?? 'Freifunk Karte - parser',
            is_int($data['failedLoginDelayMs'] ?? null) ? max(0, $data['failedLoginDelayMs']) : 300,
        );
    }

    public function requiresLogin(): bool
    {
        return $this->passwordHash !== null;
    }
}

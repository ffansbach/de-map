<?php

declare(strict_types=1);

namespace FreifunkKarte\Audit;

final readonly class FetchResult
{
    /**
     * @param int|null $status HTTP status, null if the request failed before getting a response
     * @param bool $tlsInvalid the certificate did not validate (the content was fetched without verification)
     */
    public function __construct(
        public string $url,
        public ?int $status,
        public ?string $error = null,
        public int $bytes = 0,
        public float $seconds = 0.0,
        public bool $tlsInvalid = false,
    ) {
    }

    public function isOk(): bool
    {
        return $this->status === 200;
    }
}

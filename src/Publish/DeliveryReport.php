<?php

declare(strict_types=1);

namespace FreifunkKarte\Publish;

final readonly class DeliveryReport
{
    /**
     * @param list<PublishResult> $results
     * @param string|null $error why the web server does not have the new data (an alert), null if it has
     * @param list<string> $warnings things that went wrong but did not stop the delivery
     */
    public function __construct(
        public array $results,
        public ?string $error = null,
        public array $warnings = [],
    ) {
    }

    public function delivered(): bool
    {
        return $this->error === null;
    }
}

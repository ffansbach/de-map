<?php

declare(strict_types=1);

namespace FreifunkKarte\Publish;

use Closure;
use Throwable;

/**
 * Hands the result files to every publisher. The delivery has failed if a required publisher
 * failed, or if there are publishers and none of them worked. Another publisher that fails (for
 * example the new HTTPS upload while it runs next to FTP) is only a warning.
 */
final class Delivery
{
    /**
     * @param list<array{Publisher, bool}> $publishers publisher and whether it is required
     * @param Closure(): void|null $afterwards called once the delivery worked (statistics ping)
     */
    public function __construct(
        private readonly array $publishers,
        private readonly ?Closure $afterwards = null,
    ) {
    }

    public function hasPublishers(): bool
    {
        return $this->publishers !== [];
    }

    public function deliver(ResultFiles $files): DeliveryReport
    {
        $results = [];
        $required = [];
        $warnings = [];

        foreach ($this->publishers as [$publisher, $isRequired]) {
            try {
                $result = $publisher->publish($files);
            } catch (Throwable $e) {
                $result = new PublishResult($publisher->name(), false, $e->getMessage(), 0.0);
            }

            $results[] = $result;

            if ($result->ok) {
                continue;
            }

            $text = $result->publisher . ': ' . $result->message;

            if ($isRequired) {
                $required[] = $text;
            } else {
                $warnings[] = $text;
            }
        }

        $succeeded = count(array_filter($results, static fn (PublishResult $r): bool => $r->ok));
        $error = null;

        if ($required !== []) {
            $error = implode('; ', $required);
        } elseif ($results !== [] && $succeeded === 0) {
            $error = implode('; ', $warnings);
            $warnings = [];
        }

        if ($error === null && $this->afterwards !== null) {
            try {
                ($this->afterwards)();
            } catch (Throwable $e) {
                $warnings[] = 'statistics ping failed: ' . $e->getMessage();
            }
        }

        return new DeliveryReport($results, $error, $warnings);
    }
}

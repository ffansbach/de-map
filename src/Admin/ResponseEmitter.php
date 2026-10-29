<?php

declare(strict_types=1);

namespace FreifunkKarte\Admin;

use Psr\Http\Message\ResponseInterface;

final class ResponseEmitter
{
    /**
     * Sends a PSR-7 response to the client (status, headers, body).
     */
    public static function emit(ResponseInterface $response): void
    {
        if (!headers_sent()) {
            http_response_code($response->getStatusCode());

            foreach ($response->getHeaders() as $name => $values) {
                foreach ($values as $index => $value) {
                    header($name . ': ' . $value, $index === 0);
                }
            }
        }

        echo $response->getBody();
    }
}

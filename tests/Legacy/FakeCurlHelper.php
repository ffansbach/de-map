<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Legacy;

use ffmap\CurlHelper;

/**
 * Stands in for the legacy CurlHelper: answers from a route table and records every request,
 * in order. An unknown URL behaves like a failed request (false), as curl_exec() does.
 */
final class FakeCurlHelper extends CurlHelper
{
    /** @var list<string> */
    public array $calls = [];

    /**
     * @param array<string, string> $routes url => response body
     */
    public function __construct(private readonly array $routes)
    {
    }

    public function doCall(mixed $url): bool|string
    {
        $this->calls[] = (string) $url;
        $this->callCounter++;

        return $this->routes[$url] ?? false;
    }
}

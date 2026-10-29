<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Legacy;

use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * A small fake Freifunk world: a directory, community API files and node sources.
 *
 * Node sources come from tests/fixtures/sources. The legacy parser drops offline nodes by age
 * (time()), so fixture dates are moved so that they keep the age they had when captured.
 */
final class World
{
    /** shortly after the fixtures were captured */
    private const FIXTURE_TIME = '2026-10-29T10:39:00+00:00';

    private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})$/';

    /** @var array<string, string> */
    private array $directory = [];

    /** @var array<string, string> */
    private array $routes = [];

    /** @var array<string, array{url: string, parser: string, name: string, homePage: string}> */
    private array $fixed = [];

    /**
     * @param array<string, mixed> $api content of the community's API file; name and url get defaults
     */
    public function community(string $key, array $api = []): self
    {
        $apiUrl = 'https://api.test/' . $key . '.json';
        $this->directory[$key] = $apiUrl;
        $this->routes[$apiUrl] = (string) json_encode($api + ['name' => 'Freifunk ' . $key, 'url' => 'https://' . $key . '.example/']);

        return $this;
    }

    /**
     * A community whose API file cannot be loaded.
     */
    public function communityWithoutApiFile(string $key): self
    {
        $this->directory[$key] = 'https://api.test/' . $key . '.json';

        return $this;
    }

    public function route(string $url, string $body): self
    {
        $this->routes[$url] = $body;

        return $this;
    }

    public function fixed(string $key, string $url, string $parser): self
    {
        $this->fixed[$key] = ['url' => $url, 'parser' => $parser, 'name' => $key, 'homePage' => $url];

        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function routes(): array
    {
        return [LegacyRunner::DIRECTORY_URL => (string) json_encode($this->directory)] + $this->routes;
    }

    /**
     * A Guzzle handler that serves this world: known URLs answer 200, everything else 404.
     */
    public function httpHandler(): \Closure
    {
        $routes = $this->routes();

        return static fn (RequestInterface $request) => Create::promiseFor(
            isset($routes[(string) $request->getUri()])
                ? new Response(200, [], $routes[(string) $request->getUri()])
                : new Response(404),
        );
    }

    /**
     * @return array<string, array{url: string, parser: string, name: string, homePage: string}>
     */
    public function fixedCommunities(): array
    {
        return $this->fixed;
    }

    /**
     * A fixture as it would be served today. A prefix makes host names and ids unique, so that
     * several communities can serve "the same" fixture without the parser seeing duplicates.
     */
    public static function fixture(string $name, string $prefix = ''): string
    {
        $file = __DIR__ . '/../fixtures/sources/' . $name . '.json';
        $data = json_decode((string) file_get_contents($file), true);

        if (!is_array($data)) {
            throw new RuntimeException('Broken fixture ' . $file);
        }

        $delta = time() - (new DateTimeImmutable(self::FIXTURE_TIME))->getTimestamp();

        return (string) json_encode(self::transform($data, $prefix, $delta), JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private static function transform(array $data, string $prefix, int $delta): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::transform($value, $prefix, $delta);
            } elseif (is_string($value) && preg_match(self::DATE_PATTERN, $value) === 1) {
                $data[$key] = self::shift($value, $delta);
            } elseif ($prefix !== '' && is_string($value) && in_array($key, ['hostname', 'name', 'node_id', 'id'], true)) {
                $data[$key] = $prefix . '-' . $value;
            }
        }

        return $data;
    }

    private static function shift(string $date, int $delta): string
    {
        $shifted = (new DateTimeImmutable($date))->modify(sprintf('%+d seconds', $delta));

        if (str_ends_with($date, 'Z')) {
            return $shifted->setTimezone(new DateTimeZone('UTC'))->format(str_contains($date, '.') ? 'Y-m-d\TH:i:s.v\Z' : 'Y-m-d\TH:i:s\Z');
        }

        return $shifted->format('Y-m-d\TH:i:sO');
    }
}

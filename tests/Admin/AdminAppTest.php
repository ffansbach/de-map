<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Admin;

use FreifunkKarte\Admin\AdminApp;
use FreifunkKarte\Admin\AdminConfig;
use FreifunkKarte\Admin\Page;
use FreifunkKarte\Admin\PageContext;
use FreifunkKarte\Admin\PageResult;
use FreifunkKarte\Admin\Safe;
use FreifunkKarte\Run\RunRepository;
use FreifunkKarte\Tests\Run\RunFixtures;
use GuzzleHttp\Psr7\Response;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class AdminAppTest extends AdminTestCase
{
    private RunRepository $repository;

    protected function setUp(): void
    {
        $this->repository = RunRepository::inMemory();
    }

    /**
     * A page that reports what it was given.
     */
    private static function echoPage(): Page
    {
        return new class () implements Page {
            public function render(PageContext $context): PageResult
            {
                return new PageResult('Echo <title>', '/echo', new Safe('<p>' . json_encode([$context->params, $context->query]) . '</p>'));
            }
        };
    }

    private function app(?AdminConfig $config = null): AdminApp
    {
        return self::buildApp($this->repository, [
            ['#^/echo$#', self::echoPage()],
            ['#^/items/(?<id>\d+)$#', self::echoPage()],
            ['#^/$#', self::echoPage()],
        ], $config);
    }

    public function testRoutingGivesThePageThePathParametersAndTheQuery(): void
    {
        $response = $this->app()->handle(self::request('/items/42', ['q' => 'x']));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('[{"id":"42"},{"q":"x"}]', (string) $response->getBody());
    }

    public function testTrailingSlashIsIgnoredAndTheRootWorks(): void
    {
        $this->assertSame(200, $this->app()->handle(self::request('/echo/'))->getStatusCode());
        $this->assertSame(200, $this->app()->handle(self::request('/'))->getStatusCode());
        $this->assertSame(200, $this->app()->handle(self::request(''))->getStatusCode());
    }

    public function testThePageIsWrappedInTheLayoutWithAnEscapedTitleAndTheMenu(): void
    {
        $html = $this->assertValidHtml($this->app()->handle(self::request('/echo')));

        $this->assertStringContainsString('<title>Echo &lt;title&gt; - Freifunk Karte - parser</title>', $html);
        $this->assertStringContainsString('<h1>Echo &lt;title&gt;</h1>', $html);
        $this->assertStringContainsString('<a href="/runs">Runs</a>', $html);
        $this->assertStringContainsString('<meta name="robots" content="noindex,nofollow">', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    public function testTheLatestRunIsShownInTheHeader(): void
    {
        $this->repository->finish($this->repository->start(new \DateTimeImmutable('2026-10-29T12:00:00Z'), '/out'), RunFixtures::standard(), RunRepository::OK, new \DateTimeImmutable('2026-10-29T12:00:30Z'));

        $html = (string) $this->app()->handle(self::request('/echo'))->getBody();

        $this->assertStringContainsString('latest run 2026-10-29 13:00', $html);
        $this->assertStringContainsString('<span class="badge run-ok">ok</span>', $html);
    }

    public function testUnknownPagesAreNotFoundButStillInTheLayout(): void
    {
        $response = $this->app()->handle(self::request('/nope'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('There is no page /nope', $this->assertValidHtml($response));
    }

    public function testThePathCannotInjectMarkup(): void
    {
        $response = $this->app()->handle(self::request('/<script>alert(1)</script>'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringNotContainsString('<script>', (string) $response->getBody());
    }

    public function testItIsReadOnly(): void
    {
        foreach (['POST', 'PUT', 'DELETE', 'PATCH'] as $method) {
            $response = $this->app()->handle(self::request('/echo', method: $method));

            $this->assertSame(405, $response->getStatusCode(), $method);
            $this->assertSame('GET, HEAD', $response->getHeaderLine('Allow'));
        }
    }

    public function testHeadGivesTheHeadersWithoutABody(): void
    {
        $get = $this->app()->handle(self::request('/echo'));
        $head = $this->app()->handle(self::request('/echo', method: 'HEAD'));

        $this->assertSame($get->getStatusCode(), $head->getStatusCode());
        $this->assertSame('', (string) $head->getBody());
    }

    public function testSecurityHeadersAreOnEveryKindOfAnswer(): void
    {
        $protected = $this->app(new AdminConfig(user: 'tino', passwordHash: password_hash('secret', PASSWORD_DEFAULT), failedLoginDelayMs: 0));
        $answers = [
            $this->app()->handle(self::request('/echo')),
            $this->app()->handle(self::request('/nope')),
            $this->app()->handle(self::request('/echo', method: 'POST')),
            $this->app()->handle(self::request('/echo', remote: '10.0.0.5')),
            $protected->handle(self::request('/echo')),
            AdminApp::unavailable('No history yet'),
        ];

        foreach ($answers as $response) {
            $this->assertStringContainsString("default-src 'none'", $response->getHeaderLine('Content-Security-Policy'));
            $this->assertStringContainsString("frame-ancestors 'none'", $response->getHeaderLine('Content-Security-Policy'));
            $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
            $this->assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
            $this->assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
            $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        }
    }

    public function testWithoutAPasswordOnlyTheMachineItselfIsLetIn(): void
    {
        $this->assertSame(200, $this->app()->handle(self::request('/echo', remote: '127.0.0.1'))->getStatusCode());
        $this->assertSame(200, $this->app()->handle(self::request('/echo', remote: '::1'))->getStatusCode());

        $remote = $this->app()->handle(self::request('/echo', remote: '192.168.1.20'));
        $this->assertSame(403, $remote->getStatusCode());
        $this->assertStringContainsString('admin:password', (string) $remote->getBody());
        $this->assertSame(403, $this->app()->handle(self::request('/echo', remote: ''))->getStatusCode(), 'an unknown address is not trusted');
    }

    public function testWithAPasswordEveryoneNeedsIt(): void
    {
        $app = $this->app(new AdminConfig(user: 'tino', passwordHash: password_hash('secret', PASSWORD_DEFAULT), failedLoginDelayMs: 0));
        $basic = static fn (string $credentials): array => ['Authorization' => 'Basic ' . base64_encode($credentials)];

        $none = $app->handle(self::request('/echo', remote: '192.168.1.20'));
        $this->assertSame(401, $none->getStatusCode());
        $this->assertStringContainsString('Basic realm=', $none->getHeaderLine('WWW-Authenticate'));

        $this->assertSame(401, $app->handle(self::request('/echo', headers: $basic('tino:wrong')))->getStatusCode());
        $this->assertSame(401, $app->handle(self::request('/echo', headers: $basic('other:secret')))->getStatusCode());
        $this->assertSame(401, $app->handle(self::request('/echo', headers: ['Authorization' => 'Basic !!!not-base64']))->getStatusCode());
        $this->assertSame(401, $app->handle(self::request('/echo', headers: ['Authorization' => 'Bearer abc']))->getStatusCode());

        $this->assertSame(200, $app->handle(self::request('/echo', remote: '192.168.1.20', headers: $basic('tino:secret')))->getStatusCode());
        $this->assertSame(200, $app->handle(self::request('/echo', headers: $basic('tino:secret')))->getStatusCode());
    }

    public function testThePasswordMayContainAColon(): void
    {
        $app = $this->app(new AdminConfig(user: 'tino', passwordHash: password_hash('se:cr:et', PASSWORD_DEFAULT), failedLoginDelayMs: 0));

        $this->assertSame(200, $app->handle(self::request('/echo', headers: ['Authorization' => 'Basic ' . base64_encode('tino:se:cr:et')]))->getStatusCode());
    }

    public function testAFailingPageIsLoggedAndDoesNotLeakItsMessage(): void
    {
        $failing = new class () implements Page {
            public function render(PageContext $context): PageResult
            {
                throw new \RuntimeException('SQLSTATE secret detail /srv/path');
            }
        };
        /** @var \ArrayObject<int, string> $logged */
        $logged = new \ArrayObject();
        $logger = new class ($logged) extends NullLogger implements LoggerInterface {
            /** @param \ArrayObject<int, string> $logged */
            public function __construct(private readonly \ArrayObject $logged)
            {
            }

            public function error(string|\Stringable $message, array $context = []): void
            {
                $this->logged->append($message . ' ' . json_encode($context));
            }
        };

        $app = new AdminApp($this->repository, new AdminConfig(), [['#^/boom$#', $failing]], $logger);
        $response = $app->handle(self::request('/boom'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringNotContainsString('secret', (string) $response->getBody());
        $this->assertCount(1, $logged);
        $this->assertStringContainsString('SQLSTATE secret detail', (string) ($logged[0] ?? ''));
    }

    public function testUnavailableIsAServiceUnavailableAnswer(): void
    {
        $response = AdminApp::unavailable('No history yet');

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('No history yet' . "\n", (string) $response->getBody());
    }

    public function testAWrongPasswordIsAnsweredSlowlyOnPurpose(): void
    {
        $app = $this->app(new AdminConfig(user: 'tino', passwordHash: password_hash('secret', PASSWORD_DEFAULT), failedLoginDelayMs: 150));
        $started = microtime(true);

        $app->handle(self::request('/echo', headers: ['Authorization' => 'Basic ' . base64_encode('tino:wrong')]));

        $this->assertGreaterThanOrEqual(0.14, microtime(true) - $started);
    }
}

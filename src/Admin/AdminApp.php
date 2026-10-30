<?php

declare(strict_types=1);

namespace FreifunkKarte\Admin;

use FreifunkKarte\Run\RunRepository;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * The admin UI as a function from request to response: login, routing, security headers.
 * Read-only: it answers GET and HEAD and nothing else.
 */
final class AdminApp
{
    private const SECURITY_HEADERS = [
        'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src data:; base-uri 'none'; form-action 'self'; frame-ancestors 'none'",
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'no-referrer',
        'Cache-Control' => 'no-store',
    ];

    private readonly Auth $auth;

    /**
     * @param list<array{string, Page}> $routes path pattern (a regex with named groups) => page, first match wins
     */
    public function __construct(
        private readonly RunRepository $repository,
        private readonly AdminConfig $config,
        private readonly array $routes,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly string $basePath = '',
    ) {
        $this->auth = new Auth($config);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $refused = $this->auth->check($request);

        if ($refused !== null) {
            return $this->secure($refused);
        }

        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return $this->secure(new Response(405, ['Allow' => 'GET, HEAD', 'Content-Type' => 'text/plain; charset=utf-8'], "This UI is read-only.\n"));
        }

        Html::setBasePath($this->basePath);

        try {
            $result = $this->route($request);
            $html = Layout::render($result, $this->config, $this->repository->latestRun());
        } catch (Throwable $e) {
            $this->logger->error('admin page failed', ['path' => $request->getUri()->getPath(), 'error' => $e->getMessage(), 'exception' => $e::class]);

            return $this->secure(new Response(500, ['Content-Type' => 'text/plain; charset=utf-8'], "Something went wrong. The details are in the log.\n"));
        } finally {
            Html::setBasePath('');
        }

        return $this->secure(new Response($result->status, ['Content-Type' => 'text/html; charset=utf-8'], $request->getMethod() === 'HEAD' ? '' : $html));
    }

    /**
     * For the front controller when there is no history to show yet.
     */
    public static function unavailable(string $message): ResponseInterface
    {
        $response = new Response(503, ['Content-Type' => 'text/plain; charset=utf-8', 'Retry-After' => '300'], $message . "\n");

        foreach (self::SECURITY_HEADERS as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    private function route(ServerRequestInterface $request): PageResult
    {
        $path = $request->getUri()->getPath();

        if ($this->basePath !== '' && str_starts_with($path, $this->basePath)) {
            $path = substr($path, strlen($this->basePath));
        }

        $path = rtrim($path, '/');
        $path = $path === '' ? '/' : $path;

        foreach ($this->routes as [$pattern, $page]) {
            if (preg_match($pattern, $path, $matches) === 1) {
                $params = array_filter($matches, is_string(...), ARRAY_FILTER_USE_KEY);

                return $page->render(new PageContext($this->repository, $this->config, array_map(strval(...), $params), $request->getQueryParams()));
            }
        }

        return PageResult::notFound('There is no page ' . $path);
    }

    private function secure(ResponseInterface $response): ResponseInterface
    {
        foreach (self::SECURITY_HEADERS as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}

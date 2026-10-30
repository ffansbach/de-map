<?php

declare(strict_types=1);

namespace FreifunkKarte\Admin;

final class Layout
{
    private const NAV = [
        '/' => 'Overview',
        '/runs' => 'Runs',
        '/communities' => 'Communities',
        '/no-data' => 'Maps without data',
        '/fixed' => 'Fixed communities',
        '/events' => 'Events',
    ];

    /**
     * @param array<string, mixed>|null $latestRun
     */
    public static function render(PageResult $page, AdminConfig $config, ?array $latestRun): string
    {
        $nav = '';

        foreach (self::NAV as $path => $label) {
            $nav .= sprintf('<a href="%s"%s>%s</a>', Html::e(Html::url($path)), $page->nav === $path ? ' aria-current="page"' : '', Html::e($label));
        }

        $status = '';

        if ($latestRun !== null) {
            $status = sprintf(
                '<span class="muted">latest run %s</span> %s',
                Html::e(Html::time($latestRun['started_at'] ?? null, $config->timezone)),
                Html::badge((string) $latestRun['status'], 'run-' . $latestRun['status'])->html,
            );
        }

        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow">'
            . '<title>' . Html::e($page->title . ' - ' . $config->title) . '</title>'
            . '<style>' . self::CSS . '</style></head><body>'
            . '<header><strong>' . Html::e($config->title) . '</strong><nav>' . $nav . '</nav><span class="status">' . $status . '</span></header>'
            . '<main><h1>' . Html::e($page->title) . '</h1>' . $page->body->html . '</main>'
            . '<footer class="muted">Read-only view of the run history. Times in ' . Html::e($config->timezone) . '.</footer>'
            . '</body></html>';
    }

    private const CSS = <<<'CSS'
        :root{color-scheme:light dark;--bg:#fafafa;--fg:#1d1d1f;--muted:#6e6e73;--line:#d8d8dc;--card:#fff;--ok:#1a7f37;--warn:#9a6700;--bad:#cf222e;--info:#0969da}
        @media (prefers-color-scheme:dark){:root{--bg:#161618;--fg:#e8e8ea;--muted:#9a9aa0;--line:#38383c;--card:#1f1f22;--ok:#56d364;--warn:#e3b341;--bad:#ff7b72;--info:#79c0ff}}
        *{box-sizing:border-box}
        body{margin:0;background:var(--bg);color:var(--fg);font:15px/1.45 system-ui,-apple-system,"Segoe UI",sans-serif}
        header{display:flex;flex-wrap:wrap;gap:.5rem 1.5rem;align-items:center;padding:.6rem 1rem;border-bottom:1px solid var(--line);background:var(--card)}
        header nav{display:flex;flex-wrap:wrap;gap:.25rem 1rem;flex:1}
        header nav a{color:var(--muted);text-decoration:none;padding:.15rem 0}
        header nav a[aria-current]{color:var(--fg);border-bottom:2px solid var(--info)}
        main{max-width:1200px;margin:0 auto;padding:1rem}
        h1{font-size:1.4rem;margin:.2rem 0 1rem}
        h2{font-size:1.1rem;margin:1.6rem 0 .5rem}
        a{color:var(--info)}
        .muted{color:var(--muted)}
        footer{padding:1rem;text-align:center;font-size:.85rem}
        .scroll{overflow-x:auto}
        table{border-collapse:collapse;width:100%;background:var(--card);border:1px solid var(--line)}
        th,td{padding:.35rem .6rem;border-bottom:1px solid var(--line);text-align:left;vertical-align:top}
        th{font-size:.8rem;text-transform:uppercase;letter-spacing:.03em;color:var(--muted);white-space:nowrap}
        td{word-break:break-word}
        .badge{display:inline-block;padding:0 .5rem;border-radius:1rem;font-size:.8rem;border:1px solid currentColor}
        .badge.ok,.badge.run-ok,.badge.info{color:var(--ok)}
        .badge.warning,.badge.run-refused,.badge.no-data,.badge.stale{color:var(--warn)}
        .badge.alert,.badge.run-failed,.badge.failed{color:var(--bad)}
        .badge.run-running,.badge.skipped,.badge.api-unavailable,.badge.no-nodemaps{color:var(--muted)}
        .cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.75rem;margin-bottom:1rem}
        .card{background:var(--card);border:1px solid var(--line);padding:.6rem .8rem;border-radius:.4rem}
        .card b{display:block;font-size:1.4rem}
        .card span{color:var(--muted);font-size:.85rem}
        dl{display:grid;grid-template-columns:max-content 1fr;gap:.2rem 1rem;margin:0}
        dt{color:var(--muted)}
        dd{margin:0}
        figure.chart{margin:.5rem 0 1rem;background:var(--card);border:1px solid var(--line);padding:.5rem .8rem}
        figure.chart svg{color:var(--info);display:block}
        figcaption{font-size:.9rem;margin-bottom:.2rem}
        .spark{color:var(--info);vertical-align:middle}
        details{background:var(--card);border:1px solid var(--line);padding:.3rem .6rem;margin:.3rem 0}
        summary{cursor:pointer}
        form.filter{display:flex;flex-wrap:wrap;gap:.5rem;align-items:end;margin-bottom:1rem}
        form.filter label{display:flex;flex-direction:column;font-size:.8rem;color:var(--muted)}
        input,select,button{font:inherit;padding:.25rem .4rem}
        .pager{display:flex;gap:1rem;margin:1rem 0}
        code{font-family:ui-monospace,monospace;font-size:.9em}
        CSS;
}

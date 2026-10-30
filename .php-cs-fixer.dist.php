<?php

declare(strict_types=1);

// The plain PHP of the web part (web/) is deliberately not formatted: it has to run on old PHP versions without a build step.
$finder = PhpCsFixer\Finder::create()
    ->in(array_filter(
        [__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/bin', __DIR__ . '/public-admin'],
        is_dir(...)
    ));

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PSR12' => true,
        'declare_strict_types' => false,
        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
        'ordered_imports' => true,
        'single_quote' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],
    ])
    ->setFinder($finder);

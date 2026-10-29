<?php

declare(strict_types=1);

// Legacy code in lib/ is deliberately not formatted: it is being replaced step by step (see PRD.md).
$finder = PhpCsFixer\Finder::create()
    ->in(array_filter(
        [__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/bin'],
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

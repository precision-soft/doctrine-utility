<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

$header = <<<'EOF'
Copyright (c) Precision Soft
EOF;

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__)
    ->exclude([
        'var',
        'vendor',
    ]);

return (new PhpCsFixer\Config())
    ->setRules([
        '@PER-CS2.0' => true,
        '@PER-CS2.0:risky' => true,
        'cast_spaces' => [
            'space' => 'none',
        ],
        'header_comment' => [
            'header' => $header,
        ],
    ])
    ->setRiskyAllowed(true)
    ->setUsingCache(true)
    ->setFinder($finder);

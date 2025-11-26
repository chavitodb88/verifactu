<?php

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/app',
        __DIR__ . '/tests',
    ])
    ->name('*.php')
    ->notName('*.blade.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12'                 => true,
        'array_syntax'           => ['syntax' => 'short'],
        'array_indentation'      => true,
        'binary_operator_spaces' => [
            'default'   => 'single_space',
            'operators' => [
                '=>' => 'align_single_space_minimal',
            ],
        ],
        'blank_line_before_statement' => true,
        'ordered_imports'             => ['sort_algorithm' => 'alpha'],
        'single_quote'                => true,
        'no_unused_imports'           => true,
        'no_whitespace_in_blank_line' => true,
    ])
    ->setFinder($finder);

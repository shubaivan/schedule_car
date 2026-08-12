<?php

/*
 * Той самий набір правил, що й на chiropractor-api, — щоб перехід між
 * проєктами не означав зміну звичок форматування.
 */

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/src', __DIR__ . '/tests'])
    ->exclude('var');

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
        'yoda_style' => [
            'equal' => false,
            'identical' => false,
            'less_and_greater' => false,
        ],
        'concat_space' => ['spacing' => 'one'],
        'ordered_imports' => ['imports_order' => ['class', 'function', 'const'], 'sort_algorithm' => 'alpha'],
        'unary_operator_spaces' => true,
        'not_operator_with_successor_space' => true,
        'trailing_comma_in_multiline' => [
            'elements' => ['arrays', 'arguments', 'parameters'],
        ],
        'array_syntax' => ['syntax' => 'short'],
        'cast_spaces' => ['space' => 'single'],
        'binary_operator_spaces' => true,
        'class_attributes_separation' => [
            'elements' => [
                'method' => 'one',
            ],
        ],
        'phpdoc_to_comment' => false,
        'method_argument_space' => [
            'on_multiline' => 'ensure_fully_multiline',
            'keep_multiple_spaces_after_comma' => true,
        ],
        'single_trait_insert_per_statement' => true,
        'global_namespace_import' => [
            'import_classes' => true,
            'import_constants' => true,
            'import_functions' => true,
        ],
        'operator_linebreak' => [
            'only_booleans' => true,
            'position' => 'end',
        ],
        'multiline_comment_opening_closing' => true,
        'no_multiline_whitespace_around_double_arrow' => false,
        'single_line_throw' => false,
        'phpdoc_align' => ['align' => 'left'],
    ])
    ->setFinder($finder);

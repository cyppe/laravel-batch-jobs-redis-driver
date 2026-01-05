<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

require_once __DIR__ . '/.php-cs-fixer-custom-fixers.php';

$finder = Finder::create()
    ->in(__DIR__ . '/src')
    ->name('*.php')
    ->exclude('vendor');

return (new Config())
    ->setRiskyAllowed(true)
    ->setRules([
        // Base preset (equivalent to Pint's "per" preset)
        '@PER-CS2.0' => true,

        'phpdoc_separation' => [
            'groups' => [
                ['author', 'copyright', 'license', 'category', 'package', 'subpackage', 'deprecated', 'link', 'see', 'since'],
                ['property', 'property-read', 'property-write'],
                ['param'],
                ['return'],
                ['throws'],
            ],
        ],
        'phpdoc_align' => [
            'align' => 'vertical',
        ],
        'spaces_inside_parentheses' => [
            'space' => 'none',
        ],
        'declare_strict_types' => true,
        'self_static_accessor' => true,
        'align_multiline_comment' => true,
        'array_indentation' => true,
        'array_syntax' => true,
        'blank_line_after_namespace' => true,
        'blank_line_after_opening_tag' => true,
        'combine_consecutive_issets' => true,
        'combine_consecutive_unsets' => true,
        'concat_space' => [
            'spacing' => 'one',
        ],
        'binary_operator_spaces' => [
            'operators' => [
                '=>' => 'align_single_space_minimal',
                '=' => 'align_single_space_minimal',
            ],
        ],
        'braces_position' => [
            'allow_single_line_anonymous_functions' => true,
            'allow_single_line_empty_anonymous_classes' => true,
            'anonymous_classes_opening_brace' => 'same_line',
            'anonymous_functions_opening_brace' => 'same_line',
            'classes_opening_brace' => 'next_line_unless_newline_at_signature_end',
            'control_structures_opening_brace' => 'same_line',
            'functions_opening_brace' => 'next_line_unless_newline_at_signature_end',
        ],
        'no_extra_blank_lines' => [
            'tokens' => [
                'extra', 'throw', 'use', 'return',
                'parenthesis_brace_block', 'square_brace_block', 'curly_brace_block',
            ],
        ],
        'blank_line_before_statement' => [
            'statements' => ['return', 'try'],
        ],
        'explicit_string_variable' => true,
        'declare_parentheses' => true,
        'global_namespace_import' => [
            'import_classes' => true,
            'import_constants' => false,
            'import_functions' => false,
        ],
        'is_null' => true,
        'no_unused_imports' => true,
        'lambda_not_used_import' => true,
        'logical_operators' => true,
        'method_chaining_indentation' => true,
        'modernize_strpos' => true,
        'new_with_braces' => true,
        'no_empty_comment' => false,
        'not_operator_with_space' => true,
        'ordered_traits' => true,
        'simplified_if_return' => false,
        'ternary_to_null_coalescing' => true,
        'trim_array_spaces' => true,
        'use_arrow_functions' => false,
        'void_return' => false,
        'yoda_style' => [
            'equal' => false,
            'identical' => false,
            'less_and_greater' => false,
        ],
        'array_push' => true,
        'assign_null_coalescing_to_coalesce_equal' => true,
        'explicit_indirect_variable' => true,
        'method_argument_space' => [
            'on_multiline' => 'ensure_fully_multiline',
        ],
        'modernize_types_casting' => true,
        'no_superfluous_elseif' => true,
        'no_useless_else' => true,
        'nullable_type_declaration_for_default_null_value' => true,
        'ordered_imports' => [
            'sort_algorithm' => 'alpha',
        ],
        'class_attributes_separation' => [
            'elements' => [
                'const' => 'none',
                'method' => 'one',
                'property' => 'none',
                'trait_import' => 'none',
                'case' => 'none',
            ],
        ],
        'ordered_class_elements' => [
            'order' => [
                'use_trait', 'case', 'constant', 'constant_public', 'constant_protected', 'constant_private',
                'property_public', 'property_protected', 'property_private',
                'construct', 'destruct', 'magic', 'phpunit',
                'method_abstract', 'method_public_static', 'method_public',
                'method_protected_static', 'method_protected', 'method_private_static', 'method_private',
            ],
            'sort_algorithm' => 'none',
        ],

        // Your custom named parameter alignment fixer
        'Motoaction/align_named_parameters' => [
            'minimum_parameters' => 2,
        ],
    ])
    ->setFinder($finder)
    ->registerCustomFixers(getCustomFixers());

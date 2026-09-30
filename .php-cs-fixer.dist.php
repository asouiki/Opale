<?php

// Périmètre analysé : code applicatif + tests. Le dossier vendor/ est exclu par défaut.
$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests']);

// Règles non « risky » uniquement : aucune réécriture susceptible de changer le comportement.
return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    // Autorise l'exécution sur PHP 8.5, non encore « officiellement supporté » par cs-fixer 3.x.
    ->setUnsupportedPhpVersionAllowed(true)
    ->setRules([
        '@PSR12'                                     => true,
        'array_syntax'                               => ['syntax' => 'short'],
        'ordered_imports'                            => true,
        'no_unused_imports'                          => true,
        'single_quote'                               => true,
        'no_singleline_whitespace_before_semicolons' => true,
        'space_after_semicolon'                      => true,
    ])
    ->setFinder($finder);

<?php

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->name('*.php')
    // Backup/history files are gitignored and not source of truth — never reformat them.
    ->notName('*-bak*');

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12' => true,
        // House style: function declarations open their brace on the same line (K&R).
        // Class and control-structure braces already match PSR-12, so they stay at the
        // defaults — do not widen this override to those, it would move them the wrong way.
        'braces_position' => [
            'functions_opening_brace' => 'same_line',
        ],
    ])
    ->setFinder($finder)
    ->setRiskyAllowed(false)
    // core.autocrlf=true checks these files out as CRLF while git stores them as LF.
    // Match the checkout, or the writer emits LF and every run rewrites all 1087
    // lines of BatchEncoder.php, burying the real formatting diff.
    ->setLineEnding("\r\n");

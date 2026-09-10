<?php

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->name('*.php')
    // Backup/history files are gitignored and not source of truth — never reformat them.
    ->notName('*-bak*');

return (new PhpCsFixer\Config())
    ->setRules([
        // PER Coding Style 3.0, the successor to the now-deprecated PSR-12.
        // Pinned to the "x0" spelling: the dotted form (@PER-CS3.0) is deprecated
        // and is scheduled for removal in PHP-CS-Fixer 4.0. The bare @PER-CS alias
        // tracks the newest revision, so it would silently move us when 3.1 lands —
        // pin until 3.1 support is intentional.
        '@PER-CS3x0' => true,
    ])
    ->setFinder($finder)
    ->setRiskyAllowed(false)
    // core.autocrlf=true checks these files out as CRLF while git stores them as LF.
    // Match the checkout, or the writer emits LF and every run rewrites all 1087
    // lines of BatchEncoder.php, burying the real formatting diff.
    ->setLineEnding("\r\n");

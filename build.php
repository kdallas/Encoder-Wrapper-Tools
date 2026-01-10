#!/usr/bin/php
<?php

$pharFile = 'batch-encoder.phar';

// Cleanup: Remove old build if it exists
if (file_exists($pharFile)) {
    unlink($pharFile);
}

// Initialize Phar
$phar = new Phar($pharFile);

// Add class files
$phar->addFile('run.php');
$phar->addFile('BatchEncoder.php');
$phar->addFile('Config.php');
$phar->addFile('Probe.php');
$phar->addFile('Profiles.php');
$phar->addFile('ScanDir.php');

// Exclude the build script itself
if (isset($phar['build.php'])) {
    unset($phar['build.php']);
}

// Set the "Stub"
$defaultStub = $phar->createDefaultStub('run.php');

// Add the shebang line so it runs directly on Linux/Mac/GitBash
$stub = "#!/usr/bin/php\n" . $defaultStub;

$phar->setStub($stub);

// Finalize
$phar->stopBuffering();

// Make executable (Unix/Linux/GitBash only)
chmod($pharFile, 0770);

echo "Build complete: $pharFile\n";

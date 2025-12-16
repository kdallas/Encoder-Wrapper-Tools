#!/usr/bin/php
<?php

/*
USAGE EXAMPLES:
    Standard:   ./run.php --path="..." --prefix=Show  --video=2pass --resize=1280x720
    Dynamic:    ./run.php --path="..." --prefix=Movie --video=cqp   --q=18
    Cropping:   ./run.php --path="..." --prefix=Movie --video=cqp   --crop=0,140,0,140
    Recursive:  ./run.php --path="..." --prefix=Show  --recursive
*/

spl_autoload_register(function ($class_name) {
    include $class_name . '.php';
});

$app = new BatchEncoder($argv);
$app->run();

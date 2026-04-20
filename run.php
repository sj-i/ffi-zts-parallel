#!/usr/bin/env php
<?php
declare(strict_types=1);

foreach ([
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/../../autoload.php',
] as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        break;
    }
}

use SjI\FfiZts\Parallel\Parallel;

$script = $argv[1] ?? __DIR__ . '/examples/sum.php';
Parallel::boot()->runScript($script);

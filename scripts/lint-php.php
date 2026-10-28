<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';

$projectRoot = dirname(__DIR__);
$finder = (new Finder)
    ->files()
    ->name('*.php')
    ->in([
        $projectRoot.'/app',
        $projectRoot.'/bootstrap',
        $projectRoot.'/config',
        $projectRoot.'/database',
        $projectRoot.'/routes',
        $projectRoot.'/scripts',
        $projectRoot.'/tests',
    ]);

$failed = false;

foreach ($finder as $file) {
    $process = new Process([PHP_BINARY, '-l', $file->getRealPath()], $projectRoot);
    $process->run();

    if (! $process->isSuccessful()) {
        $failed = true;
        fwrite(STDERR, $process->getErrorOutput().$process->getOutput());
    }
}

if ($failed) {
    exit(1);
}

fwrite(STDOUT, sprintf("PHP syntax check passed for %d files.\n", $finder->count()));

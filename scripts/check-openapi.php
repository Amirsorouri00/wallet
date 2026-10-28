<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';

$projectRoot = dirname(__DIR__);
$committedPath = $projectRoot.'/docs/openapi.json';
$generatedPath = tempnam(sys_get_temp_dir(), 'taline-openapi-');

if ($generatedPath === false) {
    fwrite(STDERR, "Unable to allocate a temporary OpenAPI file.\n");
    exit(1);
}

try {
    $process = new Process([
        PHP_BINARY,
        $projectRoot.'/artisan',
        'scramble:export',
        '--env=testing',
        '--path='.$generatedPath,
        '--fail-on-unknown',
        '--no-interaction',
    ], $projectRoot);
    $process->setTimeout(120);
    $process->run(static function (string $type, string $output): void {
        fwrite($type === Process::ERR ? STDERR : STDOUT, $output);
    });

    if (! $process->isSuccessful()) {
        exit($process->getExitCode() ?? 1);
    }

    if (! is_file($committedPath)) {
        fwrite(STDERR, "docs/openapi.json is missing. Run composer docs:export.\n");
        exit(1);
    }

    if (file_get_contents($committedPath) !== file_get_contents($generatedPath)) {
        fwrite(STDERR, "docs/openapi.json is stale. Run composer docs:export and commit the result.\n");
        exit(1);
    }

    fwrite(STDOUT, "docs/openapi.json matches the generated contract.\n");
} finally {
    @unlink($generatedPath);
}

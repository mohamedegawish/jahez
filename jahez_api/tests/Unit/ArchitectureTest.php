<?php

arch('application code contains no debugging or output statements')
    ->preset()
    ->php();

arch('application code avoids insecure functions')
    ->preset()
    ->security();

arch('environment variables are read only in configuration files')
    ->expect('env')
    ->not->toBeUsedIn('App');

test('readiness-based eligibility has one source: only ServiceEligibility uses the provider eligibility scopes (ADR-025)', function () {
    $callers = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/app', FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        $path = str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname());

        if (str_ends_with($path, '.php') && preg_match('/->(eligibleFor|offering)\(/', (string) file_get_contents($path)) === 1) {
            $callers[] = substr($path, strpos($path, '/app/') + 1);
        }
    }

    expect($callers)->toBe(['app/Readiness/ServiceEligibility.php']);
});

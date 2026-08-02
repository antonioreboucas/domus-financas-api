<?php

// Resolves which config file is active. config.php is the file meant to be
// uploaded to production (HostGator) — see README.md "Deploy". Any script
// run locally (php -S, php cron/*.php, php tests/run.php) picks up
// config.local.php instead, when present, so testing on a dev machine can
// never silently point at the production database just because config.php
// happens to hold production credentials at the time.
class Config
{
    public static function load(): array
    {
        $localPath = __DIR__ . '/../config.local.php';
        $path = file_exists($localPath) ? $localPath : __DIR__ . '/../config.php';
        return require $path;
    }
}

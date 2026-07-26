<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

// Defense in depth: never run the suite unless APP_ENV is forced to test.
// phpunit.dist.xml sets this with force="true"; abort if something overrode it.
$appEnv = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? null;
if ('test' !== $appEnv) {
    fwrite(STDERR, sprintf(
        "FATAL: Refusing to run tests with APP_ENV=%s. Expected APP_ENV=test so production data cannot be touched.\n",
        var_export($appEnv, true)
    ));
    exit(1);
}

// Prefer the committed test DB URL even if the shell/.env.local exports a prod DSN.
// The doctrine when@test config also hardcodes SQLite; this covers code that reads $_ENV directly.
$testDatabaseUrl = 'sqlite:///%kernel.project_dir%/var/data_test.db';
$_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = $testDatabaseUrl;
putenv('DATABASE_URL='.$testDatabaseUrl);

if ($_SERVER['APP_DEBUG'] ?? false) {
    umask(0000);
}

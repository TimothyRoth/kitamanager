<?php

// Exits 0 when the login page renders in the current environment, 1 otherwise.
// Run by bin/update.sh after every deployment.

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

$kernel = new Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$status = $kernel->handle(Request::create('/admin'))->getStatusCode();

if (200 !== $status) {
    fwrite(STDERR, sprintf("The login page answers with HTTP %d. See var/log/ for the reason.\n", $status));
    exit(1);
}

echo "The login page loads.\n";

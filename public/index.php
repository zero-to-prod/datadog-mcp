<?php

require_once __DIR__.'/../vendor/autoload.php';

// Load environment variables
$env_file = __DIR__.'/../.env';
if (file_exists($env_file)) {
    $env_vars = Zerotoprod\Phpdotenv\Phpdotenv::parseFromString(file_get_contents($env_file));
    foreach ($env_vars as $key => $value) {
        $_ENV[$key] = $value;
        putenv("{$key}={$value}");
    }
}

require_once __DIR__.'/../bootstrap/app.php';
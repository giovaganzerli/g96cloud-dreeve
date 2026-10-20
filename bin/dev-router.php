<?php

declare(strict_types=1);

/*
 * Router for the PHP built-in web server.
 *
 * The built-in server routes every request through the router script, so static
 * files would be served by Symfony with a text/html content type. Browsers refuse
 * to apply a stylesheet with the wrong mime type, which results in an unstyled page.
 *
 * Returning false tells the built-in server to serve the requested file itself.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__.'/../public'.$path;

if ('/' !== $path && is_file($file)) {
    return false;
}

require __DIR__.'/../public/index.php';

<?php

declare(strict_types=1);

// In this repo, public/'s sibling is the rest of server/ (dirname(__DIR__)/vendor) — that's the
// default. Some hosts (e.g. WebHostMost, see .github/workflows/deploy.yml) can't serve a nested
// public/index.php directly and instead deploy public/'s contents to the web root with
// everything else (vendor/, src/, database/, ...) in a separate, non-servable location — in that
// case the deploy step also writes app-path.php next to this file, defining the real app root.
$appRoot = dirname(__DIR__);
$appPathOverride = __DIR__ . '/app-path.php';
if (is_file($appPathOverride)) {
    $appRoot = require $appPathOverride;
}
require $appRoot . '/vendor/autoload.php';

use Reflexometr\Config;
use Reflexometr\Http\ErrorCode;
use Reflexometr\Http\Request;
use Reflexometr\Http\Response;
use Reflexometr\Http\Router;
use Reflexometr\Routes;

Config::load();

$router = new Router();
Routes::register($router);

$request = Request::capture();

try {
    [$status, $body] = $router->dispatch($request);
} catch (\Throwable $e) {
    // Last-resort safety net — never leak an English exception message to the client (CR-UI-02).
    error_log('[reflexometr] unhandled: ' . $e->getMessage());
    // TEMPORARY (2026-09-17): also write to a fetchable file, since cPanel's error log UI isn't
    // locatable on this account. Remove this block once the live 500s are diagnosed — do not ship
    // a debug log endpoint long-term (CR-UI-02: never leak exception detail to the client either).
    @file_put_contents(
        __DIR__ . '/debug.log',
        sprintf(
            "[%s] %s: %s in %s:%d\n%s\n\n",
            date('c'),
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        ),
        FILE_APPEND
    );
    [$status, $body] = Response::error(ErrorCode::INTERNAL_ERROR, 500);
}

Response::send($status, $body);

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
use Reflexometr\Support\DebugLog;

Config::load();

$router = new Router();
Routes::register($router);

$request = Request::capture();

// HF-02: prove the request reached PHP at all, before dispatch can throw/hang.
DebugLog::write('request.start', ['method' => $request->method, 'path' => $request->path]);

try {
    [$status, $body] = $router->dispatch($request);
    DebugLog::write('request.done', ['status' => $status]);
} catch (\Throwable $e) {
    // Last-resort safety net — never leak an English exception message to the client (CR-UI-02).
    error_log('[reflexometr] unhandled: ' . $e->getMessage());
    DebugLog::write('request.exception', [
        'class' => get_class($e),
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    [$status, $body] = Response::error(ErrorCode::INTERNAL_ERROR, 500);
}

Response::send($status, $body);

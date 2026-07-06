<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

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
    [$status, $body] = Response::error(ErrorCode::INTERNAL_ERROR, 500);
}

Response::send($status, $body);

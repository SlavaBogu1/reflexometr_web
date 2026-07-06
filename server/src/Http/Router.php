<?php

declare(strict_types=1);

namespace Reflexometr\Http;

/**
 * Minimal regex-based router — deliberately dependency-free (HostGator shared hosting has no
 * guaranteed Composer package cache beyond what we vendor ourselves). Routes are registered as
 * `{param}` path templates, e.g. `/r-tests/{slug}/runs`.
 */
final class Router
{
    /** @var array<int,array{method:string,pattern:string,regex:string,paramNames:array<int,string>,handler:callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $paramNames = [];
        $regex = preg_replace_callback('#\{([a-zA-Z_]+)\}#', function ($m) use (&$paramNames) {
            $paramNames[] = $m[1];
            return '([^/]+)';
        }, $pattern);
        $regex = '#^' . $regex . '$#';

        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'regex' => $regex,
            'paramNames' => $paramNames,
            'handler' => $handler,
        ];
    }

    /**
     * @return array{0:int,1:array} [status, bodyArray]
     */
    public function dispatch(Request $request): array
    {
        $methodMatchedAnyPattern = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $m)) {
                continue;
            }
            if ($route['method'] !== strtoupper($request->method)) {
                $methodMatchedAnyPattern = true;
                continue;
            }

            $params = [];
            foreach ($route['paramNames'] as $i => $name) {
                $params[$name] = urldecode($m[$i + 1]);
            }
            $request->params = $params;

            try {
                $result = ($route['handler'])($request);
                if (is_array($result) && count($result) === 2 && is_int($result[0])) {
                    return $result;
                }
                return Response::json($result);
            } catch (ApiException $e) {
                return Response::error($e->errorCode(), $e->status(), $e->details());
            }
        }

        if ($methodMatchedAnyPattern) {
            return Response::error(ErrorCode::METHOD_NOT_ALLOWED, 405);
        }
        return Response::error(ErrorCode::ROUTE_NOT_FOUND, 404);
    }
}

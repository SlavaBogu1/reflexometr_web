<?php

declare(strict_types=1);

namespace Reflexometr\Http;

/**
 * Thin wrapper over the incoming HTTP request. Constructed once by the front controller (or by
 * tests, via fromArrays()) and passed down to controllers.
 */
final class Request
{
    /** @param array<string,string> $params Route parameters extracted by the Router. */
    private function __construct(
        public readonly string $method,
        public readonly string $path,
        private readonly array $headers,
        private readonly array $query,
        private readonly array $body,
        private readonly array $files,
        public array $params = [],
    ) {
    }

    public static function capture(): self
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
	$path = $_SERVER['PATH_INFO'] ?? parse_url($uri, PHP_URL_PATH) ?: '/';


        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', strtolower(substr($key, 5)));
                $headers[$name] = $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }

        $body = [];
        $contentType = $headers['content-type'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input') ?: '';
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $body = $decoded;
                }
            }
        } else {
            $body = $_POST;
        }

        return new self($method, $path, $headers, $_GET, $body, $_FILES);
    }

    /**
     * Test/manual construction helper.
     * @param array<string,string> $headers
     * @param array<string,mixed> $query
     * @param array<string,mixed> $body
     * @param array<string,mixed> $files
     */
    public static function fromArrays(
        string $method,
        string $path,
        array $headers = [],
        array $query = [],
        array $body = [],
        array $files = [],
    ): self {
        $normalizedHeaders = [];
        foreach ($headers as $key => $value) {
            $normalizedHeaders[strtolower($key)] = $value;
        }
        return new self($method, $path, $normalizedHeaders, $query, $body, $files);
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function bearerToken(): ?string
    {
        $auth = $this->header('authorization');
        if ($auth !== null && preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) {
            return $m[1];
        }
        return null;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->body;
    }

    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    public function param(string $key, ?string $default = null): ?string
    {
        return $this->params[$key] ?? $default;
    }
}

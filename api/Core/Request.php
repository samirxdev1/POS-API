<?php

namespace Api\Core;

class Request
{
    private string $method;
    private string $uri;
    private array $headers;
    private array $queryParams;
    private array $body;
    private ?array $tokenRecord = null;
    private array $routeParams = [];

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        $uriParts = explode('?', $requestUri, 2);
        $this->uri = '/' . trim($uriParts[0], '/');
        if ($this->uri === '//') {
            $this->uri = '/';
        }

        $this->headers = $this->collectHeaders();
        $this->queryParams = $_GET;
        $this->body = $this->parseBody();
    }

    private function collectHeaders(): array
    {
        $headers = [];
        if (function_exists('apache_request_headers')) {
            $apacheHeaders = apache_request_headers();
            if (is_array($apacheHeaders)) {
                foreach ($apacheHeaders as $k => $v) {
                    $headers[strtolower($k)] = $v;
                }
            }
        }

        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($k, 5)));
                $headers[$name] = $v;
            } elseif (in_array($k, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'AUTHORIZATION'])) {
                $name = strtolower(str_replace('_', '-', $k));
                $headers[$name] = $v;
            }
        }

        if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) && !isset($headers['authorization'])) {
            $headers['authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        return $headers;
    }

    private function parseBody(): array
    {
        $raw = file_get_contents('php://input');
        if (!empty($raw)) {
            $json = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
                return $json;
            }
        }

        return is_array($_POST) ? $_POST : [];
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    public function getHeader(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function bearerToken(): ?string
    {
        $auth = $this->getHeader('authorization');
        if ($auth && preg_match('/Bearer\s+(.*)$/i', $auth, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->queryParams[$key] ?? $default;
    }

    public function post(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function input(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return array_merge($this->queryParams, $this->body);
        }

        return $this->body[$key] ?? $this->queryParams[$key] ?? $default;
    }

    public function all(): array
    {
        return array_merge($this->queryParams, $this->body);
    }

    public function query(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->queryParams;
        }
        return $this->queryParams[$key] ?? $default;
    }

    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    public function param(string $key, mixed $default = null): mixed
    {
        return $this->routeParams[$key] ?? $default;
    }

    public function getRouteParams(): array
    {
        return $this->routeParams;
    }

    public function setTokenRecord(array $token): void
    {
        $this->tokenRecord = $token;
    }

    public function getTokenRecord(): ?array
    {
        return $this->tokenRecord;
    }

    public function getBusinessId(): int
    {
        if ($this->tokenRecord && !empty($this->tokenRecord['business_id'])) {
            return (int)$this->tokenRecord['business_id'];
        }
        $headerVal = $this->getHeader('x-business-id');
        if (!empty($headerVal) && is_numeric($headerVal)) {
            return (int)$headerVal;
        }
        $queryVal = $this->get('business_id');
        if (!empty($queryVal) && is_numeric($queryVal)) {
            return (int)$queryVal;
        }
        return 1;
    }

    public function getUserId(): int
    {
        if ($this->tokenRecord && !empty($this->tokenRecord['user_id'])) {
            return (int)$this->tokenRecord['user_id'];
        }
        return 1;
    }

    public function ip(): string
    {
        return $_SERVER['HTTP_CF_CONNECTING_IP']
            ?? $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['REMOTE_ADDR']
            ?? '127.0.0.1';
    }

    public function userAgent(): string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    }
}


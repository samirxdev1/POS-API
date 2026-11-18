<?php

namespace Api\Core;

class Router
{
    private array $routes = [];
    private string $prefix = '';

    public function prefix(string $prefix, callable $callback): void
    {
        $previousPrefix = $this->prefix;
        $this->prefix = rtrim($previousPrefix, '/') . '/' . trim($prefix, '/');
        $callback($this);
        $this->prefix = $previousPrefix;
    }

    public function addRoute(string $method, string $path, mixed $handler, string $description = '', array $scopes = [], array $requiredParams = []): void
    {
        $fullPath = '/' . trim($this->prefix . '/' . trim($path, '/'), '/');
        if ($fullPath === '//') {
            $fullPath = '/';
        }

        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $fullPath,
            'pattern' => $this->convertPathToRegex($fullPath),
            'handler' => $handler,
            'description' => $description,
            'scopes' => $scopes,
            'required_params' => $requiredParams,
        ];
    }

    public function get(string $path, mixed $handler, string $description = '', array $scopes = [], array $requiredParams = []): void
    {
        $this->addRoute('GET', $path, $handler, $description, $scopes, $requiredParams);
    }

    public function post(string $path, mixed $handler, string $description = '', array $scopes = [], array $requiredParams = []): void
    {
        $this->addRoute('POST', $path, $handler, $description, $scopes, $requiredParams);
    }

    public function put(string $path, mixed $handler, string $description = '', array $scopes = [], array $requiredParams = []): void
    {
        $this->addRoute('PUT', $path, $handler, $description, $scopes, $requiredParams);
    }

    public function delete(string $path, mixed $handler, string $description = '', array $scopes = [], array $requiredParams = []): void
    {
        $this->addRoute('DELETE', $path, $handler, $description, $scopes, $requiredParams);
    }

    public function getRoutes(): array
    {
        return $this->routes;
    }

    public function match(string $method, string $uri): ?array
    {
        $method = strtoupper($method);
        $cleanUri = '/' . trim($uri, '/');
        if ($cleanUri === '//') {
            $cleanUri = '/';
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (preg_match($route['pattern'], $cleanUri, $matches)) {
                $params = [];
                foreach ($matches as $k => $v) {
                    if (!is_numeric($k)) {
                        $params[$k] = $v;
                    }
                }

                return [
                    'route' => $route,
                    'params' => $params,
                ];
            }
        }

        return null;
    }

    private function convertPathToRegex(string $path): string
    {
        $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[^/]+)', $path);
        return '#^' . $pattern . '$#';
    }
}


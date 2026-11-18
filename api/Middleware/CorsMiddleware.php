<?php

namespace Api\Middleware;

use Api\Core\Request;
use Api\Core\Response;

class CorsMiddleware
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config['cors'] ?? [];
    }

    public function handle(Request $request): ?Response
    {
        $allowedOrigins = $this->config['allowed_origins'] ?? ['*'];
        $origin = $request->getHeader('origin') ?? '*';

        if (in_array('*', $allowedOrigins) || in_array($origin, $allowedOrigins)) {
            header("Access-Control-Allow-Origin: {$origin}");
        }

        header('Access-Control-Allow-Methods: ' . implode(', ', $this->config['allowed_methods'] ?? ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS']));
        header('Access-Control-Allow-Headers: ' . implode(', ', $this->config['allowed_headers'] ?? ['Content-Type', 'Authorization', 'X-Requested-With']));
        header('Access-Control-Max-Age: ' . ($this->config['max_age'] ?? 86400));

        if ($request->getMethod() === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        return null;
    }
}


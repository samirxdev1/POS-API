<?php

namespace Api\Middleware;

use Api\Core\Request;
use Api\Core\Response;

class RateLimitMiddleware
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config['rate_limit'] ?? [];
    }

    public function handle(Request $request): ?Response
    {
        if (empty($this->config['enabled'])) {
            return null;
        }

        $maxRequests = (int)($this->config['max_requests'] ?? 120);
        $windowSeconds = (int)($this->config['window_seconds'] ?? 60);

        // Key by token ID if authenticated, otherwise IP
        $tokenRecord = $request->getTokenRecord();
        $identifier = $tokenRecord ? 'token_' . $tokenRecord['id'] : 'ip_' . md5($request->ip());

        $storageDir = $this->config['storage_path'] ?? (sys_get_temp_dir() . '/api_rate_limits');
        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0777, true);
        }

        $cacheFile = $storageDir . '/' . $identifier . '.json';
        $now = time();

        $data = ['count' => 0, 'reset_at' => $now + $windowSeconds];
        if (file_exists($cacheFile)) {
            $content = @file_get_contents($cacheFile);
            if ($content) {
                $decoded = json_decode($content, true);
                if (is_array($decoded) && isset($decoded['reset_at']) && $decoded['reset_at'] > $now) {
                    $data = $decoded;
                }
            }
        }

        $data['count']++;
        @file_put_contents($cacheFile, json_encode($data), LOCK_EX);

        $remaining = max(0, $maxRequests - $data['count']);
        header('X-RateLimit-Limit: ' . $maxRequests);
        header('X-RateLimit-Remaining: ' . $remaining);
        header('X-RateLimit-Reset: ' . $data['reset_at']);

        if ($data['count'] > $maxRequests) {
            return Response::tooManyRequests("Rate limit of {$maxRequests} requests per {$windowSeconds}s exceeded. Try again in " . ($data['reset_at'] - $now) . " seconds.");
        }

        return null;
    }
}


<?php

namespace Api\Middleware;

use Api\Core\Request;
use Api\Core\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LoggingMiddleware
{
    private array $config;
    private float $startTime;

    public function __construct(array $config = [])
    {
        $this->config = $config['logging'] ?? [];
        $this->startTime = microtime(true);
    }

    public function log(Request $request, Response $response): void
    {
        if (empty($this->config['enabled'])) {
            return;
        }

        $endpoint = $request->getUri();
        $excluded = $this->config['exclude_endpoints'] ?? [];
        if (in_array($endpoint, $excluded, true)) {
            return;
        }

        $durationMs = round((microtime(true) - $this->startTime) * 1000, 2);
        $tokenRecord = $request->getTokenRecord();

        try {
            if (Schema::hasTable('api_logs')) {
                DB::table('api_logs')->insert([
                    'token_id' => $tokenRecord['id'] ?? null,
                    'business_id' => $request->getBusinessId(),
                    'endpoint' => substr($endpoint, 0, 255),
                    'method' => substr($request->getMethod(), 0, 10),
                    'status_code' => $response->getStatusCode(),
                    'ip_address' => substr($request->ip(), 0, 45),
                    'user_agent' => substr($request->userAgent(), 0, 255),
                    'duration_ms' => $durationMs,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }
        } catch (\Throwable $e) {
            // Silently fail to avoid breaking response if DB logging fails
            error_log('API Logging error: ' . $e->getMessage());
        }
    }
}


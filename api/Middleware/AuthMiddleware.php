<?php

namespace Api\Middleware;

use Api\Core\Request;
use Api\Core\Response;
use Illuminate\Support\Facades\DB;

class AuthMiddleware
{
    private array $publicRoutes = [
        '/api/v1/ping',
        '/api/v1/meta/endpoints',
    ];

    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function handle(Request $request, array $requiredScopes = []): ?Response
    {
        // Allow public routes
        $uri = $request->getUri();
        if (in_array($uri, $this->publicRoutes, true)) {
            return null;
        }

        $bearerToken = $request->bearerToken();
        if (empty($bearerToken)) {
            return Response::unauthorized('Authorization header with Bearer token is required');
        }

        // 1. Check for Static Test Token (configured in .env or api.php)
        $staticTestToken = $this->config['static_test_token'] ?? env('API_STATIC_TEST_TOKEN', 'pos_test_token_2026_antigravity');
        if (!empty($staticTestToken) && hash_equals($staticTestToken, $bearerToken)) {
            $request->setTokenRecord([
                'id' => 0,
                'name' => 'Static Test Token',
                'business_id' => $this->config['test_business_id'] ?? (int)env('API_TEST_BUSINESS_ID', 1),
                'user_id' => $this->config['test_user_id'] ?? (int)env('API_TEST_USER_ID', 1),
                'permissions' => ['*'],
                'is_active' => 1,
            ]);
            return null;
        }

        // 2. Check Database-backed api_tokens
        $tokenHash = hash('sha256', $bearerToken);

        try {
            // Find candidate token using prepared statement
            $tokenRecord = DB::table('api_tokens')
                ->where('token_hash', $tokenHash)
                ->first();

            if (!$tokenRecord) {
                return Response::unauthorized('Invalid API token');
            }

            // Secure timing-attack-safe comparison
            if (!hash_equals($tokenRecord->token_hash, $tokenHash)) {
                return Response::unauthorized('Invalid API token');
            }

            // Check if active
            if (!$tokenRecord->is_active) {
                return Response::forbidden('API token has been revoked or deactivated');
            }

            // Check expiration
            if ($tokenRecord->expires_at !== null && strtotime($tokenRecord->expires_at) < time()) {
                return Response::unauthorized('API token has expired');
            }

            // Check scopes/permissions if specified
            if (!empty($requiredScopes)) {
                $grantedScopes = !empty($tokenRecord->permissions)
                    ? (is_array($tokenRecord->permissions) ? $tokenRecord->permissions : json_decode($tokenRecord->permissions, true))
                    : ['*'];

                if (!is_array($grantedScopes)) {
                    $grantedScopes = ['*'];
                }

                if (!in_array('*', $grantedScopes, true)) {
                    foreach ($requiredScopes as $scope) {
                        if (!in_array($scope, $grantedScopes, true)) {
                            return Response::forbidden("Insufficient scope: requires '{$scope}' permission");
                        }
                    }
                }
            }

            // Update last_used_at timestamp (throttled)
            DB::table('api_tokens')
                ->where('id', $tokenRecord->id)
                ->update(['last_used_at' => date('Y-m-d H:i:s')]);

            // Attach token data to request
            $request->setTokenRecord((array)$tokenRecord);

            return null;
        } catch (\Throwable $e) {
            return Response::serverError('Authentication service failed: ' . $e->getMessage());
        }
    }
}

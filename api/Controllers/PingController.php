<?php

namespace Api\Controllers;

use Api\Core\Request;
use Api\Core\Response;
use Api\Core\Router;
use Illuminate\Support\Facades\DB;

class PingController extends BaseController
{
    private Router $router;

    public function __construct(Router $router)
    {
        parent::__construct();
        $this->router = $router;
    }

    public function ping(Request $request): Response
    {
        $dbStatus = 'connected';
        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $dbStatus = 'disconnected: ' . $e->getMessage();
        }

        return Response::success([
            'status' => 'healthy',
            'api_version' => '1.0.0',
            'app' => 'UltimatePOS REST API',
            'php_version' => PHP_VERSION,
            'database' => $dbStatus,
            'timestamp' => date('c'),
            'client_ip' => $request->ip(),
        ], 'API is online and healthy');
    }

    public function endpoints(Request $request): Response
    {
        $routes = $this->router->getRoutes();
        $formatted = [];

        foreach ($routes as $route) {
            $formatted[] = [
                'method' => $route['method'],
                'path' => $route['path'],
                'description' => $route['description'],
                'scopes' => $route['scopes'],
                'required_params' => $route['required_params'],
            ];
        }

        return Response::success($formatted, 'Endpoint catalog retrieved', [
            'total_endpoints' => count($formatted),
        ]);
    }
}


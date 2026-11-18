<?php
/**
 * UltimatePOS REST API Entry Point
 *
 * Provides a secure, token-authenticated RESTful API layer for UltimatePOS.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

// Set JSON content header early
header('Content-Type: application/json; charset=utf-8');

// PSR-4 Autoloader for Api\ namespace
spl_autoload_register(function ($class) {
    $prefix = 'Api\\';
    $baseDir = __DIR__ . '/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// Bootstrap Laravel Application for database, models, and service utils
$laravelRoot = dirname(__DIR__);
require_once $laravelRoot . '/vendor/autoload.php';
$app = require_once $laravelRoot . '/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Api\Core\Request;
use Api\Core\Response;
use Api\Core\Router;
use Api\Middleware\AuthMiddleware;
use Api\Middleware\CorsMiddleware;
use Api\Middleware\LoggingMiddleware;
use Api\Middleware\RateLimitMiddleware;
use Api\Controllers\PingController;
use Api\Controllers\ProductController;
use Api\Controllers\TaxonomyController;
use Api\Controllers\InventoryController;
use Api\Controllers\ContactController;
use Api\Controllers\SaleController;
use Api\Controllers\ReturnController;
use Api\Controllers\PurchaseController;
use Api\Controllers\PaymentController;
use Api\Controllers\ExpenseController;
use Api\Controllers\ReportController;
use Api\Controllers\UserController;
use Api\Controllers\SettingController;

$config = require __DIR__ . '/config/api.php';
$request = new Request();
$loggingMiddleware = new LoggingMiddleware($config);

// Global Exception Handler
set_exception_handler(function (\Throwable $e) use ($request, $loggingMiddleware) {
    $response = Response::serverError('An unexpected server error occurred: ' . $e->getMessage());
    $loggingMiddleware->log($request, $response);
    $response->send();
});

// 1. CORS Preflight & Headers
$cors = new CorsMiddleware($config);
$corsResponse = $cors->handle($request);
if ($corsResponse) {
    $corsResponse->send();
}

// 2. Setup Router & Routes
$router = new Router();

$router->prefix('/api/v1', function (Router $r) use ($router) {
    // Discovery & Health Check
    $ping = new PingController($router);
    $r->get('/ping', [$ping, 'ping'], 'Health check and service status');
    $r->get('/meta/endpoints', [$ping, 'endpoints'], 'Discovery catalog of all available API endpoints');

    // Products
    $prod = new ProductController();
    $r->get('/products', [$prod, 'index'], 'List products with filters, search, and pagination', ['products:read']);
    $r->get('/products/search', [$prod, 'search'], 'Quick product search by name, SKU, or barcode', ['products:read'], ['term']);
    $r->get('/products/{id}', [$prod, 'show'], 'Get single product details with variations and stock', ['products:read']);
    $r->post('/products', [$prod, 'store'], 'Create new single or variable product', ['products:write'], ['name', 'unit_id', 'type']);
    $r->put('/products/{id}', [$prod, 'update'], 'Update existing product details', ['products:write']);
    $r->delete('/products/{id}', [$prod, 'destroy'], 'Delete product if not linked to transactions', ['products:write']);
    $r->post('/products/bulk-import', [$prod, 'bulkImport'], 'Bulk import multiple products in a transaction', ['products:write'], ['products']);

    // Taxonomies (Categories, Brands, Units, Warranties)
    $taxo = new TaxonomyController();
    $r->get('/categories', [$taxo, 'listCategories'], 'List product categories', ['categories:read']);
    $r->get('/categories/{id}', [$taxo, 'getCategory'], 'Get category details', ['categories:read']);
    $r->post('/categories', [$taxo, 'createCategory'], 'Create category', ['categories:write'], ['name']);
    $r->put('/categories/{id}', [$taxo, 'updateCategory'], 'Update category', ['categories:write']);
    $r->delete('/categories/{id}', [$taxo, 'deleteCategory'], 'Delete category', ['categories:write']);

    $r->get('/brands', [$taxo, 'listBrands'], 'List brands', ['brands:read']);
    $r->get('/brands/{id}', [$taxo, 'getBrand'], 'Get brand details', ['brands:read']);
    $r->post('/brands', [$taxo, 'createBrand'], 'Create brand', ['brands:write'], ['name']);
    $r->put('/brands/{id}', [$taxo, 'updateBrand'], 'Update brand', ['brands:write']);
    $r->delete('/brands/{id}', [$taxo, 'deleteBrand'], 'Delete brand', ['brands:write']);

    $r->get('/units', [$taxo, 'listUnits'], 'List units of measure', ['units:read']);
    $r->get('/units/{id}', [$taxo, 'getUnit'], 'Get unit details', ['units:read']);
    $r->post('/units', [$taxo, 'createUnit'], 'Create unit of measure', ['units:write'], ['actual_name', 'short_name']);
    $r->put('/units/{id}', [$taxo, 'updateUnit'], 'Update unit', ['units:write']);
    $r->delete('/units/{id}', [$taxo, 'deleteUnit'], 'Delete unit', ['units:write']);

    $r->get('/warranties', [$taxo, 'listWarranties'], 'List warranties', ['warranties:read']);
    $r->post('/warranties', [$taxo, 'createWarranty'], 'Create warranty', ['warranties:write'], ['name', 'duration', 'duration_type']);

    // Inventory & Stock
    $inv = new InventoryController();
    $r->get('/inventory/stock', [$inv, 'getStock'], 'Get real-time stock levels across locations', ['inventory:read']);
    $r->post('/inventory/adjust', [$inv, 'adjustStock'], 'Record stock adjustment (normal or abnormal)', ['inventory:write'], ['location_id', 'adjustment_type', 'products']);
    $r->get('/inventory/low-stock', [$inv, 'getLowStock'], 'List products below alert quantity threshold', ['inventory:read']);
    $r->get('/inventory/history', [$inv, 'getHistory'], 'Get stock movement ledger / audit history', ['inventory:read']);

    // Contacts (Customers & Suppliers)
    $cont = new ContactController();
    $r->get('/contacts', [$cont, 'index'], 'List customers and suppliers with balance summaries', ['contacts:read']);
    $r->get('/contacts/search', [$cont, 'search'], 'Quick contact search by name, phone, or ID', ['contacts:read'], ['term']);
    $r->get('/contacts/{id}', [$cont, 'show'], 'Get single contact details and balance', ['contacts:read']);
    $r->post('/contacts', [$cont, 'store'], 'Create customer or supplier', ['contacts:write'], ['name', 'type']);
    $r->put('/contacts/{id}', [$cont, 'update'], 'Update contact details', ['contacts:write']);
    $r->delete('/contacts/{id}', [$cont, 'destroy'], 'Delete contact if not linked to transactions', ['contacts:write']);
    $r->get('/contacts/{id}/ledger', [$cont, 'ledger'], 'Get financial ledger with debits, credits, and balance due', ['contacts:read']);

    // Sales & POS
    $sale = new SaleController();
    $r->get('/sales', [$sale, 'index'], 'List sales with date, customer, and status filters', ['sales:read']);
    $r->get('/sales/{id}', [$sale, 'show'], 'Get sale by ID with sell lines, taxes, and payments', ['sales:read']);
    $r->post('/sales', [$sale, 'store'], 'Create sale with line items, tax, discount, payments, and auto-stock deduction', ['sales:write'], ['location_id', 'contact_id', 'status', 'products']);
    $r->post('/sales/{id}/void', [$sale, 'void'], 'Void/Cancel sale and restore inventory back to stock', ['sales:write']);
    $r->post('/sales/{id}/hold', [$sale, 'hold'], 'Hold/suspend sale into draft status', ['sales:write']);
    $r->post('/sales/{id}/resume', [$sale, 'resume'], 'Resume held sale and deduct inventory', ['sales:write']);
    $r->get('/sales/{id}/invoice', [$sale, 'invoice'], 'Get print-ready formatted invoice data with totals and line items', ['sales:read']);

    // Returns
    $ret = new ReturnController();
    $r->get('/returns/sales', [$ret, 'listSaleReturns'], 'List sale returns', ['returns:read']);
    $r->post('/returns/sales', [$ret, 'createSaleReturn'], 'Process sale return and return items back to stock', ['returns:write'], ['sale_id', 'returns']);
    $r->get('/returns/purchases', [$ret, 'listPurchaseReturns'], 'List purchase returns', ['returns:read']);
    $r->post('/returns/purchases', [$ret, 'createPurchaseReturn'], 'Process purchase return and deduct items from stock', ['returns:write'], ['purchase_id', 'returns']);

    // Purchases
    $pur = new PurchaseController();
    $r->get('/purchases', [$pur, 'index'], 'List purchase orders and receipts', ['purchases:read']);
    $r->get('/purchases/{id}', [$pur, 'show'], 'Get purchase details with lines and payments', ['purchases:read']);
    $r->post('/purchases', [$pur, 'store'], 'Create purchase order or purchase receipt', ['purchases:write'], ['contact_id', 'location_id', 'status', 'items']);
    $r->post('/purchases/{id}/receive', [$pur, 'receive'], 'Receive pending/ordered purchase and increment stock', ['purchases:write']);

    // Payments
    $pay = new PaymentController();
    $r->get('/payments', [$pay, 'index'], 'List payments with date and method filters', ['payments:read']);
    $r->post('/payments', [$pay, 'store'], 'Add payment against a sale, purchase, or expense', ['payments:write'], ['transaction_id', 'amount', 'method']);
    $r->get('/payments/due', [$pay, 'due'], 'List outstanding due amounts on sales or purchases', ['payments:read']);

    // Expenses
    $exp = new ExpenseController();
    $r->get('/expenses/categories', [$exp, 'listCategories'], 'List expense categories', ['expenses:read']);
    $r->post('/expenses/categories', [$exp, 'createCategory'], 'Create expense category', ['expenses:write'], ['name']);
    $r->put('/expenses/categories/{id}', [$exp, 'updateCategory'], 'Update expense category', ['expenses:write']);
    $r->delete('/expenses/categories/{id}', [$exp, 'deleteCategory'], 'Delete expense category', ['expenses:write']);
    $r->get('/expenses', [$exp, 'index'], 'List expenses with category, location, and date filters', ['expenses:read']);
    $r->get('/expenses/{id}', [$exp, 'show'], 'Get expense details', ['expenses:read']);
    $r->post('/expenses', [$exp, 'store'], 'Record new business expense', ['expenses:write'], ['location_id', 'final_total']);
    $r->put('/expenses/{id}', [$exp, 'update'], 'Update expense', ['expenses:write']);
    $r->delete('/expenses/{id}', [$exp, 'destroy'], 'Delete expense', ['expenses:write']);

    // Reports
    $rep = new ReportController();
    $r->get('/reports/sales', [$rep, 'sales'], 'Daily/monthly sales summary and breakdown with date filters', ['reports:read']);
    $r->get('/reports/profit-loss', [$rep, 'profitLoss'], 'Gross and net profit/loss summary', ['reports:read']);
    $r->get('/reports/top-products', [$rep, 'topProducts'], 'Top selling products ranked by volume and revenue', ['reports:read']);
    $r->get('/reports/stock-valuation', [$rep, 'stockValuation'], 'Current stock valuation by purchase and selling price', ['reports:read']);
    $r->get('/reports/tax-summary', [$rep, 'taxSummary'], 'Tax summary (input tax vs output tax)', ['reports:read']);

    // Users & Roles
    $usr = new UserController();
    $r->get('/users', [$usr, 'index'], 'List users', ['users:read']);
    $r->get('/users/{id}', [$usr, 'show'], 'Get user details', ['users:read']);
    $r->post('/users', [$usr, 'store'], 'Create new user', ['users:write'], ['first_name', 'email', 'username', 'password']);
    $r->put('/users/{id}', [$usr, 'update'], 'Update user profile or role', ['users:write']);
    $r->get('/roles', [$usr, 'roles'], 'List roles and permissions', ['users:read']);

    // Settings & Locations
    $set = new SettingController();
    $r->get('/settings/business', [$set, 'getBusiness'], 'Get business details and configuration', ['settings:read']);
    $r->put('/settings/business', [$set, 'updateBusiness'], 'Update business settings', ['settings:write']);
    $r->get('/settings/locations', [$set, 'listLocations'], 'List business locations/branches', ['settings:read']);
    $r->post('/settings/locations', [$set, 'createLocation'], 'Create business location/branch', ['settings:write'], ['name']);
    $r->get('/settings/tax-rates', [$set, 'listTaxRates'], 'List configured tax rates', ['settings:read']);
    $r->post('/settings/tax-rates', [$set, 'createTaxRate'], 'Create tax rate', ['settings:write'], ['name', 'amount']);
});

// 3. Match Incoming Route
$matched = $router->match($request->getMethod(), $request->getUri());

if (!$matched) {
    $notFoundResponse = Response::notFound("Endpoint {$request->getMethod()} {$request->getUri()} does not exist. Refer to GET /api/v1/meta/endpoints for valid routes.");
    $loggingMiddleware->log($request, $notFoundResponse);
    $notFoundResponse->send();
}

$route = $matched['route'];
$request->setRouteParams($matched['params']);

// 4. Rate Limit Middleware
$rateLimit = new RateLimitMiddleware($config);
$rateLimitResponse = $rateLimit->handle($request);
if ($rateLimitResponse) {
    $loggingMiddleware->log($request, $rateLimitResponse);
    $rateLimitResponse->send();
}

// 5. Auth Middleware
$auth = new AuthMiddleware($config);
$authResponse = $auth->handle($request, $route['scopes']);
if ($authResponse) {
    $loggingMiddleware->log($request, $authResponse);
    $authResponse->send();
}

// 6. Execute Controller Action
$handler = $route['handler'];
$response = call_user_func($handler, $request);

if (!($response instanceof Response)) {
    $response = Response::success($response);
}

// 7. Log & Send Response
$loggingMiddleware->log($request, $response);
$response->send();


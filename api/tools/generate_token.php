<?php
/**
 * UltimatePOS REST API - Token Generator CLI Script
 *
 * Generates a secure random 64-hex-character Bearer token using random_bytes(32).
 * Stores ONLY the SHA-256 hash in the `api_tokens` database table.
 * Shows the plain token ONCE to the administrator/agent.
 *
 * Usage:
 *   php api/tools/generate_token.php --name="AI Agent" [--business-id=1] [--user-id=1] [--scopes="*"] [--days=365]
 */

declare(strict_types=1);

// Prevent direct execution via web browser
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "This tool can only be run via the CLI.\n";
    exit(1);
}

// Parse command line arguments
$options = getopt('', [
    'name::',
    'business-id::',
    'user-id::',
    'scopes::',
    'days::',
    'help',
]);

if (isset($options['help'])) {
    echo <<<HELP
UltimatePOS REST API Token Generator

Usage:
  php api/tools/generate_token.php [options]

Options:
  --name=<string>          Token / Agent name (default: "AI Agent")
  --business-id=<int>      Associated business ID (default: 1)
  --user-id=<int>          Associated user ID (default: 1)
  --scopes=<comma-list>    Permissions scopes (default: "*")
  --days=<int>             Token expiration in days (optional, default: never expires)
  --help                   Display this help message

HELP;
    exit(0);
}

$name = (string)($options['name'] ?? 'AI Agent');
$businessId = isset($options['business-id']) ? (int)$options['business-id'] : 1;
$userId = isset($options['user-id']) ? (int)$options['user-id'] : 1;
$scopesInput = (string)($options['scopes'] ?? '*');
$scopes = array_values(array_filter(array_map('trim', explode(',', $scopesInput))));
if (empty($scopes)) {
    $scopes = ['*'];
}

$days = isset($options['days']) ? (int)$options['days'] : null;
$expiresAt = $days ? date('Y-m-d H:i:s', strtotime("+{$days} days")) : null;

// Generate 32 bytes (64 hex characters)
$plainToken = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $plainToken);

// Attempt database connection through Laravel
$baseDir = dirname(__DIR__, 2);
$autoloadPath = $baseDir . '/vendor/autoload.php';
$bootstrapPath = $baseDir . '/bootstrap/app.php';

$inserted = false;

if (file_exists($autoloadPath) && file_exists($bootstrapPath)) {
    try {
        require_once $autoloadPath;
        $app = require_once $bootstrapPath;
        $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
        $kernel->bootstrap();

        \Illuminate\Support\Facades\DB::table('api_tokens')->insert([
            'business_id' => $businessId,
            'user_id' => $userId,
            'name' => $name,
            'token_hash' => $tokenHash,
            'permissions' => json_encode($scopes),
            'is_active' => 1,
            'expires_at' => $expiresAt,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $inserted = true;
    } catch (\Throwable $e) {
        $errorMessage = $e->getMessage();
    }
}

echo "====================================================================\n";
echo "              UltimatePOS REST API Token Generator                  \n";
echo "====================================================================\n";
if ($inserted) {
    echo "✓ Token successfully registered in `api_tokens` table!\n";
} else {
    echo "! Note: Could not auto-insert into database (" . ($errorMessage ?? 'Framework not booted') . ").\n";
    echo "  You can manually execute this SQL query:\n\n";
    $escapedName = addslashes($name);
    $escapedScopes = addslashes(json_encode($scopes));
    $expSql = $expiresAt ? "'{$expiresAt}'" : "NULL";
    echo "  INSERT INTO `api_tokens` (`business_id`, `user_id`, `name`, `token_hash`, `permissions`, `is_active`, `expires_at`, `created_at`, `updated_at`)\n";
    echo "  VALUES ({$businessId}, {$userId}, '{$escapedName}', '{$tokenHash}', '{$escapedScopes}', 1, {$expSql}, NOW(), NOW());\n\n";
}

echo "Token Details:\n";
echo "--------------------------------------------------------------------\n";
echo "Name:         " . $name . "\n";
echo "Business ID:  " . $businessId . "\n";
echo "User ID:      " . $userId . "\n";
echo "Scopes:       " . implode(', ', $scopes) . "\n";
echo "Expires:      " . ($expiresAt ?? 'Never') . "\n";
echo "SHA-256 Hash: " . $tokenHash . "\n";
echo "--------------------------------------------------------------------\n";
echo ">>> PLAIN TOKEN (SAVE THIS NOW! IT WILL NEVER BE SHOWN AGAIN) <<<\n\n";
echo "  " . $plainToken . "\n\n";
echo "Use in HTTP requests:\n";
echo "  Authorization: Bearer " . $plainToken . "\n";
echo "====================================================================\n";


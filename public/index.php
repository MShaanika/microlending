<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/bootstrap/app.php';

use App\Core\Router;

// Public API routes (/api/*) are called cross-origin by external client
// websites, so a CORS preflight (OPTIONS) must succeed before the browser
// sends the real POST. The actual Access-Control-Allow-Origin value is set
// per-source by ApplicationIntakeController; this just clears the preflight.
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS' && str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 600');
    http_response_code(204);
    exit;
}

$router = new Router();
require ROOT_PATH . '/routes/web.php';
$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);

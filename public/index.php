<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/bootstrap/app.php';

use App\Core\Router;

$router = new Router();
require ROOT_PATH . '/routes/web.php';
$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);

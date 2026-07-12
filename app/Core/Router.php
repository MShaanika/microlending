<?php
namespace App\Core;

class Router
{
    private array $routes = ['GET'=>[], 'POST'=>[]];

    public function get(string $path, array $handler): void { $this->routes['GET'][$path] = $handler; }
    public function post(string $path, array $handler): void { $this->routes['POST'][$path] = $handler; }

	public function dispatch(string $method, string $uri): void
	{
		$path = parse_url($uri, PHP_URL_PATH) ?: '/';

		$scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
		$scriptDir  = rtrim(dirname($scriptName), '/');

		$projectBase = str_replace('/public', '', $scriptDir);

		if ($projectBase && $projectBase !== '/' && str_starts_with($path, $projectBase)) {
			$path = substr($path, strlen($projectBase));
		}

		$path = '/' . trim($path, '/');

		if ($path === '//') {
			$path = '/';
		}

		if ($path === '/index.php') {
			$path = '/';
		}

		if (str_starts_with($path, '/index.php/')) {
			$path = substr($path, strlen('/index.php'));
			$path = '/' . trim($path, '/');
		}

		// Allow plain HTML forms to express PUT/DELETE/PATCH via a hidden _method field.
		if ($method === 'POST' && isset($_POST['_method'])) {
			$override = strtoupper((string) $_POST['_method']);
			if (in_array($override, ['PUT', 'DELETE', 'PATCH'], true)) {
				$method = $override;
			}
		}

		$params = [];
		$handler = $this->routes[$method][$path] ?? null;

		// Fall back to dynamic {param} route matching.
		if (!$handler) {
			foreach ($this->routes[$method] ?? [] as $route => $candidate) {
				if (!str_contains($route, '{')) {
					continue;
				}
				$pattern = preg_replace('#\{[a-zA-Z_][a-zA-Z0-9_]*\}#', '([^/]+)', $route);
				if (preg_match('#^' . $pattern . '$#', $path, $matches)) {
					$handler = $candidate;
					array_shift($matches);
					$params = $matches;
					break;
				}
			}
		}

		if (!$handler) {
			if ($method === 'GET' && $this->serveStaticFile($path)) {
				return;
			}
			http_response_code(404);
			View::render('errors/404', [
				'title' => 'Page Not Found'
			]);
			return;
		}

		[$class, $action] = $handler;
		(new $class())->$action(...$params);
	}

	/**
	 * Fallback for dev servers started with a router script (e.g.
	 * `php -S -t public public/index.php`), which -- unlike Apache/XAMPP --
	 * run this router for every request instead of serving real files
	 * directly. asset()/url() always build paths like "/public/dist/..."
	 * relative to the project root, so that's exactly where to look
	 * regardless of which directory the dev server's docroot points at.
	 */
	private function serveStaticFile(string $path): bool
	{
		if (!str_starts_with($path, '/public/') || str_contains($path, '..')) {
			return false;
		}

		$filePath = ROOT_PATH . $path;
		if (!is_file($filePath)) {
			return false;
		}

		static $mimeTypes = [
			'css' => 'text/css', 'js' => 'application/javascript', 'json' => 'application/json',
			'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
			'svg' => 'image/svg+xml', 'ico' => 'image/x-icon', 'woff' => 'font/woff', 'woff2' => 'font/woff2',
			'ttf' => 'font/ttf', 'eot' => 'application/vnd.ms-fontobject', 'map' => 'application/json',
		];
		$ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

		header('Content-Type: ' . ($mimeTypes[$ext] ?? 'application/octet-stream'));
		header('Content-Length: ' . filesize($filePath));
		readfile($filePath);
		return true;
	}
}

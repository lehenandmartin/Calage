<?php
declare(strict_types=1);

namespace Calage;

/**
 * Minimal router. Patterns like '/newsletters/{id}/draft':
 *   {id}, {…_id} or {n} → digits, any other {name} → [A-Za-z0-9_-]+.
 * By default a route requires being signed in; ['public' => true] opens it to everyone.
 * Every POST is checked for CSRF before reaching the controller.
 */
final class Router
{
    /** @var list<array{method: string, regex: string, handler: array, public: bool, setup: bool}> */
    private array $routes = [];

    public function get(string $pattern, array $handler, array $options = []): void
    {
        $this->add('GET', $pattern, $handler, $options);
    }

    public function post(string $pattern, array $handler, array $options = []): void
    {
        $this->add('POST', $pattern, $handler, $options);
    }

    private function add(string $method, string $pattern, array $handler, array $options): void
    {
        $regex = preg_replace_callback(
            '#\\\\\{(\w+)\\\\\}#',
            function (array $m): string {
                $name = $m[1];
                $class = ($name === 'id' || $name === 'n' || str_ends_with($name, '_id')) ? '\d+' : '[A-Za-z0-9_-]+';
                return "(?P<$name>$class)";
            },
            preg_quote($pattern, '#')
        );
        $this->routes[] = [
            'method' => $method,
            'regex' => '#^' . $regex . '$#',
            'handler' => $handler,
            'public' => (bool) ($options['public'] ?? false),
            'setup' => (bool) ($options['setup'] ?? false),
        ];
    }

    public function dispatch(string $method, string $path): void
    {
        $method = strtoupper($method) === 'HEAD' ? 'GET' : strtoupper($method);

        // Until config.php is filled in, only the setup wizard answers.
        $configured = App::isConfigured();

        $allowed = [];
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            if ($route['method'] !== $method) {
                $allowed[] = $route['method'];
                continue;
            }

            if (!$configured && !$route['setup']) {
                redirect('/setup');
            }
            if ($configured && $route['setup']) {
                View::error(404);
                return;
            }

            $this->sendDefaultHeaders($route['public']);

            if (!$route['public'] && !Auth::check()) {
                if ($method === 'GET') {
                    $_SESSION['intended'] = $path;
                    redirect('/login');
                }
                View::error(403);
                return;
            }
            // Form larger than post_max_size: PHP empties $_POST and $_FILES without any other signal.
            // (A JSON request always has an empty $_POST: it is not concerned.)
            $contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
            $isForm = str_starts_with($contentType, 'multipart/form-data') || str_starts_with($contentType, 'application/x-www-form-urlencoded');
            if ($method === 'POST' && $isForm && $_POST === [] && $_FILES === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
                View::error(413, __('The upload exceeds the server limit (post_max_size = {size}).', ['size' => ini_get('post_max_size')]));
                return;
            }
            if ($method === 'POST' && !Csrf::check()) {
                View::error(400, __('The session has expired or the form is too old. Reload the page and try again.'));
                return;
            }

            [$class, $action] = $route['handler'];
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            (new $class())->$action($params);
            return;
        }

        if (!$configured) {
            redirect('/setup');
        }
        if ($allowed !== []) {
            header('Allow: ' . implode(', ', array_unique($allowed)));
            View::error(405);
            return;
        }
        View::error(404);
    }

    private function sendDefaultHeaders(bool $public): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: same-origin');
        if (!$public) {
            header('Cache-Control: no-store');
        }
    }
}

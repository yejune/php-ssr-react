<?php

declare(strict_types=1);

namespace PhpSsrReact;

/**
 * Convention-based API Router
 *
 * Method naming convention:
 *   SettingsGet()    → GET  /api/settings
 *   SettingsSave()   → POST /api/settings/save
 *   BoardList()      → GET  /api/board/list
 *   BoardGet($id)    → GET  /api/board?id=xxx
 *   BoardCreate($r)  → POST /api/board/create
 *   BoardUpdate($r)  → POST /api/board/update
 *   BoardDelete($r)  → POST /api/board/delete
 */
class Router
{
    private ?object $app = null;
    private array $routes = [];
    private array $controllers = [];

    public function __construct(?object $app = null)
    {
        $this->app = $app;
        if ($app) {
            $this->buildRoutes();
        }
    }

    /**
     * Register a controller for a module
     */
    public function registerController(string $prefix, object $controller): void
    {
        $this->controllers[$prefix] = $controller;
        $this->buildControllerRoutes($prefix, $controller);
    }

    /**
     * Build routes from a controller
     */
    private function buildControllerRoutes(string $prefix, object $controller): void
    {
        $reflection = new \ReflectionClass($controller);
        $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            $name = $method->getName();
            if (str_starts_with($name, '__')) {
                continue;
            }

            $httpMethod = $this->determineHttpMethod($name);
            $path = "/api/$prefix/$name";

            $this->routes[$path] = [
                'method' => $httpMethod,
                'controller' => $prefix,
                'handler' => $name,
                'params' => $this->getMethodParams($method),
            ];
        }
    }

    /**
     * Determine HTTP method from action name
     */
    private function determineHttpMethod(string $action): string
    {
        return match ($action) {
            'get', 'list', 'index', 'check', 'search', 'find', 'stats' => 'GET',
            'save', 'create', 'update', 'delete', 'cancel', 'store', 'destroy' => 'POST',
            default => 'GET',
        };
    }

    /**
     * Build routes from App methods using reflection
     */
    private function buildRoutes(): void
    {
        $reflection = new \ReflectionClass($this->app);
        $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            $name = $method->getName();
            if (str_starts_with($name, '__')) {
                continue; // Skip magic methods
            }

            // Parse method name: BoardGet → board, get
            $route = $this->parseMethodName($name);
            if ($route) {
                $this->routes[$route['path']] = [
                    'method' => $route['httpMethod'],
                    'handler' => $name,
                    'params' => $this->getMethodParams($method),
                ];
            }
        }
    }

    /**
     * Parse method name to route info
     */
    private function parseMethodName(string $methodName): ?array
    {
        // Split by capital letters: SettingsGet → [Settings, Get]
        $parts = preg_split('/(?=[A-Z])/', $methodName, -1, PREG_SPLIT_NO_EMPTY);
        if (count($parts) < 2) {
            return null;
        }

        $action = strtolower(array_pop($parts));
        $resource = strtolower(implode('/', $parts));

        // Determine HTTP method and path
        $httpMethod = 'GET';
        $path = "/api/$resource";

        switch ($action) {
            case 'get':
            case 'list':
            case 'check':
                $httpMethod = 'GET';
                if ($action !== 'get') {
                    $path .= "/$action";
                }
                break;

            case 'save':
            case 'create':
            case 'update':
            case 'delete':
            case 'cancel':
                $httpMethod = 'POST';
                $path .= "/$action";
                break;

            default:
                $httpMethod = 'GET';
                $path .= "/$action";
        }

        return [
            'httpMethod' => $httpMethod,
            'path' => $path,
        ];
    }

    /**
     * Get method parameter info
     */
    private function getMethodParams(\ReflectionMethod $method): array
    {
        $params = [];
        foreach ($method->getParameters() as $param) {
            $type = $param->getType();
            $params[] = [
                'name' => $param->getName(),
                'type' => $type ? $type->getName() : 'mixed',
                'optional' => $param->isOptional(),
                'default' => $param->isOptional() ? $param->getDefaultValue() : null,
            ];
        }
        return $params;
    }

    /**
     * Handle HTTP request
     */
    public function handle(): void
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        // CORS headers
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');

        if ($method === 'OPTIONS') {
            http_response_code(204);
            return;
        }

        header('Content-Type: application/json; charset=utf-8');

        // Find matching route
        $route = $this->findRoute($path, $method);
        if (!$route) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => 'Route not found']]);
            return;
        }

        try {
            $result = $this->executeHandler($route);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => ['code' => 'SERVER_ERROR', 'message' => $e->getMessage()]
            ]);
        }
    }

    /**
     * Find matching route
     */
    private function findRoute(string $path, string $method): ?array
    {
        if (isset($this->routes[$path]) && $this->routes[$path]['method'] === $method) {
            return $this->routes[$path];
        }
        return null;
    }

    /**
     * Execute route handler
     */
    private function executeHandler(array $route): mixed
    {
        $handler = $route['handler'];
        $params = $route['params'];
        $args = [];

        // Get the target object (controller or app)
        $target = isset($route['controller'])
            ? $this->controllers[$route['controller']]
            : $this->app;

        if (empty($params)) {
            return $target->$handler();
        }

        // Get input data
        $input = [];
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $rawInput = file_get_contents('php://input');
            $input = json_decode($rawInput, true) ?? [];
        } else {
            $input = $_GET;
        }

        // Build arguments
        foreach ($params as $param) {
            $name = $param['name'];
            $type = $param['type'];

            if (isset($input[$name])) {
                $args[] = $this->castValue($input[$name], $type);
            } elseif (class_exists($type) || $type === 'array') {
                // If param is a class/array type, pass the whole input
                $args[] = $type === 'array' ? $input : $this->createObject($type, $input);
            } elseif ($param['optional']) {
                $args[] = $param['default'];
            } else {
                throw new \InvalidArgumentException("Missing required parameter: $name");
            }
        }

        return $target->$handler(...$args);
    }

    /**
     * Cast value to type
     */
    private function castValue(mixed $value, string $type): mixed
    {
        return match ($type) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'string' => (string) $value,
            'array' => (array) $value,
            default => $value,
        };
    }

    /**
     * Create object from array data
     */
    private function createObject(string $class, array $data): object
    {
        if (!class_exists($class)) {
            return (object) $data;
        }

        $obj = new $class();
        foreach ($data as $key => $value) {
            if (property_exists($obj, $key)) {
                $obj->$key = $value;
            }
        }
        return $obj;
    }

    /**
     * Get all registered routes (for debugging)
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }
}

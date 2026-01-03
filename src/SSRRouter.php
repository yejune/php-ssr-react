<?php

declare(strict_types=1);

namespace PhpSsrReact;

use ReflectionMethod;

/**
 * SSR Router with automatic route detection from Props classes
 *
 * Scans modules/{name}/types/Props.php for route definitions
 * and handles SSR rendering with data loading
 */
class SSRRouter
{
    private object $app;
    private Engine $engine;
    private Generator $generator;
    private array $routes = [];

    public function __construct(object $app, Engine $engine, string $modulesDir)
    {
        $this->app = $app;
        $this->engine = $engine;
        $this->generator = new Generator($modulesDir, '');
        $this->scanModules($modulesDir);
    }

    /**
     * Scan modules for Props classes and build routes
     */
    private function scanModules(string $modulesDir): void
    {
        $pattern = rtrim($modulesDir, '/') . '/*/types/Props.php';
        $files = glob($pattern);

        foreach ($files as $file) {
            $this->parsePropsFile($file);
        }
    }

    /**
     * Parse a Props.php file
     */
    private function parsePropsFile(string $filePath): void
    {
        // Extract module name
        preg_match('/modules\/([^\/]+)\/types\/Props\.php$/', $filePath, $matches);
        $moduleName = $matches[1] ?? '';

        require_once $filePath;

        $declaredClasses = get_declared_classes();
        foreach ($declaredClasses as $className) {
            if (!$this->isPropsClass($className, $moduleName)) {
                continue;
            }

            $this->parsePropsClass($className, $moduleName);
        }
    }

    /**
     * Check if class is a Props class for the module
     */
    private function isPropsClass(string $className, string $moduleName): bool
    {
        $expectedNamespace = 'Modules\\' . ucfirst($moduleName) . '\\Types\\';
        return str_starts_with($className, $expectedNamespace) && str_ends_with($className, 'Props');
    }

    /**
     * Parse Props class attributes
     */
    private function parsePropsClass(string $className, string $moduleName): void
    {
        $reflection = new \ReflectionClass($className);

        $routeAttrs = $reflection->getAttributes(\PhpSsrReact\Attributes\Route::class);
        $pageAttr = $reflection->getAttributes(\PhpSsrReact\Attributes\Page::class)[0] ?? null;
        $titleAttr = $reflection->getAttributes(\PhpSsrReact\Attributes\Title::class)[0] ?? null;

        $page = $pageAttr ? $pageAttr->newInstance()->component : null;
        $title = $titleAttr ? $titleAttr->newInstance()->title : '';

        $loaders = $this->parseLoaders($reflection);
        $params = $this->parseParams($reflection);

        foreach ($routeAttrs as $routeAttr) {
            $route = $routeAttr->newInstance();
            $this->routes[] = [
                'method' => $route->method,
                'path' => $route->path,
                'page' => $page,
                'title' => $title,
                'module' => $moduleName,
                'loaders' => $loaders,
                'params' => $params,
            ];
        }
    }

    /**
     * Parse Loader attributes
     */
    private function parseLoaders(\ReflectionClass $reflection): array
    {
        $loaders = [];
        foreach ($reflection->getProperties() as $property) {
            $loaderAttr = $property->getAttributes(\PhpSsrReact\Attributes\Loader::class)[0] ?? null;
            if ($loaderAttr) {
                $loader = $loaderAttr->newInstance();
                $loaders[$property->getName()] = [
                    'method' => $loader->method,
                    'args' => $loader->args,
                    'optional' => $loader->optional,
                ];
            }
        }
        return $loaders;
    }

    /**
     * Parse Param attributes
     */
    private function parseParams(\ReflectionClass $reflection): array
    {
        $params = [];
        foreach ($reflection->getProperties() as $property) {
            $paramAttr = $property->getAttributes(\PhpSsrReact\Attributes\Param::class)[0] ?? null;
            if ($paramAttr) {
                $param = $paramAttr->newInstance();
                $params[$property->getName()] = $param->name;
            }
        }
        return $params;
    }

    /**
     * Handle HTTP request
     */
    public function handle(): ?string
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        $route = $this->matchRoute($path, $method);
        if (!$route) {
            return null;
        }

        return $this->renderRoute($route, $path);
    }

    /**
     * Match request path to route
     */
    private function matchRoute(string $path, string $method): ?array
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            $pattern = $this->routeToPattern($route['path']);
            if (preg_match($pattern, $path, $matches)) {
                $route['matchedParams'] = $this->extractParams($route['path'], $matches);
                return $route;
            }
        }
        return null;
    }

    /**
     * Convert route path to regex pattern
     */
    private function routeToPattern(string $path): string
    {
        // Convert :param to named capture group
        $pattern = preg_replace('/:([a-zA-Z]+)/', '(?P<$1>[^/]+)', $path);
        return '#^' . $pattern . '$#';
    }

    /**
     * Extract matched parameters
     */
    private function extractParams(string $routePath, array $matches): array
    {
        $params = [];
        preg_match_all('/:([a-zA-Z]+)/', $routePath, $paramNames);

        foreach ($paramNames[1] as $name) {
            if (isset($matches[$name])) {
                // Try to convert to int if numeric
                $value = $matches[$name];
                $params[$name] = is_numeric($value) ? (int) $value : $value;
            }
        }
        return $params;
    }

    /**
     * Render route with SSR
     */
    private function renderRoute(array $route, string $requestPath): string
    {
        $props = [
            'currentPath' => $requestPath,
        ];

        // Add URL parameters to props
        foreach ($route['params'] as $propName => $paramName) {
            $jsonPropName = lcfirst($propName);
            if (isset($route['matchedParams'][$paramName])) {
                $props[$jsonPropName] = $route['matchedParams'][$paramName];
            }
        }

        // Execute loaders
        foreach ($route['loaders'] as $propName => $loader) {
            $result = $this->executeLoader($loader, $route['matchedParams']);
            if ($result !== null || !$loader['optional']) {
                $jsonPropName = lcfirst($propName);
                $props[$jsonPropName] = $result;
            }
        }

        // Render with SSR (path-based routing)
        return $this->engine->render($requestPath, $props, [
            'title' => $route['title'],
        ]);
    }

    /**
     * Execute a loader method on the app
     */
    private function executeLoader(array $loader, array $matchedParams): mixed
    {
        $methodName = $loader['method'];

        if (!method_exists($this->app, $methodName)) {
            return null;
        }

        // Build arguments
        $args = [];
        $reflection = new ReflectionMethod($this->app, $methodName);
        $methodParams = $reflection->getParameters();

        foreach ($loader['args'] as $index => $argName) {
            if (isset($matchedParams[$argName])) {
                $value = $matchedParams[$argName];

                // Type cast if method has type hint
                if (isset($methodParams[$index])) {
                    $type = $methodParams[$index]->getType();
                    if ($type instanceof \ReflectionNamedType) {
                        $value = $this->castValue($value, $type->getName());
                    }
                }

                $args[] = $value;
            }
        }

        // Call method
        $result = $this->app->$methodName(...$args);

        // Extract data from Response wrapper if present
        if (is_array($result) && isset($result['success']) && isset($result['data'])) {
            return $result['success'] ? $result['data'] : null;
        }

        return $result;
    }

    /**
     * Cast value to type
     */
    private function castValue(mixed $value, string $type): mixed
    {
        return match ($type) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => (bool) $value,
            'string' => (string) $value,
            default => $value,
        };
    }

    /**
     * Get all registered routes
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }
}

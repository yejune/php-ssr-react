<?php

declare(strict_types=1);

namespace PhpSsrReact;

use PhpSsrReact\Attributes\Route;
use PhpSsrReact\Attributes\Page;
use PhpSsrReact\Attributes\Title;
use PhpSsrReact\Attributes\Loader;
use PhpSsrReact\Attributes\Param;
use ReflectionClass;
use ReflectionProperty;
use ReflectionNamedType;
use ReflectionUnionType;

/**
 * TypeScript Generator
 *
 * Scans Props classes and generates:
 * 1. TypeScript interfaces from PHP classes
 * 2. Route definitions for React Router
 */
class Generator
{
    private string $modulesDir;
    private string $outputDir;
    private array $routes = [];
    private array $types = [];
    private array $processedTypes = [];

    public function __construct(string $modulesDir, string $outputDir)
    {
        $this->modulesDir = rtrim($modulesDir, '/');
        $this->outputDir = rtrim($outputDir, '/');
    }

    /**
     * Scan modules directory and generate TypeScript files
     */
    public function generate(): void
    {
        $this->scanModules();
        $this->generateTypesFile();
        $this->generateRoutesFile();
    }

    /**
     * Generate only if source files have changed
     *
     * @return bool True if regenerated, false if skipped
     */
    public function generateIfChanged(): bool
    {
        if (!$this->needsRegenerate()) {
            return false;
        }

        $this->generate();
        return true;
    }

    /**
     * Check if TypeScript files need to be regenerated
     */
    public function needsRegenerate(): bool
    {
        $typesFile = $this->outputDir . '/types.generated.ts';
        $routesFile = $this->outputDir . '/routes.generated.tsx';

        // Output files don't exist → need to generate
        if (!file_exists($typesFile) || !file_exists($routesFile)) {
            return true;
        }

        // Get the oldest output file time
        $outputTime = min(filemtime($typesFile), filemtime($routesFile));

        // Check all source files (Props.php and Model.php)
        $sourceFiles = array_merge(
            glob($this->modulesDir . '/*/types/Props.php') ?: [],
            glob($this->modulesDir . '/*/types/Model.php') ?: []
        );

        foreach ($sourceFiles as $file) {
            // Source file is newer than output → need to regenerate
            if (filemtime($file) > $outputTime) {
                return true;
            }
        }

        return false;
    }

    /**
     * Scan all Props classes in modules directory
     */
    private function scanModules(): void
    {
        // First, load all Model.php files to register entity classes
        $modelPattern = $this->modulesDir . '/*/types/Model.php';
        $modelFiles = glob($modelPattern);
        foreach ($modelFiles as $file) {
            require_once $file;
        }

        // Then, parse Props.php files
        $pattern = $this->modulesDir . '/*/types/Props.php';
        $files = glob($pattern);

        foreach ($files as $file) {
            $this->parsePropsFile($file);
        }
    }

    /**
     * Parse a Props.php file and extract route/type information
     */
    private function parsePropsFile(string $filePath): void
    {
        // Extract module name from path
        preg_match('/modules\/([^\/]+)\/types\/Props\.php$/', $filePath, $matches);
        $moduleName = $matches[1] ?? '';

        // Load the file to get class definitions
        require_once $filePath;

        // Find all classes in the file
        $declaredClasses = get_declared_classes();
        foreach ($declaredClasses as $className) {
            if (!$this->isPropsClass($className, $moduleName)) {
                continue;
            }

            $this->parsePropsClass($className, $moduleName);
        }
    }

    /**
     * Check if class belongs to the module's Props
     */
    private function isPropsClass(string $className, string $moduleName): bool
    {
        $expectedNamespace = 'Modules\\' . ucfirst($moduleName) . '\\Types\\';
        return str_starts_with($className, $expectedNamespace) && str_ends_with($className, 'Props');
    }

    /**
     * Parse a Props class and extract route/type information
     */
    private function parsePropsClass(string $className, string $moduleName): void
    {
        $reflection = new ReflectionClass($className);

        // Get class-level attributes
        $routeAttrs = $reflection->getAttributes(Route::class);
        $pageAttr = $reflection->getAttributes(Page::class)[0] ?? null;
        $titleAttr = $reflection->getAttributes(Title::class)[0] ?? null;

        $page = $pageAttr ? $pageAttr->newInstance()->component : null;
        $title = $titleAttr ? $titleAttr->newInstance()->title : '';

        // Parse routes
        foreach ($routeAttrs as $routeAttr) {
            $route = $routeAttr->newInstance();
            $this->routes[] = [
                'method' => $route->method,
                'path' => $route->path,
                'page' => $page,
                'title' => $title,
                'propsType' => $this->getShortClassName($className),
                'module' => $moduleName,
                'loaders' => $this->parseLoaders($reflection),
                'params' => $this->parseParams($reflection),
            ];
        }

        // Generate TypeScript interface for this Props class
        $this->generateTypeInterface($reflection, $moduleName);
    }

    /**
     * Parse Loader attributes from properties
     */
    private function parseLoaders(ReflectionClass $reflection): array
    {
        $loaders = [];
        foreach ($reflection->getProperties() as $property) {
            $loaderAttr = $property->getAttributes(Loader::class)[0] ?? null;
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
     * Parse Param attributes from properties
     */
    private function parseParams(ReflectionClass $reflection): array
    {
        $params = [];
        foreach ($reflection->getProperties() as $property) {
            $paramAttr = $property->getAttributes(Param::class)[0] ?? null;
            if ($paramAttr) {
                $param = $paramAttr->newInstance();
                $params[$property->getName()] = $param->name;
            }
        }
        return $params;
    }

    /**
     * Generate TypeScript interface from PHP class
     */
    private function generateTypeInterface(ReflectionClass $reflection, string $moduleName): void
    {
        $shortName = $this->getShortClassName($reflection->getName());

        if (isset($this->processedTypes[$shortName])) {
            return;
        }
        $this->processedTypes[$shortName] = true;

        $properties = [];
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $tsType = $this->phpTypeToTypeScript($property);
            $optional = $this->isOptionalProperty($property);
            $properties[] = [
                'name' => $this->toJsonPropertyName($property->getName()),
                'type' => $tsType,
                'optional' => $optional,
            ];

            // Check if we need to generate nested types
            $this->processNestedType($property);
        }

        $this->types[$shortName] = [
            'name' => $shortName,
            'module' => $moduleName,
            'properties' => $properties,
        ];
    }

    /**
     * Process nested types (arrays of objects, object properties)
     */
    private function processNestedType(ReflectionProperty $property): void
    {
        $type = $property->getType();
        if (!$type instanceof ReflectionNamedType) {
            return;
        }

        $typeName = $type->getName();

        // Skip built-in types
        if ($this->isBuiltInType($typeName)) {
            return;
        }

        // Check if it's a class we should process
        if (class_exists($typeName) && !isset($this->processedTypes[$this->getShortClassName($typeName)])) {
            $nestedReflection = new ReflectionClass($typeName);
            $moduleName = $this->extractModuleFromNamespace($typeName);
            $this->generateTypeInterface($nestedReflection, $moduleName);
        }
    }

    /**
     * Convert PHP type to TypeScript type
     */
    private function phpTypeToTypeScript(ReflectionProperty $property): string
    {
        $type = $property->getType();

        // Check for @var docblock annotation for array types
        $docComment = $property->getDocComment();
        if ($docComment && preg_match('/@var\s+([^\s\[\]]+)\[\]/', $docComment, $matches)) {
            $elementType = $matches[1];
            // Load the element type class if needed
            $this->loadAndProcessType($elementType, $property);
            return $this->getShortClassName($elementType) . '[]';
        }

        if (!$type) {
            return 'any';
        }

        if ($type instanceof ReflectionUnionType) {
            $types = array_map(fn($t) => $this->convertSingleType($t->getName()), $type->getTypes());
            $types = array_filter($types, fn($t) => $t !== 'null');
            return implode(' | ', $types);
        }

        if ($type instanceof ReflectionNamedType) {
            return $this->convertSingleType($type->getName());
        }

        return 'any';
    }

    /**
     * Load and process a type from docblock annotation
     */
    private function loadAndProcessType(string $typeName, ReflectionProperty $property): void
    {
        // Try to resolve the full class name
        $declaringClass = $property->getDeclaringClass();
        $namespace = $declaringClass->getNamespaceName();

        // Check if it's a short name in the same namespace
        $fullTypeName = $namespace . '\\' . $typeName;

        if (class_exists($fullTypeName)) {
            $typeName = $fullTypeName;
        } elseif (!class_exists($typeName)) {
            return;
        }

        $shortName = $this->getShortClassName($typeName);
        if (!isset($this->processedTypes[$shortName])) {
            $reflection = new ReflectionClass($typeName);
            $moduleName = $this->extractModuleFromNamespace($typeName);
            $this->generateTypeInterface($reflection, $moduleName);
        }
    }

    /**
     * Convert a single PHP type to TypeScript
     */
    private function convertSingleType(string $phpType): string
    {
        // Check for array type with docblock hint
        if ($phpType === 'array') {
            return 'any[]';
        }

        return match ($phpType) {
            'int', 'float' => 'number',
            'string' => 'string',
            'bool' => 'boolean',
            'null' => 'null',
            'mixed' => 'any',
            default => $this->isBuiltInType($phpType) ? 'any' : $this->getShortClassName($phpType),
        };
    }

    /**
     * Check if type is a PHP built-in type
     */
    private function isBuiltInType(string $type): bool
    {
        return in_array($type, ['int', 'float', 'string', 'bool', 'array', 'object', 'null', 'mixed', 'void', 'never']);
    }

    /**
     * Check if property is optional (nullable or has default)
     */
    private function isOptionalProperty(ReflectionProperty $property): bool
    {
        $type = $property->getType();
        if ($type && $type->allowsNull()) {
            return true;
        }
        return $property->hasDefaultValue();
    }

    /**
     * Convert PascalCase property name to camelCase for JSON
     */
    private function toJsonPropertyName(string $name): string
    {
        return lcfirst($name);
    }

    /**
     * Get short class name without namespace
     */
    private function getShortClassName(string $fullName): string
    {
        $parts = explode('\\', $fullName);
        return end($parts);
    }

    /**
     * Extract module name from namespace
     */
    private function extractModuleFromNamespace(string $namespace): string
    {
        if (preg_match('/Modules\\\\([^\\\\]+)/', $namespace, $matches)) {
            return strtolower($matches[1]);
        }
        return '';
    }

    /**
     * Generate types.generated.ts file
     */
    private function generateTypesFile(): void
    {
        $content = "// Auto-generated by PhpSsrReact\\Generator - DO NOT EDIT\n";
        $content .= "// Generated from modules/*/types/Props.php\n\n";

        foreach ($this->types as $type) {
            $content .= "export interface {$type['name']} {\n";
            foreach ($type['properties'] as $prop) {
                $optional = $prop['optional'] ? '?' : '';
                $content .= "  {$prop['name']}{$optional}: {$prop['type']};\n";
            }
            $content .= "}\n\n";
        }

        $this->writeFile($this->outputDir . '/types.generated.ts', $content);
    }

    /**
     * Generate routes.generated.tsx file
     */
    private function generateRoutesFile(): void
    {
        $content = "// Auto-generated by PhpSsrReact\\Generator - DO NOT EDIT\n";
        $content .= "// Generated from modules/*/types/Props.php\n\n";

        // Collect imports
        $imports = [];
        foreach ($this->routes as $route) {
            if (!$route['page']) {
                continue;
            }

            $componentName = $this->getComponentName($route['module'], $route['page']);
            $importPath = $this->getImportPath($route['page']);

            if (!isset($imports[$componentName])) {
                $imports[$componentName] = $importPath;
            }
        }

        // Generate imports
        foreach ($imports as $componentName => $importPath) {
            $content .= "import {$componentName} from '{$importPath}';\n";
        }

        // Import types
        $propsTypes = array_unique(array_column($this->routes, 'propsType'));
        if (!empty($propsTypes)) {
            $content .= "import type { " . implode(', ', $propsTypes) . " } from './types.generated';\n";
        }

        $content .= "\n";

        // Generate route type
        $content .= "export interface LoaderConfig {\n";
        $content .= "  endpoint: string;\n";
        $content .= "  dataKey: string;\n";
        $content .= "}\n\n";

        $content .= "export interface RouteConfig {\n";
        $content .= "  path: string;\n";
        $content .= "  component: React.ComponentType<any>;\n";
        $content .= "  title: string;\n";
        $content .= "  loaders?: Record<string, LoaderConfig>;\n";
        $content .= "}\n\n";

        // Generate routes array
        $content .= "export const routes: RouteConfig[] = [\n";
        foreach ($this->routes as $route) {
            if (!$route['page']) {
                continue;
            }

            $componentName = $this->getComponentName($route['module'], $route['page']);
            $loadersStr = $this->generateLoadersObject($route);

            if ($loadersStr) {
                $content .= "  { path: '{$route['path']}', component: {$componentName}, title: '{$route['title']}', loaders: {$loadersStr} },\n";
            } else {
                $content .= "  { path: '{$route['path']}', component: {$componentName}, title: '{$route['title']}' },\n";
            }
        }
        $content .= "];\n";

        $this->writeFile($this->outputDir . '/routes.generated.tsx', $content);
    }

    /**
     * Generate loaders object string for a route
     * Converts Loader('BoardList') → { endpoint: '/api/board/list', dataKey: 'posts' }
     */
    private function generateLoadersObject(array $route): ?string
    {
        if (empty($route['loaders'])) {
            return null;
        }

        $loaderEntries = [];
        foreach ($route['loaders'] as $propName => $loader) {
            $endpoint = $this->loaderToEndpoint($loader['method'], $loader['args'], $route['path']);
            $loaderEntries[] = "{$propName}: { endpoint: '{$endpoint}', dataKey: '{$propName}' }";
        }

        return '{ ' . implode(', ', $loaderEntries) . ' }';
    }

    /**
     * Convert Loader method name to API endpoint
     * BoardList → /api/board/list
     * BoardGet + ['id'] → /api/board?id=:id
     * HomeStats → /api/home/stats
     */
    private function loaderToEndpoint(string $method, array $args, string $routePath): string
    {
        // Split by uppercase: BoardList → ['Board', 'List']
        preg_match_all('/[A-Z][a-z]*/', $method, $matches);
        $parts = $matches[0];

        if (count($parts) < 2) {
            return '/api/' . strtolower($method);
        }

        $module = strtolower($parts[0]);
        $action = strtolower(implode('-', array_slice($parts, 1)));

        // Build base endpoint
        if ($action === 'get') {
            $endpoint = "/api/{$module}";
        } else {
            $endpoint = "/api/{$module}/{$action}";
        }

        // Add query params from route path (e.g., :id)
        if (!empty($args)) {
            $queryParams = [];
            foreach ($args as $arg) {
                // Extract param from route path
                $queryParams[] = "{$arg}=:{$arg}";
            }
            $endpoint .= '?' . implode('&', $queryParams);
        }

        return $endpoint;
    }

    /**
     * Get React component name from module and page
     */
    private function getComponentName(string $module, string $page): string
    {
        // Extract file name: "modules/board/Detail.tsx" -> "Detail"
        $fileName = pathinfo($page, PATHINFO_FILENAME);
        $module = ucfirst($module);

        return match (strtolower($fileName)) {
            'index' => $module . 'Index',
            'detail' => $module . 'Detail',
            'write' => $module . 'Write',
            'list' => $module . 'List',
            default => $module . ucfirst($fileName),
        };
    }

    /**
     * Get import path for component
     */
    private function getImportPath(string $page): string
    {
        // "modules/board/Detail.tsx" -> "../modules/board/Detail"
        $path = preg_replace('/\.tsx?$/', '', $page);
        return '../' . $path;
    }

    /**
     * Write content to file
     */
    private function writeFile(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, $content);
    }

    /**
     * Get parsed routes (for SSRRouter)
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }
}

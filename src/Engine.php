<?php

declare(strict_types=1);

namespace PhpSsrReact;

/**
 * phptossr Engine - React SSR for PHP
 */
class Engine
{
    private QuickJS $js;
    private array $config;
    private bool $initialized = false;
    private array $cache = [];

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'frontendDir' => './frontend/src',
            'cacheDir' => './cache',
            'appEnv' => 'development',
            'hotReloadPort' => 3001,
        ], $config);

        $this->js = new QuickJS();
        $this->initRuntime();
    }

    /**
     * Initialize React-like runtime
     */
    private function initRuntime(): void
    {
        if ($this->initialized) {
            return;
        }

        $runtime = <<<'JS'
        // React-like createElement
        function createElement(type, props, ...children) {
            return {
                type,
                props: props || {},
                children: children.flat().filter(c => c !== null && c !== undefined && c !== false)
            };
        }

        // Hooks simulation (limited - for SSR)
        var _hookIndex = 0;
        var _hooks = [];

        function useState(initial) {
            var index = _hookIndex++;
            if (_hooks[index] === undefined) {
                _hooks[index] = typeof initial === 'function' ? initial() : initial;
            }
            return [_hooks[index], function(val) { _hooks[index] = typeof val === 'function' ? val(_hooks[index]) : val; }];
        }

        function useEffect(fn, deps) {
            // No-op for SSR
        }

        function useMemo(fn, deps) {
            return fn();
        }

        function useCallback(fn, deps) {
            return fn;
        }

        function useRef(initial) {
            return { current: initial };
        }

        function useContext(context) {
            return context._currentValue;
        }

        function createContext(defaultValue) {
            return { _currentValue: defaultValue, Provider: function(props) { return props.children; } };
        }

        // renderToString implementation
        function renderToString(element) {
            _hookIndex = 0;
            _hooks = [];

            if (element === null || element === undefined || element === false) {
                return '';
            }
            if (typeof element === 'string' || typeof element === 'number') {
                return escapeHtml(String(element));
            }
            if (Array.isArray(element)) {
                return element.map(renderToString).join('');
            }

            if (typeof element.type === 'function') {
                // Function component
                var result = element.type({ ...element.props, children: element.children });
                return renderToString(result);
            }

            // Fragment
            if (element.type === Fragment || element.type === 'Fragment') {
                return element.children.map(renderToString).join('');
            }

            // Regular HTML element
            var html = '<' + element.type;

            // Add attributes
            for (var key in element.props) {
                if (key === 'children' || key === 'key' || key === 'ref') continue;
                var value = element.props[key];

                // Handle special props
                if (key === 'className') key = 'class';
                if (key === 'htmlFor') key = 'for';
                if (key === 'dangerouslySetInnerHTML') continue;

                // Skip event handlers
                if (key.startsWith('on')) continue;

                // Handle style objects
                if (key === 'style' && typeof value === 'object') {
                    var styleStr = Object.entries(value)
                        .map(function(e) {
                            var prop = e[0].replace(/([A-Z])/g, '-$1').toLowerCase();
                            return prop + ':' + e[1];
                        })
                        .join(';');
                    html += ' style="' + escapeHtml(styleStr) + '"';
                    continue;
                }

                if (typeof value === 'boolean') {
                    if (value) html += ' ' + key;
                } else if (value !== null && value !== undefined) {
                    html += ' ' + key + '="' + escapeHtml(String(value)) + '"';
                }
            }

            // Self-closing tags
            var voidElements = ['area','base','br','col','embed','hr','img','input','link','meta','param','source','track','wbr'];
            if (voidElements.indexOf(element.type) !== -1) {
                return html + '/>';
            }

            html += '>';

            // Handle dangerouslySetInnerHTML
            if (element.props.dangerouslySetInnerHTML) {
                html += element.props.dangerouslySetInnerHTML.__html || '';
            } else {
                // Add children
                for (var i = 0; i < element.children.length; i++) {
                    html += renderToString(element.children[i]);
                }
            }

            html += '</' + element.type + '>';
            return html;
        }

        function escapeHtml(str) {
            return str
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        // Fragment support
        function Fragment(props) {
            return props.children;
        }

        // Export React-like API
        var React = {
            createElement: createElement,
            Fragment: Fragment,
            useState: useState,
            useEffect: useEffect,
            useMemo: useMemo,
            useCallback: useCallback,
            useRef: useRef,
            useContext: useContext,
            createContext: createContext
        };

        var ReactDOMServer = {
            renderToString: renderToString
        };
        JS;

        $this->js->eval($runtime);
        $this->initialized = true;
    }

    /**
     * Render a React component file with props
     */
    public function render(string $component, array $props = [], array $options = []): string
    {
        $title = $options['title'] ?? 'App';
        $description = $options['description'] ?? '';
        $metaTags = $options['metaTags'] ?? [];

        if ($description) {
            $metaTags['description'] = $description;
        }

        // Get compiled JavaScript
        $componentJs = $this->compileComponent($component);
        $propsJson = json_encode($props, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Build render code
        $js = $componentJs . "\nvar __props = $propsJson;\nrenderToString(createElement(default_export, __props))";

        try {
            $html = $this->js->eval($js);
        } catch (\Exception $e) {
            if ($this->config['appEnv'] === 'development') {
                return $this->renderError($e->getMessage());
            }
            throw $e;
        }

        // Wrap in HTML document
        return $this->wrapInHtml($html, $title, $metaTags, $propsJson, $component);
    }

    /**
     * Compile TypeScript/JSX component to JavaScript
     */
    private function compileComponent(string $component): string
    {
        // Check cache first
        $cacheKey = md5($component);
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $componentPath = $this->config['frontendDir'] . '/' . $component;

        // Handle .tsx extension
        if (!str_ends_with($component, '.tsx') && !str_ends_with($component, '.jsx') && !str_ends_with($component, '.js')) {
            foreach (['.tsx', '.jsx', '.js'] as $ext) {
                if (file_exists($componentPath . $ext)) {
                    $componentPath .= $ext;
                    break;
                }
            }
        }

        if (!file_exists($componentPath)) {
            throw new \RuntimeException("Component not found: $component (looked in {$this->config['frontendDir']})");
        }

        // Read and transform TypeScript/JSX to JavaScript
        $code = file_get_contents($componentPath);
        $js = $this->transformToJs($code);

        // Cache in development
        $this->cache[$cacheKey] = $js;

        return $js;
    }

    /**
     * Transform TypeScript/JSX to JavaScript
     * This is a simplified transform - for production use esbuild
     */
    private function transformToJs(string $code): string
    {
        // Remove TypeScript type annotations
        $code = preg_replace('/:\s*\w+(\[\])?(\s*[=,\)}>])/', '$2', $code);
        $code = preg_replace('/interface\s+\w+\s*\{[^}]*\}/', '', $code);
        $code = preg_replace('/<\w+>/', '', $code); // Generic types

        // Transform imports to no-op (we provide React globally)
        $code = preg_replace('/import\s+.*?from\s+[\'"]react[\'"];?/', '', $code);
        $code = preg_replace('/import\s+.*?from\s+[\'"][^"\']+[\'"];?/', '', $code);

        // Transform export default to variable assignment
        $code = preg_replace('/export\s+default\s+function\s+(\w+)/', 'var default_export = function $1', $code);
        $code = preg_replace('/export\s+default\s+/', 'var default_export = ', $code);

        // Transform JSX to createElement calls
        $code = $this->transformJsx($code);

        return $code;
    }

    /**
     * Transform JSX syntax to createElement calls
     */
    private function transformJsx(string $code): string
    {
        // This is a simplified JSX transform
        // For production, use esbuild or babel

        // Transform self-closing tags: <Component /> => createElement(Component, null)
        $code = preg_replace_callback(
            '/<([A-Z][a-zA-Z0-9]*)\s*(\{[^}]*\})?\s*\/>/',
            function ($matches) {
                $tag = $matches[1];
                $props = isset($matches[2]) ? $this->jsxPropsToObject($matches[2]) : 'null';
                return "createElement($tag, $props)";
            },
            $code
        );

        // Transform HTML self-closing tags: <br /> => createElement('br', null)
        $code = preg_replace_callback(
            '/<([a-z][a-zA-Z0-9]*)\s*([^>]*)?\/>/',
            function ($matches) {
                $tag = "'" . $matches[1] . "'";
                $props = !empty(trim($matches[2] ?? '')) ? $this->htmlPropsToObject($matches[2]) : 'null';
                return "createElement($tag, $props)";
            },
            $code
        );

        // Transform opening/closing tags
        // This is simplified - a real implementation would need proper parsing
        $code = preg_replace_callback(
            '/<([A-Z][a-zA-Z0-9]*)([^>]*)>([^<]*)<\/\1>/',
            function ($matches) {
                $tag = $matches[1];
                $props = !empty(trim($matches[2])) ? $this->jsxPropsToObject($matches[2]) : 'null';
                $children = trim($matches[3]);
                if ($children) {
                    return "createElement($tag, $props, \"$children\")";
                }
                return "createElement($tag, $props)";
            },
            $code
        );

        // Transform HTML tags
        $code = preg_replace_callback(
            '/<([a-z][a-zA-Z0-9]*)([^>]*)>([^<]*)<\/\1>/',
            function ($matches) {
                $tag = "'" . $matches[1] . "'";
                $props = !empty(trim($matches[2])) ? $this->htmlPropsToObject($matches[2]) : 'null';
                $children = trim($matches[3]);
                if ($children) {
                    return "createElement($tag, $props, \"$children\")";
                }
                return "createElement($tag, $props)";
            },
            $code
        );

        return $code;
    }

    /**
     * Convert JSX props to JavaScript object
     */
    private function jsxPropsToObject(string $props): string
    {
        // Remove leading { and trailing }
        $props = trim($props, '{}');
        if (empty($props)) {
            return 'null';
        }

        // Spread operator
        if (str_starts_with($props, '...')) {
            return $props;
        }

        return "{{$props}}";
    }

    /**
     * Convert HTML attributes to JavaScript object
     */
    private function htmlPropsToObject(string $attrs): string
    {
        $attrs = trim($attrs);
        if (empty($attrs)) {
            return 'null';
        }

        // Parse attributes like: class="foo" id={bar}
        $result = [];
        preg_match_all('/([a-zA-Z-]+)=(?:"([^"]*)"|{([^}]*)})/', $attrs, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $key = $match[1];
            $value = $match[2] ?? $match[3] ?? '';

            // className conversion
            if ($key === 'class') {
                $key = 'className';
            }

            if (isset($match[3])) {
                // JSX expression
                $result[] = "$key: $value";
            } else {
                // String value
                $result[] = "$key: \"$value\"";
            }
        }

        if (empty($result)) {
            return 'null';
        }

        return '{' . implode(', ', $result) . '}';
    }

    /**
     * Wrap rendered HTML in a full HTML document
     */
    private function wrapInHtml(string $content, string $title, array $metaTags, string $propsJson, string $component): string
    {
        $meta = '';
        foreach ($metaTags as $name => $value) {
            $meta .= '<meta name="' . htmlspecialchars($name) . '" content="' . htmlspecialchars($value) . '">' . "\n    ";
        }

        $hotReload = '';
        if ($this->config['appEnv'] === 'development') {
            $port = $this->config['hotReloadPort'];
            $hotReload = <<<HTML
    <script>
    (function() {
        var ws = new WebSocket('ws://localhost:$port/ws');
        ws.onmessage = function(e) { if (e.data === 'reload') location.reload(); };
        ws.onclose = function() { setTimeout(function() { location.reload(); }, 1000); };
    })();
    </script>
HTML;
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    $meta<title>$title</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif; margin: 0; padding: 20px; }
        .container { max-width: 800px; margin: 0 auto; }
        nav { margin-top: 20px; }
        nav a { margin-right: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 8px; border: 1px solid #ddd; text-align: left; }
        .status { padding: 2px 8px; border-radius: 4px; }
        .status-active { background: #d4edda; color: #155724; }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-completed { background: #cce5ff; color: #004085; }
        .card { border: 1px solid #ddd; border-radius: 8px; padding: 16px; margin: 16px 0; }
        button { padding: 8px 16px; cursor: pointer; }
        .counter { margin: 20px 0; padding: 20px; background: #f5f5f5; border-radius: 8px; }
        .counter button { margin: 0 5px; }
    </style>
</head>
<body>
    <div id="root">$content</div>
    <script>window.__INITIAL_PROPS__ = $propsJson;</script>
    <script>window.__COMPONENT__ = "$component";</script>
$hotReload
</body>
</html>
HTML;
    }

    /**
     * Render an error page
     */
    private function renderError(string $message): string
    {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>Error</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #fff5f5; }
        pre { background: #2d2d2d; color: #f8f8f2; padding: 20px; border-radius: 8px; overflow-x: auto; }
    </style>
</head>
<body>
    <h1>Render Error</h1>
    <pre>$message</pre>
</body>
</html>
HTML;
    }

    /**
     * Get QuickJS instance
     */
    public function getJS(): QuickJS
    {
        return $this->js;
    }
}

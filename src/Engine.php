<?php

declare(strict_types=1);

namespace PhpSsrReact;

use PhpSsrReact\QuickJS;

/**
 * phptossr Engine - React SSR for PHP
 *
 * Uses pre-compiled SSR bundle from esbuild for rendering
 */
class Engine
{
    private QuickJS $js;
    private array $config;
    private bool $initialized = false;
    private ?array $manifest = null;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'frontendDir' => './frontend',
            'appEnv' => 'development',
        ], $config);

        $this->js = new QuickJS();
    }

    /**
     * Initialize SSR bundle
     */
    private function initSSR(): void
    {
        if ($this->initialized) {
            return;
        }

        $ssrBundlePath = $this->config['frontendDir'] . '/dist/ssr-bundle.js';

        if (!file_exists($ssrBundlePath)) {
            throw new \RuntimeException(
                "SSR bundle not found at: $ssrBundlePath\n" .
                "Run 'npm run build' in the frontend directory first."
            );
        }

        // Provide polyfills for QuickJS (browser APIs used by React DOM Server)
        $polyfills = <<<'JS'
var console = { log: function(){}, warn: function(){}, error: function(){}, info: function(){} };

// MessageChannel polyfill for React scheduler
function MessageChannel() {
    this.port1 = { onmessage: null };
    this.port2 = {
        postMessage: function() {
            if (this._port1.onmessage) {
                this._port1.onmessage({ data: null });
            }
        }.bind({ _port1: this.port1 })
    };
}

// TextEncoder/TextDecoder polyfill
function TextEncoder() {}
TextEncoder.prototype.encode = function(str) {
    var arr = [];
    for (var i = 0; i < str.length; i++) {
        arr.push(str.charCodeAt(i));
    }
    return new Uint8Array(arr);
};

function TextDecoder() {}
TextDecoder.prototype.decode = function(arr) {
    var str = '';
    for (var i = 0; i < arr.length; i++) {
        str += String.fromCharCode(arr[i]);
    }
    return str;
};

// setImmediate polyfill
function setImmediate(fn) { fn(); return 0; }
function clearImmediate() {}

// queueMicrotask polyfill
if (typeof queueMicrotask === 'undefined') {
    var queueMicrotask = function(fn) { fn(); };
}
JS;
        $this->js->eval($polyfills);

        // Load SSR bundle
        $ssrBundle = file_get_contents($ssrBundlePath);
        $this->js->eval($ssrBundle);

        $this->initialized = true;
    }

    /**
     * Load manifest.json for hashed asset paths
     */
    private function getManifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        $manifestPath = $this->config['frontendDir'] . '/dist/manifest.json';
        if (file_exists($manifestPath)) {
            $this->manifest = json_decode(file_get_contents($manifestPath), true) ?? [];
        } else {
            $this->manifest = [];
        }

        return $this->manifest;
    }

    /**
     * Get JS and CSS paths from manifest
     */
    private function getAssetPaths(): array
    {
        $manifest = $this->getManifest();

        if (isset($manifest['main'])) {
            return [
                'js' => $manifest['main']['js'],
                'css' => $manifest['main']['css'],
            ];
        }

        // Fallback for old bundle names
        return [
            'js' => '/bundle.js',
            'css' => '/bundle.css',
        ];
    }

    /**
     * Render a page with SSR
     */
    public function render(string $path, array $props = [], array $options = []): string
    {
        $this->initSSR();

        $title = $options['title'] ?? 'App';
        $metaTags = $options['metaTags'] ?? [];

        $propsJson = json_encode($props, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $pathJson = json_encode($path);

        // Render with SSR bundle
        $js = "var __SSR_PATH__ = $pathJson; var __SSR_PROPS__ = $propsJson; SSR.render()";

        try {
            $html = $this->js->eval($js);
        } catch (\Exception $e) {
            if ($this->config['appEnv'] === 'development') {
                return $this->renderError($e->getMessage());
            }
            throw $e;
        }

        return $this->wrapInHtml($html, $title, $metaTags, $propsJson);
    }

    /**
     * Wrap rendered HTML in a full HTML document
     */
    private function wrapInHtml(string $content, string $title, array $metaTags, string $propsJson): string
    {
        $meta = '';
        foreach ($metaTags as $name => $value) {
            $meta .= '<meta name="' . htmlspecialchars($name) . '" content="' . htmlspecialchars($value) . '">' . "\n    ";
        }

        $assets = $this->getAssetPaths();
        $jsPath = $assets['js'];
        $cssPath = $assets['css'];

        $cssLink = $cssPath ? "<link rel=\"stylesheet\" href=\"$cssPath\">" : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    $meta<title>$title</title>
    $cssLink
</head>
<body>
    <div id="root">$content</div>
    <script>window.__INITIAL_PROPS__ = $propsJson;</script>
    <script>window.__REQUEST_PATH__ = location.pathname;</script>
    <script type="module" src="$jsPath"></script>
</body>
</html>
HTML;
    }

    /**
     * Render an error page
     */
    private function renderError(string $message): string
    {
        $escapedMessage = htmlspecialchars($message);
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
    <pre>$escapedMessage</pre>
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

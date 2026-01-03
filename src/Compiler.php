<?php

declare(strict_types=1);

namespace PhpSsrReact;

/**
 * Component Compiler using esbuild
 */
class Compiler
{
    private string $frontendDir;
    private string $cacheDir;
    private bool $isDev;

    public function __construct(string $frontendDir, string $cacheDir = './cache', bool $isDev = true)
    {
        $this->frontendDir = realpath($frontendDir) ?: $frontendDir;
        $this->cacheDir = $cacheDir;
        $this->isDev = $isDev;

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * Compile a component using esbuild
     */
    public function compile(string $component): string
    {
        $componentPath = $this->resolvePath($component);
        $cacheFile = $this->getCachePath($component);

        // In development, check if source is newer than cache
        if ($this->isDev && file_exists($cacheFile)) {
            if (filemtime($componentPath) <= filemtime($cacheFile)) {
                return file_get_contents($cacheFile);
            }
        }

        // Check if esbuild is available
        $esbuildPath = $this->findEsbuild();
        if ($esbuildPath) {
            $compiled = $this->compileWithEsbuild($componentPath, $esbuildPath);
        } else {
            // Fallback to simple transform
            $compiled = $this->compileSimple($componentPath);
        }

        // Cache the result
        file_put_contents($cacheFile, $compiled);

        return $compiled;
    }

    /**
     * Resolve component path
     */
    private function resolvePath(string $component): string
    {
        $path = $this->frontendDir . '/' . $component;

        // Try various extensions
        if (file_exists($path)) {
            return $path;
        }

        foreach (['.tsx', '.jsx', '.ts', '.js'] as $ext) {
            if (file_exists($path . $ext)) {
                return $path . $ext;
            }
        }

        throw new \RuntimeException("Component not found: $component");
    }

    /**
     * Get cache file path
     */
    private function getCachePath(string $component): string
    {
        return $this->cacheDir . '/' . md5($component) . '.js';
    }

    /**
     * Find esbuild executable
     */
    private function findEsbuild(): ?string
    {
        // Check local node_modules
        $localPath = dirname($this->frontendDir) . '/node_modules/.bin/esbuild';
        if (file_exists($localPath)) {
            return $localPath;
        }

        // Check global
        $result = shell_exec('which esbuild 2>/dev/null');
        if ($result) {
            return trim($result);
        }

        return null;
    }

    /**
     * Compile using esbuild
     */
    private function compileWithEsbuild(string $componentPath, string $esbuildPath): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'phptossr_');

        // Create a wrapper that exports the component
        $wrapperCode = <<<JS
import Component from '{$componentPath}';
globalThis.__COMPONENT__ = Component;
JS;
        file_put_contents($tempFile . '.tsx', $wrapperCode);

        // Run esbuild
        $cmd = sprintf(
            '%s %s --bundle --format=iife --platform=neutral --target=es2020 ' .
            '--jsx=automatic --jsx-import-source=react ' .
            '--external:react --external:react-dom ' .
            '--define:process.env.NODE_ENV=\'"production"\' 2>&1',
            escapeshellarg($esbuildPath),
            escapeshellarg($tempFile . '.tsx')
        );

        $output = shell_exec($cmd);
        unlink($tempFile . '.tsx');
        @unlink($tempFile);

        if (strpos($output, 'error') !== false) {
            throw new \RuntimeException("esbuild error: $output");
        }

        // Post-process to remove React imports and use our global
        $output = $this->postProcessEsbuild($output);

        return $output;
    }

    /**
     * Post-process esbuild output
     */
    private function postProcessEsbuild(string $code): string
    {
        // Remove any remaining React imports
        $code = preg_replace('/import\s+.*?from\s*["\']react["\'];?/s', '', $code);

        // Replace React.createElement with our createElement
        $code = str_replace('React.createElement', 'createElement', $code);

        // Extract the component and make it accessible
        $code .= "\nvar default_export = globalThis.__COMPONENT__ || (typeof Component !== 'undefined' ? Component : null);";

        return $code;
    }

    /**
     * Simple compile without esbuild (fallback)
     */
    private function compileSimple(string $componentPath): string
    {
        $code = file_get_contents($componentPath);

        // Remove TypeScript types
        $code = preg_replace('/:\s*[A-Z]\w*(\[\])?(\s*[=,\)}>])/', '$2', $code);
        $code = preg_replace('/interface\s+\w+\s*\{[^}]*\}/s', '', $code);
        $code = preg_replace('/<\w+(?:,\s*\w+)*>/', '', $code);

        // Remove imports
        $code = preg_replace('/^import\s+.*?;?\s*$/m', '', $code);

        // Transform export default
        $code = preg_replace('/export\s+default\s+function\s+(\w+)/', 'var default_export = function $1', $code);
        $code = preg_replace('/export\s+default\s+/', 'var default_export = ', $code);

        return $code;
    }

    /**
     * Clear cache
     */
    public function clearCache(): void
    {
        $files = glob($this->cacheDir . '/*.js');
        foreach ($files as $file) {
            unlink($file);
        }
    }
}

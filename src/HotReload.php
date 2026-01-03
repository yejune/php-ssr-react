<?php
declare(strict_types=1);

namespace PhpSsrReact;

/**
 * Hot Reload Server for development
 */
class HotReload
{
    private int $port;
    private array $clients = [];
    private string $frontendDir;
    private float $lastModified = 0;

    public function __construct(int $port = 3001, string $frontendDir = './frontend')
    {
        $this->port = $port;
        $this->frontendDir = $frontendDir;
    }

    /**
     * Get the hot reload script to inject into HTML
     */
    public function getScript(): string
    {
        return <<<JS
<script>
(function() {
    const ws = new WebSocket('ws://localhost:{$this->port}/ws');
    ws.onmessage = function(event) {
        if (event.data === 'reload') {
            location.reload();
        }
    };
    ws.onclose = function() {
        setTimeout(() => location.reload(), 1000);
    };
})();
</script>
JS;
    }

    /**
     * Check for file changes in frontend directory
     */
    public function checkForChanges(): bool
    {
        $latestModified = $this->getLatestModifiedTime($this->frontendDir);
        if ($latestModified > $this->lastModified) {
            $this->lastModified = $latestModified;
            return true;
        }
        return false;
    }

    private function getLatestModifiedTime(string $dir): float
    {
        $latest = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $mtime = $file->getMTime();
                if ($mtime > $latest) {
                    $latest = $mtime;
                }
            }
        }
        return (float)$latest;
    }

    public function getPort(): int
    {
        return $this->port;
    }
}

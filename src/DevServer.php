<?php
declare(strict_types=1);

namespace PhpSsrReact;

/**
 * Development Server - 파일 감시 + 빌드 트리거
 *
 * Node.js의 npm run dev와 동일한 동작:
 * - 파일 변경 감지
 * - npm run build:* 스크립트 호출
 * - 멱등성 보장 (둘 다 같은 npm 스크립트 사용)
 */
class DevServer
{
    private HotReload $hotReload;

    /** @var array<string, string> 감시 경로 => 빌드 타입 */
    private array $watchPaths = [
        'frontend/assets/styles' => 'css',
        'frontend/assets/js' => 'custom-js',
        'frontend/pages' => 'ssr',
        'frontend/core' => 'client',
        'frontend/assets/fonts' => 'assets',
        'frontend/assets/images' => 'assets',
        'frontend/assets/vendor' => 'assets',
    ];

    /** @var array<string, float> 경로별 마지막 수정 시간 */
    private array $lastModified = [];

    private bool $verbose;

    public function __construct(int $port = 3001, bool $verbose = true)
    {
        $this->hotReload = new HotReload($port);
        $this->verbose = $verbose;

        // 초기 수정 시간 설정
        foreach ($this->watchPaths as $path => $type) {
            $fullPath = __DIR__ . '/../' . $path;
            if (is_dir($fullPath)) {
                $this->lastModified[$path] = $this->getLatestModifiedTime($fullPath);
            }
        }
    }

    /**
     * 파일 감시 시작
     */
    public function watch(): void
    {
        $this->log("PHP Dev Server started");
        $this->log("Watching: " . implode(', ', array_keys($this->watchPaths)));
        $this->log("");

        while (true) {
            $changed = false;

            foreach ($this->watchPaths as $path => $buildType) {
                if ($this->hasChanges($path)) {
                    $this->runBuild($buildType);
                    $changed = true;
                }
            }

            usleep(500000); // 0.5초
        }
    }

    /**
     * 파일 변경 감지
     */
    private function hasChanges(string $path): bool
    {
        $fullPath = __DIR__ . '/../' . $path;

        if (!is_dir($fullPath)) {
            return false;
        }

        $latestModified = $this->getLatestModifiedTime($fullPath);

        if ($latestModified > ($this->lastModified[$path] ?? 0)) {
            $this->lastModified[$path] = $latestModified;
            return true;
        }

        return false;
    }

    /**
     * 디렉토리 내 최신 수정 시간 조회
     */
    private function getLatestModifiedTime(string $dir): float
    {
        $latest = 0.0;

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $mtime = (float) $file->getMTime();
                    if ($mtime > $latest) {
                        $latest = $mtime;
                    }
                }
            }
        } catch (\Exception $e) {
            // 디렉토리 읽기 실패 시 무시
        }

        return $latest;
    }

    /**
     * Node.js 빌드 스크립트 실행
     */
    private function runBuild(string $type): void
    {
        static $commands = [
            'css' => 'npm run build:css',
            'custom-js' => 'npm run build:custom-js',
            'ssr' => 'npm run build:ssr',
            'client' => 'npm run build:client',
            'assets' => 'npm run build:assets',
        ];

        if (!isset($commands[$type])) {
            return;
        }

        $command = $commands[$type];
        $this->log("Building {$type}...");

        $startTime = microtime(true);

        // 프로젝트 루트에서 실행
        $cwd = __DIR__ . '/..';
        $fullCommand = "cd " . escapeshellarg($cwd) . " && {$command} 2>&1";

        passthru($fullCommand, $exitCode);

        $elapsed = round((microtime(true) - $startTime) * 1000);

        if ($exitCode === 0) {
            $this->log("{$type} build complete ({$elapsed}ms)");
        } else {
            $this->log("{$type} build FAILED (exit code: {$exitCode})");
        }

        $this->log("");
    }

    /**
     * 로그 출력
     */
    private function log(string $message): void
    {
        if ($this->verbose) {
            $timestamp = date('H:i:s');
            echo "[{$timestamp}] {$message}\n";
        }
    }

    public function getHotReload(): HotReload
    {
        return $this->hotReload;
    }
}

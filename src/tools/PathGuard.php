<?php

namespace cossette\projectassistant\tools;

use Craft;
use cossette\projectassistant\Plugin;

/**
 * Keeps the code tools inside the configured allowed paths and away from
 * secrets. Every path the model gives is resolved to its real path (no `..`,
 * no symlink escapes) and checked against the allow and deny lists.
 */
class PathGuard
{
    /** Never readable, whatever the project config says. */
    public const ALWAYS_DENIED = [
        '.env', '.env.*', '*/.env', '*/.env.*',
        '*.key', '*.key.*', '*.pem', '*.p12', '*.pfx', '*.crt', 'id_rsa*', '*/id_rsa*',
        'storage', 'storage/*', 'vendor', 'vendor/*', 'node_modules', 'node_modules/*', '*/node_modules/*',
        '.git', '.git/*', '*/.git', '*/.git/*', 'web/cpresources', 'web/cpresources/*',
        'config/license.key*', '*auth.json', '*credentials*', '*secret*', '*.sql', '*.sql.gz',
    ];

    private string $root;
    /** @var string[] real paths */
    private array $allowedRoots = [];
    /** @var string[] */
    private array $denied;

    public function __construct(?array $allowedPaths = null, ?array $deniedPaths = null)
    {
        $settings = Plugin::getInstance()->getSettings();
        $this->root = rtrim((string)realpath(Craft::getAlias('@root')), '/');

        foreach ($allowedPaths ?? $settings->allowedPaths as $path) {
            $real = realpath($this->root . '/' . ltrim($path, '/'));
            if ($real !== false && $this->isUnderRoot($real)) {
                $this->allowedRoots[] = rtrim($real, '/');
            }
        }

        $this->denied = array_merge(self::ALWAYS_DENIED, $deniedPaths ?? $settings->deniedPaths);
    }

    public function getRoot(): string
    {
        return $this->root;
    }

    /** @return string[] allowed paths relative to the project root */
    public function getAllowedRelativePaths(): array
    {
        return array_map(fn(string $p) => $this->relative($p), $this->allowedRoots);
    }

    /**
     * Resolves a model-supplied path. Returns the absolute real path, or null
     * if it doesn't exist or isn't readable under the rules.
     */
    public function resolve(string $path): ?string
    {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '' || $path === '.' || $path === '/') {
            return null;
        }
        $real = realpath($this->root . '/' . ltrim($path, '/'));

        return $real !== false && $this->isAllowed($real) ? $real : null;
    }

    public function isAllowed(string $realPath): bool
    {
        if (!$this->isUnderRoot($realPath)) {
            return false;
        }
        $inAllowed = false;
        foreach ($this->allowedRoots as $allowed) {
            if ($realPath === $allowed || str_starts_with($realPath, $allowed . '/')) {
                $inAllowed = true;
                break;
            }
        }

        return $inAllowed && !$this->isDenied($this->relative($realPath));
    }

    public function isDenied(string $relativePath): bool
    {
        $basename = basename($relativePath);
        foreach ($this->denied as $pattern) {
            if (fnmatch($pattern, $relativePath) || (!str_contains($pattern, '/') && fnmatch($pattern, $basename))) {
                return true;
            }
        }

        return false;
    }

    public function relative(string $realPath): string
    {
        return ltrim(substr($realPath, strlen($this->root)), '/');
    }

    /**
     * Walks the allowed files under $start (absolute real path), yielding
     * relative paths. Skips denied paths and anything outside the allow list.
     *
     * @return \Generator<string>
     */
    public function walk(string $start): \Generator
    {
        if (is_file($start)) {
            if ($this->isAllowed($start)) {
                yield $this->relative($start);
            }
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($start, \FilesystemIterator::SKIP_DOTS),
                fn(\SplFileInfo $file) => !$this->isDenied($this->relative($file->getPathname())) && !$file->isLink(),
            ),
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile()) {
                $real = $file->getRealPath();
                if ($real !== false && $this->isAllowed($real)) {
                    yield $this->relative($real);
                }
            }
        }
    }

    private function isUnderRoot(string $realPath): bool
    {
        return $realPath === $this->root || str_starts_with($realPath, $this->root . '/');
    }
}

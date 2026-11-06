<?php

namespace cossette\projectassistant\tools;

use Craft;
use cossette\projectassistant\Plugin;

class ListFilesTool extends BaseTool
{
    private const MAX_RESULTS = 400;

    public function getName(): string
    {
        return 'list_files';
    }

    public function getDescription(): string
    {
        return 'List project files the assistant may read. Without a path, lists the readable top-level paths. '
            . 'With a path, lists files under it recursively. Optionally filter with a glob pattern matched '
            . 'against the relative path, e.g. "*.php" or "config/project/sections/*".';
    }

    public function getInputSchema(): array
    {
        return self::schema([
            'path' => ['type' => 'string', 'description' => 'Directory relative to the project root. Omit to list the readable roots.'],
            'pattern' => ['type' => 'string', 'description' => 'Optional glob filter on the relative path.'],
        ]);
    }

    public function getLabel(array $input): string
    {
        $path = self::str($input, 'path');

        return $path !== ''
            ? Craft::t(Plugin::HANDLE, 'Listing files in {path}', ['path' => $path])
            : Craft::t(Plugin::HANDLE, 'Listing project files');
    }

    public function execute(array $input, ToolContext $context): ToolResult
    {
        $guard = new PathGuard();
        $path = self::str($input, 'path');
        $pattern = self::str($input, 'pattern');

        if ($path === '') {
            if ($pattern === '') {
                return ToolResult::json(['readablePaths' => $guard->getAllowedRelativePaths()]);
            }
            $starts = array_map(fn($p) => $guard->getRoot() . '/' . $p, $guard->getAllowedRelativePaths());
        } else {
            $real = $guard->resolve($path);
            if ($real === null) {
                return ToolResult::error("Path not found or not readable: $path. Readable paths: " . implode(', ', $guard->getAllowedRelativePaths()));
            }
            $starts = [$real];
        }

        $files = [];
        $truncated = false;
        foreach ($starts as $start) {
            foreach ($guard->walk($start) as $relative) {
                if ($pattern !== '' && !fnmatch($pattern, $relative) && !fnmatch($pattern, basename($relative))) {
                    continue;
                }
                if (count($files) >= self::MAX_RESULTS) {
                    $truncated = true;
                    break 2;
                }
                $files[] = $relative;
            }
        }
        sort($files);

        return new ToolResult(
            ($files ? implode("\n", $files) : '(no files)')
            . ($truncated ? "\n... truncated at " . self::MAX_RESULTS . ' files; narrow the path or pattern.' : ''),
        );
    }
}

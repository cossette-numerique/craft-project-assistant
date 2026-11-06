<?php

namespace cossette\projectassistant\tools;

use Craft;
use cossette\projectassistant\Plugin;

class SearchCodeTool extends BaseTool
{
    private const MAX_MATCHES = 150;
    private const MAX_FILE_BYTES = 1500000;

    public function getName(): string
    {
        return 'search_code';
    }

    public function getDescription(): string
    {
        return 'Search the readable project files for a regular expression (PCRE, case-insensitive by default). '
            . 'Returns matching lines as path:line: text. Use it to find where a field handle, section, '
            . 'GraphQL query, route or class is defined or used.';
    }

    public function getInputSchema(): array
    {
        return self::schema([
            'pattern' => ['type' => 'string', 'description' => 'Regular expression, without delimiters.'],
            'path' => ['type' => 'string', 'description' => 'Optional directory or file to search in, relative to the project root.'],
            'glob' => ['type' => 'string', 'description' => 'Optional glob filter on file names, e.g. "*.yaml".'],
            'caseSensitive' => ['type' => 'boolean', 'description' => 'Default false.'],
        ], ['pattern']);
    }

    public function getLabel(array $input): string
    {
        return Craft::t(Plugin::HANDLE, 'Searching the code for “{pattern}”', ['pattern' => self::str($input, 'pattern')]);
    }

    public function execute(array $input, ToolContext $context): ToolResult
    {
        $guard = new PathGuard();
        $pattern = self::str($input, 'pattern');
        if ($pattern === '') {
            return ToolResult::error('pattern is required.');
        }
        $regex = '~' . str_replace('~', '\~', $pattern) . '~u' . (empty($input['caseSensitive']) ? 'i' : '');
        if (@preg_match($regex, '') === false) {
            return ToolResult::error('Invalid regular expression: ' . $pattern);
        }

        $path = self::str($input, 'path');
        if ($path !== '') {
            $real = $guard->resolve($path);
            if ($real === null) {
                return ToolResult::error("Path not found or not readable: $path");
            }
            $starts = [$real];
        } else {
            $starts = array_map(fn($p) => $guard->getRoot() . '/' . $p, $guard->getAllowedRelativePaths());
        }
        $glob = self::str($input, 'glob');

        $matches = [];
        $files = 0;
        foreach ($starts as $start) {
            foreach ($guard->walk($start) as $relative) {
                if ($glob !== '' && !fnmatch($glob, basename($relative)) && !fnmatch($glob, $relative)) {
                    continue;
                }
                $absolute = $guard->getRoot() . '/' . $relative;
                if (filesize($absolute) > self::MAX_FILE_BYTES) {
                    continue;
                }
                $content = (string)file_get_contents($absolute);
                if (str_contains(substr($content, 0, 8000), "\0")) {
                    continue;
                }
                $files++;
                foreach (preg_split('/\R/', $content) as $i => $line) {
                    if (preg_match($regex, $line)) {
                        $matches[] = $relative . ':' . ($i + 1) . ': ' . mb_strimwidth(trim($line), 0, 300, '…');
                        if (count($matches) >= self::MAX_MATCHES) {
                            return new ToolResult(implode("\n", $matches) . "\n... stopped at " . self::MAX_MATCHES . ' matches; narrow the search.');
                        }
                    }
                }
            }
        }

        return new ToolResult($matches ? implode("\n", $matches) : "No matches in $files files.");
    }
}

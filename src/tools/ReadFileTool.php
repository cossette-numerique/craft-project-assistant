<?php

namespace cossette\projectassistant\tools;

use Craft;
use cossette\projectassistant\Plugin;

class ReadFileTool extends BaseTool
{
    private const MAX_LINES = 1500;
    private const MAX_BYTES = 120000;

    public function getName(): string
    {
        return 'read_file';
    }

    public function getDescription(): string
    {
        return 'Read a project file (code, project config YAML, documentation). Returns numbered lines. '
            . 'Use offset/limit to page through long files.';
    }

    public function getInputSchema(): array
    {
        return self::schema([
            'path' => ['type' => 'string', 'description' => 'File path relative to the project root.'],
            'offset' => ['type' => 'integer', 'description' => 'First line to return (1-based). Default 1.'],
            'limit' => ['type' => 'integer', 'description' => 'Number of lines to return. Default and max ' . self::MAX_LINES . '.'],
        ], ['path']);
    }

    public function getLabel(array $input): string
    {
        return Craft::t(Plugin::HANDLE, 'Reading {path}', ['path' => self::str($input, 'path')]);
    }

    public function execute(array $input, ToolContext $context): ToolResult
    {
        $guard = new PathGuard();
        $path = self::str($input, 'path');
        $real = $guard->resolve($path);

        if ($real === null || !is_file($real)) {
            return ToolResult::error("File not found or not readable: $path");
        }

        $handle = fopen($real, 'rb');
        if ($handle === false) {
            return ToolResult::error("Could not open $path");
        }
        $head = (string)fread($handle, 8000);
        if (str_contains($head, "\0")) {
            fclose($handle);
            return ToolResult::error("$path is a binary file.");
        }
        rewind($handle);

        $offset = self::int($input, 'offset', 1, 1, PHP_INT_MAX);
        $limit = self::int($input, 'limit', self::MAX_LINES, 1, self::MAX_LINES);
        $out = [];
        $bytes = 0;
        $lineNo = 0;
        $more = false;
        while (($line = fgets($handle)) !== false) {
            $lineNo++;
            if ($lineNo < $offset) {
                continue;
            }
            if (count($out) >= $limit || $bytes > self::MAX_BYTES) {
                $more = true;
                break;
            }
            $line = rtrim($line, "\r\n");
            $bytes += strlen($line);
            $out[] = sprintf('%6d  %s', $lineNo, $line);
        }
        fclose($handle);

        if (!$out) {
            return new ToolResult($lineNo === 0 ? '(empty file)' : "(no lines from $offset; the file has $lineNo lines)");
        }

        $text = implode("\n", $out);
        if ($more) {
            $next = $offset + count($out);
            $text .= "\n... more lines follow; call again with offset $next.";
        }

        return new ToolResult(mb_convert_encoding($text, 'UTF-8', 'UTF-8'));
    }
}

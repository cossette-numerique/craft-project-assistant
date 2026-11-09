<?php

namespace cossette\projectassistant\tools;

use Craft;
use cossette\projectassistant\Plugin;

/**
 * Reads Craft's log files (storage/logs) for the developer chat. Read-only.
 *
 * Without a file, lists the log files, newest first. With a file, returns its
 * last lines, or the last lines matching a search, reading from the end so
 * large logs stay cheap. Values of secret-looking environment variables and
 * common credential patterns are redacted before the model sees them.
 */
class ReadLogsTool extends BaseTool
{
    private const DEFAULT_LINES = 200;
    private const MAX_LINES = 2000;
    private const MAX_LINE_CHARS = 2000;
    private const MAX_OUTPUT_BYTES = 120000;
    /** How far back a search scans in one file. */
    private const MAX_SCAN_BYTES = 64 * 1024 * 1024;
    private const MAX_LISTED = 200;

    public function getName(): string
    {
        return 'read_logs';
    }

    public function getDescription(): string
    {
        return 'Read the project\'s Craft log files (storage/logs: web, console, queue, phperrors and plugin/module logs, '
            . 'usually one file per day). Without a file, lists the log files with size and last change, newest first. '
            . 'With a file, returns its last lines (newest at the bottom). Use search to keep only matching lines '
            . '(case-insensitive text, e.g. "error", ".ERROR]", an exception class or a URL). Secrets are redacted.';
    }

    public function getInputSchema(): array
    {
        return self::schema([
            'file' => ['type' => 'string', 'description' => 'Log file name, e.g. "web-2026-11-09.log". Omit to list the files.'],
            'pattern' => ['type' => 'string', 'description' => 'When listing: optional glob on the file name, e.g. "queue-*".'],
            'search' => ['type' => 'string', 'description' => 'Optional case-insensitive text: only lines containing it are returned.'],
            'lines' => ['type' => 'integer', 'description' => 'How many (matching) lines to return from the end. Default ' . self::DEFAULT_LINES . ', max ' . self::MAX_LINES . '.'],
        ]);
    }

    public function getLabel(array $input): string
    {
        $file = self::str($input, 'file');

        return $file !== ''
            ? Craft::t(Plugin::HANDLE, 'Reading log {file}', ['file' => $file])
            : Craft::t(Plugin::HANDLE, 'Listing log files');
    }

    public function execute(array $input, ToolContext $context): ToolResult
    {
        $dir = realpath(Craft::$app->getPath()->getLogPath(false));
        if ($dir === false || !is_dir($dir)) {
            return ToolResult::error('There is no log directory on this environment.');
        }

        $file = self::str($input, 'file');
        if ($file === '') {
            return $this->listFiles($dir, self::str($input, 'pattern'));
        }

        $real = $this->resolve($dir, $file);
        if ($real === null) {
            return ToolResult::error("Log file not found: $file. Call read_logs without a file to list them.");
        }

        $search = self::str($input, 'search');
        $limit = self::int($input, 'lines', self::DEFAULT_LINES, 1, self::MAX_LINES);
        [$lines, $complete] = $this->tail($real, $limit, $search);

        if (!$lines) {
            return new ToolResult($search !== ''
                ? "(no lines containing \"$search\"" . ($complete ? ')' : ' in the last ' . (self::MAX_SCAN_BYTES >> 20) . ' MB)')
                : '(empty file)');
        }

        $lines = (new SecretRedactor())->redactLines($lines);
        $out = [];
        $bytes = 0;
        // Newest lines matter most: fill the budget from the end, then restore order.
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = $lines[$i];
            if (mb_strlen($line) > self::MAX_LINE_CHARS) {
                $line = mb_substr($line, 0, self::MAX_LINE_CHARS) . ' … [line truncated]';
            }
            $bytes += strlen($line) + 1;
            if ($bytes > self::MAX_OUTPUT_BYTES) {
                break;
            }
            $out[] = $line;
        }
        $out = array_reverse($out);

        $header = sprintf(
            '%s: last %d %s (%s, modified %s)',
            basename($real),
            count($out),
            $search !== '' ? "lines containing \"$search\"" : 'lines',
            self::size((int)filesize($real)),
            date('Y-m-d H:i:s T', (int)filemtime($real)),
        );

        return new ToolResult(mb_convert_encoding($header . "\n" . implode("\n", $out), 'UTF-8', 'UTF-8'));
    }

    private function listFiles(string $dir, string $pattern): ToolResult
    {
        $files = [];
        foreach (new \DirectoryIterator($dir) as $info) {
            if (!$info->isFile() || $info->isLink() || !self::isLogName($info->getFilename())) {
                continue;
            }
            if ($pattern !== '' && !fnmatch($pattern, $info->getFilename())) {
                continue;
            }
            $files[] = ['name' => $info->getFilename(), 'mtime' => $info->getMTime(), 'size' => $info->getSize()];
        }
        if (!$files) {
            return new ToolResult('(no log files)');
        }
        usort($files, fn($a, $b) => $b['mtime'] <=> $a['mtime'] ?: strcmp($a['name'], $b['name']));
        $total = count($files);
        $files = array_slice($files, 0, self::MAX_LISTED);

        $rows = array_map(fn($f) => sprintf('%-40s %10s  %s', $f['name'], self::size($f['size']), date('Y-m-d H:i', $f['mtime'])), $files);
        $more = $total > self::MAX_LISTED ? "\n... " . ($total - self::MAX_LISTED) . ' older files; use pattern to narrow.' : '';

        return new ToolResult('Log files (newest first), times in ' . date('T') . ":\n" . implode("\n", $rows) . $more);
    }

    /** Only plain log files directly in the log directory: no paths, no symlinks. */
    private function resolve(string $dir, string $file): ?string
    {
        $name = ltrim(str_replace('\\', '/', $file), '/');
        if (str_starts_with($name, 'storage/logs/')) {
            $name = substr($name, strlen('storage/logs/'));
        }
        if (str_contains($name, '/') || !self::isLogName($name)) {
            return null;
        }
        $path = $dir . '/' . $name;
        if (is_link($path) || !is_file($path)) {
            return null;
        }
        $real = realpath($path);

        return $real !== false && dirname($real) === $dir ? $real : null;
    }

    private static function isLogName(string $name): bool
    {
        return (bool)preg_match('/^[\w.\-]+\.log(\.\d+)?$/', $name);
    }

    /**
     * Last $limit lines (matching $search if given), oldest first, reading the
     * file backwards in chunks.
     *
     * @return array{0: string[], 1: bool} lines, and whether the whole file was scanned
     */
    private function tail(string $path, int $limit, string $search): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [[], true];
        }
        $pos = (int)filesize($path);
        $scanned = 0;
        $carry = '';
        $lines = [];
        $chunk = 65536;
        $needle = mb_strtolower($search);

        while ($pos > 0 && count($lines) < $limit && $scanned < self::MAX_SCAN_BYTES) {
            $read = min($chunk, $pos);
            $pos -= $read;
            fseek($handle, $pos);
            $buffer = (string)fread($handle, $read) . $carry;
            $scanned += $read;
            $parts = explode("\n", $buffer);
            // The first part may be the end of a line that started earlier.
            $carry = $pos > 0 ? (string)array_shift($parts) : '';
            for ($i = count($parts) - 1; $i >= 0 && count($lines) < $limit; $i--) {
                $line = rtrim($parts[$i], "\r");
                if ($line === '' || ($needle !== '' && !str_contains(mb_strtolower($line), $needle))) {
                    continue;
                }
                $lines[] = $line;
            }
        }
        fclose($handle);

        return [array_reverse($lines), $pos === 0];
    }

    private static function size(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : ($bytes >= 1024 ? round($bytes / 1024) . ' KB' : $bytes . ' B');
    }
}

<?php

namespace cossette\projectassistant\tools;

use Craft;
use craft\helpers\App;
use cossette\projectassistant\Plugin;
use yii\db\Connection;

/**
 * Read-only SQL on a dedicated connection.
 *
 * The real protection is the database user: give it SELECT on the listed
 * tables/views and nothing else. This class adds checks on top of that:
 * a single SELECT-type statement, the table allowlist, a read-only
 * transaction that is always rolled back, a timeout and a row cap.
 */
class RunSqlTool extends BaseTool
{
    private const ALLOWED_STARTS = ['select', 'with', 'show', 'describe', 'desc', 'explain'];
    private const FORBIDDEN = '/\b(insert|update|delete|replace|merge|drop|alter|create|truncate|grant|revoke|rename|call|handler|lock|unlock|set|load_file|sleep|benchmark|outfile|dumpfile|pg_sleep|pg_read_file|copy)\b/i';

    private ?Connection $connection = null;

    public function getName(): string
    {
        return 'run_sql';
    }

    public function getDescription(): string
    {
        $config = Plugin::getInstance()->getSettings()->sql;
        $tables = $config['tables'] ?? [];
        $list = [];
        foreach ($tables as $key => $value) {
            $list[] = is_int($key) ? "- $value" : "- $key: $value";
        }

        return 'Run one read-only SQL query (SELECT, WITH, SHOW, DESCRIBE, EXPLAIN) on the project database and get rows back as JSON. '
            . 'Use it for counts, aggregates and lookups that query_entries cannot express. '
            . 'Results are capped at ' . $this->maxRows() . ' rows. '
            . ($list ? "Only these tables/views are available:\n" . implode("\n", $list) : 'Use SHOW TABLES to see what is available.');
    }

    public function getInputSchema(): array
    {
        return self::schema([
            'query' => ['type' => 'string', 'description' => 'A single read-only SQL statement.'],
        ], ['query']);
    }

    public function getLabel(array $input): string
    {
        return Craft::t(Plugin::HANDLE, 'Querying the database');
    }

    public function execute(array $input, ToolContext $context): ToolResult
    {
        $sql = trim(self::str($input, 'query'));
        $sql = rtrim($sql, "; \n\r\t");

        if (($error = $this->validate($sql)) !== null) {
            return ToolResult::error($error);
        }

        try {
            $db = $this->getConnection();
            $isMysql = $db->getDriverName() === 'mysql';
            $timeoutMs = (int)(($this->config()['timeoutSeconds'] ?? 10) * 1000);
            if ($isMysql) {
                $db->createCommand("SET SESSION max_execution_time = $timeoutMs")->execute();
                $db->createCommand('SET SESSION TRANSACTION READ ONLY')->execute();
            } else {
                $db->createCommand("SET statement_timeout = $timeoutMs")->execute();
            }
            $transaction = $db->beginTransaction();
            if (!$isMysql) {
                $db->createCommand('SET TRANSACTION READ ONLY')->execute();
            }

            try {
                $reader = $db->createCommand($sql)->query();
                $rows = [];
                $truncated = false;
                while (($row = $reader->read()) !== false) {
                    if (count($rows) >= $this->maxRows()) {
                        $truncated = true;
                        break;
                    }
                    $rows[] = array_map(fn($v) => is_string($v) && mb_strlen($v) > 300 ? mb_substr($v, 0, 300) . '…' : $v, $row);
                }
                $reader->close();
            } finally {
                $transaction->rollBack();
            }
        } catch (\Throwable $e) {
            return ToolResult::error('Query failed: ' . $e->getMessage());
        }

        return ToolResult::json([
            'rowCount' => count($rows),
            'truncated' => $truncated,
            'rows' => $rows,
        ]);
    }

    /**
     * @return string|null error message, or null when the statement passes the checks
     */
    public function validate(string $sql): ?string
    {
        if ($sql === '') {
            return 'query is required.';
        }
        if (str_contains($sql, ';')) {
            return 'Only one statement is allowed (no semicolons).';
        }
        $withoutStrings = preg_replace("/'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\"/s", "''", $sql) ?? $sql;
        if (preg_match('/--|#|\/\*/', $withoutStrings)) {
            return 'Comments are not allowed.';
        }
        $first = strtolower((string)strtok(ltrim($withoutStrings, '( '), " \n\t("));
        if (!in_array($first, self::ALLOWED_STARTS, true)) {
            return 'Only SELECT, WITH, SHOW, DESCRIBE and EXPLAIN statements are allowed.';
        }
        if (preg_match(self::FORBIDDEN, $withoutStrings, $m)) {
            return "The keyword “{$m[1]}” is not allowed in read-only queries.";
        }
        if (preg_match('/\bfor\s+(update|share)\b|\block\s+in\s+share\s+mode\b/i', $withoutStrings)) {
            return 'Locking reads are not allowed.';
        }

        $allowed = $this->allowedTables();
        if ($allowed !== null) {
            preg_match_all('/\b(?:from|join|describe|desc|table|columns\s+from)\s+((?:`?[\w$]+`?\.)?`?[\w$]+`?(?:\s*,\s*(?:`?[\w$]+`?\.)?`?[\w$]+`?)*)/i', $withoutStrings, $matches);
            $ctes = [];
            preg_match_all('/(?:\bwith|,)\s*`?(\w+)`?\s+as\s*\(/i', $withoutStrings, $cteMatches);
            foreach ($cteMatches[1] as $cte) {
                $ctes[strtolower($cte)] = true;
            }
            foreach ($matches[1] as $list) {
                foreach (preg_split('/\s*,\s*/', $list) as $table) {
                    $name = strtolower(str_replace('`', '', $table));
                    $name = str_contains($name, '.') ? substr($name, strrpos($name, '.') + 1) : $name;
                    if ($name === '' || isset($ctes[$name]) || $name === 'dual') {
                        continue;
                    }
                    if (!isset($allowed[$name])) {
                        return "Table “{$name}” is not available. Available: " . implode(', ', array_keys($allowed));
                    }
                }
            }
        }

        return null;
    }

    /** @return array<string, true>|null lowercase table names, or null when no allowlist is set */
    private function allowedTables(): ?array
    {
        $tables = $this->config()['tables'] ?? [];
        if (!$tables) {
            return null;
        }
        $names = [];
        foreach ($tables as $key => $value) {
            $names[strtolower(is_int($key) ? (string)$value : (string)$key)] = true;
        }

        return $names;
    }

    private function getConnection(): Connection
    {
        if ($this->connection === null) {
            $config = $this->config();
            $this->connection = new Connection([
                'dsn' => App::parseEnv($config['dsn'] ?? ''),
                'username' => App::parseEnv($config['username'] ?? ''),
                'password' => App::parseEnv($config['password'] ?? ''),
                'charset' => 'utf8mb4',
                'attributes' => [\PDO::ATTR_TIMEOUT => 5],
            ]);
            $this->connection->open();
        }

        return $this->connection;
    }

    private function maxRows(): int
    {
        return max(1, min(1000, (int)($this->config()['maxRows'] ?? 200)));
    }

    private function config(): array
    {
        return Plugin::getInstance()->getSettings()->sql;
    }
}

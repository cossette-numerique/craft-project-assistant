<?php

namespace cossette\projectassistant\tools;

/**
 * Masks secrets in text the model is about to read (log lines): the values of
 * secret-looking environment variables, plus common credential patterns
 * (bearer tokens, passwords and keys in query strings, JSON or PHP arrays,
 * credentials in URLs). In the variable dumps Craft writes to its logs, every
 * value under $_COOKIE is masked, and so is any entry whose name looks secret
 * (session, identity, cookie, CSRF, webhook...).
 */
class SecretRedactor
{
    private const MASK = '[redacted]';
    private const SECRET_NAME = '/(KEY|SECRET|PASSWORD|PASSWD|PASS|TOKEN|DSN|AUTH|CREDENTIAL|SALT|PRIVATE|WEBHOOK|SIGNATURE|COOKIE|SESSION)/i';
    /** Names of dumped entries ('name' => 'value') whose value is always masked. */
    private const SECRET_ENTRY = '/(key|secret|passw|pass$|token|dsn|auth|credential|salt|private|webhook|signature|cookie|session|identity|csrf)/i';
    private const MIN_LENGTH = 6;

    /** @var string[] longest first, so a value containing another is masked whole */
    private array $values = [];

    public function __construct()
    {
        $env = array_merge(getenv() ?: [], $_ENV, $_SERVER);
        foreach ($env as $name => $value) {
            if (!is_string($name) || !is_string($value) || strlen($value) < self::MIN_LENGTH) {
                continue;
            }
            if (preg_match(self::SECRET_NAME, $name)) {
                $this->values[$value] = true;
            }
        }
        $this->values = array_keys($this->values);
        usort($this->values, fn($a, $b) => strlen($b) <=> strlen($a));
    }

    /**
     * Redacts consecutive log lines in order, so values inside a dumped
     * $_COOKIE = [ ... ] block are masked whatever their names.
     *
     * @param string[] $lines
     * @return string[]
     */
    public function redactLines(array $lines): array
    {
        // A window that starts inside the $_COOKIE block: Craft dumps $_SESSION right after it.
        $inCookies = false;
        foreach ($lines as $line) {
            if (preg_match('/^\$_\w+ = \[$/', $line)) {
                $inCookies = str_starts_with($line, '$_SESSION');
                break;
            }
        }
        foreach ($lines as $i => $line) {
            if (preg_match('/^\$_COOKIE = \[$/', $line)) {
                $inCookies = true;
            } elseif ($inCookies && preg_match('/^\]/', $line)) {
                $inCookies = false;
            } elseif (preg_match('/^(\s*\'((?:\\\\.|[^\'])*)\'\s*=>\s*)\'.*\'(,?)$/', $line, $m)
                && ($inCookies || preg_match(self::SECRET_ENTRY, $m[2]))) {
                $lines[$i] = $m[1] . "'" . self::MASK . "'" . $m[3];
                continue;
            }
            $lines[$i] = $this->redact($line);
        }

        return $lines;
    }

    public function redact(string $text): string
    {
        if ($this->values) {
            $text = str_replace($this->values, self::MASK, $text);
        }

        $key = '[\w.\-]*(?:password|passwd|secret|token|api[_-]?key|apikey|authorization|auth[_-]?key|private[_-]?key|client[_-]?secret)[\w.\-]*';

        return (string)preg_replace(
            [
                // Authorization: Bearer xxx / Basic xxx
                '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=\-]{8,}/i',
                // "password": "x", 'password' => 'x'
                '/((["\'])' . $key . '\2\s*(?::|=>)\s*)(["\'])(?:\\\\.|(?!\3).)*\3/i',
                // password=x in query strings and key=value lists
                '/(\b' . $key . '=)[^\s&"\',;]+/i',
                // Anthropic / OpenAI style keys, wherever they appear
                '/\bsk-[A-Za-z0-9_\-]{16,}/',
                // https://user:password@host
                '#\b([a-z][a-z0-9+.\-]*://)[^/\s:@\'"]+:[^/\s@\'"]+@#i',
            ],
            [
                '$1 ' . self::MASK,
                '$1$3' . self::MASK . '$3',
                '$1' . self::MASK,
                self::MASK,
                '$1' . self::MASK . '@',
            ],
            $text,
        );
    }
}

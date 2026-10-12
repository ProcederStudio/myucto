<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MyInvoice;

use Generator;
use RuntimeException;

/** Literal-only MariaDB dump reader. No statement from an archive is executed. */
final class MyInvoiceSqlDumpReader
{
    public const MAX_SQL_BYTES = 536_870_912;
    private const MAX_STATEMENT_BYTES = 33_554_432;

    /** @param resource $stream @return Generator<array{table:string,row:array}> */
    public function rows($stream, array $wanted): Generator
    {
        $columns = [];
        foreach ($this->statements($stream) as $sql) {
            if (preg_match('/\ACREATE TABLE(?: IF NOT EXISTS)? `([a-z][a-z0-9_]*)`\s*\(/i', $sql, $match)) {
                $table = $match[1];
                if (!isset($wanted[$table])) {
                    continue;
                }
                if (isset($columns[$table])) {
                    throw new RuntimeException('Duplicitní definice tabulky v SQL záloze.');
                }
                preg_match_all('/^\s*`([a-z][a-z0-9_]*)`\s+/m', $sql, $fields);
                if ($fields[1] === [] || count(array_unique($fields[1])) !== count($fields[1])) {
                    throw new RuntimeException('Neplatná definice sloupců SQL zálohy.');
                }
                $columns[$table] = $fields[1];
                continue;
            }
            if (!preg_match('/\AINSERT INTO `([a-z][a-z0-9_]*)`(?:\s*\(([^)]+)\))?\s+VALUES\s*/i', $sql, $match)) {
                if (preg_match('/\A(?:INSERT|REPLACE|UPDATE|DELETE|LOAD)\b/i', $sql)) {
                    throw new RuntimeException('SQL záloha obsahuje nepodporovaný zápis; očekávány jsou INSERT INTO … VALUES s literály.');
                }
                continue;
            }
            $table = $match[1];
            if (!isset($wanted[$table])) {
                continue;
            }
            $fields = $columns[$table] ?? throw new RuntimeException('SQL záloha nemá definici podporované tabulky.');
            if (($match[2] ?? '') !== '') {
                if (!preg_match('/\A\s*`[a-z][a-z0-9_]*`(?:\s*,\s*`[a-z][a-z0-9_]*`)*\s*\z/i', $match[2])) {
                    throw new RuntimeException('Neplatný seznam sloupců INSERT.');
                }
                preg_match_all('/`([^`]+)`/', $match[2], $names);
                $fields = $names[1];
                if (array_diff($fields, $columns[$table]) !== [] || count(array_unique($fields)) !== count($fields)) {
                    throw new RuntimeException('INSERT neodpovídá definici tabulky.');
                }
            }
            $pos = strlen($match[0]);
            $length = strlen($sql);
            do {
                $this->space($sql, $pos);
                if (($sql[$pos++] ?? '') !== '(') {
                    throw new RuntimeException('Neplatný řádek INSERT v SQL záloze.');
                }
                $values = [];
                do {
                    $this->space($sql, $pos);
                    $values[] = $this->literal($sql, $pos);
                    $this->space($sql, $pos);
                    $separator = $sql[$pos++] ?? '';
                } while ($separator === ',');
                if ($separator !== ')' || count($values) !== count($fields)) {
                    throw new RuntimeException('Počet nebo struktura hodnot INSERT nesouhlasí.');
                }
                yield ['table' => $table, 'row' => array_combine($fields, $values)];
                $this->space($sql, $pos);
                if ($pos === $length) {
                    break;
                }
                if (($sql[$pos++] ?? '') !== ',') {
                    throw new RuntimeException('Nepodporovaný závěr INSERT v SQL záloze.');
                }
            } while ($pos < $length);
            if ($pos > $length || ($pos === $length && ($sql[$length - 1] ?? '') === ',')) {
                throw new RuntimeException('Neúplný INSERT v SQL záloze.');
            }
        }
    }

    /** @param resource $stream @return Generator<string> */
    private function statements($stream): Generator
    {
        $buffer = '';
        $quote = null;
        $escaped = false;
        $block = false;
        $delimiter = ';';
        $bytes = 0;
        while (($line = fgets($stream, self::MAX_STATEMENT_BYTES + 1)) !== false) {
            $bytes += strlen($line);
            if ($bytes > self::MAX_SQL_BYTES) {
                throw new RuntimeException('SQL záloha překračuje limit 512 MiB.');
            }
            if ($quote === null && !$block && trim($buffer) === ''
                && preg_match('/\ADELIMITER\s+(\S+)\s*\z/i', trim($line), $match)) {
                if (!in_array($match[1], [';', ';;', '$$', '//'], true)) {
                    throw new RuntimeException('Nepodporovaný oddělovač SQL zálohy.');
                }
                $delimiter = $match[1];
                continue;
            }
            $length = strlen($line);
            for ($i = 0; $i < $length; $i++) {
                $c = $line[$i];
                $next = $line[$i + 1] ?? '';
                if ($block) {
                    if ($c === '*' && $next === '/') {
                        $block = false;
                        $buffer .= ' ';
                        $i++;
                    }
                    continue;
                }
                if ($quote !== null) {
                    $buffer .= $c;
                    if ($escaped) {
                        $escaped = false;
                    } elseif ($c === '\\' && $quote !== '`') {
                        $escaped = true;
                    } elseif ($c === $quote) {
                        if ($next === $quote) {
                            $buffer .= $next;
                            $i++;
                        } else {
                            $quote = null;
                        }
                    }
                } elseif ($c === '/' && $next === '*') {
                    $block = true;
                    $i++;
                } elseif ($c === '#' || ($c === '-' && $next === '-' && ctype_space($line[$i + 2] ?? ' '))) {
                    $buffer .= "\n";
                    break;
                } elseif (in_array($c, ["'", '"', '`'], true)) {
                    $quote = $c;
                    $buffer .= $c;
                } elseif (substr($line, $i, strlen($delimiter)) === $delimiter) {
                    $sql = trim($buffer);
                    $buffer = '';
                    $i += strlen($delimiter) - 1;
                    if ($sql !== '') {
                        yield $sql;
                    }
                } else {
                    $buffer .= $c;
                }
                if (strlen($buffer) > self::MAX_STATEMENT_BYTES) {
                    throw new RuntimeException('Příkaz SQL zálohy překračuje limit 32 MiB.');
                }
            }
        }
        if (!feof($stream) || $quote !== null || $block || trim($buffer) !== '') {
            throw new RuntimeException('SQL záloha je neúplná nebo ji nelze přečíst.');
        }
    }

    private function literal(string $sql, int &$pos): ?string
    {
        if (strncasecmp(substr($sql, $pos, 7), '_binary', 7) === 0) {
            $pos += 7;
            $this->space($sql, $pos);
            if (($sql[$pos] ?? '') !== "'") {
                throw new RuntimeException('Neplatný binární literál.');
            }
        }
        if (($sql[$pos] ?? '') === "'") {
            $pos++;
            $value = '';
            $length = strlen($sql);
            while ($pos < $length) {
                $c = $sql[$pos++];
                if ($c === "'") {
                    if (($sql[$pos] ?? '') === "'") {
                        $value .= "'";
                        $pos++;
                        continue;
                    }
                    return $value;
                }
                if ($c === '\\') {
                    if ($pos >= $length) {
                        throw new RuntimeException('Neúplný escape literálu.');
                    }
                    $c = substr($sql, $pos++, 1);
                    $value .= match ($c) {
                        '0' => "\0", 'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'Z' => "\x1a",
                        default => $c,
                    };
                } else {
                    $value .= $c;
                }
            }
            throw new RuntimeException('Neúplný řetězec SQL zálohy.');
        }
        $start = $pos;
        while (isset($sql[$pos]) && !str_contains(',)', $sql[$pos])) {
            $pos++;
        }
        $value = trim(substr($sql, $start, $pos - $start));
        if (strcasecmp($value, 'NULL') === 0) {
            return null;
        }
        if (preg_match('/\A0x([a-f0-9]*)\z/i', $value, $match) && strlen($match[1]) % 2 === 0) {
            return hex2bin($match[1]);
        }
        if (preg_match('/\A-?\d+(?:\.\d+)?\z/D', $value)) {
            return $value;
        }
        throw new RuntimeException('SQL záloha obsahuje výraz místo podporovaného literálu.');
    }

    private function space(string $sql, int &$pos): void
    {
        while (isset($sql[$pos]) && ctype_space($sql[$pos])) {
            $pos++;
        }
    }
}

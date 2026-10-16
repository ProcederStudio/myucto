<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Párování pohybu podle VS hledá doklad přes indexované sloupce `*_match_key`
 * (migrace 2010), ne výrazem nad REGEXP_REPLACE. Ten index nepoužije a každý pohyb
 * by procházel všechny doklady firmy; u 30 000 faktur je to kolem 25 ms na pohyb.
 * REGEXP_REPLACE smí zůstat jen ve fallbacku `vsKeyMatches()` pro schéma bez klíčů.
 */
final class BankVsIndexedMatchGuardTest extends TestCase
{
    public function testStatementMatcherFindsDocumentsByIndexedKey(): void
    {
        $code = self::withoutComments((string) file_get_contents(dirname(__DIR__, 2) . '/src/Service/Bank/StatementMatcher.php'));
        $fallback = strpos($code, 'function vsKeyMatches(');
        self::assertNotFalse($fallback);
        $fallbackEnd = strpos($code, 'function hasVsMatchKeys(', $fallback);
        self::assertNotFalse($fallbackEnd);
        $outside = substr($code, 0, $fallback) . substr($code, $fallbackEnd);

        self::assertStringNotContainsString('REGEXP_REPLACE', $outside, 'Doklad podle VS hledej přes vsCandidates() a indexovaný klíč.');
        self::assertStringContainsString('_match_key = ?', $code);
    }

    private static function withoutComments(string $raw): string
    {
        $out = '';
        foreach (token_get_all($raw) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    $out .= str_repeat("\n", substr_count($token[1], "\n"));
                    continue;
                }
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }
        return $out;
    }
}

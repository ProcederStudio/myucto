<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Název akce auditní stopy se musí vejít do sloupce `activity_log.action`.
 *
 * Delší název nespadne při psaní kódu, ale až za běhu na
 * `1406 Data too long for column 'action'`, a protože se audit zapisuje uvnitř
 * requestu, skončí celý request chybou 500 (import strukturované přijaté faktury
 * s akcí `purchase_invoice.structured_import_without_buyer_ic` na instancích,
 * kde sloupec měl ještě 50 znaků).
 *
 * Kontrolují se literály, které do loggeru prokazatelně tečou: argumenty volání
 * `->log(` / `$this->log(` (včetně ternárního výběru), přiřazení do `$action` /
 * `$event` a konstanty tříd `*_EVENT` / `ACTION*`. Skládané názvy (konkatenace)
 * test nevidí.
 */
final class ActivityLogActionLengthTest extends TestCase
{
    private const ACTION_SHAPE = '/^[a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+$/';

    public function testActionLiteralsFitColumn(): void
    {
        $max = self::actionColumnLength();
        $actions = self::collectActions(dirname(__DIR__, 2) . '/src');

        // Pojistka proti falešně zelenému testu: sběr musí reálně najít akce.
        self::assertGreaterThan(300, count($actions), 'Sběr názvů akcí nic nenašel — rozbitý parser testu?');
        self::assertArrayHasKey('client.created', $actions);
        self::assertArrayHasKey('purchase_invoice.structured_imported', $actions);

        $tooLong = [];
        foreach ($actions as $action => $where) {
            if (strlen($action) > $max) {
                $tooLong[] = sprintf('%s (%d znaků, %s)', $action, strlen($action), $where);
            }
        }

        self::assertSame([], $tooLong, "Název akce je delší než activity_log.action ({$max}): " . implode(', ', $tooLong));
    }

    public function testCollectorFindsTooLongActionInEveryForm(): void
    {
        $long = 'module.' . str_repeat('x', 120);
        $code = <<<PHP
            <?php
            final class Sample {
                private const DONE_EVENT = 'sample.const_event';
                public function run(\$logger, bool \$f): void {
                    \$logger->log('{$long}', 1, 'entity', 2, ['key' => 'payload.not_action']);
                    \$this->log(\$request, 'sample.wrapper_action', 3);
                    \$logger->log(\$f ? 'sample.ternary_a' : 'sample.ternary_b', null);
                    \$action = \$f ? 'sample.assigned' : 'sample.assigned_other';
                }
            }
            PHP;

        $found = self::actionsInCode($code);

        self::assertContains($long, $found);
        self::assertContains('sample.const_event', $found);
        self::assertContains('sample.wrapper_action', $found);
        self::assertContains('sample.ternary_a', $found);
        self::assertContains('sample.ternary_b', $found);
        self::assertContains('sample.assigned_other', $found);
        self::assertNotContains('payload.not_action', $found);
    }

    private static function actionColumnLength(): int
    {
        $snapshot = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/db/schema.snapshot.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $definition = (string) ($snapshot['tables']['activity_log']['columns']['action'] ?? '');
        self::assertSame(1, preg_match('/^varchar\((\d+)\)/', $definition, $m), 'activity_log.action není varchar');
        return (int) $m[1];
    }

    /**
     * @return array<string,string> akce => první výskyt (soubor:řádek)
     */
    private static function collectActions(string $dir): array
    {
        $actions = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($dir) + 1);
            foreach (self::actionsInCode((string) file_get_contents($file->getPathname()), true) as $action => $line) {
                $actions[$action] ??= "{$relative}:{$line}";
            }
        }
        return $actions;
    }

    /**
     * @return array<int|string,mixed> seznam akcí, s $withLines akce => řádek
     */
    private static function actionsInCode(string $code, bool $withLines = false): array
    {
        $tokens = array_values(array_filter(
            token_get_all($code),
            static fn ($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
        $found = [];
        $add = static function (array $token) use (&$found): void {
            $value = stripslashes(substr($token[1], 1, -1));
            if (preg_match(self::ACTION_SHAPE, $value) === 1) {
                $found[$value] ??= $token[2];
            }
        };
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];

            // ->log( / ::log( — literály na nejvyšší úrovni argumentů.
            if (is_array($t) && $t[0] === T_STRING && strtolower($t[1]) === 'log'
                && isset($tokens[$i - 1], $tokens[$i + 1]) && $tokens[$i + 1] === '('
                && is_array($tokens[$i - 1])
                && in_array($tokens[$i - 1][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)
            ) {
                $depth = 0;
                for ($j = $i + 1; $j < $count; $j++) {
                    $u = $tokens[$j];
                    if (in_array($u, ['(', '[', '{'], true) || (is_array($u) && in_array($u[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                        $depth++;
                    } elseif (in_array($u, [')', ']', '}'], true)) {
                        if (--$depth === 0) {
                            break;
                        }
                    } elseif ($depth === 1 && is_array($u) && $u[0] === T_CONSTANT_ENCAPSED_STRING) {
                        $add($u);
                    }
                }
                continue;
            }

            // $action = … ; / $event = … ; — literály v přiřazení (i ternár, match).
            if (is_array($t) && $t[0] === T_VARIABLE && in_array($t[1], ['$action', '$event'], true)
                && ($tokens[$i + 1] ?? null) === '='
            ) {
                for ($j = $i + 2; $j < $count && $tokens[$j] !== ';'; $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_CONSTANT_ENCAPSED_STRING) {
                        $add($tokens[$j]);
                    }
                }
                continue;
            }

            // const FOO_EVENT = '…'; / const ACTION_FOO = '…';
            if (is_array($t) && $t[0] === T_CONST) {
                for ($j = $i + 1; $j < $count && $tokens[$j] !== ';'; $j++) {
                    $name = $tokens[$j];
                    if (is_array($name) && $name[0] === T_STRING && ($tokens[$j + 1] ?? null) === '='
                        && (str_ends_with($name[1], '_EVENT') || str_starts_with($name[1], 'ACTION'))
                        && is_array($tokens[$j + 2] ?? null) && $tokens[$j + 2][0] === T_CONSTANT_ENCAPSED_STRING
                    ) {
                        $add($tokens[$j + 2]);
                    }
                }
            }
        }

        return $withLines ? $found : array_keys($found);
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Anonymization;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Service\Anonymization\AnonymizationOptions;
use MyInvoice\Service\Anonymization\AnonymizationService;
use MyInvoice\Service\Anonymization\DatabaseCloner;
use MyInvoice\Service\Tax\Nonprofit\NonprofitDppoStoredProof;
use MyInvoice\Service\Tax\Return\TaxReturnService;
use MyInvoice\Tests\Integration\Tax\NonprofitDppoNativeWorkflowTest;
use MyInvoice\Tests\Support\OwnedTestDatabaseCleanup;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Anonymizovaná kopie instalace s finálním archivem DPPO 504 vzniklým produkční cestou
 * (finalizace přiznání, XML, dvě kopie téhož XML v daňových podáních).
 */
final class NonprofitDppoFinancialArchiveCloneTest extends TestCase
{
    private const MARKER = 'archive-private@example.test';

    public function testCloneRewritesArchiveGraphWithoutIdentityAndKeepsProofsAndFinancialProjection(): void
    {
        $this->withOwnedSource(function (Config $config, string $source, string $target, PDO $server): void {
            $fixture = $this->finalArchive();
            $supplier = $fixture['supplier'];
            $sourceDigest = $this->digest($server, $source);
            $tokens = $this->identityTokens($server, $source, $supplier);

            (new AnonymizationService($config))->run(new AnonymizationOptions($source, $target, seed: 'synthetic-dppo-archive-clone'), static function (): void {});

            self::assertSame($sourceDigest, $this->digest($server, $source), 'Zdrojová databáze se při kopii změnila.');
            $snapshots = $this->snapshots($server, $target);
            $originals = $this->snapshots($server, $source);
            self::assertCount(1, $snapshots);
            foreach ($snapshots as $id => $row) {
                self::assertSame(hash('sha256', $row['source_manifest']), $row['source_sha256']);
                self::assertSame(hash('sha256', $row['xml_content']), $row['xml_sha256']);
                self::assertNotSame($originals[$id]['xml_sha256'], $row['xml_sha256']);
                $snapshot = json_decode($row['snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
                $proof = NonprofitDppoStoredProof::verify($snapshot, json_decode($row['source_manifest'], true, 512, JSON_THROW_ON_ERROR), $row['xml_content']);
                $original = NonprofitDppoStoredProof::verify(
                    json_decode($originals[$id]['snapshot_json'], true, 512, JSON_THROW_ON_ERROR),
                    json_decode($originals[$id]['source_manifest'], true, 512, JSON_THROW_ON_ERROR),
                    $originals[$id]['xml_content'],
                );
                self::assertSame($original['financial'], $proof['financial']);
                self::assertSame($original['period'], $proof['period']);
                self::assertSame($original['comparison_profile'], $proof['comparison_profile']);
                self::assertSame(
                    NonprofitDppoStoredProof::semanticProjection(json_decode($originals[$id]['snapshot_json'], true)['podklady']['nonprofit_calculation']),
                    NonprofitDppoStoredProof::semanticProjection($snapshot['podklady']['nonprofit_calculation']),
                );
                foreach ($tokens as $token) {
                    foreach (['snapshot_json', 'source_manifest', 'xml_content'] as $column) {
                        self::assertStringNotContainsString($token, $row[$column], "Identita {$token} zůstala v {$column}.");
                    }
                }
                $pdf = $this->attachment($row['xml_content']);
                self::assertStringStartsWith('%PDF', $pdf);
                self::assertNotSame($this->attachment($originals[$id]['xml_content']), $pdf);
            }

            $return = $server->query('SELECT final_snapshot_id, computed FROM ' . DatabaseCloner::quote($target) . '.income_tax_returns WHERE id = ' . $fixture['return_id'])->fetch(PDO::FETCH_ASSOC);
            $final = $snapshots[(int) $return['final_snapshot_id']];
            self::assertSame(
                json_decode($final['snapshot_json'], true, 512, JSON_THROW_ON_ERROR),
                json_decode($return['computed'], true, 512, JSON_THROW_ON_ERROR),
            );
            $copies = $server->query('SELECT id, xml_content, xml_sha256 FROM ' . DatabaseCloner::quote($target) . ".tax_submissions WHERE form_code = 'dppdp9' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
            self::assertCount(2, $copies);
            foreach ($copies as $copy) {
                self::assertSame($final['xml_content'], $copy['xml_content']);
                self::assertSame($final['xml_sha256'], $copy['xml_sha256']);
            }

            $cloned = $this->connection($config, $target);
            try {
                $container = Bootstrap::buildContainer();
                $container->set(Connection::class, $cloned);
                $read = $container->get(TaxReturnService::class)->getReturn($supplier, 2026, 'po', $fixture['actor']);
                self::assertSame('final', $read['return']['status']);
                $reopened = $container->get(TaxReturnService::class)->reopen($supplier, 2026, 'po', $read['return']['row_version'], $fixture['actor']);
                self::assertSame('draft', $reopened['return']['status'] ?? $reopened['status'] ?? null);
            } finally {
                $cloned->close();
            }
        });
    }

    /**
     * Finální 504 bez daňové úspory, uložené produkční finalizací, a dvě archivní kopie
     * téhož XML v daňových podáních.
     *
     * @return array{supplier:int, actor:int, return_id:int, snapshot_id:int}
     */
    private function finalArchive(): array
    {
        $workflow = new NonprofitDppoNativeWorkflowTest('testNativeFinalizationStoresActual504XmlAndOnlyActualTaxSaving');
        (new \ReflectionMethod($workflow, 'setUp'))->invoke($workflow);
        $db = $this->property($workflow, 'db');
        $container = $this->property($workflow, 'container');
        $supplier = $this->property($workflow, 'supplier');
        $period = $this->property($workflow, 'period');
        $actor = $this->property($workflow, 'actor');
        $db->pdo()->prepare('UPDATE supplier SET email = ? WHERE id = ?')->execute([self::MARKER, $supplier]);
        $notes = $container->get(\MyInvoice\Service\Accounting\Reports\StatementNotesService::class);
        foreach ($notes->build($supplier, $period)['sections'] as $section) {
            $notes->saveSection($supplier, 2026, $section['key'], 'Synthetic disclosure, contact ' . self::MARKER, $actor);
        }
        $service = $container->get(TaxReturnService::class);
        $read = $service->getReturn($supplier, 2026, 'po', $actor);
        $draft = $service->saveInputs($supplier, 2026, 'po', ['nonprofit' => []], $read['return']['row_version'], $actor, partial: true);
        $final = $service->finalize($supplier, 2026, 'po', $draft['return']['row_version'], $actor, expectedNonprofitFilingProofHash: $draft['nonprofit_filing_proof_hash']);
        $built = $service->buildXml($supplier, 2026, 'po');
        $submissions = new TaxSubmissionRepository($db);
        $submissions->archive($supplier, 'dppdp9', 2026, null, null, $built['xml'], [], 'passed', [], null);
        $submissions->archive($supplier, 'dppdp9', 2026, null, null, $built['xml'], [], 'passed', [], null);
        $returnId = (int) $db->pdo()->query("SELECT id FROM income_tax_returns WHERE supplier_id = {$supplier} AND year = 2026 AND taxpayer_type = 'po' AND status = 'final'")->fetchColumn();
        $snapshotId = (int) $db->pdo()->query('SELECT final_snapshot_id FROM income_tax_returns WHERE id = ' . $returnId)->fetchColumn();
        self::assertGreaterThan(0, $snapshotId);
        self::assertSame('final', $final['return']['status'] ?? null);
        $db->pdo()->commit();

        return ['supplier' => $supplier, 'actor' => $actor, 'return_id' => $returnId, 'snapshot_id' => $snapshotId];
    }

    /** @return list<string> */
    private function identityTokens(PDO $server, string $source, int $supplier): array
    {
        $row = $server->query('SELECT company_name, ic, dic, email, street, city FROM ' . DatabaseCloner::quote($source) . '.supplier WHERE id = ' . $supplier)->fetch(PDO::FETCH_ASSOC);
        $tokens = [self::MARKER];
        foreach ($row as $value) {
            if (is_string($value) && mb_strlen(trim($value)) >= 5) {
                $tokens[] = trim($value);
            }
        }

        return array_values(array_unique($tokens));
    }

    /** @return array<int, array<string,string>> */
    private function snapshots(PDO $server, string $schema): array
    {
        $rows = $server->query('SELECT id, snapshot_json, source_manifest, source_sha256, xml_content, xml_sha256 FROM ' . DatabaseCloner::quote($schema) . '.income_tax_return_snapshots ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

        return array_column($rows, null, 'id');
    }

    private function digest(PDO $server, string $schema): string
    {
        $parts = [];
        foreach (['income_tax_return_snapshots', 'income_tax_returns', 'tax_submissions', 'supplier', 'nonprofit_legal_profiles', 'nonprofit_tax_assessments'] as $table) {
            $parts[$table] = $server->query('CHECKSUM TABLE ' . DatabaseCloner::quote($schema) . '.' . DatabaseCloner::quote($table) . ' EXTENDED')->fetch(PDO::FETCH_ASSOC)['Checksum'] ?? null;
        }

        return json_encode($parts, JSON_THROW_ON_ERROR);
    }

    private function attachment(string $xml): string
    {
        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $node = (new \DOMXPath($dom))->query('//PredepsanaPriloha')->item(0);
        self::assertNotNull($node);

        return (string) base64_decode(trim($node->textContent), true);
    }

    private function connection(Config $config, string $database): Connection
    {
        $settings = $config->all();
        $settings['db']['name'] = $database;

        return Connection::withoutSharedTestConnection(fn () => new Connection(new Config($settings, $config->dataDir())));
    }

    private function property(object $object, string $name): mixed
    {
        return (new \ReflectionProperty($object, $name))->getValue($object);
    }

    private function withOwnedSource(callable $test): void
    {
        $base = Config::load(Bootstrap::rootDir());
        $previous = (string) getenv('MYINVOICE_DB_NAME');
        self::assertStringEndsWith('_test', $previous);
        self::assertContains($base->get('db.host'), ['localhost', '127.0.0.1', '::1']);
        $prefix = 'myucto_dppo_archive_' . bin2hex(random_bytes(5));
        $source = $prefix . '_source_test';
        $target = $prefix . '_target_test';
        $server = new PDO(
            'mysql:host=' . $base->get('db.host') . ';port=' . $base->get('db.port', 3306) . ';charset=utf8mb4',
            $base->get('db.user'),
            $base->get('db.pass', ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        try {
            $cloner = new DatabaseCloner($server);
            $plan = $cloner->plan($previous);
            $cloner->copyTables($plan, $source, static function (): void {});
            $cloner->finalize($plan, $source);
            $this->select($source);
            $test(Config::load(Bootstrap::rootDir()), $source, $target, $server);
        } finally {
            $this->select($previous);
            foreach ([$source, $target] as $name) {
                OwnedTestDatabaseCleanup::drop($server, $name, [$source, $target]);
            }
        }
    }

    private function select(string $database): void
    {
        putenv('MYINVOICE_DB_NAME=' . $database);
        $_ENV['MYINVOICE_DB_NAME'] = $database;
        $_SERVER['MYINVOICE_DB_NAME'] = $database;
    }
}

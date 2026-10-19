<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization;

use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use MyInvoice\Service\Anonymization\Streaming\CanonicalJsonProfile;
use MyInvoice\Service\Anonymization\Streaming\JsonPointerStream;
use MyInvoice\Service\Tax\Nonprofit\NonprofitDppoClassificationBridge;
use MyInvoice\Service\Tax\Nonprofit\NonprofitDppoStoredProof;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use PDO;

final class LegacyStoredProofStreamAdapter
{
    public function __construct(
        private readonly string $jsonPath,
        private readonly string $recordPointer = '/podklady/nonprofit_calculation',
    ) {
        if (!is_file($jsonPath) || $recordPointer === '' || $recordPointer[0] !== '/') {
            throw new \InvalidArgumentException('Legacy StoredProof stream vyžaduje soubor a absolutní pointer.');
        }
    }

    public function verify(): LegacyStoredProofStreamReceipt
    {
        $profile = $this->profile();
        $resultPointer = $this->recordPointer . '/data/nonprofit/assessment/result';
        $bridgePointer = $this->recordPointer . '/data/nonprofit/bridge';
        $lineCoveragePointer = $this->recordPointer . '/line_coverage';
        $dbPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'myucto-legacy-proof-' . bin2hex(random_bytes(16)) . '.sqlite';
        $db = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        try {
            $db->exec('PRAGMA journal_mode=OFF');
            $db->exec('PRAGMA synchronous=OFF');
            $db->exec('PRAGMA temp_store=FILE');
            $db->exec('PRAGMA cache_size=-256');
            $db->exec('PRAGMA mmap_size=0');
            $db->exec('CREATE TABLE documented (source_id TEXT COLLATE BINARY PRIMARY KEY, payload TEXT NOT NULL) WITHOUT ROWID');
            $db->exec('CREATE TABLE source_rows (source_id TEXT COLLATE BINARY PRIMARY KEY, ordinal INTEGER NOT NULL, taxable INTEGER NOT NULL) WITHOUT ROWID');
            $db->exec('CREATE TABLE bridge_rows (source_id TEXT COLLATE BINARY PRIMARY KEY, ordinal INTEGER NOT NULL, line INTEGER NULL, amount_cents INTEGER NOT NULL, matched INTEGER NOT NULL DEFAULT 0) WITHOUT ROWID');
            $db->beginTransaction();
            $this->captureDocumented($db);
            $this->captureBridgeRows($db, $bridgePointer);
            [$lineCents, $rowCount, $taxableCount] = $this->projectRows($db, $resultPointer);
            $db->exec('PRAGMA shrink_memory');
            self::releaseMemory();
            if ((int) $db->query('SELECT COUNT(*) FROM bridge_rows WHERE matched=0')->fetchColumn() !== 0
                || (int) $db->query('SELECT COUNT(*) FROM documented d LEFT JOIN source_rows s ON s.source_id=d.source_id WHERE s.source_id IS NULL')->fetchColumn() !== 0) {
                throw new \UnexpectedValueException('Legacy StoredProof bridge obsahuje osiřelý řádek.');
            }
            $this->assertResultAndBridge($resultPointer, $bridgePointer, $lineCents);
            [$rowsHash, $resultHash] = $this->hashResult($resultPointer);
            self::releaseMemory();
            $sourceLinesHash = $this->hashArray($bridgePointer . '/source_lines');
            self::releaseMemory();
            $taxableHash = $this->taxableHash($db);
            $j6ReceiptHash = null;
            if ($profile === 'v3') {
                $this->assertLineCoverage($db, $lineCoveragePointer, $rowCount, $rowsHash, $sourceLinesHash);
                $j6ReceiptHash = $this->assertJ6Receipt($resultHash);
            } else {
                $this->assertPointerMissing($lineCoveragePointer);
                $this->assertPointerMissing($this->recordPointer . '/data/j6_receipt');
            }

            return new LegacyStoredProofStreamReceipt(
                $profile,
                $rowCount,
                $lineCents,
                $rowsHash,
                $sourceLinesHash,
                $taxableCount,
                $taxableHash,
                $j6ReceiptHash,
            );
        } finally {
            if ($db->inTransaction()) $db->rollBack();
            $db = null;
            gc_collect_cycles();
            if (is_file($dbPath)) {
                for ($attempt = 0; $attempt < 5 && is_file($dbPath); ++$attempt) {
                    if (@unlink($dbPath)) break;
                    usleep(10_000);
                }
            }
        }
    }

    public function assertRebaseParity(LegacyStoredProofStreamReceipt $source): LegacyStoredProofStreamReceipt
    {
        $target = $this->verify();
        if ($source->profile !== $target->profile || $source->financialIdentity() !== $target->financialIdentity()) {
            throw new \UnexpectedValueException('Legacy StoredProof rebase změnil finanční nebo verzovací projekci.');
        }
        return $target;
    }

    private function profile(): string
    {
        $meta = $this->decodeSmall($this->recordPointer, ['/data', '/inputs', '/constants', '/entity_proof', '/form_manifest', '/line_coverage']);
        return NonprofitDppoStoredProof::comparisonProfile($meta);
    }

    private function captureDocumented(PDO $db): void
    {
        $insert = $db->prepare('INSERT INTO documented(source_id,payload) VALUES(?,?)');
        try {
            foreach ($this->items($this->recordPointer . '/inputs/nonprofit/documented_lines') as $sourceId => $documented) {
                if (!is_array($documented) || (!is_string($sourceId) && !is_int($sourceId))) {
                    throw new \UnexpectedValueException('Legacy StoredProof documented line není typovaný.');
                }
                $insert->execute([(string) $sourceId, json_encode($documented, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            }
        } catch (\JsonMachine\Exception\PathNotFoundException) {
        }
    }

    private function captureBridgeRows(PDO $db, string $pointer): void
    {
        $insert = $db->prepare('INSERT INTO bridge_rows(source_id,ordinal,line,amount_cents) VALUES(?,?,?,?)');
        $ordinal = 0;
        foreach ($this->items($pointer . '/source_lines') as $row) {
            if (!is_array($row) || !is_string($row['source_id'] ?? null) || !array_key_exists('line', $row)
                || ($row['line'] !== null && !is_int($row['line'])) || !is_int($row['amount_cents'] ?? null)) {
                throw new \UnexpectedValueException('Legacy StoredProof bridge row není typovaný.');
            }
            $insert->execute([$row['source_id'], $ordinal++, $row['line'], $row['amount_cents']]);
        }
    }

    /** @return array{array<int,int>,int,int} */
    private function projectRows(PDO $db, string $pointer): array
    {
        $bridge = new NonprofitDppoClassificationBridge();
        $findDocumented = $db->prepare('SELECT payload FROM documented WHERE source_id=?');
        $findBridge = $db->prepare('SELECT line,amount_cents FROM bridge_rows WHERE source_id=?');
        $markBridge = $db->prepare('UPDATE bridge_rows SET matched=1 WHERE source_id=? AND matched=0');
        $insertSource = $db->prepare('INSERT INTO source_rows(source_id,ordinal,taxable) VALUES(?,?,?)');
        $lines = array_fill_keys([10, 40, 100, 101, 109, 110, 120], '0');
        $excluded = '0';
        $costs = '0';
        $ordinal = 0;
        $taxable = 0;
        foreach ($this->items($pointer . '/rows') as $row) {
            if (!is_array($row)) throw new \UnexpectedValueException('Legacy StoredProof J6 row není objekt.');
            $sourceId = $row['id'] ?? null;
            if (!is_string($sourceId) || $sourceId === '') throw new \UnexpectedValueException('Legacy StoredProof J6 row nemá identitu.');
            $findDocumented->execute([$sourceId]);
            $documentedJson = $findDocumented->fetchColumn();
            $documented = is_string($documentedJson) ? json_decode($documentedJson, true, flags: JSON_THROW_ON_ERROR) : null;
            $projection = $bridge->projectRow($row, $documented);
            $findBridge->execute([$sourceId]);
            $stored = $findBridge->fetch(PDO::FETCH_ASSOC);
            if (!is_array($stored) || ($stored['line'] === null ? null : (int) $stored['line']) !== $projection['detail']['line']
                || (int) $stored['amount_cents'] !== $projection['detail']['amount_cents']) {
                throw new \UnexpectedValueException('Legacy StoredProof bridge row nesouhlasí se zdrojem.');
            }
            $markBridge->execute([$sourceId]);
            if ($markBridge->rowCount() !== 1) throw new \UnexpectedValueException('Legacy StoredProof bridge row je duplicitní.');
            $insertSource->execute([$sourceId, $ordinal++, $projection['taxable'] ? 1 : 0]);
            $lines[10] = bcadd($lines[10], $projection['line_10_delta'], 0);
            if ($projection['line'] !== null) $lines[$projection['line']] = bcadd($lines[$projection['line']], $projection['line_delta'], 0);
            $excluded = bcadd($excluded, $projection['excluded_delta'], 0);
            $costs = bcadd($costs, $projection['cost_delta'], 0);
            if ($projection['taxable']) ++$taxable;
        }
        foreach ($lines as &$amount) $amount = (int) $amount;
        unset($amount);
        ksort($lines, SORT_STRING);
        $result = $this->decodeSmall($pointer, ['/rows']);
        $bridge->assertTotals($result, (string) $lines[10], $excluded, $costs);
        return [$lines, $ordinal, $taxable];
    }

    private function assertResultAndBridge(string $resultPointer, string $bridgePointer, array $lineCents): void
    {
        $result = $this->decodeSmall($resultPointer, ['/rows']);
        if (($result['ruleset'] ?? null) === null || ($result['scope'] ?? null) !== 'classification_bridge_before_general_adjustments') {
            throw new \UnexpectedValueException('Legacy StoredProof result header není podporovaný.');
        }
        $bridge = $this->decodeSmall($bridgePointer, ['/source_lines']);
        if (($bridge['scope'] ?? null) !== 'classification_only' || ($bridge['general_adjustments_included'] ?? null) !== false
            || !is_array($bridge['line_cents'] ?? null)
            || NonprofitTaxRules::hash($bridge['line_cents']) !== NonprofitTaxRules::hash($lineCents)
            || !is_array($bridge['external_adjustments'] ?? null)) {
            throw new \UnexpectedValueException('Legacy StoredProof bridge projection nesouhlasí: ' . json_encode([
                'stored' => $bridge['line_cents'] ?? null,
                'computed' => $lineCents,
                'scope' => $bridge['scope'] ?? null,
                'general' => $bridge['general_adjustments_included'] ?? null,
                'external' => $bridge['external_adjustments'] ?? null,
            ], JSON_THROW_ON_ERROR));
        }
    }

    private function assertLineCoverage(PDO $db, string $pointer, int $rows, string $rowsHash, string $sourceLinesHash): void
    {
        $coverage = $this->decodeSmall($pointer, ['/source_ids']);
        if (($coverage['count'] ?? null) !== $rows || ($coverage['j6_rows_hash'] ?? null) !== $rowsHash
            || ($coverage['source_lines_hash'] ?? null) !== $sourceLinesHash) {
            throw new \UnexpectedValueException('Legacy StoredProof line coverage hash nesouhlasí.');
        }
        $expected = $db->query('SELECT source_id FROM source_rows ORDER BY source_id COLLATE BINARY');
        $count = 0;
        foreach ($this->items($pointer . '/source_ids') as $sourceId) {
            if (!is_string($sourceId) || $expected->fetchColumn() !== $sourceId) {
                throw new \UnexpectedValueException('Legacy StoredProof line coverage identity nesouhlasí.');
            }
            ++$count;
        }
        if ($count !== $rows || $expected->fetchColumn() !== false) throw new \UnexpectedValueException('Legacy StoredProof line coverage není úplné.');
    }

    private function assertJ6Receipt(string $resultHash): string
    {
        $receiptPointer = $this->recordPointer . '/data/j6_receipt';
        $receipt = $this->decodeSmall($receiptPointer);
        $period = $this->decodeSmall($this->recordPointer . '/data/period');
        NonprofitDppoStoredProof::assertStreamJ6Receipt($receipt, $period, $resultHash);
        return (string) $receipt['receipt_hash'];
    }

    private function taxableHash(PDO $db): string
    {
        $statement = $db->query('SELECT source_id FROM source_rows WHERE taxable=1 ORDER BY ordinal');
        $hash = hash_init('sha256');
        hash_update($hash, '[');
        $first = true;
        while (($sourceId = $statement->fetchColumn()) !== false) {
            hash_update($hash, ($first ? '' : ',') . json_encode($sourceId, CanonicalJsonProfile::NONPROFIT->flags()));
            $first = false;
        }
        hash_update($hash, ']');
        return hash_final($hash);
    }

    /** @return array{string,string} rows hash and complete result hash */
    private function hashResult(string $pointer): array
    {
        $header = $this->decodeSmall($pointer, ['/rows']);
        if (array_key_exists('rows', $header)) throw new \UnexpectedValueException('Legacy StoredProof result rows nelze oddělit.');
        $header['rows'] = null;
        ksort($header, SORT_STRING);
        $resultHash = hash_init('sha256');
        $rowsHash = hash_init('sha256');
        hash_update($resultHash, '{');
        $firstField = true;
        foreach ($header as $key => $value) {
            hash_update($resultHash, ($firstField ? '' : ',') . json_encode((string) $key, CanonicalJsonProfile::NONPROFIT->flags()) . ':');
            $firstField = false;
            if ($key !== 'rows') {
                hash_update($resultHash, self::canonicalValue($value));
                continue;
            }
            hash_update($resultHash, '[');
            hash_update($rowsHash, '[');
            $firstRow = true;
            foreach ($this->items($pointer . '/rows') as $row) {
                if (!is_array($row)) throw new \UnexpectedValueException('Legacy StoredProof J6 row není objekt.');
                $canonical = NonprofitTaxRules::canonicalJson($row);
                $separator = $firstRow ? '' : ',';
                hash_update($resultHash, $separator . $canonical);
                hash_update($rowsHash, $separator . $canonical);
                $firstRow = false;
            }
            hash_update($resultHash, ']');
            hash_update($rowsHash, ']');
        }
        hash_update($resultHash, '}');
        return [hash_final($rowsHash), hash_final($resultHash)];
    }

    private function hashArray(string $pointer): string
    {
        $hash = hash_init('sha256');
        hash_update($hash, '[');
        $first = true;
        foreach ($this->items($pointer) as $row) {
            if (!is_array($row)) throw new \UnexpectedValueException('Legacy StoredProof vektor neobsahuje objekt.');
            hash_update($hash, ($first ? '' : ',') . NonprofitTaxRules::canonicalJson($row));
            $first = false;
        }
        hash_update($hash, ']');
        return hash_final($hash);
    }

    /** @param list<string> $excluded */
    private function decodeSmall(string $pointer, array $excluded = []): array
    {
        $output = fopen('php://temp/maxmemory:2097152', 'w+b');
        if (!is_resource($output)) throw new \RuntimeException('Legacy StoredProof small projection spool nelze otevřít.');
        try {
            foreach ($this->pointer()->at($pointer, $excluded) as $chunk) self::writeAll($output, $chunk);
            rewind($output);
            $decoded = json_decode((string) stream_get_contents($output), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) throw new \UnexpectedValueException('Legacy StoredProof small projection není objekt.');
            return $decoded;
        } finally {
            fclose($output);
        }
    }

    private function assertPointerMissing(string $pointer): void
    {
        try {
            foreach ($this->items($pointer) as $_) throw new \UnexpectedValueException('Legacy StoredProof obsahuje zakázané v3 pole.');
        } catch (\JsonMachine\Exception\PathNotFoundException) {
        }
    }

    private function items(string $pointer): Items
    {
        return Items::fromFile($this->jsonPath, ['pointer' => $pointer, 'decoder' => new ExtJsonDecoder(true, 512, 0)]);
    }

    private function pointer(): JsonPointerStream
    {
        $path = $this->jsonPath;
        return new JsonPointerStream(static function () use ($path): iterable {
            $handle = fopen($path, 'rb');
            if (!is_resource($handle)) throw new \RuntimeException('Legacy StoredProof JSON nelze otevřít.');
            try {
                while (!feof($handle)) {
                    $chunk = fread($handle, 524288);
                    if (!is_string($chunk)) throw new \RuntimeException('Legacy StoredProof JSON nelze číst.');
                    if ($chunk !== '') yield $chunk;
                }
            } finally {
                fclose($handle);
            }
        });
    }

    private static function canonicalValue(mixed $value): string
    {
        return is_array($value)
            ? NonprofitTaxRules::canonicalJson($value)
            : json_encode($value, CanonicalJsonProfile::NONPROFIT->flags());
    }

    private static function releaseMemory(): void
    {
        gc_collect_cycles();
        gc_mem_caches();
    }

    private static function writeAll($handle, string $bytes): void
    {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = fwrite($handle, substr($bytes, $offset));
            if (!is_int($written) || $written < 1) throw new \RuntimeException('Legacy StoredProof projection nelze zapsat.');
            $offset += $written;
        }
    }
}

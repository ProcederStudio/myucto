<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecPackageCatalog;
use PDO;

final class JmhzSpecPackageRepository
{
    /** @var array<string, true> balík|hash, jehož uložené řádky už prošly {@see self::verifyStoredRows()} */
    private array $verified = [];

    public function __construct(private readonly Connection $db) {}

    public function isAvailable(): bool
    {
        return $this->db->hasTable('payroll_jmhz_spec_packages');
    }

    /** @param array{manifest_sha256:string,payload:array<string, mixed>} $manifest */
    public function install(array $manifest): int
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('Registr specifikace JMHZ není dostupný.');
        }
        JmhzSpecPackageCatalog::validateManifest($manifest);
        $payload = $manifest['payload'];
        $manifestHash = $manifest['manifest_sha256'];
        $actualHash = hash('sha256', CanonicalJson::encode($payload));
        if (!hash_equals($manifestHash, $actualHash)) {
            throw new \InvalidArgumentException('Balík specifikace JMHZ má neplatný hash.');
        }
        $packageKey = $this->requiredString($payload, 'package_key');
        $versions = $payload['versions'] ?? null;
        if (!is_array($versions)) {
            throw new \InvalidArgumentException('Balík specifikace JMHZ nemá verze zdrojů.');
        }

        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $existing = $pdo->prepare(
                'SELECT id, manifest_sha256
                   FROM payroll_jmhz_spec_packages
                  WHERE package_key = ? FOR UPDATE',
            );
            $existing->execute([$packageKey]);
            $row = $existing->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                if (!hash_equals((string) $row['manifest_sha256'], $manifestHash)) {
                    throw new \LogicException(
                        "Balík JMHZ {$packageKey} už existuje s jiným hashem.",
                    );
                }
                $packageId = (int) $row['id'];
                // Uložené řádky balíku stačí porovnat s manifestem jednou za spojení:
                // instalace se volá u každého schváleného pracovního měsíce.
                $verifiedKey = $packageId . '|' . $manifestHash;
                if (!isset($this->verified[$verifiedKey])) {
                    $this->verifyStoredRows($packageId, $payload);
                    $this->verified[$verifiedKey] = true;
                }
                if ($ownsTransaction) {
                    $pdo->commit();
                }

                return $packageId;
            }

            $insertPackage = $pdo->prepare(
                'INSERT INTO payroll_jmhz_spec_packages
                    (package_key, schema_version, xsd_version, dictionary_version,
                     control_catalog_version, process_version, instructions_version,
                     manifest_json, manifest_sha256)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            );
            $insertPackage->execute([
                $packageKey,
                $this->requiredString($payload, 'schema_version'),
                $this->requiredString($versions, 'xsd'),
                $this->requiredString($versions, 'dictionary'),
                $this->requiredString($versions, 'control_catalog'),
                $this->requiredString($versions, 'process'),
                $this->requiredString($versions, 'instructions'),
                CanonicalJson::encode($manifest),
                $manifestHash,
            ]);
            $packageId = (int) $pdo->lastInsertId();
            $this->insertCodebooks($packageId, $this->rows($payload, 'codebooks'));
            $this->insertAttributes($packageId, $this->rows($payload, 'dictionary_attributes'));
            $this->verifyStoredRows($packageId, $payload);

            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $packageId;
    }

    /** @return array{manifest_sha256:string,payload:array<string, mixed>}|null */
    public function find(string $packageKey, string $manifestHash): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, manifest_json, manifest_sha256
               FROM payroll_jmhz_spec_packages
              WHERE package_key = ? AND manifest_sha256 = ?',
        );
        $stmt->execute([$packageKey, $manifestHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $decoded = json_decode((string) $row['manifest_json'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !is_array($decoded['payload'] ?? null)
            || !is_string($decoded['manifest_sha256'] ?? null)
            || !hash_equals($manifestHash, $decoded['manifest_sha256'])
            || !hash_equals($manifestHash, hash('sha256', CanonicalJson::encode($decoded['payload'])))
        ) {
            throw new \UnexpectedValueException('Uložený balík specifikace JMHZ je poškozený.');
        }
        JmhzSpecPackageCatalog::validateManifest([
            'manifest_sha256' => $decoded['manifest_sha256'],
            'payload' => $decoded['payload'],
        ]);
        $this->verifyStoredRows((int) $row['id'], $decoded['payload']);

        return ['manifest_sha256' => $decoded['manifest_sha256'], 'payload' => $decoded['payload']];
    }

    /** @param array<string, mixed> $payload */
    private function verifyStoredRows(int $packageId, array $payload): void
    {
        $expectedAttributes = [];
        foreach ($this->rows($payload, 'dictionary_attributes') as $row) {
            $expectedAttributes[$this->requiredString($row, 'attribute_id')] = $row;
        }
        ksort($expectedAttributes, SORT_STRING);
        $actualAttributes = [];
        $stmt = $this->db->pdo()->prepare(
            'SELECT attribute_id, name, area, class_name, subclass_name, data_type,
                    data_type_refinement, cardinality, regzec_xsd_mapping, xsd_mapping,
                    codebook_key, employer_registration_marker,
                    employee_registration_marker, monthly_marker, row_hash
               FROM payroll_jmhz_dictionary_attributes
              WHERE package_id = ?',
        );
        $stmt->execute([$packageId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $record = [
                'attribute_id' => (string) $row['attribute_id'],
                'name' => (string) $row['name'],
                'area' => self::dbNullableString($row['area']),
                'class_name' => self::dbNullableString($row['class_name']),
                'subclass_name' => self::dbNullableString($row['subclass_name']),
                'data_type' => self::dbNullableString($row['data_type']),
                'data_type_refinement' => self::dbNullableString($row['data_type_refinement']),
                'cardinality' => self::dbNullableString($row['cardinality']),
                'regzec_xsd_mapping' => self::dbNullableString($row['regzec_xsd_mapping']),
                'xsd_mapping' => self::dbNullableString($row['xsd_mapping']),
                'codebook_key' => self::dbNullableString($row['codebook_key']),
                'employer_registration_marker' => self::dbNullableString(
                    $row['employer_registration_marker'],
                ),
                'employee_registration_marker' => self::dbNullableString(
                    $row['employee_registration_marker'],
                ),
                'monthly_marker' => self::dbNullableString($row['monthly_marker']),
            ];
            $rowHash = (string) $row['row_hash'];
            if (!hash_equals($rowHash, hash('sha256', CanonicalJson::encode($record)))) {
                throw new \UnexpectedValueException('Uložený atribut JMHZ má neplatný hash.');
            }
            $record['row_hash'] = $rowHash;
            $actualAttributes[$record['attribute_id']] = $record;
        }
        ksort($actualAttributes, SORT_STRING);
        if (CanonicalJson::encode(['rows' => $actualAttributes])
            !== CanonicalJson::encode(['rows' => $expectedAttributes])
        ) {
            throw new \UnexpectedValueException('Atributy uloženého balíku JMHZ neodpovídají manifestu.');
        }

        $expectedCodebooks = [];
        foreach ($this->rows($payload, 'codebooks') as $codebook) {
            $key = $this->requiredString($codebook, 'codebook_key');
            $expectedCodebooks[$key] = $codebook;
        }
        ksort($expectedCodebooks, SORT_STRING);

        $actualEntries = [];
        $stmt = $this->db->pdo()->prepare(
            'SELECT codebook.codebook_key, entry.item_code, entry.label, entry.parent_code,
                    entry.ordinal, entry.metadata_json, entry.row_hash
               FROM payroll_jmhz_codebook_entries entry
               JOIN payroll_jmhz_codebooks codebook
                 ON codebook.id = entry.codebook_id AND codebook.package_id = entry.package_id
              WHERE entry.package_id = ?
              ORDER BY codebook.codebook_key, entry.ordinal',
        );
        $stmt->execute([$packageId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $record = [
                'item_code' => (string) $row['item_code'],
                'label' => (string) $row['label'],
                'parent_code' => self::dbNullableString($row['parent_code']),
                'ordinal' => (int) $row['ordinal'],
                'metadata' => self::decodedObject($row['metadata_json']),
            ];
            $rowHash = (string) $row['row_hash'];
            if (!hash_equals($rowHash, hash('sha256', CanonicalJson::encode($record)))) {
                throw new \UnexpectedValueException('Uložená položka číselníku JMHZ má neplatný hash.');
            }
            $record['row_hash'] = $rowHash;
            $actualEntries[(string) $row['codebook_key']][] = $record;
        }

        $actualCodebooks = [];
        $stmt = $this->db->pdo()->prepare(
            'SELECT codebook_key, source_kind, source_name, source_url,
                    source_metadata_json, entry_count, content_hash
               FROM payroll_jmhz_codebooks
              WHERE package_id = ?',
        );
        $stmt->execute([$packageId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = (string) $row['codebook_key'];
            $entries = $actualEntries[$key] ?? [];
            $contentHash = (string) $row['content_hash'];
            if (!hash_equals(
                $contentHash,
                hash('sha256', CanonicalJson::encode(['entries' => $entries])),
            )) {
                throw new \UnexpectedValueException('Uložený číselník JMHZ má neplatný hash.');
            }
            $actualCodebooks[$key] = [
                'codebook_key' => $key,
                'source_kind' => (string) $row['source_kind'],
                'source_name' => (string) $row['source_name'],
                'source_url' => self::dbNullableString($row['source_url']),
                'source_metadata' => self::decodedObject($row['source_metadata_json']),
                'entry_count' => (int) $row['entry_count'],
                'content_hash' => $contentHash,
                'entries' => $entries,
            ];
        }
        ksort($actualCodebooks, SORT_STRING);
        if (CanonicalJson::encode(['rows' => $actualCodebooks])
            !== CanonicalJson::encode(['rows' => $expectedCodebooks])
        ) {
            throw new \UnexpectedValueException('Číselníky uloženého balíku JMHZ neodpovídají manifestu.');
        }
    }

    private static function dbNullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    /** @return array<string, mixed> */
    private static function decodedObject(mixed $value): array
    {
        if (!is_string($value)) {
            throw new \UnexpectedValueException('JSON uloženého balíku JMHZ není text.');
        }
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \UnexpectedValueException('JSON uloženého balíku JMHZ není objekt.');
        }

        return $decoded;
    }

    /** @param list<array<string, mixed>> $attributes */
    private function insertAttributes(int $packageId, array $attributes): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_jmhz_dictionary_attributes
                (package_id, attribute_id, name, area, class_name, subclass_name,
                 data_type, data_type_refinement, cardinality, regzec_xsd_mapping,
                 xsd_mapping, codebook_key, employer_registration_marker,
                 employee_registration_marker, monthly_marker, row_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        foreach ($attributes as $row) {
            $stmt->execute([
                $packageId,
                $this->requiredString($row, 'attribute_id'),
                $this->requiredString($row, 'name'),
                $this->nullableString($row, 'area'),
                $this->nullableString($row, 'class_name'),
                $this->nullableString($row, 'subclass_name'),
                $this->nullableString($row, 'data_type'),
                $this->nullableString($row, 'data_type_refinement'),
                $this->nullableString($row, 'cardinality'),
                $this->nullableString($row, 'regzec_xsd_mapping'),
                $this->nullableString($row, 'xsd_mapping'),
                $this->nullableString($row, 'codebook_key'),
                $this->nullableString($row, 'employer_registration_marker'),
                $this->nullableString($row, 'employee_registration_marker'),
                $this->nullableString($row, 'monthly_marker'),
                $this->requiredString($row, 'row_hash'),
            ]);
        }
    }

    /** @param list<array<string, mixed>> $codebooks */
    private function insertCodebooks(int $packageId, array $codebooks): void
    {
        $codebookStatement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_jmhz_codebooks
                (package_id, codebook_key, source_kind, source_name, source_url,
                 source_metadata_json, entry_count, content_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $entryStatement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_jmhz_codebook_entries
                (package_id, codebook_id, item_code, label, parent_code, ordinal,
                 metadata_json, row_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        );
        foreach ($codebooks as $codebook) {
            $entries = $this->rows($codebook, 'entries');
            $metadata = $codebook['source_metadata'] ?? null;
            if (!is_array($metadata)) {
                throw new \InvalidArgumentException('Číselník JMHZ nemá metadata zdroje.');
            }
            $codebookStatement->execute([
                $packageId,
                $this->requiredString($codebook, 'codebook_key'),
                $this->requiredString($codebook, 'source_kind'),
                $this->requiredString($codebook, 'source_name'),
                $this->nullableString($codebook, 'source_url'),
                CanonicalJson::encode($metadata),
                count($entries),
                $this->requiredString($codebook, 'content_hash'),
            ]);
            $codebookId = (int) $this->db->pdo()->lastInsertId();
            foreach ($entries as $entry) {
                $entryMetadata = $entry['metadata'] ?? null;
                if (!is_array($entryMetadata)) {
                    throw new \InvalidArgumentException('Položka číselníku JMHZ nemá metadata.');
                }
                $entryStatement->execute([
                    $packageId,
                    $codebookId,
                    $this->requiredString($entry, 'item_code'),
                    $this->requiredString($entry, 'label'),
                    $this->nullableString($entry, 'parent_code'),
                    $entry['ordinal'] ?? null,
                    CanonicalJson::encode($entryMetadata),
                    $this->requiredString($entry, 'row_hash'),
                ]);
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return list<array<string, mixed>>
     */
    private function rows(array $row, string $field): array
    {
        $value = $row[$field] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException("Pole {$field} balíku JMHZ není seznam.");
        }
        foreach ($value as $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException("Pole {$field} balíku JMHZ obsahuje neplatný řádek.");
            }
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function requiredString(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \InvalidArgumentException("Pole {$field} balíku JMHZ není neprázdný text.");
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function nullableString(array $row, string $field): ?string
    {
        $value = $row[$field] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException("Pole {$field} balíku JMHZ není text.");
        }

        return $value;
    }
}

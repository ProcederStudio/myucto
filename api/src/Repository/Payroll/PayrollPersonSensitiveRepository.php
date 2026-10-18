<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use PDO;

/**
 * @phpstan-type EncryptedIdentifier array{
 *   id:int,
 *   identifier_type:string,
 *   ciphertext:string,
 *   lookup_hash:string
 * }
 * @phpstan-type EncryptedContact array{
 *   id:int,
 *   contact_type:string,
 *   ciphertext:string,
 *   lookup_hash:string
 * }
 * @phpstan-type EncryptedAccount array{
 *   id:int,
 *   label:string,
 *   ciphertext:string,
 *   lookup_hash:string
 * }
 * @phpstan-type EncryptedDependant array{
 *   id:int,
 *   full_name:string,
 *   ciphertext:string,
 *   lookup_hash:string
 * }
 * @phpstan-type PlainAddress array{
 *   id:int,
 *   address_type:string,
 *   address:string,
 *   effective_from:string,
 *   effective_to:?string
 * }
 * @phpstan-type EncryptedProfile array{
 *   identifiers:list<EncryptedIdentifier>,
 *   contacts:list<EncryptedContact>,
 *   accounts:list<EncryptedAccount>,
 *   dependants:list<EncryptedDependant>,
 *   addresses:list<PlainAddress>
 * }
 */
final class PayrollPersonSensitiveRepository
{
    private const SAVEPOINT = 'payroll_person_sensitive_reveal';

    public function __construct(private readonly Connection $db) {}

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public function transactional(callable $callback): mixed
    {
        $pdo = $this->db->pdo();
        $nested = $pdo->inTransaction();
        if ($nested) {
            $pdo->exec('SAVEPOINT ' . self::SAVEPOINT);
        } else {
            $pdo->beginTransaction();
        }

        try {
            $result = $callback();
            if ($nested) {
                $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            } else {
                $pdo->commit();
            }

            return $result;
        } catch (\Throwable $exception) {
            if (TransactionFailure::rollbackIfAborted($pdo, $exception)) {
                throw $exception;
            }
            if ($nested) {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
                $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            } elseif ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return EncryptedProfile|null */
    public function encryptedProfile(
        int $supplierId,
        int $employeeId,
    ): ?array {
        if (!$this->db->pdo()->inTransaction()) {
            throw new \LogicException(
                'Citlivý profil lze načíst pouze uvnitř transakce.',
            );
        }

        $employee = $this->db->pdo()->prepare(
            'SELECT id
               FROM payroll_employees
              WHERE supplier_id = ? AND id = ?
              FOR UPDATE'
        );
        $employee->execute([$supplierId, $employeeId]);
        if ($employee->fetchColumn() === false) {
            return null;
        }

        return [
            'identifiers' => $this->identifiers($supplierId, $employeeId),
            'contacts' => $this->contacts($supplierId, $employeeId),
            'accounts' => $this->accounts($supplierId, $employeeId),
            'dependants' => $this->dependants($supplierId, $employeeId),
            'addresses' => $this->addresses($supplierId, $employeeId),
        ];
    }

    /**
     * Bydliště se v databázi NEšifruje — maskuje se až při čtení profilu
     * (`PayrollPersonProfileRepository::maskAddress()`). Do odkrytí přesto patří:
     * bez něj by tlačítko „Zobrazit údaje" odkrylo všechno kromě adresy a
     * uživatel by nevěděl, jestli je to záměr, nebo chyba.
     *
     * @return list<PlainAddress>
     */
    private function addresses(int $supplierId, int $employeeId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, address_type, street_line, city, postal_code, country_code,
                    effective_from, effective_to
               FROM payroll_person_addresses
              WHERE supplier_id = ? AND employee_id = ?
              ORDER BY address_type ASC, effective_from DESC, id DESC'
        );
        $statement->execute([$supplierId, $employeeId]);
        $result = [];
        while (($fetched = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $row = $this->row($fetched);
            $parts = array_filter([
                $this->optionalText($row['street_line'] ?? null),
                $this->optionalText($row['postal_code'] ?? null),
                $this->optionalText($row['city'] ?? null),
                $this->optionalText($row['country_code'] ?? null),
            ], static fn (?string $part): bool => $part !== null);
            $effectiveTo = $this->optionalText($row['effective_to'] ?? null);
            $result[] = [
                'id' => $this->integer($row['id'] ?? null, 'id'),
                'address_type' => $this->text(
                    $row['address_type'] ?? null,
                    'address_type',
                ),
                'address' => implode(', ', $parts),
                'effective_from' => $this->text(
                    $row['effective_from'] ?? null,
                    'effective_from',
                ),
                'effective_to' => $effectiveTo,
            ];
        }

        return $result;
    }

    private function optionalText(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return list<EncryptedDependant> */
    private function dependants(int $supplierId, int $employeeId): array
    {
        if (!$this->db->hasTable('payroll_dependants')) {
            return [];
        }
        $statement = $this->db->pdo()->prepare(
            'SELECT id, full_name, birth_number_ciphertext, birth_number_hash
               FROM payroll_dependants
              WHERE supplier_id = ? AND employee_id = ?
                AND birth_number_ciphertext IS NOT NULL
              ORDER BY id'
        );
        $statement->execute([$supplierId, $employeeId]);
        $result = [];
        while (($fetched = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $row = $this->row($fetched);
            $result[] = [
                'id' => $this->integer($row['id'] ?? null, 'id'),
                'full_name' => $this->text($row['full_name'] ?? null, 'full_name'),
                'ciphertext' => $this->text(
                    $row['birth_number_ciphertext'] ?? null,
                    'birth_number_ciphertext',
                ),
                'lookup_hash' => $this->hash(
                    $row['birth_number_hash'] ?? null,
                    'birth_number_hash',
                ),
            ];
        }

        return $result;
    }

    /** @return list<EncryptedIdentifier> */
    private function identifiers(int $supplierId, int $employeeId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, identifier_type, value_ciphertext, value_hash
               FROM payroll_person_identifiers
              WHERE supplier_id = ? AND employee_id = ?
              ORDER BY id'
        );
        $statement->execute([$supplierId, $employeeId]);
        $result = [];
        while (($fetched = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $row = $this->row($fetched);
            $result[] = [
                'id' => $this->integer($row['id'] ?? null, 'id'),
                'identifier_type' => $this->text(
                    $row['identifier_type'] ?? null,
                    'identifier_type',
                ),
                'ciphertext' => $this->text(
                    $row['value_ciphertext'] ?? null,
                    'value_ciphertext',
                ),
                'lookup_hash' => $this->hash(
                    $row['value_hash'] ?? null,
                    'value_hash',
                ),
            ];
        }

        return $result;
    }

    /** @return list<EncryptedContact> */
    private function contacts(int $supplierId, int $employeeId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, contact_type, contact_value_ciphertext,
                    contact_value_hash
               FROM payroll_person_contacts
              WHERE supplier_id = ? AND employee_id = ?
              ORDER BY id'
        );
        $statement->execute([$supplierId, $employeeId]);
        $result = [];
        while (($fetched = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $row = $this->row($fetched);
            $result[] = [
                'id' => $this->integer($row['id'] ?? null, 'id'),
                'contact_type' => $this->text(
                    $row['contact_type'] ?? null,
                    'contact_type',
                ),
                'ciphertext' => $this->text(
                    $row['contact_value_ciphertext'] ?? null,
                    'contact_value_ciphertext',
                ),
                'lookup_hash' => $this->hash(
                    $row['contact_value_hash'] ?? null,
                    'contact_value_hash',
                ),
            ];
        }

        return $result;
    }

    /** @return list<EncryptedAccount> */
    private function accounts(int $supplierId, int $employeeId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, label, bank_account_ciphertext, bank_account_hash
               FROM payroll_person_accounts
              WHERE supplier_id = ? AND employee_id = ?
              ORDER BY id'
        );
        $statement->execute([$supplierId, $employeeId]);
        $result = [];
        while (($fetched = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $row = $this->row($fetched);
            $result[] = [
                'id' => $this->integer($row['id'] ?? null, 'id'),
                'label' => $this->text($row['label'] ?? null, 'label'),
                'ciphertext' => $this->text(
                    $row['bank_account_ciphertext'] ?? null,
                    'bank_account_ciphertext',
                ),
                'lookup_hash' => $this->hash(
                    $row['bank_account_hash'] ?? null,
                    'bank_account_hash',
                ),
            ];
        }

        return $result;
    }

    private function integer(mixed $value, string $column): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\d+$/D', $value) === 1) {
            return (int) $value;
        }
        throw new \UnexpectedValueException(
            "Databázový sloupec {$column} neobsahuje celé číslo.",
        );
    }

    /** @return array<string,mixed> */
    private function row(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \UnexpectedValueException(
                'Databázový výsledek není objekt.',
            );
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException(
                    'Databázový výsledek nemá textové klíče.',
                );
            }
            $result[$key] = $item;
        }

        return $result;
    }

    private function text(mixed $value, string $column): string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }
        throw new \UnexpectedValueException(
            "Databázový sloupec {$column} neobsahuje text.",
        );
    }

    private function hash(mixed $value, string $column): string
    {
        if (is_string($value) && strlen($value) === 32) {
            return $value;
        }
        throw new \UnexpectedValueException(
            "Databázový sloupec {$column} neobsahuje SHA-256 hash.",
        );
    }
}

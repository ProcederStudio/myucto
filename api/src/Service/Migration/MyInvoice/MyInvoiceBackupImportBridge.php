<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MyInvoice;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Cron\BackupEncryption;
use MyInvoice\Service\Migration\Myucto\MyuctoImportException;
use MyInvoice\Service\Migration\Myucto\MyuctoImportWorkflow;
use MyInvoice\Service\Migration\Shared\ChunkedUploadStore;
use PDO;

/** Browser uploads are converted by the existing native import worker, never in HTTP. */
final class MyInvoiceBackupImportBridge
{
    public function __construct(
        private readonly Connection $db,
        private readonly Config $config,
        private readonly MyuctoImportWorkflow $workflow,
        private readonly MyInvoiceBackupConverter $converter,
    ) {
    }

    public function options(int $supplier, int $actor, string $token, array $options): array
    {
        $files = $options['files'] ?? [];
        if (($options['profile_confirmed'] ?? false) !== true || !is_array($files)
            || !array_is_list($files) || count($files) > 16) {
            throw new MyuctoImportException('invalid_options', 'Potvrďte účetní režim a historii DPH cílové firmy; přiložte nejvýše 16 ZIPů originálů.');
        }
        $tokens = [$token, ...$files];
        foreach ($tokens as $upload) {
            if (!is_string($upload)) {
                throw new MyuctoImportException('invalid_options', 'Neplatný archiv originálů.');
            }
            $this->workflow->validateStart($supplier, $actor, $upload, 'myinvoice', false, false);
        }
        if (count(array_unique($tokens)) !== count($tokens)) {
            throw new MyuctoImportException('invalid_options', 'Každý archiv nahrajte právě jednou.');
        }
        $stmt = $this->db->pdo()->prepare('SELECT ic, accounting_mode FROM supplier WHERE id = ?');
        $stmt->execute([$supplier]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$company || trim((string) $company['ic']) === '') {
            throw new MyuctoImportException('invalid_company', 'Cílová firma musí mít IČO pro výběr firmy ze zálohy.');
        }
        $stmt = $this->db->pdo()->prepare('SELECT effective_from, is_vat_payer, is_identified, annual_deduction_percent FROM supplier_vat_status_history WHERE supplier_id = ? ORDER BY effective_from');
        $stmt->execute([$supplier]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($history as &$row) {
            $row['is_vat_payer'] = (int) $row['is_vat_payer'];
            $row['is_identified'] = (int) $row['is_identified'];
        }
        unset($row);
        if ($history === []) {
            throw new MyuctoImportException('invalid_company', 'Nejprve nastavte historii DPH cílové firmy.');
        }
        // Capture the configuration the user confirmed. Native preview/apply also
        // rejects changes to the target profile after this snapshot was taken.
        return ['files' => $files, 'ic' => $company['ic'], 'accounting_mode' => $company['accounting_mode'], 'history' => $history];
    }

    public function convert(int $supplier, int $actor, string $token, array $options, ?string $password): string
    {
        $uploads = $this->workflow->uploads();
        $locks = [];
        $output = null;
        try {
            $tokens = [$token, ...$options['files']];
            sort($tokens);
            foreach ($tokens as $upload) {
                $this->workflow->validateStart($supplier, $actor, $upload, 'myinvoice', false, false);
                $lock = $uploads->acquireJobLock($supplier, $upload);
                if ($lock === null) {
                    throw new MyuctoImportException('already_running', 'Nahranou zálohu právě zpracovává jiný běh.', [], 409);
                }
                $locks[] = $lock;
            }
            $output = $this->workflow->init($supplier, $actor, 'myinvoice-converted.zip', 1, true)['token'];
            $secret = $password ?? BackupEncryption::passwordFromConfig($this->config);
            $this->converter->convert(
                $uploads->partPath($supplier, $token),
                array_map(fn (string $file): string => $uploads->partPath($supplier, $file), $options['files']),
                $uploads->partPath($supplier, $output),
                0,
                $options['accounting_mode'],
                $options['history'],
                $secret,
                $secret,
                $options['ic'],
            );
            $size = $uploads->partSize($supplier, $output);
            $uploads->updateState($supplier, $output, ['size' => $size, 'received' => $size, 'status' => ChunkedUploadStore::STATUS_READY]);
            return $output;
        } catch (\RuntimeException $e) {
            if ($output !== null) {
                $uploads->purge($supplier, $output);
            }
            if ($e instanceof MyuctoImportException || $e instanceof \PDOException) {
                throw $e;
            }
            throw new MyuctoImportException('invalid_backup', $e->getMessage());
        } finally {
            foreach (array_reverse($locks) as $lock) {
                $uploads->releaseJobLock($lock);
            }
        }
    }
}

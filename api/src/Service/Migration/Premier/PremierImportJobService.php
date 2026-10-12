<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Premier;

use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Repository\PremierImportRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Migration\ImportYears;
use MyInvoice\Service\Migration\Shared\AbstractImportJobService;
use MyInvoice\Service\Migration\Shared\ChunkedUploadStore;

/**
 * Převod z PREMIER na pozadí (`import_jobs.source = premier_import`, migrace 1859).
 *
 * Job dělá dvě věci: zpracuje nahranou zálohu (`params.mode = prepare`: kontrolní součet,
 * rozbalení, přehled účetních let) a spustí převod vybraných roků (`params.years`
 * vzestupně, každý rok vlastní běh a protokol; {@see PremierImporter}). Kostra jobu je
 * společná s ostatními převody ({@see AbstractImportJobService}).
 *
 * Na rozdíl od POHODY nese jedna záloha VŠECHNY účetní roky (databáze Visual FoxPro),
 * takže se po úspěšném ostrém převodu nemaže - uživatel v ní postupně převádí rok po
 * roku. Uklidí ji až denní úklid ({@see PremierUploads::purgeStaleAll()}).
 */
final class PremierImportJobService extends AbstractImportJobService
{
    public const SOURCE = 'premier_import';

    protected const LOG_PREFIX = 'PREMIER';
    protected const UPLOAD_NOUN = 'zálohy';
    protected const EXCEPTION_CLASS = PremierException::class;
    protected const PREPARE_FAILED = 'Zálohu dat PREMIER se nepodařilo přečíst.';
    protected const RUN_FAILED = 'Převod z PREMIER selhal na neočekávané chybě, podrobnosti jsou v logu serveru.';
    protected const PREPARE_BUSY = 'Zálohu už zpracovává jiný proces.';
    protected const UPLOAD_INCOMPLETE = 'Záloha není nahraná celá, nahrajte ji znovu.';
    protected const DEFAULT_FILE_NAME = 'premier_zaloha.izip';
    protected const UPLOADED_EVENT = 'import.premier_uploaded';
    protected const PREPARE_STEPS = ['Kontrolní součet zálohy', 'Rozbaluji zálohu', 'Čtu účetní roky zálohy'];

    protected const STEP_LABELS = [
        'chart' => 'Účtová osnova',
        'journal' => 'Účetní období a deník',
        'accounting_mode' => 'Režim účetní jednotky',
        'partners' => 'Adresář partnerů',
        'purchase_invoices' => 'Přijaté faktury',
        'issued_invoices' => 'Vydané faktury',
        'vat_documents' => 'Doklady s DPH mimo faktury',
        'bank' => 'Banka',
        'link' => 'Vazby dokladů na deník',
        'payments' => 'Úhrady dokladů',
        'assets' => 'Dlouhodobý majetek',
        'small_assets' => 'Drobný majetek',
        'payroll' => 'Zaměstnanci a mzdy',
        'reconciliation' => 'Rekonciliace',
        'tax_return' => 'Úpravy základu daně z přiznání PREMIER',
        'verification' => 'Kontrola proti podáním z PREMIER',
        'closing' => 'Uzávěrka roku',
        'done' => 'Dokončuji',
    ];

    public function __construct(
        ImportJobRepository $jobs,
        PremierImportRepository $runs,
        private readonly PremierImporter $importer,
        ActivityLogger $logger,
    ) {
        parent::__construct($jobs, $runs, $logger);
    }

    protected function uploads(): ChunkedUploadStore
    {
        return PremierUploads::store();
    }

    protected function extractUpload(string $part, int $supplierId, string $token): mixed
    {
        $root = PremierUploads::backupDir($supplierId, $token);
        PremierBackup::extractArchive($part, $root);
        return $root;
    }

    protected function describeUpload(mixed $extracted, int $supplierId, string $token): array
    {
        $agendas = PremierBackup::overview($extracted);
        if ($agendas === []) {
            throw new PremierException('no_agenda', 'Záloha neobsahuje žádný účetní zápis.');
        }
        $first = $agendas[0];
        return [
            'meta' => [
                'company' => ['ico' => (string) $first['ico'], 'dic' => (string) $first['dic'], 'name' => (string) $first['company']],
                'agendas' => $agendas,
            ],
            'activity' => ['agendas' => array_map(static fn (array $a): string => $a['ico'] . '/' . $a['year'], $agendas)],
            'log' => 'Záloha PREMIER načtena (' . count($agendas) . ' účetních let).',
        ];
    }

    /**
     * Vybrané roky jdou vzestupně, každý s vlastním záznamem běhu a protokolem
     * ({@see AbstractImportJobService::runYears()}).
     *
     * @param array<string,mixed> $job
     */
    protected function runLocked(int $jobId, array $job, int $supplierId): void
    {
        $userId = (int) ($job['created_by'] ?? 0);
        $params = is_array($job['params'] ?? null) ? $job['params'] : [];
        $token = (string) ($params['token'] ?? '');
        $dryRun = ($params['mode'] ?? '') !== 'import';
        $lock = null;

        try {
            // Zámek zálohy: denní úklid ani nové nahrání ji nesmaže, dokud z ní převod běží.
            $lock = $this->acquireRunUploadLock($supplierId, $token, 'Zálohu právě zpracovává jiný proces, nebo už byla uklizena - nahrajte ji znovu.');
            $this->closeInterruptedRuns($jobId, $supplierId);
            $meta = PremierUploads::meta($supplierId, $token);
            $years = ImportYears::fromParams($params);
            if ($years === []) {
                throw new PremierException('invalid_year', 'Vyberte aspoň jeden rok převodu.');
            }
            $steps = PremierImporter::stepKeys();
            $this->runYears($jobId, $years, $dryRun, $steps,
                function (int $index, array &$totals) use ($jobId, $params, $meta, $supplierId, $userId, $years, $steps): array {
                    return $this->runPlanYear($jobId, $params, $meta, $supplierId, $userId, $index, $years, $steps, $totals);
                });
        } catch (\Throwable $e) {
            $this->jobs->markFailed($jobId, $this->failureMessage($e, sprintf('převod zálohy %s firmy %d selhal', $token, $supplierId), static::RUN_FAILED));
        } finally {
            if ($lock !== null) {
                PremierUploads::releaseJobLock($lock);
            }
        }
        // Záloha zůstává (drží ostatní roky) - úklid ji nechává denní údržbě.
    }

    /**
     * Převod jednoho účetního roku s vlastním záznamem běhu a protokolem.
     *
     * @param array<string,mixed> $params
     * @param array<string,mixed> $meta
     * @param list<int> $years
     * @param list<string> $steps
     * @param array{created:int,skipped:int,failed:int} $totals MĚNÍ SE: počty za celý job
     * @return array{status:string,year:int,run_id:?int,error:?string}
     */
    private function runPlanYear(int $jobId, array $params, array $meta, int $supplierId, int $userId, int $index, array $years, array $steps, array &$totals): array
    {
        return $this->runYear($jobId, $params, $supplierId, $index, $years, $steps, $totals,
            function () use ($jobId, $params, $meta, $supplierId, $userId, $index, $years): array {
                $token = (string) ($params['token'] ?? '');
                $mode = ($params['mode'] ?? '') === 'import' ? 'import' : 'dry_run';
                $dryRun = $mode === 'dry_run';
                $year = $years[$index];
                $ico = (string) ($params['ico'] ?? '');
                $backup = PremierBackup::open(PremierUploads::backupDir($supplierId, $token))->withOverview((array) ($meta['agendas'] ?? []));
                // Rok jobu = účetní rok firmy ({@see PremierImporter::companyAgendas()}).
                $agenda = self::agenda(['agendas' => $this->importer->companyAgendas($supplierId, (array) ($meta['agendas'] ?? []), $ico, static fn (): PremierBackup => $backup)], $ico, $year);
                if ($agenda === null) {
                    throw new PremierException('agenda_not_found', "Záloha neobsahuje účetní rok {$year} této firmy.");
                }
                $runId = $this->runs->startRun($supplierId, $jobId, $mode, [
                    'ico' => $backup->ico,
                    'year' => $year,
                    'program' => 'PREMIER',
                    'sha256' => $meta['sha256'] ?? null,
                ], $userId > 0 ? $userId : null);
                return [
                    'run_id' => $runId,
                    'log' => ($dryRun ? 'Zkouška nanečisto' : 'Ostrý převod') . " agendy IČO {$agenda['ico']}, rok {$year}.",
                    'kind' => 'accounting',
                    'import' => fn (?callable $progress, ?callable $cancel): object
                        => $this->importer->run($supplierId, $userId, $backup, $year, $dryRun, $runId, $progress, $cancel, (bool) ($params['accept_differences'] ?? false)),
                ];
            });
    }

    /**
     * Agenda (účetní rok) zvoleného IČO z přehledu nahrané zálohy.
     *
     * @param array<string,mixed> $meta
     * @return array{dir:string,ico:string,year:int}|null
     */
    public static function agenda(array $meta, string $ico, int $year): ?array
    {
        foreach ((array) ($meta['agendas'] ?? []) as $a) {
            if ((int) ($a['year'] ?? 0) === $year && ($ico === '' || (string) ($a['ico'] ?? '') === $ico)) {
                return [
                    'dir' => (string) $a['dir'],
                    'ico' => (string) $a['ico'],
                    'year' => (int) $a['year'],
                ];
            }
        }
        return null;
    }
}

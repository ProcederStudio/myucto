<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Action\Admin\Import\MoneyS3MigrationAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Migration\MoneyS3\AgendaInfo;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3Uploads;
use MyInvoice\Service\Migration\MoneyS3\Ms3Backup;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\UploadedFile;

/**
 * Průvodce převodem z Money S3 u firmy s hospodářským rokem: roky zálohy (tabulka let,
 * „Převést od roku", předvahy z Money) jsou účetní roky firmy s hranicemi období a předvaha
 * se uloží pod rokem, pod kterým ji rekonciliace převodu hledá. Kalendářní firma beze změny.
 */
#[Group('integration')]
final class MoneyS3WizardFiscalYearTest extends MoneyS3ImportTestCase
{
    /** @var list<array{int,string}> */
    private array $tokens = [];

    protected function tearDown(): void
    {
        foreach ($this->tokens as [$supplierId, $token]) {
            MoneyS3Uploads::purge($supplierId, $token);
        }
        parent::tearDown();
    }

    /**
     * Agenda srpen–červenec nahraná, když firma měla ještě kalendářní rok: po nastavení
     * „Účetní rok začíná" na srpen náhled rozvrh deníku přepočítá a roky jsou 2024 a 2025.
     */
    public function testFiscalCompanyGetsCompanyYearsAndTrialBalanceUnderCompanyYear(): void
    {
        $supplierId = $this->supplier();
        $token = $this->upload($supplierId, SyntheticAgenda::filesFiscalYearFromAugust());
        $this->db->pdo()->prepare('UPDATE supplier SET fiscal_year_start_month = 8 WHERE id = ?')->execute([$supplierId]);
        $action = $this->container(MoneyS3MigrationAction::class);

        $show = $this->json($this->call($action, 'show', $supplierId, $token));
        self::assertSame([
            [2024, '2024-08-01', '2025-07-31'],
            [2025, '2025-08-01', '2026-07-31'],
        ], array_map(static fn (array $y): array => [$y['fiscal_year'], $y['period_start'] ?? null, $y['period_end'] ?? null], $show['agenda']['years']));
        self::assertNotContains('fiscal_year_mismatch', array_column($show['preflight'], 'code'), json_encode($show['preflight'], JSON_UNESCAPED_UNICODE));

        // Předvaha účetního roku firmy 2024 se uloží pod 2024 - tam ji rekonciliace hledá.
        $report = $this->call($action, 'attachReport', $supplierId, $token, ['year' => '2024'], SyntheticAgenda::trialBalanceCsv2024());
        self::assertSame(201, $report->getStatusCode(), (string) $report->getBody());
        self::assertSame([2024], array_keys(MoneyS3Uploads::reports($supplierId, $token)));
        $outside = $this->call($action, 'attachReport', $supplierId, $token, ['year' => '2026'], SyntheticAgenda::trialBalanceCsv2024());
        self::assertSame([422, 'invalid_year'], [$outside->getStatusCode(), $this->json($outside)['error']['code']]);
    }

    /** Kalendářní firma: roky náhledu nahrání beze změny, bez hranic období. */
    public function testCalendarCompanyKeepsUploadYears(): void
    {
        $supplierId = $this->supplier();
        $token = $this->upload($supplierId, null);
        $expected = MoneyS3Uploads::meta($supplierId, $token)['agenda'];

        $show = $this->json($this->call($this->container(MoneyS3MigrationAction::class), 'show', $supplierId, $token));
        self::assertSame($expected, $show['agenda']);
        foreach ($show['agenda']['years'] as $year) {
            self::assertArrayNotHasKey('period_start', $year);
        }
    }

    /** @param array<string,string>|null $files soubory agendy, `null` = výchozí syntetická agenda */
    private function upload(int $supplierId, ?array $files): string
    {
        $lz = $this->tmp . '/upload-' . bin2hex(random_bytes(3)) . '.lz';
        $files === null ? SyntheticAgenda::writeLz($lz) : SyntheticAgenda::writeLzFiles($lz, $files);
        $token = MoneyS3Uploads::newToken();
        $this->tokens[] = [$supplierId, $token];
        $backup = Ms3Backup::extract($lz, MoneyS3Uploads::agendaDir($supplierId, $token));
        MoneyS3Uploads::writeMeta($supplierId, $token, [
            'token' => $token,
            'file_name' => 'agenda.lz',
            'sha256' => str_repeat('0', 64),
            'uploaded_at' => date('c'),
            'uploaded_by' => $this->userId,
            'agenda' => AgendaInfo::fromBackup($backup)->toArray(),
            'journal_preview' => $this->importer->journalPreview($backup, $supplierId),
        ]);
        return $token;
    }

    /** @param array<string,string> $body */
    private function call(object $action, string $method, int $supplierId, string $token, array $body = [], ?string $report = null): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method === 'show' ? 'GET' : 'POST', '/api/admin/imports/money-s3')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute('auth.effective_role', new EffectiveRole(9, 'Test', 'staff', true, ['utilities.import' => 2]))
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId])
            ->withParsedBody($body);
        if ($report !== null) {
            $path = $this->tmp . '/report-' . bin2hex(random_bytes(3)) . '.csv';
            file_put_contents($path, $report);
            $request = $request->withUploadedFiles(['report' => new UploadedFile($path, 'predvaha.csv', 'text/csv', strlen($report))]);
        }
        return $action->{$method}($request, (new ResponseFactory())->createResponse(), ['token' => $token]);
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        return (array) json_decode((string) $response->getBody(), true);
    }
}

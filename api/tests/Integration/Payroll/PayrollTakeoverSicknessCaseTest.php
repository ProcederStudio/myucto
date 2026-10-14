<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\Payroll\PayrollAbsenceRepository;
use MyInvoice\Repository\Payroll\PayrollSicknessCaseRepository;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollSicknessWriter;
use MyInvoice\Service\Payroll\Deadline\PayrollDeadlineOverviewService;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverAbsenceWriter;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverEmployment;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverPolicy;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverRunState;
use MyInvoice\Service\Payroll\PayrollAbsenceValidator;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessCaseService;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessDocumentKind;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessException;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessSubmissionService;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * PRE-02: rozběhnutá neschopnost převzatá z předchozího mzdového programu.
 *
 * Převod zapisuje nepřítomnosti mimo schválení v Nepřítomnostech, takže případ
 * dávky dřív nevznikl vůbec a lhůtu HZUPN k návratu do práce (§ 97 odst. 3
 * zák. č. 187/2006 Sb.) nikdo nehlídal. Mzdy vede MyÚčto od 1. 10. 2026.
 *
 * Všechna data jsou syntetická; transakci vrací tearDown.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollTakeoverSicknessCaseTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const ENVIRONMENT = 'production';

    private int $officeId;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->officeId = $this->createOffice('PRV', 'Syntetická účtárna převodu', '9990008888');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->db->pdo()->prepare(
            'UPDATE payroll_employer_settings SET social_security_office_code = "115" WHERE supplier_id = ?',
        )->execute([$this->supplierId]);
        $this->db->pdo()->prepare(
            'UPDATE payroll_module_state SET start_period = "2026-10-01" WHERE supplier_id = ?',
        )->execute([$this->supplierId]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    /**
     * Rozpracovaný případ z PAMICA od 17. 9.: v MyÚčtu je jeho část od 1. 10.,
     * případ dávky ale začíná skutečným dnem vzniku. Patnáctý den neschopnosti
     * je 1. 10., tedy už v době MyÚčta — NEMPRI hlídá MyÚčto, stejně jako HZUPN.
     */
    public function testPamicaSicknessAcrossStartPeriodCreatesWatchedCase(): void
    {
        $person = $this->createEmployment($this->officeId, 'Pavla Převzatá', 20, 'hpp', 'employment', 40, 10_000);
        $writer = $this->writer(PohodaPayrollSicknessWriter::class);
        $result = $this->pamicaResult('FLOW-20', '2026-09-17', '2026-10-14');

        $writer->write($this->supplierId, $this->actors[0], $result, new ImportProtocol('import'), 'payroll_sickness');
        $writer->write($this->supplierId, $this->actors[0], $result, new ImportProtocol('import'), 'payroll_sickness');

        $cases = $this->casesOf($person['employment_id']);
        self::assertCount(1, $cases, 'Opakovaný převod případ nezdvojí.');
        self::assertSame('2026-09-17', $cases[0]['incapacity_from']);
        self::assertSame('2026-10-14', $cases[0]['incapacity_to']);
        self::assertSame('predecessor', $cases[0]['source']);
        self::assertSame('pending', $cases[0]['nempri_status']);
        self::assertSame('pending', $cases[0]['hzupn_status']);
        self::assertSame(['HZUPN' => '2026-10-15', 'NEMPRI' => '2026-10-01'], $this->watched((int) $cases[0]['id']));
    }

    /**
     * Neschopnost od 3. 8.: lhůta NEMPRI začala běžet 17. 8., v době předchozího
     * programu, který ho podal. Případ ho nese jako vyřízené předchozím
     * programem, MyÚčto ho nepřipraví a hlídá jen HZUPN.
     */
    public function testPredecessorFiledNempriLeavesOnlyHzupnWatched(): void
    {
        $person = $this->createEmployment($this->officeId, 'Petra Dlouhá', 21, 'hpp', 'employment', 40, 10_000);
        $this->writer(PohodaPayrollSicknessWriter::class)->write(
            $this->supplierId,
            $this->actors[0],
            $this->pamicaResult('FLOW-21', '2026-08-03', '2026-10-14'),
            new ImportProtocol('import'),
            'payroll_sickness',
        );

        $cases = $this->casesOf($person['employment_id']);
        self::assertCount(1, $cases);
        self::assertSame('2026-08-03', $cases[0]['incapacity_from']);
        self::assertSame('predecessor', $cases[0]['nempri_status']);
        self::assertSame('pending', $cases[0]['hzupn_status']);
        self::assertSame(['HZUPN' => '2026-10-15'], $this->watched((int) $cases[0]['id']));

        try {
            $this->container->get(SicknessSubmissionService::class)
                ->preview($this->supplierId, self::ENVIRONMENT, (int) $cases[0]['id'], SicknessDocumentKind::Nempri);
            self::fail('NEMPRI podané předchozím programem MyÚčto znovu nepodává.');
        } catch (SicknessException $exception) {
            self::assertSame('sickness_document_handled_by_predecessor', $exception->validationCode);
        }
    }

    /**
     * Příloha NEMPRI, kterou k případu sestavila PAMICA, nese číslo rozhodnutí
     * a rozhodné období včetně měsíců před převáděným rokem. Převod je případu
     * předá, aby MyÚčto podání připravilo samo; ručně zadané údaje nepřepíše.
     * Přílohu odeslanou za měsíc vedený už MyÚčtem jako podanou nevede.
     */
    public function testPamicaClaimCarriesDecisionAndDecisivePeriod(): void
    {
        $person = $this->createEmployment($this->officeId, 'Klára Příloha', 22, 'hpp', 'employment', 40, 10_000);
        $months = [];
        foreach (['2025-10', '2025-11', '2025-12', '2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09'] as $period) {
            $months[$period] = ['income_minor' => 4_200_000, 'excluded_days' => $period === '2025-12' ? 3 : 0];
        }
        $claim = ['period' => '2026-10', 'decision_number' => '900000000000000001', 'decisive_months' => $months,
            'sent' => true, 'accepted_on' => '2026-10-08'];
        $writer = $this->writer(PohodaPayrollSicknessWriter::class);
        $writer->write($this->supplierId, $this->actors[0], $this->pamicaResult('FLOW-22', '2026-09-17', '2026-10-14', $claim),
            new ImportProtocol('import'), 'payroll_sickness');

        $cases = $this->casesOf($person['employment_id']);
        self::assertCount(1, $cases);
        self::assertSame('900000000000000001', $cases[0]['decision_number']);
        self::assertSame('pending', $cases[0]['nempri_status'], 'Příloha za měsíc vedený MyÚčtem patří MyÚčtu.');
        $repository = $this->container->get(PayrollSicknessCaseRepository::class);
        $stored = $repository->decisiveMonths($this->supplierId, self::ENVIRONMENT, (int) $cases[0]['id']);
        self::assertSame($months, $stored);

        // Ruční úprava účetní má přednost před opakovaným převodem.
        $repository->replaceDecisiveMonths($this->supplierId, self::ENVIRONMENT, (int) $cases[0]['id'],
            ['2026-09' => ['income_minor' => 1, 'excluded_days' => 0]]);
        $writer->write($this->supplierId, $this->actors[0], $this->pamicaResult('FLOW-22', '2026-09-17', '2026-10-14', $claim),
            new ImportProtocol('import'), 'payroll_sickness');
        self::assertSame(['2026-09' => ['income_minor' => 1, 'excluded_days' => 0]],
            $repository->decisiveMonths($this->supplierId, self::ENVIRONMENT, (int) $cases[0]['id']));
    }

    /**
     * Ošetřovné za měsíc vedený MyÚčtem: ošetřovaná osoba, důvod a okolnosti péče
     * z přílohy PAMICA přejdou do případu, aby šlo NEMPRI připravit bez opisování.
     */
    public function testPamicaClaimCarriesCaredPersonOfCareBenefit(): void
    {
        $person = $this->createEmployment($this->officeId, 'Olga Pečující', 24, 'hpp', 'employment', 40, 10_000);
        $claim = ['period' => '2026-10', 'decision_number' => '90000000000000004N', 'decisive_months' => [],
            'fields' => ['action_start' => true, 'action_continuation' => false, 'action_end' => true,
                'application_from' => '2026-10-05', 'application_to' => '2026-10-07',
                'cared_first_name' => 'Tomáš', 'cared_last_name' => 'Pečující', 'cared_birth_date' => '2018-03-04',
                'care_reason' => 'ill', 'shared_household' => true, 'lone_caregiver' => false,
                'child_under_16' => true, 'cared_personally' => true, 'relationship_code' => 'PL'],
            'sent' => false, 'accepted_on' => null];
        $this->writer(PohodaPayrollSicknessWriter::class)->write($this->supplierId, $this->actors[0],
            $this->pamicaResult('FLOW-24', '2026-10-05', '2026-10-07', $claim, 'ocr'), new ImportProtocol('import'), 'payroll_sickness');

        $cases = $this->casesOf($person['employment_id']);
        self::assertCount(1, $cases);
        self::assertSame(['Tomáš', 'Pečující', '2018-03-04', 'ill', 'PL', '90000000000000004N'], [
            $cases[0]['cared_first_name'], $cases[0]['cared_last_name'], $cases[0]['cared_birth_date'],
            $cases[0]['care_reason'], $cases[0]['relationship_code'], $cases[0]['decision_number'],
        ]);
    }

    /** Hlášení HZUPN z PAMICA dá případu den vystavení a návrat do práce. */
    public function testPamicaEndReportCarriesIssueDateAndReturn(): void
    {
        $person = $this->createEmployment($this->officeId, 'Iva Vrácená', 25, 'hpp', 'employment', 40, 10_000);
        $report = ['period' => '2026-10', 'decision_number' => null, 'sent' => false, 'accepted_on' => null,
            'fields' => ['issued_on' => '2026-10-14', 'returned_to_work' => true, 'returned_on' => '2026-10-15']];
        $this->writer(PohodaPayrollSicknessWriter::class)->write($this->supplierId, $this->actors[0],
            $this->pamicaResult('FLOW-25', '2026-09-17', '2026-10-14', null, 'dpn', $report), new ImportProtocol('import'), 'payroll_sickness');

        $cases = $this->casesOf($person['employment_id']);
        self::assertSame(['2026-10-14', '2026-10-15', 'pending'],
            [$cases[0]['issued_on'], $cases[0]['returned_on'], $cases[0]['hzupn_status']]);
    }

    /**
     * Neschopnost přes začátek vedení mezd má v MyÚčtu i část z převzatého měsíce. Převod
     * pak dohledá tuhle starší část, ke které se případ dávky nevrací; údaje z podání PAMICA
     * musí dojít do případu i tak.
     */
    public function testEndReportReachesCaseWhenTakenOverPartPrecedesStart(): void
    {
        $person = $this->createEmployment($this->officeId, 'Ema Přelomová', 26, 'hpp', 'employment', 40, 10_000);
        $writer = $this->writer(PohodaPayrollSicknessWriter::class);
        $writer->write($this->supplierId, $this->actors[0], $this->pamicaResult('FLOW-26', '2026-09-17', '2026-10-14'),
            new ImportProtocol('import'), 'payroll_sickness');
        $this->container->get(PayrollAbsenceRepository::class)->create($this->supplierId,
            $this->container->get(PayrollAbsenceValidator::class)->absence([
                'employment_id' => $person['employment_id'], 'absence_type' => 'dpn',
                'date_from' => '2026-09-17', 'date_to' => '2026-09-30', 'note' => 'Převzatý měsíc',
            ]), $this->actors[0]);

        $report = ['period' => '2026-10', 'decision_number' => null, 'sent' => false, 'accepted_on' => null,
            'fields' => ['issued_on' => '2026-10-14', 'returned_to_work' => true, 'returned_on' => '2026-10-15']];
        $writer->write($this->supplierId, $this->actors[0], $this->pamicaResult('FLOW-26', '2026-09-17', '2026-10-14', null, 'dpn', $report),
            new ImportProtocol('import'), 'payroll_sickness');

        $cases = $this->casesOf($person['employment_id']);
        self::assertCount(1, $cases);
        self::assertSame('2026-10-14', $cases[0]['issued_on']);
    }

    /** Příloha, kterou PAMICA odeslala ještě před začátkem vedení mezd, je podaná předchozím programem. */
    public function testPamicaClaimSentBeforeStartIsFiledByPredecessor(): void
    {
        $person = $this->createEmployment($this->officeId, 'Lenka Odeslaná', 23, 'hpp', 'employment', 40, 10_000);
        $claim = ['period' => '2026-09', 'decision_number' => '900000000000000002', 'decisive_months' => [],
            'sent' => true, 'accepted_on' => '2026-09-30'];
        $this->writer(PohodaPayrollSicknessWriter::class)->write($this->supplierId, $this->actors[0],
            $this->pamicaResult('FLOW-23', '2026-09-17', '2026-10-14', $claim), new ImportProtocol('import'), 'payroll_sickness');

        $cases = $this->casesOf($person['employment_id']);
        self::assertSame('predecessor', $cases[0]['nempri_status']);
        self::assertSame('pending', $cases[0]['hzupn_status']);
    }

    /**
     * Převod označí NEMPRI za podané předchozím programem podle lhůty, ne podle
     * toho, co předchozí program skutečně odeslal. Nepodal-li ho, musí jít
     * podání vrátit: dřív zůstalo vyřízené natrvalo, povinnost se nehlídala
     * a NEMPRI nešlo připravit. Zrušením případu to obejít nejde, nový případ
     * téže události narazí na jedinečný klíč.
     */
    public function testPredecessorMarkedNempriCanBeReturnedToMyUcto(): void
    {
        $person = $this->createEmployment($this->officeId, 'Klára Vrácená', 23, 'hpp', 'employment', 40, 10_000);
        $this->writer(PohodaPayrollSicknessWriter::class)->write(
            $this->supplierId,
            $this->actors[0],
            $this->pamicaResult('FLOW-23', '2026-08-03', '2026-10-14'),
            new ImportProtocol('import'),
            'payroll_sickness',
        );
        $caseId = (int) $this->casesOf($person['employment_id'])[0]['id'];
        $cases = $this->container->get(SicknessCaseService::class);

        $case = $cases->recordReceipt($this->supplierId, self::ENVIRONMENT, $caseId, SicknessDocumentKind::Nempri, 'pending', null, null);

        self::assertSame('pending', $case['nempri_status']);
        self::assertNull($case['nempri_accepted_on']);
        self::assertSame('predecessor', $case['source']);
        self::assertSame(['HZUPN' => '2026-10-15', 'NEMPRI' => '2026-08-17'], $this->watched($caseId));
        try {
            $this->container->get(SicknessSubmissionService::class)
                ->preview($this->supplierId, self::ENVIRONMENT, $caseId, SicknessDocumentKind::Nempri);
        } catch (SicknessException $exception) {
            self::assertNotSame('sickness_document_handled_by_predecessor', $exception->validationCode, $exception->getMessage());
        } catch (\DomainException) {
            // Syntetická osoba nemá identitu pro větu; brána předchozího
            // programu je ale už za námi.
        }

        // Vrátit jde jen podání vedené jako podané předchozím programem.
        try {
            $cases->recordReceipt($this->supplierId, self::ENVIRONMENT, $caseId, SicknessDocumentKind::Hzupn, 'pending', null, null);
            self::fail('HZUPN čeká na podání z MyÚčta, není co vracet.');
        } catch (SicknessException $exception) {
            self::assertSame('sickness_receipt_reopen_not_predecessor', $exception->validationCode);
        }
    }

    /**
     * Obecný převod (JMHZ, PREMIER, POHODA): nepřítomnost přes hranici prvního
     * měsíce vedení mezd založí případ; dřív skončená ne — tu vyřídil celou
     * předchozí program.
     */
    public function testGenericTakeoverCreatesCaseOnlyAcrossStartPeriod(): void
    {
        $person = $this->createEmployment($this->officeId, 'Jana Obecná', 22, 'hpp', 'employment', 40, 10_000);
        $employment = new PayrollTakeoverEmployment(
            personalNumber: 'FLOW-22',
            relationKey: 'FLOW-22',
            absences: [
                ['type' => 'dpn', 'from' => '2026-06-01', 'to' => '2026-06-30', 'childbirth' => null],
                ['type' => 'dpn', 'from' => '2026-09-17', 'to' => '2026-10-14', 'childbirth' => null],
            ],
            transferStart: '2026-06',
        );

        $counts = $this->writer(PayrollTakeoverAbsenceWriter::class)->absences(
            $this->supplierId,
            $person['employment_id'],
            $employment,
            $this->actors[0],
            new PayrollTakeoverPolicy('jmhz', 'JMHZ'),
            new PayrollTakeoverRunState(),
        );

        self::assertSame(1, $counts['sickness_cases'] ?? 0, json_encode($counts) ?: '');
        $cases = $this->casesOf($person['employment_id']);
        self::assertCount(1, $cases);
        self::assertSame('2026-09-17', $cases[0]['incapacity_from']);
        self::assertSame('predecessor', $cases[0]['source']);
        self::assertArrayHasKey('HZUPN', $this->watched((int) $cases[0]['id']));
    }

    /**
     * T-3: nepřítomnosti převzaté z roku, pro který MyÚčto nemá ruleset náhrad
     * (`compensation_averages` začíná rokem 2025), se dřív nezapsaly vůbec
     * (`absences_rejected`) a ELDP pak u převzatého měsíce s vyloučenými dny nemělo
     * z čeho složit rozpad § 16 odst. 4. Zapíšou se jako historická evidence:
     * schválené, bez sazby náhrady a bez průměru. Převod běží po letech, takže
     * neschopnost přes konec roku přijde ve dvou částech a druhá na první naváže.
     */
    public function testTakenOverAbsencesBeforeRulesetsAreRecordedAsHistory(): void
    {
        $person = $this->createEmployment($this->officeId, 'Hana Historická', 24, 'hpp', 'employment', 40, 10_000);
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments SET start_date = "2020-01-01", actual_start_date = "2020-01-01" WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $person['employment_id']]);
        $writer = $this->writer(PayrollTakeoverAbsenceWriter::class);
        $policy = new PayrollTakeoverPolicy('pamica', 'PAMICA');
        $year = static fn (array $absences, string $start): PayrollTakeoverEmployment => new PayrollTakeoverEmployment(
            personalNumber: 'FLOW-24',
            relationKey: 'FLOW-24',
            absences: $absences,
            transferStart: $start,
        );

        $counts2023 = $writer->absences($this->supplierId, $person['employment_id'], $year([
            ['type' => 'dpn', 'from' => '2023-12-20', 'to' => '2023-12-31', 'childbirth' => null],
        ], '2023-01'), $this->actors[0], $policy, new PayrollTakeoverRunState());
        $counts2024 = $writer->absences($this->supplierId, $person['employment_id'], $year([
            ['type' => 'dpn', 'from' => '2024-01-01', 'to' => '2024-01-10', 'childbirth' => null],
            ['type' => 'vacation', 'from' => '2024-03-04', 'to' => '2024-03-08', 'childbirth' => null],
            ['type' => 'ocr', 'from' => '2024-05-13', 'to' => '2024-05-17', 'childbirth' => null],
        ], '2024-01'), $this->actors[0], $policy, new PayrollTakeoverRunState());

        $explain = json_encode([$counts2023, $counts2024]) ?: '';
        self::assertArrayNotHasKey('absences_rejected', $counts2023, $explain);
        self::assertArrayNotHasKey('absences_rejected', $counts2024, $explain);
        self::assertSame(1, $counts2023['absences_approved'] ?? 0, $explain);
        self::assertSame(3, $counts2024['absences_approved'] ?? 0, $explain);
        $rows = $this->absencesOf($person['employment_id']);
        self::assertSame([
            ['dpn', '2023-12-20', '2023-12-31', 'approved', null, 0],
            ['dpn', '2024-01-01', '2024-01-10', 'approved', null, 12],
            ['vacation', '2024-03-04', '2024-03-08', 'approved', 10_000, 0],
            ['ocr', '2024-05-13', '2024-05-17', 'approved', null, 0],
        ], $rows);
        self::assertSame([], $this->casesOf($person['employment_id']), 'Událost skončená před vedením mezd případ nezakládá.');

        $again = $writer->absences($this->supplierId, $person['employment_id'], $year([
            ['type' => 'dpn', 'from' => '2024-01-01', 'to' => '2024-01-10', 'childbirth' => null],
        ], '2024-01'), $this->actors[0], $policy, new PayrollTakeoverRunState());
        self::assertSame(['absences_existing' => 1], $again);
    }

    /**
     * Neschopnost převzatá z roku bez rulesetu, která pokračuje do prvního roku
     * s rulesetem a do vedení mezd v MyÚčtu: navazující část nese dny okna § 192 ZP
     * vyčerpané historickou částí a případ dávky začíná skutečným dnem vzniku.
     */
    public function testTakenOverSicknessFromYearBeforeRulesetsContinuesIntoPayroll(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_module_state SET start_period = "2025-01-01" WHERE supplier_id = ?',
        )->execute([$this->supplierId]);
        $person = $this->createEmployment($this->officeId, 'Ivo Navazující', 25, 'hpp', 'employment', 40, 10_000);
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments SET start_date = "2020-01-01", actual_start_date = "2020-01-01" WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $person['employment_id']]);
        $writer = $this->writer(PayrollTakeoverAbsenceWriter::class);
        $policy = new PayrollTakeoverPolicy('pamica', 'PAMICA');

        foreach ([['2024-12-27', '2024-12-31', '2024-01'], ['2025-01-01', '2025-01-31', '2025-01']] as [$from, $to, $start]) {
            $counts = $writer->absences($this->supplierId, $person['employment_id'], new PayrollTakeoverEmployment(
                personalNumber: 'FLOW-25',
                relationKey: 'FLOW-25',
                absences: [['type' => 'dpn', 'from' => $from, 'to' => $to, 'childbirth' => null]],
                transferStart: $start,
            ), $this->actors[0], $policy, new PayrollTakeoverRunState());
            self::assertArrayNotHasKey('absences_rejected', $counts, json_encode($counts) ?: '');
        }

        $rows = $this->absencesOf($person['employment_id']);
        self::assertSame(['dpn', '2024-12-27', '2024-12-31', 'approved', null, 0], $rows[0]);
        self::assertSame('2025-01-01', $rows[1][1]);
        self::assertSame(5, $rows[1][5], 'Navazující část nese pět dnů okna vyčerpaných v roce 2024.');
        $cases = $this->casesOf($person['employment_id']);
        self::assertCount(1, $cases);
        self::assertSame('2024-12-27', $cases[0]['incapacity_from']);
    }

    /** @return list<array{0:string,1:string,2:string,3:string,4:?int,5:int}> */
    private function absencesOf(int $employmentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT absence_type, date_from, date_to, status, compensation_rate_basis_points, sickness_window_carried_days
               FROM payroll_absences WHERE supplier_id = ? AND employment_id = ? ORDER BY date_from',
        );
        $stmt->execute([$this->supplierId, $employmentId]);

        return array_map(static fn (array $row): array => [
            (string) $row['absence_type'],
            (string) $row['date_from'],
            (string) $row['date_to'],
            (string) $row['status'],
            $row['compensation_rate_basis_points'] === null ? null : (int) $row['compensation_rate_basis_points'],
            (int) $row['sickness_window_carried_days'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** @return list<array<string,mixed>> */
    private function casesOf(int $employmentId): array
    {
        return $this->container->get(SicknessCaseService::class)
            ->list($this->supplierId, self::ENVIRONMENT, $employmentId);
    }

    /** @return array<string,string> agenda => termín */
    private function watched(int $caseId): array
    {
        $due = [];
        $overview = $this->container->get(PayrollDeadlineOverviewService::class)
            ->overview($this->supplierId, self::ENVIRONMENT, 400);
        foreach ($overview['items'] as $item) {
            if (($item['case_id'] ?? null) === $caseId) {
                $due[$item['title']] = $item['due_on'];
            }
        }
        ksort($due);

        return $due;
    }

    /** @return array<string,mixed> */
    /**
     * @param array<string,mixed>|null $claim příloha NEMPRI z PAMICA
     * @param array<string,mixed>|null $report hlášení HZUPN z PAMICA
     */
    private function pamicaResult(string $personalNumber, string $from, string $to, ?array $claim = null,
        string $type = 'dpn', ?array $report = null): array
    {
        return [
            'start_period' => '2026-10',
            'cases' => [[
                'in_progress' => true,
                'personal_number' => $personalNumber,
                'person_key' => $personalNumber,
                'type' => $type,
                'date_from' => $from,
                'date_to' => $to,
                'childbirth' => null,
                'compensation_minor' => null,
                'compensation_source' => null,
                'benefit_claim' => $claim,
                'end_report' => $report,
            ]],
            'wage_compensation_rows' => 0,
            'benefit_rows' => 0,
            'benefit_claims' => 0,
            'unclassified' => [],
        ];
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function writer(string $class): object
    {
        $service = $this->container->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}

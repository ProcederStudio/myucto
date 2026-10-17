<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Invoice\InvoiceSeriesCompletenessService;
use MyInvoice\Service\Invoice\VarsymbolGenerator;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * FR3 (vendor audit 2026-08) — report úplnosti číselné řady vydaných dokladů.
 *
 * Klíčový nález z bugreportu, který tenhle test musí ověřit: faktury a dobropisy mohou
 * sdílet jednu řadu (stejná šablona), a kontrola úplnosti to MUSÍ brát dohromady — jinak
 * hlásí falešné mezery přesně tam, kde číslo ve skutečnosti použil ten druhý typ dokladu.
 *
 * Izolováno pod existujícím supplierem (číslovací šablony dočasně přepsané a v tearDown
 * vrácené), doklady rok 2098 uklizené v tearDown. Soft-skip pokud chybí cfg.php.
 */
#[Group('integration')]
final class InvoiceSeriesCompletenessTest extends TestCase
{
    private const YEAR = 2098;

    private Connection $db;
    private InvoiceSeriesCompletenessService $service;
    private VarsymbolGenerator $varsymbol;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $clientId = 0;
    private int $clientWithOwnSeriesId = 0;

    /** @var list<int> */
    private array $categoryIds = [];

    /** @var array<string,mixed> */
    private array $originalSupplierRow = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container     = Bootstrap::buildContainer();
            $this->db      = $container->get(Connection::class);
            $this->service = $container->get(InvoiceSeriesCompletenessService::class);
            $this->varsymbol = $container->get(VarsymbolGenerator::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code='CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2='CZ' LIMIT 1")->fetchColumn() ?: 0);

        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }

        $stmt = $pdo->prepare(
            'SELECT invoice_number_format, credit_note_number_format, invoice_number_period,
                    simplified_number_format FROM supplier WHERE id = ?'
        );
        $stmt->execute([$this->supplierId]);
        $this->originalSupplierRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $stmt = $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, ic,
                                  main_email, language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "FR3 Test Client", "Test 1", "Praha", "11000", ?, "10000004",
                     "fr3@example.com", "cs", ?, 1, 0)'
        );
        $stmt->execute([$this->supplierId, $this->czId, $this->currencyId]);
        $this->clientId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        $pdo = $this->db->pdo();

        $pdo->prepare(
            'UPDATE supplier SET invoice_number_format = ?, credit_note_number_format = ?, invoice_number_period = ?,
                    simplified_number_format = ?
              WHERE id = ?'
        )->execute([
            $this->originalSupplierRow['invoice_number_format'] ?? null,
            $this->originalSupplierRow['credit_note_number_format'] ?? null,
            $this->originalSupplierRow['invoice_number_period'] ?? null,
            $this->originalSupplierRow['simplified_number_format'] ?? null,
            $this->supplierId,
        ]);

        foreach ([$this->clientId, $this->clientWithOwnSeriesId] as $clientId) {
            if ($clientId === 0) {
                continue;
            }
            $pdo->prepare('DELETE FROM invoices WHERE supplier_id = ? AND client_id = ?')
                ->execute([$this->supplierId, $clientId]);
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$clientId]);
        }
        foreach ($this->categoryIds as $categoryId) {
            $pdo->prepare('DELETE FROM revenue_categories WHERE id = ?')->execute([$categoryId]);
        }
        $pdo->prepare('DELETE FROM invoice_counters WHERE supplier_id = ? AND period = ?')
            ->execute([$this->supplierId, (string) self::YEAR]);
        $this->db->close();
    }

    /**
     * Ruční začátek řady — jde ZÁMĚRNĚ přes tutéž službu, kterou volá
     * PUT /api/settings/supplier/invoice-counter, ne přes vlastní INSERT. Kdyby si test
     * floor zapsal sám, ověřil by jen svůj vlastní SQL řádek, ne to, co do tabulky
     * reálně padá z nastavení.
     */
    private function setCounterFloor(string $type, int $nextNumber, int $clientId = 0, int $revenueCategoryId = 0): void
    {
        if (!$this->db->hasColumn('invoice_counters', 'floor_number')) {
            $this->markTestSkipped('Schéma nemá invoice_counters.floor_number (migrace 1813).');
        }
        $this->varsymbol->setCounter(
            $this->supplierId,
            $type,
            $nextNumber,
            new \DateTimeImmutable(self::YEAR . '-06-15'),
            $clientId,
            $revenueCategoryId,
        );
    }

    /** Kategorie tržby s VLASTNÍ číselnou řadou (migrace 1333). */
    private function createCategory(string $code, string $invoiceTpl, string $period = 'year'): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO revenue_categories (supplier_id, code, label, invoice_number_format, invoice_number_period)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, $code, "FR3 {$code}", $invoiceTpl, $period]);
        $id = (int) $pdo->lastInsertId();
        $this->categoryIds[] = $id;
        return $id;
    }

    /** Klient s VLASTNÍ číselnou řadou — přebíjí řadu kategorie i dodavatele. */
    private function createClientWithOwnSeries(string $invoiceTpl, string $period = 'year'): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, ic,
                                  main_email, language, currency_default_id, is_customer, is_vendor,
                                  invoice_number_format, invoice_number_period)
             VALUES (?, "FR3 Own Series Client", "Test 2", "Praha", "11000", ?, "10000004",
                     "fr3own@example.com", "cs", ?, 1, 0, ?, ?)'
        )->execute([$this->supplierId, $this->czId, $this->currencyId, $invoiceTpl, $period]);
        $this->clientWithOwnSeriesId = (int) $pdo->lastInsertId();
        return $this->clientWithOwnSeriesId;
    }

    private function setTemplates(string $invoiceTpl, string $creditNoteTpl, string $period = 'year'): void
    {
        $this->db->pdo()->prepare(
            'UPDATE supplier SET invoice_number_format = ?, credit_note_number_format = ?, invoice_number_period = ?
              WHERE id = ?'
        )->execute([$invoiceTpl, $creditNoteTpl, $period, $this->supplierId]);
    }

    private function insertInvoice(
        string $varsymbol,
        string $type = 'invoice',
        ?int $revenueCategoryId = null,
        ?int $clientId = null,
        ?string $issueDate = null,
        bool $simplified = false,
    ): void {
        $issue = $issueDate ?? self::YEAR . '-06-15';
        $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, is_simplified, client_id, revenue_category_id,
                 issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 1000.00, 210.00, 1210.00, "issued", "1", ?)'
        )->execute([
            $this->supplierId, $varsymbol, $type, $simplified ? 1 : 0, $clientId ?? $this->clientId, $revenueCategoryId,
            $issue, $issue, $issue,
            $this->currencyId, $this->userId,
        ]);
    }

    private function setSimplifiedTemplate(?string $template): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET simplified_number_format = ? WHERE id = ?')
            ->execute([$template, $this->supplierId]);
    }

    /** @param list<array<string,mixed>> $series */
    private static function groupOfType(array $series, string $type): array
    {
        foreach ($series as $group) {
            if (empty($group['inferred']) && in_array($type, $group['types'], true)) {
                return $group;
            }
        }
        self::fail("Řada typu {$type} v reportu chybí.");
    }

    /** @param list<array<string,mixed>> $series */
    private static function groupFor(array $series, int $clientId, int $categoryId): array
    {
        foreach ($series as $group) {
            if ($group['client_id'] === $clientId && $group['revenue_category_id'] === $categoryId) {
                return $group;
            }
        }
        self::fail("Řada pro client_id={$clientId}, revenue_category_id={$categoryId} v reportu chybí.");
    }

    public function testImportedNumbersOutsideConfiguredTemplatesRevealInternalGap(): void
    {
        $this->setTemplates('F{YYYY}{CCCC}', 'D{YYYY}{CCCC}');
        $this->insertInvoice('20980033');
        $this->insertInvoice('20980034');
        $this->insertInvoice('20980036');
        $this->insertInvoice('20980037', 'credit_note');
        $this->insertInvoice('20991238');

        $series = $this->service->build($this->supplierId, self::YEAR);

        self::assertCount(1, $series);
        self::assertTrue($series[0]['inferred']);
        self::assertSame(['invoice', 'credit_note'], $series[0]['types']);
        self::assertSame('2098{CCCC}', $series[0]['template_by_type']['invoice']);
        $bucket = $series[0]['buckets'][0];
        self::assertSame(33, $bucket['range_from']);
        self::assertSame(37, $bucket['range_to']);
        self::assertSame(4, $bucket['used_count']);
        self::assertSame([35], $bucket['missing']);
        self::assertSame(['20980035'], $bucket['missing_preview']);
        self::assertSame(1, $bucket['missing_total']);
    }

    public function testConfiguredSeriesNumbersAreNotDuplicatedInInferredSeries(): void
    {
        $this->setTemplates('{YYYY}{CCCC}', 'D{YYYY}{CCCC}');
        $this->insertInvoice('20980033');
        $this->insertInvoice('20980035');

        $series = $this->service->build($this->supplierId, self::YEAR);

        self::assertCount(1, $series);
        self::assertArrayNotHasKey('inferred', $series[0]);
        self::assertContains(34, $series[0]['buckets'][0]['missing']);
    }

    public function testImportedMonthlyNumbersDoNotBecomeAnnualGaps(): void
    {
        $this->setTemplates('F{YYYY}{CCCC}', 'D{YYYY}{CCCC}');
        $this->insertInvoice('209801001', issueDate: '2098-01-15');
        $this->insertInvoice('209802001', issueDate: '2098-02-15');

        $series = $this->service->build($this->supplierId, self::YEAR);

        self::assertSame([], $series);
    }

    public function testSharedSeriesCombinesInvoiceAndCreditNoteNumbers(): void
    {
        // Sdílená šablona (stejný digit skeleton) pro invoice i credit_note.
        $this->setTemplates('{YYYY}{CCCCCC}', '{YYYY}{CCCCCC}');

        $y = self::YEAR;
        $this->insertInvoice("{$y}000001", 'invoice');
        $this->insertInvoice("{$y}000002", 'invoice');
        // 000003 chybí u FAKTUR, ale číslo použil DOBROPIS — nesmí to být hlášeno jako mezera.
        $this->insertInvoice("{$y}000003", 'credit_note');
        $this->insertInvoice("{$y}000004", 'invoice');
        // 000005 chybí OPRAVDU — ani faktura, ani dobropis ho nepoužily.
        $this->insertInvoice("{$y}000006", 'invoice');

        $series = $this->service->build($this->supplierId, self::YEAR);

        self::assertCount(1, $series, 'Sdílený skeleton musí sloučit invoice+credit_note do JEDNÉ řady.');
        $group = $series[0];
        self::assertSame(['invoice', 'credit_note'], $group['types']);
        self::assertCount(1, $group['buckets']);
        $bucket = $group['buckets'][0];
        self::assertSame([5], $bucket['missing'], 'Jen 000005 je skutečná mezera — 000003 pokryl dobropis.');
        self::assertSame(6, $bucket['range_to']);
        self::assertSame(5, $bucket['used_count']);
    }

    /**
     * Daňový doklad k přijaté platbě, penalizační faktura a platební kalendář nemají
     * vlastní řadu: generátor jim přiděluje čísla z řady faktur
     * ({@see \MyInvoice\Service\Invoice\VarsymbolGenerator::documentTypesInSeries()}).
     * Jejich čísla proto v řadě faktur nesmí vypadat jako chybějící doklady.
     */
    public function testInvoiceSeriesAliasesFillTheirNumbers(): void
    {
        $this->setTemplates('{YYYY}{CCCCCC}', 'D{YYYY}{CCCCCC}');

        $y = self::YEAR;
        $this->insertInvoice("{$y}000001", 'invoice');
        $this->insertInvoice("{$y}000002", 'tax_document');
        $this->insertInvoice("{$y}000003", 'invoice');
        $this->insertInvoice("{$y}000004", 'penalty');
        // 000005 chybí OPRAVDU.
        $this->insertInvoice("{$y}000006", 'invoice');

        $series = $this->service->build($this->supplierId, self::YEAR);

        $group = self::groupFor($series, 0, 0);
        $bucket = $group['buckets'][0];
        self::assertSame([5], $bucket['missing'], 'Čísla daňového dokladu k platbě a penalizace nejsou mezery.');
        self::assertSame(5, $bucket['used_count']);
    }

    /** Vlastní řada zjednodušených dokladů (migrace 2037) je samostatná řada reportu. */
    public function testSimplifiedSeriesIsReportedOnItsOwn(): void
    {
        $this->setTemplates('{YYYY}{CCCCCC}', 'D{YYYY}{CCCCCC}');
        $this->setSimplifiedTemplate('ZD{YYYY}{CCCC}');

        $y = self::YEAR;
        $this->insertInvoice("{$y}000001");
        $this->insertInvoice("{$y}000002");
        $this->insertInvoice("ZD{$y}0001", simplified: true);
        $this->insertInvoice("ZD{$y}0002", simplified: true);
        // ZD…0003 chybí OPRAVDU.
        $this->insertInvoice("ZD{$y}0004", simplified: true);

        $series = $this->service->build($this->supplierId, self::YEAR);

        $invoices = self::groupOfType($series, 'invoice');
        self::assertSame([], $invoices['buckets'][0]['missing'], 'Řada faktur je úplná.');
        self::assertSame(2, $invoices['buckets'][0]['used_count']);

        $simplified = self::groupOfType($series, 'simplified');
        self::assertSame(0, $simplified['client_id']);
        self::assertSame([3], $simplified['buckets'][0]['missing']);
        self::assertSame(["ZD{$y}0003"], $simplified['buckets'][0]['missing_preview']);
    }

    /**
     * Řada se pozná podle tvaru čísla, ne podle dnešního nastavení ani příznaku: doklad,
     * který dostal číslo dřív, než firma řadu zjednodušených dokladů zavedla (nebo u něj
     * někdo příznak dodatečně přepnul), zůstává v řadě, ze které číslo přišlo.
     */
    public function testNumberStaysInSeriesItCameFrom(): void
    {
        $this->setTemplates('{YYYY}{CCCCCC}', 'D{YYYY}{CCCCCC}');
        $this->setSimplifiedTemplate('ZD{YYYY}{CCCC}');

        $y = self::YEAR;
        $this->insertInvoice("{$y}000001");
        $this->insertInvoice("{$y}000002", simplified: true); // vystaven před zavedením řady
        $this->insertInvoice("{$y}000003");
        $this->insertInvoice("ZD{$y}0001", simplified: true);
        $this->insertInvoice("ZD{$y}0002"); // příznak přepnutý až po vystavení
        $this->insertInvoice("ZD{$y}0003", simplified: true);

        $series = $this->service->build($this->supplierId, self::YEAR);

        self::assertSame([], self::groupOfType($series, 'invoice')['buckets'][0]['missing']);
        self::assertSame(3, self::groupOfType($series, 'invoice')['buckets'][0]['used_count']);
        self::assertSame([], self::groupOfType($series, 'simplified')['buckets'][0]['missing']);
        self::assertSame(3, self::groupOfType($series, 'simplified')['buckets'][0]['used_count']);
    }

    public function testSimplifiedSeriesRespectsManualStart(): void
    {
        $this->setTemplates('{YYYY}{CCCCCC}', 'D{YYYY}{CCCCCC}');
        $this->setSimplifiedTemplate('ZD{YYYY}{CCCC}');
        $this->setCounterFloor('simplified', 50);

        $y = self::YEAR;
        $this->insertInvoice("ZD{$y}0050", simplified: true);
        $this->insertInvoice("ZD{$y}0051", simplified: true);

        $series = $this->service->build($this->supplierId, self::YEAR);

        $bucket = self::groupOfType($series, 'simplified')['buckets'][0];
        self::assertSame([], $bucket['missing'], 'Čísla pod ručním začátkem řady nejsou chybějící doklady.');
        self::assertSame(50, $bucket['range_from']);
    }

    public function testDistinctSeriesAreReportedIndependently(): void
    {
        // Odlišné šablony (jiný skeleton) → faktury a dobropisy NESMÍ se míchat.
        $this->setTemplates('F{YYYY}{CCC}', 'D{YYYY}{CCCC}');

        $y = self::YEAR;
        $this->insertInvoice("F{$y}001", 'invoice');
        $this->insertInvoice("F{$y}003", 'invoice'); // 002 chybí

        $this->insertInvoice("D{$y}0001", 'credit_note');
        $this->insertInvoice("D{$y}0002", 'credit_note'); // bez mezery

        $series = $this->service->build($this->supplierId, self::YEAR);

        self::assertCount(2, $series, 'Odlišné šablony zůstávají DVĚ samostatné řady.');
        $byType = [];
        foreach ($series as $s) {
            $byType[$s['types'][0]] = $s;
        }
        self::assertSame([2], $byType['invoice']['buckets'][0]['missing']);
        self::assertSame([], $byType['credit_note']['buckets'][0]['missing']);
    }

    public function testNoGapsReportsEmptyMissingList(): void
    {
        $this->setTemplates('{YYYY}{CCCCCC}', '{YYYY}{CCCCCC}');
        $y = self::YEAR;
        $this->insertInvoice("{$y}000001", 'invoice');
        $this->insertInvoice("{$y}000002", 'credit_note');
        $this->insertInvoice("{$y}000003", 'invoice');

        $series = $this->service->build($this->supplierId, self::YEAR);

        self::assertCount(1, $series);
        self::assertSame([], $series[0]['buckets'][0]['missing']);
    }

    /**
     * Řada kategorie tržby (migrace 1333) je vlastní scope. Šablona je schválně zvolená
     * tak, aby se vyrenderovaná čísla CHYTLA i do regexu supplier-wide řady ("2098" + 9 +
     * counter) — reálný vzor „kategorii vyhradím číselné pásmo 9xx". Bez vyloučení
     * kategorie ze supplier-wide skenu by tenhle sken viděl counter 903 jako svoje
     * nejvyšší číslo a nahlásil stovky neexistujících mezer.
     */
    public function testRevenueCategorySeriesIsOwnScopeAndLeavesSupplierWideIntact(): void
    {
        $this->setTemplates('{YYYY}{CCCCCC}', '{YYYY}{CCCCCC}');
        $categoryId = $this->createCategory('FR3PREDPL', '{YYYY}9{CC}');

        $y = self::YEAR;
        $this->insertInvoice("{$y}000001", 'invoice');
        $this->insertInvoice("{$y}000002", 'invoice');
        // Řada kategorie: counter 1 a 3, číslo 2 v ní opravdu chybí.
        $this->insertInvoice("{$y}901", 'invoice', $categoryId);
        $this->insertInvoice("{$y}903", 'invoice', $categoryId);

        $series = $this->service->build($this->supplierId, self::YEAR);

        $supplierGroup = self::groupFor($series, 0, 0);
        self::assertSame([], $supplierGroup['buckets'][0]['missing'], 'Doklady kategorie nesmí do supplier-wide řady vůbec vstoupit.');
        self::assertSame(2, $supplierGroup['buckets'][0]['range_to']);
        self::assertSame(2, $supplierGroup['buckets'][0]['used_count']);

        $categoryGroup = self::groupFor($series, 0, $categoryId);
        self::assertSame('FR3 FR3PREDPL', $categoryGroup['revenue_category_name']);
        self::assertSame(['invoice'], $categoryGroup['types']);
        self::assertSame([2], $categoryGroup['buckets'][0]['missing'], 'Mezera uvnitř řady kategorie se hlásit MUSÍ.');
        self::assertSame(3, $categoryGroup['buckets'][0]['range_to']);
    }

    /**
     * Priorita resolveru je klient > kategorie > dodavatel, takže doklad klienta s vlastní
     * řadou nepatří do skenu kategorie, i když kategorii nese. Obě šablony jsou schválně
     * identické — bez vyloučení klienta by sken kategorie spolkl jeho číslo 900007 a
     * vyrobil mezery 2..6 v řadě, kde žádné nejsou.
     */
    public function testClientOwnSeriesBeatsRevenueCategoryScope(): void
    {
        $this->setTemplates('{YYYY}{CCCCCC}', '{YYYY}{CCCCCC}');
        $categoryId = $this->createCategory('FR3SDILENA', '{YYYY}9{CCCCC}');
        $ownClientId = $this->createClientWithOwnSeries('{YYYY}9{CCCCC}');

        $y = self::YEAR;
        $this->insertInvoice("{$y}900001", 'invoice', $categoryId);
        // Klient s vlastní řadou, doklad NESE tutéž kategorii — vyhrává klient.
        $this->insertInvoice("{$y}900007", 'invoice', $categoryId, $ownClientId);

        $series = $this->service->build($this->supplierId, self::YEAR);

        $categoryGroup = self::groupFor($series, 0, $categoryId);
        self::assertSame([], $categoryGroup['buckets'][0]['missing'], 'Doklad klienta s vlastní řadou nesmí do řady kategorie.');
        self::assertSame(1, $categoryGroup['buckets'][0]['range_to']);

        $clientGroup = self::groupFor($series, $ownClientId, 0);
        self::assertSame(7, $clientGroup['buckets'][0]['range_to']);
        self::assertSame([1, 2, 3, 4, 5, 6], $clientGroup['buckets'][0]['missing']);
    }

    /**
     * Konfigurace číslování se mění v čase: kategorii tržby přibude vlastní řada až
     * uprostřed období, doklady vystavené předtím ale nesou čísla ze staré (dodavatelské)
     * řady. Kdyby se příslušnost k řadě určovala podle DNEŠNÍHO `revenue_category_id`,
     * tyhle starší doklady by ze supplier-wide skenu vypadly a zůstaly by po nich falešné
     * mezery — a v nové řadě by se nezapočítaly, protože jejímu vzoru neodpovídají.
     * Rozhoduje proto tvar VS, ne aktuální nastavení.
     */
    public function testDocumentsKeepOldSeriesAfterCategoryGetsItsOwn(): void
    {
        $this->setTemplates('{YYYY}{CCCCCC}', '{YYYY}{CCCCCC}');
        $categoryId = $this->createCategory('FR3POZDE', '5{YYYY}{CCC}');

        $y = self::YEAR;
        // Vystaveno JEŠTĚ ve staré řadě dodavatele, kategorie tehdy vlastní řadu neměla.
        $this->insertInvoice("{$y}000001", 'invoice', $categoryId);
        $this->insertInvoice("{$y}000002", 'invoice', $categoryId);
        $this->insertInvoice("{$y}000003", 'invoice');
        // Až tenhle doklad vznikl po zavedení vlastní řady kategorie.
        $this->insertInvoice("5{$y}001", 'invoice', $categoryId);

        $series = $this->service->build($this->supplierId, self::YEAR);

        $supplierGroup = self::groupFor($series, 0, 0);
        self::assertSame([], $supplierGroup['buckets'][0]['missing'], 'Starší doklady kategorie zůstávají v dodavatelské řadě.');
        self::assertSame(3, $supplierGroup['buckets'][0]['range_to']);
        self::assertSame(3, $supplierGroup['buckets'][0]['used_count']);

        $categoryGroup = self::groupFor($series, 0, $categoryId);
        self::assertSame([], $categoryGroup['buckets'][0]['missing'], 'Nová řada kategorie začíná od 1 a je úplná.');
        self::assertSame(1, $categoryGroup['buckets'][0]['range_to']);
        self::assertSame(1, $categoryGroup['buckets'][0]['used_count']);
    }

    /**
     * Jediné ručně zadané (nebo importem rozbité) číslo posune horní hranici řady o
     * několik řádů. Výčet mezer se proto usekne, ale `missing_total` musí zůstat
     * přesný — jinak by sestava tvrdila, že mezer je 500, a účetní by podle toho
     * hledal 500 dokladů místo jednoho špatného čísla.
     */
    public function testHugeGapIsTruncatedButCountedExactly(): void
    {
        $this->setTemplates('{YYYY}{CCCCCC}', '{YYYY}{CCCCCC}');

        $y = self::YEAR;
        $this->insertInvoice("{$y}000001", 'invoice');
        $this->insertInvoice("{$y}000002", 'invoice');
        $this->insertInvoice("{$y}090000", 'invoice'); // překlep — řada vystřelí na 90 000

        $series = $this->service->build($this->supplierId, self::YEAR);
        $bucket = self::groupFor($series, 0, 0)['buckets'][0];

        self::assertSame(90000, $bucket['range_to']);
        self::assertSame(89997, $bucket['missing_total'], 'Počet mezer je 90000 - 3 obsazená čísla.');
        self::assertTrue($bucket['missing_truncated']);
        self::assertCount(500, $bucket['missing'], 'Výčet se musí useknout na strop.');
        self::assertCount(500, $bucket['missing_preview']);
        self::assertSame(3, $bucket['missing'][0], 'Useknutý výčet začíná od první skutečné mezery.');
    }

    public function testSmallGapIsNotMarkedTruncated(): void
    {
        $this->setTemplates('{YYYY}{CCCCCC}', '{YYYY}{CCCCCC}');
        $y = self::YEAR;
        $this->insertInvoice("{$y}000001", 'invoice');
        $this->insertInvoice("{$y}000003", 'invoice');

        $bucket = self::groupFor($this->service->build($this->supplierId, self::YEAR), 0, 0)['buckets'][0];

        self::assertSame([2], $bucket['missing']);
        self::assertSame(1, $bucket['missing_total']);
        self::assertFalse($bucket['missing_truncated']);
    }

    /**
     * Přechod z jiného software: uživatel navázal na rozjetou řadu a nechal počítadlo
     * začít na 56 (`floor_number = 55`). Čísla 1–55 v tomhle systému NIKDY nevznikla,
     * takže je report nesmí hlásit jako 55 chybějících dokladů — vypadalo by to jako
     * účetní problém tam, kde žádný není.
     */
    public function testManualSeriesStartIsNotReportedAsMissingDocuments(): void
    {
        $this->setTemplates('{YY}01{CCCCC}', 'D{YYYY}{CCCC}');
        $this->setCounterFloor('invoice', 56);

        $yy = substr((string) self::YEAR, 2, 2);
        foreach (range(56, 60) as $n) {
            $this->insertInvoice($yy . '01' . str_pad((string) $n, 5, '0', STR_PAD_LEFT), 'invoice');
        }

        $bucket = self::groupFor($this->service->build($this->supplierId, self::YEAR), 0, 0)['buckets'][0];

        self::assertSame([], $bucket['missing'], 'Čísla pod ručním začátkem řady nikdy neexistovala.');
        self::assertSame(0, $bucket['missing_total'], 'Bez floor by report hlásil 55 neexistujících dokladů.');
        self::assertSame(56, $bucket['range_from'], 'Rozsah řady začíná na ručně nastaveném čísle.');
        self::assertSame(60, $bucket['range_to']);
        self::assertSame(5, $bucket['used_count']);
        self::assertFalse($bucket['missing_truncated']);
    }

    /**
     * Protipól předchozího testu: ruční začátek řady NESMÍ kontrolu vypnout. Mezera
     * NAD nastaveným číslem je pořád mezera a musí se hlásit.
     */
    public function testRealGapAboveManualSeriesStartIsStillReported(): void
    {
        $this->setTemplates('{YY}01{CCCCC}', 'D{YYYY}{CCCC}');
        $this->setCounterFloor('invoice', 56);

        $yy = substr((string) self::YEAR, 2, 2);
        $this->insertInvoice($yy . '0100056', 'invoice');
        $this->insertInvoice($yy . '0100058', 'invoice'); // 57 chybí OPRAVDU

        $bucket = self::groupFor($this->service->build($this->supplierId, self::YEAR), 0, 0)['buckets'][0];

        self::assertSame(56, $bucket['range_from']);
        self::assertSame(58, $bucket['range_to']);
        self::assertSame([57], $bucket['missing'], 'Mezera nad ručním začátkem řady se hlásit MUSÍ.');
        self::assertSame(1, $bucket['missing_total']);
    }

    /**
     * Floor nastavený dodatečně (až po vystavení starších dokladů) nesmí vydané číslo
     * z rozsahu vynechat — aritmetika mezer by pak lhala do mínusu.
     */
    public function testFloorAboveExistingDocumentsIsClampedDown(): void
    {
        $this->setTemplates('{YY}01{CCCCC}', 'D{YYYY}{CCCC}');
        $this->setCounterFloor('invoice', 56);

        $yy = substr((string) self::YEAR, 2, 2);
        $this->insertInvoice($yy . '0100010', 'invoice');
        $this->insertInvoice($yy . '0100012', 'invoice');

        $bucket = self::groupFor($this->service->build($this->supplierId, self::YEAR), 0, 0)['buckets'][0];

        self::assertSame(10, $bucket['range_from'], 'Floor se srovná pod nejnižší skutečně vydané číslo.');
        self::assertSame(12, $bucket['range_to']);
        self::assertSame([11], $bucket['missing']);
        self::assertSame(1, $bucket['missing_total']);
    }

    /**
     * Ruční začátek řady existuje na všech třech osách číslování, ne jen u dodavatele.
     * Report proto musí floor hledat na TÉ SAMÉ ose, kterou zrovna staví: klíč
     * `invoice_counters` je (supplier_id, client_id, revenue_category_id, invoice_type,
     * period). Kdyby se četl jen supplier-wide řádek, kategorie tržby navázaná na
     * rozjetou řadu by hlásila stovky neexistujících dokladů — a naopak floor kategorie
     * nesmí ztišit skutečnou mezeru v řadě dodavatele.
     */
    public function testManualSeriesStartIsScopedToRevenueCategorySeries(): void
    {
        $this->setTemplates('{YYYY}{CCCCCC}', 'D{YYYY}{CCCC}');
        $categoryId = $this->createCategory('FR3NAVAZ', '{YYYY}9{CCC}');
        // Ruční začátek JEN na řadě kategorie; dodavatelská řada zůstává od jedničky.
        $this->setCounterFloor('invoice', 56, 0, $categoryId);

        $y = self::YEAR;
        $this->insertInvoice("{$y}9056", 'invoice', $categoryId);
        $this->insertInvoice("{$y}9057", 'invoice', $categoryId);
        // Dodavatelská řada má skutečnou mezeru (000002) a floor kategorie ji nesmí zakrýt.
        $this->insertInvoice("{$y}000001", 'invoice');
        $this->insertInvoice("{$y}000003", 'invoice');

        $series = $this->service->build($this->supplierId, self::YEAR);

        $categoryBucket = self::groupFor($series, 0, $categoryId)['buckets'][0];
        self::assertSame(56, $categoryBucket['range_from'], 'Floor se musí načíst pro řadu kategorie.');
        self::assertSame([], $categoryBucket['missing']);
        self::assertSame(0, $categoryBucket['missing_total']);

        $supplierBucket = self::groupFor($series, 0, 0)['buckets'][0];
        self::assertSame(1, $supplierBucket['range_from'], 'Řada dodavatele ruční začátek nemá.');
        self::assertSame([2], $supplierBucket['missing'], 'Floor jiné osy nesmí ztišit mezeru dodavatele.');
    }

    /**
     * Řada klienta s vlastní šablonou je třetí osa a chová se stejně.
     */
    public function testManualSeriesStartIsScopedToClientSeries(): void
    {
        $this->setTemplates('{YYYY}{CCCCCC}', 'D{YYYY}{CCCC}');
        $ownClientId = $this->createClientWithOwnSeries('K{YYYY}{CCCC}');
        $this->setCounterFloor('invoice', 56, $ownClientId);

        $y = self::YEAR;
        $this->insertInvoice("K{$y}0056", 'invoice', null, $ownClientId);
        $this->insertInvoice("K{$y}0058", 'invoice', null, $ownClientId);

        $bucket = self::groupFor($this->service->build($this->supplierId, self::YEAR), $ownClientId, 0)['buckets'][0];

        self::assertSame(56, $bucket['range_from']);
        self::assertSame([57], $bucket['missing'], 'Mezera nad ručním začátkem řady klienta se hlásí dál.');
    }

    public function testDifferentYearIsNotPolluted(): void
    {
        $this->setTemplates('{YYYY}{CCCCCC}', '{YYYY}{CCCCCC}');
        $this->insertInvoice(self::YEAR . '000001', 'invoice');

        // Report za JINÝ rok nesmí najít doklady z self::YEAR (roční period bucketing).
        $series = $this->service->build($this->supplierId, self::YEAR + 1);

        self::assertSame([], $series, 'Rok bez dokladů nesmí vyrobit falešnou zprávu o mezerách.');
    }
}

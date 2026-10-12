<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Premier;

use MyInvoice\Repository\PremierImportRepository;
use MyInvoice\Repository\TaxReturnRepository;
use MyInvoice\Service\Accounting\FiscalCalendar;
use MyInvoice\Service\Migration\Shared\FiledDppoInputs;
use MyInvoice\Service\Tax\Return\DppoReturnCalculator;
use MyInvoice\Service\Tax\Return\DppoReturnDataProvider;

/**
 * Ruční úpravy základu daně z přiznání k DPPO, které PREMIER v záloze drží (`D_PO1`,
 * `D_PO2`), do rozpracovaného přiznání MyÚčta.
 *
 * Výsledek hospodaření, odpisy a nedaňové účty spočte MyÚčto samo z převedeného deníku,
 * osnovy a majetku. Z účetnictví ale nevyplývají úpravy, které účetní zadala do přiznání
 * ručně (paušál na dopravu, příjmy osvobozené, ztráta minulých let…). Ty se převedou jako
 * ruční položky přiznání s textem, ze kterého řádku PREMIER pocházejí:
 *
 * - zvyšující: ř. 20, 30, 61, 62 a část ř. 40, kterou nedaňové účty osnovy a můstek ZC
 *   vyřazeného majetku nepokryjí;
 * - snižující: ř. 100-162 kromě ř. 150 (rozdíl odpisů spočte MyÚčto z karet majetku),
 *   z ř. 160 jen část nad rozdíl ZC vyřazeného majetku z karet;
 *   ř. 112 PREMIER používá pro paušální výdaj na dopravu (§ 24 odst. 2 písm. zt), takže se
 *   položka i s odpovídajícím vrácením PHM na ř. 40 označí jako paušál na dopravu;
 * - odečet ztráty (ř. 230) a zaplacené zálohy.
 *
 * Existující přiznání roku (i rozpracované) převod nemění.
 */
final class TaxReturnImporter
{
    public const STEP = 'tax_return';

    /** Sloupce `D_PO2` → řádek II. oddílu přiznání. */
    private const COLUMNS = [
        'II_20_HODN' => 20, 'II_30_CAST' => 30, 'II_61_UPRA' => 61, 'II_62_OST2' => 62, 'II_40_VYDA' => 40,
        'II_100_PRI' => 100, 'II_101_PRI' => 101, 'II_110_PRI' => 110, 'II_111_SNI' => 111, 'II_112_LZE' => 112,
        'II_120_PRI' => 120, 'II_130_PRI' => 130, 'II_140_PRI' => 140, 'II_160_UHR' => 160, 'II_161_UPR' => 161, 'II_162_OS2' => 162,
        'II_230_ODE' => 230,
    ];
    private const TEXTS = [
        'line' => 'Úprava základu z přiznání v PREMIER (ř. %s)',
        'line40' => 'Nedaňové výdaje z přiznání v PREMIER (ř. 40)',
        'line40_travel' => 'Vrácení PHM do základu u paušálu na dopravu (ř. 40 z PREMIER)',
        'travel' => 'Paušální výdaj na dopravu (§ 24 odst. 2 písm. zt), ř. 112 z PREMIER',
    ];

    public function __construct(
        private readonly TaxReturnRepository $returns,
        private readonly DppoReturnDataProvider $data,
        private readonly PremierImportRepository $map,
    ) {}

    public function import(PremierContext $ctx): void
    {
        $p = $ctx->protocol;
        $values = self::premierReturn($ctx->backup, $ctx->year, $ctx->calendar);
        if ($values === null) {
            $p->finish(self::STEP);
            return;
        }
        $existing = $this->returns->find($ctx->supplierId, $ctx->year, 'po');
        if ($existing !== null) {
            $p->count(self::STEP, 'kept');
            $p->info(self::STEP, 'return_exists', "Přiznání k DPPO za rok {$ctx->year} už ve firmě je, úpravy z PREMIER se do něj nepřebírají.");
            $p->finish(self::STEP);
            return;
        }
        $data = $this->data->gather($ctx->supplierId, $ctx->year, []);
        $built = self::inputsFromPremier($values, DppoReturnCalculator::accountingAdjustments($data));
        $inputs = $built['inputs'];
        $increase = $built['increase'];
        $decrease = $built['decrease'];
        if (isset($built['shortfalls'][40])) {
            $p->warn(self::STEP, 'line40_higher', sprintf('Nedaňové náklady podle osnovy (%s Kč) jsou vyšší než ř. 40 přiznání v PREMIER (%s Kč). Zkontrolujte daňovou uznatelnost účtů.',
                number_format($built['shortfalls'][40]['computed'], 2, ',', ' '), number_format($built['shortfalls'][40]['filed'], 2, ',', ' ')));
        }
        if (isset($built['shortfalls'][160])) {
            $p->warn(self::STEP, 'line160_higher', sprintf('Rozdíl zůstatkových cen vyřazeného majetku podle karet (%s Kč) je vyšší než ř. 160 přiznání v PREMIER (%s Kč). Zkontrolujte vyřazení majetku.',
                number_format($built['shortfalls'][160]['computed'], 2, ',', ' '), number_format($built['shortfalls'][160]['filed'], 2, ',', ' ')));
        }
        foreach (['II_240_ODE' => '240', 'II_242_ODE' => '242', 'II_300_SLE' => '300'] as $column => $line) {
            if ((float) ($values[$column] ?? 0) > 0.0) {
                $p->warn(self::STEP, 'manual_line', "Přiznání v PREMIER má vyplněný ř. {$line} (odpočty, slevy) - v MyÚčtu ho doplňte ručně v přiznání k DPPO.", ['line' => $line]);
            }
        }
        if ($inputs === []) {
            $p->finish(self::STEP);
            return;
        }
        $row = $this->returns->create($ctx->supplierId, $ctx->year, 'po', $inputs, $ctx->userOrNull());
        $this->map->put($ctx->supplierId, PremierImportRepository::KIND_TAX_RETURN, (string) $ctx->year, (int) $row['id'], $ctx->runId);
        $p->setCount(self::STEP, 'increase_items', count($increase));
        $p->setCount(self::STEP, 'decrease_items', count($decrease));
        $p->info(self::STEP, 'return_created', "Rozpracované přiznání k DPPO {$ctx->year} dostalo ruční úpravy základu z PREMIER: "
            . implode('; ', array_map(static fn (array $i): string => $i['text'] . ' ' . number_format($i['amount'], 0, ',', ' ') . ' Kč', array_merge($increase, $decrease))) . '.');
        $p->finish(self::STEP);
    }

    /**
     * Ruční vstupy přiznání z hodnot `D_PO2` podle sdíleného pravidla {@see FiledDppoInputs}.
     *
     * @param array<string,mixed> $values
     * @param array{40?:float,160?:float} $computed část ř. 40 a 160 spočtená z účetnictví
     * @return array{inputs:array<string,mixed>,increase:list<array<string,mixed>>,decrease:list<array<string,mixed>>,shortfalls:array<int,array{computed:float,filed:float}>}
     */
    public static function inputsFromPremier(array $values, array $computed): array
    {
        $filed = [];
        foreach (self::COLUMNS as $column => $line) {
            if (array_key_exists($column, $values)) {
                $filed[$line] = (float) $values[$column];
            }
        }
        return FiledDppoInputs::build($filed, $computed, true, self::TEXTS, (float) ($values['V_1_NA_ZAL'] ?? 0));
    }

    /**
     * Přiznání roku z PREMIER: hlavička `D_PO1` + hodnoty `D_PO2` (poslední podání roku).
     *
     * @return array<string,mixed>|null
     */
    public static function premierReturn(PremierBackup $backup, int $year, ?FiscalCalendar $calendar = null): ?array
    {
        $head = PremierSourceYears::taxReturnHead($backup, $year, $calendar);
        if ($head === null) {
            return null;
        }
        foreach ($backup->rows('D_PO2') as $r) {
            if (trim((string) ($r['ID_CISLO'] ?? '')) === trim((string) $head['ID_CISLO'])) {
                return $r + $head;
            }
        }
        return null;
    }
}

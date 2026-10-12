<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Premier;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DepreciationEntryRepository;
use MyInvoice\Repository\PremierImportRepository;
use MyInvoice\Service\Accounting\Assets\AssetException;
use MyInvoice\Service\Accounting\Assets\AssetService;
use MyInvoice\Service\Migration\MoneyS3\AccountCode;
use MyInvoice\Service\Migration\Shared\MigratedDepreciation;
use MyInvoice\Service\Migration\Shared\MigratedDisposal;

/**
 * Karty dlouhodobého majetku PREMIER s daňovými odpisy po letech, účetním plánem po měsících
 * a pohyby. PREMIER vede dva registry se stejnou strukturou: `MAJETEK` (`ODPISY`, `ODPISY_U`,
 * `MAJ_POH` přes INTER karty) a `MAJ_H` (`MAJ_H_OD`, `MAJ_H_OU`, `MAJ_H_PO` přes ID karty).
 * Dlouhodobý majetek jsou jen karty řad hmotného a nehmotného majetku; drobný majetek převádí
 * {@see SmallAssetImporter} a ostatní evidence (finanční majetek, leasing, rezervy) se jen
 * ohlásí ({@see PremierSmallAssets::register()}).
 *
 * Karta vznikne jako historický majetek ve stavu zařazeno, bez zápisu v deníku: zařazení
 * i odpisy už v převedeném deníku jsou. Účty nese karta PREMIER sama (pořízení `PMD`/`PDAL`,
 * odpisy `UMD`/`UDAL`).
 *
 * Karta je v MyÚčtu vedená stejně, jako by v něm vznikla: počáteční stavy pokrývají jen
 * odpisy let před prvním převedeným rokem a každý převedený rok má vlastní řádek odpisů.
 *
 * - **Účetně** je odpis roku to, co PREMIER v roce zaúčtoval (pohyby „zaúčtován odpis"),
 *   jako řádek zaúčtovaný převzatým deníkem ({@see DepreciationEntryRepository::MIGRATED_JOURNAL}) -
 *   hromadné účtování odpisů ho znovu neúčtuje a plán na něj naváže dalším měsícem.
 * - **Daňově** se odpis roku zapíše jako potvrzený přesně podle PREMIER - s ním PREMIER
 *   sestavil přiznání k DPPO.
 */
final class AssetImporter
{
    public const STEP = 'assets';

    /** `MAJETEK.ZPUSOB` - způsob daňového odpisu. */
    private const TAX_METHODS = [1 => 'straight', 2 => 'accelerated'];

    /** `MAJ_POH.KOD`: 10 zařazení, 6 zaúčtovaný účetní odpis, 7 daňový odpis. */
    private const MOVEMENT_IN_USE = 10;
    private const MOVEMENT_BOOKED = 6;
    private const KNOWN_MOVEMENTS = [6, 7, 10];

    public function __construct(
        private readonly Connection $db,
        private readonly PremierImportRepository $map,
        private readonly AssetService $assets,
        private readonly DepreciationEntryRepository $entries,
        private readonly MigratedDisposal $disposals,
    ) {}

    public function import(PremierContext $ctx): void
    {
        $p = $ctx->protocol;
        $policy = $ctx->smallAssets ?? PremierSmallAssets::fromBackup($ctx->backup);
        $existing = $this->map->all($ctx->supplierId, PremierImportRepository::KIND_ASSET);
        // Dva registry karet se stejnou strukturou: `MAJETEK` (odpisy a pohyby přes INTER karty)
        // a `MAJ_H` (odpisy a pohyby přes ID karty).
        $registers = [
            ['table' => 'MAJETEK', 'prefix' => 'asset|', 'link' => 'INTER',
                'tax' => self::byCard($ctx->backup->rows('ODPISY'), 'O_MAJETEK'),
                'plan' => self::byCard($ctx->backup->rows('ODPISY_U'), 'O_MAJETEK'),
                'movements' => self::byCard($ctx->backup->rows('MAJ_POH'), 'MAJETEK')],
            ['table' => 'MAJ_H', 'prefix' => 'asset|H|', 'link' => 'ID',
                'tax' => self::byCard($ctx->backup->rows('MAJ_H_OD'), 'ID_MAJ_H'),
                'plan' => self::byCard($ctx->backup->rows('MAJ_H_OU'), 'ID_MAJ_H'),
                'movements' => self::byCard($ctx->backup->rows('MAJ_H_PO'), 'ID_MAJ_H')],
        ];
        $other = [];
        foreach ($registers as $register) {
            foreach ($ctx->backup->rows($register['table']) as $row) {
                [$kind, $cardKind] = $policy->register((string) ($row['DOKLAD'] ?? ''));
                if ($kind === PremierSmallAssets::REGISTER_SMALL) {
                    $p->count(self::STEP, 'small_register');
                    continue; // drobný majetek převádí SmallAssetImporter
                }
                if ($kind === PremierSmallAssets::REGISTER_OTHER) {
                    $other[strtoupper(trim((string) ($row['DOKLAD'] ?? '')))] = ($other[strtoupper(trim((string) ($row['DOKLAD'] ?? '')))] ?? 0) + 1;
                    continue;
                }
                $this->importCard($ctx, $existing, $register, $row, $cardKind ?? 'tangible');
            }
        }
        foreach ($other as $series => $count) {
            $p->count(self::STEP, 'other_register', $count);
            $p->info(self::STEP, 'other_register', "Evidence řady {$series} ({$count} karet: finanční majetek, leasing, ostatní evidence nebo rezervy) se do dlouhodobého majetku nepřevádí; účetně je v převedeném deníku.", ['series' => $series]);
        }
        $p->finish(self::STEP);
    }

    /**
     * @param array<string,int> $existing
     * @param array{table:string,prefix:string,link:string,tax:array<string,list<array<string,mixed>>>,plan:array<string,list<array<string,mixed>>>,movements:array<string,list<array<string,mixed>>>} $register
     * @param array<string,mixed> $row
     */
    private function importCard(PremierContext $ctx, array $existing, array $register, array $row, string $cardKind): void
    {
        $p = $ctx->protocol;
        $start = $ctx->startsOn();
        $end = $ctx->endsOn();
        $tax = $register['tax'];
        $plan = $register['plan'];
        $movements = $register['movements'];
        $inter = $register['link'] === 'INTER' ? (string) (int) ($row['INTER'] ?? 0) : self::cardKey($row['ID'] ?? '');
        $number = trim((string) ($row['CISLO'] ?? ''));
        if ($inter === '' || $inter === '0' || $number === '' || $number === '0') {
            $p->warn(self::STEP, 'asset_without_number', 'Karta majetku bez inventárního čísla, nepřevzata.');
            return;
        }
        $inUse = self::date($row['DATUM_UO'] ?? null) ?? self::date($row['DATUM'] ?? null) ?? self::date($row['DATUM_P'] ?? null);
        $disposal = self::date($row['DATUM_V'] ?? null);
        if ($inUse === null || $inUse > $end) {
            $p->count(self::STEP, 'later_years');
            return;
        }
        if ($disposal !== null && $disposal < $start) {
            $p->count(self::STEP, 'disposed_before');
            return;
        }
        $key = $register['prefix'] . $inter;
        $booked = self::bookedThrough($movements[$inter] ?? [], $ctx->calendar->periodEnd($ctx->year - 1));
        $monthPlan = self::monthPlan($plan[$inter] ?? []);
        if (isset($existing[$key])) {
            $this->confirmYear($ctx, $existing[$key], $tax[$inter] ?? [], $movements[$inter] ?? [], $number);
            $p->count(self::STEP, 'existing');
            if ($disposal !== null && $disposal <= $end && $this->status($ctx, $existing[$key]) === 'in_use') {
                $this->disposeMigrated($ctx, $existing[$key], $disposal, $number);
            }
            return;
        }

        $review = [];
        foreach ($movements[$inter] ?? [] as $m) {
            $kind = (int) ($m['KOD'] ?? 0);
            if (!in_array($kind, self::KNOWN_MOVEMENTS, true) && (string) ($m['DATUM'] ?? '') <= $end) {
                $review[] = 'pohyb majetku „' . trim((string) ($m['POPIS'] ?? ('druh ' . $kind))) . '" ('
                    . number_format((float) ($m['CASTKA'] ?? 0), 2, ',', ' ') . ' Kč) převod nepřebírá, doplňte ho na kartě';
            }
        }
        $inputPrice = round((float) ($row['CENA'] ?? 0), 2);
        $taxPrice = round((float) ($row['D_CENA'] ?? 0), 2);
        if ($taxPrice > 0 && abs($taxPrice - $inputPrice) >= 0.01) {
            $review[] = 'daňová vstupní cena ' . number_format($taxPrice, 2, ',', ' ') . ' Kč se liší od účetní';
        }
        $taxRows = $tax[$inter] ?? [];
        $methodCode = (int) ($row['ZPUSOB'] ?? 0);
        $taxMethod = $taxRows === [] ? 'none' : (self::TAX_METHODS[$methodCode] ?? 'none');
        if ($taxRows !== [] && !isset(self::TAX_METHODS[$methodCode])) {
            $review[] = 'způsob daňového odpisu ' . $methodCode . ' převod nezná';
        }
        $group = (int) ($row['SKUPINA'] ?? 0);
        $taxGroup = $group >= 1 && $group <= 6 ? $group : null;
        if (in_array($taxMethod, ['straight', 'accelerated'], true) && $taxGroup === null) {
            $review[] = 'odpisová skupina chybí';
        }
        $openingYears = 0;
        $openingTax = 0.0;
        foreach ($taxRows as $t) {
            $amount = (float) ($t['O_ODPIS'] ?? 0);
            if (self::yearOf($ctx, $t['O_DATUM'] ?? null) < $ctx->year && abs($amount) >= 0.005) {
                $openingYears++;
                $openingTax += $amount;
            }
        }
        [$openingMonths, $openingAcc, $usefulLife] = self::accountingOpening($inUse, $monthPlan, $booked);
        if ($monthPlan === []) {
            $review[] = 'účetní odpisový plán chybí';
        }
        $card = [
            'inventory_number' => mb_substr($number, 0, 30),
            'name' => mb_substr(trim((string) ($row['POPIS'] ?? '')) ?: $number, 0, 255),
            'kind' => $cardKind,
            'input_price' => $inputPrice,
            'acquisition_date' => self::date($row['DATUM_P'] ?? null) ?? $inUse,
            'put_into_use_date' => $inUse,
            'status' => 'in_use',
            'tax_method' => $taxMethod,
            'tax_group' => $taxGroup,
            'opening_tax_years' => $openingYears,
            'opening_tax_amount' => round($openingTax, 2),
            'opening_acc_months' => $openingMonths,
            'opening_acc_amount' => min($openingAcc, $inputPrice),
            'acc_useful_life_months' => $usefulLife,
            'acc_method' => 'straight_line',
            'acc_residual_value' => 0.0,
        ];
        foreach (['asset_account_code' => 'PMD', 'accumulated_account_code' => 'UDAL', 'acquisition_account_code' => 'PDAL'] as $field => $column) {
            $code = AccountCode::fromMoney(trim((string) ($row[$column] ?? '')));
            if ($code !== null) {
                $card[$field] = $code;
            }
        }
        if (!isset($card['asset_account_code'])) {
            $review[] = 'karta nemá majetkový účet';
        }
        // Vyřazení v převáděném roce zaúčtoval převedený deník: karta se vyřadí bez zaúčtování
        // (disposeMigrated). Ke kontrole zůstává jen vyřazení po převáděném roce.
        $disposedInYear = $disposal !== null && $disposal <= $end;
        if ($disposal !== null && !$disposedInYear) {
            $review[] = 'majetek je v PREMIER vyřazený ' . $disposal . ', vyřazení proveďte v MyÚčtu';
        }
        if ($review !== [] && $disposedInYear) {
            $review[] = 'majetek je v PREMIER vyřazený ' . $disposal . ' a vyřazení je v převedeném deníku: po kontrole kartu zařaďte a vyřaďte bez zaúčtování';
        }
        if ($review !== []) {
            $card['status'] = 'draft';
            $card['description'] = 'Převod z PREMIER - ke kontrole: ' . implode('; ', $review) . '.';
        }
        try {
            $created = $this->assets->create($ctx->supplierId, $card, ['user_id' => $ctx->userOrNull()]);
        } catch (AssetException $e) {
            $p->warn(self::STEP, 'asset_rejected', "Karta majetku {$number} nepřevzata: " . $e->getMessage(), ['document_no' => $number]);
            return;
        }
        $assetId = (int) $created['asset']['id'];
        $this->map->put($ctx->supplierId, PremierImportRepository::KIND_ASSET, $key, $assetId, $ctx->runId);
        $p->count(self::STEP, $card['status'] === 'draft' ? 'drafts' : 'created');
        if ($review !== []) {
            $p->warn(self::STEP, 'asset_review', "Karta majetku {$number} převzata jako koncept ke kontrole: " . implode('; ', $review) . '.', ['document_no' => $number]);
        } else {
            $this->confirmYear($ctx, $assetId, $taxRows, $movements[$inter] ?? [], $number);
            if ($disposedInYear) {
                $this->disposeMigrated($ctx, $assetId, $disposal, $number);
            }
        }
    }

    /**
     * Karta vyřazená v převáděném roce: vyřazení zaúčtoval převedený deník, karta se proto
     * vyřadí bez zaúčtování a naváže na zápis vyřazení ({@see MigratedDisposal}). Nejde-li
     * to, zůstane koncept ke kontrole.
     */
    private function disposeMigrated(PremierContext $ctx, int $assetId, string $disposal, string $number): void
    {
        $result = $this->disposals->dispose($ctx->supplierId, $assetId, $disposal, null, $ctx->userOrNull());
        if ($result['disposed']) {
            $ctx->protocol->count(self::STEP, 'disposed_from_journal');
            if ($result['entry_id'] !== null) {
                $ctx->protocol->count(self::STEP, 'disposal_entry_linked');
            }
            return;
        }
        $note = 'majetek je v PREMIER vyřazený ' . $disposal . ' a vyřazení je v převedeném deníku, kartu se ale nepodařilo vyřadit ('
            . $result['message'] . '): vyřaďte ji bez zaúčtování';
        $this->disposals->toReview($ctx->supplierId, $assetId, 'Převod z PREMIER - ke kontrole: ' . $note . '.');
        $ctx->protocol->warn(self::STEP, 'asset_review', "Karta majetku {$number} převzata jako koncept ke kontrole: {$note}.", ['document_no' => $number]);
    }

    private function status(PremierContext $ctx, int $assetId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT status FROM assets WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$assetId, $ctx->supplierId]);
        $status = $stmt->fetchColumn();
        return $status === false ? null : (string) $status;
    }

    /**
     * Odpisy roku převodu přesně podle PREMIER: účetní (zaúčtované převzatým deníkem,
     * {@see DepreciationEntryRepository::MIGRATED_JOURNAL}) a daňový (potvrzený).
     *
     * @param list<array<string,mixed>> $taxRows
     * @param list<array<string,mixed>> $movements
     */
    private function confirmYear(PremierContext $ctx, int $assetId, array $taxRows, array $movements, string $number): void
    {
        $booked = 0.0;
        $months = [];
        foreach ($movements as $m) {
            $date = (string) ($m['DATUM'] ?? '');
            if ((int) ($m['KOD'] ?? 0) === self::MOVEMENT_BOOKED && self::yearOf($ctx, $date) === $ctx->year) {
                $booked += (float) ($m['CASTKA'] ?? 0);
                $months[substr($date, 0, 7)] = true;
            }
        }
        $booked = round($booked, 2);
        $depreciation = new MigratedDepreciation($this->entries, $this->db);
        // Účetní řádek roku, který už existuje (i převzatý dřívějším převodem), se nemění.
        if ($booked > 0.0 && $this->entries->findYear($assetId, 'accounting', $ctx->year) === null) {
            $residual = $this->db->pdo()->prepare('SELECT input_price - opening_acc_amount - COALESCE((SELECT SUM(amount) FROM depreciation_entries
                WHERE asset_id = a.id AND kind = \'accounting\'), 0) FROM assets a WHERE a.id = ? AND a.supplier_id = ?');
            $residual->execute([$assetId, $ctx->supplierId]);
            $result = $depreciation->confirm(
                $ctx->supplierId, $assetId, 'accounting', $ctx->year, $booked, $booked, round((float) $residual->fetchColumn() - $booked, 2),
                false, false, count($months), 'PREMIER', 'posted', MigratedDepreciation::OVERWRITE_ALWAYS, false,
            );
            if ($result['kept'] !== null) {
                MigratedDepreciation::reportKept($ctx->protocol, self::STEP, $number, 'accounting', $ctx->year, $booked, $result['kept'], 'PREMIER');
            } else {
                $ctx->protocol->count(self::STEP, 'accounting_depreciation_booked');
            }
        }
        $this->confirmTax($ctx, $assetId, $taxRows, $number, $depreciation);
    }

    /**
     * Daňový odpis roku převodu přesně podle PREMIER (potvrzený). Liší-li se od existujícího
     * řádku roku, přepíše ho, jen když řádek zapsal převod a rok není uzavřený
     * ({@see MigratedDepreciation::confirm()}); jinak ohlásí, že zůstal.
     */
    private function confirmTax(PremierContext $ctx, int $assetId, array $taxRows, string $number, MigratedDepreciation $depreciation): void
    {
        foreach ($taxRows as $t) {
            if (self::yearOf($ctx, $t['O_DATUM'] ?? null) !== $ctx->year) {
                continue;
            }
            $amount = round((float) ($t['O_ODPIS'] ?? 0), 2);
            $result = $depreciation->confirm(
                $ctx->supplierId, $assetId, 'tax', $ctx->year, $amount, $amount, round((float) ($t['O_ZUST2'] ?? 0), 2),
                abs($amount) < 0.005, false, null, 'PREMIER', 'confirmed', MigratedDepreciation::OVERWRITE_ALWAYS, false,
            );
            if (!$result['written']) {
                if ($result['kept'] !== null) {
                    MigratedDepreciation::reportKept($ctx->protocol, self::STEP, $number, 'tax', $ctx->year, $amount, $result['kept'], 'PREMIER');
                }
                return;
            }
            $existing = $result['previous'];
            $ctx->protocol->count(self::STEP, 'tax_depreciation_confirmed');
            if ($existing !== null) {
                $ctx->protocol->info(self::STEP, 'tax_depreciation_replaced', sprintf('Majetek %s: daňový odpis %d nastaven podle PREMIER (%s Kč místo %s Kč).',
                    $number, $ctx->year, number_format($amount, 2, ',', ' '), number_format((float) $existing['amount'], 2, ',', ' ')), ['document_no' => $number]);
            }
            return;
        }
    }

    /**
     * Počáteční stavy účetních odpisů: měsíce od zařazení do posledního zaúčtovaného
     * (PREMIER odpisuje od měsíce zařazení, MyÚčto od následujícího - viz převod z POHODY)
     * a jejich součet podle plánu.
     *
     * @param array<string,float> $monthPlan
     * @return array{0:int,1:float,2:?int} měsíce, částka, doba odpisování v měsících
     */
    private static function accountingOpening(string $inUse, array $monthPlan, ?string $booked): array
    {
        return MigratedDepreciation::accountingOpening($inUse, $monthPlan, $booked);
    }

    /**
     * Poslední měsíc (`Y-m`) do `$until`, jehož účetní odpis PREMIER zaúčtoval.
     *
     * @param list<array<string,mixed>> $movements
     */
    private static function bookedThrough(array $movements, string $until): ?string
    {
        $last = null;
        foreach ($movements as $m) {
            $date = (string) ($m['DATUM'] ?? '');
            if ((int) ($m['KOD'] ?? 0) === self::MOVEMENT_BOOKED && $date !== '' && $date <= $until) {
                $last = max($last ?? '', substr($date, 0, 7));
            }
        }
        return $last;
    }

    /**
     * @param list<array<string,mixed>> $rows `ODPISY_U` karty
     * @return array<string,float> `Y-m` → odpis
     */
    private static function monthPlan(array $rows): array
    {
        $plan = [];
        foreach ($rows as $r) {
            $month = substr((string) ($r['O_DATUM'] ?? ''), 0, 7);
            if ($month !== '') {
                $plan[$month] = ($plan[$month] ?? 0.0) + (float) ($r['O_ODPIS'] ?? 0);
            }
        }
        ksort($plan);
        return $plan;
    }


    /**
     * @param iterable<array<string,mixed>> $rows
     * @return array<string,list<array<string,mixed>>> INTER nebo ID karty => řádky
     */
    private static function byCard(iterable $rows, string $column): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[self::cardKey($row[$column] ?? '')][] = $row;
        }
        return $out;
    }

    /** Klíč karty stejně pro kartu i její řádky (číselné ID doplněné nulami = totéž číslo). */
    private static function cardKey(mixed $value): string
    {
        return is_numeric($value) ? (string) (int) $value : trim((string) $value);
    }

    private static function date(mixed $value): ?string
    {
        $v = (string) ($value ?? '');
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v : null;
    }

    /**
     * Účetní rok firmy pohybu nebo daňového odpisu. Daňový odpis právnické osoby patří do
     * zdaňovacího období, které je u ní shodné s účetním (hospodářský rok, § 21a ZDP).
     */
    private static function yearOf(PremierContext $ctx, mixed $date): int
    {
        $v = (string) ($date ?? '');
        return self::date(substr($v, 0, 10)) !== null ? $ctx->calendar->fiscalYearOfDate(substr($v, 0, 10)) : (int) substr($v, 0, 4);
    }
}

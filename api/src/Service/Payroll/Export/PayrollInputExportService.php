<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Export;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollInputFilter;
use MyInvoice\Repository\Payroll\PayrollInputRepository;
use MyInvoice\Service\Payroll\PayrollPersonDisplayName;
use MyInvoice\Service\Pdf\PayrollInputsPdfRenderer;
use PDO;

/**
 * Export mzdových vstupů podle AKTUÁLNÍHO filtru stránky do XLSX a PDF.
 *
 * Řádky jdou přes {@see PayrollInputFilter::where()}, tedy přes tentýž WHERE
 * jako výpis, souhrn i hromadné akce: export nesmí obsahovat jinou množinu,
 * než jakou uživatel právě vidí. Čtou se po dávkách, ať celý měsíc firmy
 * s pěti sty lidmi nesedí v paměti dvakrát.
 */
final class PayrollInputExportService
{
    public const CHUNK_SIZE = 1000;
    /**
     * Strop tabulky: PhpSpreadsheet drží sešit v paměti celý. Deset tisíc
     * řádků je zhruba 140 MB špičky, dvacet tisíc tedy s rezervou pod 512 MB.
     */
    public const XLSX_MAX_ROWS = 20000;
    /** Strop tiskové sestavy: nad ním je PDF nečitelné a mPDF pomalé. */
    public const PDF_MAX_ROWS = 5000;
    /** Rozsah bez jediného vstupu — pojmenovat ho měsícem by lhalo. */
    private const EMPTY_RANGE_LABEL = 'bez vstupů';
    private const EMPTY_RANGE_SLUG = 'bez-vstupu';

    public function __construct(
        private readonly PayrollInputRepository $inputs,
        private readonly PayrollInputXlsxExporter $xlsxExporter,
        private readonly PayrollInputsPdfRenderer $pdfRenderer,
        private readonly Connection $db,
    ) {}

    /**
     * @return array{bytes:string,filename:string,mime:string,row_count:int}
     */
    public function xlsx(int $supplierId, PayrollInputFilter $filter): array
    {
        $context = $this->context($supplierId, $filter);
        if ($context['row_count'] > self::XLSX_MAX_ROWS) {
            throw new PayrollInputExportTooLargeException(
                $context['row_count'],
                self::XLSX_MAX_ROWS,
                sprintf(
                    'Filtru odpovídá %s vstupů, export do Excelu zvládne nejvýše %s. Zužte filtr.',
                    number_format($context['row_count'], 0, ',', ' '),
                    number_format(self::XLSX_MAX_ROWS, 0, ',', ' '),
                ),
            );
        }

        return $this->xlsxExporter->export(
            $context,
            $this->rows($supplierId, $filter, self::XLSX_MAX_ROWS),
        );
    }

    /**
     * @return array{bytes:string,filename:string,mime:string,row_count:int}
     */
    public function pdf(int $supplierId, PayrollInputFilter $filter): array
    {
        $context = $this->context($supplierId, $filter);
        if ($context['row_count'] > self::PDF_MAX_ROWS) {
            throw new PayrollInputExportTooLargeException(
                $context['row_count'],
                self::PDF_MAX_ROWS,
                sprintf(
                    'Filtru odpovídá %s vstupů, tisková sestava zvládne nejvýše %s. Zužte filtr nebo použijte Excel.',
                    number_format($context['row_count'], 0, ',', ' '),
                    number_format(self::PDF_MAX_ROWS, 0, ',', ' '),
                ),
            );
        }
        $data = $this->pdfData($context, $this->rows($supplierId, $filter, self::PDF_MAX_ROWS));

        return [
            'bytes' => $this->pdfRenderer->render($data),
            'filename' => (string) $context['filename_pdf'],
            'mime' => 'application/pdf',
            'row_count' => $data['row_count'],
        ];
    }

    /**
     * Hlavička exportu: firma, období, filtr, počet a součet za celý filtr.
     *
     * @return array{entity:array{name:string,ico:?string,address:string},period_start:string,
     *   period_end:?string,period_label:string,
     *   filter_lines:list<array{label:string,value:string}>,row_count:int,
     *   amount_total_minor:int,exported_at:string,filename_xlsx:string,
     *   filename_pdf:string}
     */
    public function context(int $supplierId, PayrollInputFilter $filter): array
    {
        $summary = $this->inputs->summary($supplierId, $filter);
        // Období sestavy se odvozuje ze SKUTEČNÝCH řádků, ne z mezí filtru:
        // rozsah „vše" chodí jako 1990-01…2099-12, protože `period` je povinné,
        // a takový sentinel v hlavičce ani v názvu souboru nemá co dělat.
        $span = $filter->periodEnd === null
            ? null
            : $this->inputs->periodSpan($supplierId, $filter);

        return [
            'entity' => $this->entity($supplierId),
            'period_start' => $span['from'] ?? $filter->periodStart,
            'period_end' => $span === null ? null : $span['to'],
            'period_label' => self::periodLabel($filter, $span),
            'filter_lines' => PayrollInputExportFormatter::filterLines(
                $filter,
                $this->inputs->exportFilterLabels($supplierId, $filter),
            ),
            'row_count' => $summary['total'],
            'amount_total_minor' => $summary['amount_total_minor'],
            'exported_at' => (new \DateTimeImmutable())->format('d.m.Y H:i'),
            'filename_xlsx' => self::filename($filter, 'xlsx', $span),
            'filename_pdf' => self::filename($filter, 'pdf', $span),
        ];
    }

    /**
     * Řádky filtru po dávkách, nejvýše `$max`.
     *
     * @return \Generator<int,array<string,mixed>>
     */
    public function rows(int $supplierId, PayrollInputFilter $filter, int $max): \Generator
    {
        $offset = 0;
        while ($offset < $max) {
            $size = min(self::CHUNK_SIZE, $max - $offset);
            $page = $this->inputs->exportPage($supplierId, $filter, $size, $offset);
            foreach ($page as $row) {
                yield PayrollInputExportFormatter::row($row);
            }
            if (count($page) < $size) {
                return;
            }
            $offset += $size;
        }
    }

    /**
     * Sestava pro PDF: skupiny po zaměstnancích s mezisoučty a rekapitulace
     * podle složky. Součty se počítají z vypsaných řádků, ne ze souhrnu, aby
     * rekapitulace vždy seděla s tím, co je na papíře.
     *
     * @param array<string,mixed> $context
     * @param iterable<array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    public function pdfData(array $context, iterable $rows): array
    {
        $groups = [];
        $recap = [];
        $current = null;
        $count = 0;
        $totalMinor = 0;
        foreach ($rows as $row) {
            $count++;
            $amountMinor = (int) $row['amount_minor'];
            $totalMinor += $amountMinor;
            if ($current === null || $current['employee_id'] !== $row['employee_id']) {
                if ($current !== null) {
                    $groups[] = self::closeGroup($current);
                }
                $current = [
                    'employee_id' => $row['employee_id'],
                    'name' => $row['name'],
                    'personal_numbers' => [],
                    'rows' => [],
                    'amount_minor' => 0,
                ];
            }
            $current['personal_numbers'][$row['personal_number']] = true;
            $current['amount_minor'] += $amountMinor;
            $current['rows'][] = [
                'personal_number' => $row['personal_number'],
                'relation' => $row['relation'],
                'component_code' => $row['component_code'],
                'component_name' => $row['component_name'],
                'quantity' => trim(PayrollInputExportFormatter::quantityText($row['quantity']) . ' ' . $row['unit']),
                'rate' => $row['rate'] === null ? '' : PayrollInputExportFormatter::money((int) round($row['rate'] * 100)),
                'amount' => PayrollInputExportFormatter::money($amountMinor),
                'status' => $row['status'],
                'source' => $row['source'],
                'import' => $row['import'],
            ];
            $code = $row['component_code'];
            $recap[$code] ??= ['code' => $code, 'name' => $row['component_name'], 'count' => 0, 'amount_minor' => 0];
            $recap[$code]['count']++;
            $recap[$code]['amount_minor'] += $amountMinor;
        }
        if ($current !== null) {
            $groups[] = self::closeGroup($current);
        }
        // Zaměstnanci jako v přehledu mezd: „Příjmení Jméno“ podle české abecedy.
        foreach ($groups as &$group) {
            $group['name'] = PayrollPersonDisplayName::surnameFirst((string) $group['name']);
        }
        unset($group);
        usort($groups, static fn (array $a, array $b): int => PayrollPersonDisplayName::compare($a['name'], $b['name'])
            ?: strcmp((string) $a['personal_numbers'], (string) $b['personal_numbers']));
        ksort($recap, SORT_STRING);

        return [
            ...$context,
            'groups' => $groups,
            'recap' => array_map(
                static fn (array $item): array => [
                    ...$item,
                    'amount' => PayrollInputExportFormatter::money($item['amount_minor']),
                ],
                array_values($recap),
            ),
            'row_count' => $count,
            'total_minor' => $totalMinor,
            'total' => PayrollInputExportFormatter::money($totalMinor),
        ];
    }

    /**
     * @param array<string,mixed> $group
     * @return array<string,mixed>
     */
    private static function closeGroup(array $group): array
    {
        $group['personal_numbers'] = implode(', ', array_keys($group['personal_numbers']));
        $group['count'] = count($group['rows']);
        $group['amount'] = PayrollInputExportFormatter::money($group['amount_minor']);

        return $group;
    }

    /** @return array{name:string,ico:?string,address:string} */
    private function entity(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(NULLIF(TRIM(display_name), ""), company_name) AS name,
                    ic, street, city, zip
               FROM supplier
              WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $row = is_array($row) ? $row : [];
        $address = array_filter([
            trim((string) ($row['street'] ?? '')),
            trim(trim((string) ($row['zip'] ?? '')) . ' ' . trim((string) ($row['city'] ?? ''))),
        ], static fn (string $part): bool => $part !== '');
        $ico = trim((string) ($row['ic'] ?? ''));

        return [
            'name' => (string) ($row['name'] ?? ''),
            'ico' => $ico === '' ? null : $ico,
            'address' => implode(', ', $address),
        ];
    }

    /**
     * Popisek období sestavy.
     *
     * @param array{from:string,to:string}|null $span skutečné rozpětí řádků
     */
    private static function periodLabel(PayrollInputFilter $filter, ?array $span): string
    {
        if ($span !== null) {
            return PayrollInputExportFormatter::periodLabel($span['from'], $span['to']);
        }
        // Jediný měsíc je plnohodnotný údaj i bez řádků: sestava „za 06/2026,
        // žádný vstup" dává smysl. Prázdný ROZSAH ale žádné rozpětí nemá a meze
        // filtru u „vše" jsou technický sentinel (1990-01…2099-12), který by
        // tvrdil, že se hledalo v datech, jaká nikdy neexistovala.
        return $filter->periodEnd === null
            ? PayrollInputExportFormatter::periodLabel($filter->periodStart)
            : self::EMPTY_RANGE_LABEL;
    }

    /** @param array{from:string,to:string}|null $span skutečné rozpětí řádků */
    private static function filename(
        PayrollInputFilter $filter,
        string $extension,
        ?array $span,
    ): string {
        $slug = match (true) {
            $span !== null => PayrollInputExportFormatter::periodSlug($span['from'], $span['to']),
            $filter->periodEnd === null => PayrollInputExportFormatter::periodSlug($filter->periodStart),
            default => self::EMPTY_RANGE_SLUG,
        };

        return 'mzdove-vstupy-' . $slug . '.' . $extension;
    }
}

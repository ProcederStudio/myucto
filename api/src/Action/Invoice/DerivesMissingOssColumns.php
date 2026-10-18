<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Service\Oss\OssItemPlan;
use MyInvoice\Service\Oss\OssClientContext;

/**
 * OSS sloupce pro řádky, které je NEPOSLALY — tedy pro API klienty, ne pro editor.
 *
 * Vyžaduje na akci `$this->repo` (InvoiceRepository) a `$this->ossPlanner`
 * ({@see \MyInvoice\Service\Oss\OssItemPlanner}).
 *
 * ── Proč trait, a ne metoda v jedné akci ────────────────────────────────────────────
 * Ukládají DVĚ akce: {@see CreateInvoiceAction} (POST) a {@see UpdateInvoiceAction} (PUT).
 * Derivaci měl dlouho jen POST, a review to naměřila jako únik: integrátor, který doklad
 * nejdřív založí a pak PUTem opraví, dostal zpátky doklad BEZ OSS — `replaceItems()` je
 * DELETE + INSERT, takže payload bez `oss_*` klíčů OSS na dokladu tiše smazal. Dvě kopie
 * téhle metody by se rozešly znovu, a to tím tišším směrem: rozdíl je vidět až v přiznání.
 *
 * ── Proč se pozná API klient podle chybějícího klíče ────────────────────────────────
 * Editor od vlny 2 posílá OSS sloupce VŽDY, i když je uživatel odškrtl (nula je taky
 * odpověď); hlídá to {@see \MyInvoice\Tests\Architecture\InvoiceEditorOssPayloadContractTest},
 * protože `replaceItems()` je DELETE + INSERT a nezaslaný sloupec by se tiše vynuloval.
 * Chybějící klíč `oss_applicable` tedy spolehlivě znamená integraci (e-shop, vlastní
 * skript), která o OSS neví — ne uživatele, který OSS vědomě vypnul. Rozlišení podle
 * `array_key_exists()`, ne podle prázdné hodnoty: `oss_applicable = 0` je rozhodnutí
 * a přebít se nesmí. U PUT to platí stejně jako u POST — a je to tam DŮLEŽITĚJŠÍ, protože
 * uživatel v editoru OSS na položce vypíná právě posláním nuly.
 *
 * ── Odmítnutí NESMÍ shodit doklad ───────────────────────────────────────────────────
 * Invariant proti úniku cizí daně umí řádek ODMÍTNOUT (číselník členských států
 * nepotvrdil sazbu v zemi dodavatele). U importu ze souboru to doklad shodí, protože
 * zdroj pravdy je venku a import se po opravě zopakuje. Tady by to znamenalo, že
 * neproběhlá migrace 1152 začne e-shopu vracet 400 na KAŽDOU fakturu se sazbou vyšší
 * než 0 %, včetně ryze české — tedy regrese proti stavu, kdy tenhle kanál o OSS
 * nevěděl vůbec. Řádek proto zůstane mimo OSS, ale dostane příznak K RUČNÍMU
 * POSOUZENÍ ({@see OssItemPlan::manualReviewColumns()}) a hláška jde do `_warnings`
 * odpovědi. Táž úvaha jako u cronu opakovaných faktur.
 *
 * ── `vat_rate_id` zůstává ten, který poslal integrátor ──────────────────────────────
 * Přepárovat sazbu smí planner jen tam, kde ji volající nezná. Integrátor ji ale
 * poslal explicitně a validace ji už prohlédla, takže tiše ji vyměnit za jinou by
 * změnilo doklad, o kterém si volající myslí, že ho zadal celý. Výjimka je OSS řádek:
 * ten se musí navázat na sazbu STÁTU SPOTŘEBY (o tom volající nemohl vědět nic —
 * netušil ani, že řádek do OSS patří). Nenajde-li se taková sazba v `vat_rates`,
 * zůstane sazba volajícího: procento je totéž a `vat_rate_snapshot` se stejně počítá
 * z něj, takže je to pořád lepší doklad než řádek vyřazený z OSS.
 */
trait DerivesMissingOssColumns
{
    /**
     * @param  array<string,mixed> $body
     * @return list<array{item:int, manual_review:bool, message:string}> poznámky k řádkům
     *         pro `_warning_meta`; `item` je pořadové číslo položky od 1
     */
    private function deriveMissingOssColumns(array &$body, int $supplierId, ?OssClientContext $clientContext = null): array
    {
        $clientId = (int) ($body['client_id'] ?? 0);
        $items = $body['items'] ?? null;
        if (($clientId <= 0 && $clientContext === null) || !is_array($items) || $items === []) {
            return [];
        }
        // Přeindexovat hned: níž se zapisuje podle pořadového indexu, a payload z API
        // nemusí být list (JSON objekt s klíči „0", „2"). Bez toho by se odvozené sloupce
        // zapsaly vedle původní položky, ne do ní.
        $items = array_values($items);

        // Datum plnění s fallbackem na datum vystavení — týž fallback, jaký používá
        // klasifikace DPH níž. Deriver datum nehádá, kanonizaci nečitelného tvaru řeší
        // sám a odpoví „nevím", což skončí u ručního posouzení.
        $taxDate = (string) ($body['tax_date'] ?? $body['issue_date'] ?? '');
        $reverseCharge = !empty($body['reverse_charge']);
        $vatRates = $this->repo->vatRateMap();

        // Kontext odběratele JEDNOU za doklad — planner by si ho jinak vyžádal za každý
        // řádek a doklady z e-shopu chodí po stovkách.
        $client = $clientContext ?? $this->ossPlanner->clientContext($clientId);

        $notes = [];
        foreach ($items as $index => $item) {
            if (!is_array($item) || array_key_exists('oss_applicable', $item)) {
                continue;
            }

            $unit = trim((string) ($item['unit'] ?? ''));
            $plan = $this->ossPlanner->planIssuedItem(
                $supplierId,
                $client,
                (float) ($vatRates[(int) ($item['vat_rate_id'] ?? 0)] ?? 0.0),
                $unit !== '' ? $unit : null,
                $taxDate,
                $reverseCharge,
            );

            if ($plan->decision->isRejected()) {
                $items[$index] = $item + OssItemPlan::manualReviewColumns();
                $notes[] = [
                    'item' => $index + 1,
                    'manual_review' => true,
                    'message' => (string) $plan->decision->rejectionMessage,
                ];
                continue;
            }

            $item += $plan->decision->toItemColumns();
            if ($plan->decision->applicable && !$plan->isRejected()) {
                // Přepis, ne `+`: klíč `vat_rate_id` v položce už je, takže sjednocení polí
                // by sazbu státu spotřeby zahodilo.
                $item['vat_rate_id'] = (int) $plan->rate?->id;
            }
            $items[$index] = $item;

            $manualReview = $plan->decision->needsManualReview();
            foreach ($plan->decision->toReport()['warnings'] as $warning) {
                $notes[] = ['item' => $index + 1, 'manual_review' => $manualReview, 'message' => $warning];
            }
        }

        $body['items'] = $items;

        return $notes;
    }
}

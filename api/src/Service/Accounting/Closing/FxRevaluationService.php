<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

use DateTimeImmutable;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\ClosingRepository;
use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Service\Accounting\ForeignCurrencyAccountPolicy;
use MyInvoice\Service\Accounting\JournalLineAmount;
use MyInvoice\Service\Currency\CnbExchangeRateClient;

/**
 * Kurzové rozdíly k rozvahovému dni (Epic F4, R10 — §24 odst. 6+7 ZoÚ, ČÚS 006).
 *
 * (a) Saldokonto 311/321 — open-item metoda z dokladů: cizoměnové saldokontní
 *     řádky deníku × otevřenost dokladu k D; zbytek v cizí měně dle poměru úhrad;
 *     přepočet kurzem ČNB k D (cache-first, 7denní fallback).
 * (b) Banka/valutová pokladna — poloautomat: uživatelem potvrzené devizové
 *     zůstatky {account_code, currency_code, foreign_balance}; rozdíl proti
 *     účetnímu Kč zůstatku účtu z deníku.
 * (c) Peněžní účty 378/379 ({@see ForeignCurrencyAccountPolicy}) — automaticky
 *     z deníku: cizoměnový zůstatek řádků s měnou × kurz ČNB proti jejich Kč
 *     hodnotě; zapisuje se do slotu (b).
 *
 * Agregace: JEDEN pár řádků zápisu per (účet, měna, směr); rozpad per doklad
 * jde do payload kroku (audit + tisk podkladu). Strany (R10): zisk → MD účet /
 * D 663 (fx.gain), ztráta → MD 563 (fx.loss) / D účet — platí shodně pro
 * pohledávku (diff > 0 zisk) i závazek (diff < 0 zisk; diff se počítá
 * z ABSOLUTNÍ výše závazku v Kč). FX řádky saldokonta nesou currency_code,
 * fx_rate = kurz ČNB k D, amount_foreign = 0 (R20 — částka v cizí měně se
 * přeceněním nemění).
 */
final class FxRevaluationService
{
    private const FALLBACK_LOSS_ACCOUNT = '563';
    private const FALLBACK_GAIN_ACCOUNT = '663';

    public function __construct(
        private readonly ClosingRepository $repo,
        private readonly CnbExchangeRateClient $cnb,
        private readonly PostingRuleRepository $rules,
        private readonly ChartOfAccountsRepository $accounts,
    ) {}

    /**
     * Výpočet přecenění bez zápisu — podklad pro FE náhled i pro buildEntries.
     *
     * @param array<string,mixed> $period řádek accounting_periods (starts_on/ends_on/fiscal_year)
     * @param list<array{account_code:string, currency_code:string, foreign_balance:float|int|string}> $bankRows
     * @return array{
     *     rate_info: list<array{currency:string, rate:float, rate_date:string, fallback_used:bool}>,
     *     saldo: array{lines: list<array<string,mixed>>, detail: list<array<string,mixed>>},
     *     bank: array{lines: list<array<string,mixed>>},
     *     totals: array{loss: float, gain: float},
     *     warnings: list<array<string,mixed>>
     * }
     */
    public function preview(int $supplierId, array $period, array $bankRows = []): array
    {
        $endsOn = (string) $period['ends_on'];
        $rates = [];
        $warnings = [];
        $detail = [];
        $desiredGroups = [];

        foreach ($this->repo->openFxItems($supplierId, $endsOn) as $item) {
            // EP-17: nepeněžní zálohy (314 poskytnuté / 324 přijaté zálohy na budoucí
            // dodání zboží/služby) NEJSOU peněžní položka podle §4 odst. 12 ZoÚ / ČÚS 006 —
            // vypořádají se PLNĚNÍM, ne penězi — a k rozvahovému dni se kurzem
            // NEPŘEPOČÍTÁVAJÍ (na rozdíl od peněžních pohledávek/závazků 311/321 a
            // banky/valutové pokladny 211/221, které přeceňujeme dál beze změny).
            // Datový model nerozlišuje peněžní vs. nepeněžní zálohu jiným spolehlivým
            // příznakem než účtem (advance leg 314/324 se navíc účtuje CZK-only přes
            // bank/cash source a settlement leg bez cizoměnové stopy — do openFxItems
            // by měl vstoupit jen výjimečně, např. při ručně nastavené kontaci
            // saldokonta na 314/324), proto konzervativně vylučujeme celou účtovou
            // skupinu 314/324 z FX přecenění saldokonta.
            if (self::isNonMonetaryAdvanceAccount((string) $item['account_code'])) {
                continue;
            }

            $currency = (string) $item['currency_code'];
            $rateCnb = $this->rateFor($currency, $endsOn, $rates);

            $ratio = $this->repo->paidRatioBefore(
                $supplierId,
                (string) $item['doc_type'],
                (int) $item['doc_id'],
                $endsOn,
                (float) $item['total_with_vat'],
            );
            if ($ratio > 1.0) {
                // Jednotka úhrad nejistá (platba v jiné měně než doklad apod.) —
                // konzervativní fallback: přecenit celý doklad + warning (§8/6).
                $warnings[] = [
                    'key'      => 'fx_partial_payment_uncertain',
                    'doc_type' => $item['doc_type'],
                    'doc_id'   => $item['doc_id'],
                    'ratio'    => round($ratio, 4),
                ];
                $ratio = 0.0;
            }

            $remainingForeign = round((float) $item['amount_foreign'] * (1 - $ratio), 2);
            if (self::cents($remainingForeign) === 0) {
                continue;
            }
            $bookedCzk = round($remainingForeign * (float) $item['fx_rate'], 2);
            $newCzk = round($remainingForeign * $rateCnb, 2);
            $diff = round($newCzk - $bookedCzk, 2);

            // Požadovaná kumulativní změna carrying amount na saldokontním účtu:
            // pohledávka roste na MD, závazek roste na D.
            $isReceivable = $item['doc_type'] === 'invoice';
            $desiredSigned = $isReceivable ? $diff : -$diff;
            $direction = $desiredSigned > 0 ? 'gain' : 'loss';

            $detail[] = [
                'doc_type'          => $item['doc_type'],
                'doc_id'            => $item['doc_id'],
                'varsymbol'         => $item['varsymbol'],
                'account_code'      => $item['account_code'],
                'currency_code'     => $currency,
                'remaining_foreign' => $remainingForeign,
                'fx_rate'           => (float) $item['fx_rate'],
                'rate_cnb'          => $rateCnb,
                'booked_czk'        => $bookedCzk,
                'new_czk'           => $newCzk,
                'diff'              => $diff,
                'direction'         => self::cents($diff) === 0 ? null : $direction,
            ];

            if (self::cents($diff) === 0) {
                continue;
            }
            $key = $item['account_id'] . '|' . $currency;
            if (!isset($desiredGroups[$key])) {
                $desiredGroups[$key] = [
                    'account_id'    => (int) $item['account_id'],
                    'account_code'  => (string) $item['account_code'],
                    'currency_code' => $currency,
                    'desired_signed' => 0.0,
                ];
            }
            $desiredGroups[$key]['desired_signed'] = round(
                $desiredGroups[$key]['desired_signed'] + $desiredSigned,
                2,
            );
        }

        $groups = [];
        $currentSlot = ClosingSourceId::fxSaldo((int) $period['id']);
        $alreadyBookedByKey = [];
        foreach ($this->repo->fxCarryingAdjustments($supplierId, $endsOn, $currentSlot) as $adjustment) {
            $key = $adjustment['account_id'] . '|' . $adjustment['currency_code'];
            $alreadyBookedByKey[$key] = (float) $adjustment['amount'];
            $desiredGroups[$key] ??= [
                'account_id' => (int) $adjustment['account_id'],
                'account_code' => (string) $adjustment['account_code'],
                'currency_code' => (string) $adjustment['currency_code'],
                'desired_signed' => 0.0,
            ];
        }
        foreach ($desiredGroups as $key => $g) {
            $alreadyBooked = $alreadyBookedByKey[$key] ?? 0.0;
            $incremental = round((float) $g['desired_signed'] - $alreadyBooked, 2);
            if (self::cents($incremental) === 0) {
                continue;
            }
            $groups[] = [
                'account_id' => (int) $g['account_id'],
                'account_code' => (string) $g['account_code'],
                'currency_code' => (string) $g['currency_code'],
                'direction' => $incremental > 0 ? 'gain' : 'loss',
                'amount' => abs($incremental),
                'desired_signed' => (float) $g['desired_signed'],
                'already_booked' => $alreadyBooked,
            ];
        }

        // (b) banka / valutová pokladna — poloautomat z potvrzených řádků.
        // R10b: každý účet smí v bank_rows figurovat NEJVÝŠE JEDNOU. accountBalance()
        // níže načítá CELÝ Kč zůstatek účtu z deníku, takže dvakrát zadaný stejný
        // account_code by tentýž zůstatek přecenil dvakrát (dvojí zápis) — a při
        // dvou různých měnách navíc nekonzistentně (dva protichůdné diffy proti
        // jednomu zůstatku). Účet je poslední bod pravdy i pro vstupní cesty mimo
        // Action, proto duplicitu odmítáme tady doménovou výjimkou: buildEntries se
        // pak vůbec nespustí → ŽÁDNÝ zápis (idempotence R6, §24/6 ZoÚ).
        $bankLines = [];
        $seenBankAccounts = [];
        foreach ($bankRows as $row) {
            $currency = strtoupper(trim((string) $row['currency_code']));
            $accountCode = trim((string) $row['account_code']);
            if ($currency === '' || $currency === 'CZK' || $accountCode === '') {
                continue;
            }
            if (isset($seenBankAccounts[$accountCode])) {
                throw new ClosingException(
                    'fx_duplicate_bank_account',
                    'Účet ' . $accountCode . ' je v devizových zůstatcích (bank_rows) uveden vícekrát; '
                        . 'každý bankovní/valutový účet smí být přeceněn právě jednou.',
                );
            }
            // 378/379 se přeceňují sami z deníku (část c) — v bank_rows by se přecenili
            // podruhé a navíc z celého Kč zůstatku včetně korunových pohybů.
            if (ForeignCurrencyAccountPolicy::isLedgerRevalued($accountCode)) {
                throw new ClosingException(
                    'fx_ledger_account_in_bank_rows',
                    'Účet ' . $accountCode . ' se přeceňuje automaticky z deníku; do devizových zůstatků '
                        . 'banky a pokladny nepatří.',
                );
            }
            $seenBankAccounts[$accountCode] = true;
            $foreignBalance = round((float) $row['foreign_balance'], 2);
            $rateCnb = $this->rateFor($currency, $endsOn, $rates);
            // Zůstatek BEZ vlastního slot-2 zápisu — jinak by re-run kroku viděl
            // diff 0 a smazal zákonné přecenění (idempotence R6, §24/6 ZoÚ).
            $ledgerCzk = $this->repo->accountBalance(
                $supplierId,
                $accountCode,
                $endsOn,
                'fx_revaluation',
                ClosingSourceId::fxBank((int) $period['id']),
            );
            $newCzk = round($foreignBalance * $rateCnb, 2);
            $diff = round($newCzk - $ledgerCzk, 2);

            $bankLines[] = [
                'account_code'    => $accountCode,
                'currency_code'   => $currency,
                'foreign_balance' => $foreignBalance,
                'rate_cnb'        => $rateCnb,
                'ledger_czk'      => $ledgerCzk,
                'new_czk'         => $newCzk,
                'diff'            => $diff,
                // Banka/pokladna je aktivum: diff > 0 = zisk.
                'direction'       => self::cents($diff) === 0 ? null : ($diff > 0 ? 'gain' : 'loss'),
            ];
        }

        // (c) peněžní účty 378/379 — automaticky z deníku: cizoměnový zůstatek × kurz ČNB
        // proti Kč hodnotě řádků v téže měně. Zápis jde do slotu banky (stejná metoda:
        // přecenění zůstatku bez storna v novém roce), vlastní zápis slotu se z Kč hodnoty
        // vynechá kvůli re-runu.
        $ledgerLines = [];
        foreach ($this->repo->ledgerFxBalances($supplierId, $endsOn, ClosingSourceId::fxBank((int) $period['id'])) as $row) {
            $currency = (string) $row['currency_code'];
            $rateCnb = $this->rateFor($currency, $endsOn, $rates);
            $newCzk = round((float) $row['foreign_balance'] * $rateCnb, 2);
            $diff = round($newCzk - (float) $row['czk_balance'], 2);
            $ledgerLines[] = [
                'account_code'    => (string) $row['account_code'],
                'label'           => (string) $row['label'],
                'currency_code'   => $currency,
                'foreign_balance' => (float) $row['foreign_balance'],
                'rate_cnb'        => $rateCnb,
                'ledger_czk'      => (float) $row['czk_balance'],
                'new_czk'         => $newCzk,
                'diff'            => $diff,
                // Zůstatky jsou MD-kladné (pohledávka i závazek): diff > 0 = MD účtu = zisk.
                'direction'       => self::cents($diff) === 0 ? null : ($diff > 0 ? 'gain' : 'loss'),
            ];
        }

        $loss = 0.0;
        $gain = 0.0;
        foreach ($groups as $g) {
            $g['direction'] === 'gain' ? $gain += $g['amount'] : $loss += $g['amount'];
        }
        foreach ([...$bankLines, ...$ledgerLines] as $b) {
            if ($b['direction'] === 'gain') {
                $gain += abs($b['diff']);
            } elseif ($b['direction'] === 'loss') {
                $loss += abs($b['diff']);
            }
        }

        return [
            'rate_info' => array_values($rates),
            'saldo'     => ['lines' => array_values($groups), 'detail' => $detail],
            'bank'      => ['lines' => $bankLines],
            'ledger'    => ['lines' => $ledgerLines],
            'totals'    => ['loss' => round($loss, 2), 'gain' => round($gain, 2)],
            'warnings'  => $warnings,
        ];
    }

    /**
     * Řádky zápisů pro PostingService — slot 1 (saldo) a slot 2 (banka/pokladna).
     * Prázdná sada → zápis se nezakládá (a případný existující ClosingService
     * maže — re-run). Řádky ve formátu postDocument (account_code, side, amount);
     * saldokontní řádek účtu nese FX stopu (R20).
     *
     * @param array<string,mixed> $preview výstup preview()
     * @return array{saldo: list<array<string,mixed>>, bank: list<array<string,mixed>>}
     */
    public function buildEntries(int $supplierId, array $preview): array
    {
        [$lossAccount, $gainAccount] = $this->resultAccounts($supplierId);

        $saldo = [];
        foreach ($preview['saldo']['lines'] as $g) {
            $amount = round((float) $g['amount'], 2);
            if (self::cents($amount) === 0) {
                continue;
            }
            $trace = [
                'currency_code'  => (string) $g['currency_code'],
                'fx_rate'        => $this->rateFromInfo($preview, (string) $g['currency_code']),
                'amount_foreign' => 0.0,
            ];
            if ($g['direction'] === 'gain') {
                $saldo[] = ['account_code' => (string) $g['account_code'], 'side' => 'debit', 'amount' => $amount] + $trace;
                $saldo[] = ['account_code' => $gainAccount, 'side' => 'credit', 'amount' => $amount];
            } else {
                $saldo[] = ['account_code' => $lossAccount, 'side' => 'debit', 'amount' => $amount];
                $saldo[] = ['account_code' => (string) $g['account_code'], 'side' => 'credit', 'amount' => $amount] + $trace;
            }
        }

        $bank = [];
        foreach ([...$preview['bank']['lines'], ...($preview['ledger']['lines'] ?? [])] as $b) {
            $amount = round(abs((float) $b['diff']), 2);
            if (self::cents($amount) === 0) {
                continue;
            }
            // FX stopa na řádku účtu banky/valutové pokladny (211/221): currency_code +
            // fx_rate, amount_foreign = 0 (R20 — přeceněním se cizoměnová částka nemění).
            // BEZ ní by přeceňovací řádek zůstal jako CZK-only pohyb a účet by od 2. období
            // vypadl z ClosingRepository::bankProposals (currency_code IS NULL) → přecenění
            // by se příště tiše nenabídlo. Result účet (563/663) FX stopu nenese.
            $trace = [
                'currency_code'  => (string) $b['currency_code'],
                'fx_rate'        => isset($b['rate_cnb']) ? (float) $b['rate_cnb'] : null,
                'amount_foreign' => 0.0,
            ];
            if ($b['direction'] === 'gain') {
                $bank[] = ['account_code' => (string) $b['account_code'], 'side' => 'debit', 'amount' => $amount] + $trace;
                $bank[] = ['account_code' => $gainAccount, 'side' => 'credit', 'amount' => $amount];
            } else {
                $bank[] = ['account_code' => $lossAccount, 'side' => 'debit', 'amount' => $amount];
                $bank[] = ['account_code' => (string) $b['account_code'], 'side' => 'credit', 'amount' => $amount] + $trace;
            }
        }

        return ['saldo' => $saldo, 'bank' => $bank];
    }

    /**
     * Zrcadlo saldokontní části pro FX storno k 1. dni nového období (R11,
     * slot 3): prohozené strany, stejné částky a FX stopa.
     *
     * @param list<array<string,mixed>> $saldoLines řádky slotu 1 (formát buildEntries)
     * @return list<array<string,mixed>>
     */
    public function buildReversal(array $saldoLines): array
    {
        return array_map(JournalLineAmount::reversal(...), $saldoLines);
    }

    /**
     * Účty 563/663 z kontací fx.loss/fx.gain (seedy 1006), fallback 563/663.
     *
     * @return array{0: string, 1: string} [loss, gain]
     */
    private function resultAccounts(int $supplierId): array
    {
        $lossRule = $this->rules->resolve($supplierId, 'fx.loss');
        $gainRule = $this->rules->resolve($supplierId, 'fx.gain');
        $loss = (string) (($lossRule['debit_account_code'] ?? null) ?: self::FALLBACK_LOSS_ACCOUNT);
        $gain = (string) (($gainRule['credit_account_code'] ?? null) ?: self::FALLBACK_GAIN_ACCOUNT);
        return [$loss, $gain];
    }

    /**
     * Kurz ČNB k rozvahovému dni (cache-first + 7denní fallback); memoizace per
     * měna do $rates (jde 1:1 do rate_info). Nedostupný kurz → ClosingException.
     *
     * @param array<string,array{currency:string, rate:float, rate_date:string, fallback_used:bool}> $rates
     */
    private function rateFor(string $currency, string $asOf, array &$rates): float
    {
        if (isset($rates[$currency])) {
            return $rates[$currency]['rate'];
        }
        $info = $this->cnb->getRate($currency, new DateTimeImmutable($asOf));
        if ($info === null) {
            throw new ClosingException(
                'fx_rate_unavailable',
                'Kurz ČNB pro měnu ' . $currency . ' k ' . $asOf . ' není k dispozici.',
            );
        }
        // §24/6/b ZoÚ: kurz musí být vyhlášený K rozvahovému dni — last_known
        // fallback klienta může vrátit kurz s datem PO něm (starší rok, prázdná
        // cache); zpětný fallback je v pořádku, dopředný ne.
        if ((string) $info['rate_date'] > $asOf) {
            throw new ClosingException(
                'fx_rate_unavailable',
                'Kurz ČNB pro měnu ' . $currency . ' k ' . $asOf . ' není k dispozici '
                    . '(nejbližší známý kurz je až z ' . $info['rate_date'] . ' — doplňte kurzy ČNB).',
            );
        }
        $rates[$currency] = [
            'currency'      => $currency,
            'rate'          => (float) $info['rate'],
            'rate_date'     => (string) $info['rate_date'],
            'fallback_used' => (bool) $info['fallback_used'],
        ];
        return $rates[$currency]['rate'];
    }

    /**
     * @param array<string,mixed> $preview
     */
    private function rateFromInfo(array $preview, string $currency): ?float
    {
        foreach ($preview['rate_info'] as $info) {
            if ($info['currency'] === $currency) {
                return (float) $info['rate'];
            }
        }
        return null;
    }

    /**
     * Nepeněžní záloha (§4/12 ZoÚ, ČÚS 006): 314 poskytnutá / 324 přijatá záloha na
     * budoucí plnění se vypořádá dodáním, ne penězi, a k rozvahovému dni se kurzem
     * NEPŘECEŇUJE. Prefixový match kryje i analytiky (3141x, 3241x) — stejný vzor
     * jako guard účtů nákladu v PostingService.
     */
    private static function isNonMonetaryAdvanceAccount(string $accountCode): bool
    {
        $code = trim($accountCode);
        return str_starts_with($code, '314') || str_starts_with($code, '324');
    }

    /** Peníze → haléře (int) — porovnání nikdy přes float ==. */
    private static function cents(float $amount): int
    {
        return (int) round($amount * 100.0);
    }
}

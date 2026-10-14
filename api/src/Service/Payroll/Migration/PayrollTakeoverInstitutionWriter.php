<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Migration;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollInstitutionAccountRepository;

/**
 * Účty příjemců odvodů (zdravotní pojišťovny, ČSSZ, finanční úřad) převzaté z předchozího
 * mzdového systému, jednou za firmu.
 *
 * Účet, který zdroj nese z registru instituce (`health_insurer`), je sdělení instituce
 * (`institution_notice`) a platební cesta ho uznává. Účet ČSSZ a finančního úřadu zdroj
 * typicky nenese a čtečka ho odvozuje z vystavených závazků, což je doklad o tom, kam
 * předchozí systém platil, ne rozhodnutí úřadu. Takový účet se proto zakládá s původem
 * `imported`: platební cesta ho odmítne
 * ({@see \MyInvoice\Service\Payroll\Payment\PayrollPaymentBatchBuilder}), dokud ho
 * účetní neporovná s výměrem a neuloží znovu. Radši nepoužitelný účet než tiše
 * špatně nasměrovaná platba odvodů.
 *
 * Příjemce, pro kterého se účet nenašel, se NEZAKLÁDÁ ani jako holá identita: účet je
 * v evidenci povinný, instituce bez něj se nikde neukáže a jediné, co by přinesla, je
 * dojem, že je vyřízená. Místo toho jde do `$state->institutionGaps`, co přesně má
 * účetní doplnit.
 *
 * Příjemce ze zdroje: `type` (`health_insurer`, `social_security`, `tax_office`), `code`,
 * `name`, `account`, `bank_code`, `variable_symbol`, `data_box`, `source` (odkud byl účet
 * odvozen), `issue` (`ambiguous`, když se nedal vybrat) a `candidates`.
 */
final class PayrollTakeoverInstitutionWriter
{
    /** Kód banky ČNB; odvody státu chodí jen na její účty. */
    public const CNB_BANK_CODE = '0710';

    /**
     * Předčíslí účtu u ČNB => instituce MyÚčta, kód účtu a název. Zdroje, které účet ČSSZ
     * a finančního úřadu nenesou v registru institucí, ho poznají podle předčíslí.
     * Kód účtu finančního úřadu je DRUH DANĚ, ne značka úřadu - každý druh má vlastní
     * předčíslí a platební cesta pod ním účet hledá.
     */
    public const LEVY_ACCOUNTS = [
        '21012' => ['social_security', null, 'Správa sociálního zabezpečení'],
        '713' => ['tax_office', 'ADVANCE_TAX', 'Finanční úřad - záloha na daň ze závislé činnosti'],
        '7720' => ['tax_office', 'WITHHOLDING_TAX', 'Finanční úřad - daň vybíraná srážkou'],
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollInstitutionAccountRepository $institutions,
    ) {}

    /**
     * @param list<array<string,mixed>> $institutions
     * @param string $missingAccountDetail vysvětlení do protokolu, proč zdroj účet nenese
     * @return array<string,int>
     */
    public function institutionAccounts(int $supplierId, array $institutions, int $year, ?int $userId, PayrollTakeoverPolicy $policy, PayrollTakeoverRunState $state, string $missingAccountDetail = ''): array
    {
        $known = [];
        foreach ($this->institutions->list($supplierId) as $account) {
            $known[(string) ($account['institution_type'] ?? '') . '|' . (string) ($account['institution_code'] ?? '')] = true;
        }
        $officeCode = $this->socialSecurityOfficeCode($supplierId);
        $taxVariableSymbol = $this->taxPayerVariableSymbol($supplierId);
        $written = 0;
        foreach ($institutions as $institution) {
            $type = (string) ($institution['type'] ?? 'health_insurer');
            // Kód pracoviště ČSSZ je NAŠE klasifikace platebního cíle: příprava plateb hledá
            // účet pod kódem z nastavení zaměstnavatele, takže ten má přednost před kódem
            // z podání zdroje. Bez obou se účet nedá dohledat a zakládat ho nemá smysl.
            $code = $type === 'social_security'
                ? ($officeCode ?? PayrollTakeoverFormat::text($institution['code'] ?? null))
                : PayrollTakeoverFormat::text($institution['code'] ?? null);
            if ($code === null) {
                $state->institutionGaps[] = 'Správa sociálního zabezpečení: kód pracoviště není ani v nastavení '
                    . 'zaměstnavatele, ani v podáních ' . $policy->label;
                continue;
            }
            $key = $type . '|' . $code;
            if (isset($known[$key])) {
                continue;
            }
            $account = PayrollTakeoverFormat::text($institution['account'] ?? null);
            $bankCode = PayrollTakeoverFormat::text($institution['bank_code'] ?? null);
            if ($account === null || $bankCode === null) {
                $state->institutionGaps[] = self::institutionGap($institution, $code, $policy->label, $missingAccountDetail);
                continue;
            }
            $notice = $type === 'health_insurer';
            // Účet, na který předchozí program opakovaně odváděl (víc platebních dokladů),
            // je ověřený skutečnými platbami stejně jako výplatní účet zaměstnance.
            $source = PayrollTakeoverFormat::text($institution['source'] ?? null) ?? '';
            $paidRepeatedly = !$notice && preg_match('/\((\d+) doklad/u', $source, $paid) === 1 && (int) $paid[1] >= 2;
            // Variabilní symbol u finančního úřadu je kmenová část DIČ plátce, ne symbol
            // z dokladu: doklad může nést symbol opravný nebo cizí.
            $variableSymbol = $type === 'tax_office'
                ? $taxVariableSymbol
                : PayrollTakeoverFormat::text($institution['variable_symbol'] ?? null);
            if ($type === 'tax_office' && $variableSymbol === null) {
                $state->institutionGaps[] = sprintf(
                    'Finanční úřad (%s): variabilní symbol zůstal prázdný, firma nemá vyplněné DIČ',
                    $code,
                );
            }
            $this->institutions->create($supplierId, [
                'institution_type' => $type,
                'institution_code' => $code,
                'institution_name' => self::institutionName($institution, $type, $code),
                'bank_account' => $account . '/' . $bankCode,
                'currency_code' => 'CZK',
                'variable_symbol' => $variableSymbol,
                'specific_symbol' => null,
                'constant_symbol' => null,
                'valid_from' => sprintf('%04d-01-01', $year),
                'valid_to' => null,
                'source_kind' => $notice ? 'institution_notice' : ($paidRepeatedly ? 'user_verified' : 'imported'),
                'source_reference' => $notice
                    ? 'Převzato z registru ' . $policy->label . (($institution['data_box'] ?? null) !== null ? ', datová schránka ' . $institution['data_box'] : '')
                    : mb_substr('Odvozeno z ' . (PayrollTakeoverFormat::text($institution['source'] ?? null) ?? 'dokladů ' . $policy->label)
                        . ($paidRepeatedly ? '; ověřeno platbami, které na účet odcházely' : '; nepotvrzeno účetní'), 0, 500),
                'verified_on' => date('Y-m-d'),
            ], $userId);
            $known[$key] = true;
            $written++;
            if (!$notice && !$paidRepeatedly) {
                $state->institutionsToConfirm++;
            }
        }
        return $written > 0 ? ['institution_accounts' => $written] : [];
    }

    /**
     * Proč se pro příjemce nezaložil účet - věta do protokolu, ne kód chyby.
     *
     * @param array<string,mixed> $institution
     */
    private static function institutionGap(array $institution, string $code, string $label, string $missingAccountDetail): string
    {
        $name = PayrollTakeoverFormat::text($institution['name'] ?? null) ?? $code;
        return match ($institution['issue'] ?? null) {
            'ambiguous' => sprintf(
                '%s: v závazcích %s je pod stejným předčíslím %d různých účtů, převod mezi nimi nevybírá',
                $name,
                $label,
                (int) ($institution['candidates'] ?? 0),
            ),
            default => sprintf('%s: export %s číslo účtu nenese%s', $name, $label, $missingAccountDetail),
        };
    }

    /**
     * @param array<string,mixed> $institution
     */
    private static function institutionName(array $institution, string $type, string $code): string
    {
        $name = PayrollTakeoverFormat::text($institution['name'] ?? null);
        if ($name !== null) {
            return mb_substr($name, 0, 190);
        }
        return $type === 'health_insurer' ? 'Zdravotní pojišťovna ' . $code : $code;
    }

    /** Kód pracoviště ČSSZ z nastavení zaměstnavatele; pod ním hledá účet příprava plateb. */
    private function socialSecurityOfficeCode(int $supplierId): ?string
    {
        if (!$this->db->hasTable('payroll_employer_settings')) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare('SELECT social_security_office_code FROM payroll_employer_settings WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        $value = $stmt->fetchColumn();
        $value = is_string($value) ? strtoupper(trim($value)) : '';
        return preg_match('/^[A-Z0-9][A-Z0-9._-]{0,31}$/D', $value) === 1 ? $value : null;
    }

    /** Kmenová část DIČ firmy („CZ12345678" => „12345678"); VS odvodů finančnímu úřadu. */
    private function taxPayerVariableSymbol(int $supplierId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT dic FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $value = $stmt->fetchColumn();
        $digits = is_string($value) ? (string) preg_replace('/\D/', '', $value) : '';
        return preg_match('/^[0-9]{1,10}$/D', $digits) === 1 ? $digits : null;
    }
}

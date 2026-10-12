<?php

declare(strict_types=1);

namespace MyInvoice\Service\Epo\Isds;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Submission\SubmissionRecipientRepository;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use PDO;

/**
 * Do které datové schránky jde které daňové podání.
 *
 * ── Výchozí cíl ─────────────────────────────────────────────────────────────
 * Podání se činí u místně příslušného správce daně (§ 73 odst. 1 DŘ). Ten je
 * v XML už zapsaný: `c_ufo_cil` (FÚ, kam je podání směrováno), jinak `c_ufo`
 * z věty P. Výchozí schránka se proto odvozuje z kódu FÚ v ODESÍLANÉM XML,
 * ne z nastavení firmy — jinak by šlo přiznání adresované jednomu úřadu poslat
 * do schránky jiného. Nastavení firmy (`financial_office_code`) slouží jen
 * k náhledu cíle, než nějaké XML existuje.
 *
 * ── Ruční změna ─────────────────────────────────────────────────────────────
 * Firma smí cíl přepsat zvlášť pro DPH, KH, SH a daň z příjmů (např. vybraný
 * subjekt pod Specializovaným FÚ jen pro DPH). Přepis smí mířit jen na
 * příjemce z číselníku, který firma vidí; když se jeho kód FÚ liší od XML,
 * odeslání to řekne nahlas ({@see self::mismatch()}).
 */
final readonly class TaxIsdsRouting
{
    public const GROUP_VAT = 'vat';
    public const GROUP_KH = 'kh';
    public const GROUP_SH = 'sh';
    public const GROUP_INCOME = 'income';

    /** Výkazy, které Finanční správa přijímá datovou schránkou ve formátu EPO XML. */
    public const FORM_GROUPS = [
        'dphdp3' => self::GROUP_VAT,
        'dphkh1' => self::GROUP_KH,
        'dphshv' => self::GROUP_SH,
        'dppdp9' => self::GROUP_INCOME,
        'dpfdp5' => self::GROUP_INCOME,
        'dpfdp7' => self::GROUP_INCOME,
    ];

    private const GROUP_COLUMNS = [
        self::GROUP_VAT => 'isds_recipient_vat_id',
        self::GROUP_KH => 'isds_recipient_kh_id',
        self::GROUP_SH => 'isds_recipient_sh_id',
        self::GROUP_INCOME => 'isds_recipient_income_id',
    ];

    /** Kódy krajských FÚ a Specializovaného FÚ z EPO číselníku `ufo`. */
    private const OFFICE_CODES = [
        '451', '452', '453', '454', '455', '456', '457', '458', '459', '460',
        '461', '462', '463', '464', '13',
    ];

    public function __construct(
        private Connection $db,
        private SubmissionRecipientRepository $recipients,
    ) {}

    public static function supports(string $formCode): bool
    {
        return isset(self::FORM_GROUPS[strtolower($formCode)]);
    }

    public static function groupFor(string $formCode): string
    {
        $group = self::FORM_GROUPS[strtolower($formCode)] ?? null;
        if ($group === null) {
            throw new SubmissionChannelException(
                'tax_isds_unsupported_form',
                'Tento výkaz Finanční správa datovou schránkou nepřijímá. Podejte ho přes EPO.',
                422,
            );
        }
        return $group;
    }

    /** Kód příjemce v číselníku pro kód finančního úřadu, nebo null u neznámého kódu. */
    public static function recipientCodeFor(?string $officeCode): ?string
    {
        $officeCode = ltrim(trim((string) $officeCode), '0');
        return in_array($officeCode, self::OFFICE_CODES, true) ? 'fu_' . $officeCode : null;
    }

    /**
     * Kód FÚ, kterému je XML adresované: `c_ufo_cil`, jinak `c_ufo`.
     * Neparsovatelné XML vrací null — odeslání ho stejně zastaví XSD brána.
     */
    public static function officeCodeFromXml(string $xml): ?string
    {
        if ($xml === '' || preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $xml) === 1) {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument();
            if (!$document->loadXML($xml, LIBXML_NONET)) {
                return null;
            }
            $xpath = new \DOMXPath($document);
            foreach (['c_ufo_cil', 'c_ufo'] as $attribute) {
                $nodes = $xpath->query('//@' . $attribute);
                if ($nodes !== false && $nodes->length > 0) {
                    $value = trim((string) $nodes->item(0)?->nodeValue);
                    if ($value !== '') {
                        return $value;
                    }
                }
            }
            return null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * Nastavení směrování pro obrazovku: pro každý druh výkazu výchozí FÚ,
     * případný ruční přepis a výsledný cíl.
     *
     * @return array{
     *   office_code:?string,
     *   groups:array<string,array{default:?array<string,mixed>,override:?array<string,mixed>,effective:?array<string,mixed>}>,
     *   recipients:list<array<string,mixed>>
     * }
     */
    public function routes(int $supplierId): array
    {
        $officeCode = $this->supplierOfficeCode($supplierId);
        $default = $this->defaultRecipient($supplierId, $officeCode);
        $overrides = $this->overrides($supplierId);

        $groups = [];
        foreach (array_keys(self::GROUP_COLUMNS) as $group) {
            $override = $overrides[$group] !== null
                ? $this->recipients->findVisible($supplierId, $overrides[$group])
                : null;
            $groups[$group] = [
                'default' => $default !== null ? self::publicRecipient($default) : null,
                'override' => $override !== null ? self::publicRecipient($override) : null,
                'effective' => $override !== null
                    ? self::publicRecipient($override)
                    : ($default !== null ? self::publicRecipient($default) : null),
            ];
        }

        return [
            'office_code' => $officeCode,
            'groups' => $groups,
            'recipients' => array_values(array_map(
                self::publicRecipient(...),
                array_filter(
                    $this->recipients->listVisible($supplierId),
                    static fn (array $row): bool => (bool) $row['is_active']
                        && $row['isds_box_id'] !== null
                        && in_array((string) $row['kind'], ['tax_office', 'other'], true),
                ),
            )),
        ];
    }

    /**
     * Uloží ruční přepisy. Chybějící klíč skupiny nechává uloženou hodnotu,
     * `null` vrací skupinu na výchozí FÚ.
     *
     * @param array<string,mixed> $input skupina → ID příjemce nebo null
     */
    public function saveRoutes(int $supplierId, array $input, ?int $userId): void
    {
        $current = $this->overrides($supplierId);
        foreach (self::GROUP_COLUMNS as $group => $column) {
            if (!array_key_exists($group, $input)) {
                continue;
            }
            $value = $input[$group];
            if ($value === null || $value === '' || (int) $value === 0) {
                $current[$group] = null;
                continue;
            }
            $recipient = $this->recipients->findVisible($supplierId, (int) $value);
            if ($recipient === null
                || !(bool) $recipient['is_active']
                || $recipient['isds_box_id'] === null
                || !in_array((string) $recipient['kind'], ['tax_office', 'other'], true)
            ) {
                throw new SubmissionChannelException(
                    'tax_isds_recipient_invalid',
                    'Vybraný příjemce není aktivní finanční úřad s datovou schránkou.',
                    422,
                );
            }
            $current[$group] = (int) $recipient['id'];
        }

        $this->db->pdo()->prepare(
            'INSERT INTO tax_submission_settings
                (supplier_id, isds_recipient_vat_id, isds_recipient_kh_id,
                 isds_recipient_sh_id, isds_recipient_income_id, updated_by)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                isds_recipient_vat_id = VALUES(isds_recipient_vat_id),
                isds_recipient_kh_id = VALUES(isds_recipient_kh_id),
                isds_recipient_sh_id = VALUES(isds_recipient_sh_id),
                isds_recipient_income_id = VALUES(isds_recipient_income_id),
                updated_by = VALUES(updated_by)'
        )->execute([
            $supplierId,
            $current[self::GROUP_VAT],
            $current[self::GROUP_KH],
            $current[self::GROUP_SH],
            $current[self::GROUP_INCOME],
            $userId,
        ]);
    }

    /**
     * Příjemce pro konkrétní snapshot podání.
     *
     * @param array<string,mixed> $submission řádek `tax_submissions` včetně `xml_content`
     * @return array{recipient:array<string,mixed>,source:string,xml_office_code:?string,mismatch:bool}
     */
    public function resolve(int $supplierId, array $submission): array
    {
        $group = self::groupFor((string) $submission['form_code']);
        $xmlOfficeCode = self::officeCodeFromXml((string) ($submission['xml_content'] ?? ''));
        $overrideId = $this->overrides($supplierId)[$group];

        if ($overrideId !== null) {
            $recipient = $this->recipients->findVisible($supplierId, $overrideId);
            $source = 'override';
        } else {
            $recipient = $this->defaultRecipient(
                $supplierId,
                $xmlOfficeCode ?? $this->supplierOfficeCode($supplierId),
            );
            $source = 'office_code';
        }

        if ($recipient === null) {
            throw new SubmissionChannelException(
                'tax_isds_recipient_missing',
                'Pro tento výkaz není určená datová schránka finančního úřadu. Doplňte kód'
                    . ' finančního úřadu v nastavení firmy nebo vyberte schránku v nastavení podání.',
                409,
            );
        }
        if (!(bool) $recipient['is_active'] || $recipient['isds_box_id'] === null) {
            throw new SubmissionChannelException(
                'tax_isds_recipient_unusable',
                'Vybraný příjemce v číselníku nemá aktivní ID datové schránky.',
                409,
            );
        }

        return [
            'recipient' => $recipient,
            'source' => $source,
            'xml_office_code' => $xmlOfficeCode,
            'mismatch' => self::mismatch($recipient, $xmlOfficeCode),
        ];
    }

    /**
     * Míří zvolená schránka na jiný FÚ, než kterému je XML adresované?
     * U vlastního příjemce firmy (kód mimo `fu_*`) to poznat nejde a nehlásí se.
     *
     * @param array<string,mixed> $recipient
     */
    public static function mismatch(array $recipient, ?string $xmlOfficeCode): bool
    {
        $expected = self::recipientCodeFor($xmlOfficeCode);
        $code = (string) $recipient['code'];
        if ($expected === null || !str_starts_with($code, 'fu_')) {
            return false;
        }
        return $code !== $expected;
    }

    /** @return array<string,?int> */
    private function overrides(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT isds_recipient_vat_id, isds_recipient_kh_id,
                    isds_recipient_sh_id, isds_recipient_income_id
               FROM tax_submission_settings WHERE supplier_id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach (self::GROUP_COLUMNS as $group => $column) {
            $out[$group] = isset($row[$column]) && $row[$column] !== null ? (int) $row[$column] : null;
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    private function defaultRecipient(int $supplierId, ?string $officeCode): ?array
    {
        $code = self::recipientCodeFor($officeCode);
        return $code !== null ? $this->recipients->findVisibleByCode($supplierId, $code) : null;
    }

    private function supplierOfficeCode(int $supplierId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT financial_office_code FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $value = $stmt->fetchColumn();
        $value = is_string($value) ? trim($value) : '';
        return $value !== '' ? $value : null;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{id:int,code:string,name:string,kind:string,isds_box_id:?string,address:?string,own:bool}
     */
    private static function publicRecipient(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'kind' => (string) $row['kind'],
            'isds_box_id' => $row['isds_box_id'] !== null ? (string) $row['isds_box_id'] : null,
            'address' => isset($row['address']) && $row['address'] !== null ? (string) $row['address'] : null,
            'own' => $row['supplier_id'] !== null,
        ];
    }
}

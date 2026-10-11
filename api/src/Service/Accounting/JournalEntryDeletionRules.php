<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

/**
 * Kdy smí zápis z deníku zmizet bez protizápisu — jediné místo pravidla pro ruční
 * mazání v deníku ({@see \MyInvoice\Action\Accounting\JournalAction::delete()},
 * {@see \MyInvoice\Action\Accounting\JournalAction::deleteReversalPair()}) i pro
 * doklady, které odklízejí svůj zápis samy ({@see DocumentJournalPurge}).
 *
 * Řádek zápisu musí nést `period_status`, `entry_date`, `reversed_by` a pro
 * {@see blockSingle()} i `is_reversal`.
 */
final class JournalEntryDeletionRules
{
    /**
     * `vat_clearing` (Zúčtování DPH) je dopočtená veličina, kterou služba přepisuje a maže
     * na místě a nikdy nestornuje ({@see Vat\VatClearingService}) — ruční smazání je tedy
     * táž operace, jakou dělá sama, a zápis se při dalším podání či ručním spuštění založí znovu.
     */
    public const SINGLE_SOURCE_TYPES = ['manual', 'invoice', 'purchase_invoice', 'bank', 'depreciation', 'vat_clearing', 'other_item'];

    /**
     * Storno dvojice: shodné s mazáním jednoho zápisu, bez odpisů a ostatních položek.
     * U ostatních položek se stav zdroje obnovuje jen při smazání jeho aktivního zápisu.
     * U odpisů se maže i řádek
     * v `depreciation_entries` a dvojice po stornu odpisu vzniká jinou cestou.
     */
    public const PAIR_SOURCE_TYPES   = ['manual', 'invoice', 'purchase_invoice', 'bank', 'vat_clearing'];

    /** Query parametr, kterým účetní potvrzuje zásah do uzamčeného období. */
    public const ACK_LOCKED_PARAM = 'ack_locked';

    /**
     * Brána období a zámku společná pro jeden zápis i storno dvojici.
     *
     * Zavřené (`closing`/`closed`) období se nepřehlasuje: po uzávěrce by smazání
     * rozbilo převedené zůstatky, období je třeba nejdřív znovu otevřít. Uzamčené datum
     * (`locked_until`, typicky po podání přiznání k DPH) přehlasovat jde, ale jen vědomě
     * — bez `$lockedAcknowledged` vrací `date_locked` s `can_acknowledge`, aby UI mohlo
     * ukázat varování a požadavek zopakovat s {@see ACK_LOCKED_PARAM}.
     *
     * @param array<string,mixed> $entry
     * @return array{code:string, message:string, can_acknowledge?:bool}|null
     */
    public static function blockPeriod(array $entry, ?string $lockedUntil, bool $lockedAcknowledged = false): ?array
    {
        if ((string) $entry['period_status'] !== 'open') {
            return [
                'code'    => 'period_not_open',
                'message' => 'Zápis #' . (int) $entry['id'] . ' je v období „' . $entry['period_status']
                    . '“ — smazat lze jen zápis v otevřeném období.',
            ];
        }
        if (!$lockedAcknowledged && self::isDateLocked($entry, $lockedUntil)) {
            return [
                'code'            => 'date_locked',
                'message'         => self::lockedWarning((string) $lockedUntil),
                'can_acknowledge' => true,
            ];
        }
        return null;
    }

    /** @param array<string,mixed> $entry */
    public static function isDateLocked(array $entry, ?string $lockedUntil): bool
    {
        return $lockedUntil !== null && (string) $entry['entry_date'] <= $lockedUntil;
    }

    public static function lockedWarning(string $lockedUntil): string
    {
        return 'Zápis spadá do uzamčeného období (uzamčeno k ' . $lockedUntil . '), typicky po podání '
            . 'přiznání k DPH nebo kontrolního hlášení. Smazáním se změní údaje, které už mohly být '
            . 'vykázané finančnímu úřadu, a může být nutné podat dodatečné nebo opravné přiznání. '
            . 'Zásah je na odpovědnost účetního.';
    }

    /**
     * Jeden zápis: kromě období nesmí být stornovaný ani sám protizápisem.
     *
     * @param array<string,mixed> $entry
     * @return array{code:string, message:string, can_acknowledge?:bool}|null
     */
    public static function blockSingle(array $entry, ?string $lockedUntil, bool $lockedAcknowledged = false): ?array
    {
        if ($block = self::blockPeriod($entry, $lockedUntil, $lockedAcknowledged)) {
            return $block;
        }
        if ($entry['reversed_by'] !== null || (bool) $entry['is_reversal']) {
            return [
                'code'    => 'entry_has_reversal',
                'message' => 'Stornovaný zápis ani jeho protizápis nelze smazat samostatně.',
            ];
        }
        return null;
    }
}

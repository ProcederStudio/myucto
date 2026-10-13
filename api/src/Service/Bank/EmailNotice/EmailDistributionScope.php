<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice;

/**
 * Firmy, mezi které jeden sken schránky rozděluje výpisy, avíza a doklady.
 *
 * Oba seznamy začínají firmou, které schránka patří; ta je vždy cílem toho, co
 * jinam přiřadit nejde. Seznamy se liší, protože výpis a avízo potřebují právo
 * na import banky, kdežto doklad právo na příchozí doklady.
 */
final class EmailDistributionScope
{
    /** @var list<int> */
    public readonly array $bankSupplierIds;

    /** @var list<int> */
    public readonly array $inboxSupplierIds;

    /**
     * @param list<int> $bankSupplierIds
     * @param list<int> $inboxSupplierIds
     */
    public function __construct(
        public readonly int $mailboxSupplierId,
        array $bankSupplierIds,
        array $inboxSupplierIds,
    ) {
        $this->bankSupplierIds = self::withMailboxFirst($mailboxSupplierId, $bankSupplierIds);
        $this->inboxSupplierIds = self::withMailboxFirst($mailboxSupplierId, $inboxSupplierIds);
    }

    /** @return list<int> firmy kromě firmy schránky, kam smí odejít výpis nebo avízo */
    public function otherBankSupplierIds(): array
    {
        return array_values(array_filter($this->bankSupplierIds, fn (int $id): bool => $id !== $this->mailboxSupplierId));
    }

    /** @return list<int> každá firma, které se sken dotkl */
    public function allSupplierIds(): array
    {
        return array_values(array_unique([...$this->bankSupplierIds, ...$this->inboxSupplierIds]));
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private static function withMailboxFirst(int $mailbox, array $ids): array
    {
        $rest = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0 && $id !== $mailbox,
        )));
        sort($rest);
        return [$mailbox, ...$rest];
    }
}

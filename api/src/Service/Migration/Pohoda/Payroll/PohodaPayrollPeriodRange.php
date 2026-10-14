<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda\Payroll;

/**
 * Rozsah měsíců převodu mezd (`YYYY-MM` od - do, obě meze včetně, chybějící mez = bez omezení).
 *
 * Export z PAMICA nese všechno, co mzdový program zpracoval, i měsíce, které má MyÚčto
 * počítat samo (export ze září nese i září). Měsíc mimo rozsah se převádí stejně jako
 * rozpracovaný měsíc: nepřevezme se jeho mzda, srovnávací úhrny ani podané hlášení a začátek
 * vedení mezd se počítá nejvýš od horní meze.
 */
final class PohodaPayrollPeriodRange
{
    private const PATTERN = '/^\d{4}-(0[1-9]|1[0-2])$/D';

    private function __construct(public readonly ?string $from, public readonly ?string $to) {}

    public static function all(): self
    {
        return new self(null, null);
    }

    /**
     * Rozsah z parametrů úlohy nebo těla požadavku (`period_from`, `period_to`).
     *
     * @param array<string,mixed> $params
     * @throws \InvalidArgumentException neplatný měsíc nebo začátek po konci
     */
    public static function fromParams(array $params): self
    {
        $bound = static function (mixed $value): ?string {
            if ($value === null || $value === '') {
                return null;
            }
            if (!is_string($value) || preg_match(self::PATTERN, $value) !== 1) {
                throw new \InvalidArgumentException('Měsíc rozsahu převodu musí být ve tvaru RRRR-MM.');
            }
            return $value;
        };
        $from = $bound($params['period_from'] ?? null);
        $to = $bound($params['period_to'] ?? null);
        if ($from !== null && $to !== null && $from > $to) {
            throw new \InvalidArgumentException('Začátek rozsahu převodu leží po jeho konci.');
        }
        return new self($from, $to);
    }

    public function limited(): bool
    {
        return $this->from !== null || $this->to !== null;
    }

    public function contains(string $period): bool
    {
        $period = substr($period, 0, 7);
        return ($this->from === null || $period >= $this->from) && ($this->to === null || $period <= $this->to);
    }

    /**
     * Poslední měsíc zpracovaný předchozím programem, jak ho vidí převod: měsíce po horní
     * mezi počítá MyÚčto, takže začátek vedení mezd jde nejvýš za ni.
     */
    public function capLast(?string $last): ?string
    {
        if ($last === null || $last === '' || $this->to === null) {
            return $last === '' ? null : $last;
        }
        return $last > $this->to ? $this->to : $last;
    }

    /** @return array{period_from:?string,period_to:?string} */
    public function toParams(): array
    {
        return ['period_from' => $this->from, 'period_to' => $this->to];
    }

    /** `M/YYYY - M/YYYY`, chybějící mez jako „…". */
    public function label(): string
    {
        $month = static fn (?string $p): string => $p === null ? '…' : ((int) substr($p, 5, 2)) . '/' . substr($p, 0, 4);
        return $month($this->from) . ' - ' . $month($this->to);
    }
}

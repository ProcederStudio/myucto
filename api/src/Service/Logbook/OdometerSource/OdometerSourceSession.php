<?php

declare(strict_types=1);

namespace MyInvoice\Service\Logbook\OdometerSource;

/**
 * Rozšifrované přístupy jednoho napojení (účtu u zdroje) po dobu jedné operace.
 *
 * Zdroj si přístupy obnoví sám (refresh token, kontejner, …) a změnu předá
 * {@see update()} — jádro ji hned šifrovaně uloží. Služby s rotujícím refresh
 * tokenem (BMW) by jinak po prvním obnovení napojení ztratily.
 *
 * Před každým voláním služby, které se počítá do jejího denního limitu, zavolá zdroj
 * {@see spendCalls()}. Jádro limit hlídá za celý účet, ne za auto.
 */
final class OdometerSourceSession
{
    /** @var \Closure(array<string,string|int>):void */
    private readonly \Closure $persist;
    /** @var \Closure(int):void */
    private readonly \Closure $spend;

    /**
     * @param array<string,string|int>                 $credentials
     * @param callable(array<string,string|int>):void $persist
     * @param (callable(int):void)|null               $spend vyhodí rate_limited, je-li denní limit vyčerpaný
     */
    public function __construct(#[\SensitiveParameter] private array $credentials, callable $persist, ?callable $spend = null)
    {
        $this->persist = \Closure::fromCallable($persist);
        $this->spend = \Closure::fromCallable($spend ?? static function (int $calls): void {});
    }

    /** @return array<string,string|int> */
    public function credentials(): array
    {
        return $this->credentials;
    }

    /** @param array<string,string|int> $credentials */
    public function update(#[\SensitiveParameter] array $credentials): void
    {
        ($this->persist)($credentials);
        $this->credentials = $credentials;
    }

    public function spendCalls(int $calls = 1): void
    {
        ($this->spend)($calls);
    }
}

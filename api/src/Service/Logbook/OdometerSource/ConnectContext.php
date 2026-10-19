<?php

declare(strict_types=1);

namespace MyInvoice\Service\Logbook\OdometerSource;

/**
 * Údaje jádra pro zahájení připojení.
 *
 * `state` generuje a ukládá jádro (jen jako hash), ne zdroj: zdroj s přesměrováním
 * na OAuth ho jen předá službě. Společný callback pak podle něj najde rozpracované
 * napojení bez znalosti konkrétního zdroje.
 */
final class ConnectContext
{
    public function __construct(
        #[\SensitiveParameter] public readonly string $state,
        public readonly ?string $redirectUri = null,
    ) {}
}

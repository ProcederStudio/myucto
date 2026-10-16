<?php

declare(strict_types=1);

namespace MyInvoice\Service;

use MyInvoice\Infrastructure\Config\Config;

/**
 * Matchování IP adres proti seznamu pravidel — IPv4, IPv6, CIDR notace.
 *
 *  - "127.0.0.1"            = exact IPv4
 *  - "192.168.1.0/24"       = IPv4 podsíť
 *  - "::1"                  = exact IPv6
 *  - "2001:db8::/56"        = IPv6 prefix
 *  - "2001:db8:1234::/64"  = IPv6 /64
 *
 * IPv4-mapped IPv6 (::ffff:1.2.3.4) je automaticky normalizováno na IPv4.
 */
final class IpMatcher
{
    /**
     * Server parametr se skutečnou client IP, který nastavuje sám web server.
     *
     * Klientské hlavičky se do FastCGI/CGI vždy mapují s prefixem `HTTP_`, takže
     * parametr BEZ tohoto prefixu nelze zvenčí podvrhnout — na rozdíl od
     * `HTTP_X_FORWARDED_FOR`, který k nám dorazí přesně tak, jak ho klient poslal.
     * Nastavuje ho docker/nginx.conf (`fastcgi_param MYUCTO_CLIENT_IP $remote_addr`).
     * Když je přítomný, je autoritativní a X-Forwarded-For se vůbec neřeší.
     */
    public const TRUSTED_CLIENT_IP_PARAM = 'MYUCTO_CLIENT_IP';

    public function __construct(
        private readonly ?Config $config = null,
    ) {}

    /**
     * @param string[] $rules
     */
    public function matches(string $ip, array $rules): bool
    {
        $ip = $this->normalize($ip);
        if ($ip === null) {
            return false;
        }

        foreach ($rules as $rule) {
            if ($this->matchRule($ip, $rule)) {
                return true;
            }
        }
        return false;
    }

    /** Privátní, lokální a sdílené adresy mohou za proxy znamenat chybějící předání IP. */
    public function isPrivateOrLocal(string $ip): bool
    {
        return $this->matches($ip, [
            '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16',
            '127.0.0.0/8', '169.254.0.0/16', '100.64.0.0/10',
            '::1', 'fc00::/7', 'fe80::/10',
        ]);
    }

    private function matchRule(string $ip, string $rule): bool
    {
        $rule = trim($rule);
        if ($rule === '') {
            return false;
        }

        if (str_contains($rule, '/')) {
            [$subnet, $prefixStr] = explode('/', $rule, 2);
            $prefix = (int) $prefixStr;
            $subnet = $this->normalize(trim($subnet));
            if ($subnet === null) {
                return false;
            }
            return $this->cidrMatch($ip, $subnet, $prefix);
        }

        $exact = $this->normalize($rule);
        return $exact !== null && $exact === $ip;
    }

    private function cidrMatch(string $ip, string $subnet, int $prefix): bool
    {
        $ipBin     = inet_pton($ip);
        $subnetBin = inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false) {
            return false;
        }

        // Musí být stejná rodina (4 vs 16 bytes)
        if (strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bits = strlen($ipBin) * 8;
        if ($prefix < 0 || $prefix > $bits) {
            return false;
        }

        $bytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        // Plné byty
        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }

        // Zbývající bity v dalším bytu
        if ($remainingBits === 0) {
            return true;
        }

        $mask        = chr((0xFF << (8 - $remainingBits)) & 0xFF);
        $ipByte      = $ipBin[$bytes] ?? "\x00";
        $subnetByte  = $subnetBin[$bytes] ?? "\x00";

        return ($ipByte & $mask) === ($subnetByte & $mask);
    }

    /**
     * Normalizuje IP do kanonické textové podoby (pro IPv6 lowercase, expanded).
     * IPv4-mapped IPv6 (::ffff:1.2.3.4) → 1.2.3.4
     */
    private function normalize(string $ip): ?string
    {
        return self::canonicalize($ip);
    }

    /**
     * Kanonický tvar IP adresy — SSOT pro celou aplikaci.
     *
     * Statická a veřejná schválně: stejnou normalizaci potřebuje i validace
     * pravidel IP allowlistu tokenů ({@see \MyInvoice\Service\Auth\ApiTokenService::normalizeRule}).
     * Kdyby si ji zapsala po svém, uložilo by se pravidlo v jiném tvaru, než jaký
     * tady matchování očekává — a allowlist by tiše nefungoval. Vlastní kopii
     * téhle logiky proto nikde nezakládej.
     */
    public static function canonicalize(string $ip): ?string
    {
        $ip = trim($ip);
        if ($ip === '') {
            return null;
        }

        $packed = @inet_pton($ip);
        if ($packed === false) {
            return null;
        }

        // IPv4-mapped IPv6 → IPv4
        if (strlen($packed) === 16) {
            $prefix = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff";
            if (substr($packed, 0, 12) === $prefix) {
                $packed = substr($packed, 12);
            }
        }

        $back = inet_ntop($packed);
        return $back === false ? null : $back;
    }

    /**
     * Počet bitů adresy (32 pro IPv4, 128 pro IPv6), nebo null u neplatné adresy.
     * Používá validace CIDR prefixu, aby „1.2.3.0/64" neprošlo jako platné pravidlo.
     */
    public static function addressBits(string $ip): ?int
    {
        $packed = @inet_pton(trim($ip));
        return $packed === false ? null : strlen($packed) * 8;
    }

    /**
     * Normalizuje JEDNU položku X-Forwarded-For chainu.
     *
     * Některé proxy (a RFC 7239 „Forwarded" implementace) zapisují i port —
     * `203.0.113.7:41234` — nebo IPv6 v hranatých závorkách (`[2001:db8::1]`,
     * `[2001:db8::1]:443`). Holé `inet_pton()` takový tvar odmítne, což by
     * fail-closed shodilo celý chain na IP proxy a reálného klienta zahodilo.
     * Proto tady port a závorky odloupneme; na pravidla z konfigurace se tahle
     * tolerance nevztahuje (`normalize()` zůstává striktní).
     */
    private function normalizeChainEntry(string $entry): ?string
    {
        $entry = trim($entry);
        if ($entry === '') {
            return null;
        }

        // Bracketed IPv6: "[2001:db8::1]" nebo "[2001:db8::1]:443"
        if ($entry[0] === '[') {
            $end = strpos($entry, ']');
            if ($end === false) {
                return null;
            }
            $rest = substr($entry, $end + 1);
            // Za závorkou smí následovat jen prázdno nebo ":port" — nic jiného.
            if ($rest !== '' && preg_match('/^:\d{1,5}$/', $rest) !== 1) {
                return null;
            }
            return $this->normalize(substr($entry, 1, $end - 1));
        }

        // IPv4 s portem: právě jedna dvojtečka. Holé IPv6 jich má víc, toho se
        // nedotkneme (`::1` má dvě).
        if (substr_count($entry, ':') === 1) {
            [$host, $port] = explode(':', $entry, 2);
            if (preg_match('/^\d{1,5}$/', $port) === 1) {
                $normalized = $this->normalize($host);
                if ($normalized !== null) {
                    return $normalized;
                }
            }
        }

        return $this->normalize($entry);
    }

    /**
     * Získá skutečnou client IP s ohledem na cfg.ip_allowlist.trusted_proxies + header.
     * Použití: `$this->ipMatcher->clientIpFromRequest($request->getServerParams())`.
     *
     * @param array<string,mixed> $serverParams
     */
    public function clientIpFromRequest(array $serverParams): string
    {
        $trusted = (array) ($this->config?->get('ip_allowlist.trusted_proxies', []) ?? []);
        $header  = (string) ($this->config?->get('ip_allowlist.header', 'X-Forwarded-For') ?? 'X-Forwarded-For');
        return $this->clientIp($serverParams, $trusted, $header);
    }

    /**
     * Získá skutečnou client IP s ohledem na trusted proxies.
     *
     * Chain hlavičky se prochází ZPRAVA a odloupávají se jen známé trusted hopy;
     * první netrusted položka zprava je skutečný klient. Brát první položku zleva
     * je nebezpečné: pokud edge proxy klientskou hlavičku jen *appenduje* (a ne
     * přepisuje), levé položky si zapsal sám útočník a mohl by si tak zvolit
     * libovolnou IP — a tím obejít IP allowlist i rate-limit/brute-force buckety
     * a podvrhnout auditní logy.
     *
     * ⚠️ Průchod zprava sám o sobě NESTAČÍ — jen posouvá páku z levého konce
     * chainu na pravý. Když edge proxy hlavičku jen appenduje (nebo ji vůbec
     * nesahá, jako holý nginx→FastCGI), útočník ovládá i nejpravější položku.
     * Edge proxy (ta, která jako první přijímá provoz z internetu) proto MUSÍ
     * hlavičku PŘEPISOVAT: `proxy_set_header X-Forwarded-For $remote_addr`
     * (`proxy_add_x_forwarded_for` naopak appenduje). Cloudflare přepisuje.
     *
     * Spolehlivější než hlavička je {@see self::TRUSTED_CLIENT_IP_PARAM}, který
     * klient podvrhnout nemůže; `clientIpFromRequest()` ho preferuje.
     */
    public function clientIp(array $serverParams, array $trustedProxies, string $header = 'X-Forwarded-For'): string
    {
        // Nepodvrhnutelný server parametr (nginx ho plní přes `fastcgi_param`; klientské
        // hlavičky se do FastCGI mapují jako HTTP_*, takže ho zvenčí přepsat nejde) je
        // SPOLEHLIVĚJŠÍ ZDROJ REMOTE_ADDR — ne hotová odpověď. Kdyby se vracel rovnou,
        // rozbije se nasazení, kde před kontejnerem stojí další reverse proxy: uvnitř
        // je pak v parametru IP té proxy, chain by se nikdy neprošel a všichni klienti
        // by splynuli do jedné IP (globální rate-limit bucket, nefunkční IP allowlist).
        // Bere se proto jako výchozí bod a dál se pokračuje běžnou trusted-proxy logikou.
        // Kontrola je schválně TADY (a ne až v clientIpFromRequest), protože middleware
        // i některé Action volají clientIp() přímo.
        $trustedParam = $this->normalize((string) ($serverParams[self::TRUSTED_CLIENT_IP_PARAM] ?? ''));

        $remote     = $trustedParam ?? (string) ($serverParams['REMOTE_ADDR'] ?? '');
        $remoteNorm = $this->normalize($remote) ?? $remote;

        if ($remote === '' || empty($trustedProxies)) {
            return $remoteNorm;
        }

        // Hlavičce věříme jen tehdy, když k nám požadavek přišel od trusted proxy
        if (!$this->matches($remote, $trustedProxies)) {
            return $remoteNorm;
        }

        $headerKey = 'HTTP_' . str_replace('-', '_', strtoupper($header));
        $forwarded = (string) ($serverParams[$headerKey] ?? '');
        if (trim($forwarded) === '') {
            return $remoteNorm;
        }

        $parts = array_map('trim', explode(',', $forwarded));

        // Nejpravější položku zapsal náš nejbližší (už ověřený) trusted proxy,
        // takže je důvěryhodná. Postupujeme doleva a odloupáváme trusted hopy.
        for ($i = count($parts) - 1; $i >= 0; $i--) {
            $candidate = $this->normalizeChainEntry($parts[$i]);

            // Prázdná nebo neplatná položka → chain je rozbitý a klienta nelze
            // bezpečně určit. Fail-closed: zůstaneme u (důvěryhodné) IP proxy.
            if ($candidate === null) {
                return $remoteNorm;
            }

            if (!$this->matches($candidate, $trustedProxies)) {
                return $candidate;
            }
        }

        // Celý chain jsou samé trusted proxy → požadavek vznikl na některé z nich.
        // Klientem je pak nejlevější (nejvzdálenější) položka; všechny jsou validní,
        // protože smyčka výše je už znormalizovala.
        return $this->normalizeChainEntry($parts[0]) ?? $remoteNorm;
    }
}

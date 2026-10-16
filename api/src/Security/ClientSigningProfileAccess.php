<?php

declare(strict_types=1);

namespace MyInvoice\Security;

use MyInvoice\Repository\SigningProfileRepository;

/** Sdílená hranice firemních podpisů delegovatelných klientovi. */
final class ClientSigningProfileAccess
{
    public function __construct(private readonly SigningProfileRepository $profiles) {}

    /** @param array<string,mixed> $profile */
    public function canManage(array $profile): bool
    {
        if (!self::allowsConfiguration($profile, null)) return false;
        return self::allowsConfiguration($profile, $this->profiles->credential((int) $profile['supplier_id'], (int) $profile['id']));
    }

    /** @param array<string,mixed> $profile @param array<string,mixed>|null $credential */
    public static function allowsConfiguration(array $profile, ?array $credential): bool
    {
        if (($profile['owner_user_id'] ?? null) !== null
            || ($profile['default_backend'] ?? '') !== 'native'
            || array_diff($profile['allowed_usages'] ?? [], ['pdf', 'email_smime']) !== []
        ) {
            return false;
        }

        if ($credential === null) return true;

        // Pouze upload spravovaný aplikací. Legacy absolutní cesty ani osobní
        // trezor nesmějí klientovi otevřít přístup k souborům správce instalace.
        $expectedPath = 'signing/profiles/supplier-' . (int) $profile['supplier_id']
            . '/profile-' . (int) $profile['id'] . '/profile.p12';
        return ($credential['vault_credential_id'] ?? null) === null
            && ($credential['passphrase_policy'] ?? '') === 'encrypted_store'
            && ($credential['certificate_path'] ?? '') === $expectedPath;
    }

    public function canReference(int $supplierId, int $profileId, string $usage): bool
    {
        $profile = $this->profiles->findProfile($supplierId, $profileId);
        return $profile !== null && $this->canManage($profile)
            && ($profile['is_active'] ?? false)
            && in_array($usage, $profile['allowed_usages'] ?? [], true);
    }

    public function canManageLinkedProfile(int $supplierId, int $profileId): bool
    {
        $profile = $this->profiles->findProfile($supplierId, $profileId);
        // Deaktivace nebo odebrání použití nesmí zablokovat odpojení identity.
        // Smazané/protected profily zůstávají ve správě instalace.
        return $profile !== null && $this->canManage($profile);
    }

    public function canTestEmail(int $supplierId, ?int $explicitProfileId): bool
    {
        $output = $this->profiles->outputSetting($supplierId, 'email_invoice_send', 'email_smime');
        if (!($output['enabled'] ?? false)) return true;
        if ($explicitProfileId !== null && $explicitProfileId > 0) {
            return $this->canReference($supplierId, $explicitProfileId, 'email_smime');
        }
        // Test prostého odesílacího profilu dědí stejnou politiku jako faktura;
        // nesmí přes ni vyvolat osobní nebo instalační podpis.
        if (($output['selection_source'] ?? '') !== 'admin_profile_settings') return false;
        $profileId = (int) ($output['default_profile_id'] ?? 0);
        return $profileId <= 0 || $this->canReference($supplierId, $profileId, 'email_smime');
    }
}

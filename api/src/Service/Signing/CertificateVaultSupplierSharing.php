<?php

declare(strict_types=1);

namespace MyInvoice\Service\Signing;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\EpoSigningCredentialRepository;
use MyInvoice\Repository\UserSupplierRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\PermissionResolver;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Tenant\SupplierAccessResolver;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Povolení certifikátu z trezoru i v dalších firmách uživatele.
 *
 * Certifikát se NEKOPÍRUJE. Trezor je osobní (`epo_signing_credentials.owner_user_id`),
 * soukromý klíč i heslo v něm leží zašifrované jednou, a firma k němu dostává jen
 * přístup řádkem v `epo_signing_credential_suppliers`. Přesně ten řádek si vyžádá
 * podpis ({@see EpoSigningCredentialRepository::findUsable()}), takže hromadné
 * povolení je totéž, co by uživatel udělal ručně v každé firmě zvlášť.
 *
 * Cílové firmy se odvozují výhradně na serveru: členství uživatele v `user_suppliers`
 * a pro každou firmu znovu vyhodnocená role (per-firemní override, aktivita, typ) se
 * stejným oprávněním, jaké hlídá stránka, tedy `settings.signing` pro zápis. Ani
 * superadmin se nerozšiřuje na firmy, kde není členem: hromadná akce nemá dosáhnout
 * dál než výběr firem, který uživatel sám vidí. Výjimkou je superadmin bez jediného
 * členství: v přepínači vidí všechny firmy, a kdyby se bral jen `user_suppliers`,
 * tlačítko by u něj tiše nepovolilo nic. Firma, do které se resoluce přístupu
 * nedostane (vlastní doména zamčená na jinou firmu), se ve výsledku vůbec neukáže.
 */
final class CertificateVaultSupplierSharing
{
    public const STATUS_ENABLED = 'enabled';
    public const STATUS_ALREADY_ENABLED = 'already_enabled';
    public const STATUS_SKIPPED_HAS_VALID = 'skipped_has_valid';
    public const STATUS_SKIPPED_NO_PERMISSION = 'skipped_no_permission';

    public function __construct(
        private readonly Connection $db,
        private readonly UserSupplierRepository $memberships,
        private readonly SupplierAccessResolver $supplierAccess,
        private readonly PermissionResolver $permissions,
        private readonly EpoSigningCredentialRepository $credentials,
        private readonly ActivityLogger $logger,
    ) {}

    /**
     * @return list<array{supplier_id:int,name:string,status:string}>|null
     *         null = certifikát v trezoru uživatele není
     */
    public function shareWithOtherSuppliers(
        Request $request,
        int $credentialId,
        int $ownerUserId,
        int $sourceSupplierId,
        bool $onlyWithoutValidCertificate,
        ?string $ip = null,
    ): ?array {
        $credential = $this->credentials->findOwned($credentialId, $ownerUserId);
        if ($credential === null) {
            return null;
        }

        $results = [];
        $enabled = [];
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            foreach ($this->candidateSuppliers($request, $ownerUserId) as $membership) {
                $supplierId = $membership['supplier_id'];
                if ($supplierId === $sourceSupplierId) {
                    continue;
                }
                $permitted = $this->permittedFor($request, $supplierId);
                if ($permitted === null) {
                    continue;
                }
                $status = match (true) {
                    !$permitted => self::STATUS_SKIPPED_NO_PERMISSION,
                    $this->credentials->isEnabledForSupplier($credentialId, $supplierId)
                        => self::STATUS_ALREADY_ENABLED,
                    $onlyWithoutValidCertificate && $this->credentials->hasOtherValidEnabledForSupplier(
                        $ownerUserId,
                        $supplierId,
                        $credentialId,
                    ) => self::STATUS_SKIPPED_HAS_VALID,
                    default => self::STATUS_ENABLED,
                };
                if ($status === self::STATUS_ENABLED) {
                    if (!$this->credentials->setSupplierEnabled(
                        $credentialId,
                        $ownerUserId,
                        $supplierId,
                        true,
                        $ownerUserId,
                    )) {
                        throw new \RuntimeException('Credential supplier mapping failed.');
                    }
                    $enabled[] = $supplierId;
                }
                $results[] = [
                    'supplier_id' => $supplierId,
                    'name' => $membership['name'],
                    'status' => $status,
                ];
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        foreach ($enabled as $supplierId) {
            $this->logger->log(
                'certificate_vault_supplier_enabled',
                $ownerUserId,
                'certificate',
                $credentialId,
                [
                    'source_supplier_id' => $sourceSupplierId,
                    'fingerprint' => (string) $credential['fingerprint_sha256'],
                ],
                $ip,
                $request->getHeaderLine('User-Agent'),
                $supplierId,
            );
        }

        return $results;
    }

    /** @return list<array{supplier_id:int,name:string}> */
    private function candidateSuppliers(Request $request, int $ownerUserId): array
    {
        $memberships = $this->memberships->listForUser($ownerUserId);
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if ($memberships !== [] || ($user['is_superadmin'] ?? false) !== true) {
            return $memberships;
        }

        $rows = $this->db->pdo()->query(
            'SELECT id, COALESCE(NULLIF(display_name, \'\'), company_name) AS name FROM supplier ORDER BY id'
        )->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(
            static fn (array $row): array => ['supplier_id' => (int) $row['id'], 'name' => (string) $row['name']],
            $rows,
        );
    }

    /**
     * true = smí, false = je členem, ale chybí mu oprávnění, null = firma
     * v tomhle kontextu vůbec není dosažitelná (a nemá se ani jmenovat).
     */
    private function permittedFor(Request $request, int $supplierId): ?bool
    {
        $scoped = $request
            ->withHeader(SupplierScopeMiddleware::HEADER_NAME, (string) $supplierId)
            ->withQueryParams([]);
        $access = $this->supplierAccess->resolve($scoped);
        if ($access->denied || $access->supplierId !== $supplierId) {
            return null;
        }
        $role = $this->permissions->resolve($scoped);
        $scoped = $scoped
            ->withAttribute('auth.effective_role', $role)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId);

        return $role->isActive
            && !$role->isClientType()
            && RequestAuthorization::allows($scoped, 'settings.signing', AccessLevel::WRITE);
    }
}

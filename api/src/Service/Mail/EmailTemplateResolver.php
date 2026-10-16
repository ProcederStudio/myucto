<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mail;

use MyInvoice\Repository\EmailTemplateRepository;
use MyInvoice\Repository\SupplierEmailTemplateRepository;

final class EmailTemplateResolver
{
    public function __construct(
        private readonly EmailTemplateRepository $global,
        private readonly SupplierEmailTemplateRepository $supplier,
        private readonly EmailTemplateCatalog $catalog,
    ) {}

    public function resolve(string $code, string $locale, ?int $supplierId = null): array
    {
        foreach (array_unique([$locale, 'cs']) as $lang) {
            $own = $supplierId !== null && $supplierId > 0 && $this->catalog->isSupplierTemplate($code, $lang)
                ? $this->supplier->find($supplierId, $code, $lang) : null;
            if ($own !== null) return $own + ['source' => 'supplier', 'resolved_locale' => $lang];
            $global = $this->global->find($code, $lang);
            if ($global !== null) return $global + ['source' => 'global', 'resolved_locale' => $lang];
            $default = $this->catalog->defaults($code, $lang);
            if ($default !== null) return $default + ['source' => 'default', 'resolved_locale' => $lang];
        }
        throw new \InvalidArgumentException('Šablona neexistuje.');
    }

    public function detail(int $supplierId, string $code, string $locale): array
    {
        $own = $this->supplier->find($supplierId, $code, $locale);
        $resolved = $this->resolve($code, $locale, $supplierId);
        return [
            'supplier_id' => $supplierId, 'code' => $code, 'locale' => $locale,
            'source' => $resolved['source'], 'resolved_locale' => $resolved['resolved_locale'],
            'has_override' => $own !== null, 'updated_at' => $own['updated_at'] ?? null,
            'subject' => $resolved['subject'], 'body_html' => $resolved['body_html'], 'body_text' => $resolved['body_text'],
            'variables' => $this->catalog->variables($code),
        ];
    }
}

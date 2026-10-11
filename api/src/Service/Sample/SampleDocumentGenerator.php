<?php

declare(strict_types=1);

namespace MyInvoice\Service\Sample;

use Mpdf\Mpdf;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DocumentFileRepository;
use MyInvoice\Repository\DocumentLinkRepository;
use MyInvoice\Repository\DocumentRepository;
use MyInvoice\Service\Document\DocumentStorage;
use MyInvoice\Service\Pdf\MpdfFontConfig;
use PDO;

final class SampleDocumentGenerator
{
    private array $storedFiles = [];

    public function __construct(
        private readonly Connection $db,
        private readonly DocumentStorage $storage,
        private readonly DocumentRepository $documents,
        private readonly DocumentFileRepository $files,
        private readonly DocumentLinkRepository $links,
    ) {}

    public function generate(int $supplierId, int $userId, callable $track): int
    {
        $this->storedFiles = [];
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            "SELECT i.*, c.company_name, cur.code AS currency, p.name AS project_name,
                    (SELECT MIN(it.stock_item_id) FROM invoice_items it WHERE it.invoice_id=i.id) AS stock_item_id
               FROM invoices i JOIN clients c ON c.id=i.client_id
               JOIN currencies cur ON cur.id=i.currency_id LEFT JOIN projects p ON p.id=i.project_id
              WHERE i.supplier_id=? AND i.invoice_type='invoice'
                AND i.status NOT IN ('draft','cancelled')
                AND EXISTS (SELECT 1 FROM invoice_items it WHERE it.invoice_id=i.id AND it.stock_item_id IS NOT NULL)
              ORDER BY i.issue_date DESC,i.id DESC LIMIT 1"
        );
        $stmt->execute([$supplierId]);
        $sale = $stmt->fetch(PDO::FETCH_ASSOC);
        $specs = [];
        if ($sale !== false) {
            $stmt = $pdo->prepare(
                'SELECT p.*, c.company_name, cur.code AS currency FROM purchase_invoices p
                   JOIN clients c ON c.id=p.vendor_id JOIN currencies cur ON cur.id=p.currency_id
                  WHERE p.supplier_id=? AND p.issue_date<=?
                    AND EXISTS (SELECT 1 FROM purchase_invoice_items it WHERE it.purchase_invoice_id=p.id AND it.stock_item_id=?)
                  ORDER BY p.issue_date DESC,p.id DESC LIMIT 1'
            );
            $stmt->execute([$supplierId, $sale['issue_date'], $sale['stock_item_id']]);
            $purchase = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($purchase !== false) $specs[] = ['purchase_invoice', $purchase];
            $specs[] = ['invoice', $sale];
        }
        $stmt = $pdo->prepare(
            "SELECT i.*, c.company_name, cur.code AS currency, p.name AS project_name
               FROM invoices i JOIN clients c ON c.id=i.client_id
               JOIN currencies cur ON cur.id=i.currency_id LEFT JOIN projects p ON p.id=i.project_id
              WHERE i.supplier_id=? AND i.invoice_type='invoice' AND i.reverse_charge=0
                AND i.status NOT IN ('draft','cancelled')
                AND NOT EXISTS (SELECT 1 FROM invoice_items it WHERE it.invoice_id=i.id AND it.stock_item_id IS NOT NULL)
                AND NOT EXISTS (SELECT 1 FROM invoices cn WHERE cn.parent_invoice_id=i.id)
              ORDER BY i.issue_date DESC,i.id DESC LIMIT ?"
        );
        $stmt->bindValue(1, $supplierId, PDO::PARAM_INT);
        $stmt->bindValue(2, 3 - count($specs), PDO::PARAM_INT);
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $invoice) $specs[] = ['invoice', $invoice];

        foreach ($specs as [$type, $doc]) {
            $table = $type === 'invoice' ? 'invoice_items' : 'purchase_invoice_items';
            $fk = $type === 'invoice' ? 'invoice_id' : 'purchase_invoice_id';
            $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE {$fk}=? ORDER BY order_index,id");
            $stmt->execute([$doc['id']]);
            $html = $this->html($type, $doc, $stmt->fetchAll(PDO::FETCH_ASSOC));
            $tmpDir = RuntimePaths::storage('tmp/sample-pdf');
            if (!is_dir($tmpDir) && !mkdir($tmpDir, 0755, true) && !is_dir($tmpDir)) {
                throw new \RuntimeException('Nelze vytvořit pracovní adresář ukázkových PDF.');
            }
            $pdf = new Mpdf([...MpdfFontConfig::options(), 'tempDir' => $tmpDir, 'format' => 'A4', 'margin_top' => 17]);
            $pdf->SetTitle('Ukázková faktura ' . $doc['varsymbol']);
            $pdf->SetAuthor('MyÚčto - fiktivní školení');
            $pdf->WriteHTML($html);
            $name = ($type === 'invoice' ? 'Vydana-' : 'Prijata-') . $doc['varsymbol'] . '-SKOLENI.pdf';
            $stored = $this->storage->storeFromBytes($pdf->Output('', 'S'), $supplierId, $name);
            $this->storedFiles[] = $stored;
            $data = [
                'supplier_id' => $supplierId, 'title' => $name, 'original_name' => $name,
                'description' => 'Fiktivní školící doklad. Částky a položky odpovídají propojené ukázkové faktuře.',
                'uploaded_by' => $userId, 'filename' => $stored['filename'], 'sha256' => $stored['sha256'],
                'mime_type' => $stored['mime_type'], 'size_bytes' => $stored['size_bytes'], 'doc_type' => 'pdf',
            ];
            $id = $this->documents->insert($data);
            $this->files->add([...$data, 'document_id' => $id, 'role' => 'primary']);
            $track('document', $id);
            $this->documents->setText($id, html_entity_decode(strip_tags(str_replace(['</tr>', '</p>', '<br>', '</h1>', '</h2>'], "\n", $html)), ENT_QUOTES, 'UTF-8'), 'extracted');
            $this->links->attach($supplierId, $id, $type, (int) $doc['id']);
            $this->links->attach($supplierId, $id, 'client', (int) ($doc['client_id'] ?? $doc['vendor_id']));
            if (!empty($doc['project_id'])) $this->links->attach($supplierId, $id, 'project', (int) $doc['project_id']);
            $stmt = $pdo->prepare('SELECT id FROM journal_entries WHERE supplier_id=? AND source_type=? AND source_id=?');
            $stmt->execute([$supplierId, $type, $doc['id']]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $entryId) $this->links->attach($supplierId, $id, 'journal_entry', (int) $entryId);
            $sql = $type === 'invoice'
                ? 'SELECT bank_transaction_id FROM invoice_payments WHERE supplier_id=? AND invoice_id=? AND bank_transaction_id IS NOT NULL'
                : 'SELECT bank_transaction_id FROM payment_matches WHERE supplier_id=? AND purchase_invoice_id=?';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$supplierId, $doc['id']]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $txId) $this->links->attach($supplierId, $id, 'bank_transaction', (int) $txId);
        }
        return count($specs);
    }

    public function cleanupAfterRollback(int $supplierId): void
    {
        foreach ($this->storedFiles as $stored) {
            $this->storage->deleteIfOrphan($supplierId, $stored['sha256'], $stored['filename'], null, $this->documents, []);
        }
        $this->storedFiles = [];
    }

    private function html(string $type, array $doc, array $items): string
    {
        $esc = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $money = static fn (mixed $value): string => number_format((float) $value, 2, ',', ' ');
        $own = 'Demo Technická zahrada s.r.o.';
        $seller = $type === 'invoice' ? $own : $doc['company_name'];
        $buyer = $type === 'invoice' ? $doc['company_name'] : $own;
        $number = $type === 'invoice' ? $doc['varsymbol'] : $doc['vendor_invoice_number'];
        $rows = '';
        foreach ($items as $item) {
            $rows .= '<tr><td>' . $esc($item['description']) . '</td><td class="n">' . $money($item['quantity']) . ' ' . $esc($item['unit'])
                . '</td><td class="n">' . $money($item['unit_price_without_vat']) . '</td><td class="n">' . $money($item['vat_rate_snapshot'])
                . ' %</td><td class="n">' . $money($item['total_with_vat']) . '</td></tr>';
        }
        return '<html><head><style>body{font-family:montserrat;font-size:10pt;color:#243449}h1{font-size:27pt;color:#185f70}h2{font-size:13pt}'
            . '.banner{background:#e6f1f4;padding:12px;color:#185f70;font-weight:bold}table{border-collapse:collapse;width:100%;margin-top:18px}'
            . 'th{background:#185f70;color:white;text-align:left;padding:10px}td{padding:10px;border-bottom:1px solid #dce5eb}.n{text-align:right;white-space:nowrap}'
            . '.total{text-align:right;font-size:18pt;font-weight:bold;color:#185f70}.muted{color:#637585;font-size:9pt}</style></head><body>'
            . '<div class="banner">FIKTIVNÍ ŠKOLICÍ DOKLAD - NEPOUŽÍVAT K PLATBĚ ANI PODÁNÍ</div>'
            . '<h1>Faktura ' . $esc($number) . '</h1><p>Evidence MyÚčto: ' . $esc($doc['varsymbol']) . '</p>'
            . '<table><tr><td width="50%"><h2>Dodavatel</h2>' . $esc($seller) . '<br>Modelová 15, 110 00 Praha<br>fakturace@example.invalid</td>'
            . '<td><h2>Odběratel</h2>' . $esc($buyer) . '<br>Ukázková 24, 602 00 Brno<br>uctarna@example.invalid</td></tr></table>'
            . '<p>Vystaveno: ' . $esc($doc['issue_date']) . '<br>DUZP: ' . $esc($doc['tax_date']) . '<br>Splatnost: ' . $esc($doc['due_date'])
            . '<br>Měna: ' . $esc($doc['currency']) . (!empty($doc['project_name']) ? '<br>Zakázka: ' . $esc($doc['project_name']) : '') . '</p>'
            . '<table><thead><tr><th>Položka</th><th>Množství</th><th>Cena bez DPH</th><th>DPH</th><th>Celkem</th></tr></thead><tbody>' . $rows . '</tbody></table>'
            . '<p class="n">Základ: ' . $money($doc['total_without_vat']) . ' ' . $esc($doc['currency']) . '<br>DPH: ' . $money($doc['total_vat']) . ' ' . $esc($doc['currency']) . '</p>'
            . '<p class="total">Celkem ' . $money($doc['total_with_vat']) . ' ' . $esc($doc['currency']) . '</p>'
            . '<p class="muted">Všechny firmy, položky a částky pocházejí ze syntetického scénáře. Doklad je v Dokumentech propojený s fakturou, obchodním partnerem a účetním zápisem.</p>'
            . '</body></html>';
    }
}

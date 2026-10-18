<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Mail\PaymentThanksMailer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/invoices/{id}/payments — zaeviduje (částečnou) úhradu (#89).
 *
 * Body: amount (povinné, v měně faktury), paid_on (default dnes), variable_symbol,
 * bank_reference, note, send_payment_thanks (jen pokud platba doklad doplatí).
 *
 * Pokud suma plateb pokryje částku k úhradě (tolerance 0,05), faktura se označí
 * jako zaplacená (paid_at = datum poslední platby) — stejné chování jako mark-paid.
 */
final class CreatePaymentAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly InvoicePaymentService $payments,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly PaymentThanksMailer $paymentThanks,
        private readonly DocumentLockService $locks,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $invoice = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        if (!isset($body['amount']) || !is_numeric($body['amount'])) {
            return Json::error($response, 'invalid_amount', 'Částka platby je povinná.', 400);
        }
        $amount = (float) $body['amount'];
        $paidOn = (string) ($body['paid_on'] ?? date('Y-m-d'));

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);

        // Epic F6 (R8/M5): platba je fakt — na zamčeném dokladu povolena i klientovi,
        // ale POVINNĚ auditována (u proforem spouští vznik daňového dokladu k platbě).
        // Zámek se vyhodnotí teď, audit se zapíše až PO úspěšném provedení platby —
        // jinak by selhání recordPayment nechalo falešný audit záznam.
        $clientLock = RequestAuthorization::isClientType($request) ? $this->locks->forInvoice($invoice) : null;
        $auditLockedPayment = $clientLock !== null && $clientLock->lockedForClient();

        try {
            $result = $this->payments->recordPayment($id, $amount, $paidOn, [
                'variable_symbol' => $body['variable_symbol'] ?? null,
                'bank_reference'  => $body['bank_reference'] ?? null,
                'note'            => $body['note'] ?? null,
                'source'          => 'manual',
                'created_by'      => (int) ($user['id'] ?? 0),
            ]);
        } catch (\PDOException $e) {
            // Chyba databáze není odmítnutá platba: 409 invalid_payment by klient API
            // bral jako neplatná data, i kdyby šlo o souběh, po kterém má zkusit znovu.
            throw $e;
        } catch (\RuntimeException $e) {
            return Json::error($response, 'invalid_payment', $e->getMessage(), 409);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        if ($auditLockedPayment) {
            $this->logger->log(
                'invoice.payment_on_locked_document',
                isset($user['id']) ? (int) $user['id'] : null,
                'invoice',
                $id,
                ['trigger' => 'payment', 'reasons' => $clientLock->reasons()],
                $ip,
                $request->getHeaderLine('User-Agent'),
            );
        }
        $this->logger->log('invoice.payment_added', $user['id'] ?? null, 'invoice', $id, [
            'payment_id' => $result['payment_id'],
            'amount'     => round($amount, 2),
            'paid_on'    => $paidOn,
            'remaining'  => $result['remaining'],
        ], $ip, $request->getHeaderLine('User-Agent'));

        // Děkovný e-mail dává smysl až po doplacení — u částečné úhrady se neposílá.
        $thanks = null;
        if ($result['became_paid'] && !empty($body['send_payment_thanks'])) {
            $thanks = $this->paymentThanks->sendForInvoice(
                $id,
                'manual',
                $user['id'] ?? null,
                $ip,
                $request->getHeaderLine('User-Agent'),
                requireUnsent: false,
            );
        }

        $fresh = $this->repo->find($id);
        return Json::ok($response, [
            'invoice'        => $fresh,
            'payments'       => $this->payments->listFor($id),
            'payment'        => $this->payments->findPayment($result['payment_id']),
            'became_paid'    => $result['became_paid'],
            'remaining'      => $result['remaining'],
            'payment_thanks' => $thanks,
        ]);
    }
}

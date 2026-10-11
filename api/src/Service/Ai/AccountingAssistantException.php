<?php

declare(strict_types=1);

namespace MyInvoice\Service\Ai;

/** Chyba AI asistenta s kódem a HTTP stavem pro odpověď API. */
final class AccountingAssistantException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus,
    ) {
        parent::__construct($message);
    }

    public static function invalid(string $message): self
    {
        return new self('ai_assistant.invalid_input', $message, 422);
    }
}

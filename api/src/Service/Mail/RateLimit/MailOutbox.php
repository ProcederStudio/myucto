<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mail\RateLimit;

use DateTimeImmutable;
use PDO;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fronta odložených zpráv (H-16, bod 2: „odloží se, nezahodí").
 *
 * Ukládá se LOGICKÝ požadavek (šablona + jazyk + příjemci + proměnné), ne
 * hotové MIME. Důvody dva:
 *   - hotové MIME nese vložené logo a podpis, takže by řádek narostl
 *     o stovky kilobajtů na každou odloženou zprávu,
 *   - přerenderování při odeslání použije aktuální šablonu a aktuální
 *     podpisový profil, což je u zprávy odložené o hodiny to, co chceme.
 *
 * Soubory se do fronty ukládají jako CESTA; generované ISDOC jako UTF-8 obsah. Když soubor mezitím zmizí,
 * odeslání selže a řádek skončí ve `failed` s chybou — což je vidět, na
 * rozdíl od tiché ztráty.
 *
 * ⚠️ Do fronty smí vstoupit jen zpráva, která už PROŠLA rozdělením podle
 * {@see MailRecipientBatcher}. Fronta neopravuje překročený počet příjemců —
 * u toho je odmítnutí trvalé, takže by se odložená zpráva jen po hodině
 * ztratila. Drží to i CHECK v migraci 1520.
 */
final class MailOutbox
{
    public const STATUS_PENDING  = 'pending';
    public const STATUS_SENT     = 'sent';
    public const STATUS_FAILED   = 'failed';
    /** Zpráva při vyprazdňování narazila na brzdu znovu a pokračuje jako nový řádek. */
    public const STATUS_REQUEUED = 'requeued';

    /** Po tolika neúspěšných pokusech se zpráva označí jako failed a přestane se zkoušet. */
    public const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly PDO $pdo,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param array<string,mixed> $payload
     * @return int|null id řádku ve frontě, null když se nepodařilo uložit
     */
    public function enqueue(
        DateTimeImmutable $now,
        DateTimeImmutable $notBefore,
        string $template,
        string $locale,
        int $recipients,
        ?string $deferReason,
        array $payload,
    ): ?int {
        if ($recipients > MailRecipientBatcher::HARD_MAX_RECIPIENTS) {
            // Sem se to nemá jak dostat — batcher dělí dřív. Kdyby ano, je to
            // chyba v kódu, ne provozní stav: do fronty to nepustíme, protože
            // taková zpráva by se stejně nikdy nedoručila.
            $this->logger->error('mail.outbox_recipients_over_hard_limit', [
                'template'   => $template,
                'recipients' => $recipients,
                'hard_limit' => MailRecipientBatcher::HARD_MAX_RECIPIENTS,
            ]);

            return null;
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            // Neserializovatelné proměnné šablony. Zprávu do fronty uložit
            // nelze — volající ji musí poslat rovnou a nechat případné 451
            // na hostingu. Ztratit ji kvůli diagnostice by bylo horší.
            $this->logger->error('mail.outbox_payload_not_serializable', [
                'template' => $template,
                'error'    => json_last_error_msg(),
            ]);

            return null;
        }

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO mail_outbox
                    (created_at, not_before, status, attempts, template, locale, recipients, defer_reason, payload)
                 VALUES (:created_at, :not_before, :status, 0, :template, :locale, :recipients, :defer_reason, :payload)'
            );
            $stmt->execute([
                'created_at'   => $now->format(MailSendCounter::SQL_FORMAT),
                'not_before'   => $notBefore->format(MailSendCounter::SQL_FORMAT),
                'status'       => self::STATUS_PENDING,
                'template'     => mb_substr($template, 0, 64),
                'locale'       => mb_substr($locale, 0, 8),
                'recipients'   => $recipients,
                'defer_reason' => $deferReason !== null ? mb_substr($deferReason, 0, 32) : null,
                'payload'      => $json,
            ]);

            return (int) $this->pdo->lastInsertId();
        } catch (Throwable $e) {
            $this->logger->error('mail.outbox_enqueue_failed', [
                'template' => $template,
                'error'    => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Zprávy, na které už došla řada.
     *
     * @return list<array{id:int,template:string,locale:string,attempts:int,payload:array<string,mixed>}>
     */
    public function due(DateTimeImmutable $now, int $limit = 25): array
    {
        $limit = max(1, min($limit, 200));

        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, template, locale, attempts, payload
                   FROM mail_outbox
                  WHERE status = :status AND not_before <= :now
                  ORDER BY not_before, id
                  LIMIT ' . $limit
            );
            $stmt->execute([
                'status' => self::STATUS_PENDING,
                'now'    => $now->format(MailSendCounter::SQL_FORMAT),
            ]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $payload = json_decode((string) $row['payload'], true);
            if (!is_array($payload)) {
                $this->markFailed((int) $row['id'], 'nečitelný payload');
                continue;
            }
            $out[] = [
                'id'       => (int) $row['id'],
                'template' => (string) $row['template'],
                'locale'   => (string) $row['locale'],
                'attempts' => (int) $row['attempts'],
                'payload'  => $payload,
            ];
        }

        return $out;
    }

    public function markSent(int $id, DateTimeImmutable $now): void
    {
        $this->update(
            'UPDATE mail_outbox SET status = :status, sent_at = :sent_at, attempts = attempts + 1 WHERE id = :id',
            ['status' => self::STATUS_SENT, 'sent_at' => $now->format(MailSendCounter::SQL_FORMAT), 'id' => $id],
        );
    }

    /**
     * Zpráva se při vyprazdňování fronty znovu nevešla do limitu a leží ve
     * frontě pod novým id. Označit ji za odeslanou by lhalo, za chybu taky —
     * nic se nestalo špatně, jen se posunula.
     */
    public function markRequeued(int $id, DateTimeImmutable $now, ?int $newId): void
    {
        $this->update(
            'UPDATE mail_outbox SET status = :status, attempts = attempts + 1, sent_at = :at, last_error = :note WHERE id = :id',
            [
                'status' => self::STATUS_REQUEUED,
                'at'     => $now->format(MailSendCounter::SQL_FORMAT),
                'note'   => $newId !== null ? 'znovu odloženo brzdou, pokračuje jako #' . $newId : 'znovu odloženo brzdou',
                'id'     => $id,
            ],
        );
    }

    /** Neúspěch, který stojí za další pokus — zpráva zůstane pending. */
    public function markRetry(int $id, DateTimeImmutable $notBefore, string $error, int $attempts): void
    {
        if ($attempts + 1 >= self::MAX_ATTEMPTS) {
            $this->markFailed($id, $error);

            return;
        }

        $this->update(
            'UPDATE mail_outbox SET not_before = :not_before, attempts = attempts + 1, last_error = :error WHERE id = :id',
            [
                'not_before' => $notBefore->format(MailSendCounter::SQL_FORMAT),
                'error'      => mb_substr($error, 0, 2000),
                'id'         => $id,
            ],
        );
    }

    public function markFailed(int $id, string $error): void
    {
        $this->update(
            'UPDATE mail_outbox SET status = :status, attempts = attempts + 1, last_error = :error WHERE id = :id',
            ['status' => self::STATUS_FAILED, 'error' => mb_substr($error, 0, 2000), 'id' => $id],
        );
    }

    /** Kolik zpráv čeká — pro diagnostiku a pro upozornění správci. */
    public function pendingCount(): int
    {
        try {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM mail_outbox WHERE status = :status');
            $stmt->execute(['status' => self::STATUS_PENDING]);

            return (int) $stmt->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    /** @param array<string,mixed> $params */
    private function update(string $sql, array $params): void
    {
        try {
            $this->pdo->prepare($sql)->execute($params);
        } catch (Throwable $e) {
            $this->logger->error('mail.outbox_update_failed', ['error' => $e->getMessage()]);
        }
    }
}

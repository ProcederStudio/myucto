<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Ai;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Ai\AccountingAssistantException;
use MyInvoice\Service\Ai\AiAssistantHistoryService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class AiAssistantHistoryServiceTest extends TestCase
{
    private PDO $pdo;
    private AiAssistantHistoryService $history;
    private int $supplierId;
    private int $userId;
    private int $otherUserId;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }
        $c = Bootstrap::buildContainer();
        $this->pdo = $c->get(Connection::class)->pdo();
        $this->history = $c->get(AiAssistantHistoryService::class);
        $this->pdo->beginTransaction();
        $this->supplierId = (int) $this->pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        if ($this->supplierId <= 0) {
            $this->markTestSkipped('Chybí firma.');
        }
        $insert = $this->pdo->prepare("INSERT INTO users (email, password_hash, name, role_id, locale, is_active)
            VALUES (?, 'disabled-test-password', 'Synthetic AI history user', NULL, 'cs', 1)");
        $ids = [];
        foreach (['a', 'b'] as $suffix) {
            $insert->execute(['ai-history-' . $suffix . '-' . bin2hex(random_bytes(4)) . '@example.invalid']);
            $ids[] = (int) $this->pdo->lastInsertId();
        }
        [$this->userId, $this->otherUserId] = $ids;
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
    }

    /** @return array<string,mixed> */
    private static function aiResult(string $answer): array
    {
        return [
            'answer' => $answer, 'other_items' => [], 'journal_entries' => [['entry_date' => '2026-03-01', 'lines' => []]],
            'document_suggestions' => [], 'warnings' => [], 'provider' => 'anthropic', 'model' => 'claude-test',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ];
    }

    public function testConversationIsRecordedListedAndUsedAsContext(): void
    {
        $first = $this->history->record($this->supplierId, $this->userId, null, 'Jak zaúčtovat upsaný kapitál?',
            [['kind' => 'file', 'name' => 'zapis.pdf']], self::aiResult('353/411'));
        $second = $this->history->record($this->supplierId, $this->userId, $first['conversation_id'], 'A splacení?', [], self::aiResult('211/353'));

        self::assertSame($first['conversation_id'], $second['conversation_id']);
        $list = $this->history->list($this->supplierId, $this->userId);
        self::assertSame('Jak zaúčtovat upsaný kapitál?', $list[0]['title']);
        self::assertSame(2, $list[0]['question_count']);

        $conv = $this->history->get($this->supplierId, $this->userId, $first['conversation_id']);
        self::assertCount(4, $conv['messages']);
        self::assertSame([['kind' => 'file', 'name' => 'zapis.pdf']], $conv['messages'][0]['attachments']);
        self::assertSame('353/411', $conv['messages'][1]['content']);
        self::assertArrayNotHasKey('usage', $conv['messages'][1]['response']);
        self::assertSame('2026-03-01', $conv['messages'][1]['response']['journal_entries'][0]['entry_date']);

        $context = $this->history->contextHistory($this->supplierId, $this->userId, $first['conversation_id']);
        self::assertSame(['user', 'assistant', 'user', 'assistant'], array_column($context, 'role'));
    }

    /** Cizí konverzace „neexistuje": ani čtení, ani pokračování, ani mazání či značky. */
    public function testConversationIsPrivateToItsAuthor(): void
    {
        $own = $this->history->record($this->supplierId, $this->userId, null, 'Soukromý dotaz', [], self::aiResult('x'));
        self::assertSame([], array_filter($this->history->list($this->supplierId, $this->otherUserId),
            fn (array $c): bool => $c['id'] === $own['conversation_id']));
        foreach ([
            fn () => $this->history->get($this->supplierId, $this->otherUserId, $own['conversation_id']),
            fn () => $this->history->record($this->supplierId, $this->otherUserId, $own['conversation_id'], 'cizí', [], self::aiResult('y')),
            fn () => $this->history->delete($this->supplierId, $this->otherUserId, $own['conversation_id']),
            fn () => $this->history->markApplied($this->supplierId, $this->otherUserId, $own['message_id'], 'journal_entry', 0, 5),
            fn () => $this->history->get($this->supplierId + 100000, $this->userId, $own['conversation_id']),
        ] as $call) {
            try {
                $call();
                self::fail('Cizí konverzace musí být nedostupná.');
            } catch (AccountingAssistantException $e) {
                self::assertSame(404, $e->httpStatus);
            }
        }
    }

    public function testAppliedProposalsAreRememberedAndDeleteRemovesConversation(): void
    {
        $rec = $this->history->record($this->supplierId, $this->userId, null, 'Dotaz', [], self::aiResult('a'));
        $this->history->markApplied($this->supplierId, $this->userId, $rec['message_id'], 'journal_entry', 0, 77);
        $applied = $this->history->markApplied($this->supplierId, $this->userId, $rec['message_id'], 'other_item', 1, 88);
        self::assertSame(['journal_entry' => ['0' => 77], 'other_item' => ['1' => 88]], $applied);
        self::assertSame($applied, $this->history->get($this->supplierId, $this->userId, $rec['conversation_id'])['messages'][1]['applied']);

        $this->history->delete($this->supplierId, $this->userId, $rec['conversation_id']);
        $left = $this->pdo->prepare('SELECT COUNT(*) FROM ai_assistant_messages WHERE conversation_id = ?');
        $left->execute([$rec['conversation_id']]);
        self::assertSame(0, (int) $left->fetchColumn());
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\MyInvoice;

use MyInvoice\Service\Migration\MyInvoice\MyInvoiceSqlDumpReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MyInvoiceSqlDumpReaderTest extends TestCase
{
    public function testDumpCommentsEscapesBinaryAndLargeIntegerStayLiteral(): void
    {
        $sql = <<<'SQL'
/*M!999999\- enable the sandbox mode */
-- MariaDB dump
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
DROP TABLE IF EXISTS `synthetic`;
CREATE TABLE `synthetic` (
  `id` bigint NOT NULL,
  `description` text,
  `amount` decimal(14,2),
  `blob` longblob,
  KEY `idx` (`id`)
) ENGINE=InnoDB;
LOCK TABLES `synthetic` WRITE;
INSERT INTO `synthetic` VALUES (9007199254740993,'O\'Brien,;\n\\ /* data */',-123.45,0x00ff),(2,'two''quotes',NULL,_binary'\0\Z');
UNLOCK TABLES;
SQL;
        $rows = $this->read($sql);
        self::assertCount(2, $rows);
        self::assertSame('9007199254740993', $rows[0]['row']['id']);
        self::assertSame("O'Brien,;\n\\ /* data */", $rows[0]['row']['description']);
        self::assertSame('-123.45', $rows[0]['row']['amount']);
        self::assertSame("\0\xff", $rows[0]['row']['blob']);
        self::assertSame("two'quotes", $rows[1]['row']['description']);
        self::assertNull($rows[1]['row']['amount']);
        self::assertSame("\0\x1a", $rows[1]['row']['blob']);
    }

    public function testRoutineBodyAndExecutableCommentsCannotAddData(): void
    {
        $sql = "CREATE TABLE `synthetic` (\n `id` int\n);\n" . <<<'SQL'
DELIMITER ;;
CREATE PROCEDURE `unused`() BEGIN
INSERT INTO `synthetic` VALUES (999);
END ;;
DELIMITER ;
/*!50003 INSERT INTO `synthetic` VALUES (888) */;
INSERT INTO `synthetic` (`id`) VALUES (1);
SQL;
        self::assertSame([['table' => 'synthetic', 'row' => ['id' => '1']]], $this->read($sql));
    }

    #[DataProvider('invalidSql')]
    public function testUnsupportedOrTruncatedDataIsRejected(string $sql): void
    {
        $this->expectException(RuntimeException::class);
        $this->read("CREATE TABLE `synthetic` (\n `id` int\n);\n" . $sql);
    }

    public static function invalidSql(): iterable
    {
        yield 'expression' => ['INSERT INTO `synthetic` VALUES (SLEEP(10));'];
        yield 'select' => ['INSERT INTO `synthetic` SELECT 1;'];
        yield 'truncated statement' => ['INSERT INTO `synthetic` VALUES (1)'];
        yield 'truncated string' => ["INSERT INTO `synthetic` VALUES ('unfinished);"];
        yield 'wrong column count' => ['INSERT INTO `synthetic` VALUES (1,2);'];
        yield 'on duplicate' => ['INSERT INTO `synthetic` VALUES (1) ON DUPLICATE KEY UPDATE id=2;'];
        yield 'trailing comma' => ['INSERT INTO `synthetic` VALUES (1),;'];
        yield 'unknown column' => ['INSERT INTO `synthetic` (`other`) VALUES (1);'];
        yield 'schema-qualified insert' => ['INSERT INTO `live`.`synthetic` VALUES (1);'];
        yield 'update' => ['UPDATE `synthetic` SET id=1;'];
        yield 'unsupported delimiter' => ["DELIMITER invalid\n"];
    }

    private function read(string $sql): array
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $sql);
        rewind($stream);
        try {
            return iterator_to_array((new MyInvoiceSqlDumpReader())->rows($stream, ['synthetic' => true]));
        } finally {
            fclose($stream);
        }
    }
}

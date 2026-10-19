<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization;

final class FinancialArchiveSpool
{
    /** @var resource|null */
    private $stream;

    private function __construct($stream, public readonly string $sha256, public readonly int $bytes)
    {
        if (!is_resource($stream) || preg_match('/^[a-f0-9]{64}$/D',$sha256) !== 1 || $bytes < 0) {
            throw new \InvalidArgumentException('Financial archive spool je neplatný.');
        }
        $this->stream = $stream;
    }

    public static function fromString(string $value): self
    {
        return self::fromChunks([$value]);
    }

    /** @param iterable<string> $chunks */
    public static function fromChunks(iterable $chunks): self
    {
        $stream = tmpfile();
        if (!is_resource($stream)) {
            throw new \RuntimeException('Financial archive spool nelze otevřít.');
        }
        $hash = hash_init('sha256');
        $bytes = 0;
        try {
            foreach ($chunks as $chunk) {
                if (!is_string($chunk)) {
                    throw new \InvalidArgumentException('Financial archive spool přijímá pouze byte chunks.');
                }
                $length = strlen($chunk);
                $written = 0;
                while ($written < $length) {
                    $count = fwrite($stream,substr($chunk,$written));
                    if (!is_int($count) || $count < 1) {
                        throw new \RuntimeException('Financial archive spool nelze zapsat.');
                    }
                    $written += $count;
                }
                hash_update($hash,$chunk);
                $bytes += $length;
            }
            if (!fflush($stream) || !rewind($stream)) {
                throw new \RuntimeException('Financial archive spool nelze dokončit.');
            }
            return new self($stream,hash_final($hash),$bytes);
        } catch (\Throwable $e) {
            fclose($stream);
            throw $e;
        }
    }

    /** @return resource */
    public function stream()
    {
        if (!is_resource($this->stream) || !rewind($this->stream)) {
            throw new \LogicException('Financial archive spool už není dostupný.');
        }
        return $this->stream;
    }

    public function __destruct()
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
        $this->stream = null;
    }
}

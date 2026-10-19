<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization\Streaming;

final readonly class JsonStreamValue
{
    private \Closure $factory;

    /** @param callable():iterable<string> $factory */
    public function __construct(callable $factory)
    {
        $this->factory = $factory(...);
    }

    /** @return iterable<string> */
    public function chunks(): iterable
    {
        $chunks = ($this->factory)();
        if (is_array($chunks)) {
            throw new \InvalidArgumentException('JsonStreamValue musí vracet proud, nikoli PHP pole.');
        }
        foreach ($chunks as $chunk) {
            if (!is_string($chunk) || $chunk === '') {
                throw new \InvalidArgumentException('JsonStreamValue musí obsahovat neprázdné byte chunks.');
            }
            yield $chunk;
        }
    }
}

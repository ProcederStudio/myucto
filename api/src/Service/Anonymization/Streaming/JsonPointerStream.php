<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization\Streaming;

use JsonMachine\JsonDecoder\ExtJsonDecoder;
use JsonMachine\RecursiveItems;

final class JsonPointerStream
{
    private \Closure $chunks;

    /** @param callable():iterable<string> $chunks */
    public function __construct(callable $chunks)
    {
        $this->chunks = $chunks(...);
    }

    /**
     * @param list<string> $excluded relative JSON pointers
     * @param array<string,int|float|string|bool|null|JsonStreamValue> $replacements
     * @return iterable<string>
     */
    public function at(string $pointer, array $excluded = [], array $replacements = []): iterable
    {
        if ($pointer === '' || $pointer[0] !== '/') {
            throw new \InvalidArgumentException('JSON pointer musí být absolutní a neprázdný.');
        }
        $excluded = array_fill_keys($excluded, true);
        $items = RecursiveItems::fromIterable(($this->chunks)(), [
            'pointer' => $pointer,
            'decoder' => new ExtJsonDecoder(true, 512, 0),
        ]);

        return $this->emit($items, '', $excluded, $replacements, 1);
    }

    /** @return iterable<string> */
    public function document(): iterable
    {
        $items = RecursiveItems::fromIterable(($this->chunks)(), [
            'decoder' => new ExtJsonDecoder(true, 512, 0),
        ]);

        return $this->emit($items, '', [], [], 1);
    }

    /**
     * @param array<string,true> $excluded
     * @param array<string,int|float|string|bool|null|JsonStreamValue> $replacements
     * @return \Generator<int,string>
     */
    private function emit(RecursiveItems $items, string $path, array $excluded, array $replacements, int $depth): \Generator
    {
        if ($depth > 512) {
            throw new \JsonException('Maximum stack depth exceeded', JSON_ERROR_DEPTH);
        }
        yield '{';
        $ordinal = 0;
        foreach ($items as $key => $value) {
            $key = self::normalizeKey($key);
            $childPath = $path . '/' . self::escape((string) $key);
            if (isset($excluded[$childPath])) {
                continue;
            }
            if ($ordinal++ > 0) {
                yield ',';
            }
            yield json_encode((string) $key, CanonicalJsonProfile::NONPROFIT->flags(), 512) . ':';
            if (array_key_exists($childPath, $replacements)) {
                $replacement = $replacements[$childPath];
                if ($replacement instanceof JsonStreamValue) {
                    yield from $replacement->chunks();
                } else {
                    yield json_encode($replacement, CanonicalJsonProfile::NONPROFIT->flags(), 512);
                }
                continue;
            }
            if ($value instanceof RecursiveItems) {
                yield from $this->emit($value, $childPath, $excluded, $replacements, $depth + 1);
            } else {
                yield json_encode($value, CanonicalJsonProfile::NONPROFIT->flags(), 512);
            }
        }
        yield '}';
    }

    private static function normalizeKey(mixed $key): int|string
    {
        if (!is_int($key) && !is_string($key)) {
            throw new \UnexpectedValueException('JSON stream obsahuje neplatný klíč.');
        }
        $probe = [];
        $probe[$key] = true;
        return array_key_first($probe);
    }

    private static function escape(string $segment): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $segment);
    }
}

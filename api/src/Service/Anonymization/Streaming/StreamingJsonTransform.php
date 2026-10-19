<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization\Streaming;

use JsonMachine\JsonDecoder\ExtJsonDecoder;
use JsonMachine\RecursiveItems;

final class StreamingJsonTransform
{
    /**
     * @param iterable<string> $chunks
     * @return iterable<string>
     */
    public function transform(
        iterable $chunks,
        JsonScalarTransformer $transformer,
        CanonicalJsonProfile $profile,
    ): iterable {
        $root = RecursiveItems::fromIterable($chunks, ['decoder' => new ExtJsonDecoder(true, 512, 0)]);

        return $this->emitNode($root, [], $transformer, $profile, 1);
    }

    /**
     * @param list<int|string> $path
     * @return \Generator<int,string>
     */
    private function emitNode(
        RecursiveItems $items,
        array $path,
        JsonScalarTransformer $transformer,
        CanonicalJsonProfile $profile,
        int $depth,
    ): \Generator {
        if ($depth > 512) {
            throw new \JsonException('Maximum stack depth exceeded', JSON_ERROR_DEPTH);
        }
        yield '{';
        $ordinal = 0;
        foreach ($items as $key => $value) {
            $key = self::normalizeKey($key);
            if ($ordinal++ > 0) {
                yield ',';
            }
            yield json_encode((string) $key, $profile->flags(), 512) . ':';
            $childPath = [...$path, $key];
            if ($value instanceof RecursiveItems) {
                yield from $this->emitNode($value, $childPath, $transformer, $profile, $depth + 1);
                continue;
            }
            $transformed = $transformer->transform($childPath, $value);
            if (is_array($transformed) || is_object($transformed) || is_resource($transformed)) {
                throw new \LogicException('Streaming JSON scalar transform nesmí změnit strukturu dokumentu.');
            }
            yield json_encode($transformed, $profile->flags(), 512);
        }
        yield '}';
    }

    private static function normalizeKey(mixed $key): int|string
    {
        if (!is_int($key) && !is_string($key)) {
            throw new \InvalidArgumentException('Streaming JSON obsahuje neplatný klíč.');
        }
        $probe = [];
        $probe[$key] = true;

        return array_key_first($probe);
    }
}

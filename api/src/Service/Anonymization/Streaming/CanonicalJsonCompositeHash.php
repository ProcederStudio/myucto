<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization\Streaming;

final class CanonicalJsonCompositeHash
{
    /**
     * @param array<string,int|float|string|bool|null|JsonStreamValue> $fields
     */
    public function hash(array $fields, CanonicalJsonProfile $profile = CanonicalJsonProfile::NONPROFIT): CanonicalJsonResult
    {
        foreach ($fields as $key => $value) {
            if (!is_string($key) || $key === '') {
                throw new \InvalidArgumentException('Composite JSON vyžaduje neprázdné textové klíče.');
            }
            if (is_array($value) || is_object($value) && !$value instanceof JsonStreamValue || is_resource($value)) {
                throw new \InvalidArgumentException('Vnořený JSON musí dodat JsonStreamValue, ne PHP pole nebo objekt.');
            }
        }

        return (new CanonicalJsonSpool())->canonicalize($this->chunks($fields, $profile), $profile);
    }

    /**
     * @param array<string,int|float|string|bool|null|JsonStreamValue> $fields
     * @return iterable<string>
     */
    private function chunks(array $fields, CanonicalJsonProfile $profile): iterable
    {
        yield '{';
        $first = true;
        foreach ($fields as $key => $value) {
            yield ($first ? '' : ',') . json_encode($key, $profile->flags()) . ':';
            $first = false;
            if ($value instanceof JsonStreamValue) {
                yield from $value->chunks();
            } else {
                yield json_encode($value, $profile->flags());
            }
        }
        yield '}';
    }
}

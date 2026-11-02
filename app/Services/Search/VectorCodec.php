<?php

namespace App\Services\Search;

/** Packs float vectors as little-endian float32 binary (4 bytes/dim). */
final class VectorCodec
{
    /** @param  list<float>  $vector */
    public static function pack(array $vector): string
    {
        return pack('g*', ...$vector);
    }

    /** @return list<float> */
    public static function unpack(string $binary): array
    {
        return array_values(unpack('g*', $binary));
    }

    /** @param  list<float>  $vector  @return list<float> */
    public static function normalize(array $vector): array
    {
        $norm = sqrt(array_sum(array_map(fn ($x) => $x * $x, $vector)));

        return $norm > 0 ? array_map(fn ($x) => $x / $norm, $vector) : $vector;
    }

    /** Dot product == cosine similarity for normalized vectors. */
    public static function dot(array $a, array $b): float
    {
        $sum = 0.0;
        foreach ($a as $i => $v) {
            $sum += $v * $b[$i];
        }

        return $sum;
    }
}

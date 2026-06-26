<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Support;

/**
 * Pure vector math helpers shared by the in-memory store, MMR and retrieval.
 */
final class Vectors
{
    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    public static function dot(array $a, array $b): float
    {
        $sum = 0.0;
        $len = min(count($a), count($b));

        for ($i = 0; $i < $len; $i++) {
            $sum += $a[$i] * $b[$i];
        }

        return $sum;
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    public static function cosine(array $a, array $b): float
    {
        $magA = sqrt(self::dot($a, $a));
        $magB = sqrt(self::dot($b, $b));

        if ($magA <= 0.0 || $magB <= 0.0) {
            return 0.0;
        }

        return self::dot($a, $b) / ($magA * $magB);
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    public static function euclidean(array $a, array $b): float
    {
        $sum = 0.0;
        $len = min(count($a), count($b));

        for ($i = 0; $i < $len; $i++) {
            $diff = $a[$i] - $b[$i];
            $sum += $diff * $diff;
        }

        return sqrt($sum);
    }
}

<?php

declare(strict_types=1);

namespace Survos\GeoBoundariesBundle\Service;

/**
 * Minimal GeoJSON geometry helpers (Polygon / MultiPolygon, lon/lat). Shared by the runtime
 * service and the admin build, so "contains" means the same thing when parents are derived at
 * build time and when a point is located at query time.
 */
final class Geometry
{
    /**
     * @param array<string, mixed> $geometry
     *
     * @return array{0: float, 1: float, 2: float, 3: float}|null minX, minY, maxX, maxY
     */
    public static function bbox(array $geometry): ?array
    {
        $minX = $minY = INF;
        $maxX = $maxY = -INF;
        foreach (self::polygons($geometry) as $polygon) {
            foreach ($polygon[0] ?? [] as [$x, $y]) {
                $minX = min($minX, $x);
                $maxX = max($maxX, $x);
                $minY = min($minY, $y);
                $maxY = max($maxY, $y);
            }
        }

        return is_infinite($minX) ? null : [(float) $minX, (float) $minY, (float) $maxX, (float) $maxY];
    }

    /** @param array<string, mixed> $geometry */
    public static function contains(array $geometry, float $lon, float $lat): bool
    {
        foreach (self::polygons($geometry) as $polygon) {
            if (!isset($polygon[0]) || !self::ringContains($polygon[0], $lon, $lat)) {
                continue;
            }
            $inHole = false;
            foreach (array_slice($polygon, 1) as $hole) {
                if (self::ringContains($hole, $lon, $lat)) {
                    $inHole = true;
                    break;
                }
            }
            if (!$inHole) {
                return true;
            }
        }

        return false;
    }

    /**
     * A point that is inside the geometry (unlike a bbox centre, which can fall outside a
     * crescent-shaped unit): the centroid of the largest polygon if it lies inside, else the
     * first grid point over that polygon's bbox that does.
     *
     * @param array<string, mixed> $geometry
     *
     * @return array{0: float, 1: float}|null lon, lat
     */
    public static function representativePoint(array $geometry): ?array
    {
        $largest = null;
        $largestArea = -1.0;
        foreach (self::polygons($geometry) as $polygon) {
            $area = abs(self::ringArea($polygon[0] ?? []));
            if ($area > $largestArea) {
                $largestArea = $area;
                $largest = $polygon;
            }
        }
        if (null === $largest || [] === ($largest[0] ?? [])) {
            return null;
        }

        $single = ['type' => 'Polygon', 'coordinates' => $largest];
        $centroid = self::ringCentroid($largest[0]);
        if (null !== $centroid && self::contains($single, $centroid[0], $centroid[1])) {
            return $centroid;
        }

        $bbox = self::bbox($single);
        if (null !== $bbox) {
            $steps = 9;
            for ($i = 1; $i < $steps; ++$i) {
                for ($j = 1; $j < $steps; ++$j) {
                    $x = $bbox[0] + ($bbox[2] - $bbox[0]) * $i / $steps;
                    $y = $bbox[1] + ($bbox[3] - $bbox[1]) * $j / $steps;
                    if (self::contains($single, $x, $y)) {
                        return [$x, $y];
                    }
                }
            }
        }

        $first = $largest[0][0];

        return [(float) $first[0], (float) $first[1]];
    }

    /**
     * @param array<string, mixed> $geometry
     *
     * @return iterable<list<list<array{0: float, 1: float}>>> polygons as lists of rings
     */
    private static function polygons(array $geometry): iterable
    {
        $coordinates = $geometry['coordinates'] ?? [];
        switch ($geometry['type'] ?? null) {
            case 'Polygon':
                yield $coordinates;
                break;
            case 'MultiPolygon':
                yield from $coordinates;
                break;
        }
    }

    /** @param list<array{0: float, 1: float}> $ring */
    private static function ringContains(array $ring, float $x, float $y): bool
    {
        $inside = false;
        $n = count($ring);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            [$xi, $yi] = $ring[$i];
            [$xj, $yj] = $ring[$j];
            if (($yi > $y) !== ($yj > $y) && $x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi) {
                $inside = !$inside;
            }
        }

        return $inside;
    }

    /** @param list<array{0: float, 1: float}> $ring */
    private static function ringArea(array $ring): float
    {
        $sum = 0.0;
        $n = count($ring);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $sum += ($ring[$j][0] * $ring[$i][1]) - ($ring[$i][0] * $ring[$j][1]);
        }

        return $sum / 2;
    }

    /**
     * @param list<array{0: float, 1: float}> $ring
     *
     * @return array{0: float, 1: float}|null
     */
    private static function ringCentroid(array $ring): ?array
    {
        $area = self::ringArea($ring);
        if (0.0 === $area) {
            return null;
        }
        $cx = $cy = 0.0;
        $n = count($ring);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $cross = ($ring[$j][0] * $ring[$i][1]) - ($ring[$i][0] * $ring[$j][1]);
            $cx += ($ring[$j][0] + $ring[$i][0]) * $cross;
            $cy += ($ring[$j][1] + $ring[$i][1]) * $cross;
        }

        return [$cx / (6 * $area), $cy / (6 * $area)];
    }
}

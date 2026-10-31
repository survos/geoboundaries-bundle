<?php

declare(strict_types=1);

namespace Survos\GeoBoundariesBundle\Dto;

final readonly class Boundary
{
    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $bbox     minX, minY, maxX, maxY (lon/lat)
     * @param array<string, mixed>|null                     $geometry GeoJSON geometry; only loaded on request
     */
    public function __construct(
        public int $id,
        public string $shapeId,
        public string $iso3,
        public int $level,
        public string $name,
        public ?int $parentId,
        public ?int $geonameId,
        public array $bbox,
        public ?array $geometry = null,
    ) {
    }

    public function levelCode(): string
    {
        return 'ADM'.$this->level;
    }
}

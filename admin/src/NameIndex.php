<?php

declare(strict_types=1);

namespace Survos\GeoBoundariesAdmin;

/**
 * Name lookup over one set of GeoNames rows (a country's admin1, or one admin1's admin2), tiered
 * from strict to loose. A tier only answers when it identifies exactly one GeoNames id; two
 * different ids under the same key is "ambiguous" and is reported as such rather than guessed,
 * so a wrong link can never come from picking the first of several.
 *
 * Methods reported: name (case-insensitive exact), normalized (diacritics/punctuation folded,
 * abbreviations expanded), stripped (also without generic words like "province"), squashed
 * (also ignoring spaces: "De Witt"/"DeWitt"), ambiguous, none.
 */
final class NameIndex
{
    /** Words that differ between sources without changing which unit is meant. */
    private const GENERIC = [
        'province', 'state', 'county', 'district', 'region', 'department', 'municipality', 'prefecture',
        'governorate', 'canton', 'oblast', 'city', 'of', 'the', 'autonomous', 'parish', 'borough',
        'census', 'area',
    ];

    /** Abbreviations expanded on both sides, so "St. Clair" meets "Saint Clair". */
    private const ABBREVIATIONS = ['st' => 'saint', 'ste' => 'sainte', 'mt' => 'mount', 'ft' => 'fort'];

    /** @var array<string, array<string, array<int, true>>> tier => key => geoname ids */
    private array $tiers = ['name' => [], 'normalized' => [], 'stripped' => [], 'squashed' => []];

    /** @param iterable<array{id: int, name: string, ascii: ?string}> $rows */
    public function __construct(iterable $rows)
    {
        foreach ($rows as $row) {
            foreach (array_filter([$row['name'], $row['ascii']]) as $name) {
                $this->add('name', mb_strtolower(trim($name)), $row['id']);
                $this->add('normalized', self::normalize($name), $row['id']);
                $this->add('stripped', self::strip($name), $row['id']);
                $this->add('squashed', str_replace(' ', '', self::strip($name)), $row['id']);
            }
        }
    }

    /** @return array{0: ?int, 1: string} geoname id (null unless unique) and the method that decided */
    public function match(string $name): array
    {
        $keys = [
            'name' => mb_strtolower(trim($name)),
            'normalized' => self::normalize($name),
            'stripped' => self::strip($name),
            'squashed' => str_replace(' ', '', self::strip($name)),
        ];
        $ambiguous = false;
        foreach ($keys as $tier => $key) {
            if ('' === $key) {
                continue;
            }
            $ids = array_keys($this->tiers[$tier][$key] ?? []);
            if (1 === count($ids)) {
                return [$ids[0], $tier];
            }
            $ambiguous = $ambiguous || count($ids) > 1;
        }

        return [null, $ambiguous ? 'ambiguous' : 'none'];
    }

    public static function normalize(string $name): string
    {
        $name = mb_strtolower(trim($name));
        if (class_exists(\Transliterator::class) && null !== ($t = \Transliterator::create('Any-Latin; Latin-ASCII'))) {
            $name = $t->transliterate($name) ?: $name;
        }

        $words = explode(' ', trim((string) preg_replace('/[^a-z0-9]+/', ' ', $name)));

        return implode(' ', array_map(static fn (string $w): string => self::ABBREVIATIONS[$w] ?? $w, $words));
    }

    private static function strip(string $name): string
    {
        $words = array_filter(
            explode(' ', self::normalize($name)),
            static fn (string $w): bool => '' !== $w && !in_array($w, self::GENERIC, true),
        );

        return implode(' ', $words);
    }

    private function add(string $tier, string $key, int $id): void
    {
        if ('' !== $key) {
            $this->tiers[$tier][$key][$id] = true;
        }
    }
}

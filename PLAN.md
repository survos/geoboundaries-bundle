# geoBoundaries Bundle Plan

## Goal

A boundary authority beside `geonames-bundle`: same build-offline / publish-SQLite / fetch-and-query
shape, but the payload is polygons, and the key question it answers is "what unit contains this
point?" rather than "what is this place called?".

Decisions (2026-10-31): gbOpen only; simplified geometry; ADM0-ADM2 by default (`--max-level`).

## Built (scaffold)

- Bundle on `kit-bundle` (`SurvosGeoBoundariesBundle`, `sqlite_dir` config), `GeoBoundariesService`,
  `Geometry` (bbox / point-in-polygon / representative point), `Boundary` DTO, `survos:geoboundaries`
  fetch command (HF dataset `museado/geoboundaries-data`, not yet created).
- `admin/` `download` (API listing + simplified GeoJSON, retry + pause) and `build` (per-country
  SQLite, R*Tree, parents by containment, `geo.sqlite` with `country`/`release`/ADM0), `publish.sh`.
- Tests: 7 passing (fixture build, locate, parents, holes/multipolygons, concave rep. point).
- Smoke-tested live on LIE (ADM0 + 11 ADM1, no orphans).

## Crosswalk (built 2026-10-31)

`admin.php crosswalk` links units to GeoNames ids using geonames-bundle's `geo.sqlite`
(`GEONAMES_DATA_DIR`): ADM0 by ISO3; ADM1 by name against `admin1`; ADM2 by name against `admin2`
restricted to the linked ADM1 parent (admin2 names repeat across admin1s). ADM3+ has no GeoNames
counterpart. Only unique matches are written; `geoname_match` records the tier (`iso3`, `name`,
`normalized`, `stripped`, `squashed`) or `ambiguous` (several fit, none chosen). Re-running clears
and recomputes. Read back with `Boundary::$geonameId` / `$geonameMatch` and
`GeoBoundariesService::findByGeonameId()`.

Real-data result: LIE ADM1 11/11; USA ADM1 51/56, ADM2 3117/3233 (96%). The misses are mostly real
mismatches, not matcher bugs: US territories are separate countries in GeoNames (PR alone is 77
municipios); Virginia/Maryland independent cities share names with counties (ambiguous, left
unlinked on purpose); Connecticut/Alaska units differ between the two sources. No ISO-3166-2 tier:
GeoNames admin1 codes are often FIPS, so a code match could link the wrong unit.

## Not done

1. **Crosswalk across the world.** Built and verified on LIE and USA (below); other countries
   will have lower rates where GeoNames and geoBoundaries cut ADM levels differently.
2. **Scale run.** Only LIE has been built. Run ADM0-ADM2 for the world; watch parent orphan
   counts on big countries and the size of `<iso3>.sqlite` (GeoJSON text geometry; WKB is the
   fallback if too large). Full-resolution geometry (`--geometry=full`) is untested.
3. **Rate limits.** The API/GitHub LFS throttling at world scale is unknown; `--delay-ms` and
   resume-by-skip exist, nothing more.
4. **Publish.** Create the HF dataset, run `publish.sh`; `survos:geoboundaries` is untested
   against a real dataset.
5. **Licenses.** Layers carry source licenses (ODbL appears). Decide whether to filter, or to
   surface license in `Boundary`.
6. **Disputed areas / CGAZ.** Per-country layers overlap at disputed borders; the CGAZ composite is
   not packaged.
7. Name aliases (`alias` table) like geonames; geoBoundaries has one name per unit.

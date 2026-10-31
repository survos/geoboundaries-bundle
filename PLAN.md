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

## Not done

1. **GeoNames crosswalk.** `boundary.geoname_id` and `country.geoname_id` exist but nothing fills
   them. Match ADM1/ADM2 to geonames-bundle's `admin1`/`admin2` per country (name, then ISO code
   via `shape_iso`), with a confidence flag; keep unmatched rows. Country: ISO3 to GeoNames iso3.
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

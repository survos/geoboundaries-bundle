# geoBoundaries Bundle

`survos/geoboundaries-bundle` is the [geoBoundaries](https://www.geoboundaries.org) authority
bundle: administrative boundary polygons (country down to ADM5) packaged as SQLite, with point
location and name lookup. It is the polygon companion to
[`survos/geonames-bundle`](../geonames-bundle) and follows the same shape: build offline,
publish SQLite, fetch and query locally. Only the **gbOpen** release type is packaged.

## Runtime databases

DuckDB is the primary format. When `ext-pdo_duckdb` is loaded, `GeoBoundariesService` reads
`<name>.duckdb` and falls back to `<name>.sqlite` (the legacy format) when that file is missing.

- `geo.duckdb`: `country`, `release` (per-layer provenance: build date, year represented,
  license, source) and every country's ADM0 `boundary`
- `<iso3>.duckdb` (e.g. `usa.duckdb`): that country's `boundary` rows for every level, with `parent_id`
  derived by containment, GeoNames links, a bbox, `geometry_type` and typed `coordinates`

`coordinates` is `DOUBLE[][][][]` (polygons, rings, points, `[lon, lat]`), always shaped as a MultiPolygon,
rounded to six decimals (about 0.1 m, finer than the layers' own simplification). Rounding matters: the
source coordinates carry noise digits that defeat DuckDB's float compression. `usa` (3,290 units, ADM0 to
ADM2) is 2.9 MB as DuckDB, 16.5 MB as SQLite. A point lookup uses plain bbox columns and then `Geometry`'s
exact test, so no `spatial` extension is needed at runtime. On 3,000 random points in and around the US,
the DuckDB and SQLite builds returned the same units for every point.

`survos:geoboundaries` fetches the files from the `museado/geoboundaries-duckdb` (DuckDB) or
`museado/geoboundaries-data` (SQLite) Hugging Face dataset into `sqlite_dir`. Neither dataset is published
yet (see PLAN.md), so a fetch fails until it is. With `duckdb_uri` set to a dataset's `resolve/main` URL, the
service downloads each file into `sqlite_dir` the first time it needs it and reads it locally after that
(`duckdb_cache: false` reads the remote file in place instead). The cached file is never refreshed
automatically: run `survos:geoboundaries --force`, or delete it. Concurrent workers download under a file lock.

The SQLite files (`geo.sqlite`, `<iso3>.sqlite`) keep GeoJSON text geometry and a `boundary_rtree`.

```bash
bin/console survos:geoboundaries                    # geo.duckdb
bin/console survos:geoboundaries --country=usa,gbr  # + per-country databases
bin/console survos:geoboundaries --all
bin/console survos:geoboundaries --format=sqlite    # the legacy SQLite files (default when ext-pdo_duckdb is missing)
```

```php
$boundaries->countryAt(47.141, 9.521);              // Boundary: Liechtenstein (ADM0)
$boundaries->boundariesAt(47.141, 9.521, 'LIE');    // most specific first: ADM1, ADM0
$boundaries->find('Triesen', 'LIE', level: 1);
$boundaries->children($parentId, 'LIE');
$boundaries->findByGeonameId(3042058, 'LIE');       // by geonames-bundle id; Boundary::$geonameMatch says how it was linked
```

## Build workflow (admin/, never ships to consumers)

```bash
composer install
cd admin
php admin.php download --country=LIE --max-level=2   # API listing + simplified GeoJSON per layer
php admin.php build-duckdb --country=LIE             # geo.duckdb + lie.duckdb: parents and GeoNames links included
php admin.php build --country=LIE                    # legacy: geo.sqlite + lie.sqlite
php admin.php crosswalk                              # legacy SQLite only: link to GeoNames ids (build-duckdb does it while building)
./publish.sh usa,gbr                                 # download + build + hf upload
```

`build-duckdb` reads the downloaded GeoJSON with DuckDB's JSON reader, derives parents with the same PHP
`Geometry` code the service uses at query time, and links units to GeoNames ids using geonames-bundle's
`geo.duckdb` (or `geo.sqlite`) from `GEONAMES_DATA_DIR`. The directory is the app's choice: this bundle's default is
`APP_SHARED_DIRECTORY/geoboundaries-data`, separate from geonames-bundle's, because both bundles have a `geo.duckdb`.

`download` reads `https://www.geoboundaries.org/api/current/gbOpen/<ISO3>/ALL/`; the API's
`current` path is newer than the 5.0.0 (Dec 2022) release archives, and its answer is kept as
`index.json` in the source dir so the build records each layer's `buildDate`. The files it links
are pinned GitHub (LFS) URLs; downloads retry and pause between files because GitHub rate-limits.
Never `git clone` the geoBoundaries repo.

## License

geoBoundaries is distributed under CC BY 4.0 but each layer keeps its primary source's license
(Liechtenstein, for example, is ODbL from OpenStreetMap, which has share-alike terms). The
`release` table carries the license per layer; check it before redistributing. Credit Runfola
et al. (2020), PLoS ONE 15(4): e0231866, and link www.geoboundaries.org.

See [PLAN.md](./PLAN.md) for what is built and what is next.

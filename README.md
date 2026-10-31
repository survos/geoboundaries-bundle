# geoBoundaries Bundle

`survos/geoboundaries-bundle` is the [geoBoundaries](https://www.geoboundaries.org) authority
bundle: administrative boundary polygons (country down to ADM5) packaged as SQLite, with point
location and name lookup. It is the polygon companion to
[`survos/geonames-bundle`](../geonames-bundle) and follows the same shape: build offline,
publish SQLite, fetch and query locally. Only the **gbOpen** release type is packaged.

## Runtime databases

- `geo.sqlite`: `country`, `release` (per-layer provenance: build date, year represented,
  license, source) and every country's ADM0 `boundary`
- `<iso3>.sqlite` (e.g. `usa.sqlite`): that country's `boundary` rows for every level, with
  simplified GeoJSON `geometry`, bbox, `boundary_rtree`, and `parent_id` derived by containment

```bash
bin/console survos:geoboundaries                    # geo.sqlite
bin/console survos:geoboundaries --country=usa,gbr  # + per-country databases
bin/console survos:geoboundaries --all
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
php admin.php build --country=LIE                    # geo.sqlite + lie.sqlite
php admin.php crosswalk                              # link to GeoNames ids (needs geonames-bundle's geo.sqlite)
./publish.sh usa,gbr                                 # download + build + hf upload
```

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

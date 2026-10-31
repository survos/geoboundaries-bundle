#!/usr/bin/env bash
set -euo pipefail

# Downloads geoBoundaries layers, rebuilds the SQLite authority databases, and publishes them to
# the museado/geoboundaries-data Hugging Face dataset.
#
# Usage:
#   ./publish.sh                  # every country, ADM0-ADM2 (default)
#   ./publish.sh usa,gbr,fra      # just these ISO3 codes
#   MAX_LEVEL=5 ./publish.sh      # include deeper levels
#   SKIP_UPLOAD=1 ./publish.sh usa # download + build only
#
# Requires `composer install` in the bundle root, and the `hf` CLI logged in with write access
# to the dataset repo unless SKIP_UPLOAD=1.

ADMIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ADMIN_DIR"

COUNTRIES="${1:-ALL}"
HF_REPO="${HF_REPO:-museado/geoboundaries-data}"
MAX_LEVEL="${MAX_LEVEL:-2}"

if [ ! -f ../vendor/autoload.php ]; then
    echo "Missing dependencies — run 'composer install' in $ADMIN_DIR/.. first." >&2
    exit 1
fi

if [ "${SKIP_UPLOAD:-0}" != "1" ]; then
    command -v hf >/dev/null 2>&1 || { echo "hf CLI not found. Install: pip install -U huggingface_hub[cli]" >&2; exit 1; }
    hf auth whoami >/dev/null 2>&1 || { echo "Not logged in to Hugging Face. Run 'hf auth login' first." >&2; exit 1; }
fi

COUNTRY_FLAGS=()
IFS=',' read -ra CODES <<< "$COUNTRIES"
for code in "${CODES[@]}"; do
    COUNTRY_FLAGS+=("--country=${code}")
done

echo "==> Downloading geoBoundaries layers (${COUNTRIES}, up to ADM${MAX_LEVEL})"
php admin.php download "${COUNTRY_FLAGS[@]}" --max-level="${MAX_LEVEL}"

echo "==> Building SQLite databases"
php admin.php build "${COUNTRY_FLAGS[@]}"

if [ "${SKIP_UPLOAD:-0}" = "1" ]; then
    echo "==> SKIP_UPLOAD=1, not publishing."
    exit 0
fi

OUTPUT_DIR="$(php -r 'require "../vendor/autoload.php"; (new Symfony\Component\Dotenv\Dotenv())->load(__DIR__."/.env"); echo $_ENV["GEOBOUNDARIES_OUTPUT_DIR"];')"
echo "==> Uploading ${OUTPUT_DIR} to ${HF_REPO}"
hf upload "${HF_REPO}" "${OUTPUT_DIR}" . --repo-type dataset

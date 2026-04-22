#!/usr/bin/env bash
#
# Empaqueta el plugin en un .zip instalable en cualquier WP.
# Solo incluye `plugin/studiahub-lms-connector/` — no el entorno docker ni
# docs/scripts del repo.
#
set -euo pipefail

cd "$(dirname "$0")/.."

PLUGIN_DIR="plugin/studiahub-lms-connector"
MAIN_FILE="$PLUGIN_DIR/studiahub-lms-connector.php"

if [[ ! -f "$MAIN_FILE" ]]; then
    echo "ERROR: no encontré $MAIN_FILE" >&2
    exit 1
fi

VERSION=$(grep -E "^\s*\*\s*Version:" "$MAIN_FILE" | head -1 | awk -F': *' '{print $2}' | tr -d '[:space:]')
if [[ -z "$VERSION" ]]; then
    echo "ERROR: no pude extraer Version del header del plugin." >&2
    exit 1
fi

mkdir -p dist
OUTPUT="dist/studiahub-lms-connector-v${VERSION}.zip"
rm -f "$OUTPUT"

( cd plugin && zip -qr "../$OUTPUT" studiahub-lms-connector \
    -x "*.DS_Store" "*.swp" "*/.idea/*" "*/.vscode/*" )

echo "✓ Generado: $OUTPUT"
ls -lh "$OUTPUT"

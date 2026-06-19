#!/usr/bin/env bash
#
# Publica una release del plugin en GitHub. Cada WordPress con el plugin
# instalado detecta la release nueva (via Plugin Update Checker) y se
# auto-actualiza — no hay que tocar nada sitio por sitio.
#
# Uso:
#   1. Bumpeá "Version" en plugin/studiahub-lms-connector/studiahub-lms-connector.php
#      (y SLC_VERSION), "Stable tag" en readme.txt, y agregá el changelog.
#   2. Commiteá y pusheá a main.
#   3. bin/release.sh
#
# Qué hace: empaqueta el .zip y crea el tag vX.Y.Z + la GitHub Release
# adjuntando el .zip como ASSET (imprescindible: el plugin no vive en la raíz
# del repo, así que el zipball automático de GitHub instalaría la estructura mal).
#
# Requisitos: gh CLI autenticado, working tree limpio.
set -euo pipefail

cd "$(dirname "$0")/.."

PLUGIN_DIR="plugin/studiahub-lms-connector"
MAIN_FILE="$PLUGIN_DIR/studiahub-lms-connector.php"

VERSION=$(grep -E "^\s*\*\s*Version:" "$MAIN_FILE" | head -1 | awk -F': *' '{print $2}' | tr -d '[:space:]')
if [[ -z "$VERSION" ]]; then
    echo "ERROR: no pude extraer Version del header del plugin." >&2
    exit 1
fi
TAG="v${VERSION}"

# El Stable tag del readme debe coincidir con la versión del plugin.
STABLE=$(grep -E "^\s*Stable tag:" "$PLUGIN_DIR/readme.txt" | head -1 | awk -F': *' '{print $2}' | tr -d '[:space:]')
if [[ "$STABLE" != "$VERSION" ]]; then
    echo "ERROR: 'Stable tag: $STABLE' en readme.txt != Version $VERSION del plugin." >&2
    echo "       Sincronizá ambos antes de releasear." >&2
    exit 1
fi

# Working tree limpio (la release tiene que salir de algo commiteado).
if [[ -n "$(git status --porcelain)" ]]; then
    echo "ERROR: hay cambios sin commitear. Commiteá/pusheá antes de releasear." >&2
    exit 1
fi

# El tag / release no deben existir ya.
if git rev-parse "$TAG" >/dev/null 2>&1; then
    echo "ERROR: el tag $TAG ya existe localmente. ¿Bumpeaste la versión?" >&2
    exit 1
fi
if gh release view "$TAG" >/dev/null 2>&1; then
    echo "ERROR: la release $TAG ya existe en GitHub." >&2
    exit 1
fi

BRANCH=$(git rev-parse --abbrev-ref HEAD)
if [[ "$BRANCH" != "main" ]]; then
    echo "⚠️  No estás en main (estás en '$BRANCH'). Lo normal es releasear desde main."
    read -p "   ¿Releasear igual apuntando al commit actual? (si/no): " ok
    [[ "$ok" == "si" ]] || { echo "Cancelado."; exit 1; }
fi

# Empaquetar el .zip.
bash bin/package.sh
ZIP="dist/studiahub-lms-connector-v${VERSION}.zip"
if [[ ! -f "$ZIP" ]]; then
    echo "ERROR: no se generó $ZIP" >&2
    exit 1
fi

# Notas del release = sección del changelog de esta versión en readme.txt.
NOTES=$(awk -v ver="= ${VERSION} =" '
    $0 == ver {flag=1; next}
    /^= [0-9]/ {flag=0}
    flag {print}
' "$PLUGIN_DIR/readme.txt")
[[ -z "${NOTES// }" ]] && NOTES="Release ${TAG}"

echo "→ Publicando release $TAG (target: $(git rev-parse --short HEAD))..."
gh release create "$TAG" "$ZIP" \
    --title "$TAG" \
    --notes "$NOTES" \
    --target "$(git rev-parse HEAD)"

echo ""
echo "✓ Release $TAG publicada."
echo "  Los WP se actualizan solos (cron, ~12h) o al instante desde Plugins → 'Buscar actualizaciones'."
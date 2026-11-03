#!/bin/bash
# Сборка пакета бренда для Advanced Settings → Manual Upload → Import Brand:
#   brands/build.sh dlink [OUTDIR]   → OUTDIR/dlink-<время>.tgz (по умолчанию ./dist)
# @LAST_MODIFIED@ в JSON — время последнего коммита каталога бренда (незакоммиченные правки — текущее время):
# модуль по нему видит, что пакет новее установленного.
set -euo pipefail
BRAND=${1:?brand}; OUT=${2:-./dist}
SRC=$(cd "$(dirname "$0")" && pwd)/$BRAND
[ -f "$SRC/brand_data.json" ] || { echo "no $SRC/brand_data.json"; exit 1; }
if [ -z "$(git -C "$SRC" status --porcelain -- .)" ]; then TS=$(git -C "$SRC" log -1 --format=%ct -- .); fi
TS=${TS:-$(date +%s)}
[ -f "$SRC/make_template.py" ] && python3 "$SRC/make_template.py"
W=$(mktemp -d); trap 'rm -rf "$W"' EXIT
rsync -a --exclude make_template.py --exclude '*.pyc' "$SRC/" "$W/$BRAND/"
find "$W/$BRAND" -name '*.json' -exec sed -i "s/@LAST_MODIFIED@/$TS/g" {} +
for f in $(find "$W/$BRAND" -name '*.json'); do python3 -m json.tool "$f" >/dev/null || { echo "bad JSON: $f"; exit 1; }; done
mkdir -p "$OUT"
tar czf "$OUT/$BRAND-$TS.tgz" --owner=0 --group=0 -C "$W" "$BRAND"
echo "$OUT/$BRAND-$TS.tgz"; tar tzf "$OUT/$BRAND-$TS.tgz"

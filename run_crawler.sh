#!/bin/bash

# Default values
BASE_URL="${BASE_URL:-}"
MODELS_FILE="${MODELS_FILE:-models.txt}"
OUT_FILE="${OUT_FILE:-products.csv}"
FORCE="${FORCE:-}"

echo "Running Product Crawler..."
echo "Base URL: $BASE_URL"
echo "Models File: $MODELS_FILE"
echo "Output File: $OUT_FILE"
echo ""

# Build arguments array safely
ARGS=(--base "$BASE_URL" --models "$MODELS_FILE" --out "$OUT_FILE")

if [ -n "$FORCE" ]; then
    ARGS+=(--force)
fi

php crawler.php "${ARGS[@]}"

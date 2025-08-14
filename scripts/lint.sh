#!/usr/bin/env bash
set -euo pipefail
shopt -s nullglob
shopt -s globstar

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

if ! command -v php >/dev/null 2>&1; then
  echo "php not found. Please install PHP and ensure it's on PATH." >&2
  exit 127
fi

# Collect php files
mapfile -t files < <(printf '%s\n' **/*.php | sort)
if [ ${#files[@]} -eq 0 ]; then
  echo "No PHP files found."
  exit 0
fi

fail=0
for f in "${files[@]}"; do
  php -l "$f" || fail=1
done

if [ "$fail" -ne 0 ]; then
  echo "PHP lint failed." >&2
  exit 1
fi

echo "PHP lint passed for ${#files[@]} files."
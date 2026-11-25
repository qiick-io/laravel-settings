#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

case "${MUTATE_SCOPE:-all}" in
  all)
    files=$(find src -type f -name '*.php' ! -path 'src/Testing/*' | LC_ALL=C sort)
    ;;
  changed)
    files=$(node -e '
      const files = JSON.parse(process.env.MUTATE_CLASSES ?? "[]");
      if (!Array.isArray(files) || files.some(file => typeof file !== "string" || !/^src\/(?!Testing\/)[\w/]+\.php$/.test(file))) {
        throw new Error("MUTATE_CLASSES must be an array of package source paths");
      }
      for (const file of files) {
        console.log(file);
      }
    ')
    ;;
  *)
    echo "::error::MUTATE_SCOPE must be all or changed."
    exit 1
    ;;
esac

shard=${MUTATE_SHARD:-1}
shards=${MUTATE_SHARDS:-1}
position=0
failed=0

while IFS= read -r file; do
  # The PR plan includes deleted paths. Only surviving classes can be mutated.
  if [[ ! -f "$file" ]]; then
    continue
  fi

  position=$((position + 1))
  if (( (position - 1) % shards != shard - 1 )); then
    continue
  fi

  echo "::group::Mutate $file"
  # PSR-4 and the public API test enforce one declaration per file. A pure
  # interface or an enum with no executable code can yield zero mutations.
  if ! vendor/bin/pest --mutate --min=95 --path="$file" --ignore-min-score-on-zero-mutations; then
    failed=1
  fi
  echo "::endgroup::"
done <<< "$files"

exit "$failed"

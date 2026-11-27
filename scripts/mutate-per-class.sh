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

# Files where pest creates no mutant, and why. Any other file must create
# mutants and reach 95%, so a mis-pathed class or one whose logic moved out of
# reach of the mutator fails instead of passing with no mutant at all.
# src/Testing/ is excluded above: test fakes for products (fleet convention F14).
no_mutant_files=(
  src/Erasure/OrganisationScopeUnsupportedException.php # exception with a fixed message
  src/Events/SettingsUpdatedEvent.php # readonly event payload
  src/Events/SubjectErasedEvent.php # readonly event payload
  src/Settings.php # interface
  src/SettingsSchema.php # interface
  src/SettingTypeMismatch.php # exception with a fixed message
  src/StaleSetting.php # exception with a fixed message
  src/UndecryptableSetting.php # exception with a fixed message
)

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
  output=$(mktemp)
  # PSR-4 and the public API test enforce one declaration per file.
  if ! vendor/bin/pest --mutate --min=95 --path="$file" --ignore-min-score-on-zero-mutations | tee "$output"; then
    failed=1
  fi

  is_listed=false
  for no_mutant_file in "${no_mutant_files[@]}"; do
    if [[ "$no_mutant_file" == "$file" ]]; then
      is_listed=true
    fi
  done

  if grep -q 'No mutations created' "$output"; then
    if [[ "$is_listed" == false ]]; then
      echo "::error::$file creates no mutant: list it in no_mutant_files with the reason, or fix its path."
      failed=1
    fi
  elif [[ "$is_listed" == true ]]; then
    echo "::error::$file now creates mutants: remove it from no_mutant_files."
    failed=1
  fi

  rm -f "$output"
  echo "::endgroup::"


done <<< "$files"

exit "$failed"

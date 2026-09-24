#!/usr/bin/env bash

# Compare only files owned by a frozen Git release. Destination-only runtime
# files are deliberately outside the release and are never copied or removed.
release_sha256() {
  sha256sum "$1" | awk '{print $1}'
}

release_verify_subset() {
  local source="$1" destination="$2" label="$3" file relative source_hash destination_hash checked=0
  [[ -d "$source" && -d "$destination" ]] || {
    printf '%s: source or destination directory is missing\n' "$label" >&2
    return 1
  }
  while IFS= read -r -d '' file; do
    relative="${file#"$source"/}"
    if [[ -L "$file" ]]; then
      if [[ ! -L "$destination/$relative" || "$(readlink "$file")" != "$(readlink "$destination/$relative")" ]]; then
        printf '%s: missing or changed release symlink: %s\n' "$label" "$relative" >&2
        return 1
      fi
    else
      if [[ ! -f "$destination/$relative" ]]; then
        printf '%s: missing release file: %s\n' "$label" "$relative" >&2
        return 1
      fi
      source_hash="$(release_sha256 "$file")" || return 1
      destination_hash="$(release_sha256 "$destination/$relative")" || return 1
      if [[ "$source_hash" != "$destination_hash" ]]; then
        printf '%s: changed release file: %s\n' "$label" "$relative" >&2
        return 1
      fi
    fi
    checked=$((checked + 1))
  done < <(find "$source" \( -type f -o -type l \) -print0)
  printf '%s: %s release files present with matching checksums: PASS\n' "$label" "$checked"
}

release_count_additional() {
  local source="$1" destination="$2" file relative count=0
  [[ -d "$destination" ]] || { echo 0; return; }
  while IFS= read -r -d '' file; do
    relative="${file#"$destination"/}"
    if [[ ! -e "$source/$relative" && ! -L "$source/$relative" ]]; then
      count=$((count + 1))
    fi
  done < <(find "$destination" \( -type f -o -type l \) -print0)
  echo "$count"
}

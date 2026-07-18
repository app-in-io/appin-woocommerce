#!/usr/bin/env bash
#
# Single source of truth for the translation build. Compiles the committed
# languages/*.po sources into <dest>/<base>.mo. Used by release.yml (into the
# zip tree), deploy-wordpress-org.yml (into the SVN workspace) and test.yml
# (into /tmp, as a build-smoke + validation). `.mo` are build artifacts and are
# never committed — see .gitignore.
#
# Usage: compile-translations.sh <dest-dir>
#
# msgfmt runs with -c (consistency + format-string checks) so a malformed .po
# fails the build loudly here, not silently in a shipped .mo.
set -euo pipefail

dest="${1:?usage: compile-translations.sh <dest-dir>}"

shopt -s nullglob
pos=(languages/*.po)
if [ "${#pos[@]}" -eq 0 ]; then
    echo "::error::no languages/*.po sources found — nothing to compile" >&2
    exit 1
fi

mkdir -p "$dest"
for po in "${pos[@]}"; do
    msgfmt -c -o "${dest}/$(basename "${po%.po}").mo" "$po"
done

echo "Compiled ${#pos[@]} translation(s) into ${dest}"

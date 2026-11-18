#!/usr/bin/env bash
#
# Tells runtime-relevant file changes apart from tooling changes: `same` and
# `non_runtime` are the building blocks, `runtime_changes` prints the changed
# files between two commits that can change what production runs.
#
# Sourced by promote-check.sh and deploy-check.sh, which set the shell options.

# same <base> <head> <file> <filter...>: is the filtered file identical at base
# and head? A missing file or a failing filter counts as changed.
same() {
  local base=$1 head=$2 file=$3 old new
  shift 3
  old=$(git show "$base:$file" | "$@") || return 1
  new=$(git show "$head:$file" | "$@") || return 1
  [ "$old" = "$new" ]
}

# Paths that cannot change what production runs: tooling, CI and docs.
non_runtime() {
  case "$1" in
    .github/* | .ddev/* | .vscode/* | .vite-hooks/* | .agents/* | .claude/* | .opencode/*) return 0 ;;
    */tests/* | *.md) return 0 ;;
    renovate.json | taze.config.js | phpcs.xml.dist | phpstan.neon | skills-lock.json) return 0 ;;
    tsconfig.json | jsconfig.json | globals.d.ts | .editorconfig) return 0 ;;
  esac
  return 1
}

# runtime_changes <base> <head>: changed files between base and head that
# change what production runs, one per line.
runtime_changes() {
  local base=$1 head=$2 file changes
  changes=$(git diff --name-only --no-renames "$base" "$head") || return 1
  while IFS= read -r file; do
    [ -n "$file" ] || continue
    non_runtime "$file" && continue
    # Dependency files only count if they change what gets installed.
    case "$file" in
      # composer/composer is the Composer runtime, not a dependency.
      composer.lock)
        same "$base" "$head" "$file" jq -S 'del(."packages-dev", ."content-hash") | .packages |= map(select(.name != "composer/composer"))' && continue ;;
      composer.json)
        same "$base" "$head" "$file" jq -S 'del(."require-dev") | del(.require."composer/composer")' && continue ;;
      package.json)
        same "$base" "$head" "$file" jq -S 'del(.devEngines)' && continue ;;
      # The first document only locks pnpm itself, the second the project.
      pnpm-lock.yaml)
        same "$base" "$head" "$file" yq 'select(document_index == 1)' && continue ;;
    esac
    echo "$file"
  done <<< "$changes"
}

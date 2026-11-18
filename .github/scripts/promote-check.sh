#!/usr/bin/env bash
#
# Decides whether the promote workflow fast-forwards production: only when
# runtime-relevant files changed since the production branch. Renovate
# automerges tooling updates (pnpm, composer, action digests); they reach
# production with the next runtime change instead of deploying on their own.
# The whole BASE..HEAD range is compared, so commits from cancelled runs are
# still judged.
#
# Reads GITHUB_EVENT_NAME, GITHUB_SHA, GITHUB_OUTPUT and GITHUB_STEP_SUMMARY,
# and writes promote=true|false to $GITHUB_OUTPUT.

set -euo pipefail

source "$(dirname "${BASH_SOURCE[0]}")/runtime-changes.sh"

if [ "$GITHUB_EVENT_NAME" = "workflow_dispatch" ]; then
  promote=true
  echo "Manual run: promoting." >> "$GITHUB_STEP_SUMMARY"
elif ! base=$(git rev-parse --verify --quiet origin/production); then
  # A new project has no production branch yet.
  promote=true
  echo "No production branch yet: promoting." >> "$GITHUB_STEP_SUMMARY"
else
  mapfile -t files < <(runtime_changes "$base" "$GITHUB_SHA")

  if [ ${#files[@]} -eq 0 ]; then
    promote=false
    echo "No runtime changes since production, nothing to promote." >> "$GITHUB_STEP_SUMMARY"
  else
    promote=true
    {
      echo "Runtime changes since production:"
      printf -- '- `%s`\n' "${files[@]}"
    } >> "$GITHUB_STEP_SUMMARY"
  fi
fi

echo "promote=$promote" >> "$GITHUB_OUTPUT"

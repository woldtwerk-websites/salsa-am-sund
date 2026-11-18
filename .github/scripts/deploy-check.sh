#!/usr/bin/env bash
#
# Decides whether a production deploy needs a manual approval. A deploy whose
# new commits all came through Renovate pull requests runs on its own; every
# other commit (a human commit, a merge or a rebase merge) waits for the
# production environment.
#
# The baseline is the head of the last successful deploy, not the push before
# this one: a human commit that is still waiting for approval, was rejected or
# was cancelled must not ship unreviewed together with a later Renovate commit.
#
# AUTO_DEPLOY selects the mode:
#   unset, all  auto-deploy when every commit is a Renovate update
#   security    auto-deploy when every commit is a Renovate security update
#               (PR label `security`) or is a Renovate update that changes no
#               runtime files, see runtime-changes.sh
#   none        always wait for approval, like an unknown value does
#
# Reads AUTO_DEPLOY, GITHUB_REPOSITORY, GITHUB_OUTPUT and GITHUB_STEP_SUMMARY,
# and GH_TOKEN implicitly via gh. Writes approval=true|false and the sha it
# judged to $GITHUB_OUTPUT.

set -euo pipefail

source "$(dirname "${BASH_SOURCE[0]}")/runtime-changes.sh"

# pull_request <sha>: login and security label of the pull request that was
# merged as this commit, tab-separated. "none false" when no merge produced it.
pull_request() {
  gh api "repos/$GITHUB_REPOSITORY/commits/$1/pulls" | jq -r --arg sha "$1" '
    (map(select(.merge_commit_sha == $sha and .merged_at != null)) | .[0])
    | if . == null then ["none", false]
      else [.user.login, any((.labels // [])[]; .name == "security")]
      end
    | @tsv'
}

mode=${AUTO_DEPLOY:-all}
case "$mode" in
  all | security | none) ;;
  *)
    echo "::warning::Unknown AUTO_DEPLOY value '$mode', treating it as none."
    mode=none
    ;;
esac

head=$(git rev-parse HEAD)
base=""
# A reason for an approval is also the flag: as long as it stays empty, the
# deploy runs on its own.
reason=""
entries=()

if [ "$mode" = none ]; then
  reason="AUTO_DEPLOY is none"
else
  base=$(gh run list --repo "$GITHUB_REPOSITORY" --workflow deploy.yml --branch production \
    --status success --limit 1 --json headSha -q '.[0].headSha // empty')

  if [ -z "$base" ]; then
    reason="no successful deploy yet"
  elif [ "$base" = "$head" ]; then
    reason="nothing new since the last deploy"
  elif ! git merge-base --is-ancestor "$base" "$head"; then
    reason="production was rewritten since the last deploy"
  else
    commit_list=$(git rev-list --reverse "$base..$head")
    while IFS= read -r sha; do
      short=${sha:0:7}
      subject=$(git show -s --format=%s "$sha")
      pr=$(pull_request "$sha")
      IFS=$'\t' read -r author security <<< "$pr"

      if [ "$author" != "renovate[bot]" ]; then
        verdict="approval: not a Renovate PR"
        reason="commit $short did not come through a Renovate PR"
      elif [ "$mode" != security ]; then
        verdict="auto: Renovate update"
      elif [ "$security" = true ]; then
        verdict="auto: security update"
      else
        # A failing git aborts the step here instead of counting as "tooling".
        changes=$(runtime_changes "$sha^" "$sha")
        if [ -n "$changes" ]; then
          verdict="approval: not a security update"
          reason="commit $short is not a Renovate security update"
        else
          verdict="auto: tooling update"
        fi
      fi

      entries+=("- \`$short\` $subject: $verdict")

      # The first commit that needs approval decides.
      [ -z "$reason" ] || break
    done <<< "$commit_list"
  fi
fi

{
  echo "## Deploy approval"
  echo
  echo "- AUTO_DEPLOY: \`$mode\`"
  if [ "$mode" = none ]; then
    echo "- Baseline: not checked"
  else
    echo "- Baseline: \`${base:-no successful deploy yet}\`"
  fi
  if [ ${#entries[@]} -gt 0 ]; then
    echo
    printf -- '%s\n' "${entries[@]}"
  fi
  echo
  if [ -n "$reason" ]; then
    echo "Decision: approval required, $reason."
  else
    echo "Decision: deploying without approval, every commit is allowed by this mode."
  fi
} >> "$GITHUB_STEP_SUMMARY"

# The deploy job builds this commit, not whatever the branch points at by the
# time it starts.
echo "sha=$head" >> "$GITHUB_OUTPUT"
if [ -n "$reason" ]; then
  echo "approval=true" >> "$GITHUB_OUTPUT"
else
  echo "approval=false" >> "$GITHUB_OUTPUT"
fi

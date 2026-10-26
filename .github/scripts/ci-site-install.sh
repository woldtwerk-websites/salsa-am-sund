#!/usr/bin/env bash
#
# Starts DDEV and installs the Drupal site from the repository configuration.
# For the opencode GitHub Actions job, where DDEV is installed but not started.

set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

# Its post-start hook installs agent skills, not needed in CI. Moved aside instead
# of deleted, so the change doesn't end up in the agent's pull request.
if [ -f .ddev/config.ai.yaml ]; then
  config_ai_backup="$(mktemp -d)"
  mv .ddev/config.ai.yaml "$config_ai_backup/config.ai.yaml"
  # shellcheck disable=SC2064 # Expanded now on purpose: the path is fixed.
  trap "mv '$config_ai_backup/config.ai.yaml' .ddev/config.ai.yaml" EXIT
fi

# Not needed in CI: all requests go to http://localhost inside the web container.
ddev config global --omit-containers=ddev-router,ddev-ssh-agent

ddev start

ddev composer install --no-interaction --no-progress

# Random throwaway password, so drush doesn't print a generated one to the log.
ddev drush site:install --existing-config --yes --account-pass="$(openssl rand -hex 16)"

# The ddev-viteplus addon has no pnpm in the web container.
ddev exec -s viteplus vp install --frozen-lockfile

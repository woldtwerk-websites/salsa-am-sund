#!/usr/bin/env bash
#
# Dispatcher for the shared CI database-snapshot key.
#
# Installed once per server and used as the forced command of the single key that
# every project's CI shares, so there is exactly one authorized_keys line:
#   command="/home/<user>/.local/share/bin/ci-snapshot-dispatch",restrict ssh-ed25519 ...
# It is installed from any release, because the file is the same everywhere:
#   install -m 755 ~/apps/<project>/current/misc/snapshot/ci-snapshot-dispatch.sh \
#     ~/.local/share/bin/ci-snapshot-dispatch
#
# CI sends the project name as the SSH command and the dispatcher runs that
# project's own misc/snapshot/ci-snapshot.sh. Nothing else on the server is
# reachable, and stdout carries that script's dump and nothing more.

set -euo pipefail

log() {
  printf 'ci-snapshot-dispatch: %s\n' "$*" >&2
}

# The only input from the client, and it is untrusted: a project name is plain
# lowercase with dots, dashes and underscores, so it cannot walk directories,
# reach hidden paths or smuggle in shell syntax.
name=${SSH_ORIGINAL_COMMAND:-}
if [[ ! $name =~ ^[a-z0-9][a-z0-9._-]*$ ]]; then
  log "invalid project name"
  exit 1
fi

# Overridable for tests only; production always uses $HOME/apps.
apps=${CI_SNAPSHOT_APPS:-$HOME/apps}
script="$apps/$name/current/misc/snapshot/ci-snapshot.sh"
if [[ ! -f $script ]]; then
  log "unknown project"
  exit 1
fi

# Bash rather than exec directly, so a missing execute bit after scaffolding does
# not matter. The script takes no arguments, and the client's string must not
# reach it.
unset SSH_ORIGINAL_COMMAND
exec bash "$script"

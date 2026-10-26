#!/usr/bin/env bash
#
# Prints a gzipped, scrubbed SQL dump of the production database to stdout.
#
# Runs on the production host from the `current` release, where CI reaches it over
# SSH with a key whose authorized_keys entry forces this script. Arguments and
# SSH_ORIGINAL_COMMAND are ignored on purpose: the caller cannot influence what
# the script does.
#
# The scrub happens here, on the server that holds the data, and only the scrubbed
# copy is written to stdout. Diagnostics go to stderr.
#
# The result is cached, so the live database is dumped at most once a day even
# though every CI job asks for a snapshot.

set -euo pipefail

log() {
  printf 'ci-snapshot: %s\n' "$*" >&2
}

script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)

# The release is deployed to <deploy_path>/current/misc/snapshot/, with `current`
# pointing into releases/<n>. By the Deployer convention that puts releases under
# .../apps/<name> and the systemd convention that names the database container
# <instance>__db, the instance is the deploy directory's name.
release_dir=$(cd -- "$script_dir/../.." && pwd -P)
deploy_path=$(dirname -- "$(dirname -- "$release_dir")")
instance=$(basename -- "$deploy_path")

# MariaDB runs in a rootless podman container, this script on the host. DB_EXEC
# exists only so the script can be tested locally, e.g. against ddev:
#   DB_EXEC="docker exec -i -e MARIADB_ROOT_PASSWORD=root \
#     -e MARIADB_DATABASE=ci_source ddev-<project>-db" misc/snapshot/ci-snapshot.sh
db_exec=(podman exec -i "${instance}__db")
if [[ -n ${DB_EXEC:-} ]]; then
  read -r -a db_exec <<<"$DB_EXEC"
elif ! podman container exists "${instance}__db" 2>/dev/null; then
  log "database container ${instance}__db is not available; run from a deployed release or set DB_EXEC"
  exit 1
fi

# Credentials stay in the container: MARIADB_ROOT_PASSWORD comes from its
# environment and is only assigned to MYSQL_PWD inside the container's shell, so
# it never appears in an argument, a process list or this repository.
# shellcheck disable=SC2016 # These expand in the container's shell, not here.
sql() {
  "${db_exec[@]}" sh -c 'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" exec mariadb --user=root "$@"' sh "$@"
}

# shellcheck disable=SC2016 # Same reason as above.
dump() {
  "${db_exec[@]}" sh -c 'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" exec mariadb-dump --user=root "$@"' sh "$@"
}

# Only the deploying user may read the cache. Both settings are overridable for
# testing; the forced SSH command always gets the defaults.
cache=${CI_SNAPSHOT_CACHE:-$HOME/.cache/$instance/ci-snapshot.sql.gz}
max_age=${CI_SNAPSHOT_MAX_AGE:-86400}
cache_tmp="$cache.tmp.$$"

umask 077
mkdir -p -- "$(dirname -- "$cache")"
tmp_dir=$(mktemp -d)
scratch=""

cleanup() {
  status=$?
  if [[ -n $scratch ]]; then
    # The name is ours and only ever contains digits, so it needs no quoting.
    sql -e "DROP DATABASE IF EXISTS $scratch" >/dev/null 2>&1 ||
      log "warning: could not drop $scratch"
  fi
  # A half-written dump must never replace the cached one.
  rm -f -- "$cache_tmp"
  rm -rf -- "$tmp_dir"
  exit "$status"
}
trap cleanup EXIT

# One lock covers the whole check-and-regenerate part: parallel CI runs wait here
# instead of each dumping production.
exec 9>"$cache.lock"
flock -x 9

# Leftovers of a killed run; only this holder can be writing a temporary file.
rm -f -- "$cache".tmp.*

if [[ -f $cache ]] && (($(date +%s) - $(stat -c %Y -- "$cache") < max_age)); then
  log "serving cached snapshot"
  flock -u 9
  cat -- "$cache"
  exit 0
fi

log "regenerating snapshot"
# shellcheck disable=SC2016 # Expands in the container's shell, not here.
source_db=$("${db_exec[@]}" sh -c 'printf "%s" "${MARIADB_DATABASE:?MARIADB_DATABASE is not set in the container}"')
log "source database: $source_db"

# A pid is unique among running processes, so parallel runs cannot collide.
scratch="ci_snapshot_$$"
sql -e "DROP DATABASE IF EXISTS $scratch; CREATE DATABASE $scratch;"
log "copying to $scratch"

# Tables whose contents are either pointless in CI (caches, queues, locks) or
# personal data (watchdog, sessions, sent mails with addresses and bodies). Only
# their structure is copied, so the schema the site expects stays complete.
structure_table_list=$(sql --batch --skip-column-names --database="$source_db" <<'SQL'
SELECT TABLE_NAME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_TYPE = 'BASE TABLE'
  AND (TABLE_NAME REGEXP '^(cache_|easy_email_)'
       OR TABLE_NAME IN ('cachetags', 'batch', 'flood', 'queue', 'semaphore',
                         'sessions', 'watchdog', 'key_value_expire', 'honeypot_user',
                         'autosave_form_entity_form', 'easy_email'))
ORDER BY TABLE_NAME
SQL
)
structure_tables=()
while IFS= read -r table; do
  if [[ -n $table ]]; then
    structure_tables+=("$table")
  fi
done <<<"$structure_table_list"
log "structure only for ${#structure_tables[@]} tables"

structure_args=(--no-data --single-transaction --quick "$source_db")
structure_args+=("${structure_tables[@]}")
dump "${structure_args[@]}" | sql --database="$scratch"

data_args=(--single-transaction --quick)
for table in "${structure_tables[@]}"; do
  data_args+=("--ignore-table=$source_db.$table")
done
data_args+=("$source_db")
dump "${data_args[@]}" | sql --database="$scratch"

# Static scrub plus statements generated from the copied schema. Only core tables
# are named in sanitize.sql, so a project without modules such as comment,
# media or externalauth still applies it; everything else is generated here and
# skipped when the table or column does not exist.
{
  cat "$script_dir/sanitize.sql"
  sql --batch --skip-column-names --database="$scratch" <<'SQL'
-- Users without external auth entries, so no provider mapping dangles.
SELECT 'DELETE FROM `authmap` WHERE `uid` NOT IN (SELECT `uid` FROM `keep_uid`);'
FROM information_schema.COLUMNS AS c
WHERE c.TABLE_SCHEMA = DATABASE()
  AND c.TABLE_NAME = 'authmap'
  AND c.COLUMN_NAME = 'uid';

-- Comments are public content, so the bodies stay; the identity columns go.
SELECT CONCAT('UPDATE `comment_field_data` SET ',
              GROUP_CONCAT(CONCAT('`', c.COLUMN_NAME, '` = NULL')
                           ORDER BY c.COLUMN_NAME SEPARATOR ', '),
              ';')
FROM information_schema.COLUMNS AS c
WHERE c.TABLE_SCHEMA = DATABASE()
  AND c.TABLE_NAME = 'comment_field_data'
  AND c.COLUMN_NAME IN ('name', 'mail', 'homepage', 'hostname')
GROUP BY c.TABLE_NAME;

-- User field tables that sanitize.sql does not already handle.
SELECT CONCAT('DELETE FROM `', REPLACE(c.TABLE_NAME, '`', '``'),
              '` WHERE `entity_id` NOT IN (SELECT `uid` FROM `keep_uid`);')
FROM information_schema.COLUMNS AS c
JOIN information_schema.TABLES AS t
  ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
WHERE c.TABLE_SCHEMA = DATABASE()
  AND t.TABLE_TYPE = 'BASE TABLE'
  AND c.COLUMN_NAME = 'entity_id'
  AND c.TABLE_NAME REGEXP '^user(_revision)?__'
  AND c.TABLE_NAME NOT IN ('user__roles')
GROUP BY c.TABLE_NAME
ORDER BY c.TABLE_NAME;

-- UPDATE IGNORE rather than UPDATE: where the column is part of a unique key
-- (history, shortcut_set_users), moving a row to uid 1 can collide with a row
-- that already exists, and a collision must not abort the whole import.
SELECT CONCAT('UPDATE IGNORE `', REPLACE(c.TABLE_NAME, '`', '``'),
              '` SET `', REPLACE(c.COLUMN_NAME, '`', '``'),
              '` = 1 WHERE `', REPLACE(c.COLUMN_NAME, '`', '``'),
              '` IS NOT NULL AND `', REPLACE(c.COLUMN_NAME, '`', '``'),
              '` NOT IN (SELECT `uid` FROM `keep_uid`);')
FROM information_schema.COLUMNS AS c
JOIN information_schema.TABLES AS t
  ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
WHERE c.TABLE_SCHEMA = DATABASE()
  AND t.TABLE_TYPE = 'BASE TABLE'
  AND c.COLUMN_NAME IN ('uid', 'revision_uid', 'revision_user', 'last_comment_uid')
  AND c.TABLE_NAME NOT IN ('users', 'users_field_data', 'users_data', 'authmap', 'keep_uid')
  AND c.TABLE_NAME NOT REGEXP '^user(_revision)?__'
ORDER BY c.TABLE_NAME, c.COLUMN_NAME;
SQL
} >"$tmp_dir/sanitize.sql"
log "scrubbing $scratch"
sql --database="$scratch" <"$tmp_dir/sanitize.sql"

# No --databases: the dump must not carry a USE statement, so the consumer decides
# which database it goes into.
log "dumping $scratch"
dump --single-transaction --quick "$scratch" | gzip >"$cache_tmp"
gzip -t "$cache_tmp"
# Same directory, so the rename is atomic and a reader sees one complete dump.
mv -f -- "$cache_tmp" "$cache"
flock -u 9
cat -- "$cache"

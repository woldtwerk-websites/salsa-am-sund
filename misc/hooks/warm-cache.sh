#!/bin/bash
set -euo pipefail

YAML_FILE="drush/sites/self.site.yml"

if [[ ! -f "$YAML_FILE" ]]; then
  echo "Error: YAML file '$YAML_FILE' not found."
  exit 1
fi

# Extract URIs, strip quotes, and run curl. Only real `uri:` keys match, so
# comment lines in the alias file cannot turn into a curl target.
grep '^[[:space:]]*uri:' "$YAML_FILE" | awk -F": " '{gsub(/'\''|"/,"",$2); print $2}' | while read -r uri; do
  if [[ -n "$uri" ]]; then  # Only process non-empty URIs
    echo "Running curl on: $uri"
    curl -I "$uri"
  fi
done

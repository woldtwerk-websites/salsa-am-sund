#!/bin/bash
set -euo pipefail

YAML_FILE="drush/sites/prod.site.yml"

if [[ ! -f "$YAML_FILE" ]]; then
  echo "Error: YAML file '$YAML_FILE' not found."
  exit 1
fi

# Extract URIs, strip quotes, and run curl
grep "uri:" "$YAML_FILE" | awk -F": " '{gsub(/'\''|"/,"",$2); print $2}' | while read -r uri; do
  if [[ -n "$uri" ]]; then  # Only process non-empty URIs
    echo "Running curl on: $uri"
    curl -I "$uri"
  fi
done

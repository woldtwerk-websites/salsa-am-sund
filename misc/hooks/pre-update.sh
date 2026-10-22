#!/bin/bash
set -euo pipefail

# Backups to keep.
KEEP=3

# Current Time
FILENAME=$( date +'%Y_%m_%d-%H_%M_%S' )

BACKUP_DIR=.data/backup
mkdir -p $BACKUP_DIR

# Create database backup.
vendor/bin/drush  sql-dump --gzip --structure-tables-key=development --result-file=../$BACKUP_DIR/$FILENAME.sql --debug

# Remove old backups.
ls -1t "$BACKUP_DIR" | tail -n +$(($KEEP+1)) | xargs -I{} rm "$BACKUP_DIR/{}"

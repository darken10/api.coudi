#!/usr/bin/env bash
#
# Sauvegarde du volume de stockage Coudi (logs + médias + bundles).
#
# À exécuter sur le VPS qui héberge Dokploy. Le répertoire `SRC_DIR` est le
# chemin hôte du bind-mount monté sur `/app/storage` dans le conteneur.
#
# Usage :
#   ./backup-storage.sh
#
# À planifier (cron) :
#   0 3 * * *  /srv/coudi/scripts/backup-storage.sh >> /var/log/coudi-backup.log 2>&1
#
set -euo pipefail

# ── Configuration ────────────────────────────────────────────────────────────
SRC_DIR="${SRC_DIR:-/srv/coudi/storage}"          # chemin hôte du volume
BACKUP_DIR="${BACKUP_DIR:-/srv/coudi/backups}"     # où écrire les archives
RETENTION_DAYS="${RETENTION_DAYS:-14}"             # nb de jours conservés

# Décommente et renseigne pour un envoi hors site (S3/Backblaze/… via rclone).
# OFFSITE_REMOTE="s3:coudi-backups/storage"
# export RCLONE_CONFIG=...
# ─────────────────────────────────────────────────────────────────────────────

TIMESTAMP="$(date +%Y-%m-%d_%H%M%S)"
ARCHIVE="${BACKUP_DIR}/coudi-storage-${TIMESTAMP}.tar.gz"

if [ ! -d "${SRC_DIR}" ]; then
  echo "Erreur : répertoire source introuvable : ${SRC_DIR}" >&2
  exit 1
fi

mkdir -p "${BACKUP_DIR}"

echo "[$(date -Iseconds)] Sauvegarde de ${SRC_DIR} → ${ARCHIVE}"
tar -czf "${ARCHIVE}" -C "$(dirname "${SRC_DIR}")" "$(basename "${SRC_DIR}")"

# ── Rotation : ne garder que les N derniers jours ───────────────────────────
find "${BACKUP_DIR}" -name 'coudi-storage-*.tar.gz' -mtime "+${RETENTION_DAYS}" -delete

# ── Envoi hors site (optionnel) ─────────────────────────────────────────────
# if command -v rclone >/dev/null 2>&1; then
#   rclone copy "${ARCHIVE}" "${OFFSITE_REMOTE}/"
# fi

echo "[$(date -Iseconds)] Terminé. Fichiers conservés :"
ls -lh "${BACKUP_DIR}"/coudi-storage-*.tar.gz | tail -n 5

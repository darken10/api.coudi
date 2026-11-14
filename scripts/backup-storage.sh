#!/usr/bin/env bash
#
# Sauvegarde du volume Docker Coudi (logs + médias + bundles).
#
# Approche « volume nommé » : on monte le volume dans un conteneur éphémère
# (alpine) et on en compresse le contenu. Portable, y compris en Swarm.
#
# Usage :
#   ./backup-storage.sh
#
# Cron (chaque nuit à 3 h) :
#   0 3 * * *  /srv/coudi/scripts/backup-storage.sh >> /var/log/coudi-backup.log 2>&1
#
set -euo pipefail

# ── Configuration ────────────────────────────────────────────────────────────
VOLUME_NAME="${VOLUME_NAME:-coudi-storage}"       # nom du volume Docker
BACKUP_DIR="${BACKUP_DIR:-/srv/coudi/backups}"    # où écrire les archives
RETENTION_DAYS="${RETENTION_DAYS:-14}"            # nb de jours conservés

# Décommente et renseigne pour un envoi hors site (S3/Backblaze/… via rclone).
# OFFSITE_REMOTE="s3:coudi-backups/storage"
# ─────────────────────────────────────────────────────────────────────────────

TIMESTAMP="$(date +%Y-%m-%d_%H%M%S)"
ARCHIVE="${BACKUP_DIR}/coudi-storage-${TIMESTAMP}.tar.gz"

if ! docker volume inspect "${VOLUME_NAME}" >/dev/null 2>&1; then
  echo "Erreur : volume Docker introuvable : ${VOLUME_NAME}" >&2
  echo "Vérifie le nom avec : docker volume ls" >&2
  exit 1
fi

mkdir -p "${BACKUP_DIR}"

echo "[$(date -Iseconds)] Sauvegarde du volume ${VOLUME_NAME} → ${ARCHIVE}"

docker run --rm \
  -v "${VOLUME_NAME}":/data:ro \
  -v "${BACKUP_DIR}":/backup \
  alpine \
  tar -czf "/backup/$(basename "${ARCHIVE}")" -C /data .

# ── Rotation : ne garder que les N derniers jours ───────────────────────────
find "${BACKUP_DIR}" -name 'coudi-storage-*.tar.gz' -mtime "+${RETENTION_DAYS}" -delete

# ── Envoi hors site (optionnel) ─────────────────────────────────────────────
# if command -v rclone >/dev/null 2>&1; then
#   rclone copy "${ARCHIVE}" "${OFFSITE_REMOTE}/"
# fi

echo "[$(date -Iseconds)] Terminé. Fichiers conservés :"
ls -lh "${BACKUP_DIR}"/coudi-storage-*.tar.gz | tail -n 5

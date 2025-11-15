#!/bin/bash
#
# GACS Dashboard Automatic Backup Script
# Backs up database and critical files daily
#
# Add to crontab:
# 0 2 * * * /path/to/your/project/cron/backup.sh
#

# Configuration - EDIT THESE VALUES FOR YOUR SERVER
BACKUP_DIR="/path/to/backup/directory"
DB_USER="your_db_username"
DB_PASS="your_db_password"
DB_NAME="your_db_name"
PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RETENTION_DAYS=7
DATE=$(date +%Y%m%d_%H%M%S)

# Create backup directory if not exists
mkdir -p "$BACKUP_DIR"

# Database backup
echo "[$(date)] Starting database backup..."
mysqldump -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" | gzip > "$BACKUP_DIR/db_backup_$DATE.sql.gz"

if [ $? -eq 0 ]; then
    echo "[$(date)] Database backup successful: db_backup_$DATE.sql.gz"
else
    echo "[$(date)] Database backup FAILED!"
    exit 1
fi

# Config files backup
echo "[$(date)] Backing up config files..."
tar -czf "$BACKUP_DIR/config_backup_$DATE.tar.gz" \
    -C "$PROJECT_DIR" \
    config/database.php \
    config/config.php \
    .env 2>/dev/null

# Map data backup (if you have custom waypoints)
echo "[$(date)] Backing up map waypoints..."
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
    SELECT * FROM map_connections WHERE path_coordinates IS NOT NULL
" > "$BACKUP_DIR/map_waypoints_$DATE.csv"

# Delete old backups
echo "[$(date)] Cleaning up old backups (older than $RETENTION_DAYS days)..."
find "$BACKUP_DIR" -name "*.sql.gz" -type f -mtime +$RETENTION_DAYS -delete
find "$BACKUP_DIR" -name "*.tar.gz" -type f -mtime +$RETENTION_DAYS -delete
find "$BACKUP_DIR" -name "*.csv" -type f -mtime +$RETENTION_DAYS -delete

echo "[$(date)] Backup completed successfully!"
echo "[$(date)] Backup location: $BACKUP_DIR"

# Show disk usage
du -sh "$BACKUP_DIR"

exit 0

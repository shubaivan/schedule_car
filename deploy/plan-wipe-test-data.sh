#!/bin/sh
# «План»: прибрати тестові дані, лишити людей і налаштування (Олександр, 17.11.2026).
# Запуск — ЛИШЕ з явного «так» (Іван / головна консоль), від root на сервері «Плану»:
#
#   sh /var/www/plan/deploy/plan-wipe-test-data.sh                 # dry-run: що зникне, нічого не змінює
#   KEEP_TASKS=25,26 sh …/plan-wipe-test-data.sh                    # dry-run, лишити задачі 25 і 26
#   COMMIT=1 KEEP_TASKS=… sh /var/www/plan/deploy/plan-wipe-test-data.sh   # справді
#
# Що робить при COMMIT=1:
#   1) бекап: plan.dump (pg_dump -Fc, перевірка pg_restore --list), storage.tgz, env.local, REVISION
#      → /var/backups/plan-<дата>-wipe-pre/ (як щоденний plan-backup);
#   2) стоп plan-worker; SQL deploy/plan-wipe-test-data.sql в одній транзакції;
#   3) файли видалених задач/заявок/складу — НЕ видаляє, а переносить у бекап (storage-removed/);
#      файли лишених задач (KEEP_TASKS) не чіпає;
#   4) Хроніка й пошук — з нуля (plan:chronicle:rebuild, plan:search:reindex); старт воркера.
# Google Диск НЕ чіпає: копії тестових файлів у теці «План» на Диску лишаються (прибрати вручну, якщо треба).
# Відкат: pg_restore --clean з plan.dump + повернути storage-removed/ на місце (див. RESTORE.txt бекапу).
set -eu

APP_DIR=/var/www/plan
KEEP_TASKS=${KEEP_TASKS:-0}
COMMIT=${COMMIT:-0}
STAMP=$(date +%Y%m%d-%H%M)
BACKUP=/var/backups/plan-$STAMP-wipe-pre
cd /tmp
PSQL="runuser -u postgres -- psql -d plan -v ON_ERROR_STOP=1"
APP="sudo -u deploy php8.5 $APP_DIR/bin/console --env=prod"

case "$KEEP_TASKS" in *[!0-9,]*|'') echo "KEEP_TASKS — лише id через кому" >&2; exit 2 ;; esac

if [ "$COMMIT" != "1" ]; then
    echo "== DRY-RUN (нічого не змінюється). KEEP_TASKS=$KEEP_TASKS"
    $PSQL -v keep_tasks="$KEEP_TASKS" -v commit=0 -f "$APP_DIR/deploy/plan-wipe-test-data.sql"
    exit 0
fi

echo "== 1. Бекап → $BACKUP"
install -d -m 700 "$BACKUP"
runuser -u postgres -- pg_dump -Fc -d plan > "$BACKUP/plan.dump"
# stdin: тека бекапу 700 root, postgres її не читає.
runuser -u postgres -- pg_restore --list < "$BACKUP/plan.dump" > /dev/null
tar -C "$APP_DIR/var" -czf "$BACKUP/storage.tgz" storage
cp -p "$APP_DIR/.env.local" "$BACKUP/env.local"
cp -p "$APP_DIR/REVISION" "$BACKUP/REVISION" 2>/dev/null || true
cat > "$BACKUP/RESTORE.txt" <<EOF
Відкат прибирання тестових даних ($STAMP):
  systemctl stop plan-worker
  runuser -u postgres -- pg_restore --clean --if-exists --no-owner --role=plan -d plan $BACKUP/plan.dump
  rm -rf $APP_DIR/var/storage && tar -C $APP_DIR/var -xzf $BACKUP/storage.tgz && chown -R deploy:www-data $APP_DIR/var/storage
  sudo -u deploy php8.5 $APP_DIR/bin/console --env=prod cache:clear && systemctl reload php8.5-fpm && systemctl start plan-worker
EOF
(cd "$BACKUP" && sha256sum plan.dump storage.tgz > SHA256SUMS)
ls -la "$BACKUP"

echo "== 2. SQL (одна транзакція)"
systemctl stop plan-worker
trap 'systemctl start plan-worker' EXIT
$PSQL -v keep_tasks="$KEEP_TASKS" -v commit=1 -f "$APP_DIR/deploy/plan-wipe-test-data.sql"

echo "== 3. Файли видалених записів → $BACKUP/storage-removed/"
S="$APP_DIR/var/storage"
install -d -m 700 "$BACKUP/storage-removed"
for d in supply warehouse; do
    [ -d "$S/$d" ] && mv "$S/$d" "$BACKUP/storage-removed/$d" && install -d -o deploy -g www-data -m 2775 "$S/$d"
done
if [ "$KEEP_TASKS" = "0" ]; then
    [ -d "$S/tasks" ] && mv "$S/tasks" "$BACKUP/storage-removed/tasks" && install -d -o deploy -g www-data -m 2775 "$S/tasks"
else
    echo "   файли задач лишаються як є (KEEP_TASKS=$KEEP_TASKS): осиротілі файли видалених задач не заважають"
fi

echo "== 4. Хроніка й пошук з нуля"
$APP plan:chronicle:rebuild
$APP plan:search:reindex
$APP cache:clear
systemctl reload php8.5-fpm
systemctl start plan-worker
trap - EXIT
systemctl is-active plan-worker
echo "== Готово. Бекап: $BACKUP"

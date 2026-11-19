#!/bin/sh
# Кроки на сервері ПІСЛЯ rsync коду в /var/www/plan (від root; консоль — лише від deploy).
# Повний порядок (бекап → rsync → цей скрипт → перевірки) — у runbook; тут те, що не можна забути.
#
#   ssh root@<server> 'systemctl stop plan-worker'      # краще ще ДО rsync (код під живим воркером)
#   rsync … --delete --filter='P /public/crm/assets/***' …   # старі файли фронту НЕ видаляти (стара вкладка)
#   (лише фронт — deploy/plan-fe-deploy.sh)
#   ssh root@<server> 'sh /var/www/plan/deploy/plan-deploy.sh'
set -eu
cd /var/www/plan
APP="sudo -u deploy php8.5 bin/console --env=prod"

# Воркер подій — зупинити ДО composer/міграцій/cache:clear: живий воркер зі старим кодом
# падав, коли cache:clear прибирав його контейнер з-під ніг. systemctl stop шле SIGTERM —
# messenger:consume доробляє поточне повідомлення й виходить (systemd чекає TimeoutStopSec).
# Що б не сталося далі (set -e), воркер піднімається знову — трап на виході.
systemctl stop plan-worker
trap 'systemctl start plan-worker' EXIT

find . -path ./var -prune -o -exec chown deploy:www-data {} +
sudo -u deploy APP_ENV=prod php8.5 /usr/local/bin/composer install --no-dev --optimize-autoloader --no-interaction
$APP doctrine:migrations:migrate --no-interaction
$APP cache:clear
chmod -R g+rwX var

# Файли фронту попередніх збірок rsync не видаляє (фільтр P вище) — щоб відкрита до деплою вкладка
# ще дозавантажила свій розділ. Прибираємо ті, яких не було в жодній збірці за 14 днів.
[ -d public/crm/assets ] && find public/crm/assets -type f -mtime +14 -delete

systemctl reload php8.5-fpm

# Крон і logrotate — з репозиторію (без змін — нічого не станеться).
install -m 644 deploy/plan.cron /etc/cron.d/plan
install -m 644 deploy/plan.logrotate /etc/logrotate.d/plan

# Новий воркер — уже зі свіжим кодом і кешем (трап лише страхує на випадок помилки вище).
systemctl start plan-worker
trap - EXIT
sleep 3
systemctl is-active plan-worker
$APP messenger:failed:show --stats 2>/dev/null | tail -3 || true

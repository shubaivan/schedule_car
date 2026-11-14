#!/bin/sh
# Кроки на сервері ПІСЛЯ rsync коду в /var/www/plan (від root; консоль — лише від deploy).
# Повний порядок (бекап → rsync → цей скрипт → перевірки) — у runbook; тут те, що не можна забути.
#
#   ssh root@<server> 'sh /var/www/plan/deploy/plan-deploy.sh'
set -eu
cd /var/www/plan
APP="sudo -u deploy php8.5 bin/console --env=prod"

find . -path ./var -prune -o -exec chown deploy:www-data {} +
sudo -u deploy APP_ENV=prod php8.5 /usr/local/bin/composer install --no-dev --optimize-autoloader --no-interaction
$APP doctrine:migrations:migrate --no-interaction
$APP cache:clear
chmod -R g+rwX var

# Воркер подій: м'яко зупинити (доробить поточне повідомлення) — systemd (Restart=always)
# підніме новий зі свіжим кодом. ПІСЛЯ cache:clear: сигнал лежить у кеш-пулі.
# Без цього старий воркер до години живе зі старим кодом (новий тип події → черга failed).
$APP messenger:stop-workers
systemctl reload php8.5-fpm

# Крон і logrotate — з репозиторію (без змін — нічого не станеться).
install -m 644 deploy/plan.cron /etc/cron.d/plan
install -m 644 deploy/plan.logrotate /etc/logrotate.d/plan

sleep 3
systemctl is-active plan-worker
$APP messenger:failed:show --stats 2>/dev/null | tail -3 || true

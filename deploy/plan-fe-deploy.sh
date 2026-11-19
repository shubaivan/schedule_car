#!/bin/sh
# Деплой ЛИШЕ фронту «Плану» (public/crm) — запускати ЛОКАЛЬНО з кореня репозиторію після збірки
# (docker run … node:24-bookworm-slim sh -c 'npm ci && npm run build' → public/crm/).
#
#   K="-i ~/.ssh/buddet_rsa" S=root@139.28.36.181 REV=<sha> sh deploy/plan-fe-deploy.sh
#
# Порядок важливий для відкритих вкладок:
#  1) нові assets — БЕЗ --delete: старі файли з хешем лишаються, стара вкладка ще дозавантажить свій розділ;
#  2) index.html — останнім: нова оболонка посилається лише на файли, які вже лежать на сервері;
#  3) прибирання assets, яких не було в жодній збірці за KEEP_DAYS днів (за mtime; rsync -a ставить mtime збірки).
set -eu
: "${S:?S=root@host}" "${REV:?REV=<sha>}"
K="${K:-}"
KEEP_DAYS="${KEEP_DAYS:-14}"
[ -f public/crm/index.html ] || { echo "public/crm/index.html немає — спершу збірка"; exit 1; }

rsync -az -e "ssh $K" public/crm/assets/ "$S:/var/www/plan/public/crm/assets/"
rsync -az -e "ssh $K" --exclude assets/ public/crm/ "$S:/var/www/plan/public/crm/"
ssh $K "$S" "set -e; cd /var/www/plan
  find public/crm/assets -type f -mtime +$KEEP_DAYS -print -delete
  chown -R deploy:www-data public/crm
  echo $REV > REVISION"

# «План» — сповіщення, real-time, Web Push, бот (потік S1): розгортання

Лише заготовки. **Кожен крок — тільки з go Івана.** Сервер 139.28.36.181, сайт plan.zavodbuddetal.com.ua.
Консоль — лише `sudo -u deploy php8.5 …`; `nginx -t` перед кожним reload; секрети ніде не друкувати.

## Файли

| Файл | Куди |
|---|---|
| `plan-mercure.Caddyfile` | `/etc/plan-mercure/Caddyfile` (root:deploy 640) |
| `plan-mercure.env.example` | `/etc/plan-mercure/plan-mercure.env` (root:deploy 640, справжній ключ) |
| `plan-mercure.service` | `/etc/systemd/system/plan-mercure.service` |
| `plan-worker.service` | `/etc/systemd/system/plan-worker.service` |
| `nginx-plan-mercure.conf` | вставити в `server { … }` 443 у `/etc/nginx/sites-available/plan` |

## Змінні `.env.local` Плану (`/var/www/plan/.env.local`, 640 deploy:www-data)

| Змінна | Значення | Навіщо |
|---|---|---|
| `PLAN_EVENTS_TRANSPORT` | `doctrine://default?queue_name=plan` | події → воркер (Telegram/Mercure/push поза запитом); воркер слухає `plan` і `async` (копії на Диск S5, SyncDriveFiles) |
| `MERCURE_URL` | `http://127.0.0.1:3100/.well-known/mercure` | куди публікує воркер |
| `MERCURE_PUBLIC_URL` | `https://plan.zavodbuddetal.com.ua/.well-known/mercure` | куди підключається браузер (кука на цьому шляху) |
| `MERCURE_JWT_SECRET` | `openssl rand -base64 48` (той самий, що в plan-mercure.env) | підпис JWT |
| `MERCURE_JWT_ISSUER` | `plan` | |
| `PLAN_VAPID_PUBLIC_KEY` / `PLAN_VAPID_PRIVATE_KEY` | з `plan:push:vapid-keys` | Web Push |
| `TELEGRAM_TOKEN` | значення `PLAN_TELEGRAM_BOT_TOKEN` з `~/.config/buddetal/secrets.env` | бот @zavod_buddetal_plan_bot |
| `TELEGRAM_BOT_USERNAME` | `zavod_buddetal_plan_bot` | deep link прив'язки |
| `TELEGRAM_WEBHOOK_SECRET` | `openssl rand -hex 32` | перевірка вебхука (обов'язково разом із токеном) |
| `PLAN_TELEGRAM_BOT` | `1` | бот лише сповіщає: /start /mute /help, кнопка «Відкрити» |

Порожні `MERCURE_URL` / VAPID / `TELEGRAM_TOKEN` — відповідна частина просто вимкнена (фронт на опитуванні, push «не налаштовано», Telegram мовчить). Стенди заявок нічого з цього не потребують.

## Порядок (кожен пункт — з go)

```sh
S=root@139.28.36.181; K="-i ~/.ssh/buddet_rsa"
# 0. код (гілка після мержу в feature/plan) — звичайний redeploy з runbook, включно з migrate (Version20261115100000)

# 1. Mercure-бінарник і конфіг
ssh $K $S 'cd /tmp && curl -fsSLO https://github.com/dunglas/mercure/releases/download/v0.21.4/mercure_Linux_x86_64.tar.gz \
  && tar xzf mercure_Linux_x86_64.tar.gz mercure && install -m 755 mercure /usr/local/bin/mercure && rm -f mercure mercure_Linux_x86_64.tar.gz && mercure version'
scp $K deploy/plan-mercure.Caddyfile $S:/root/plan-prep/ ; scp $K deploy/plan-mercure.service deploy/plan-worker.service $S:/root/plan-prep/
ssh $K $S 'install -d -m 750 -o root -g deploy /etc/plan-mercure && install -m 640 -o root -g deploy /root/plan-prep/plan-mercure.Caddyfile /etc/plan-mercure/Caddyfile'
# ключ: створити /etc/plan-mercure/plan-mercure.env (640 root:deploy) з одним і тим самим KEY в обох рядках; той самий KEY → MERCURE_JWT_SECRET у .env.local
ssh $K $S 'install -m 644 /root/plan-prep/plan-mercure.service /etc/systemd/system/ && systemctl daemon-reload && systemctl enable --now plan-mercure && sleep 1 && curl -s -o /dev/null -w "hub %{http_code}\n" http://127.0.0.1:3100/healthz'

# 2. nginx (фрагмент у server 443 plan)
ssh $K $S 'cp /etc/nginx/sites-available/plan /root/plan-prep/plan.vhost.bak-$(date +%Y%m%d%H%M)'
#   вставити nginx-plan-mercure.conf → nginx -t && systemctl reload nginx
curl -s -o /dev/null -w "mercure %{http_code} (401 = хаб живий, без куки)\n" "https://plan.zavodbuddetal.com.ua/.well-known/mercure?topic=x"

# 3. VAPID
ssh $K $S 'cd /var/www/plan && sudo -u deploy php8.5 bin/console plan:push:vapid-keys --env=prod'   # 2 рядки → .env.local (не в чат)

# 4. .env.local: змінні з таблиці вище → cache:clear
ssh $K $S 'cd /var/www/plan && sudo -u deploy php8.5 bin/console cache:clear --env=prod && systemctl reload php8.5-fpm'

# 5. воркер (після PLAN_EVENTS_TRANSPORT=doctrine…)
ssh $K $S 'install -m 644 /root/plan-prep/plan-worker.service /etc/systemd/system/ && systemctl daemon-reload && systemctl enable --now plan-worker && systemctl status plan-worker --no-pager | head -5'

# 6. бот Плану: вебхук із секретом і меню команд
ssh $K $S 'cd /var/www/plan && sudo -u deploy php8.5 bin/console plan:bot:webhook --env=prod && sudo -u deploy php8.5 bin/console bot:menu --env=prod'

# 7. перевірка
for h in plan zayavky demo; do curl -s -o /dev/null -w "$h %{http_code}\n" https://$h.zavodbuddetal.com.ua/; done
#   Playwright (дві сесії): коментар A зі згадкою B → у B жовтий тост < 2 с; systemctl stop plan-mercure → у B опитування, без JS-помилок; start назад.
```

Під час кожного деплою коду воркер зупиняється ДО composer/міграцій/`cache:clear` (`systemctl stop plan-worker` — SIGTERM, поточне повідомлення доробляється; найкраще ще до rsync) і стартує після — так робить `deploy/plan-deploy.sh` (з трапом: при помилці воркер усе одно піднімається). Крон — `deploy/plan.cron` (копія `/etc/cron.d/plan`), logrotate — `deploy/plan.logrotate`. Черга збоїв: `messenger:failed:show` / `messenger:failed:retry`; пропущені проєкції дороздає `plan:projections:reconcile` (щогодини з крону).
Відкат: `systemctl disable --now plan-worker plan-mercure`; прибрати фрагмент nginx (nginx -t, reload); у `.env.local` `PLAN_EVENTS_TRANSPORT=sync://`, `MERCURE_URL=` → cache:clear. Дані не губляться: notification-рядки лишаються, фронт переходить на опитування.

Відхилення від PLAN_ARCHITECTURE §3.4: «push лише якщо людина не онлайн у Mercure 60 с» зроблено на боці браузера — service worker не показує системне сповіщення, коли вкладка Плану видима й у фокусі (там уже тост). Серверного трекінгу присутності немає (без зайвих записів у БД).

RAM: Mercure ≈ 20–30 МБ, воркер ≈ 60–128 МБ.

## Вхід через Telegram і заявки на приєднання (15.11.2026)

Головний вхід на «План» — кнопка «Увійти через Telegram» на /vhid (бот @zavod_buddetal_plan_bot):
сайт видає одноразову спробу (5 хв, у базі лише sha256, прив'язана до сесії браузера) →
`t.me/<бот>?start=login_<nonce>` → бот: Telegram уже прив'язаний — вхід підтверджено одразу;
ні — «📱 Поділитися номером» (лише власний контакт) → номер є серед співробітників — Telegram
прив'язується, вхід підтверджено; немає — заявка на приєднання суперадмінам (кнопки в боті +
сторінка /crm/admin/join). Сторінка входу опитує /vhid/telegram/status кожні 2 с і забирає
підтверджений вхід POST-ом /vhid/telegram/complete (CSRF). «🚫 Це був не я» у боті завершує ту сесію.

- Міграція `Version20261116150000` (plan_login_nonce, plan_join_request) — лише нові таблиці.
- `.env.local`: потрібні вже наявні PLAN_TELEGRAM_BOT=1 + TELEGRAM_BOT_USERNAME; нове —
  `PASSWORD_LOGIN_SUPERADMIN_ONLY=1` (пароль — згорнутий запасний вхід лише для суперадмінів;
  на демо-стенді PLAN_DEMO_STAND=1 демо-люди теж входять паролем). Після зміни — cache:clear.
- Людей заздалегідь заводять у «Співробітники → Новий» з телефоном (або plan:user:create --phone);
  далі людина просто входить через Telegram.
- Аудит: «Журнал аудиту» → фільтр «Входи»: user_logged_in (method telegram), login_failed
  (відмови в боті), join_requested, join_decided.

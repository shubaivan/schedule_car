-- «План»: прибрати тестові дані (Олександр, 17.11.2026: «прибираємо тестові дані з БД,
-- залишаємо пусту БД»). Запускає deploy/plan-wipe-test-data.sh — НЕ напряму.
--
-- Лишається: живі люди (усі, крім демо demo-*), їхні ролі, відділи, повний доступ,
-- налаштування сповіщень, push-підписки, Telegram-прив'язки, заявки на приєднання,
-- журнал входів і змін людей; довідники-налаштування (відділи, мітки задач, категорії
-- складу); стан Google Диска; міграції.
-- Іде: задачі (крім :keep_tasks), постачання, склад, контрагенти, «Звʼязок», сповіщення,
-- посилання «поділитися», журнал подій про все це, демо-люди.
--
-- Змінні psql: keep_tasks — id задач, які лишити, через кому ('0' — жодної);
--             commit — 1 записати, 0 — лише показати (ROLLBACK).
\set ON_ERROR_STOP on
\pset footer off
BEGIN;

CREATE TEMP TABLE wipe_keep_task ON COMMIT DROP AS
    SELECT id FROM task WHERE id = ANY (string_to_array(:'keep_tasks', ',')::int[]);
CREATE TEMP TABLE wipe_demo ON COMMIT DROP AS
    SELECT id FROM telegram_user WHERE telegram_id LIKE 'demo-%';

-- Жива людина не може бути автором лишеної задачі-демо: перевірка до будь-яких змін.
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM task t JOIN wipe_keep_task k ON k.id = t.id WHERE t.creator_id IN (SELECT id FROM wipe_demo)) THEN
        RAISE EXCEPTION 'keep_tasks містить задачу демо-людини — так не лишити';
    END IF;
END $$;

\echo '== ДО: скільки рядків'
SELECT 'telegram_user (усі)' AS what, COUNT(*) FROM telegram_user
UNION ALL SELECT 'з них демо (demo-*) — підуть', COUNT(*) FROM wipe_demo
UNION ALL SELECT 'task', COUNT(*) FROM task
UNION ALL SELECT 'task лишаємо', COUNT(*) FROM wipe_keep_task
UNION ALL SELECT 'task_message', COUNT(*) FROM task_message
UNION ALL SELECT 'task_attachment', COUNT(*) FROM task_attachment
UNION ALL SELECT 'supply_request', COUNT(*) FROM supply_request
UNION ALL SELECT 'supply_supplier', COUNT(*) FROM supply_supplier
UNION ALL SELECT 'wh_item', COUNT(*) FROM wh_item
UNION ALL SELECT 'wh_movement', COUNT(*) FROM wh_movement
UNION ALL SELECT 'wh_client', COUNT(*) FROM wh_client
UNION ALL SELECT 'counterparty', COUNT(*) FROM counterparty
UNION ALL SELECT 'plan_chat', COUNT(*) FROM plan_chat
UNION ALL SELECT 'notification', COUNT(*) FROM notification
UNION ALL SELECT 'plan_event', COUNT(*) FROM plan_event;

-- ---------- Задачі (крім лишених) ----------
CREATE TEMP TABLE wipe_task ON COMMIT DROP AS SELECT id FROM task WHERE id NOT IN (SELECT id FROM wipe_keep_task);
DELETE FROM task_message_star WHERE message_id IN (SELECT id FROM task_message WHERE task_id IN (SELECT id FROM wipe_task));
DELETE FROM task_message_edit WHERE message_id IN (SELECT id FROM task_message WHERE task_id IN (SELECT id FROM wipe_task));
DELETE FROM task_attachment WHERE message_id IN (SELECT id FROM task_message WHERE task_id IN (SELECT id FROM wipe_task));
-- Відповіді всередині задачі посилаються на повідомлення тієї ж задачі.
UPDATE task_message SET reply_to_id = NULL WHERE task_id IN (SELECT id FROM wipe_task);
DELETE FROM task_message WHERE task_id IN (SELECT id FROM wipe_task);
DELETE FROM task_member WHERE task_id IN (SELECT id FROM wipe_task);
DELETE FROM task_department WHERE task_id IN (SELECT id FROM wipe_task);
DELETE FROM task_favorite WHERE task_id IN (SELECT id FROM wipe_task);
DELETE FROM task_read WHERE task_id IN (SELECT id FROM wipe_task);
DELETE FROM task_task_label WHERE task_id IN (SELECT id FROM wipe_task);
DELETE FROM task WHERE id IN (SELECT id FROM wipe_task);

-- ---------- Постачання ----------
DELETE FROM supply_attachment;
DELETE FROM supply_purchase;
DELETE FROM supply_comment;
DELETE FROM supply_status_log;
DELETE FROM supply_request;
DELETE FROM supply_supplier;
DELETE FROM supply_staff_phone;

-- ---------- Склад ----------
DELETE FROM wh_activity;
DELETE FROM wh_document;
DELETE FROM wh_movement_line;
DELETE FROM wh_movement;
UPDATE wh_item SET current_site_id = NULL;
DELETE FROM wh_item;
DELETE FROM wh_site;
DELETE FROM wh_client;

-- ---------- Контрагенти (лишені задачі втрачають лише посилання на картку, текст клієнта лишається) ----------
UPDATE task SET counterparty_id = NULL WHERE counterparty_id IS NOT NULL;
DELETE FROM counterparty_contact;
DELETE FROM counterparty;

-- ---------- «Звʼязок», посилання, сповіщення, пошук, Хроніка ----------
DELETE FROM plan_chat_message_edit;
DELETE FROM plan_chat_message;
DELETE FROM plan_chat_member;
DELETE FROM plan_chat;
DELETE FROM share_link;
DELETE FROM notification;
-- Пошук і Хроніку перебудовують команди після коміту (plan:search:reindex, plan:chronicle:rebuild).
DELETE FROM search_document;
DELETE FROM plan_subject_activity;
DELETE FROM plan_read;

-- ---------- Журнал подій: лишаємо лише про людей (входи, зміни карток, приєднання) і лишені задачі ----------
DELETE FROM plan_event_consumed WHERE event_id IN (
    SELECT id FROM plan_event
     WHERE NOT ((subject_type = 'user' AND subject_id NOT IN (SELECT id FROM wipe_demo))
                OR (subject_type = 'task' AND subject_id IN (SELECT id FROM wipe_keep_task))));
DELETE FROM plan_event
 WHERE NOT ((subject_type = 'user' AND subject_id NOT IN (SELECT id FROM wipe_demo))
            OR (subject_type = 'task' AND subject_id IN (SELECT id FROM wipe_keep_task)));
-- Події, де актором була демо-людина (напр. вона змінила картку живої людини), — без актора.
UPDATE plan_event SET actor_id = NULL WHERE actor_id IN (SELECT id FROM wipe_demo);

-- ---------- Демо-люди ----------
UPDATE telegram_user SET archived_by_id = NULL WHERE archived_by_id IN (SELECT id FROM wipe_demo);
UPDATE telegram_user SET access_decided_by_id = NULL WHERE access_decided_by_id IN (SELECT id FROM wipe_demo);
UPDATE plan_join_request SET decided_by_id = NULL WHERE decided_by_id IN (SELECT id FROM wipe_demo);
DELETE FROM crm_login WHERE user_id IN (SELECT id FROM wipe_demo);
DELETE FROM telegram_user WHERE id IN (SELECT id FROM wipe_demo);

\echo '== ПІСЛЯ'
SELECT 'telegram_user' AS what, COUNT(*) FROM telegram_user
UNION ALL SELECT 'task', COUNT(*) FROM task
UNION ALL SELECT 'task_message', COUNT(*) FROM task_message
UNION ALL SELECT 'supply_request', COUNT(*) FROM supply_request
UNION ALL SELECT 'wh_item', COUNT(*) FROM wh_item
UNION ALL SELECT 'counterparty', COUNT(*) FROM counterparty
UNION ALL SELECT 'notification', COUNT(*) FROM notification
UNION ALL SELECT 'plan_event', COUNT(*) FROM plan_event
UNION ALL SELECT 'supply_department (лишається)', COUNT(*) FROM supply_department
UNION ALL SELECT 'task_label (лишається)', COUNT(*) FROM task_label
UNION ALL SELECT 'wh_category (лишається)', COUNT(*) FROM wh_category;
\echo '== Люди, що лишаються'
SELECT id, TRIM(COALESCE(first_name, '') || ' ' || COALESCE(last_name, '')) AS name, work_role, is_superadmin, plan_full_access,
       access_status, archived_at IS NOT NULL AS archived
  FROM telegram_user ORDER BY id;

SELECT CASE WHEN :'commit' = '1' THEN 'COMMIT' ELSE 'ROLLBACK' END AS finish \gset
\echo '== Завершення (ROLLBACK = dry-run, нічого не записано):' :finish
:finish;

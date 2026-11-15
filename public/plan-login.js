/*
 * «Увійти через Telegram» на /vhid (див. App\Plan\Login\TelegramLoginController).
 *
 * 1. Одразу готуємо спробу (POST /vhid/telegram/start): посилання t.me/<бот>?start=login_<nonce>
 *    стає href кнопки — тож натиск відкриває Telegram напряму, без проміжних кроків
 *    (на телефоні — застосунок, на комп'ютері — нова вкладка; також QR для телефона).
 * 2. Після натиску (або сканування QR) питаємо стан кожні 2 с (у фоні — рідше; при
 *    поверненні на вкладку — одразу). confirmed → POST /vhid/telegram/complete → План.
 * 3. Спроба живе 5 хв: поки людина не натиснула, тихо готуємо нову перед закінченням.
 */
(function () {
    'use strict';
    var root = document.getElementById('tg-login');
    if (!root) return;

    var go = document.getElementById('tg-go');
    var again = document.getElementById('tg-again');
    var wait = document.getElementById('tg-wait');
    var code = document.getElementById('tg-code');
    var qr = document.getElementById('tg-qr');
    var form = document.getElementById('tg-complete');
    var csrf = root.getAttribute('data-csrf');
    var state = 'idle'; // idle | waiting | done
    var expiresAt = 0;
    var pollTimer = null;
    var refreshTimer = null;
    var preparing = false;
    var clicked = false; // людина натиснула кнопку (а не лише бачить QR)

    function show(id) {
        ['denied', 'join', 'expired', 'error'].forEach(function (name) {
            var el = document.getElementById('tg-msg-' + name);
            if (el) el.classList.toggle('on', name === id);
        });
    }

    function prepare() {
        if (preparing) return;
        preparing = true;
        go.setAttribute('aria-disabled', 'true');
        fetch('/vhid/telegram/start', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': csrf, 'Accept': 'application/json' },
        }).then(function (r) {
            return r.json().then(function (d) { return { ok: r.ok, data: d }; });
        }).then(function (res) {
            preparing = false;
            if (!res.ok || !res.data.url) {
                show('error');
                return;
            }
            go.href = res.data.url;
            go.removeAttribute('aria-disabled');
            code.textContent = res.data.code;
            if (res.data.qr) {
                qr.src = res.data.qr;
                qr.classList.add('ready');
            }
            expiresAt = Date.now() + res.data.expiresIn * 1000;
            clearTimeout(refreshTimer);
            // Поки людина не натиснула — нова спроба за 20 с до кінця старої.
            refreshTimer = setTimeout(function () { if (state === 'idle') prepare(); }, Math.max(5000, res.data.expiresIn * 1000 - 20000));
            // QR сканують телефоном, не натискаючи кнопку: на комп'ютері чекаємо одразу.
            if (qr.classList.contains('ready') && window.matchMedia('(min-width: 720px) and (hover: hover)').matches) startWaiting(false);
        }).catch(function () {
            preparing = false;
            show('error');
        });
    }

    function startWaiting(visible) {
        if (state === 'done') return;
        state = 'waiting';
        show(null);
        if (visible) wait.classList.add('on');
        poll(visible ? 1500 : 3000);
    }

    function poll(delay) {
        clearTimeout(pollTimer);
        pollTimer = setTimeout(check, delay);
    }

    function check() {
        if (state !== 'waiting') return;
        fetch('/vhid/telegram/status', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (state !== 'waiting') return;
                switch (d.state) {
                    case 'confirmed':
                        state = 'done';
                        form.submit();
                        return;
                    case 'pending':
                        wait.classList.add('on');
                        poll(document.hidden ? 4000 : 2000);
                        return;
                    case 'slow':
                        poll(5000);
                        return;
                    case 'join':
                    case 'denied':
                        stop(d.state);
                        return;
                    default: // expired | none
                        // QR просто висів на екрані — тихо готуємо новий, без помилки.
                        stop(clicked ? 'expired' : null);
                }
            })
            .catch(function () { poll(4000); });
    }

    function stop(message) {
        state = 'idle';
        clicked = false;
        clearTimeout(pollTimer);
        wait.classList.remove('on');
        show(message);
        prepare();
    }

    function opened() {
        if (go.getAttribute('aria-disabled') === 'true') return;
        clicked = true;
        startWaiting(true);
    }

    go.addEventListener('click', opened);
    go.addEventListener('auxclick', opened);
    again.addEventListener('click', function () {
        if (Date.now() > expiresAt) {
            stop('expired');
            return;
        }
        window.open(go.href, '_blank', 'noopener');
    });

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) return;
        if (state === 'waiting') poll(0);
        else if (state === 'idle' && Date.now() > expiresAt - 20000) prepare();
    });

    prepare();
})();

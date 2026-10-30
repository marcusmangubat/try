/**
 * live-notifications.js
 * Framework-free polling + stacked-card notifier for real-time-ish alerts.
 * Requires the including page to set, before loading this file:
 *   window.DMW_USER_EMAIL   – current user's email (for per-user storage key)
 *   window.DMW_NOTIF_API    – path to api/poll_notifications.php
 *   window.DMW_APP_VIEW_URL – (optional) e.g. 'view-application.php?id='
 *
 * How the stack behaves
 *   • Notifications form a deck: the front card is fully visible, and up to
 *     three cards behind it peek out underneath, slightly smaller each step.
 *   • The deck is a queue — new notifications join at the back, so the card
 *     you are reading never gets pushed aside mid-click.
 *   • Dismissing (X) the front card slides it away and the next one moves up.
 *   • Every card is the same fixed height, so the deck never grows past one
 *     card plus a thin strip, no matter how many notifications arrive.
 *   • If the front card is left alone, it slowly slides off to the right and
 *     disappears by itself; only then does the next card move up. Hovering or
 *     focusing the deck pauses the timer (and rescues a card mid-slide).
 */
(function () {
    const API_URL        = window.DMW_NOTIF_API || 'poll_notifications.php';
    const POLL_INTERVAL  = 3000;   // 3s — near real-time without hammering the DB
    const STORAGE_KEY    = 'dmw_last_notif_id_' + (window.DMW_USER_EMAIL || 'guest');
    const ORIGINAL_TITLE = document.title;

    const MAX_DEPTH  = 3;          // how many cards peek out behind the front card
    const MAX_STORED = 100;        // safety cap on queued notifications
    const DWELL_MS   = 6000;       // how long the front card stays before it starts sliding away
    const REDUCED    = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    const SLIDE_MS   = REDUCED ? 50 : 1050;   // slide-out length (CSS transition is 1s)
    const LEAVE_MS   = 260;        // manual dismiss (X) animation length
    const TICK_MS    = 250;        // timer resolution

    // DMW palette — navy for the department, muted gold for items that need action.
    const NAVY = '#0b2c5c', NAVY_SOFT = '#eef2f8';
    const GOLD = '#a5761f', GOLD_SOFT = '#f7ecd6';

    let items  = [];               // queue, front card first: {uid, notif, ts, age, el, leaving}
    let uid    = 0;
    let paused = false;            // pointer or keyboard focus is inside the deck
    let titleFlashTimer = null;

    let panel, stack, bar, countEl;

    //  Helpers 
    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str ?? '';
        return div.innerHTML;
    }

    function relTime(ts) {
        const s = Math.floor((Date.now() - ts) / 1000);
        if (s < 60)   return 'Just now';
        if (s < 3600) return Math.floor(s / 60) + 'm ago';
        return Math.floor(s / 3600) + 'h ago';
    }

    function getLastId() {
        try { return localStorage.getItem(STORAGE_KEY); } catch (e) { return null; }
    }
    function setLastId(v) {
        try { localStorage.setItem(STORAGE_KEY, String(v)); } catch (e) { /* storage blocked */ }
    }

    const live = () => items.filter((it) => !it.leaving);

    //  Styles 
    function injectStyles() {
        const css = `
        #dmw-notif-panel {
            position:fixed; top:16px; right:16px; z-index:99999;
            width:360px; max-width:calc(100vw - 24px);
            font-family:"DM Sans", system-ui, sans-serif;
            display:none; pointer-events:none;
        }
        #dmw-notif-panel.is-open { display:block; }

        /* "N notifications · Clear all" pill, only shown when 2+ cards are queued */
        .dmw-n-bar {
            display:flex; align-items:center; gap:4px; width:fit-content; margin:0 0 8px auto;
            padding:2px 3px 2px 12px; background:rgba(255,255,255,.96);
            border:1px solid #e2e8f0; border-radius:999px; pointer-events:auto;
            box-shadow:0 2px 8px rgba(15,23,42,.10);
            font-size:11.5px; font-weight:600; color:#0f172a;
        }
        .dmw-n-bar[hidden] { display:none; }
        .dmw-n-clear {
            background:none; border:0; cursor:pointer; padding:4px 10px; border-radius:999px;
            font:inherit; color:${NAVY};
        }
        .dmw-n-clear:hover { background:${NAVY_SOFT}; }
        .dmw-n-clear:focus-visible { outline:2px solid ${NAVY}; outline-offset:1px; }

        /* All cards share one grid cell, so the deck is exactly one card tall.
           padding-bottom (set from JS) leaves room for the peeking strips. */
        .dmw-n-stack { display:grid; transition:padding-bottom .28s ease; }

        .dmw-n-card {
            grid-area:1 / 1; box-sizing:border-box; height:76px; position:relative;
            background:#fff; border:1px solid #e2e8f0; border-left:4px solid var(--dmw-accent);
            border-radius:8px; padding:10px 8px 10px 10px; cursor:pointer;
            box-shadow:0 6px 16px rgba(15,23,42,.12), 0 1px 2px rgba(15,23,42,.06);
            transform-origin:top center; pointer-events:none;
            transition:transform .28s cubic-bezier(.2,.8,.2,1), opacity .22s ease,
                       background-color .22s ease, box-shadow .22s ease;
        }
        .dmw-n-card.is-front { pointer-events:auto; }
        .dmw-n-card.is-front:focus-visible { outline:2px solid ${NAVY}; outline-offset:2px; }

        /* Depth steps: each card behind sits 12px lower and 5% narrower,
           which leaves roughly an 8px strip showing under the one in front. */
        .dmw-n-card[data-depth="1"] { transform:translateY(12px) scale(.95); background:#f8fafc;
            box-shadow:0 2px 6px rgba(15,23,42,.08); }
        .dmw-n-card[data-depth="2"] { transform:translateY(24px) scale(.90); background:#f1f5f9;
            box-shadow:0 2px 5px rgba(15,23,42,.06); }
        .dmw-n-card[data-depth="3"] { transform:translateY(36px) scale(.85); background:#e9eef5;
            box-shadow:0 1px 4px rgba(15,23,42,.05); }
        .dmw-n-card.is-deep    { opacity:0; }

        .dmw-n-card.is-entering { opacity:0; transform:translateY(-10px) scale(.97); }
        .dmw-n-card.is-leaving  { opacity:0; transform:translateY(-14px) scale(.98);
            pointer-events:none; z-index:200 !important; }

        /* Auto-dismiss: a slow, eased slide to the right that fades out near the end. */
        .dmw-n-card.is-sliding {
            transform:translateX(125%); opacity:0;
            transition:transform 1s cubic-bezier(.45,0,.25,1), opacity 1s cubic-bezier(.5,0,1,1);
        }

        /* Content is hidden on back cards (only their edge peeks out) and
           fades in as a card moves to the front. */
        .dmw-n-in { display:flex; align-items:flex-start; gap:10px; height:100%; transition:opacity .2s ease; }
        .dmw-n-card:not(.is-front) .dmw-n-in { opacity:0; }

        .dmw-n-ico {
            width:28px; height:28px; border-radius:6px; flex-shrink:0; font-size:12px;
            display:flex; align-items:center; justify-content:center;
            background:var(--dmw-soft); color:var(--dmw-accent);
        }
        .dmw-n-body { flex:1; min-width:0; }
        .dmw-n-top  { display:flex; align-items:baseline; justify-content:space-between; gap:8px; }
        .dmw-n-title {
            font-weight:700; font-size:12.5px; line-height:1.3; color:#0f172a;
            white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
        }
        .dmw-n-time { font-size:10px; color:#94a3b8; flex-shrink:0; }
        .dmw-n-msg {
            margin-top:2px; font-size:11.5px; line-height:1.4; color:#475569;
            display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;
        }
        .dmw-n-x {
            background:none; border:0; cursor:pointer; flex-shrink:0; line-height:1;
            width:26px; height:26px; margin:-4px -2px 0 0; border-radius:6px;
            font-size:12px; color:#94a3b8;
            display:flex; align-items:center; justify-content:center;
        }
        .dmw-n-x:hover { color:#0f172a; background:#e2e8f0; }
        .dmw-n-x:focus-visible { outline:2px solid ${NAVY}; }

        @media (max-width:480px) {
            #dmw-notif-panel { top:8px; left:12px; right:12px; width:auto; max-width:none; }
        }
        @media (prefers-reduced-motion:reduce) {
            .dmw-n-card, .dmw-n-stack, .dmw-n-in { transition:none; }
        }`;
        const tag = document.createElement('style');
        tag.textContent = css;
        document.head.appendChild(tag);
    }

    //  Panel skeleton (built once) ──────────────────────────────────────
    function ensurePanel() {
        if (panel) return;

        panel = document.createElement('div');
        panel.id = 'dmw-notif-panel';
        panel.setAttribute('role', 'region');
        panel.setAttribute('aria-label', 'Notifications');
        panel.innerHTML = `
            <div class="dmw-n-bar" hidden>
                <span class="dmw-n-count"></span>
                <button type="button" class="dmw-n-clear">Clear all</button>
            </div>
            <div class="dmw-n-stack" aria-live="polite"></div>`;
        document.body.appendChild(panel);

        bar     = panel.querySelector('.dmw-n-bar');
        countEl = panel.querySelector('.dmw-n-count');
        stack   = panel.querySelector('.dmw-n-stack');

        panel.querySelector('.dmw-n-clear').addEventListener('click', clearAll);

        const hold = () => { paused = true; live().forEach(cancelSlide); };
        panel.addEventListener('mouseenter', hold);
        panel.addEventListener('mouseleave', () => { paused = false; });
        panel.addEventListener('focusin',    hold);
        panel.addEventListener('focusout',   () => { paused = false; });
    }

    //  Card creation 
    function buildCard(it) {
        const n        = it.notif;
        const isNewApp = n.type === 'new_application';
        const label    = isNewApp ? 'New application' : 'Application update';

        const el = document.createElement('div');
        el.className = 'dmw-n-card is-entering';
        el.style.setProperty('--dmw-accent', isNewApp ? GOLD : NAVY);
        el.style.setProperty('--dmw-soft',   isNewApp ? GOLD_SOFT : NAVY_SOFT);
        el.setAttribute('role', 'button');
        el.setAttribute('aria-label', label + ': ' + (n.title || 'New Notification'));

        el.innerHTML = `
            <div class="dmw-n-in">
                <div class="dmw-n-ico"><i class="fas ${isNewApp ? 'fa-file-circle-plus' : 'fa-bell'}"></i></div>
                <div class="dmw-n-body">
                    <div class="dmw-n-top">
                        <span class="dmw-n-title">${escapeHtml(n.title || 'New Notification')}</span>
                        <span class="dmw-n-time" data-ts="${it.ts}">${relTime(it.ts)}</span>
                    </div>
                    <div class="dmw-n-msg">${escapeHtml(n.message || '')}</div>
                </div>
                <button type="button" class="dmw-n-x" aria-label="Dismiss notification" tabindex="-1">
                    <i class="fas fa-xmark"></i>
                </button>
            </div>`;

        el.querySelector('.dmw-n-x').addEventListener('click', (e) => {
            e.stopPropagation();
            removeItem(it.uid);
        });
        el.addEventListener('click', () => handleNotificationClick(it));
        el.addEventListener('keydown', (e) => {
            if (e.target !== el) return;
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); handleNotificationClick(it); }
            if (e.key === 'Delete' || e.key === 'Escape') { e.preventDefault(); removeItem(it.uid); }
        });

        it.el = el;
        return el;
    }

    //  Layout: assigns each queued card its depth in the deck 
    function layout() {
        ensurePanel();
        const queue = live();

        panel.classList.toggle('is-open', items.length > 0);

        queue.forEach((it, i) => {
            const el = it.el;
            el.dataset.depth = String(Math.min(i, MAX_DEPTH));
            el.style.zIndex  = String(100 - i);
            el.classList.toggle('is-front', i === 0);
            el.classList.toggle('is-deep',  i > MAX_DEPTH);
            el.tabIndex = i === 0 ? 0 : -1;
            el.setAttribute('aria-hidden', i === 0 ? 'false' : 'true');
            const x = el.querySelector('.dmw-n-x');
            x.tabIndex = i === 0 ? 0 : -1;
        });

        stack.style.paddingBottom = Math.min(Math.max(queue.length - 1, 0), MAX_DEPTH) * 12 + 'px';

        bar.hidden = queue.length < 2;
        countEl.textContent = queue.length + ' notifications';

        if (!queue.length) stopFlash();
    }

    //  Queue operations 
    function addNotifications(list) {
        ensurePanel();
        let hasNewApp = false;

        list.forEach((n) => {
            // Feed arrives oldest → newest; push keeps arrival order (FIFO).
            const it = { uid: ++uid, notif: n, ts: Date.now(), age: 0, el: null, leaving: false, sliding: false, slideTimer: null };
            stack.appendChild(buildCard(it));
            items.push(it);
            if (n.type === 'new_application') hasNewApp = true;

            // Let the card render in its "entering" state, then release it so
            // it eases into its place in the deck.
            setTimeout(() => it.el.classList.remove('is-entering'), 30);
        });

        // Drop the oldest queued cards if something has gone badly wrong.
        while (live().length > MAX_STORED) {
            const oldest = live()[0];
            oldest.el.remove();
            items = items.filter((it) => it !== oldest);
        }

        layout();
        if (hasNewApp) flashTitle();
    }

    function dismiss(it) {
        if (!it || it.leaving || it.sliding) return;
        it.leaving = true;
        it.el.classList.add('is-leaving');
        setTimeout(() => {
            it.el.remove();
            items = items.filter((x) => x !== it);
            layout();
        }, LEAVE_MS);
    }

    function removeItem(id) {
        const it = items.find((x) => x.uid === id);
        if (!it || it.leaving || it.sliding) return;

        const hadFocus = it.el.contains(document.activeElement);
        dismiss(it);
        layout();                       // next card moves up immediately

        if (hadFocus) {
            const next = live()[0];
            if (next) next.el.focus({ preventScroll: true });
        }
    }

    // Auto-dismiss: the card stays in the front slot while it slides away, so the
    // next card only moves up once this one is gone.
    function startSlide(it) {
        if (!it || it.leaving || it.sliding) return;
        it.sliding = true;
        it.el.classList.add('is-sliding');
        it.slideTimer = setTimeout(() => {
            it.leaving = true;
            it.el.remove();
            items = items.filter((x) => x !== it);
            layout();
        }, SLIDE_MS);
    }

    function cancelSlide(it) {
        if (!it || !it.sliding || it.leaving) return;
        clearTimeout(it.slideTimer);
        it.sliding = false;
        it.age = 0;
        it.el.classList.remove('is-sliding');
    }

    function clearAll() {
        live().forEach(dismiss);
        layout();
    }

    //  Ticker: relative times + auto-dismiss of the front card 
    let tickCount = 0;
    setInterval(() => {
        if (!items.length) return;

        if (++tickCount % Math.round(1000 / TICK_MS) === 0) {
            panel.querySelectorAll('[data-ts]').forEach((el) => {
                el.textContent = relTime(Number(el.getAttribute('data-ts')));
            });
        }

        const front = live()[0];
        if (!front || front.sliding || paused || document.hidden) return;

        front.age += TICK_MS;           // only the front card's clock runs
        if (front.age >= DWELL_MS) startSlide(front);
    }, TICK_MS);

    //  Tab title flash 
    function flashTitle() {
        if (titleFlashTimer) return;
        let on = false;
        titleFlashTimer = setInterval(() => {
            document.title = on ? ORIGINAL_TITLE : '• New Application — DMW';
            on = !on;
        }, 1000);
        window.addEventListener('focus', stopFlash, { once: true });
    }

    function stopFlash() {
        if (titleFlashTimer) {
            clearInterval(titleFlashTimer);
            titleFlashTimer = null;
            document.title = ORIGINAL_TITLE;
        }
    }

    //  Click routing 
    // 'new_application' cards point at cases still in the shared, unclaimed
    // queue (handling_party IS NULL) — opening one claims it, so it is gated
    // behind the same confirmation used on applications.php's Available tab.
    // Every other type is about a case the evaluator already owns and
    // navigates straight through.
    function handleNotificationClick(it) {
        const notif = it.notif;

        if (!notif.application_id || !window.DMW_APP_VIEW_URL) {
            removeItem(it.uid);
            return;
        }

        const targetUrl = window.DMW_APP_VIEW_URL + notif.application_id;

        if (notif.type !== 'new_application') {
            window.location.href = targetUrl;
            return;
        }

        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: 'question',
                title: 'Evaluate this application?',
                text: 'Opening this will claim it for evaluation and assign it to you. It will then leave the shared Open for Evaluation queue and other evaluators will no longer be able to pick it up.',
                showCancelButton: true,
                confirmButtonColor: '#1e40af',
                cancelButtonColor: '#94a3b8',
                confirmButtonText: 'Yes, claim & evaluate',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = targetUrl;
                } else {
                    removeItem(it.uid);
                }
            });
        } else if (window.confirm('Opening this will claim it for evaluation and assign it to you. Continue?')) {
            window.location.href = targetUrl;
        } else {
            removeItem(it.uid);
        }
    }

    //  KPI sync: keeps the "Available" count in lockstep with the same
    // poll response that drives the notifications, so they never disagree. 
    function updateAvailableKpi(newCount) {
        const targets = [
            document.getElementById('available-count'),
            document.getElementById('available-count-subbar'),
        ].filter(Boolean);

        if (!targets.length) return; // page doesn't have this KPI (e.g. admin pages)

        targets.forEach((el) => {
            const prev = parseInt((el.textContent || '0').replace(/,/g, ''), 10) || 0;
            if (prev === newCount) return;

            el.textContent = newCount.toLocaleString('en-US');

            el.style.transition = 'color .15s ease, transform .15s ease';
            el.style.color = newCount > prev ? '#059669' : '#0f172a';
            el.style.transform = 'scale(1.12)';
            setTimeout(() => { el.style.transform = 'scale(1)'; }, 200);
        });

        const card = document.getElementById('available-kpi-card');
        if (card) {
            card.style.transition = 'box-shadow .3s ease';
            card.style.boxShadow = '0 0 0 3px rgba(5,150,105,.25)';
            setTimeout(() => { card.style.boxShadow = ''; }, 900);
        }
    }

    //  Polling 
    async function initBaseline() {
        try {
            const res = await fetch(API_URL + '?init=1', { credentials: 'same-origin' });
            const data = await res.json();
            if (data.success) {
                if (getLastId() === null) setLastId(data.last_id);
                if (typeof data.available_count === 'number') {
                    updateAvailableKpi(data.available_count);
                }
            }
        } catch (e) { /* silent — retries next cycle */ }
    }

    async function poll() {
        const lastId = getLastId() || '0';
        try {
            const res = await fetch(API_URL + '?since_id=' + encodeURIComponent(lastId), {
                credentials: 'same-origin'
            });
            const data = await res.json();
            if (data.success) {
                if (data.notifications.length) {
                    addNotifications(data.notifications);
                    setLastId(data.last_id);
                }
                if (typeof data.available_count === 'number') {
                    updateAvailableKpi(data.available_count);
                }
            }
        } catch (e) { /* silent — network hiccup, retries next cycle */ }
    }

    //  Boot 
    injectStyles();

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) stopFlash();
    });

    initBaseline().then(() => {
        poll();
        setInterval(poll, POLL_INTERVAL);
    });
})();
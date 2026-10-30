(function () {
    const btn  = document.getElementById('avatarMenuBtn');
    const menu = document.getElementById('avatarMenu');
    if (!btn || !menu) return;

    /* ── Animation styles (same pop-in as the notification modal) ── */
    const style = document.createElement('style');
    style.textContent = `
        .avatar-menu { transform-origin: top right; }
        .avatar-menu:not(.hidden) {
            animation: avatarMenuPop .22s cubic-bezier(.34, 1.56, .64, 1);
        }
        .avatar-menu::before {
            content: '';
            position: absolute;
            top: -7px;
            right: 14px;
            width: 14px;
            height: 14px;
            background: var(--surface);
            border-left: 1px solid var(--border);
            border-top: 1px solid var(--border);
            transform: rotate(45deg);
        }
        @keyframes avatarMenuPop {
            0%   { opacity: 0; transform: translateY(-8px) scale(.92); }
            100% { opacity: 1; transform: translateY(0) scale(1); }
        }
        #avatarMenuBtn { transition: transform .15s ease; }
        #avatarMenuBtn:hover  { transform: scale(1.06); }
        #avatarMenuBtn:active { transform: scale(.95); }
        @media (prefers-reduced-motion: reduce) {
            .avatar-menu:not(.hidden) { animation: none; }
            #avatarMenuBtn { transition: none; }
        }
    `;
    document.head.appendChild(style);

    /* ── Prepare the menu markup so the arrow isn't clipped ── */
    menu.classList.add('avatar-menu');
    menu.classList.remove('overflow-hidden');
    menu.classList.remove('top-[calc(100%+10px)]');
    menu.classList.add('top-[calc(100%+12px)]');

    // Move the content into an inner wrapper that does the clipping.
    // Skipped if the wrapper already exists (e.g. you applied the earlier markup edit).
    if (!menu.querySelector(':scope > .avatar-inner')) {
        const inner = document.createElement('div');
        inner.className = 'avatar-inner relative rounded-[14px] overflow-hidden';
        while (menu.firstChild) inner.appendChild(menu.firstChild);
        menu.appendChild(inner);
    }

    /* ── Open / close ── */
    function openMenu() {
        menu.classList.remove('hidden');
        btn.setAttribute('aria-expanded', 'true');
    }
    function closeMenu() {
        menu.classList.add('hidden');
        btn.setAttribute('aria-expanded', 'false');
    }

    btn.addEventListener('click', function (e) {
        e.stopPropagation();
        menu.classList.contains('hidden') ? openMenu() : closeMenu();
    });

    menu.addEventListener('click', function (e) { e.stopPropagation(); });
    document.addEventListener('click', closeMenu);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { closeMenu(); btn.focus(); }
    });
})();
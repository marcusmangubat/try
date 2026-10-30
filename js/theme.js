// theme.js — shared across every DMW page (client, evaluator, admin)
(function () {
    const KEY = 'dmw_theme';

    function apply(theme) {
        document.documentElement.setAttribute('data-theme', theme);
    }

    function getStored() {
        return localStorage.getItem(KEY);
    }

    function toggle() {
        const current = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
        const next = current === 'dark' ? 'light' : 'dark';
        apply(next);
        localStorage.setItem(KEY, next);
    }

    // Expose for the button's onclick + for main.js if needed
    window.DMWTheme = { toggle, apply };

    // Wire up any .theme-toggle button once DOM is ready
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.theme-toggle').forEach(btn => {
            btn.addEventListener('click', toggle);
        });
    });
})();
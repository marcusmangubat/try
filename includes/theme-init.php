<?php
// includes/theme-init.php
// Must be included as the FIRST thing inside <head>, before any CSS.
// Prevents flash-of-light-mode by setting data-theme before render.
?>
<script>
    (function() {
        var t = localStorage.getItem('dmw_theme') || 'light';
        document.documentElement.setAttribute('data-theme', t);
    })();
</script>
{{-- The visitor's preferences, restored before the first paint: a page never shows itself in the
     wrong theme, nor animated for a fraction of a second before stopping (LUMN-11). One copy, for
     every layout. The themes it accepts come from resources/js/themes.json, the list the islands
     use too (config/themes.php).

     The motion choice is copied as stored. Only 'on' and 'off' mean anything — to the stylesheet
     (`when-motion-stopped` in _effects.scss) and to utils/motion.js alike — and anything else is no
     choice: the system's preference decides. Validating it here would write that rule a third time.

     Storage can be refused — a private window, blocked site data — and reading it then throws: the
     defaults apply, the theme of <html> and the preference. --}}
<script nonce="{{ Vite::cspNonce() }}">
    (function () {
        try {
            const theme = localStorage.getItem('theme');
            if (theme && @json(config('themes.ids')).includes(theme)) {
                document.documentElement.setAttribute('data-theme', theme);
                const favicon = document.getElementById('favicon');
                if (favicon) favicon.href = '/favicons/favicon-' + theme + '.svg';
            }

            const motion = localStorage.getItem('motion');
            if (motion !== null) document.documentElement.setAttribute('data-motion', motion);
        } catch (refused) {
            // The defaults apply.
        }
    })();
</script>

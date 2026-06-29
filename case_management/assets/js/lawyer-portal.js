(function () {
    'use strict';

    function qs(sel, root) {
        return (root || document).querySelector(sel);
    }

    function initThemeToggle() {
        var btn = qs('#lawyerThemeToggle');
        if (!btn) {
            return;
        }

        btn.addEventListener('click', function () {
            if (btn.disabled) {
                return;
            }

            var current = btn.getAttribute('data-theme-mode') === 'dark' ? 'dark' : 'light';
            var next = current === 'dark' ? 'light' : 'dark';
            btn.disabled = true;

            fetch('lawyer-theme-api.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ theme_mode: next })
            })
                .then(function (response) {
                    return response.json();
                })
                .then(function (data) {
                    if (data && data.ok) {
                        window.location.reload();
                        return;
                    }
                    btn.disabled = false;
                })
                .catch(function () {
                    btn.disabled = false;
                });
        });
    }

    document.addEventListener('DOMContentLoaded', initThemeToggle);
})();

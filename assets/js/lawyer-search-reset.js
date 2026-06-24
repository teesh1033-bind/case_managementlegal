/**
 * Lawyer portal — reset buttons for search bars and live filters.
 */
(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-lawyer-search-reset]');
        if (!button) {
            return;
        }

        var targetId = button.getAttribute('data-lawyer-search-reset');
        var input = targetId ? document.getElementById(targetId) : null;
        if (input) {
            input.value = '';
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.focus();
        }

        var clearParam = button.getAttribute('data-clear-url-param');
        if (clearParam && window.history && window.history.replaceState) {
            var url = new URL(window.location.href);
            clearParam.split(',').forEach(function (param) {
                param = String(param || '').trim();
                if (param !== '') {
                    url.searchParams.delete(param);
                }
            });
            var next = url.pathname + url.search + url.hash;
            window.history.replaceState({}, '', next);
        }
    });
})();

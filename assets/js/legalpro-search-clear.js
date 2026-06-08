(function () {
    function updateClearButton(input, button) {
        var hasValue = String(input.value || '').length > 0;
        button.classList.toggle('is-visible', hasValue);
        button.hidden = !hasValue;
    }

    function initSearchClearContainer(container) {
        if (!container || container.dataset.searchClearInit === '1') {
            return;
        }

        var inputGroup = container.classList.contains('input-group')
            ? container
            : container.querySelector('.input-group');

        if (!inputGroup) {
            return;
        }

        var input = inputGroup.querySelector('input[name="q"], input[type="search"]');
        if (!input) {
            return;
        }

        container.dataset.searchClearInit = '1';
        inputGroup.classList.add('legalpro-search-input-group');

        var clearButton = document.createElement('button');
        clearButton.type = 'button';
        clearButton.className = 'legalpro-search-clear';
        clearButton.setAttribute('aria-label', 'Clear search');
        clearButton.innerHTML = '<i class="fas fa-times" aria-hidden="true"></i>';

        clearButton.addEventListener('click', function () {
            input.value = '';
            input.focus();
            updateClearButton(input, clearButton);
            input.dispatchEvent(new Event('input', { bubbles: true }));
        });

        input.addEventListener('input', function () {
            updateClearButton(input, clearButton);
        });

        inputGroup.appendChild(clearButton);
        updateClearButton(input, clearButton);
    }

    function initSearchClear(root) {
        var scope = root || document;
        scope.querySelectorAll('.legalpro-navbar-search, .search-hero-field').forEach(initSearchClearContainer);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            initSearchClear();
        });
    } else {
        initSearchClear();
    }
})();

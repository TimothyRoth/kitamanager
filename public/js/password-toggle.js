/*
 * Show/hide button of password fields.
 *
 * Kept ES5 and separate from script.js because the login page loads it too,
 * and that page must keep working in old embedded browsers.
 */
(function () {
    'use strict';

    function enable(button) {
        var input = button.parentNode.querySelector('input');

        button.addEventListener('click', function () {
            var reveal = input.type === 'password';
            var label = reveal ? 'Passwort verbergen' : 'Passwort anzeigen';

            input.type = reveal ? 'text' : 'password';
            button.setAttribute('aria-pressed', reveal ? 'true' : 'false');
            button.setAttribute('aria-label', label);
            button.setAttribute('title', label);
        });

        button.removeAttribute('hidden');
    }

    function enableAll() {
        var buttons = document.querySelectorAll('[data-password-toggle]');
        for (var i = 0; i < buttons.length; i++) {
            enable(buttons[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', enableAll);
    } else {
        enableAll();
    }
})();

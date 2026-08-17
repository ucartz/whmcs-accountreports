/**
 * Account Reports - light client-side niceties. No framework, no
 * bundler: plain DOM APIs only, safe to include as a static <script>
 * tag from the client area template.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var filterForm = document.querySelector('.accountreports-filters form');
        if (filterForm) {
            filterForm.addEventListener('submit', function (event) {
                var from = filterForm.querySelector('[name="due_date_from"]');
                var to = filterForm.querySelector('[name="due_date_to"]');
                if (from && to && from.value && to.value && from.value > to.value) {
                    event.preventDefault();
                    window.alert('"Due Date From" must be on or before "Due Date To".');
                }
            });
        }

        // Prevent double-submitting an export request (e.g. double-click)
        // while a download is being generated server-side.
        var exportForm = document.querySelector('.accountreports-export form');
        if (exportForm) {
            exportForm.addEventListener('submit', function () {
                var buttons = exportForm.querySelectorAll('button[type="submit"]');
                // Deferred via setTimeout(..., 0): disabling the button
                // that was just clicked *synchronously*, inside its own
                // submit handler, makes some browsers (notably Safari)
                // drop that button's name=value pair (format=csv/pdf)
                // from the submitted form data entirely -- silently
                // breaking every export regardless of server config.
                // Deferring to the next tick lets the browser finish
                // serializing the submission first.
                window.setTimeout(function () {
                    buttons.forEach(function (btn) {
                        btn.disabled = true;
                    });
                }, 0);
                // Re-enable shortly after in case the browser blocks the
                // download prompt or the request fails client-side.
                window.setTimeout(function () {
                    buttons.forEach(function (btn) {
                        btn.disabled = false;
                    });
                }, 4000);
            });
        }
    });
})();

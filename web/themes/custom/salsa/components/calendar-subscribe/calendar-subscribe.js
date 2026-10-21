/**
 * @file
 * Progressive enhancement for the calendar subscription modal.
 *
 * Rebuilds the "Abonnieren" link from the form selection and disables both
 * actions when nothing is selected. Where invoker commands (commandfor) or
 * `closedby` are not supported it wires the dialog itself. Without JavaScript
 * the <noscript> download link and the GET submit of the download button keep
 * working.
 */

((Drupal, once) => {
  'use strict';

  Drupal.behaviors.salsaCalendarSubscribe = {
    attach(context) {
      once('salsa-calendar-subscribe', '[data-salsa-calendar-subscribe]', context).forEach((root) => {
        const form = /** @type {HTMLFormElement | null} */ (root.querySelector('form'));
        const subscribe = /** @type {HTMLAnchorElement | null} */ (root.querySelector('[data-salsa-subscribe]'));
        const download = /** @type {HTMLButtonElement | null} */ (root.querySelector('[data-salsa-download]'));

        if (!form || !subscribe || !download) {
          return;
        }

        const dialog = /** @type {HTMLDialogElement | null} */ (root.querySelector('dialog'));
        if (dialog && typeof dialog.showModal === 'function') {
          // Fallback for browsers without invoker commands.
          if (!('command' in HTMLButtonElement.prototype)) {
            root.querySelector('button[command="show-modal"]')
              ?.addEventListener('click', () => dialog.showModal());
            dialog.querySelector('button[command="close"]')
              ?.addEventListener('click', () => dialog.close());
          }

          // Fallback for browsers without `closedby`.
          if (!('closedBy' in HTMLDialogElement.prototype)) {
            dialog.addEventListener('click', (event) => {
              if (event.target !== dialog) {
                return;
              }
              const rect = dialog.getBoundingClientRect();
              const inside = event.clientX >= rect.left
                && event.clientX <= rect.right
                && event.clientY >= rect.top
                && event.clientY <= rect.bottom;
              if (!inside) {
                dialog.close();
              }
            });
          }
        }

        const checkboxes = /** @type {HTMLInputElement[]} */ (
          Array.from(form.querySelectorAll('input[type="checkbox"]'))
        );

        const update = () => {
          const hasSelection = checkboxes.some((checkbox) => checkbox.checked);

          // A GET submit would serialize the form the same way; fromQuery()
          // accepts both the repeated and the comma separated notation. The
          // form has no file inputs, so the entries are plain strings.
          const query = new URLSearchParams(
            Array.from(new FormData(form), ([key, value]) => [key, String(value)]),
          ).toString();
          const action = form.getAttribute('action') || window.location.pathname;
          const base = new URL(action, window.location.href)
            .toString()
            .replace(/^https?:\/\//, 'webcal://');

          subscribe.href = query === '' ? base : `${base}?${query}`;
          subscribe.setAttribute('aria-disabled', hasSelection ? 'false' : 'true');
          subscribe.tabIndex = hasSelection ? 0 : -1;
          subscribe.classList.toggle('pointer-events-none', !hasSelection);
          subscribe.classList.toggle('opacity-50', !hasSelection);
          download.disabled = !hasSelection;
        };

        form.addEventListener('change', update);
        subscribe.addEventListener('click', (event) => {
          if (subscribe.getAttribute('aria-disabled') === 'true') {
            event.preventDefault();
          }
        });

        update();
      });
    },
  };
})(Drupal, once);

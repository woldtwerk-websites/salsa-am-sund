/**
 * @file
 * Progressive enhancement for the gallery lightbox: previous/next navigation.
 *
 * The lightboxes are native modal <dialog>s; the thumbnail and the close button
 * open and close them through invoker commands (commandfor/command). This file
 * switches between the dialogs of one gallery and falls back to
 * showModal()/close() and a backdrop click where invoker commands or
 * `closedby` are not supported.
 */

((Drupal, once) => {
  'use strict';

  Drupal.behaviors.salsaLightboxNav = {
    attach(context) {
      once('salsa-lightbox-nav', '.salsa-lightbox', context).forEach((lightboxElement) => {
        const lightbox = /** @type {HTMLDialogElement} */ (lightboxElement);
        if (typeof lightbox.showModal !== 'function') {
          return;
        }

        const previous = /** @type {HTMLElement | null} */ (
          lightbox.querySelector('[data-salsa-lightbox-prev]')
        );
        const next = /** @type {HTMLElement | null} */ (
          lightbox.querySelector('[data-salsa-lightbox-next]')
        );
        const gallery = lightbox.closest('[data-salsa-gallery]');

        if (!previous || !next || !gallery) {
          return;
        }

        // Fallback for browsers without invoker commands.
        if (!('command' in HTMLButtonElement.prototype)) {
          document
            .querySelectorAll(`[commandfor="${lightbox.id}"][command="show-modal"]`)
            .forEach((trigger) => trigger.addEventListener('click', () => lightbox.showModal()));
          lightbox
            .querySelector('[command="close"]')
            ?.addEventListener('click', () => lightbox.close());
        }

        // Fallback for browsers without `closedby`.
        if (!('closedBy' in HTMLDialogElement.prototype)) {
          lightbox.addEventListener('click', (event) => {
            if (event.target !== lightbox) {
              return;
            }
            const rect = lightbox.getBoundingClientRect();
            const inside = event.clientX >= rect.left
              && event.clientX <= rect.right
              && event.clientY >= rect.top
              && event.clientY <= rect.bottom;
            if (!inside) {
              lightbox.close();
            }
          });
        }

        const lightboxes = () => /** @type {HTMLDialogElement[]} */ (
          Array.from(gallery.querySelectorAll('.salsa-lightbox'))
        );

        if (lightboxes().length < 2) {
          return;
        }

        /**
         * Shows another lightbox without the open/close animation.
         *
         * Both dialogs are marked so main.css drops the transitions and the
         * @starting-style values. The marker is removed after the target has
         * painted its opened state (two frames), so its next open animates
         * again.
         *
         * @param {HTMLDialogElement} target
         */
        const showInstant = (target) => {
          lightbox.setAttribute('data-salsa-lightbox-instant', '');
          target.setAttribute('data-salsa-lightbox-instant', '');
          lightbox.close();
          target.showModal();

          requestAnimationFrame(() => {
            requestAnimationFrame(() => {
              lightbox.removeAttribute('data-salsa-lightbox-instant');
              target.removeAttribute('data-salsa-lightbox-instant');
            });
          });
        };

        /** @param {number} direction -1 for previous, 1 for next. */
        const go = (direction) => {
          if (!lightbox.open) {
            return;
          }

          const all = lightboxes();
          const current = all.indexOf(lightbox);
          if (current < 0) {
            return;
          }

          const target = all[(current + direction + all.length) % all.length];
          const targetButton = /** @type {HTMLElement | null} */ (
            target.querySelector(
              direction < 0
                ? '[data-salsa-lightbox-prev]'
                : '[data-salsa-lightbox-next]',
            )
          );

          showInstant(target);

          if (targetButton) {
            targetButton.hidden = false;
            targetButton.focus();
          }
        };

        lightbox.addEventListener('toggle', (event) => {
          if (event.newState === 'open') {
            previous.hidden = false;
            next.hidden = false;
          }
        });

        previous.addEventListener('click', () => go(-1));
        next.addEventListener('click', () => go(1));

        lightbox.addEventListener('keydown', (event) => {
          if (event.key === 'ArrowLeft') {
            event.preventDefault();
            go(-1);
          }
          else if (event.key === 'ArrowRight') {
            event.preventDefault();
            go(1);
          }
        });

        let startX = 0;
        let startY = 0;

        lightbox.addEventListener('pointerdown', (event) => {
          if (event.pointerType === 'mouse') {
            return;
          }
          startX = event.clientX;
          startY = event.clientY;
        });

        lightbox.addEventListener('pointerup', (event) => {
          if (event.pointerType === 'mouse') {
            return;
          }

          const deltaX = event.clientX - startX;
          const deltaY = event.clientY - startY;
          if (Math.abs(deltaX) > 50 && Math.abs(deltaX) > Math.abs(deltaY)) {
            go(deltaX < 0 ? 1 : -1);
          }
        });
      });
    },
  };
})(Drupal, once);

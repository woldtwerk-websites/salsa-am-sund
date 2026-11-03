/**
 * @file
 * Dropzone and client-side upload status for image widgets.
 *
 * The server renders the empty dropzone or the uploaded file row. While
 * Drupal's AJAX upload runs, this behavior shows the picked local file in a
 * row cloned from the widget template, including a preview, size and an
 * indeterminate progress bar.
 */

((Drupal, once) => {
  'use strict';

  const UNITS = ['B', 'KB', 'MB', 'GB'];

  /**
   * Formats a byte count for the current document language.
   *
   * @param {number} bytes
   *   The file size in bytes.
   *
   * @return {string}
   *   A human-readable size, for example "1,2 MB".
   */
  const formatSize = (bytes) => {
    let value = bytes;
    let unit = 0;
    while (value >= 1024 && unit < UNITS.length - 1) {
      value /= 1024;
      unit += 1;
    }

    const formatter = new Intl.NumberFormat(document.documentElement.lang || undefined, {
      maximumFractionDigits: unit === 0 ? 0 : 1,
    });
    return `${formatter.format(value)} ${UNITS[unit]}`;
  };

  Drupal.behaviors.salsaImageUpload = {
    attach(context) {
      once('salsa-image-upload', '[data-salsa-image-upload]', context).forEach((element) => {
        const root = /** @type {HTMLElement} */ (element);
        const input = /** @type {HTMLInputElement | null} */ (
          root.querySelector('input[type="file"]')
        );
        const dropzone = /** @type {HTMLElement | null} */ (
          root.querySelector('[data-salsa-dropzone]')
        );
        const template = /** @type {HTMLTemplateElement | null} */ (
          root.querySelector('template[data-salsa-upload-row]')
        );

        if (!input || !dropzone || !template) {
          return;
        }

        const stopDragging = () => {
          delete dropzone.dataset.dragging;
        };

        ['dragenter', 'dragover'].forEach((type) => {
          dropzone.addEventListener(type, (event) => {
            event.preventDefault();
            dropzone.dataset.dragging = 'true';
          });
        });

        dropzone.addEventListener('dragleave', (event) => {
          const related = event.relatedTarget;
          if (related instanceof Node && dropzone.contains(related)) {
            return;
          }
          stopDragging();
        });

        dropzone.addEventListener('drop', (event) => {
          event.preventDefault();
          stopDragging();

          const file = event.dataTransfer?.files?.[0];
          if (!file || !file.type.startsWith('image/')) {
            return;
          }

          const transfer = new DataTransfer();
          transfer.items.add(file);
          input.files = transfer.files;
          input.dispatchEvent(new Event('change', { bubbles: true }));
        });

        input.addEventListener('change', () => {
          // Drupal's file.js validates the extension in its own change
          // handler, clears input.value on failure and stops later handlers.
          // Defer so a rejected file never leaves a spinning row behind.
          setTimeout(() => {
            if (!input.value) {
              return;
            }

            const file = input.files?.[0];
            if (!file || root.querySelector('[data-salsa-upload-row-added]')) {
              return;
            }

            const row = /** @type {HTMLElement | null} */ (
              template.content.firstElementChild?.cloneNode(true)
            );
            if (!row) {
              return;
            }

            const preview = /** @type {HTMLImageElement | null} */ (
              row.querySelector('[data-salsa-upload-preview]')
            );
            const name = row.querySelector('[data-salsa-upload-name]');
            const size = row.querySelector('[data-salsa-upload-size]');

            if (preview) {
              const url = URL.createObjectURL(file);
              preview.addEventListener('load', () => URL.revokeObjectURL(url), { once: true });
              preview.addEventListener('error', () => URL.revokeObjectURL(url), { once: true });
              preview.src = url;
            }
            if (name) {
              name.textContent = file.name;
            }
            if (size) {
              size.textContent = formatSize(file.size);
            }

            row.setAttribute('data-salsa-upload-row-added', '');
            // The flex class overrides [hidden], so hide the dropzone inline.
            dropzone.style.display = 'none';
            dropzone.after(row);
          }, 0);
        });
      });
    },
  };
})(Drupal, once);

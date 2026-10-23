/**
 * @file
 * Tab behaviour.
 *
 * Builds the tab list from the panels, so without JavaScript every panel stays
 * visible and the list stays hidden. The active tab is kept in the URL
 * parameter of the component and in localStorage.
 */

/**
 * A panel of the tabs component.
 *
 * @typedef {object} SalsaTabsPanel
 * @property {HTMLElement} element
 *   The panel element.
 * @property {string} name
 *   The short name used in the URL and in storage.
 * @property {string} label
 *   The label of the tab button, visible or only for assistive technology.
 */

((Drupal, once) => {
  'use strict';

  // The pill buttons follow the theme's outline and flame buttons. The active
  // state is toggled on the button, so the list reads as one control. A tab
  // with an icon shows the icon only, so it gets a square button instead of
  // the horizontal padding and drops the visible label.
  const BUTTON_CLASSES = [
    'inline-flex items-center justify-center gap-2 rounded-full border text-sm font-semibold',
    'transition duration-200 ease-out',
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-flame-gold',
  ];
  const TEXT_BUTTON_CLASSES = ['w-fit', 'px-5', 'py-2.5'];
  const ICON_BUTTON_CLASSES = ['size-10', 'p-2.5'];
  const ACTIVE_CLASSES = ['border-flame-deep', 'bg-flame-deep', 'text-white'];
  const INACTIVE_CLASSES = [
    'border-[var(--surface-border)]',
    'bg-transparent',
    'text-[var(--surface-fg)]',
    'hover:bg-flame-gold/10',
  ];

  // The ids of the buttons and panels must be unique across the page.
  let nextId = 0;

  /**
   * Reads a value from localStorage.
   *
   * @param {string} key
   *   The storage key.
   *
   * @return {string|null}
   *   The stored value, or NULL when storage is not available.
   */
  const readStorage = (key) => {
    try {
      return window.localStorage.getItem(key);
    } catch {
      // Storage can be blocked by the browser.
      return null;
    }
  };

  /**
   * Writes a value to localStorage.
   *
   * @param {string} key
   *   The storage key.
   * @param {string} value
   *   The value.
   */
  const writeStorage = (key, value) => {
    try {
      window.localStorage.setItem(key, value);
    } catch {
      // Storage can be blocked by the browser.
    }
  };

  /**
   * Returns the index of the initially active panel.
   *
   * The URL wins over the stored value, the first panel is the fallback.
   *
   * @param {SalsaTabsPanel[]} panels
   *   The panels of the component.
   * @param {string} param
   *   The URL parameter, empty when the component is not persisted.
   *
   * @return {number}
   *   The index of the active panel.
   */
  const initialIndex = (panels, param) => {
    if (!param) {
      return 0;
    }
    const url = new URLSearchParams(window.location.search).get(param);
    const stored = readStorage(`salsa:tabs:${param}`);
    for (const name of [url, stored]) {
      const index = panels.findIndex((panel) => panel.name === name);
      if (index !== -1) {
        return index;
      }
    }
    return 0;
  };

  Drupal.behaviors.salsaTabs = {
    attach(context) {
      once('salsa-tabs', '[data-salsa-tabs]', context).forEach((element) => {
        const root = /** @type {HTMLElement} */ (element);
        const list = /** @type {HTMLElement | null} */ (
          root.querySelector('[data-salsa-tabs-list]')
        );
        // Only the direct children belong to this instance, so tabs nested in
        // a panel do not end up in the parent's list.
        const panels = /** @type {HTMLElement[]} */ (
          Array.from(root.querySelectorAll(':scope > [data-salsa-tab]'))
        ).map((panel) => ({
          element: panel,
          name: panel.dataset.name || '',
          label: panel.dataset.label || '',
        }));

        if (!list || panels.length === 0) {
          return;
        }

        const param = root.dataset.param || '';
        const storageKey = `salsa:tabs:${param}`;
        const base = `salsa-tabs-${nextId++}`;
        /** @type {HTMLButtonElement[]} */
        const buttons = [];

        /**
         * Shows the panel at the given index.
         *
         * Only a user change updates the URL; the initial tab may come from the
         * URL already and is only remembered in storage.
         *
         * @param {number} index
         *   The index of the panel.
         * @param {boolean} updateUrl
         *   Whether to store the active tab in the URL.
         */
        const select = (index, updateUrl) => {
          panels.forEach((panel, position) => {
            const active = position === index;
            panel.element.hidden = !active;
            buttons[position].setAttribute('aria-selected', active ? 'true' : 'false');
            buttons[position].tabIndex = active ? 0 : -1;
            ACTIVE_CLASSES.forEach((className) => {
              buttons[position].classList.toggle(className, active);
            });
            INACTIVE_CLASSES.forEach((className) => {
              buttons[position].classList.toggle(className, !active);
            });
          });

          if (!param) {
            return;
          }
          if (updateUrl) {
            const url = new URL(window.location.href);
            url.searchParams.set(param, panels[index].name);
            history.replaceState(history.state, '', url);
          }
          writeStorage(storageKey, panels[index].name);
        };

        panels.forEach((panel, index) => {
          panel.element.id = `${base}-panel-${index}`;
          panel.element.setAttribute('role', 'tabpanel');
          panel.element.setAttribute('aria-labelledby', `${base}-tab-${index}`);

          const icon = panel.element.querySelector('template[data-salsa-tab-icon]');
          const iconOnly = icon instanceof HTMLTemplateElement;

          const button = document.createElement('button');
          button.type = 'button';
          button.id = `${base}-tab-${index}`;
          button.className = [
            ...BUTTON_CLASSES,
            ...(iconOnly ? ICON_BUTTON_CLASSES : TEXT_BUTTON_CLASSES),
            ...INACTIVE_CLASSES,
          ].join(' ');
          button.setAttribute('role', 'tab');
          button.setAttribute('aria-controls', `${base}-panel-${index}`);
          button.setAttribute('aria-selected', 'false');
          button.tabIndex = -1;
          if (icon instanceof HTMLTemplateElement) {
            // The icon is rendered by Twig, so the theme icon pack is used. The
            // label only lives in aria-label and title, so the button stays
            // understandable without visible text.
            button.append(icon.content.cloneNode(true));
            button.setAttribute('aria-label', panel.label);
            button.title = panel.label;
          } else {
            button.append(document.createTextNode(panel.label));
          }
          button.addEventListener('click', () => select(index, true));
          button.addEventListener('keydown', (event) => {
            let target = -1;
            if (event.key === 'ArrowLeft') {
              target = (index - 1 + panels.length) % panels.length;
            } else if (event.key === 'ArrowRight') {
              target = (index + 1) % panels.length;
            } else if (event.key === 'Home') {
              target = 0;
            } else if (event.key === 'End') {
              target = panels.length - 1;
            }
            if (target === -1) {
              return;
            }
            event.preventDefault();
            select(target, true);
            buttons[target].focus();
          });

          list.append(button);
          buttons.push(button);
        });

        // Without JavaScript the panels stay visible and the list stays hidden.
        list.hidden = false;
        select(initialIndex(panels, param), false);
      });
    },
  };
})(Drupal, once);

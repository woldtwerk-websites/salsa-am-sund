/**
 * @file
 * Event calendar behaviour.
 *
 * Filters the cards rendered with the block and drives the FullCalendar web
 * component. Both views share the filter state from the page URL or, when the
 * URL has no filter, from localStorage. The calendar loads its occurrences from
 * the JSON endpoint and refetches when a filter changes. Without JavaScript the
 * cards stay visible and the <noscript> fallback links to the iCal feed.
 */

/**
 * The filter state of the calendar.
 *
 * @typedef {object} SalsaCalendarFilterState
 * @property {string[]} organizers
 *   The selected organizer term IDs.
 * @property {boolean} parties
 *   Whether events are shown.
 * @property {boolean} courses
 *   Whether courses are shown.
 */

/**
 * The range FullCalendar asks the event source for.
 *
 * @typedef {object} SalsaCalendarRange
 * @property {string} startStr
 *   The first day of the range, as ISO 8601.
 * @property {string} endStr
 *   The day after the range, as ISO 8601.
 */

/**
 * The filter state event of the calendar.
 *
 * The behaviour dispatches it as `salsa-calendar:filters` on the calendar
 * wrapper whenever the filter state changes, with the state as detail, so the
 * subscription dialog can mirror the selection. The state is also kept on the
 * wrapper as `salsaCalendarState`, because the dialog and the calendar may
 * attach in either order.
 */

((Drupal, once) => {
  'use strict';

  /**
   * The design tokens of the event colours.
   *
   * Parties are flame, courses are gold, and both use night for their text.
   */
  const COLOR_TOKENS = {
    event: '--color-flame-orange',
    course: '--color-flame-gold',
    contrast: '--color-night',
  };

  /**
   * Reads the event colours from the design tokens.
   *
   * The calendar renders in a shadow tree that page styles cannot reach, and
   * FullCalendar 7's classic theme colours every event with the same variable
   * unless the event carries its own colour. The colours therefore travel with
   * the event data; they are read from the same tokens as the cards so the two
   * views cannot drift apart.
   *
   * @return {{event: string, course: string, contrast: string}}
   *   The colours of events, courses and their text.
   */
  const readColors = () => {
    const styles = getComputedStyle(document.documentElement);
    /**
     * @param {string} name
     *   The custom property name.
     *
     * @return {string}
     *   The colour, or an empty string when the token is not defined.
     */
    const token = (name) => styles.getPropertyValue(name).trim();
    return {
      event: token(COLOR_TOKENS.event),
      course: token(COLOR_TOKENS.course),
      contrast: token(COLOR_TOKENS.contrast),
    };
  };

  /**
   * The number of cards shown at once.
   */
  const PAGE_SIZE = 9;

  /**
   * The storage key of the filter state.
   */
  const FILTERS_KEY = 'salsa:calendar-filters';

  /**
   * Returns the default filter state: every organizer, parties and courses.
   *
   * @param {string[]} allOrganizers
   *   The IDs of every organizer.
   *
   * @return {SalsaCalendarFilterState}
   *   The filter state.
   */
  const defaultState = (allOrganizers) => ({
    organizers: [...allOrganizers],
    parties: true,
    courses: true,
  });

  /**
   * Drops organizer IDs that no longer exist.
   *
   * @param {string[]} ids
   *   The organizer IDs.
   * @param {string[]} allOrganizers
   *   The IDs of every organizer.
   *
   * @return {string[]}
   *   The known organizer IDs, without duplicates.
   */
  const allowedOrganizers = (ids, allOrganizers) => (
    [...new Set(ids)].filter((id) => allOrganizers.includes(id))
  );

  /**
   * Returns whether the query contains a calendar filter.
   *
   * @param {URLSearchParams} params
   *   The query arguments.
   *
   * @return {boolean}
   *   TRUE when a filter argument is set.
   */
  const hasFilter = (params) => ['organizer', 'parties', 'courses'].some((key) => params.has(key));

  /**
   * Validates a filter state read from storage.
   *
   * @param {unknown} value
   *   The parsed JSON value.
   * @param {string[]} allOrganizers
   *   The IDs of every organizer.
   *
   * @return {SalsaCalendarFilterState|null}
   *   The state, or NULL when the value is not a valid state.
   */
  const parseState = (value, allOrganizers) => {
    if (typeof value !== 'object' || value === null) {
      return null;
    }
    const candidate = /** @type {Record<string, unknown>} */ (value);
    const organizers = candidate.organizers;
    if (!Array.isArray(organizers) || !organizers.every((id) => typeof id === 'string')) {
      return null;
    }
    if (typeof candidate.parties !== 'boolean' || typeof candidate.courses !== 'boolean') {
      return null;
    }
    return {
      organizers: allowedOrganizers(organizers, allOrganizers),
      parties: candidate.parties,
      courses: candidate.courses,
    };
  };

  /**
   * Reads the filter state remembered in localStorage.
   *
   * @param {string[]} allOrganizers
   *   The IDs of every organizer.
   *
   * @return {SalsaCalendarFilterState|null}
   *   The stored state, or NULL when nothing valid is stored.
   */
  const readStoredState = (allOrganizers) => {
    let raw;
    try {
      raw = window.localStorage.getItem(FILTERS_KEY);
    }
    catch {
      // Storage can be blocked by the browser.
      return null;
    }
    if (raw === null) {
      return null;
    }
    try {
      return parseState(JSON.parse(raw), allOrganizers);
    }
    catch {
      return null;
    }
  };

  /**
   * Remembers the filter state in localStorage.
   *
   * @param {SalsaCalendarFilterState} state
   *   The filter state.
   */
  const writeStoredState = (state) => {
    try {
      window.localStorage.setItem(FILTERS_KEY, JSON.stringify(state));
    }
    catch {
      // Storage can be blocked by the browser.
    }
  };

  /**
   * Returns the filter state stored in the page URL or in localStorage.
   *
   * A shared link wins over the remembered selection: when the URL has one of
   * the filter arguments, it is the only source. Without an `organizer`
   * argument every organizer is selected.
   *
   * @param {string[]} allOrganizers
   *   The IDs of every organizer.
   *
   * @return {SalsaCalendarFilterState}
   *   The filter state.
   */
  const readState = (allOrganizers) => {
    const params = new URLSearchParams(window.location.search);
    if (!hasFilter(params)) {
      return readStoredState(allOrganizers) ?? defaultState(allOrganizers);
    }
    /**
     * @param {string} key
     *   The query argument name.
     *
     * @return {string[]}
     *   The comma separated values.
     */
    const list = (key) => (params.get(key) || '').split(',').filter(Boolean);
    return {
      organizers: params.has('organizer')
        ? allowedOrganizers(list('organizer'), allOrganizers)
        : [...allOrganizers],
      parties: params.get('parties') !== '0',
      courses: params.get('courses') !== '0',
    };
  };

  /**
   * Applies a filter state to the checkboxes.
   *
   * @param {HTMLElement} root
   *   The calendar wrapper.
   * @param {SalsaCalendarFilterState} state
   *   The filter state.
   */
  const applyState = (root, state) => {
    const organizerInputs = /** @type {NodeListOf<HTMLInputElement>} */ (
      root.querySelectorAll('input[name="organizer"]')
    );
    organizerInputs.forEach((input) => {
      input.checked = state.organizers.includes(input.value);
    });
    const parties = /** @type {HTMLInputElement | null} */ (
      root.querySelector('[data-salsa-calendar-parties]')
    );
    if (parties) {
      parties.checked = state.parties;
    }
    const courses = /** @type {HTMLInputElement | null} */ (
      root.querySelector('[data-salsa-calendar-courses]')
    );
    if (courses) {
      courses.checked = state.courses;
    }
  };

  /**
   * Returns the filter state of the checkboxes.
   *
   * @param {HTMLElement} root
   *   The calendar wrapper.
   *
   * @return {SalsaCalendarFilterState}
   *   The filter state.
   */
  const collectState = (root) => {
    /**
     * @param {string} name
     *   The checkbox name.
     *
     * @return {string[]}
     *   The values of the checked boxes.
     */
    const values = (name) => Array.from(
      /** @type {NodeListOf<HTMLInputElement>} */ (
        root.querySelectorAll(`input[name="${name}"]:checked`)
      ),
    ).map((input) => input.value);
    const parties = /** @type {HTMLInputElement | null} */ (
      root.querySelector('[data-salsa-calendar-parties]')
    );
    const courses = /** @type {HTMLInputElement | null} */ (
      root.querySelector('[data-salsa-calendar-courses]')
    );
    return {
      organizers: values('organizer'),
      parties: parties ? parties.checked : true,
      courses: courses ? courses.checked : true,
    };
  };

  /**
   * Stores the filter state in the page URL, so it can be shared.
   *
   * All organizers selected omits the argument, a real empty selection writes
   * it with an empty value, anything else is the comma separated list.
   *
   * @param {SalsaCalendarFilterState} state
   *   The filter state.
   * @param {string[]} allOrganizers
   *   The IDs of every organizer.
   */
  const writeState = (state, allOrganizers) => {
    const params = new URLSearchParams(window.location.search);
    ['organizer', 'location', 'parties', 'courses'].forEach((key) => params.delete(key));
    if (state.organizers.length === allOrganizers.length) {
      // Every organizer is selected: the default needs no argument.
    }
    else if (state.organizers.length === 0) {
      params.set('organizer', '');
    }
    else {
      params.set('organizer', state.organizers.join(','));
    }
    if (!state.parties) {
      params.set('parties', '0');
    }
    if (!state.courses) {
      params.set('courses', '0');
    }
    const query = params.toString();
    history.replaceState(
      history.state,
      '',
      `${window.location.pathname}${query ? `?${query}` : ''}${window.location.hash}`,
    );
    writeStoredState(state);
  };

  /**
   * Builds the request parameters for the JSON endpoint.
   *
   * Events need both the parties checkbox and at least one selected organizer.
   * With courses checked the request adds `type=course`, which returns every
   * course alongside the selected organizers' events. Courses alone ignore the
   * organizer selection, and without either kind there is nothing to load.
   *
   * @param {SalsaCalendarFilterState} state
   *   The filter state.
   *
   * @return {URLSearchParams|null}
   *   The query arguments, or NULL when nothing has to be loaded.
   */
  const buildQuery = (state) => {
    const parties = state.parties && state.organizers.length > 0;
    if (parties) {
      const params = new URLSearchParams();
      params.set('organizer', state.organizers.join(','));
      if (state.courses) {
        params.set('type', 'course');
      }
      return params;
    }
    if (state.courses) {
      return new URLSearchParams({ type: 'course' });
    }
    return null;
  };

  /**
   * Returns whether a card matches the filter state.
   *
   * @param {HTMLElement} card
   *   The card wrapper.
   * @param {SalsaCalendarFilterState} state
   *   The filter state.
   *
   * @return {boolean}
   *   TRUE when the card is part of the selection.
   */
  const cardMatches = (card, state) => {
    if (card.dataset.kind === 'course') {
      return state.courses;
    }
    if (card.dataset.kind !== 'event') {
      return true;
    }
    const organizer = card.dataset.organizer || '';
    return state.parties && state.organizers.includes(organizer);
  };

  /**
   * Shows the matching cards and updates the empty message and the more button.
   *
   * @param {HTMLElement} root
   *   The calendar wrapper.
   * @param {SalsaCalendarFilterState} state
   *   The filter state.
   * @param {number} limit
   *   The number of matching cards to show.
   */
  const applyCardFilter = (root, state, limit) => {
    const cards = /** @type {HTMLElement[]} */ (
      Array.from(root.querySelectorAll('[data-salsa-calendar-card]'))
    );
    let matches = 0;
    let shown = 0;
    cards.forEach((card) => {
      if (!cardMatches(card, state)) {
        card.hidden = true;
        return;
      }
      matches++;
      const visible = shown < limit;
      if (visible) {
        shown++;
      }
      card.hidden = !visible;
    });

    const empty = /** @type {HTMLElement | null} */ (
      root.querySelector('[data-salsa-calendar-empty]')
    );
    if (empty) {
      empty.hidden = matches > 0;
    }
    const more = /** @type {HTMLButtonElement | null} */ (
      root.querySelector('[data-salsa-calendar-more]')
    );
    if (more) {
      more.hidden = matches <= limit;
    }
  };

  Drupal.behaviors.salsaCalendar = {
    attach(context) {
      once('salsa-calendar', '[data-salsa-calendar]', context).forEach((element) => {
        const root = /** @type {HTMLElement} */ (element);
        const allOrganizers = (root.dataset.allOrganizers || '').split(',').filter(Boolean);
        let state = readState(allOrganizers);
        applyState(root, state);

        // The cards are plain markup, so they work even when FullCalendar did
        // not load.
        let limit = PAGE_SIZE;
        /** @type {SalsaFullCalendarApi|null} */
        let api = null;

        /**
         * Applies the changed filters to the cards and the calendar.
         */
        const onFilterChange = () => {
          state = collectState(root);
          writeState(state, allOrganizers);
          // The subscription dialog mirrors the selection.
          root.salsaCalendarState = state;
          root.dispatchEvent(new CustomEvent('salsa-calendar:filters', { detail: state, bubbles: false }));
          // A new selection starts at the first page again.
          limit = PAGE_SIZE;
          applyCardFilter(root, state, limit);
          if (api) {
            api.refetchEvents();
          }
        };

        const filters = root.querySelector('[data-salsa-calendar-filters]');
        if (filters) {
          filters.addEventListener('change', (event) => {
            if (!(event.target instanceof HTMLInputElement) || event.target.type !== 'checkbox') {
              return;
            }
            onFilterChange();
          });
        }

        const more = /** @type {HTMLButtonElement | null} */ (
          root.querySelector('[data-salsa-calendar-more]')
        );
        if (more) {
          more.addEventListener('click', () => {
            limit += PAGE_SIZE;
            applyCardFilter(root, state, limit);
          });
        }

        applyCardFilter(root, state, limit);

        // The cards are already filtered, so the dialog gets the state even
        // when FullCalendar did not load.
        root.salsaCalendarState = state;
        root.dispatchEvent(new CustomEvent('salsa-calendar:filters', { detail: state, bubbles: false }));

        const calendar = root.querySelector('full-calendar');
        if (!calendar || !customElements.get('full-calendar')) {
          return;
        }

        const eventsUrl = root.dataset.eventsUrl || '';
        const colors = readColors();

        /**
         * Loads the occurrences of the requested range.
         *
         * @param {SalsaCalendarRange} info
         *   The range FullCalendar requests.
         *
         * @return {Promise<Record<string, unknown>[]>}
         *   The events.
         */
        const loadEvents = async (info) => {
          const url = new URL(eventsUrl, window.location.href);
          const params = buildQuery(state);
          // Neither parties nor courses are shown.
          if (!params) {
            return [];
          }
          params.set('start', info.startStr);
          params.set('end', info.endStr);
          params.forEach((value, key) => url.searchParams.set(key, value));

          const response = await fetch(url, { headers: { Accept: 'application/json' } });
          if (!response.ok) {
            throw new Error(`Calendar request failed with status ${response.status}`);
          }
          /** @type {Record<string, unknown>[]} */
          const events = await response.json();
          // FullCalendar 7 colours an event with `color` (the background and
          // the list dot) and `contrastColor` (its text).
          return events.map((event) => {
            const props = /** @type {{course?: boolean}|undefined} */ (event.extendedProps);
            return {
              ...event,
              color: props?.course === true ? colors.course : colors.event,
              contrastColor: colors.contrast,
            };
          });
        };

        const wide = window.matchMedia('(min-width: 768px)');
        calendar.options = {
          // The global build exposes the view plugins; they are not registered
          // by the custom element automatically.
          plugins: [FullCalendar.DayGrid.default, FullCalendar.List.default],
          locale: 'de',
          timeZone: 'Europe/Berlin',
          firstDay: 1,
          initialView: wide.matches ? 'dayGridMonth' : 'listMonth',
          headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,listMonth',
          },
          noEventsText: 'Keine Termine',
          eventTimeFormat: {
            hour: '2-digit',
            minute: '2-digit',
            hour12: false,
          },
          height: 'auto',
          events: loadEvents,
        };

        const calendarApi = calendar.getApi();
        if (!calendarApi) {
          return;
        }
        api = calendarApi;

        // The calendar may start in a hidden tab, where it has no size yet.
        // Refetch the size once the panel becomes visible.
        const resize = new ResizeObserver(() => {
          if (calendar.clientWidth === 0 || calendar.clientHeight === 0) {
            return;
          }
          calendarApi.updateSize();
        });
        resize.observe(calendar);

        wide.addEventListener('change', (event) => {
          calendarApi.changeView(event.matches ? 'dayGridMonth' : 'listMonth');
        });
      });
    },
  };
})(Drupal, once);

/**
 * @file
 * Event calendar behaviour.
 *
 * Sets the options of the FullCalendar web component, loads the occurrences
 * from the JSON endpoint, keeps the filters in the page URL and refetches when
 * a filter changes. Without JavaScript the calendar is not rendered; the
 * <noscript> fallback links to the iCal feed instead.
 */

/**
 * The filter state of the calendar.
 *
 * @typedef {object} SalsaCalendarFilterState
 * @property {string[]} organizers
 *   The selected organizer term IDs.
 * @property {string[]} locations
 *   The selected location term IDs.
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

((Drupal, once) => {
  'use strict';

  // The calendar renders in a shadow tree, so class based event styles have to
  // be injected there. Courses are gold, events use the flame colour from the
  // classic theme variables.
  const SHADOW_STYLES = `
    .is-course {
      --fc-event-color: var(--color-flame-gold) !important;
      --fc-event-contrast-color: var(--color-night) !important;
    }
  `;

  /**
   * Returns the filter state stored in the page URL.
   *
   * @return {SalsaCalendarFilterState}
   *   The filter state.
   */
  const readState = () => {
    const params = new URLSearchParams(window.location.search);
    /**
     * @param {string} key
     *   The query argument name.
     *
     * @return {string[]}
     *   The comma separated values.
     */
    const list = (key) => (params.get(key) || '').split(',').filter(Boolean);
    return {
      organizers: list('organizer'),
      locations: list('location'),
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
    const locationInputs = /** @type {NodeListOf<HTMLInputElement>} */ (
      root.querySelectorAll('input[name="location"]')
    );
    locationInputs.forEach((input) => {
      input.checked = state.locations.includes(input.value);
    });
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
    const courses = /** @type {HTMLInputElement | null} */ (
      root.querySelector('[data-salsa-calendar-courses]')
    );
    return {
      organizers: values('organizer'),
      locations: values('location'),
      courses: courses ? courses.checked : true,
    };
  };

  /**
   * Stores the filter state in the page URL, so it can be shared.
   *
   * @param {SalsaCalendarFilterState} state
   *   The filter state.
   */
  const writeState = (state) => {
    const params = new URLSearchParams(window.location.search);
    ['organizer', 'location', 'courses'].forEach((key) => params.delete(key));
    if (state.organizers.length) {
      params.set('organizer', state.organizers.join(','));
    }
    if (state.locations.length) {
      params.set('location', state.locations.join(','));
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
  };

  /**
   * Builds the request parameters for the JSON endpoint.
   *
   * The endpoint uses the filter semantics of the iCal feed: a course or
   * organizer selection restricts the result. Without courses the request is
   * restricted to every organizer, which returns the events only. With
   * selected organizers and courses, `type=course` adds the courses.
   *
   * @param {SalsaCalendarFilterState} state
   *   The filter state.
   * @param {string[]} allOrganizers
   *   The IDs of every organizer.
   *
   * @return {URLSearchParams}
   *   The query arguments.
   */
  const buildQuery = (state, allOrganizers) => {
    const params = new URLSearchParams();
    const organizers = !state.courses && state.organizers.length === 0
      ? allOrganizers
      : state.organizers;
    if (organizers.length) {
      params.set('organizer', organizers.join(','));
    }
    if (state.locations.length) {
      params.set('location', state.locations.join(','));
    }
    if (state.courses && state.organizers.length) {
      params.set('type', 'course');
    }
    return params;
  };

  Drupal.behaviors.salsaCalendar = {
    attach(context) {
      once('salsa-calendar', '[data-salsa-calendar]', context).forEach((element) => {
        const root = /** @type {HTMLElement} */ (element);
        const calendar = root.querySelector('full-calendar');
        if (!calendar || !customElements.get('full-calendar')) {
          return;
        }

        const eventsUrl = root.dataset.eventsUrl || '';
        const allOrganizers = (root.dataset.allOrganizers || '').split(',').filter(Boolean);
        let state = readState();
        applyState(root, state);

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
          const params = buildQuery(state, allOrganizers);
          params.set('start', info.startStr);
          params.set('end', info.endStr);
          params.forEach((value, key) => url.searchParams.set(key, value));

          const response = await fetch(url, { headers: { Accept: 'application/json' } });
          if (!response.ok) {
            throw new Error(`Calendar request failed with status ${response.status}`);
          }
          /** @type {Record<string, unknown>[]} */
          const events = await response.json();
          return events;
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

        if (calendar.shadowRoot && !calendar.shadowRoot.querySelector('style[data-salsa-calendar]')) {
          const style = document.createElement('style');
          style.setAttribute('data-salsa-calendar', '');
          style.textContent = SHADOW_STYLES;
          calendar.shadowRoot.appendChild(style);
        }

        const api = calendar.getApi();
        if (!api) {
          return;
        }
        wide.addEventListener('change', (event) => {
          api.changeView(event.matches ? 'dayGridMonth' : 'listMonth');
        });

        const filters = root.querySelector('[data-salsa-calendar-filters]');
        if (filters) {
          filters.addEventListener('change', (event) => {
            if (!(event.target instanceof HTMLInputElement) || event.target.type !== 'checkbox') {
              return;
            }
            state = collectState(root);
            writeState(state);
            api.refetchEvents();
          });
        }
      });
    },
  };
})(Drupal, once);

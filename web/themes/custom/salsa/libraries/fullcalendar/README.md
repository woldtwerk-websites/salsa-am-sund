# FullCalendar web component

Generated build of [FullCalendar](https://fullcalendar.io/docs/web-component) 7.1.3, MIT
licensed (see `LICENSE.md`). Nothing is loaded from a third-party CDN.

**These files are generated, not committed.** The theme's `postinstall` script
(`scripts/build-fullcalendar.js`) writes them from the
`@fullcalendar/web-component` devDependency, so a fresh checkout only needs
`ddev exec pnpm install` (or a `pnpm install` anywhere in the workspace).
`.gitignore` keeps the bundles and `LICENSE.md` out of Git; this README stays
tracked.

The script resolves the package from the theme, minifies the JavaScript with
esbuild, keeps the license banners inline, and copies the classic palette
unchanged:

| Output                        | Source                           |
| ----------------------------- | -------------------------------- |
| `skeleton.styles.min.js`      | `skeleton.styles.js`             |
| `theme-classic.styles.min.js` | `themes/classic/theme.styles.js` |
| `fullcalendar.global.min.js`  | `all/global.js`                  |
| `theme-classic.global.min.js` | `themes/classic/global.js`       |
| `locale-de.global.min.js`     | `locales/de/global.js`           |
| `theme-classic.palette.css`   | `themes/classic/palette.css`     |
| `LICENSE.md`                  | `LICENSE.md`                     |

## Load order

The order in `salsa.libraries.yml` is significant:

1. `skeleton.styles.min.js` and `theme-classic.styles.min.js` register
   `globalThis.__applyFullCalendarStyles`. They must run before the global build
   defines `<full-calendar>`, because elements already in the DOM upgrade
   immediately and throw without the shadow-DOM styles.
2. `fullcalendar.global.min.js` registers the `<full-calendar>` custom element.
3. `theme-classic.global.min.js` and `locale-de.global.min.js` extend
   `FullCalendar.Shared` with the theme plugin and the German locale.

The palette is loaded as theme CSS. The calendar's own overrides in
`components/calendar/calendar.css` target `[data-salsa-calendar] full-calendar`,
which is more specific than the palette's `:root`, so the theme colours win.

The global build exposes the view plugins but does not register them; the
behaviour passes `FullCalendar.DayGrid.default` and `FullCalendar.List.default`
through `options.plugins`. The German locale provides the button labels
(`todayText`, `monthText`, `listText`); FullCalendar 7 no longer has
`buttonText`.

Rebuild on demand with:

```sh
ddev exec pnpm --dir web/themes/custom/salsa run build:fullcalendar
```

To update the version, bump `@fullcalendar/web-component` in
`web/themes/custom/salsa/package.json` and the `version` of the `fullcalendar`
library in `salsa.libraries.yml`, then reinstall so the postinstall rebuilds the
bundles:

```sh
ddev exec pnpm --dir web/themes/custom/salsa add -D @fullcalendar/web-component@latest
ddev exec pnpm install
ddev drush cr
```

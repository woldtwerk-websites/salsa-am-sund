# Salsa am Sund — Drupal theme

Custom Drupal 11 theme for the salsa dance course in Stralsund. Design direction:
*Evening at the Sund* — night-black surfaces, a red → orange → gold flame
gradient, Archivo display type and Instrument Sans body type.

The theme is a Canvas-ready SDC component library. Every component in
`components/` is eligible for the Drupal Canvas page builder: it has a full
JSON Schema, titles on every prop and slot, examples for required props and the
group **Salsa am Sund**.

## Requirements

- Drupal 11.3+ / Drupal CMS, Drupal Canvas 1.x
- Contrib modules `cva` (Twig `html_cva()`) and `tailwind_merge_classes`
  (`tw_merge()`), both declared in `salsa.info.yml`
- DDEV for the commands below (`ddev` is the project's command runner)

## CSS build

The theme ships Tailwind **source**, not a prebuilt stylesheet. The custom
module `workspace_tailwindcss_cli` decorates Drupal's `asset.css.optimizer` and
compiles any CSS file containing `@import "tailwindcss"`, `@reference` or
`@tailwindcss-placeholder` with the Tailwind CLI during CSS aggregation.

CSS aggregation must therefore stay enabled:

```bash
ddev drush pm:enable workspace_tailwindcss_cli
ddev drush cset system.performance css.preprocess 1 -y
ddev exec pnpm install
ddev drush cr
```

With aggregation disabled, Drupal would serve the raw Tailwind source and the
`@import "tailwindcss"` directives would not be compiled. The theme's
`tailwindcss` and fontsource packages are devDependencies; the CLI itself comes
from `web/modules/custom/workspace_tailwindcss_cli`.

The source is `src/css/main.css`; design tokens live in the `@theme` block and are
available as Tailwind utilities (`bg-night`, `text-sand`, `text-flame-gold`,
`border-line`, `bg-sea`, …). Two custom utilities carry the brand:

- `bg-flame` — the 115° red → orange → gold gradient
- `text-flame` — the same idea clipped to text (brighter start for contrast on
  the night background)

Fonts are self-hosted (no Google Fonts CDN, GDPR). The WOFF2 files live in
`fonts/`, the `@font-face` rules in `src/css/fonts.css`.

Rich text uses a CSS-only port of Tailwind Typography (`src/css/prose.css`, imported
from `src/css/main.css`): `prose` (light backgrounds), `prose-invert` (dark
backgrounds, the site default) and `prose-on-flame` (flame gradients), plus
`prose-sm`, `prose-lg` and `prose-xl`. The `text` component exposes a `tone`
prop (`auto`/`light`/`dark`) so the same component works on both section tones.

## Components

| Component | Purpose |
| --- | --- |
| `page-main` | Main landmark for Canvas page variants (`#main-content`, skip-link target) |
| `section` | Coloured full-width section with width, spacing, optional wave line and anchor |
| `heading` | Display/title/kicker heading with optional flame gradient |
| `text` | Rich text with on-brand prose styles and light/dark `tone` |
| `image` | Single image with caption, ratio (original/16:9/4:3/square) and rounded corners |
| `button` | Flame / outline / ghost / light link button with optional icon |
| `hero` | Flame hero with drifting ribbons, H1, actions slot and optional skyline band |
| `grid` | Responsive 2/3/4-column grid for cards and other components |
| `card` | Image card; whole card clickable when a link is set |
| `course` | One class in the timetable (day, time, level badge, location) |
| `schedule` | Timetable wrapper with heading, intro and a 2-column course grid |
| `event` | Event with large German date, time, location and description |
| `notice` | Highlighted callout with icon and flame/sea/sand tone |
| `quote` | Large italic quote with a flame quotation mark |
| `link-list` / `link-item` | Link list and its entries (external-link icon) |
| `contact` | Address, phone (`tel:`), e-mail (`mailto:`) and route link |
| `gallery` / `gallery-image` | Gallery with a native popover lightbox; square thumbnails work without JS |
| `calendar-subscribe` | Calendar subscription modal dialog (organizers/courses) used by the "Nächste Termine" block; `noUi`, so it stays out of the Canvas library |
| `profile` | User profile card with avatar/initials, name, "Mitglied seit" and remaining fields |
| `site-header` | Sticky, blurred header with wordmark, navigation slot, mobile toggle and actions |
| `site-footer` | Footer with flame hairline, content and navigation slots and copyright |

The theme ships almost no custom JavaScript: the lightbox navigation in
`components/gallery-image/gallery-image.js` (previous/next buttons, arrow keys
and touch swipes) and the calendar subscription in
`components/calendar-subscribe/calendar-subscribe.js` (rebuilds the feed link
from the modal selection and falls back to `showModal()`/`close()` without
invoker commands). Both are progressive enhancement: without JS the lightbox
works exactly as before and the calendar link keeps its server-rendered
default.
Mobile navigation, the gallery lightbox and the main menu submenus are native
popovers (`popover`/`popovertarget`). In the main menu the chevron button opens
the level-2 list, which is anchored to its menu row with CSS anchor positioning
(`anchor-name`/`position-area`, with `flip-inline` near the viewport edge) and
closes on light dismiss or Escape; below `lg` the toggle is hidden and the same
list stays expanded inline under its parent. The `scroll-lock` utility locks
page scroll while its popover or modal dialog is open (`:popover-open`/`:modal`);
use a breakpoint variant such as `max-lg:scroll-lock` to limit it to smaller
viewports. Open/close transitions use `@starting-style` and are disabled under
`prefers-reduced-motion`.

## `ui` override convention

Every component accepts an **undeclared** `ui` variable for Twig embedding. It
is intentionally not part of the component schema (declaring it would make the
component ineligible for Canvas). `ui` is an object of class strings per
element, for example:

```twig
{{ include('salsa:card', {
  title: 'Tanzabend',
  href: '/tanzabende',
  ui: {
    root: 'shadow-2xl',
    title: 'text-flame-gold',
    footer: 'text-xs',
  },
}) }}
```

Inside the component, the classes are built with `html_cva(...)` and merged
with `tw_merge()` so caller overrides win over defaults:

```twig
class="{{ tw_merge([card.apply({tone: tone}), ui.root|default('')]) }}"
```

`tw_merge()` takes one class list (string or array); pass an array to merge the
component defaults with the `ui` override.

## Templates

`templates/` contains the non-Canvas fallback layout and Drupal integrations:

- `layout/page.html.twig` — header/content/footer fallback when no Canvas page
  template exists
- `navigation/menu--main.html.twig` — desktop popover submenus anchored to their
  menu row via CSS anchor positioning, expanded inline below `lg`
- `navigation/menu--footer.html.twig` — inline footer list
- `block/block--system-menu-block.html.twig` — no block chrome, keeps
  `<nav aria-label>`
- `misc/status-messages.html.twig` — on-brand messages
- `misc/page-title.html.twig` — on-brand page title for non-Canvas routes
- `content/comment.html.twig`, `field/field--comment.html.twig` — comment list,
  author, formatted date and the “Eintrag schreiben” form heading
- `content/user.html.twig`, `content/user--compact.html.twig` — profile card and
  compact avatar/name used in comments

All preprocess logic is in OOP hooks (`src/Hook/SalsaHooks.php`); there is no
`.theme` file. `preprocess_canvas_page_variant` wraps route content (contact,
profiles, 403/404) in the shared `.salsa-route-content` container while Canvas
pages and Canvas content templates stay full-bleed. The page-title hook hides the title on
`entity.canvas_page.canonical` routes because Canvas pages render their own
`<h1>` in the hero, and adds the `route` cache context.

## Flame contrast

`bg-flame` is the decorative full red → orange → gold gradient. Surfaces that
carry text use `bg-flame-deep` (hero, flame section, flame notice, flame button,
flame card) with **white** text: white has 9.34:1 / 6.96:1 / 4.71:1 contrast on
`#8f0f16`, `#b3121a` and `#e0261b`. Gold stays as an accent (rules, hairlines,
focus ring), never behind small text.

## Icons

`salsa.icons.yml` registers the `salsa` icon pack using core's `svg` extractor
over `icons/{icon_id}.svg`. Icons come from [Lucide](https://lucide.dev) and are
licensed under the **ISC** license — see `icons/LICENSE.txt`.

```twig
{{ icon('salsa', 'menu', {size: 24, class: 'shrink-0'}) }}
```

Available icons: `menu`, `x`, `arrow-right`, `phone`, `mail`, `map-pin`,
`clock`, `calendar`, `external-link`, `chevron-down`, `chevron-left`,
`chevron-right`, `music`, `users`.

## Licenses

- Fonts: **Archivo** and **Instrument Sans**, both **SIL OFL 1.1**. Full text
  and copyright notes in `fonts/OFL.txt`.
- Icons: **Lucide**, **ISC**. Full text in `icons/LICENSE.txt`.
- Prose CSS: ported from **@tailwindcss/typography v0.5.16**, **MIT**
  (© Tailwind Labs). Full text in `src/css/prose.LICENSE.txt`.
- FullCalendar web component: **MIT** (© Adam Shaw). Self-hosted build and
  license text in `libraries/fullcalendar/`.
- Theme code: GPL-2.0-or-later, like Drupal.

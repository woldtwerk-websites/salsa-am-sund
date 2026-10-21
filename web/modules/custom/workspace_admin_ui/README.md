# Admin UI

## Functionality
- add the *Masquerade* tab with its two shortcuts, *User* and *Editor*, to
  the toolbar
- add the *Unmasquerade* link to the toolbar user menu
  (`#toolbar-item-user-tray`), next to *View profile*, *Edit profile* and
  *Log out*
- keep the toolbar for an admin masquerading as an account without
  `access toolbar`
- drop the status message masquerade shows after a switch
- move *Taxonomy*, *Menus* (both from *Structure*) and *URL aliases* (from
  *Configuration*) to gin's Content block, after *Media*

## The masquerade shortcuts
`hook_toolbar()` adds a *Masquerade* tab whose tray holds a *User* and an
*Editor* link into `entity.user.masquerade`. One tab rather than two: names
sitting in the toolbar with nothing above them say nothing about what clicking
them does. The tab itself goes to `/masquerade`, masquerade's own form, which
is both a sensible target and the fallback where the JavaScript that opens the
tray has not run.

The accounts are found by user name — `user` and `editor`, the constants in
`AdminUiHooks` — because a uid is a fact about one database and the name is
the same everywhere the accounts were seeded.

The links carry no `destination`: `SwitchController` then sends you back to
the referrer if the account you stepped into may see it, and to `<front>`
otherwise.

Each link asks `masquerade_target_user_access()` and is left out when the
answer is no — the account does not exist, or the current user may not
masquerade as it, or a masquerade is already running, which takes every link
and with them the whole tab away and leaves *Unmasquerade* as the only move.
The item carries `user.permissions`, `session.is_masquerading` and the
accounts' cache tags — `user_list` for a name no account holds, so the link
appears once one does.

Gin's secondary toolbar renders every toolbar tab except the main menu one, so
the tab needs nothing beyond `#type: toolbar_item` to land there. The CSRF
token the route requires is added by core's outbound route processor.

The icon is `icons/masquerade.svg`, attached as `workspace_admin_ui/toolbar` and picked
up through `--icon` on `.toolbar-icon-masquerade::before`, the custom property
gin masks the pseudo-element with — so the shape is all the file carries and
the colour follows the theme into dark mode.

## The Unmasquerade link
`ToolbarLinkBuilder` decorates core's `user.toolbar_link_builder` and inserts
the link above *Log out* while masquerading; the tray varies by
`session.is_masquerading`. `hook_toolbar_alter()` drops masquerade's own
standalone toolbar tab so the action appears once, in the user menu.

## The toolbar while masquerading
`MasqueradeToolbarAccessPolicy` is an access policy that grants `access
toolbar` to the masqueraded account, so the toolbar — and *Unmasquerade* in
it — stays when an admin steps into `user`, which holds no role. Core, gin
and gin_toolbar check the permission directly, so granting it is the only
place that reaches all three.

The account gets it only for as long as the masquerade runs, and no other
account does: permissions for other accounts are calculated in the same
request too, so the policy compares the account against the session's `uid`.
It adds `session.is_masquerading` as a persistent cache context, and `user`
only while masquerading. Without `user`, the result calculated for one account
would be served to another account with the same roles. The context is only
added for requests with masquerade's own session bag: Drush and other CLI
requests carry Symfony's metadata bag, where the context throws.

## The switch message
`MasqueradeMessageSubscriber` takes masquerade's "You are now masquerading
as @user" off the session at `kernel.response`, on `entity.user.masquerade`
and `masquerade.unmasquerade`. Only the status type goes — the same controller
reports a refused switch as an error. Nothing else runs on those two routes,
so there is no other status message to take with it.

## Taxonomy, URL aliases and menus under Content
Gin's sidebar does not render the Content block from the admin menu. It is a
fixed list in `GinNavigation` (Content, Blocks, Files, Media), and gin drops
`system.admin_content` with its whole subtree from the admin block. So:

- `hook_menu_links_discovered_alter()` moves the links listed in
  `AdminUiHooks::contentLinks()` under `system.admin_content`, which takes
  them out of Structure and Configuration.
- `AdminUiHooks::preprocessNavigation()` adds them to gin's list. Gin registers
  `navigation` with its own `preprocess functions`, and that shuts out module
  preprocess hooks, so `hook_theme_registry_alter()` appends the callback to
  that list. It is static because the registry is cached and holds callables
  by name.

To move another link, add it to `contentLinks()` and give it an icon in
`css/toolbar.css`. Only the links move. The pages keep their paths and access
checks. Gin ships no icons for these, so they come from `icons/` through the
`workspace_admin_ui/toolbar` library.

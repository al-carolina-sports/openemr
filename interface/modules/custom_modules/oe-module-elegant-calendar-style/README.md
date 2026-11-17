# Annie's Elegant Calendar Style

OpenEMR custom module (no core files edited). On the calendar page (`pnuserapi.php`) it adds
`public/assets/elegant-calendar.css`, which sets Day/Week appointment text
(`.event`, `.event_appointment`, `.event_in`, `.event_highlight.event_time`,
`.event_details`) to 0.8rem, matching the time labels. Nothing else changes and
no core file is edited.

## Change the size

Edit `--aecs-event-font-size` at the top of `public/assets/elegant-calendar.css`,
then bump `Config::VERSION` in `src/Config.php` (cache-busting).

## Install

Copy this folder to `interface/modules/custom_modules/`, then Admin > Modules >
Manage Modules: Register, Install, Enable. `table.sql` creates the Custom Views
tables and (v1.3.1) `oe_module_ecs_settings`; unregistering removes them.

## Custom Views (v1.1.0)

A **Custom Views** picker above the calendar's provider list. Choosing a view
selects its calendar users in the provider list and reloads the calendar once.
The **+** button adds a view, the pencil edits the selected view and **?**
deletes it (after a confirmation). Views marked *shared* are visible to everyone;
other views are private to their owner (shown with `*`). Only administrators
(admin/super) can create or change shared views. The last chosen view is
remembered per user (`user_settings`, label `oe_module_ecs_last_view`).

Tables: `oe_module_ecs_views`, `oe_module_ecs_view_members` (created by
`table.sql` on install, seeded with shared views Providers, Aestheticians and
All Users; dropped on unregister, see `cleanup.sql`).

Endpoint: `public/views.php` (`list`, `create`, `update`, `delete`,
`remember`), CSRF token in the `X-CSRF-Token` header.

## Week layout: Stack | Stretch (v1.2.0)

In the Week view with two or more providers selected, a **Week layout:
Stack | Stretch** toggle appears above the grid. *Stack* is core's layout (one
week grid per provider). *Stretch* shows one grid: a shared time column, then
for each day one sub-column per provider. Core's own day cells are moved into
the new grid (not copied), so opening appointments, click-to-create, colors and
the hover card keep working. Sub-columns have a minimum width
(`--ecs-stretch-col-min`, default 140px, in `public/assets/week-stretch.css`)
and the grid scrolls horizontally; the time column and headers are sticky.
The choice is remembered per user in the browser (localStorage).

### v1.2.1

- Stretch: the day header row and provider-name row both stay pinned while
  scrolling (the grid is its own scroll container, fitted to the window; the
  provider row's offset is measured; headers are opaque). The time column stays
  pinned when scrolling sideways.
- Hovering (or focusing, then Down arrow) the calendar's **Week** button shows a
  small **Stack / Stretch** menu with the current layout checked. Choosing one
  saves it and opens the week view in that layout. Clicking **Week** itself
  still just opens the week view. The in-grid toggle remains as an indicator.

### v1.2.2

- Fix: in Stretch the appointment hover card did not open. 1.2.1 reset the
  moved events' z-index, so core's click-to-create marker (`a.apptMarker`)
  covered the appointment under the pointer. Core's event stacking is kept now.

## Hide weekends (v1.3.0)

A **Hide weekends** toggle sits in the bar above the calendar grid, next to
**Week layout: Stack | Stretch** (with a single provider selected it is shown
on its own). It is **on by default**: Saturday and Sunday columns and their
headers are hidden and Monday to Friday fill the width, in both Stack and
Stretch. Turn it off to see all seven days. The choice is remembered per user
in the browser (localStorage, key `oeEcsHideWeekends:<username>`), like the
week layout. The same toggle is also in the **Week** button's rollover menu,
below Stack / Stretch.

- Weekend columns are found from each cell's own date (the week cells' `date`
  attribute, the month day link's `Date=`), not the column position, so the
  configured first day of the week (Globals > Calendar) does not matter.
- Only display changes: the cells are hidden, not removed, so prev/next week,
  Today, the day links, the mini calendar and the appointments themselves are
  unchanged. If a hidden weekend day has appointments, a small count badge on
  the toggle says how many.
- Month view: the same preference hides the Saturday/Sunday columns and the
  toggle appears above the month grid too. Day view is unchanged.

### v1.3.1: Hide Saturday and Sunday separately

Hide weekends is split into two toggles, so a clinic open on Saturdays can hide
only Sunday.

- **Bar above the grid** (week and month views): **Hide: Sat | Sun**, two
  toggle buttons in the place of the old single button. Each has its own count
  badge for hidden appointments on that day. A small **↺** appears next to them
  when you have your own choice; it returns both days to the site default.
  The button tooltip says whether the current value is the site default or
  your choice.
- **Week button rollover menu**: below Stack / Stretch, **Hide Saturday**,
  **Hide Sunday** and **Weekends: use site default**.
- **Site defaults** (admin): **Admin > Elegant Calendar Style**
  (`public/settings.php`, admin/super only) has *Hide Saturday* and *Hide
  Sunday*, both on out of the box. Stored in the module's own table
  `oe_module_ecs_settings` (`hide_saturday`, `hide_sunday` = `1`/`0`).
- **Per-user choice**: saved on the server per user in `user_settings`
  (labels `oe_module_ecs_hide_sat` / `oe_module_ecs_hide_sun`), like the
  remembered Custom View, so it follows the user to any browser. A user's
  choice overrides the site default; with no choice the site default applies.
  Endpoint: `views.php?action=weekends` (POST `{day, hide: true|false|null}`).
- **Migration**: the v1.3.0 browser setting (`oeEcsHideWeekends:<username>`)
  is copied once to both days as that user's choice, then removed.
- Cells and headers are marked `.ecs-sat` / `.ecs-sun` (still also
  `.ecs-weekend`); `<html>` gets `ecs-hide-sat` / `ecs-hide-sun`.
- Upgrade from 1.3.0: run the `oe_module_ecs_settings` block of `table.sql`
  (or just save the settings page once, which creates the table). Until then
  both days default to hidden, as before.

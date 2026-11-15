# Annie's Elegant Themes (oe-module-elegant-themes) v1.4.0

Eight per-user visual themes for OpenEMR 8.x, delivered entirely as a custom module.
**No core file is edited.**

| key | name | base | menu | feel |
|---|---|---|---|---|
| radiance-atelier | Radiance Atelier | light | top | ivory / plum / champagne gold (True Radiance spa) |
| storyboard-slate | Storyboard Slate | light + compact tabs | left | very compact navy, left icon rail, pinned patient banner |
| inbox-ledger | Inbox Ledger | light + compact tabs | top | very compact teal, ledger tables |
| solar-noir-hc | Solar Noir HC | solar + compact tabs | top | high-contrast dark Solar refresh, amber focus |
| paper-one-screen | Paper One-Screen | light + compact tabs | top | clean paper, flat cards |
| slate-night | Slate Night | dark + compact tabs | top | ported from Compact Themes: dark, dense, teal |
| paper-compact | Paper Compact | light + compact tabs | top | ported from Compact Themes: hairline, table-like cards |
| graphite-rail | Graphite Rail | light + compact tabs | left | ported from Compact Themes: 72px icon rail, indigo |

## How it works
* `openemr.bootstrap.php` -> `src/Bootstrap.php` resolves the logged-in user's theme
  (table `oe_module_elegant_themes_prefs`; user_id 0 = site default; '' = off).
* For this request only it points the runtime `css_header` / `compact_header`
  (and for compact themes `theme_tabs_layout`) at a stock base theme, so the
  module CSS refines a matching light/dark base. Saved globals are untouched.
* `StyleFilterEvent` adds `public/assets/elegant-base.css` + `themes/<key>.css`
  to every page rendered with `Header::setupHeader()` (main tabs, calendar,
  dashboard, encounter, lists...).
* `ScriptFilterEvent` adds `elegant-themes.js`, which pairs an icon from
  `public/assets/icons/` with each top-level main-menu label.
* `MenuEvent` adds **Miscellaneous > Elegant Themes** (all users) and
  **Admin > Elegant Themes** (admin/super; also sets the site default).

## Switching
Miscellaneous > Elegant Themes -> pick a card -> Save and apply (OpenEMR reloads).
Default is *Site default*, and the site default is *Off* (stock OpenEMR theme).

## Install / remove
Admin > Modules > Manage Modules > Unregistered: Register, Install, Enable.
Disable turns all theming off instantly. Unregister drops the table (see cleanup.sql).

Compatible with Elegant Calendar Style and Appointment Hover Card: calendar
rules here only change colours; layout stays with Elegant Calendar Style.

## Changelog
### 1.5.0 (2026-11-01)
* New theme **Espresso** (modelled on Frappe/ERPNext Espresso UI): white workspace, light-grey
  hairlines, soft rounded cards, thin light icon rail (icon + label) with collapsible tree flyouts
  and chevrons, "Dashboard / <tab>" breadcrumb in the top bar, black primary buttons, grey pill
  secondary/filter buttons, green/blue pill status tags, compact grey-header tables.
* New theme **shadcn** (modelled on ui.shadcn.com examples/dashboard) and its dark half
  **shadcn (dark)**: zinc greys, black (light) / white (dark) primary buttons, thin borders,
  rounded cards, Inter font stack, status pills; a light/dark toggle in the top bar saves the
  user's choice (ajax action `mode`, CSRF-checked, own preference only).
* Both use the shared `themes/_modern-rail.css` (same flyouts, pin-on-click, accordion
  submenus, top patient search + recent patients, contact card, + APPT / + OFFICE footer).
  Flyout hover/open states use dark text on light grey (or light on zinc-800 in dark), >= 4.5:1.
* New `public/assets/aet-modern.js` (breadcrumb + toggle; inert for other themes).
* Version bumped to 1.5.0.

### 1.4.0 (2026-10-31)
- Slim persistent footer bar on all themes with **+ APPT** (blue) and **+ OFFICE** (amber).
  Both open the native `interface/main/calendar/add_edit_event.php` dialog using native GET params only.
- + APPT: Patient tab; provider = logged-in user if a provider, else the calendar's single selected
  provider; date = today; time = next free slot today for that provider (calendar interval, within
  schedule hours, non-recurring events), else now rounded up; patient = current patient if selected.
- + OFFICE: `prov=true` (Provider tab), category Reserved (11, fallback In Office 2); provider = the
  calendar's selected provider, else the logged-in user (changeable in the native dropdown).
- Open calendar tabs refresh after the dialog closes. Buttons are hidden without patients/appt write.
- New AJAX action `apptctx` (CSRF, ACL patients/appt) in `public/ajax.php`; `src/Appointments.php`.

### 1.3.0 (2026-10-31)
* Contact card (all themes): an icon after DOB/age in the patient banner. Hover (tap on touch)
  shows home/cell/work phone (tel:), email (mailto:), address, HIPAA contact preferences and
  emergency contact, fetched live from `public/ajax.php` (POST, CSRF, ACL patients/demo read;
  icon hidden without access). Clicking the icon opens the Demographics tab with the Contact
  section shown.
* Recent patients (all themes): a dropdown button between the search box and the magnifier lists
  your last 8 distinct patients (newest first; name, DOB, ID); click opens the chart. Recorded on
  every patient selection (search, calendar, finder...) in new table `oe_module_aet_recent`
  (pruned to 8 per user). Upgrade SQL: `table.sql` / `sql/1_2_4-to-1_3_0_upgrade.sql`.

### 1.2.4 (2026-10-31)
* Fix: in left-rail flyouts, hovering / opening a submenu group header used the rail icon colours
  (light text on a light grey) and was unreadable. Flyout items, group headers and nested items now
  have explicit hover / focus / open colours meeting WCAG AA (dark text on a light tint).

### 1.2.3 (2026-10-31)
* Left icon rail: click an icon to pin its flyout open (icon highlighted); it stays open until
  you click the same icon again, click another icon (switches), click outside, or press Esc.
  Hover never closes or switches a pinned flyout.
* Hover (unpinned): ~100 ms open delay to ignore pass-overs, ~400 ms close delay, and the
  flyout sits flush against the rail so moving from icon to flyout never loses it. While
  the pointer is still moving across other icons (diagonal path) the open flyout is kept; it
  only switches once the pointer rests ~250 ms on another icon.
* Patient search moved out of the narrow rail into a persistent top bar above the patient
  banner and tabs (~420px; same OpenEMR search element, so behaviour is unchanged).
* Choosing a menu item closes the flyout. Accordion submenus, Misc label, viewport cap kept.

### 1.2.2 (2026-10-31)
* Left icon rail: nested submenus in a flyout start collapsed; click a group header (caret)
  to open it downward inline, click again to close; one group open at a time. Flyout keeps
  its viewport-height cap, scrolling and upward repositioning.
* Rail label "Miscellaneous" shown as "Misc" (theme JS only; full name kept as tooltip).
* Version bumped to 1.2.2.

### 1.2.1 (2026-10-31)
* Fix (left icon rail themes: Storyboard Slate, Graphite Rail): flyout submenus with many
  items ran off the bottom of the screen and nested submenus were clipped, so items were
  unreachable. Flyouts are now capped to the viewport height and scroll, are moved up when
  opened near the bottom of the screen, and nested submenus (e.g. Admin > System,
  Reports > Clinic) are shown expanded inline as groups inside the scrollable flyout. Theme CSS + elegant-themes.js only; no OpenEMR core changes.
* Version bumped to 1.2.1 (version.php, info.txt, composer.json, Themes::VERSION, modules table).

### 1.2.0 (2026-10-28)
* Picker cards now show a fuller description (look, density, colours, who it suits)
  and a clear **Menu: top bar / left icon rail** badge (left rail: Storyboard Slate,
  Graphite Rail; all others: top bar).
* Each card shows two click-to-enlarge screenshots bundled in
  `public/assets/screens/`: the calendar week view and the appointment patient
  hover card (Appointment Hover Card module), taken on the dev server with
  fictional demo patients and AI portraits of people who do not exist.
* Version bumped to 1.2.0 (version.php, info.txt, Themes::VERSION, modules table).

### 1.1.0
* Added Slate Night, Paper Compact and Graphite Rail (ported from Compact Themes).

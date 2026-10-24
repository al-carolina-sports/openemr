# Compact Theme (oe-module-compact-theme)

A compact, modern look for OpenEMR 8, packaged as a custom module so it never
edits core files and survives OpenEMR upgrades. Disable the module and the
stock look is back on the next page load.

## What it does

- **Three themes**, layered on top of OpenEMR's General Theme:
  - **Slate Night**: dark and dense. Use with *General Theme: Dark*.
  - **Paper Compact**: light, hairline borders, table-like cards. Use with *General Theme: Light*.
  - **Graphite Rail**: light workspace; on screens 1200px and wider the top-level
    main menu becomes a 72px icon rail on the left, with submenus flying out to the right.
- **Density**: *Compact* (13px base) or *Cozy* (14px base), plus tighter
  table rows, card padding, form controls and buttons.
- **Menu icons**: one outline icon per top-level menu item (Calendar, Finder,
  Flow, Recalls, Messages, Patient, Groups, Fees, eRx, Modules, Inventory,
  Procedures, Admin, Reports, Miscellaneous, Popups). Menu style can be
  *Icons and labels*, *Icons only* (label kept as tooltip and for screen
  readers) or *Labels only*.
- **Accent color**: optional override for buttons, links, focus rings and the active tab.

Every setting is user-specific: the value under **Admin > Config > Compact Theme**
is the practice default, and each user can override it under their own
**User Settings > Compact Theme**.

## Install

1. Copy this folder to `interface/modules/custom_modules/oe-module-compact-theme`
   on the server (it is already there if you deploy from this repository).
2. **Modules > Manage Modules > Unregistered**: *Register* "Compact Theme".
3. **Registered** tab: *Install*, then *Enable*.
4. Log out and back in (the main menu is built at login).
5. Optional: set the General Theme (**Admin > Config > Appearance**) to *Dark* if
   you choose Slate, *Light* for Paper or Rail.

Install adds four default rows to `globals` (`cmt_theme`, `cmt_density`,
`cmt_menu_style`, `cmt_accent`) and nothing else.

## How it hooks in (no core edits)

| Hook | Used for |
| --- | --- |
| `GlobalsInitializedEvent` | the *Compact Theme* settings tab (also shown in User Settings) |
| `StyleFilterEvent` | adds `public/assets/css/compact-theme.css` to every page that uses `Header::setupHeader()` |
| `ScriptFilterEvent` | adds `public/assets/js/compact-theme.js`, which tags `<html>` with the user's theme, density and menu style |
| `MenuEvent::MENU_UPDATE` | sets an icon class on each top-level main menu item |

The patient portal, login and setup screens are left alone. Pages that do not
call `Header::setupHeader()` (a few older screens) keep the base theme.

## Files

```
openemr.bootstrap.php          wiring only
src/Bootstrap.php              event listeners
src/ThemeSettings.php          settings parsing + the globals tab
src/MenuIconMap.php            menu item -> icon name
src/Theme.php, Density.php, MenuStyle.php
public/assets/css/compact-theme.css
public/assets/js/compact-theme.js
public/assets/icons/*.svg      24px outline icons, 1.75px stroke
table.sql                      default settings
```

## Rolling back

Modules > Manage Modules > Disable. To remove the settings too, unregister
the module and delete the four `cmt_*` rows from `globals`.

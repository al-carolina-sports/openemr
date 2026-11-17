# Annie's Compact Themes (oe-module-compact-theme)

Compact, modern theme packs for OpenEMR 8, built the same way as Annie's
Elegant Calendar Style: a self-contained custom module that adds stylesheets
through OpenEMR's events and never edits a core file. Because nothing in core
changes, it can be installed on top of any OpenEMR update, and settings carry
over untouched.

## What it does

- **Theme packs**, each a folder under `public/themes/`, layered on top of
  OpenEMR's General Theme. Three ship with the module:

  | Theme | Folder | Pairs with General Theme | Main menu |
  | --- | --- | --- | --- |
  | Slate Night | `slate` | Dark | top bar |
  | Paper Compact | `paper` | Light | top bar |
  | Graphite Rail | `rail` | Light | 72px left icon rail (screens 1200px and wider) |

- **Density**: *Compact* (13px base) or *Cozy* (14px base), plus tighter table
  rows, card padding, form controls and buttons.
- **Menu icons** for every top-level main menu item, shown as *icons and
  labels*, *icons only* (label kept as tooltip and for screen readers) or
  *labels only*.
- **Accent color**: optional per-user override for buttons, links, focus rings
  and the active tab.

## Where to choose a theme

**Miscellaneous > Annie's Elegant Themes** (open to every user). The module
looks under Miscellaneous for an entry whose name contains "Annie" and "Theme"
(so "Annie's Elegant Themes" and "Annie's Themes" both match) and adds to it:

- **Choose a Theme**: the picker, with a preview of each installed theme pack,
  plus density, main menu style and accent color.
- **One entry per theme pack** (Slate Night, Paper Compact, Graphite Rail, and
  any pack added later): opens the picker with that theme already selected.

If that entry is a plain menu item rather than a folder, it becomes a folder
whose first entry is the original item, so it still opens what it did before.
If there is no such entry, the module creates the folder at the end of
Miscellaneous.

In the picker:

- **Save for me**: your own choice.
- **Use practice default**: drop your own choice.
- **Save as practice default**: shown to users with Administration >
  Superuser access; applies to everyone without their own choice.

After saving, **Reload OpenEMR** applies it to the menu and every open tab.

The same settings are also in **Admin > Config > Compact Themes** (practice
default) and each user's **User Settings > Compact Themes**. The themes do
**not** appear in Admin > Config > Appearance > General Theme: that list is
OpenEMR's compiled core themes, which an update would overwrite.

## Install (fresh, or on top of an OpenEMR update)

1. Copy this folder to `interface/modules/custom_modules/oe-module-compact-theme`.
2. Modules > Manage Modules > *Unregistered*: **Register** "Annie's Compact Themes".
3. *Registered* tab: **Install**, then **Enable**.
4. Log out and back in (the main menu is built at login).

Install seeds four defaults in `globals` (`cmt_theme`, `cmt_density`,
`cmt_menu_style`, `cmt_accent`) only where no value exists, so re-installing
never resets anyone's choices.

## Updating

- **OpenEMR update**: nothing to do. If an update replaced the
  `custom_modules` folder, copy this module back in; settings are still in
  the database and apply as soon as it is enabled again.
- **Module update**: copy the new folder over the old one. `Config::VERSION`
  (with `version.php`) is part of every asset URL, so browsers fetch the new
  files immediately.
- **Theme pack update**: replace that pack's folder and bump `"version"` in its
  `theme.json`.

## Making a theme

Copy `public/themes/_template` to `public/themes/<your-id>`, set `"id"` in
`theme.json` to the folder name, and edit the tokens in `theme.css`. The new
theme appears in Miscellaneous > Annie's Elegant Themes and the Theme dropdown on the next
page load. No code change, no
database change. The template's comments list every token.

`theme.json` fields:

| Field | Values |
| --- | --- |
| `id` | the folder name: lowercase letters, digits, `-`, `_`; not starting with `_` |
| `name`, `description` | shown in the Theme dropdown |
| `base` | `light` or `dark`: the General Theme it is designed for |
| `layout` | `top`, or `rail` for the side icon menu |
| `order` | position in the dropdown (lower first) |
| `version` | bump when `theme.css` changes |
| `preview` | optional `#rrggbb` colors for the picker thumbnail: `bg`, `surface`, `nav`, `border`, `text`, `muted`, `accent`, `rail` |

Folders starting with `_`, or missing `theme.json`/`theme.css`, or with a
malformed `theme.json`, are skipped. If a user's chosen pack is removed they
fall back to Paper Compact.

## How it hooks in (no core edits)

| Hook | Used for |
| --- | --- |
| `GlobalsInitializedEvent` | the *Compact Themes* settings section (also in User Settings) |
| `StyleFilterEvent` | `public/assets/css/compact-base.css`, then the chosen `public/themes/<id>/theme.css` |
| `ScriptFilterEvent` | `public/assets/js/compact-theme.js`, which tags `<html>` with the theme, layout, density and menu style |
| `MenuEvent::MENU_UPDATE` | the theme entries under Miscellaneous > Annie's Elegant Themes, and an icon class on each top-level main menu item |

The patient portal, login and setup screens are left alone. Pages that do not
call `Header::setupHeader()` (a few older screens) keep the base theme.

## Files

```
openemr.bootstrap.php            wiring only
ModuleManagerListener.php        seeds default settings on install/enable
src/Bootstrap.php                event listeners
src/ThemeRegistry.php            finds theme packs in public/themes/
src/ThemeManifest.php            parses and validates theme.json
src/ThemeSettings.php            settings parsing + the settings section
src/ThemeChoice.php              a validated picker submission
src/ThemePreferences.php         saves to globals / user_settings
public/theme_picker.php          the theme picker page
src/ThemesMenu.php               places the picker under Annie's Elegant Themes
src/MenuIconMap.php              menu item -> icon name
src/Config.php                   cache-busting version
src/Density.php, MenuStyle.php, Layout.php, ColorScheme.php
public/assets/css/compact-base.css   shared styles, driven by --cmt-* tokens
public/assets/js/compact-theme.js
public/assets/icons/*.svg        24px outline icons, 1.75px stroke
public/themes/<id>/              theme packs (theme.json + theme.css)
table.sql                        default settings
```

## Rolling back

Modules > Manage Modules > **Disable**. To remove the settings too, unregister
the module and delete the four `cmt_*` rows from `globals`.

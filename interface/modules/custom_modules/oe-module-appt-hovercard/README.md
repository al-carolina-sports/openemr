# oe-module-appt-hovercard

Replaces the calendar's plain appointment tooltip with a hover card (appointment, patient and contact blocks, plus photo), modeled on PatientNow's "Appointment Information" popup. Everything lives in this module; no OpenEMR core file is changed.

## Install

1. Admin > Modules > Manage Modules, register and enable **Appointment Hover Card** (this runs `table.sql`).
2. Open **Admin > Appointment Hover Card** to configure it (admin ACL only).
3. For the two custom contact fields, add "Preferred contact method" and "Best time to call" under Admin > Forms > Layouts (Demographics), then pick them on the settings page.

Disabling the module restores the native tooltip.

## How it works

- `Bootstrap` subscribes to `html.head.script.filter`, `html.head.style.filter` and `MenuEvent::MENU_UPDATE`. The script and CSS are only added on the calendar pages, and only for roles allowed to see the card.
- `public/config.js.php` hands the page its display settings and a CSRF token.
- `public/assets/hovercard.js` finds appointments by `.event[data-eid]`, calls `public/ajax.php?eid=`, and draws the card. If the request fails, the native tooltip comes back.
- `public/ajax.php` requires a session, a CSRF token, calendar access (`patients/appt`) and an allowed role (403 otherwise). It drops every field the role may not see on the server, uses bound parameters only, and writes one audit entry per patient per session when enabled.
- Settings live in `oe_module_appt_hovercard_settings`, so they survive OpenEMR upgrades.

## Known gaps in this first scaffold

- **Balance due** field is not implemented (needs core's balance calculation).
- **Photo** is served from the core document URL at full size; the spec calls for a thumbnail.
- **View detection:** `currentViewAllowed()` in `hovercard.js` allows every view that renders `.event[data-eid]`; the Day/Week/Month setting is stored but not yet enforced client-side.
- **Field order** is a number box, not drag-and-drop.
- Not yet run against a live OpenEMR instance; PHPStan and the repo's pre-commit suite were not run (no vendor/Docker in the authoring environment).

## After every OpenEMR upgrade

Re-test the card. The only coupling to core markup is the `SELECTORS` block at the top of `public/assets/hovercard.js`.

## Tests

`tests/AccessAndSettingsTest.php` covers the role rules, phone masking, age and settings validation without a database.

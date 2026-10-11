# oe-module-appt-hovercard

Replaces the calendar's plain appointment tooltip with a hover card (appointment, patient and contact blocks, plus photo), modeled on PatientNow's "Appointment Information" popup. Everything lives in this module; no OpenEMR core file is changed.

## Install

1. Admin > Modules > Manage Modules, register and enable **Appointment Hover Card** (this runs `table.sql`).
2. Open **Admin > Appointment Hover Card** to configure it (admin ACL only).
3. For the two custom contact fields, add "Preferred contact method" and "Best time to call" under Admin > Forms > Layouts (Demographics), then pick them on the settings page.

Disabling the module restores the native tooltip.

## How it works

- `Bootstrap` subscribes to `html.head.script.filter`, `html.head.style.filter` and `MenuEvent::MENU_UPDATE`. The script and CSS are added only on the calendar (`pnuserapi.php`), only on views enabled in settings (the view comes from the event's `viewtype` context, checked server-side), and only for roles allowed to see the card.
- `public/config.js.php` hands the page its display settings and a CSRF token.
- `public/assets/hovercard.js` finds appointments by `.event[data-eid]` (Day/Week) and `.month_event[data-eid]` (Month), calls `public/ajax.php?eid=` with the token in an `X-CSRF-Token` header, and draws the card. It removes every native tooltip under the event and core's picture pop-up while active; if the request fails, the native tooltips come back. The card hides once the pointer is off both the event and the card.
- `public/ajax.php` requires a session, a CSRF token (header), calendar access (`patients/appt`) and an allowed role (403 otherwise). DOB, contact fields and the photo also need core's `patients/demo` ACL. It drops every field the role may not see on the server, uses bound parameters only, and writes one audit entry per patient per session when enabled.
- Photos are served by `ajax.php?action=photo&pid=` as a small square JPEG (never the original), after the document-category ACL check; core's `context=patient_picture` URL is not used (8.5 only allows it for the open patient). No photo or no GD: initials.
- Settings live in `oe_module_appt_hovercard_settings`, so they survive upgrades. Disabling keeps them; **unregistering the module drops the table** (`ModuleManagerListener`; OpenEMR does not run `cleanup.sql` itself).

## Known gaps

- **Balance due** field is not implemented (needs core's balance calculation).
- **Field order** is a number box, not drag-and-drop.
- PHP >=8.3 matches OpenEMR 8.5's own minimum.
- PHPStan and the repo pre-commit suite were not run in the authoring environment (no vendor/Docker); run `openemr-cmd phpstan` before merging.

## After every OpenEMR upgrade

Re-test the card. The only coupling to core markup is the `SELECTORS` block at the top of `public/assets/hovercard.js`.

## Tests

`tests/AccessAndSettingsTest.php` covers role rules, phone masking, age and settings validation; `tests/ViewGatingAndPhotoTest.php` covers per-view gating and thumbnail generation. Neither needs a database.

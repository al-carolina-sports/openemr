# Annie's Elegant Calendar Style

CSS-only OpenEMR custom module. On the calendar page (`pnuserapi.php`) it adds
`public/assets/elegant-calendar.css`, which sets Day/Week appointment text
(`.event`, `.event_appointment`, `.event_in`, `.event_highlight.event_time`,
`.event_details`) to 0.8rem, matching the time labels. Nothing else changes and
no core file is edited.

## Change the size

Edit `--aecs-event-font-size` at the top of `public/assets/elegant-calendar.css`,
then bump `Config::VERSION` in `src/Config.php` (cache-busting).

## Install

Copy this folder to `interface/modules/custom_modules/`, then Admin > Modules >
Manage Modules: Register, Install, Enable. No tables are created.

# ZeroDoc Clinical Encounter Workflow (v0.1.0-dev)

OpenEMR 8.5 custom module — **scaffold (WP-00b)**. No core file is edited.

This package is the plumbing, not the product: it proves every integration
point ZeroDoc needs, with one stub encounter form, one served asset and one
endpoint. The clinical schema and the real sections land on top of it.

## What is here

| Path | What it proves |
|---|---|
| `openemr.bootstrap.php` | the module is discovered and its namespace autoloads with no `composer install` |
| `src/Bootstrap.php` | event-only integration: encounter-form hosting, page- and role-gated asset injection, admin menu |
| `ModuleManagerListener.php` | enable / disable / unregister hooks, module-owned ACL registration, explicit table drop |
| `table.sql` / `cleanup.sql` | idempotent install machinery with a marked drop-in point for the real DDL |
| `forms/zdoc_encounter/` | the `new.php` / `view.php` / `report.php` encounter-form pattern, end to end |
| `public/api.php` | the endpoint security template every later ZeroDoc endpoint copies |
| `public/save.php` | writing a note to `form_zdoc_encounter` + core `forms`, with audit |
| `public/status.php` | Admin > ZeroDoc Workflow — the "did it load?" page |
| `public/assets/` | a statically served stylesheet and script |
| `src/Capability.php` | one place to ask whether a neighbouring module is enabled |

Conventions and the core code behind them: `../MODULE-CONVENTIONS.md`.
Deployment on this environment: `../DEPLOY.md`.

## Schema

- Table prefix is **`zdoc_`**. Encounter form tables are `form_zdoc_<section>`,
  because OpenEMR requires `form_<registry.directory>`.
- This scaffold installs exactly two tables: `zdoc_schema_version`
  (bookkeeping) and `form_zdoc_encounter` (the stub form), plus one `registry`
  row.
- **The clinical schema is not in here.** It drops into `table.sql` between the
  `BEGIN ZeroDoc clinical schema` / `END ZeroDoc clinical schema` markers, with
  the matching drops between the markers in `cleanup.sql`. The requirements are
  written out at those markers.
- `src/Forms.php` is the single list of ZeroDoc's encounter form directories.
  Adding one means four edits in lockstep: `Forms::ALL`,
  `ModuleManagerListener::FORMDIRS`, a `registry` row in `table.sql`, and a
  `forms/<dir>/` folder with the three pages (whose `report.php` must define
  `<dir>_report()`).

`ElegantAiScribe\Templates` in the AI Scribe module is **unrelated** to
ZeroDoc's templates — it is a hard-coded list of note headings. Do not build on
it, and do not reuse the word loosely in ZeroDoc code; ZeroDoc's objects are
`zdoc_template`, `zdoc_saved_finding` and so on.

## Access control

ZeroDoc registers its own phpGACL section, `zerodoc`, with objects `view`,
`document`, `sign`, `dispatch`, `setup`, `settings` (`src/AclObject.php`),
granted to the stock groups on first **enable** (`src/AclRegistrar.php`).
OpenEMR only runs `acl/acl_setup.php` for Laminas modules, so a custom module
has to do this itself — and the `openemr:zfc-module` CLI never calls the
listener, so after a CLI enable the objects must be registered by toggling
enable once in the UI, or they simply do not exist and superusers still pass
(`Access::can()` falls back to `admin/super`).

Every page and endpoint asks `src/AccessPolicy.php`, never `AclMain` directly.
Role gating is server-side only.

## Install (this environment)

Short version; the full runbook with backups is `../DEPLOY.md`.

1. Place the directory at
   `interface/modules/custom_modules/oe-module-zerodoc-encounter` inside the
   `openemr-proto-openemr-1` container.
2. Administration → Modules → **Manage Modules**. Loading that page registers
   newly found on-disk modules.
3. On the `oe-module-zerodoc-encounter` row click **Install** (this runs
   `table.sql`), then **Enable**. Enable in the UI, not from the CLI, so the
   ACL objects get registered.

## Verify it loaded

1. **Admin > ZeroDoc Workflow** exists in the menu and shows version `0.1.0`,
   `zdoc_encounter` with *Shown = yes*, and schema component `core` at
   revision 1.
2. Open a test patient, open an encounter, and the form menu's **Clinical**
   group lists **ZeroDoc Encounter Note**.
3. Open it. The grey banner at the top turns into
   `ZeroDoc 0.1.0 · core schema r1` — that is `public/api.php` answering with a
   valid CSRF header, so the endpoint, the asset and the ACL all work.
4. Type something, Save. The note appears in the encounter's form list, and in
   the patient report (that is `zdoc_encounter_report()` firing). Reopen it and
   the text is still there.
5. The PHP error log is quiet. A broken bootstrap is swallowed by core and only
   shows up there — and leaves the module force-disabled.
6. Disable the module: the form disappears from the encounter menu and
   `interface/modules/custom_modules/oe-module-zerodoc-encounter/*.php` 403s.
   Nothing is left injected into core pages.

## Standing rules for this module

- Modules only. Never edit OpenEMR base code.
- Never recreate the Docker containers.
- Back up to `~/openemr-backups/` on the VM before any change.
- Test data only.
- Run `openemr-cmd phpstan` before merging.

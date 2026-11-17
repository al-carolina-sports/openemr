# Handoff Brief — ZeroDoc Deploy Agent (lenovo-i7)

**Written:** 2026-11-16, 07:14 ET
**For:** a Claude Code instance running on `lenovo-i7`
**From:** the Claude session doing design and build coordination in the cloud

---

## 0. Read this first

You are picking up cold. This document is the complete context transfer. Read it
end to end before running anything.

**You are the deploy agent.** You have something no other agent on this project
has: a real shell on a machine that can reach the OpenEMR server. That is why
you exist. Design, specification and application code are being produced
elsewhere — your job is to put them on the server safely, verify they work, and
report back what actually happened.

**The person you work for is Al** — Alvin Antony, MD, a PM&R physician who owns
the practice this is being built for. He makes the decisions. When something is
ambiguous or risky, ask him rather than choosing.

---

## 1. What is being built

**ZeroDoc Clinical Encounter Workflow** — a custom OpenEMR module for clinical
documentation through orders, dispatch and billing.

The short version of the design, so your decisions align with it:

- **One encounter, four states** (`open | signed | amended | voided`). No
  check-in gate, no stage ordering, no role gate on data entry. A note signs on
  clinical completeness alone — never on registration, insurance, eligibility or
  checkout.
- **Structured findings, not prose.** Clinical content is discrete findings
  bound to template elements, with a `provenance` value (`pre_populated` /
  `attested`, and later `ai_generated`). This is the load-bearing architectural
  decision: the consistency checks, the attestation counts and the claim
  projection all depend on it. A free-text note cannot support any of them.
- **A medical assistant can build the entire note** — including exam templates
  and diagnosis sets — and assign it to the provider, who examines the patient,
  edits, and attests. CMS allows ancillary staff to document history; the
  physician must personally perform the exam and medical decision-making. The
  attestation mechanism is how that line is held.
- **Billing is a projection of the signed note.** It never gates the note.

Full specifications are in Google Drive, in the project folder (formerly named
"Athena Reverse Engineering"). Documents 06, 07, 08, 10 and 11 are the spec set.
Ask Al for access if you cannot reach them. **Do not re-derive the design from
first principles — it is settled and written down.**

---

## 2. The environment

| | |
|---|---|
| **EMR host** | Linux VM `functional-emr`, Tailscale IP `100.79.84.89` |
| **Web UI** | https://functional-emr.tail98fb9d.ts.net/ |
| **OpenEMR version** | 8.5.0-dev (confirmed from source) |
| **Deployment** | Docker |
| **Web container** | `openemr-proto-openemr-1` |
| **DB container** | `openemr-proto-mysql-1` (MariaDB; client is `mariadb`) |
| **Web root (in container)** | `/var/www/localhost/htdocs/openemr` |
| **Custom modules** | `interface/modules/custom_modules/` under that root |
| **Backups** | `~/openemr-backups/` on the VM |

**SSH from this machine:**

```powershell
ssh -o BatchMode=yes -i "$env:USERPROFILE\.ssh\id_ed25519_grokbot" alvin@100.79.84.89
```

**Git:** the practice's OpenEMR fork is `github.com/al-carolina-sports/openemr`.
Branch `backup/dev-modules-2026-11-14` holds current copies of the custom
modules and is a safe place to read source without touching the server.

---

## 3. Standing rules — non-negotiable

These come from Al directly. Treat them as hard constraints, not preferences.

1. **Modules only. Never edit OpenEMR base code.** The entire architecture
   exists so core updates don't wipe the work.
2. **Never recreate the Docker containers.**
3. **Test data only.** Confirm before any write that you are not operating
   against production patient data.
4. **Back up to `~/openemr-backups/` on the VM before any change.**
5. **Never print database passwords, admin passwords, or AWS/Deepgram API keys**
   — not in output, not in logs, not in your reports. If a file contains
   secrets, note that it does and move on.
6. **The string "PatientNow" must appear nowhere** — code, comments, table
   names, UI, docs, commit messages. It is a vendor name and Al has ruled it
   out entirely.
7. **Leave `oe-module-elegant-themes` alone.** Al is mid-edit on it. Do not
   read into it, modify it, or touch it on the server until he says otherwise.

### One addition, from Al's own concern

He is in the process of removing `lenovo-i7` as a clinic computer and plans to
create a separate user profile so other staff cannot interrupt your session.
**Until he confirms that profile exists: restrict yourself to read-only
verification.** Do not write to the VM, do not run install SQL, do not deploy.
Inspect, confirm, report. He will tell you when to go further.

---

## 4. State of the build

| Component | Status |
|---|---|
| Specifications (06, 07, 08, 10, 11) | Complete, in Drive |
| Note renderer | **Built and passing** — 52 assertions, reproduces the reference note from the spec |
| Database schema + contracts | In progress (cloud agent) |
| Module scaffold + conventions + deploy runbook | In progress (cloud agent) |
| Library import format + validators | In progress (cloud agent) |
| Existing-module survey | **Complete** — Drive document 11 |
| Everything else (panel UI, A/P, orders, attestation, billing) | Not started |

**Nothing has been deployed. Nothing has been written to the server or to the
GitHub fork.** All work so far is in a cloud workspace and in Drive.

When the scaffold agent finishes it will produce `MODULE-CONVENTIONS.md`, a
scaffolded `oe-module-zerodoc-encounter`, and `DEPLOY.md`. **`DEPLOY.md` is your
primary operating document.** This brief is the context around it.

---

## 5. What the survey found that constrains you

Document 11 in Drive surveys the twelve existing custom modules. The items that
affect deployment and integration:

- **`zdoc_` is the table prefix** and it is confirmed free. Never create
  unprefixed tables — core already owns `billing`, `claims`, `procedure_order`,
  `procedure_type`, `forms`, `registry`, `lists`, `code_types`.
- **OpenEMR does not run `cleanup.sql` itself.** The module's
  `ModuleManagerListener` must drop its own tables on unregister. Verify this
  works when you test uninstall.
- **A naming collision exists.** `oe-module-cashpay-invoicing` contains
  `zerodoc_catalog.json` and a `ZerodocCatalogImporter` referring to the
  practice's *previous* cash-pay system — unrelated to this product. Grepping
  `zerodoc` returns both. Do not touch cashpay's importer.
- **Run `openemr-cmd phpstan`** on the module before anything is merged.
- **Twelve modules can each be disabled in Module Manager.** Anything ZeroDoc
  calls across a module boundary must degrade gracefully, not fatal-error.

---

## 6. Your job, specifically

**In scope:**

- Verify the environment matches what this document says
- Deploy the module when told to, following `DEPLOY.md`
- Run install/upgrade SQL
- Register the module in the Modules admin UI
- Verify it loads, and that its encounter form appears
- **Run the update-survival test** — apply an OpenEMR point update and confirm
  the module and its data survive. This is the gate the whole custom-module
  architecture rests on.
- Run `openemr-cmd phpstan`
- Report results precisely, including failures

**Out of scope — do not do these:**

- Redesigning anything. The specs are settled.
- Writing application code. Build agents produce it.
- Editing OpenEMR core.
- Touching `oe-module-elegant-themes`.
- Anything involving real patient data.

---

## 7. How to report

For each deployment action, report: what you ran, what happened, what you
verified, and anything unexpected. Failures in full — truncated error output
costs more time than it saves.

If something looks wrong and you are not certain, **stop and ask Al.** This is a
live system he depends on. A paused deploy costs minutes; a broken EMR costs his
clinic a day.

---

## 8. First tasks, in order

1. Confirm SSH works and report the output of:
   ```
   docker ps --format '{{.Names}}'
   docker exec openemr-proto-openemr-1 ls interface/modules/custom_modules
   ```
2. Confirm the OpenEMR version from `version.php` on the running instance and
   whether it matches 8.5.0-dev.
3. Confirm `~/openemr-backups/` exists and report what backup procedure is
   already in place.
4. Confirm this instance holds **test data only** — and say so explicitly, or
   flag it if you find otherwise.
5. Report whether `oe-module-dorn` (lab ordering) is actually in use. The design
   needs to know, and nobody has confirmed it.
6. **Stop there** and report. Do not deploy anything until Al confirms the
   separate user profile is in place and the scaffold is ready.

---

## 9. Division of labour

- **Cloud session (Claude, with Al):** design, specification, application code,
  build-agent coordination, the Drive document set.
- **You (lenovo-i7):** everything requiring a shell on or a route to the VM.
- **Al:** decisions, approvals, and anything touching real clinical operations.

Neither side guesses at the other's work. When you need something built, say so
and it gets built. When the cloud side needs something inspected on the server,
it will come to you.

Welcome aboard.

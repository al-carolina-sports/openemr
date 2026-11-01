# AI Assistant — acceptance test (Phase 1, read-only)

Run on the **dev server only**, with test patients. Do not use real patient
names in prompts.

## Before you start

- [ ] `bin/spike.php` prints `PASS`
- [ ] Admin > AI Assistant > **Test connection** succeeds
- [ ] Two test patients with the same last name exist (e.g. *Patricia Test*,
      DOB 1968-03-14, and *Patrick Test*), and Patricia has one past and one
      upcoming appointment
- [ ] One provider has a lunch block and a few appointments next week

## Reads

| # | Ask | Pass when |
|---|---|---|
| R1 | "Find Patricia Test" | Names her with date of birth. Doesn't mix her up with Patrick |
| R2 | "Find Test" | Lists both and asks which one you mean |
| R3 | "When is Patricia Test's next visit?" | Gives the right date, time and provider |
| R4 | "What does Dr. *X*'s schedule look like next Tuesday?" | Matches the calendar, including the lunch block. Cancelled visits are shown as cancelled |
| R5 | "Find a 30-minute opening for Dr. *X* next week" | Every time offered is actually free in the calendar, falls within office hours, and is in the future |
| R6 | "What appointments has Patricia had this year?" | Past visits, most recent first |

## Guardrails

| # | Do | Pass when |
|---|---|---|
| G1 | "Book Patricia for Tuesday at 10" | Says it can't change the calendar yet. Nothing changes in the calendar |
| G2 | Log in as a user **without** Patients > Demographics and ask R1 | Says it is not permitted. No patient details |
| G3 | Remove *AI Assistant > Use* from a group and log in as that user | No chat button |
| G4 | Untick **Assistant enabled** | Chat button disappears for everyone on next page load |
| G5 | After R1–R3, check `SELECT created_at, role, tool_name, pid FROM mod_ai_assistant_log ORDER BY id DESC LIMIT 20` (phpMyAdmin) and Reports > Audit Log | Each question, tool call and answer has a row. Each patient read appears in the audit log as a `view` event for your user |
| G6 | Click **New chat**, ask "Who did I just ask about?" | It doesn't know |
| G7 | Temporarily rename the credentials file and ask anything | Friendly "not configured" message, no stack trace. Restore the file afterwards |

## Record the result

Model ID, date, tester, and pass/fail for each row. Failures: copy the
question and the reply (test patients only) into the issue.

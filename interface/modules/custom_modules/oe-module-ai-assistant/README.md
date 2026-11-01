# AI Assistant for OpenEMR (v0.1, read-only)

A chat panel in the OpenEMR top bar. Staff ask in plain English and the
assistant looks things up in OpenEMR using the user's own permissions:

- find a patient (name, optional first name and date of birth)
- list providers
- show a provider's schedule (up to 14 days)
- find open appointment times for a provider
- show a patient's upcoming and past appointments

**This version cannot change anything.** Booking, rescheduling, cancelling and
blocking provider time come in later phases. The panel says so if asked.

The model runs on Amazon Bedrock (default: Llama 4 Maverick,
`us.meta.llama4-maverick-17b-instruct-v1:0`) in your AWS account, which is
covered by your AWS Business Associate Addendum. No other service receives
patient data.

## How it works

1. A staff member types a question in the panel.
2. The module sends the conversation, a short system prompt and the five tool
   descriptions to Bedrock (Converse API).
3. When the model asks for a tool, the module runs it **with the user's own
   OpenEMR rights** (Patients > Demographics for patient lookups,
   Patients > Appointments for calendar reads) and sends back the result.
4. This repeats (up to 8 tool calls by default) until the model answers in words.

Every question, tool call, answer and error is written to
`mod_ai_assistant_log`, and every patient the assistant reads is also recorded
in the core OpenEMR audit log as a `view` event. Log rows are purged after
30 days by a daily background job. Conversations are kept per user in the
database and forgotten after 2 idle hours or when the user clicks **New chat**.

## Install

1. **AWS (once).** In IAM, create a user `openemr-ai-assistant` with no
   console access and an inline policy allowing only
   `bedrock:InvokeModel` on the model you will use (for Llama 4 Maverick, the
   inference profile `arn:aws:bedrock:us-east-1:<account>:inference-profile/us.meta.llama4-maverick-17b-instruct-v1:0`
   and the foundation model `arn:aws:bedrock:*::foundation-model/meta.llama4-maverick-17b-instruct-v1:0`).
   Create an access key for it yourself in the console.
2. **Credentials on the server.** Never in the database, never in Git:

   ```bash
   sudo install -d -m 750 -o root -g www-data /etc/openemr
   sudo install -m 640 -o root -g www-data /dev/null /etc/openemr/ai-assistant.env
   sudo nano /etc/openemr/ai-assistant.env
   ```

   ```ini
   AWS_ACCESS_KEY_ID=AKIA...
   AWS_SECRET_ACCESS_KEY=...
   ```

   (Or set the same two variables with Apache `SetEnv`/PHP-FPM `env[]`;
   environment variables win over the file.) Use your web server's group in
   place of `www-data` if it differs (`apache` on RHEL-family systems).
3. **Check the model works with tools** (Phase 0 spike, no patient data):

   ```bash
   sudo -u www-data php interface/modules/custom_modules/oe-module-ai-assistant/bin/spike.php
   ```

   It prints `PASS` when the model correctly asks for the `find_patient` tool.
   Try other models with `--model=...`.
4. **OpenEMR.** Admin > Modules > Manage Modules > Unregistered: register,
   install and enable **AI Assistant**. This creates three tables and the
   *AI Assistant* access-control section.
5. **Settings.** Admin > AI Assistant: check the model and region, click
   **Test connection** (sends "Reply with the word OK." — no patient data),
   then tick **Assistant enabled** and save.
6. **Who can use it.** Admin > ACL: *AI Assistant > Use* is granted to
   Administrators, Physicians and Front Office by default. Remove it from any
   group that should not see the panel.

The chat button appears in the top bar after the next page load.

## Settings

| Setting | Default | Notes |
|---|---|---|
| Enabled | off | Panel hidden for everyone until turned on |
| Model ID | `us.meta.llama4-maverick-17b-instruct-v1:0` | Any Bedrock model that supports Converse tool use |
| Region | `us-east-1` | |
| Credentials file | `/etc/openemr/ai-assistant.env` | Used when the environment variables are not set |
| Max tool calls per question | 8 | |
| Messages per user per hour | 30 | |
| Max answer length | 1000 tokens | |
| Log retention | 30 days | |
| Assume office hours | on | Calendar start/end/interval from Admin > Config > Calendar, on the working days below, when a provider has no In/Out of Office markers that day |
| Working days | Mon–Fri | |

## Uninstall

Disable the module in Manage Modules. Tables are kept until you run
*Unregister*, which executes `cleanup.sql` and drops them (including the log).

## Development

```bash
openemr-cmd e 'cd interface/modules/custom_modules/oe-module-ai-assistant && php ../../../../vendor/bin/phpunit -c phpunit.xml'
```

The tests run without a database or network. See [TESTING.md](TESTING.md) for
the manual acceptance test on the dev server.

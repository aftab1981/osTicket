# IT Approvals — osTicket plugin

Multi-level approval workflow for IT request forms, built on osTicket Help Topics.
Line manager comes from Entra ID (Microsoft Graph); approvers act in the client portal
using the existing Entra SSO. Design and rationale: [docs/STRATEGY.md](docs/STRATEGY.md).

- osTicket **1.18.x**, PHP 8.x with `curl` and `openssl`
- Existing Entra SSO via the `auth-oauth2` plugin (unchanged)
- No core files are modified

## How it works (one paragraph)

A user opens a ticket on an approval-enabled Help Topic. The plugin parks it in the
**Approval Hold** department with status **Pending Approval** (no agent is alerted),
resolves level 1 (e.g. the requester's Entra manager) and emails the approver a link to
the portal. Approvers **Approve**, **Return for information** or **Reject**. After the
last level the ticket moves to the fulfilment department, gets its normal status/SLA/
auto-assignment, and IT receives the standard *New Ticket* alert. Every step is recorded
in the plugin tables and as an internal note on the ticket.

| Page | URL |
|------|-----|
| Approver / requester portal | `https://<helpdesk>/ajax.php/itapprovals` |
| Admin: matrix, simulator, health | `https://<helpdesk>/scp/ajax.php/itapprovals/admin` (also Admin Panel › Applications › IT Approvals) |

## Installation (staging first)

### 1. Deploy the files
Copy `include/plugins/it-approvals/` to the same path on the osTicket server
(it is already in this repository at that path).

### 2. Ticket statuses — Admin Panel › Manage › Lists › Ticket Statuses
| Name | State | Notes |
|------|-------|-------|
| Pending Approval | Open | Tick *Customers can see this status* if offered |
| Information Required | Open | |
| Rejected | Closed | |

### 3. Hold department — Admin Panel › Agents › Departments › Add
- Name `Approval Hold`, Type **Private**
- *Alerts*: **disabled** (Group membership / alerts setting = "Disable for all")
- Auto-response: your choice (on = requester gets the "we received your request" email)
- Members: only the IT admin(s) who look after approvals
- Do not use this department as any Help Topic's department

### 4. Form — Admin Panel › Manage › Forms › Add New Custom Form
Title `Internet / Web Access Request`:

| Label | Type | Settings |
|-------|------|----------|
| Access type | Choices | Standard browsing; Specific website(s); Social media; Streaming / Media; Cloud storage / File sharing; Unrestricted — **Required** |
| Website URL(s) | Long Answer | Optional. Hint: *Required if Access type = Specific website(s)* |
| Business justification | Long Answer | **Required** |
| Duration | Choices | Permanent; Temporary — **Required** |
| End date | Date and Time | Optional (date only). Hint: *Required if Duration = Temporary* |
| Device / Computer name | Short Answer | Optional |

For every field: *Visible to users* and *Editable by users* = on (so requesters can
correct answers when a request is returned).

### 5. Help Topic — Admin Panel › Manage › Help Topics › Add
- Topic `Internet / Web Access Request`, Public, Active
- Department: the **fulfilment** department (e.g. IT Network); priority/SLA as usual
- Forms: add `Internet / Web Access Request`
- Auto-assignment (optional): it is applied **after** approval

### 6. Entra app registration for Microsoft Graph
Separate from the SSO app (least privilege):
1. Entra admin center › App registrations › New registration, e.g. `osTicket Approvals Graph`
   (single tenant, no redirect URI).
2. API permissions › Add › Microsoft Graph › **Application permissions** › `User.Read.All` ›
   **Grant admin consent**.
3. Certificates & secrets › New client secret. Note its expiry date — calendar a renewal.
4. Copy the **Directory (tenant) ID**, **Application (client) ID** and the secret value.
5. Make sure managers are populated in Entra (`manager` attribute, usually synced from AD/HR).

The server must reach `login.microsoftonline.com` and `graph.microsoft.com` over HTTPS.

### 7. Install and configure the plugin — Admin Panel › Manage › Plugins
1. **Add New Plugin** › *IT Approvals* › Install.
2. Open it › **Instances** › Add New Instance, name `IT Approvals`.
3. Fill in:
   - Statuses: Pending Approval / Information Required / Rejected; Approved = empty (system default)
   - Approval hold department: `Approval Hold`
   - Tenant ID, Client ID, Client secret
   - Fallback approver: e.g. the IT manager's email
   - Keep defaults for the rest (skip duplicates, resubmit on reply, 24h reminders, agent-created tickets)
4. Status = **Active**, Save. The plugin creates its tables (`<prefix>itapp_*`) on save.
5. Set the plugin itself to **Enabled** in the plugin list.

### 8. Approval matrix — Admin Panel › Applications › IT Approvals
1. **Configure** `Internet / Web Access Request`, for example:
   1. `Line Manager` — *Line manager (Entra ID)*
   2. `Head of IT` — *Named approver(s)* — `it.head@company.com`
   3. `Information Security` — *Named approver(s)* — `ciso@company.com, isec@company.com`
2. Fulfilment department: `IT Network` (or leave on the Help Topic's department).
3. Save, then open the **Simulator** and try 2–3 real employees, including one with no
   manager and one who is also an approver. All health checks should be green.

### 9. Cron
Reminders need the osTicket cron (`api/cron.php` or *Auto-cron*). If you already fetch
email by cron, nothing to do.

### 10. Portal menu and look
The plugin adds an **Approvals** item (with a count of requests waiting for the signed-in
user) to the portal menu automatically — no template edits. For the refreshed portal
look, also install the companion **Portal Theme** plugin (`include/plugins/portal-theme/`).

## Phase 2 features

### Form pack — Admin Panel › Applications › IT Approvals › Form pack
One click installs a complete request type: the custom form, a public Help Topic (with the
standard Ticket Details form), its conditional-field rules and a starting approval matrix.
Running it again is safe ("Repair / complete").

| Form | Conditional fields | Approval chain |
|---|---|---|
| Internet / Web Access | Website URL(s) if *Specific website(s)*; End date if *Temporary* | Line Manager → Head of IT |
| VPN Access | Vendor company / contact / email / phone, Sponsor, NDA if *Vendor* or *Guest*; End date if *Temporary* | Line Manager → Head of IT → Information Security *(vendor / guest only)* |
| SAP Change Request | Roles / users affected if *Roles & authorisations* | Line Manager → Process Owner → Head of IT *(High / Critical only)* |
| Email / Network | Access level (shared mailbox / folder / distribution list), Location (network), Firewall details (firewall); End date if *Temporary* | Line Manager → Head of IT → Information Security *(firewall rules only)* |

Named-approver levels start as the fallback approver. Set the real approvers in the matrix
before go-live.

### Conditional fields — … › Conditional fields
osTicket forms can't show or hide fields on their own. A rule shows a field only when another
field has one of the given values, and can make it required while it's visible. The portal
applies the rules as the user fills in the form. On submission the request is checked again,
and an incomplete request is **returned to the requester automatically**, listing what's
missing, before any approver sees it. Resubmitting is refused until the missing fields are
filled in.

### Conditional approval levels — matrix editor, "Only when"
A level can apply only when a field has certain values, for example Information Security
signs off vendor VPN access only. Otherwise the level is recorded as *not required* and
skipped.

### Reports — … › Reports
Covers the last 7, 30 or 90 days, or 12 months:
- **per request type:** volume, outcomes, approval rate and average time to approve
- **per approval level:** decisions, average and slowest decision time, and oldest pending request
- **per approver:** decisions and response time
- **overdue:** requests waiting longer than the reminder interval

The per-level figures can be exported as CSV.

### Microsoft Graph in national clouds
For a national cloud such as GCC High, add to `include/ost-config.php`:

```php
define('ITAPPROVALS_GRAPH_ENDPOINT', 'https://graph.microsoft.us');
define('ITAPPROVALS_LOGIN_ENDPOINT', 'https://login.microsoftonline.us');
```

### Email sender
Approval notifications are sent from osTicket's **Default System Email**. Set it to
`helpdesk@dhrp.com.au` under Admin Panel › Emails.

## Test plan

Run the full lifecycle in staging — see [docs/STRATEGY.md §11](docs/STRATEGY.md#11-testing-strategy--full-lifecycle-not-pages)
(T1–T15). Minimum before go-live: T1, T2, T3, T4, T5, T6, T8, T9.

Verify these environment-specific points during staging:
- An approver who has **never** logged in to the portal can sign in via SSO from the
  email link and lands back on the request page (depends on your `auth-oauth2`
  client-registration settings).
- The osTicket email address of employees matches their Entra `mail`/UPN (the simulator
  shows *User not found in Entra ID* when it doesn't).

## Troubleshooting

| Symptom | Check |
|---------|-------|
| Tickets on the topic are not held | Plugin enabled **and** instance active; matrix enabled with ≥1 level; statuses + hold department set (health checks) |
| Approver always = fallback | Simulator notes: Graph credentials, admin consent, user's `manager` attribute, email/UPN mismatch |
| "Graph error" in Admin › Dashboard › System Logs | Secret expired / wrong tenant / missing consent |
| Approver gets 404 on the link | They are signed in with a different email than the one on the step — add it as a secondary email on their osTicket user, or fix the matrix |
| Ticket stuck in Approval Hold with no request | System Logs › "IT Approvals: Unable to start approval" — fix the cause, then move the ticket manually |

## Files

```
plugin.php            manifest
itapprovals.php       plugin class, signal wiring
config.php            plugin settings
class.schema.php      tables (created on enable / config save)
class.models.php      ORM models
class.graph.php       Microsoft Graph client + manager cache
class.engine.php      workflow state machine, notifications
class.portal.php      client portal controller
class.admin.php       SCP admin controller (matrix, simulator)
templates/            portal + admin views
docs/STRATEGY.md      strategy, design, security, test plan, roadmap
```

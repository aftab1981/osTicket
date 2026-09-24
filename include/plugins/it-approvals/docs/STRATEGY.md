# IT Approvals for osTicket — Strategy & Design

Status: Phase 1 and Phase 2 implemented and tested locally (plugin v1.0.0) — pending staging test
Target: osTicket 1.18.x, Entra ID SSO via the official `auth-oauth2` plugin
Inspired by: "Building an In-House IT Forms Portal & Digital Approval Workflow" (aacable, 2026-09-22)

---

## 1. Problem

IT requests that need business approval (Internet access, VPN, SAP changes, mailbox/DL
requests) are handled on paper or by email. Requesters cannot answer the question the
article opens with — *"Where is my form now, and whom should I call?"* — and IT has no
auditable record of who approved what, when.

We already run osTicket for support and all employees sign in with Entra ID. The goal is
to add a digital approval workflow **inside osTicket** rather than build a second portal.

## 2. Goals / non-goals

**Goals**
- Employees submit IT request forms from the existing osTicket client portal.
- Each form routes through a configurable, multi-level approval chain before IT sees it.
- Line manager is resolved automatically from Entra ID (Microsoft Graph).
- Approvers act from the client portal (Entra SSO) — no agent seats needed.
- Approve / Reject / Return-for-information, with mandatory comments on reject/return.
- Requester always sees current stage, pending approver, and a clean timeline.
- After final approval the request becomes a normal osTicket ticket in the fulfilment
  department: assignment, SLA, replies and closure work exactly as today.
- Full audit trail (plugin tables + internal notes on the ticket thread).
- No modification of osTicket core files (survives upgrades).

**Non-goals (Phase 1)**
- A new form designer — osTicket's Forms + Help Topics already do this.
- Approval via one-click links in email (see §8, security).
- Delegation / out-of-office routing (Phase 3).

## 3. Key design decisions

| # | Decision | Rationale |
|---|----------|-----------|
| D1 | Build as an osTicket **plugin** using core signals | No core patches; upgrade-safe; enable/disable per environment. |
| D2 | A form = **Help Topic + custom Form** | Admins already know this UI; field types, required flags and client/agent visibility are built in. (Stock osTicket has no conditional show/hide between fields — see §10.) |
| D3 | The ticket is created **at submission**, held in a *Pending Approval* status + *hold* department | Requester gets a ticket number immediately, sees status in "My Tickets", can reply/attach. osTicket's thread gives us the conversation for free. |
| D4 | Approval chain lives in a **matrix per Help Topic**, not in the form | Mirrors the article's core idea: routing is configuration, not code. |
| D5 | The matrix is **snapshotted** onto each request at submission | Editing the matrix never changes the routing of in-flight requests. |
| D6 | Approver resolution is **lazy** (per level, when the level activates) | A manager change in Entra between level 1 and 2 is honoured. |
| D7 | Line manager from **Microsoft Graph** (`/users/{id}/manager`), cached (default 24h) | Single source of truth = Entra/HR; no duplicate data in osTicket. |
| D8 | Approvers are **portal users**, authenticated by existing Entra SSO | No agent licences/seats; managers use the same login as every employee. |
| D9 | **Authentication ≠ authorisation**: a user may act on a step only if their email is in that step's resolved approver list | Straight from the article. Logging in never grants approval rights. |
| D10 | On final approval the plugin moves the ticket to the fulfilment dept and fires osTicket's standard **New Ticket alert** there | IT agents experience approved requests exactly like new tickets. |

## 4. Architecture

```
 Employee (Entra SSO)                         IT Agents (SCP)
        │                                            ▲
        │ 1. Open New Ticket → Help Topic            │ 7. New-ticket alert, assign,
        │    "Internet Access Request"               │    SLA, work, close (stock osTicket)
        ▼                                            │
 ┌─────────────────────── osTicket core ─────────────┴───────────────┐
 │ Ticket::create()                                                   │
 │   ├─ signal ticket.create.validated ──► [plugin] force status =     │
 │   │                                     Pending Approval, dept =    │
 │   │                                     Approval Hold, no auto-assign│
 │   └─ signal ticket.created ──────────► [plugin] Engine::start()    │
 │ ThreadEntry created ─ threadentry.created ► auto-resubmit on reply │
 │ cron ────────────────────────────────► reminders                   │
 └────────────────────────────────────────────────────────────────────┘
                 │                          ▲
                 ▼                          │
 ┌──────────── IT Approvals plugin ────────────────────────────────────┐
 │ Engine     : state machine, level activation, decisions, finalise   │
 │ Resolver   : manager / skip-manager (Graph) / fixed approvers        │
 │ Graph      : client-credentials token, /users/{upn}/manager, cache  │
 │ Notifier   : emails to approvers & requester (links to portal)      │
 │ Portal     : /ajax.php/itapprovals  (client side)                   │
 │ Admin      : SCP ▸ Admin ▸ Applications ▸ IT Approvals (matrix +    │
 │              simulator)                                              │
 │ Tables     : itapp_matrix, itapp_request, itapp_step,               │
 │              itapp_step_approver, itapp_mgr_cache                    │
 └──────────────────────────────────────────────────────────────────────┘
                 │ HTTPS (client credentials)
                 ▼
          Microsoft Graph  (User.Read.All, application permission)
```

## 5. Request lifecycle

### 5.1 States

| Request state | osTicket ticket status (configurable) | Dept |
|---------------|---------------------------------------|------|
| `pending`     | Pending Approval (state *open*)       | Approval Hold |
| `returned`    | Information Required (state *open*)   | Approval Hold |
| `rejected`    | Rejected (state *closed*)             | Approval Hold |
| `cancelled`   | Rejected / Closed (state *closed*)    | Approval Hold |
| `approved`    | Open (system default)                 | Fulfilment dept (per matrix, else Help Topic dept) |

After `approved` the plugin steps back; the ticket follows normal osTicket flow.

### 5.2 Transitions

```
submit ──► pending(L1) ──approve──► pending(L2) … ──approve(last)──► approved ──► fulfilment
              │   ▲                                                         (stock osTicket)
              │   └──── resubmit (requester reply or button) ◄── returned
              ├──return (comment required)──────────────────────► returned
              ├──reject (comment required)──────────────────────► rejected  [final]
              └──cancel (requester)─────────────────────────────► cancelled [final]
```

- **Return** sends the request back to the requester; resubmission restarts at the
  **same level** that returned it (earlier approvals stand). Each resubmission increments
  `cycle`, and every step row keeps its cycle so the timeline shows history without
  duplicates.
- **Duplicate approver collapse** (optional, default on): if a level resolves to only
  people who already approved an earlier level of this request, it is recorded as
  `skipped` and the chain moves on.
- **Self-approval guard**: the requester is always removed from approver lists. If a
  level resolves to nobody (e.g. CEO has no manager, Graph error), it routes to the
  configured **fallback approver** and records why.

## 6. Approval matrix

One row per Help Topic:

```
topic: "Internet / Web Access Request"
enabled: yes
fulfilment dept: IT Network
levels:
  1. "Line Manager"      type = manager
  2. "Head of IT"        type = approvers   approvers = it.head@corp.com
  3. "Information Sec."  type = approvers   approvers = ciso@corp.com, isec.deputy@corp.com   (any one)
```

Level types:
- `manager` — requester's Entra manager.
- `manager2` — manager's manager (skip-level / HOD).
- `approvers` — fixed list of emails; **any one** of them can decide.

Admin tools (SCP ▸ Admin ▸ Applications ▸ IT Approvals):
- **Matrix editor** — per Help Topic, up to 5 levels.
- **Simulator** — pick a topic and a requester email, see the exact resolved chain
  (live Graph lookup), including fallbacks, self-approval removals and skips. This is the
  article's "approval matrix simulator"; it is admin-only.

## 7. Entra ID / Microsoft Graph

- SSO: unchanged — the existing `auth-oauth2` plugin keeps handling sign-in.
- Manager lookup: a **separate** Entra app registration (least privilege):
  - API permission: Microsoft Graph → **Application** → `User.Read.All`, admin-consented.
  - Credential: client secret (stored encrypted in osTicket plugin config using
    osTicket's `PasswordField`, i.e. encrypted with `SECRET_SALT`).
  - Calls: `POST https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token`
    (`client_credentials`, scope `https://graph.microsoft.com/.default`), then
    `GET https://graph.microsoft.com/v1.0/users/{email}/manager?$select=displayName,mail,userPrincipalName`.
  - Requester lookup uses the email osTicket has for the user (which the SSO plugin set
    from Entra). If `mail` differs from UPN, both are tried.
  - Results cached in `itapp_mgr_cache` (TTL configurable, default 24h). Timeouts are
    short (5s) and failure never blocks submission — it routes to the fallback approver.
- Approver sign-in: approver emails are ensured to exist as osTicket users so the SSO
  plugin can sign them in to the portal.

## 8. Security

| Concern | Control |
|---------|---------|
| Who can approve | Server-side check: signed-in user's email ∈ resolved approvers of the **current pending step**. Checked on every POST, not just when rendering buttons. |
| Self-approval | Requester stripped from all approver lists. |
| CSRF | osTicket's global CSRF check on all client POSTs (`client.inc.php`); forms carry `csrf_token()`. |
| Email link pre-fetch (Defender Safe Links, scanners) | Email links only **open** the portal page; the decision needs a signed-in POST. No one-click approve tokens. |
| SQL injection | All DB access through osTicket ORM (parameter-bound) or `db_input()`; no string-concatenated user input. |
| XSS | All output via `Format::htmlchars()`; form answers rendered by osTicket's own field `display()`. |
| Admin tools | Matrix & simulator served via `scp/ajax.php`; every handler checks `isAdmin()`. |
| Secrets | Graph client secret encrypted at rest; never rendered back to the page. |
| Audit | Every decision stored (who, when, comment, cycle) + internal note on the ticket thread; ticket events log status/dept changes. |
| Tampering while held | Hold department should have no agent members except the IT approvals admin(s); approval tickets aren't visible in normal queues. |

## 9. osTicket configuration (one-time, per environment)

1. **Ticket statuses** (Admin ▸ Manage ▸ Lists ▸ Ticket Statuses):
   `Pending Approval` (open), `Information Required` (open), `Rejected` (closed).
   Allow client visibility of the name so requesters see it in "My Tickets".
2. **Department** `Approval Hold`: private, *Alerts: disabled*, no auto-response
   *(optional — leave auto-response on if you want the "we received your request" email)*,
   no members except approval admins.
3. **Form** `Internet / Web Access Request` (Admin ▸ Manage ▸ Forms), see §10.
4. **Help Topic** `Internet / Web Access Request`: public, form attached,
   department = fulfilment dept (e.g. IT Network), priority/SLA as normal.
5. **Entra app registration** for Graph (§7).
6. **Plugin**: install, create the (single) instance, fill config (statuses, hold dept,
   Graph credentials, fallback approver), enable.
7. **Matrix**: SCP ▸ Admin ▸ Applications ▸ IT Approvals → configure the topic, run the
   simulator for 2–3 real employees.

## 10. Phase 1 form — Internet / Web Access Request

| Field | Type | Notes |
|-------|------|-------|
| Access type | Choices: Standard browsing, Specific website(s), Social media, Streaming/Media, Cloud storage/File sharing, Unrestricted | required |
| Website URL(s) | Long text | optional; hint: "Required if Access type = Specific website(s)" |
| Business justification | Long text | required |
| Duration | Choices: Permanent, Temporary | required |
| End date | Date | optional; hint: "Required if Duration = Temporary" |
| Device / Computer name | Short text | optional |

Issue summary / details are the standard ticket fields.

## 11. Testing strategy — full lifecycle, not pages

From the article: test the complete lifecycle. Test cases (staging first):

| # | Scenario | Expected |
|---|----------|----------|
| T1 | Submit with 3-level matrix | Ticket in Pending Approval, Approval Hold dept, not assigned, no IT alert; L1 approver emailed; requester sees stage + approver |
| T2 | L1 approve → L2 approve → L3 approve | Ticket Open in fulfilment dept, New-Ticket alert to IT, topic auto-assignment applied, 3 notes on thread |
| T3 | L2 returns with comment | Status Information Required, requester emailed with comment |
| T4 | Requester replies in portal | Auto-resubmit to L2 (L1 approval kept), cycle 2 in timeline, no duplicate entries |
| T5 | L3 rejects with comment | Ticket closed as Rejected, requester emailed, no IT alert |
| T6 | Non-approver opens request URL | Can view nothing / 403; POST decision refused |
| T7 | Requester is also L2 approver | Requester removed; routes to fallback |
| T8 | Requester has no Entra manager | L1 routes to fallback, reason recorded |
| T9 | Graph unreachable / bad secret | Submission still succeeds; fallback; error in System Logs |
| T10 | Same person L1 and L2 | L2 recorded `skipped` (if collapse enabled) |
| T11 | Matrix edited mid-flight | In-flight request keeps its snapshot |
| T12 | Pending > reminder hours | Reminder email once per interval |
| T13 | Help Topic without matrix | Ticket created normally (plugin does nothing) |
| T14 | Agent creates ticket on behalf of user on that topic | Same approval flow |
| T15 | CSRF: POST without token | Rejected by osTicket |

## 12. Roll-out plan

| Phase | Scope |
|-------|-------|
| **1 — done** | Plugin core, Graph resolver, portal inbox/detail, admin matrix + simulator, reminders, **Internet / Web Access** form. Pilot with IT + one business department. |
| **2 — done** | VPN Access (vendor/guest fields, temporary end date), small conditional show/hide script for forms, Email/Network forms, SAP Change Request. Reporting page (turnaround per level). |
| 3 | Delegation / out-of-office, escalation after N days, Teams notifications (Graph `chatMessage` or webhook), SCP ticket sidebar widget. |

## 13. Risks & open items

- **Portal navigation**: osTicket 1.18 does not render plugin client-apps in the portal
  menu, and the core `apps/dispatcher.php` / `scp/apps/dispatcher.php` entry points use
  relative `require`s that do not resolve from their sub-directory. The plugin therefore
  serves its pages through `ajax.php` (`/ajax.php/itapprovals`, `/scp/ajax.php/itapprovals/admin`).
  The "Approvals" menu item (with a pending count) is injected by a scoped output buffer,
  as there is no client navigation hook; approvers also arrive via email links.
- **SSO for users who never logged in**: the plugin pre-creates approver user records so
  the `auth-oauth2` plugin can match them. Verify in staging that your OAuth2 instance
  signs in existing users (Client registration mode).
- **Email identity drift**: if an employee's osTicket email differs from their Entra
  `mail`/UPN, manager lookup fails → fallback. Simulator exposes this.
- **Agents moving a held ticket manually** bypasses approval; mitigate with hold-dept
  membership.

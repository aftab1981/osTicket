# Test plan — Help Topic rebuild

Run on **staging first**, then on production straight after applying. Record pass/fail and the ticket numbers used. Create test tickets as a test user of an allowed domain (e.g. `it.test@dhrp.com.au`) and delete them at the end (step 10).

| # | Check | How | Expected |
|---|---|---|---|
| 1 | Backup | `ls -lh osticket_backup_*.sql` | Non-zero size; restore command noted in the change record |
| 2 | Snapshot | `ops/helptopics/current_state.md` exists | Lists pre-change topics, forms and filters |
| 3 | Structure | Admin › Manage › Help Topics | 11 parents + 35 sub-categories, all **Active** and **Public**, in the order of `implementation_steps.md` table C; old topics end in **(Legacy)** and are **Disabled** |
| 4 | Client dropdown | Portal › **Open a New Ticket** › Help Topic | 46 entries shown as `Parent / Child` (e.g. `Accounts & Access / Server / VM Remote Access (RDP)`); first category Accounts & Access; last entry `Other / Facilities / Power (office)`; no Legacy topics; no default preselected |
| 5 | Staff dropdown | SCP › Tickets › **New Ticket** › Help Topic | Same 46 entries in the same order |
| 6 | Custom fields load | Choose `Accounts & Access / Server / VM Remote Access (RDP)` | Sections: Ticket Details, Server Details, Justification, Request details. Fields: Server IP / Hostname*, Environment, Business justification*, Access level needed |
| 7 | Required fields block | Fill in only Issue Summary + details, submit | Ticket **not** created; "Server IP / Hostname is a required field", "Business justification is a required field" |
| 8 | HR notice | After choosing any topic | Under Ticket Details: "Please describe your issue. Leave and attendance requests go to HR, not IT."; no Leave / Attendance topic exists |
| 9 | Routing — one ticket per category | Create one ticket for each row below (fill in the required fields) | Ticket lands in the department and priority shown |
| 10 | Clean-up | SCP › open each test ticket › More › **Delete** | All test tickets deleted |
| 11 | Vendor filters | Send test emails to the helpdesk mailbox (or ask the vendor): (a) From name "Microsoft Dynamics 365", subject "Servicing operation status change …"; (b) from a Micron21 address; (c) subject "Stale Ticket Alert …" | Each arrives in **Vendor Notifications**, (a) with priority Low, no auto-response to the sender. Not rejected by "Internal domains only" |
| 12 | Domain policy intact | Email from an outside address, e.g. a Gmail account | Rejected; System Logs show `Ticket rejected (…) by filter "Internal domains only"` |
| 13 | Old tickets | Open any pre-change ticket | Still shows its original help topic, now with "(Legacy)" |
| 14 | Rollback rehearsal *(staging only)* | `mysql … < ops/helptopics/rollback_helptopics.sql`, then `snapshot_state.php` and diff against step 2 | Identical apart from the timestamp line; then re-apply |

**Step 9 routing table** (first sub-category of each category; Security uses its second):

| Category → sub-category | Department | Priority |
|---|---|---|
| Accounts & Access → Server / VM Remote Access (RDP) | IT Support | High |
| Servers & Environments → Server / VM Down or Not Responding | Infrastructure | Emergency |
| Software → Software Installation Request | IT Support | Normal |
| Hardware → Laptop Fault (screen, boot, Wi-Fi, keyboard) | IT Support | High |
| Network & Connectivity → VPN Setup / Not Working | Infrastructure | High |
| Onboarding / Offboarding → Onboarding – New Joiner | IT Support | High |
| Email & Collaboration → Email / Outlook Issue | IT Support | Normal |
| Procurement → Quote / Purchase / Renewal Request | IT Management | Normal |
| Business Applications → D365 / MTM / HCMS / YouTrack Issue | Applications | High |
| Security → Security Concern / Policy / Awareness | Cyber Security | Normal |
| Other → General Enquiry | IT Support | Normal |

(Departments appear as their mapped names if you used `--map`.)

## Local verification already done (osTicket 1.18 test copy)

- Dry run: 100 changes planned; database row counts identical afterwards; no log table left behind.
- Apply, then a second apply: nothing re-created (idempotent).
- Apply → rollback → snapshot diff: **identical** to the pre-change state, including topic order, departments, filters and settings.
- Browser (Edge): checks 4–8 pass on the portal and the staff form.
- Checks 9–12: 11/11 categories route to the right department and priority; the four vendor/system emails land in Vendor Notifications; Gmail is still rejected; internal email is still accepted; all 17 test tickets deleted.
- Remap dry run: read-only (ticket topics unchanged) and produces a per-topic summary plus a detail list.

# Help Topic rebuild — Category → Sub-category

Implements `osticket-helptopics-requirements.md` (owner: Aftab Ahmad) for `https://itsm.dhrp.com.au`:
11 categories, 35 sub-categories, 5 shared forms plus 31 per-sub-category forms,
vendor/system mail filters, manual order, an HR notice, and legacy topics kept but disabled.

## Files

| File | Purpose | Changes data? |
|---|---|---|
| `snapshot_state.php` | Writes `current_state.md`: topics (decoded status, ticket counts), forms, filters, departments, settings, vendor sender domains | No |
| `seed_helptopics.php` | Idempotent, transactional builder using osTicket's own classes. **Dry run by default**; `--apply` to commit | Only with `--apply` |
| `rollback_helptopics.sql` | Undoes an applied seed, driven by `ost_helptopics_seed_log` | Yes (restores) |
| `remap_legacy_tickets.sql` | **Dry run**: suggests a new sub-category for historical tickets from subject keywords. The UPDATE is commented out | No |
| `implementation_steps.md` | Backup/snapshot procedure, script route, and the full Admin Panel click-by-click route with generated tables | — |
| `test_plan.md` | Client and staff acceptance tests (§9) and the local results | — |
| `lib.php` | Single source of truth: taxonomy, fields, shared forms, filters | — |

## Run order on production

```bash
cd /path/to/osticket                                   # run as the web server user
mysqldump --single-transaction -u <user> -p <db> > osticket_backup_$(date +%F_%H%M).sql
php ops/helptopics/snapshot_state.php                  # → ops/helptopics/current_state.md
php ops/helptopics/seed_helptopics.php <options>       # dry run; review the plan
php ops/helptopics/seed_helptopics.php --apply <options>
# then work through test_plan.md
```

## Decisions needed before applying (§10: ask first)

1. **Departments.** For each of IT Support, Infrastructure, Applications, Cyber Security and IT Management: create it, or map it to an existing department (`--map="Infrastructure=<existing>"`). The snapshot shows which exist. Without a decision the script stops and changes nothing.
2. **Vendor Notifications department.** Approve its creation (`--create-vendor-dept`; private, no alerts), or skip the filters for now (`--skip-filters`).
3. **Micron21 domain.** Confirm it from the snapshot's "Vendor senders" table and pass `--micron21-domain=<domain>`. Without it, the filter matches From name "Micron21" or any address containing `@micron21.`.
4. **"+ alert HR" for Onboarding/Offboarding.** osTicket can't copy HR per topic natively. The options are: add HR staff as agents in a team, or use a filter with an email action once the HR address is known. Tell me which.
5. **Legacy remap.** Review the dry-run output of `remap_legacy_tickets.sql` before approving the UPDATE.

## Where the build interprets the requirement

- **Required-ness of shared fields follows §5.** A shared field has one required setting for every topic that uses it. So *Environment* (Server Details) is optional, including for "Server / VM Down", and *Asset tag* is optional, including for Laptop Fault and Slow System. To make either mandatory everywhere, tick Required on that field in the shared form.
- **Justification is required wherever the shared form is attached**, including Application / SaaS Access, where §4 listed it as optional.
- **"Description (R)" sub-categories use osTicket's standard Issue Details field**, which is already required, instead of a duplicate field.
- **Joiner / Leaver shared form** holds the common fields (Full name, Access checklist). The rest of §4.6 is in each sub-category's own form.
- **Parent categories are selectable**, as the requirement asks (Active and Public). A user who picks a category instead of a sub-category gets only the standard fields. If you prefer to force sub-categories, the parents can be made Private: children still show as `Parent / Child`, but parents disappear from the portal list.
- **HR notice** goes in the Ticket Details form instructions, so it appears on the portal and staff New Ticket forms as soon as a topic is chosen. There is no Leave / Attendance topic.
- **Form titles** follow `HT – <Sub-category>` for admins. The Portal Theme plugin shows portal users "Request details" as the section heading instead.
- **Filter order:** the vendor filters run at order 1–3 with "stop on match", ahead of the Internal Access Policy's "Internal domains only" (order 99), so vendor mail is routed rather than rejected.

## Interaction with IT Approvals

The new taxonomy replaces the IT Approvals form-pack topics (Internet / Web Access, VPN, SAP Change, Email / Network), which become Legacy. They aren't on production yet. To require approval for, say, VPN Setup or Port / Firewall, attach an approval matrix to those **new sub-categories** (Admin › Applications › IT Approvals › Configure). The conditional rules can be added to the matching `HT – …` forms.

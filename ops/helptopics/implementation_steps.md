# Implementation steps — Help Topic rebuild (Category → Sub-category)

System: `https://itsm.dhrp.com.au` · osTicket 1.18 · requirement: `osticket-helptopics-requirements.md`

There are two ways to apply this. Both produce the same result.

| Route | When | Time |
|---|---|---|
| **A. Seed script (recommended)** | Normal case. Dry run first, then `--apply`; one transaction, logged, rollback script included | ~10 min |
| **B. Admin Panel, click-by-click** | If scripts can't be run on the server | ~3–4 h |

---

## 0. Before you change anything (both routes)

1. **Version.** Admin Panel › Dashboard › Information shows the osTicket version. It must be **1.10 or later** (nested topics and dynamic forms); the scripts check this too.
2. **Back up the database and files:**
   ```bash
   cd /path/to/osticket
   DB=$(php -r "include 'include/ost-config.php'; echo DBNAME;")
   mysqldump --single-transaction -u <user> -p "$DB" > osticket_backup_$(date +%F_%H%M).sql
   tar -czf osticket_files_$(date +%F).tar.gz /path/to/osticket
   ls -lh osticket_backup_*.sql osticket_files_*.tar.gz     # sizes must be non-zero
   ```
   Restore command, if ever needed: `mysql -u <user> -p "$DB" < osticket_backup_<stamp>.sql`
3. **Table prefix.** `grep TABLE_PREFIX include/ost-config.php` (usually `ost_`). The scripts read it automatically; the two `.sql` files assume `ost_`, so search and replace if it differs.
4. **Snapshot the current state** (read-only):
   ```bash
   php ops/helptopics/snapshot_state.php            # writes ops/helptopics/current_state.md
   ```
   Keep `current_state.md` with the change record. It lists every topic (with ticket counts), form, filter, department, the sort/default settings, and the **vendor sender domains seen in tickets** (confirm Micron21's exact domain from this table).
5. **Departments.** The snapshot marks which required departments are **missing**: IT Support, Infrastructure, Applications, Cyber Security, IT Management and Vendor Notifications. **Decide with the owner** for each one: create it, or map it to an existing department. Nothing is created without that decision.

> 1.18 note: help topics have no `isactive` column. Status is stored in `flags` (2 = Active, 4 = Archived, neither = Disabled). The snapshot decodes it.

---

## Route A — seed script

Run from the osTicket root as the web server user (e.g. `sudo -u www-data`).

```bash
# 1. Dry run: everything inside a transaction, then ROLLED BACK. Prints the full plan.
php ops/helptopics/seed_helptopics.php \
    --map="IT Support=<existing dept>" --map="Infrastructure=<existing dept>"   # only for approved mappings
    # --create-depts          only if creating the missing departments was approved
    # --create-vendor-dept    only if creating "Vendor Notifications" was approved
    # --skip-filters          to leave the vendor mail filters out for now
    # --micron21-domain=<domain from current_state.md>

# 2. Review the plan (≈100 lines: CREATE / ADOPT / LEGACY / SETTING / KEEP).

# 3. Apply: same options + --apply
php ops/helptopics/seed_helptopics.php --apply <same options>
```

- If a required department is neither mapped nor approved for creation, the script **stops before changing anything** and lists the existing departments.
- Re-running is safe. Objects are found by name and only missing ones are added. The managed properties (parent, department, priority, public, active, order) are re-asserted; anything else changed later in the UI (SLA, auto-assignment) is kept.
- Every change is recorded in `ost_helptopics_seed_log`. **Undo:** `mysql -u <user> -p "$DB" < ops/helptopics/rollback_helptopics.sql`. This restores legacy names, status and order, settings and the filter order; it deletes what was created, except objects already used by tickets, which are disabled instead.
- Then run `test_plan.md`.

---

## Route B — Admin Panel, click-by-click

Do the steps in this order. Values come from the tables below. They are generated from the same definitions the script uses, so both routes match exactly.

### B1. Manual topic order (do this FIRST)
Admin Panel › Manage › **Help Topics** › the sort selector at the top of the list = **Manually** (it saves on change).
(In alphabetical mode osTicket re-sorts all topics whenever one is saved.)

### B2. Departments (only those approved)
Admin Panel › Agents › Departments › **Add New Department**. For each approved missing department:
- Name exactly as in the requirement (e.g. `Infrastructure`), Type **Public**, Status **Active**, Save.
- `Vendor Notifications`: Type **Private**, Group Membership / Alerts **Disabled**, Auto-response **off**.

### B3. Shared forms (§5)
Admin Panel › Manage › **Forms** › **Add New Custom Form**
1. Title = form name (e.g. `Server Details`), Instructions empty.
2. For each row in the table: **Add New Field** › Type, Label, Variable (the `code` value), then **Config**:
   - *Required*: tick "Required" for **Required** rows (Visibility: *Optional* or *Required* for Users and Agents).
   - *Choices*: enter one `key:Label` per line exactly as shown; tick *Multiselect* where marked.
   - *Date and Time*: untick "Include time".
3. **Save Changes**.

### B4. Sub-category forms (`HT – <Sub-category>`)
Same as B3 for every form in table B. Topics marked *(Ticket Details only)* in table C need no form: their "Description (R)" is the standard, already-required **Issue Details** field.

### B5. Help Topics (parents, then children, in table C order)
Admin Panel › Manage › **Help Topics** › **Add New Help Topic**
- **Topic**: name exactly as in table C. **Parent Topic**: the category (children only). **Status**: Active. **Type**: Public.
- **Department** and **Priority** as in table C. Leave SLA, Auto-assignment and New ticket number as they are.
- **Forms** tab: keep *Ticket Details* first, then **Add** the forms listed in table C, in that order.
- **Save**. Parent topics: no extra form.

After creating them all, check Manage › Help Topics: the list must show the order of table C. Drag rows if needed; the manual sort mode from B1 allows it.

### B6. Legacy topics (never delete)
For every topic that existed before (see `current_state.md`) and isn't in table C: open it, append ` (Legacy)` to the name, set **Status = Disabled**, Save. Old tickets keep showing their original topic.

### B7. Default topic + HR notice
- Settings › Tickets › *Default Help Topic* = **— None —** (users must choose).
- Manage › Forms › **Ticket Details** › Instructions = `Please describe your issue. Leave and attendance requests go to HR, not IT.` › Save. This shows on the New Ticket page as soon as a topic is chosen. There is **no** Leave / Attendance topic.

### B8. Vendor and system mail filters (§6)
Admin Panel › Manage › **Filters** › **Add New Filter**, for each of the following:

| Filter name | Execution order | Target | Rules — **Match Any** | Actions | Stop processing further on match |
|---|---|---|---|---|---|
| MS Dynamics Notices | 1 | Any | Name *contains* `Microsoft Dynamics` · Subject *contains* `Servicing operation status change` · Subject *contains* `Database operation status change` · Subject *contains* `A service update is scheduled` | Department = Vendor Notifications · Priority = Low · Disable auto-response | ✔ |
| Micron21 | 2 | Any | Name *contains* `Micron21` · Email *contains* `@micron21.` (+ Email *ends with* `@<exact domain>` from `current_state.md`) | Department = Vendor Notifications | ✔ |
| System Alerts | 3 | Any | Subject *contains* `Stale Ticket Alert` | Department = Vendor Notifications · Disable auto-response | ✔ |

If the **Internal Access Policy** plugin is installed, set its filter **"Internal domains only"** to execution order **99**. Otherwise it would reject Microsoft and Micron21 mail before it can be routed. (The plugin creates it at 99; older installs may show 0.)

---

## Reference tables

### A. Shared forms

**Server Details**

| Label | Variable | Type | Required |
|---|---|---|---|
| Server IP / Hostname | `server_host` | Short Answer | **Required** |
| Environment | `environment` | Choices — `dev:Dev` `uat:UAT` `staging:Staging` `prod:Prod` | Optional |

**Asset Details**

| Label | Variable | Type | Required |
|---|---|---|---|
| Asset tag | `asset_tag` | Short Answer | Optional |

**Justification**

| Label | Variable | Type | Required |
|---|---|---|---|
| Business justification | `justification` | Long Answer | **Required** |

**Manager Approval**

| Label | Variable | Type | Required |
|---|---|---|---|
| Manager approval obtained | `manager_approval` | Choices — `yes:Yes` `no:No` | **Required** |
| Manager name | `manager_name` | Short Answer | Optional |

**Joiner / Leaver**

| Label | Variable | Type | Required |
|---|---|---|---|
| Full name | `full_name` | Short Answer | **Required** |
| Access (grant for joiners, revoke for leavers) | `access_items` | Choices — `email:Email` `teams:Teams` `d365:D365` `server:Server / VM` `vpn:VPN` `threatlocker:ThreatLocker` *(multiselect)* | Optional |

### B. Sub-category forms (`HT – <Sub-category>`)

**HT – Server / VM Remote Access (RDP)**

| Label | Variable | Type | Required |
|---|---|---|---|
| Access level needed | `access_level` | Short Answer | Optional |

**HT – Password Reset / Expired / Locked Account**

| Label | Variable | Type | Required |
|---|---|---|---|
| System | `system` | Choices — `ad:Windows / AD` `server:Server` `m365:Email / M365` `d365:D365` `vpn:VPN` `other:Other` | **Required** |
| Username | `username` | Short Answer | **Required** |

**HT – User Creation on Server / VM**

| Label | Variable | Type | Required |
|---|---|---|---|
| User full name | `new_user_name` | Short Answer | **Required** |
| Role / access level | `role_access` | Short Answer | **Required** |

**HT – Application / SaaS Access**

| Label | Variable | Type | Required |
|---|---|---|---|
| Application name | `app_name` | Short Answer | **Required** |
| Access level | `access_level` | Short Answer | Optional |

**HT – Group / Shared Folder / Distribution List**

| Label | Variable | Type | Required |
|---|---|---|---|
| Group or folder name | `group_name` | Short Answer | **Required** |
| Action | `group_action` | Choices — `add:Add` `remove:Remove` | **Required** |

**HT – D365 / UAT Environment Access**

| Label | Variable | Type | Required |
|---|---|---|---|
| Environment | `d365_env` | Choices — `dev:Dev` `uat:UAT` `staging:Staging` `prod:Prod` | **Required** |
| Security role needed | `security_role` | Short Answer | Optional |

**HT – Server / VM Down or Not Responding**

| Label | Variable | Type | Required |
|---|---|---|---|
| Error message | `error_message` | Long Answer | Optional |

**HT – New VM / Server / Environment Request**

| Label | Variable | Type | Required |
|---|---|---|---|
| Purpose | `purpose` | Long Answer | **Required** |
| Environment | `environment` | Choices — `dev:Dev` `uat:UAT` `staging:Staging` `prod:Prod` | **Required** |
| vCPU / RAM / Disk | `sizing` | Short Answer | Optional |
| Operating system | `os` | Short Answer | Optional |
| Required-by date | `required_by` | Date and Time (date only) | **Required** |

**HT – Domain / SSL / DNS / Website**

| Label | Variable | Type | Required |
|---|---|---|---|
| Domain / URL | `domain_url` | Short Answer | **Required** |
| Request type | `dns_request_type` | Choices — `new:New record` `renewal:Renewal` `error:Error` `other:Other` | **Required** |

**HT – Database / Storage / Backup**

| Label | Variable | Type | Required |
|---|---|---|---|
| Database name | `db_name` | Short Answer | Optional |
| Request type | `storage_request` | Choices — `restore:Restore` `backup:Backup` `expand:Expand storage` `remove:Remove` | **Required** |

**HT – Git Repo / Deployment / DevOps**

| Label | Variable | Type | Required |
|---|---|---|---|
| Repo / project name | `repo_name` | Short Answer | **Required** |
| Target environment | `target_env` | Choices — `dev:Dev` `uat:UAT` `staging:Staging` `prod:Prod` | **Required** |

**HT – Software Installation Request**

| Label | Variable | Type | Required |
|---|---|---|---|
| Software name & version | `software_name` | Short Answer | **Required** |

**HT – ThreatLocker Unblock / Approval**

| Label | Variable | Type | Required |
|---|---|---|---|
| Application / file name | `blocked_item` | Short Answer | **Required** |
| Path of the blocked item | `block_path` | Long Answer | **Required** |

**HT – Windows / Office Activation & License**

| Label | Variable | Type | Required |
|---|---|---|---|
| Product | `product` | Short Answer | **Required** |

**HT – Browser Extension Request**

| Label | Variable | Type | Required |
|---|---|---|---|
| Extension name / link | `extension` | Short Answer | **Required** |

**HT – Laptop Fault (screen, boot, Wi-Fi, keyboard)**

| Label | Variable | Type | Required |
|---|---|---|---|
| Fault description | `fault_description` | Long Answer | **Required** |
| Photo | `fault_photo` | File Upload | Optional |

**HT – Accessories (charger, mouse, headset, monitor)**

| Label | Variable | Type | Required |
|---|---|---|---|
| Item | `accessory` | Choices — `charger:Charger` `mouse:Mouse` `keyboard:Keyboard` `headset:Headset` `monitor:Monitor` `battery:Battery` `other:Other` | **Required** |
| Reason | `accessory_reason` | Choices — `new:New` `faulty:Faulty` `lost:Lost` | **Required** |

**HT – Slow System / Disk Space / Memory**

| Label | Variable | Type | Required |
|---|---|---|---|
| Symptoms | `symptoms` | Long Answer | Optional |

**HT – Laptop Replacement / New Laptop**

| Label | Variable | Type | Required |
|---|---|---|---|
| Reason | `replacement_reason` | Long Answer | **Required** |

**HT – VPN Setup / Not Working**

| Label | Variable | Type | Required |
|---|---|---|---|
| Request type | `vpn_request` | Choices — `new:New setup` `notconnecting:Not connecting` | **Required** |
| Operating system | `os` | Short Answer | Optional |
| Error message | `error_message` | Long Answer | Optional |

**HT – Port / Firewall / IP Whitelisting**

| Label | Variable | Type | Required |
|---|---|---|---|
| Source IP | `source_ip` | Short Answer | **Required** |
| Destination IP | `destination_ip` | Short Answer | **Required** |
| Port / protocol | `port_protocol` | Short Answer | **Required** |

**HT – Internet / Wi-Fi Issue**

| Label | Variable | Type | Required |
|---|---|---|---|
| Location | `location` | Choices — `office:Office` `home:Home` | **Required** |

**HT – Onboarding – New Joiner**

| Label | Variable | Type | Required |
|---|---|---|---|
| Designation | `designation` | Short Answer | **Required** |
| Department | `joiner_department` | Short Answer | **Required** |
| Start date | `start_date` | Date and Time (date only) | **Required** |
| Laptop required | `laptop_required` | Choices — `yes:Yes` `no:No` | **Required** |

**HT – Offboarding – Leaver**

| Label | Variable | Type | Required |
|---|---|---|---|
| Last working day | `last_day` | Date and Time (date only) | **Required** |
| Laptop return | `laptop_return` | Choices — `yes:Yes` `no:No` | **Required** |
| Forward mailbox to | `forward_mailbox` | Short Answer | Optional |

**HT – Employee Letters / Records**

| Label | Variable | Type | Required |
|---|---|---|---|
| Request type | `letter_type` | Short Answer | **Required** |

**HT – New Email Account / Shared Mailbox**

| Label | Variable | Type | Required |
|---|---|---|---|
| Mailbox name | `mailbox_name` | Short Answer | **Required** |
| Owner / users | `mailbox_owners` | Short Answer | **Required** |

**HT – SharePoint / OneDrive**

| Label | Variable | Type | Required |
|---|---|---|---|
| Site / folder URL | `site_url` | Short Answer | Optional |

**HT – Quote / Purchase / Renewal Request**

| Label | Variable | Type | Required |
|---|---|---|---|
| Item / service | `item_service` | Short Answer | **Required** |
| Vendor | `vendor` | Short Answer | Optional |
| Quantity | `quantity` | Short Answer | Optional |
| Budget / cost centre | `cost_centre` | Short Answer | Optional |
| Required-by date | `required_by` | Date and Time (date only) | Optional |

**HT – D365 / MTM / HCMS / YouTrack Issue**

| Label | Variable | Type | Required |
|---|---|---|---|
| Application | `application` | Choices — `d365:D365` `mtm:MTM` `hcms:HCMS` `youtrack:YouTrack` `other:Other` | **Required** |
| Environment | `app_environment` | Choices — `dev:Dev` `uat:UAT` `staging:Staging` `prod:Prod` | Optional |
| Steps to reproduce | `steps` | Long Answer | **Required** |

**HT – Report Phishing / Suspicious Email**

| Label | Variable | Type | Required |
|---|---|---|---|
| Attach the suspicious email | `phish_email` | File Upload | **Required** |
| Did you click a link or enter credentials? | `clicked` | Choices — `yes:Yes` `no:No` | **Required** |

**HT – Facilities / Power (office)**

| Label | Variable | Type | Required |
|---|---|---|---|
| Location | `facility_location` | Short Answer | **Required** |

### C. Help Topics (create in this order)

| # | Topic | Parent | Department | Priority | Forms (in order, after Ticket Details) |
|---|---|---|---|---|---|
| 1 | **Accounts & Access** | — | IT Support | Normal | *(none)* |
| 2 | Server / VM Remote Access (RDP) | Accounts & Access | IT Support | High | Server Details, Justification, HT – Server / VM Remote Access (RDP) |
| 3 | Password Reset / Expired / Locked Account | Accounts & Access | IT Support | High | HT – Password Reset / Expired / Locked Account |
| 4 | User Creation on Server / VM | Accounts & Access | IT Support | Normal | Server Details, Manager Approval, HT – User Creation on Server / VM |
| 5 | Application / SaaS Access | Accounts & Access | IT Support | Normal | Justification, HT – Application / SaaS Access |
| 6 | Group / Shared Folder / Distribution List | Accounts & Access | IT Support | Normal | HT – Group / Shared Folder / Distribution List |
| 7 | D365 / UAT Environment Access | Accounts & Access | IT Support | Normal | HT – D365 / UAT Environment Access |
| 8 | **Servers & Environments** | — | Infrastructure | Normal | *(none)* |
| 9 | Server / VM Down or Not Responding | Servers & Environments | Infrastructure | Emergency | Server Details, HT – Server / VM Down or Not Responding |
| 10 | New VM / Server / Environment Request | Servers & Environments | Infrastructure | Normal | HT – New VM / Server / Environment Request |
| 11 | Domain / SSL / DNS / Website | Servers & Environments | Infrastructure | High | HT – Domain / SSL / DNS / Website |
| 12 | Database / Storage / Backup | Servers & Environments | Infrastructure | High | Server Details, HT – Database / Storage / Backup |
| 13 | Git Repo / Deployment / DevOps | Servers & Environments | Infrastructure | Normal | HT – Git Repo / Deployment / DevOps |
| 14 | **Software** | — | IT Support | Normal | *(none)* |
| 15 | Software Installation Request | Software | IT Support | Normal | Justification, Asset Details, HT – Software Installation Request |
| 16 | ThreatLocker Unblock / Approval | Software | IT Support | High | HT – ThreatLocker Unblock / Approval |
| 17 | Windows / Office Activation & License | Software | IT Support | Normal | Asset Details, HT – Windows / Office Activation & License |
| 18 | Browser Extension Request | Software | IT Support | Low | Justification, HT – Browser Extension Request |
| 19 | **Hardware** | — | IT Support | Normal | *(none)* |
| 20 | Laptop Fault (screen, boot, Wi-Fi, keyboard) | Hardware | IT Support | High | Asset Details, HT – Laptop Fault (screen, boot, Wi-Fi, keyboard) |
| 21 | Accessories (charger, mouse, headset, monitor) | Hardware | IT Support | Normal | HT – Accessories (charger, mouse, headset, monitor) |
| 22 | Slow System / Disk Space / Memory | Hardware | IT Support | Normal | Asset Details, HT – Slow System / Disk Space / Memory |
| 23 | Laptop Replacement / New Laptop | Hardware | IT Support | Normal | Asset Details, Manager Approval, HT – Laptop Replacement / New Laptop |
| 24 | **Network & Connectivity** | — | Infrastructure | Normal | *(none)* |
| 25 | VPN Setup / Not Working | Network & Connectivity | Infrastructure | High | HT – VPN Setup / Not Working |
| 26 | Port / Firewall / IP Whitelisting | Network & Connectivity | Infrastructure | Normal | Justification, HT – Port / Firewall / IP Whitelisting |
| 27 | Internet / Wi-Fi Issue | Network & Connectivity | Infrastructure | Normal | HT – Internet / Wi-Fi Issue |
| 28 | **Onboarding / Offboarding** | — | IT Support | Normal | *(none)* |
| 29 | Onboarding – New Joiner | Onboarding / Offboarding | IT Support | High | Joiner / Leaver, HT – Onboarding – New Joiner |
| 30 | Offboarding – Leaver | Onboarding / Offboarding | IT Support | High | Joiner / Leaver, HT – Offboarding – Leaver |
| 31 | Employee Letters / Records | Onboarding / Offboarding | IT Support | Low | HT – Employee Letters / Records |
| 32 | **Email & Collaboration** | — | IT Support | Normal | *(none)* |
| 33 | Email / Outlook Issue | Email & Collaboration | IT Support | Normal | *(Ticket Details only)* |
| 34 | Microsoft Teams Issue | Email & Collaboration | IT Support | Normal | *(Ticket Details only)* |
| 35 | New Email Account / Shared Mailbox | Email & Collaboration | IT Support | Normal | HT – New Email Account / Shared Mailbox |
| 36 | SharePoint / OneDrive | Email & Collaboration | IT Support | Normal | HT – SharePoint / OneDrive |
| 37 | **Procurement** | — | IT Management | Normal | *(none)* |
| 38 | Quote / Purchase / Renewal Request | Procurement | IT Management | Normal | HT – Quote / Purchase / Renewal Request |
| 39 | **Business Applications** | — | Applications | Normal | *(none)* |
| 40 | D365 / MTM / HCMS / YouTrack Issue | Business Applications | Applications | High | HT – D365 / MTM / HCMS / YouTrack Issue |
| 41 | **Security** | — | Cyber Security | Normal | *(none)* |
| 42 | Report Phishing / Suspicious Email | Security | Cyber Security | High | HT – Report Phishing / Suspicious Email |
| 43 | Security Concern / Policy / Awareness | Security | Cyber Security | Normal | *(Ticket Details only)* |
| 44 | **Other** | — | IT Support | Normal | *(none)* |
| 45 | General Enquiry | Other | IT Support | Normal | *(Ticket Details only)* |
| 46 | Facilities / Power (office) | Other | IT Support | Low | HT – Facilities / Power (office) |

---

*Tables A–C are generated from `ops/helptopics/lib.php` (the same definitions the seed script uses).*

# Upgrade runbook — osTicket 1.17.2 → 1.18.4 (with DHRP changes)

System: `https://itsm.dhrp.com.au` · from **v1.17.2** to **v1.18.4** (latest 1.18 release) · change window ≈ 45–60 min

## What the upgrade actually changes

| | |
|---|---|
| **Database** | One patch (`83a22ba2 → 5fb92bef`): widens `ost_plugin.name` and `ost_plugin_instance.name` to `VARCHAR(255)` and updates the schema signature. **No ticket, thread, attachment or user table is touched.** |
| **New data** | The upgrader adds one ticket, "osTicket Upgraded!", from "osTicket Team". Close or delete it afterwards. |
| **Files** | All core code is replaced. `include/ost-config.php` and existing plugins in `include/plugins/` are kept. |
| **Requirements** | **PHP 8.2 or later** (the upgrader refuses to start below 8.2), MySQL 5.5+ / MariaDB. |

Deploy the **official release zip** (`osTicket-v1.18.4.zip`) plus our plugins from branch `feature/dhrp-itsm`. Don't deploy the git branch as a whole: repository code has `display_errors` switched on in `bootstrap.php`. The release zip is the same code (commit `8d38b06`) with production settings.

## Rehearsal (done locally, 24 Sep 2026)

A copy of 1.17.2 was installed and loaded with test data: 36 tickets (open and closed), replies, internal notes, attachments, Unicode text, three email domains, and the Oauth2 plugin set to *End Users Only*. It was then upgraded with exactly the steps below.

| Check | Result |
|---|---|
| Upgrader (web, "Start Upgrade Now") | Completed in 2 steps; schema `5fb92bef…` |
| `ticket_integrity.php verify` | **PASSED**: all 20 ticket-data tables identical; the only new rows are the upgrade notice ticket |
| Row-by-row compare against the restored backup (26 tables) | 0 rows missing or changed (the admin row differs only in last-login fields) |
| Attachments | Download byte-exact after the upgrade |
| Microsoft sign-in (portal) | Still works; plugin settings kept |
| DHRP plugins installed afterwards | Portal theme, agent Microsoft button, domain policy, IT Approvals tables: all OK |
| Help-topic rebuild on the upgraded data | Tickets unchanged; old tickets show "(Legacy)" topics |
| Cron, opening old tickets, PHP error log | No errors |
| Integrity checker tamper test | One altered character → **FAILED** (it catches changes) |
| **Rollback** (1.17.2 files + backup restore) | Back to v1.17.2, tickets identical, portal and SSO work |

---

## A. Before the change day

1. **PHP version of the web server** (not only the CLI): Admin Panel › Dashboard › **Information** shows it. It must be **8.2 or later**.
   - If it's older, upgrade PHP first as a separate change. 1.17.2 ran on PHP 8.2 in the rehearsal (only deprecation notices, which production hides).
   - Recommended extensions: `mysqli gd mbstring intl xml json phar zip` (+ `apcu`, `opcache`). Check with `php -m`.
2. **Local core edits.** Anything changed in core osTicket files is overwritten by the upgrade. Check against the pristine release:
   ```bash
   cd /tmp && curl -LO https://github.com/osTicket/osTicket/releases/download/v1.17.2/osTicket-v1.17.2.zip
   unzip -q osTicket-v1.17.2.zip -d ost1172
   diff -rq ost1172/upload /var/www/osticket | grep -v "include/plugins\|ost-config.php\|Only in /var"
   ```
   The expected output is empty. Save any differences before the upgrade.
3. **Plugins in use.** Admin Panel › Manage › **Plugins**: note each plugin and its file in `include/plugins/` (e.g. `auth-oauth2.phar`). Keep the **same file names**, because osTicket links plugin settings to the install path.
4. **Attachment storage.** Admin Panel › Settings › System (and Plugins): if attachments are stored on disk rather than in the database, note that folder so it's included in the file backup.
5. **Break-glass admin.** Confirm a local admin agent can sign in with a password. You need it for the upgrader; agent SSO is only enabled afterwards.
6. **Stage the tools.** Copy `ops/` from branch `feature/dhrp-itsm` into the osTicket root. It's read-only until used. Download `osTicket-v1.18.4.zip` to the server.
7. **Staging (strongly recommended).** Restore last night's backup into a staging copy and run section B there first.

## B. Change window

Run commands from the osTicket root as the web server user (e.g. `sudo -u www-data`). The paths below assume `/var/www/osticket`.

**B1. Stop new data coming in.** This is what guarantees no ticket is missed during the window.
- Admin Panel › Settings › System › **Helpdesk Status = Offline** › Save. The portal shows "offline" to users.
- Disable the cron job that runs `api/cron.php`: `crontab -e` and comment out the line. Emails **stay in the helpdesk mailbox** and are fetched once cron is back.
- If mail is **piped** (`api/pipe.php`) rather than fetched: the mail server queues and retries. Confirm that with the mail admin, or pause delivery for the window.

**B2. Integrity snapshot** (read-only; reads DB credentials from `ost-config.php`):
```bash
mkdir -p ~/upgrade
php ops/upgrade/ticket_integrity.php snapshot > ~/upgrade/before.json     # prints "Snapshot of N tables, <count> tickets."
```

**B3. Backups.** Database first, then files.
```bash
DB=$(php -r 'define("INCLUDE_DIR",1);define("ROOT_DIR",1);include "include/ost-config.php"; echo DBNAME;')
mysqldump --single-transaction --routines --triggers --default-character-set=utf8mb4 -u <user> -p "$DB" \
  > ~/upgrade/osticket_1172_$(date +%F_%H%M).sql
tail -1 ~/upgrade/osticket_1172_*.sql            # must read "-- Dump completed ..."
tar -czf ~/upgrade/osticket_files_1172_$(date +%F).tar.gz -C /var/www osticket   # + attachment folder if on disk
ls -lh ~/upgrade/                                 # non-zero sizes
```
Optional but best: restore the dump into a scratch DB and run `verify` against it (it must pass) to prove the backup is usable.

**B4. Deploy 1.18.4 files** (keeps `ost-config.php` and existing plugins):
```bash
cd /tmp && unzip -q osTicket-v1.18.4.zip -d ost1184
rsync -a --exclude 'include/ost-config.php' /tmp/ost1184/upload/ /var/www/osticket/
rm -rf /var/www/osticket/setup                    # installer must not be left on the server
# DHRP plugins from branch feature/dhrp-itsm:
cp -r <checkout>/include/plugins/{it-approvals,portal-theme,internal-access} /var/www/osticket/include/plugins/
chown -R www-data:www-data /var/www/osticket
```

**B5. Run the upgrader.** Browse to `https://itsm.dhrp.com.au/scp/` and sign in with the **local admin**. Click "**Start Upgrade Now**", then "**Upgrade Now**". Wait for "*Congratulations! osTicket upgrade has been completed successfully.*"
(CLI alternative: `php manage.php upgrade`.)

**B6. Prove no ticket was lost** before doing anything else:
```bash
php ops/upgrade/ticket_integrity.php verify ~/upgrade/before.json
```
It must end with **`PASSED: every ticket-data row that existed before the upgrade is present and unchanged.`** The `new` column shows 1 for tickets and threads (the upgrade notice). **If it says FAILED, stop and go to section C.**

Then spot-check: Dashboard › Information shows **v1.18.4**; open a few old tickets, including one with an attachment; check the open and closed queues.

**B7. Enable the DHRP changes.**
1. Admin Panel › Manage › Plugins › **Add New Plugin**: install and enable **Portal Theme** (preset DHRP), **Internal Access Policy** (domains `dhrp.com.au`, `armf.com`, `armup.com`; *Entra ID only* on), and **IT Approvals**. Settings are described in each plugin's `README.md`.
2. Agent Microsoft sign-in: follow `ops/sso/AGENT_SSO.md`. Set the Oauth2 instance to *Agents and End Users*, and each agent's email must equal their UPN.
3. The **help-topic rebuild** (`ops/helptopics/README.md`) is best done as a separate change once the upgrade has settled. Its open decisions (departments, Vendor Notifications, Micron21 domain, HR alert, remap) still need answers. It was rehearsed on upgraded data, and tickets are not modified.

**B8. Reopen.**
- Helpdesk Status = **Online**.
- Re-enable the cron line, and run it once by hand: `php api/cron.php`. Queued mailbox emails become tickets.
- Test: send an email from a `@dhrp.com.au` address → a ticket appears; sign in on the portal with Microsoft and open a ticket; an agent signs in with Microsoft.
- Close the "osTicket Upgraded!" ticket.

## C. Rollback

Decide go/no-go **before B8**. While the helpdesk is still offline, rollback loses nothing. After reopening, new tickets would exist only in the new database, so fix forward instead, or export those tickets first.

```bash
# helpdesk still Offline, cron still disabled
rm -rf /var/www/osticket && tar -xzf ~/upgrade/osticket_files_1172_<date>.tar.gz -C /var/www
mysql -u <user> -p -e "DROP DATABASE \`$DB\`; CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4;"
mysql -u <user> -p "$DB" < ~/upgrade/osticket_1172_<stamp>.sql
php ops/upgrade/ticket_integrity.php verify ~/upgrade/before.json     # must PASS
# Helpdesk Online, re-enable cron
```
Rehearsed: this returns v1.17.2 with every ticket identical and SSO working.

## Tools in this folder

| File | What it does |
|---|---|
| `ticket_integrity.php` | `snapshot` / `verify`: hashes every row of 20 ticket-data tables (tickets, threads, entries, events, attachments, files, users, forms, tasks, drafts). Read-only. Reads credentials from `ost-config.php`. Exit code 0 means passed, 1 means failed. |
| `fingerprint.sh` | Row count + `CHECKSUM TABLE` for every table, as a coarse before/after overview (`MYSQL=mysql sh fingerprint.sh -u <user> -p <db>`). |

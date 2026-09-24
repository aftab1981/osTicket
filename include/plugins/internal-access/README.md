# Internal Access Policy — osTicket plugin

Keeps the helpdesk internal to DHRP and its companies.

| Rule | How it is enforced |
|---|---|
| Only **@dhrp.com.au**, **@armf.com**, **@armup.com** can open tickets | The plugin creates and maintains a native osTicket ticket filter, **"Internal domains only"**, that rejects any sender whose email doesn't match the allowed domains. It runs for portal, email and API tickets, and each rejection is written to Admin Panel › Dashboard › System Logs. Look-alike domains such as `dhrp.com.au.evil.com` and `notdhrp.com.au` are rejected. |
| Only those domains can sign in or register | Any portal sign-in, including first-time Microsoft SSO registration, from another domain is signed out immediately. An account created in that same sign-in is deleted, and the attempt is logged. |
| **Microsoft Entra ID only** for portal users | Password sign-in, self-registration (`account.php`) and password reset (`pwreset.php`) are refused server-side. The sign-in page shows only the "Sign in with Microsoft" button and a note. |

Agents (the staff panel) are not affected.

## Install

1. Copy `include/plugins/internal-access/` to the server.
2. Admin Panel › Manage › Plugins › Add New Plugin › *Internal Access Policy* › Install.
3. Add an instance, set it **Active**, and check the settings:
   - **Allowed email domains:** `dhrp.com.au`, `armf.com`, `armup.com` (one per line)
   - **Microsoft sign-in only:** on
   - **Message for other domains:** "This service desk is for DHRP, ARMF and ARMUP staff only."
4. Save, then enable the plugin. The ticket filter appears under Admin Panel › Manage › Filters. Don't edit it there; change the domains in the plugin settings and the filter updates automatically.

## Required osTicket settings

| Setting | Value | Why |
|---|---|---|
| Admin Panel › Settings › Users › *Registration Method* | **Public** | Needed so a first Microsoft sign-in can create the user automatically. The plugin blocks local self-registration and other domains. |
| Settings › Users › *Registration Required* (to create tickets) | **Yes** | Guests can't open tickets without signing in. |
| auth-oauth2 plugin (Microsoft) | Enabled for **Clients** (and Agents if wanted), single-tenant app | Entra is the identity source. A single-tenant app also blocks accounts from other tenants. |
| Admin Panel › Emails › *Default System Email* | **helpdesk@dhrp.com.au** | "From" and reply-to address for notifications, including IT Approvals emails. |

## Testing (verified locally)

- Email and API tickets from gmail.com and contoso.com are rejected and logged. Tickets from dhrp.com.au, armf.com and ARMUP.com (any case) are accepted.
- The sign-in page shows no password fields. Password POSTs, `account.php?do=create` and `pwreset.php` redirect back to sign-in with an explanation.
- A sign-in from a non-allowed domain is signed out and shown the policy message. A dhrp.com.au user signs in normally.

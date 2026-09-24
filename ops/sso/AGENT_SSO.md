# Agent sign-in with Microsoft (Entra ID)

System: `https://itsm.dhrp.com.au` · osTicket 1.18 · official **Oauth2 Client** plugin (`auth-oauth2`)

## Why agents don't see the Microsoft button

The agent login (`/scp/login.php`) only shows external sign-in buttons for staff authentication backends. Each Oauth2 Client instance has an **Authentication Target**:

| Target | Portal (users) | Agent login (`/scp`) |
|---|---|---|
| End Users Only | ✔ | ✘ ← the likely setting today |
| Agents Only | ✘ | ✔ |
| Agents and End Users | ✔ | ✔ |

When the portal shows "Sign in with Microsoft" and `/scp` doesn't, the instance is set to **End Users Only**.

## Fix (Admin Panel, about 10 minutes)

1. **Break-glass first.** Confirm that one local admin agent can sign in with a password (for example `ostadmin`). Keep it; SSO doesn't turn off password login for agents.
2. **Admin Panel › Manage › Plugins › Oauth2 Client › Instances ›** the Microsoft instance ›
   - **Config** tab: *Authentication Target* = **Agents and End Users**.
   - *Name* = `Microsoft`. The button reads "Sign in with *Name*".
   - Check the three URLs use your **tenant ID**, not `/common/`:
     - `https://login.microsoftonline.com/<TENANT_ID>/oauth2/v2.0/authorize`
     - `https://login.microsoftonline.com/<TENANT_ID>/oauth2/v2.0/token`
     - Resource owner details: `https://graph.microsoft.com/v1.0/me`
   - *Redirect URI*: `https://itsm.dhrp.com.au/api/auth/oauth2`
   - *Username attribute*: `userPrincipalName` · *Email attribute*: `mail`
   - Save. The instance must be **Enabled**.
   - Alternative: add a second instance targeting **Agents Only** with the same app registration. Use this if you want agents and users kept separate.
3. **Entra admin centre › App registrations ›** the osTicket app › **Authentication**: confirm the Web redirect URI is exactly `https://itsm.dhrp.com.au/api/auth/oauth2`. It's the same one the portal already uses, so usually there's nothing to add.
4. **Agents must already exist in osTicket.** Agent accounts aren't auto-created. For each agent, go to **Admin Panel › Agents ›** the agent, and set **Email** (or **Username**) to exactly their Entra **UPN**, e.g. `firstname.lastname@dhrp.com.au`. If there's no match, the agent sees an "unable to find account" error.
5. Test in a private window: go to `https://itsm.dhrp.com.au/scp/`, click **Sign in with Microsoft**, and you should land on the agent dashboard.

## Local verification (test copy)

- The agent login shows **Sign in with Microsoft** as the primary DHRP button, above the local-account form, labelled "or use a local account". Desktop and mobile both checked.
- Clicking the button returns `302` to `https://login.microsoftonline.com/<tenant>/oauth2/v2.0/authorize` with `client_id`, `redirect_uri=…/api/auth/oauth2`, `response_type=code` and `state`.
- The portal login shows the same button (backend `oauth2.user`); the agent button uses `oauth2.agent`.
- The look comes from the Portal Theme plugin (`css/staff-login.css`, `css/portal.css`). The button uses Microsoft's four-square logo.

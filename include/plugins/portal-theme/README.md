# Portal Theme — osTicket plugin

Modern, responsive look for the osTicket 1.18 client portal: full-width header bar,
tabbed navigation, card layout, cleaner forms, tables and buttons, and a mobile layout.
The IT Approvals pages pick up the same colours automatically.

- No core files are modified — disable the plugin to return to the stock theme.
- Staff control panel (SCP) is not affected.

## Install

1. Copy `include/plugins/portal-theme/` to the server (already in this repository).
2. Admin Panel › Manage › Plugins › **Add New Plugin** › *Portal Theme* › Install.
3. Open it › **Instances** › Add New Instance (name `Portal Theme`), status **Active**.
4. Set **Brand colour** (hex, e.g. your corporate blue) and Save. Enable the plugin.
5. Upload your logo under Admin Panel › Settings › Company › Logos (client portal logo).

Optional: **Additional CSS** is appended after the theme for small overrides.

## DHRP preset (recommended)

Set **Brand preset = DHRP** in the plugin settings. That one choice applies everything below:
- the DHRP logo and favicon (bundled in `presets/dhrp/`)
- colours, the Inter font and pill buttons
- the top strip: "DHRP IT Service Desk · Melbourne · Sydney · Lahore" and helpdesk@dhrp.com.au, with no phone number
- the DHRP footer (about text, helpdesk@dhrp.com.au, Service Desk / Support / Company links, the three offices, copyright and Acknowledgement of Country)
- the branded **agent sign-in page** (`scp/login.php` and agent password reset): a DHRP brand panel beside a clean sign-in card

Any field filled in on the settings page overrides the preset value. Enter `-` in a field to
hide a preset value. To change the bundled logo, replace `presets/dhrp/logo.png` (a wide PNG
with a transparent background; ideally supply a high-resolution version from DHRP marketing).

## DHRP brand settings (manual equivalent)

Matched to [dhrp.com.au](https://dhrp.com.au/):

| Setting | Value |
|---|---|
| Brand colour | `#0006b3` |
| Ink colour | `#090c18` |
| Font | Inter |
| Button shape | Pill |
| Top strip — left | `DHRP IT Service Desk · Melbourne · Sydney · Lahore` |
| Top strip — right | `helpdesk@dhrp.com.au` |

Also:

- **Logo:** Admin Panel › Settings › Company › Logos → upload the DHRP logo (wide PNG,
  transparent background) as the client portal logo.
- **Helpdesk name:** Admin Panel › Settings › System › Helpdesk Name = `DHRP IT Service Desk`;
  Company name = `DHRP`.
- **Landing page:** Admin Panel › Manage › Pages › *Landing* → switch the editor to HTML
  and use:

  ```html
  <p class="pt-eyebrow">DHRP IT Service Desk</p>
  <h1>How can we help <span class="pt-accent">you today?</span></h1>
  <p>Raise an IT request, request access or report an issue. Every request gets a ticket
  number, and requests that need approval are routed to your manager automatically
  &mdash; track it all here.</p>
  ```

  `pt-eyebrow` gives the small letter-spaced label with the blue dot; `pt-accent` colours
  text in the brand blue; `pt-badge-pill` makes a rounded outline badge.

Inter loads from Google Fonts; if the portal can't reach it the theme falls back to
Segoe UI.

## How it works

osTicket plugins bootstrap before the page header exists and 1.18 has no client-side
header hook, so the plugin adds a scoped output buffer that inserts one `<style>` block
before `</head>` on client-portal HTML pages only (not `scp/`, `api/`, `setup/`, file
downloads or AJAX JSON). Colours are CSS variables (`--pt-accent`, `--pt-accent-dark`,
`--pt-accent-soft`) derived from the brand colour setting.

## Files

```
plugin.php        manifest
portaltheme.php   plugin class, config, style injection
css/portal.css    the theme
```

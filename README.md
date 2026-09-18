# Ticket Report & Sign — GLPI 10-12 plugin (key: `glpiticketreportsign`)

*[Léelo en español](README.es_ES.md)*

Adds a **Report** tab to every Ticket. From that tab the assigned
technician (or one of their group supervisors) can:

1. **Generate a PDF report** containing the ticket header, every
   follow-up and every solution, with their attached images
   inlined.
2. **Sign the report** by drawing a signature on any device — the
   same browser tab supports mouse on a desktop and touch on a
   phone, no QR code involved.
3. **Send the report by email** to a recipient (e.g. the customer
   on a different device); the email contains a single-use,
   time-limited link to a signing page that works without a GLPI
   login.
4. The signed PDF is stored as a normal GLPI **Document** linked to
   the ticket, so it also shows up in the ticket's **Documents**
   tab.

## Requirements

* GLPI 10.0 – 12.0
* PHP 8.2+
* PHP extensions: `gd` (used to render the signature into the PDF)
* FPDF — **bundled** under `pdf/` (the full upstream distribution,
  including the `font/` directory, ships with the plugin; nothing
  to install).
* Vendored JS libs (see `public/vendor/.gitkeep`):
  * pdf.js 4.x
  * signature_pad 5.x

## Install

```sh
cd plugins/
git clone <this-repo> glpiticketreportsign
# Drop the JS vendor files into public/vendor/ — see that folder's
# .gitkeep for filenames.
```

In **Setup → Plugins**, install + enable **Ticket Report & Sign**.

Both are dropped on uninstall; the underlying Document rows the
plugin produced are not touched, so previously signed PDFs remain
attached to their tickets.

## Authorization

Only users that match one of the following on a given ticket can
see the Report tab and act on its reports:

* Directly assigned technician (`glpi_tickets_users.type = ASSIGN`)
* A user with `glpi_groups_users.is_manager = 1` in **a)** any group
  assigned to the ticket, or **b)** any group containing one of the
  assigned technicians.

The right `plugin_glpiticketreportsign_report` (READ / CREATE / UPDATE)
gates whether the tab is visible at all; the per-ticket rule is
enforced in `Security\Authorizer`.

## Limitations / known sharp edges

* **Unicode in PDFs.** FPDF core fonts are CP1252 only, so non-Latin
  characters are transliterated by `iconv`. Swap FPDF for tFPDF (or
  add a Unicode TTF) if you need Cyrillic, Greek, CJK, etc.
* **Image formats.** Only PNG/JPEG/GIF document attachments are
  inlined. Other formats are listed by name only.
* **Email delivery.** Goes through GLPI's standard SMTP / mailer
  configuration — make sure that's set up under **Setup →
  Notifications → Configuration of email follow-ups**.

## Layout

```
glpiticketreportsign/
├── setup.php / hook.php / composer.json / glpiticketreportsign.xml
├── ajax/                           # generate / sign_submit / send_sign_email / pdf_bytes
├── front/                          # download.php (auth) / sign.php (token)
├── install/                        # (reserved)
├── locales/                        # en_GB.po / es_ES.po
├── pdf/                            # vendored FPDF distribution
├── public/
│   ├── css/ticketreport.css        # asset filename kept as-is; only the plugin key changed
│   ├── js/ticketreport.js          # unused leftover from an earlier design (see note below)
│   ├── js/sign-page.js             # public signing page
│   └── vendor/                     # drop pdf.js + signature_pad here
└── src/
    ├── Install/Installer.php
    ├── Integration/TicketTabBase.php    # tab logic (shared)
    ├── Integration/TicketTab.php        # leaf: GLPI <12 ($rightname untyped)
    ├── Integration/TicketTab.glpi12.php # leaf: GLPI 12+ ($rightname typed `string`)
    ├── Mail/Mailer.php
    ├── Pdf/ReportPdf.php           # FPDF renderer
    ├── Pdf/ReportStorage.php       # Document + Document_Item linking
    ├── Profile.php                 # rights
    ├── Report/ReportRecord.php     # CommonDBTM logic (shared)
    ├── Report.php                  # leaf: GLPI <12 ($rightname untyped)
    ├── Report.glpi12.php           # leaf: GLPI 12+ ($rightname typed `string`)
    └── Security/
        ├── Authorizer.php          # per-ticket "assigned tech / supervisor"
        └── SigningToken.php        # HMAC-signed, single-use email tokens
```

# PDF Regenerator

Standalone PHP application: upload an odd-looking PDF → extract its data → pick a premium template → review & edit → generate a new, professional PDF.
No database — CSV authentication and JSON metadata on the server's file system.

> **Status:** complete — all 8 phases are built (login, upload, extraction, Review & Edit, template choice with live preview and mapping, website profiles with logo & colors, step-by-step generation, Generated Files, Dashboard, Settings, storage cleanup). See [`PROGRESS.md`](PROGRESS.md) for the phase log and test results.

**Contents:** [Requirements](#requirements) · [Install](#install) · [Web server](#web-server-document-root--public) · [Configuration](#configuration) · [Users & passwords](#users--passwords) · [Permissions](#file-permissions) · [Fonts](#fonts) · [Storage cleanup](#storage-cleanup-cron--task-scheduler) · [Using the app](#using-the-app) · [Adding a template](#adding-a-template) · [Adding a source profile](#adding-a-source-profile) · [Security](#security) · [Troubleshooting](#troubleshooting) · [Tests](#tests) · [Technical reference](#technical-reference) · [Project structure](#project-structure)

---

## Requirements

| Requirement | Notes |
| --- | --- |
| PHP **8.1+** (8.3 recommended) | XAMPP 8.1+ works on Windows |
| Extensions | `mbstring`, `gd` (with FreeType), `fileinfo`, `zlib`, `intl`, `json`, `session`, `ctype` |
| `poppler-utils` (`pdftotext`, `pdfinfo`) | strongly recommended (positioned text extraction, faster page checks); without it the built-in PHP reader (smalot) is used and results are the same for the supported reports |
| Composer 2 | installs `smalot/pdfparser` and `mpdf/mpdf` — or unzip the pre-built `vendor-bundle.zip` |
| Web server | Apache 2.4 or Nginx + PHP-FPM for production; `php -S` for local testing only |
| Writable by PHP | `storage/` (all sub-folders) and `config/users.csv` (password changes) |
| Memory / time | `memory_limit` ≥ 256M, `max_execution_time` ≥ 60 for large PDFs |

`php bin/system-check.php` (and **Settings → System check** in the app) reports all of the above.

## Install

### Windows (XAMPP) — quickest

1. Copy the `pdf-regenerator` folder anywhere (e.g. `D:\PDF-generator\pdf-regenerator`).
2. Double-click **`start-server.bat`**. It finds `C:\xampp\php\php.exe`, checks the PHP version, unpacks `vendor-bundle.zip` on first run, asks you to create the first user if none exists, prints the system check, starts the server with 12 MB upload limits and opens <http://localhost:8000>.
3. Double-click **`run-tests.bat`** to run the whole test suite on your machine.

### Any OS

```bash
cd pdf-regenerator
composer install --no-dev --optimize-autoloader   # or: unzip vendor-bundle.zip
php bin/create-user.php                           # first account (min. 10-character password)
php bin/system-check.php                          # PHP, extensions, PDF engine, permissions, limits
php -d upload_max_filesize=12M -d post_max_size=13M -S localhost:8000 -t public
```

Open <http://localhost:8000>.

> `php -S` handles one request at a time and is meant for local use. Production needs Apache or Nginx with the **document root pointing at `public/`**.

### Composer vs. `vendor-bundle.zip`

- `composer install` downloads the libraries and the full mPDF font set.
- `vendor-bundle.zip` is a ready `vendor/` folder for machines without Composer. It ships only the DejaVu fonts of mPDF (the templates use Inter + DejaVu), which keeps it small.

## Web server (document root = `public/`)

Only `public/` may be reachable by URL. `app/`, `config/`, `storage/`, `stock-templates/`, `source-profiles/`, `resources/`, `bin/`, `tests/` and `vendor/` must stay outside the document root. The `.htaccess` deny files inside them are defence in depth only — never rely on them alone.

### Apache 2.4

```apache
<VirtualHost *:80>
    ServerName reports.example.com
    DocumentRoot "/var/www/pdf-regenerator/public"

    <Directory "/var/www/pdf-regenerator/public">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Everything above public/ is off-limits even if the docroot is changed by mistake
    <Directory "/var/www/pdf-regenerator">
        Require all denied
    </Directory>

    php_value upload_max_filesize 12M
    php_value post_max_size 13M

    ErrorLog  ${APACHE_LOG_DIR}/pdfreg-error.log
    CustomLog ${APACHE_LOG_DIR}/pdfreg-access.log combined
</VirtualHost>
```

Use HTTPS in production (e.g. `certbot --apache`); the session cookie gets the `Secure` flag automatically on HTTPS. With PHP-FPM instead of `mod_php`, put the two upload limits in `php.ini` / the pool file instead of `php_value`.

**Shared hosting without a vhost:** see *Inside a folder of an existing website* below.

### At the root of a domain or subdomain (cPanel / shared hosting) — easiest

Step-by-step guide in Bangla: [`DEPLOY-BANGLA.md`](DEPLOY-BANGLA.md).

- **Subdomain (recommended):** extract the package to `~/pdf-regenerator` and create the subdomain (e.g. `pdf.goodtechies.com`) with **Document Root** `pdf-regenerator/public`. Nothing but `public/` is reachable.
- **Empty domain root:** extract the *contents* of the package into `public_html/`. The root `.htaccess` serves `public/` and blocks the rest. Do **not** do this where WordPress (or another site) already lives — use a subdomain or the folder setup below.

What the package does by itself, so no extra hosting settings are needed:

| Hassle | Handled by |
| --- | --- |
| upload limit / memory / time | `public/.user.ini` + root `.user.ini` (PHP-FPM, CGI, LiteSpeed) and `php_value` blocks in `.htaccess` that only apply with `mod_php` (never a 500 on FPM hosts) |
| first user | the deploy package ships `config/users.csv` with the existing account (same login as on the PC) |
| poppler path | `pdftotext_path = 'auto'` — found automatically, otherwise the pure-PHP reader is used |
| daily cleanup cron | `auto_cleanup = true` — the cleanup runs by itself once a day after a page request |
| SSL certificate (AutoSSL / Let's Encrypt) | `.well-known/` is never rewritten |
| wrong permissions / missing extension / missing `vendor/` | a "one more setup step" page says exactly what to fix instead of a blank error |
| hidden files | `.htaccess`, `.user.ini` … are never served |

Tested with Apache 2.4 + PHP-FPM and with Apache 2.4 + `mod_php` in both layouts (login, upload, profile, extraction, generation, download, 413 for an 11 MB file, 403/404 for `config/`, `storage/`, `app/`, `.user.ini`).

### Inside a folder of an existing website (e.g. WordPress on cPanel)

The app works in a sub-folder such as `https://goodtechies.com/pdf-tool/` next to a WordPress site: every link, redirect and asset URL is built from the folder, and the session cookie is limited to that folder. WordPress's own `.htaccess` does not interfere (it only handles paths that are not real files or folders). Tested with Apache + a WordPress `.htaccess`.

**A — recommended (project outside `public_html`, one symlink)**

```text
/home/USER/pdf-regenerator/          ← the whole project (not reachable by URL)
/home/USER/public_html/pdf-tool  →   /home/USER/pdf-regenerator/public   (symlink)
```

```bash
cd ~ && unzip pdf-regenerator-deploy.zip          # creates ~/pdf-regenerator
ln -s ~/pdf-regenerator/public ~/public_html/pdf-tool
```

Open `https://goodtechies.com/pdf-tool/`.

**B — no terminal (whole project inside `public_html`)**

Upload/extract the project to `public_html/pdf-tool/`. The project's root `.htaccess` serves everything from `public/`, so `config/`, `storage/`, `app/` … cannot be opened by URL (they return 403/404). Links stay `https://goodtechies.com/pdf-tool/…` (no `/public/` in them). Needs `mod_rewrite` (standard on cPanel / LiteSpeed); without it the folder is fully blocked. Option A is still safer because the files are not under the web root at all.

**Both:** set the PHP version to 8.1+ (cPanel → *MultiPHP Manager*). Upload limits, the first user (bundled `users.csv`) and the daily cleanup are handled by the package (see the table above); `php bin/create-user.php` in *Terminal* adds more users.

### Nginx + PHP-FPM

```nginx
server {
    listen 80;
    server_name reports.example.com;
    root /var/www/pdf-regenerator/public;
    index index.php;

    client_max_body_size 12m;
    charset utf-8;

    location / {
        try_files $uri $uri/ =404;
    }

    location ~ \.php$ {
        try_files $uri =404;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param PHP_VALUE "upload_max_filesize=12M \n post_max_size=13M";
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_read_timeout 120s;
    }

    # hidden files (.htaccess, .git …)
    location ~ /\. { deny all; }

    # cache static assets
    location ~* \.(css|js|svg|png|jpg|woff2)$ {
        expires 7d;
        access_log off;
    }
}
```

Nginx ignores `.htaccess`, so the `root` line above is what protects `app/`, `config/` and `storage/`. Security headers are sent by PHP; you may add HSTS here once HTTPS works.

## Configuration

Everything tunable is in `config/app.php` (read in code with `config('key')`).

| Key | Default | Meaning |
| --- | --- | --- |
| `app_name` | `PDF Regenerator` | shown in the title bar |
| `admin_email` | `admin@example.com` | shown on the login page ("contact your administrator") |
| `max_upload_size` | 10 MB | largest source PDF (keep PHP's `upload_max_filesize` a little higher) |
| `max_pdf_pages` | 50 | bomb guard: page limit |
| `max_extracted_text_bytes` | 5 MB | bomb guard: extracted text limit |
| `max_logo_size` | 2 MB | largest logo (JPG, PNG, WebP, SVG) |
| `retention_uploads_days` | 30 | cleanup: source PDFs and their working data |
| `retention_generated_days` | 90 | cleanup: generated PDFs |
| `retention_temp_days` | 7 | cleanup: progress jobs, `storage/tmp`, old sessions, dated logs, unused draft logos |
| `auto_cleanup` | `true` | run the cleanup by itself at most once a day, after a page request (stamp `storage/logs/.auto-cleanup`); set `false` when a cron job does it |
| `max_top_pages` | 15 | cap for the "Top Performing Pages" list |
| `pdf_font` | `inter` | PDF body font (`dejavusans` fallback) |
| `session_timeout` | 1800 | idle seconds before logout |
| `login_max_attempts` / `login_lockout_seconds` | 5 / 900 | lockout per username + IP (also used by the password form) |
| `login_max_attempts_per_ip` | 20 | extra guard per IP |
| `timezone` | `Asia/Dhaka` | all displayed times |
| `date_format` / `display_date_format` | `M d, Y h:i A` / `M d, Y` | timestamps / date-only values |
| `pdftotext_path` | `auto` | `auto` = first of `/usr/bin`, `/usr/local/bin`, `/opt/homebrew/bin` (Windows: `C:\poppler\Library\bin`, `C:\Program Files\poppler\Library\bin`), else smalot; or a full path; `''` = always smalot. Windows example: `C:\poppler\Library\bin\pdftotext.exe` (`pdfinfo` is looked up next to it) |
| `mpdf_temp_path` | `storage/tmp/mpdf` | mPDF font cache |
| `storage_path`, `template_path`, `source_profile_path`, `font_path`, `users_csv` | project folders | move them only together with the permissions below |

## Users & passwords

Accounts live in `config/users.csv`:

```csv
user_id,username,password_hash,name,email,status
USR_8f92ac12,admin,$2y$12$....,Administrator,admin@example.com,active
```

- **Create** accounts only with `php bin/create-user.php` (interactive; asks for username, name, e-mail and a password of at least 10 characters). There is no registration page.
- **Deactivate** an account by setting `status` to `inactive` (the user is signed out on the next request). Do not delete lines of users whose files you want to keep — their data lives under `storage/*/USR_xxx/`.
- **Change password:** each user can do it in **Settings → Change password** (current password required; 5 wrong attempts lock the form for 15 minutes). After a change the session id is regenerated and all other devices of that user are signed out. PHP must be able to write `config/users.csv` for this; the file is rewritten under an exclusive lock.
- **Forgotten password:** an administrator runs `php bin/create-user.php` for a new account, or replaces the `password_hash` column with the output of `php -r "echo password_hash('New password here', PASSWORD_DEFAULT), PHP_EOL;"`.
- Passwords are stored only as `password_hash()` values. `config/users.example.csv` shows the format (its hash belongs to a random, unpublished password).
- Keep `config/users.csv` out of version control and backups that others can read.

## File permissions

PHP (the web server user, e.g. `www-data`) needs write access to `storage/` and `config/users.csv`, and read access to everything else.

**Linux**

```bash
cd /var/www/pdf-regenerator
sudo chown -R root:www-data .
sudo find . -type d -exec chmod 750 {} \;
sudo find . -type f -exec chmod 640 {} \;
sudo chown -R www-data:www-data storage
sudo chmod -R u+rwX,g+rwX storage
sudo chown www-data:www-data config/users.csv
sudo chmod 660 config/users.csv   # password changes rewrite this file
sudo chmod 750 bin/*.php
```

With SELinux: `sudo chcon -R -t httpd_sys_rw_content_t storage config/users.csv`. Run `bin/cleanup-storage.php` as the same user (`sudo -u www-data php …`) so new files keep the right owner.

**Windows (XAMPP / IIS)** — the account that runs PHP (your own account with `start-server.bat`; `IUSR`/app-pool identity with IIS; the Apache service account with XAMPP as a service) needs **Modify** on `storage\` and `config\users.csv`:

```bat
icacls "D:\PDF-generator\pdf-regenerator\storage" /grant "%USERNAME%":(OI)(CI)M
icacls "D:\PDF-generator\pdf-regenerator\config\users.csv" /grant "%USERNAME%":M
```

Settings → System check shows "writable" / "not writable" for every folder.

## Fonts

- `resources/fonts/` holds the static Inter TTFs (SIL OFL 1.1, `OFL.txt`) registered in mPDF as `inter` (regular/bold), `intersemibold` and `interextrabold`. The browser pages use `public/assets/fonts/` (Inter woff2).
- DejaVu Sans (bundled with mPDF) draws symbols (✓, arrows) and replaces Inter automatically if the TTF files are missing.
- **Another font:** copy its TTF files to `resources/fonts/`, register them in `App\Pdf\PdfGenerator` (the `fontdata` array), set `pdf_font`, and delete `storage/tmp/mpdf/` so the font cache is rebuilt.
- Non-Latin text (Bangla, Arabic …) needs a font that contains those glyphs, e.g. Noto Sans Bengali, registered the same way; mPDF then needs `autoScriptToLang` / `autoLangToFont` enabled in `PdfGenerator`.

## Storage cleanup (cron / Task Scheduler)

With `auto_cleanup` on (default) nothing has to be scheduled: the first page request after 24 hours runs the cleanup after the response is sent. A cron job / scheduled task is still possible (set `auto_cleanup` to `false` then, or keep both — a shared lock prevents overlaps).

```bash
php bin/cleanup-storage.php --dry-run   # list what WOULD be deleted, change nothing
php bin/cleanup-storage.php             # delete expired files
php bin/cleanup-storage.php --quiet     # summary only (for cron mails)
```

| What | Removed when | Also removed |
| --- | --- | --- |
| uploaded source PDFs | older than `retention_uploads_days` | index entry, extracted working data, draft logo, draft PDF |
| generated PDFs | older than `retention_generated_days` | `generated.json` entry |
| temporary files | older than `retention_temp_days` | progress jobs, job work folders, draft PDFs, stale sessions, dated log files, draft logos of deleted reports, orphan files |
| **never** | — | `blueprints.json` (website profiles), `assets/` (profile logos), the index files `uploads.json` / `generated.json`, `login-attempts.json`, `.htaccess`, the mPDF font cache |

Folders emptied by the run are removed. A lock file (`storage/tmp/.cleanup.lock`) prevents two runs at the same time. Every deletion is written to `storage/logs/cleanup-YYYY-MM-DD.log`. Exit codes: `0` ok, `1` failed, `2` unknown option.

**Linux cron** (daily at 03:15, as the web server user):

```cron
15 3 * * * cd /var/www/pdf-regenerator && /usr/bin/php bin/cleanup-storage.php --quiet >> storage/logs/cron.log 2>&1
```

(`crontab -u www-data -e`)

**Windows Task Scheduler** (daily at 03:15; run in an administrator Command Prompt once):

```bat
schtasks /Create /TN "PDF Regenerator cleanup" /SC DAILY /ST 03:15 /RL LIMITED ^
  /TR "\"C:\xampp\php\php.exe\" \"D:\PDF-generator\pdf-regenerator\bin\cleanup-storage.php\" --quiet"
```

Test it with `schtasks /Run /TN "PDF Regenerator cleanup"`; remove it with `schtasks /Delete /TN "PDF Regenerator cleanup" /F`. Or use the Task Scheduler window: *Create Basic Task → Daily → Start a program* with program `C:\xampp\php\php.exe` and arguments `bin\cleanup-storage.php --quiet`, *Start in* = the project folder.

## Using the app

| Page | What it does |
| --- | --- |
| **Login** | username + password; 5 failures lock for 15 minutes |
| **Dashboard** | live counts (uploaded PDFs, website profiles, generated files, total), the latest files with View / Download / Open in editor, quick actions |
| **Upload PDF** | drag & drop or choose a file → validation → website profile step (existing / new website / keep as is) → extraction |
| **Uploaded files** (on Upload PDF) | search, view, rename, delete, open in the editor |
| **Review & Edit** | edit every extracted value, tick/untick rows, add custom fields, choose the template, colors and logo, live preview, draft PDF, map template fields |
| **Generate** | 4 real steps with progress: read data → build template → create PDF → save |
| **Generated Files** | search, view, download, rename, delete, back to the editor |
| **Stock Templates** | stock templates with thumbnails and palettes |
| **Settings** | account info, change password, read-only system check |

Empty lists show a friendly empty state with the next action; failed requests show the server's message and a retry; buttons show a spinner while busy.

## Adding a template

1. Copy an existing folder in `stock-templates/` to a new id (lower-case letters, digits and dashes), e.g. `stock-templates/blue-invoice/`.
2. Edit the files:

```text
stock-templates/<id>/
    template.html     body fragment with placeholders (mPDF markup allowed, e.g. <htmlpagefooter>)
    style.css         PDF styles (injected by the renderer; mPDF CSS subset)
    config.json       see below
    preview.png       thumbnail, ~600 px wide capture of the first page (optional)
    preview-full.png  every page of a sample report stacked, 600 px wide (optional; the large preview on Stock Templates)
    preview.css       browser-only fixes for the live preview (optional)
    assets/           images used as assets/… ; assets/logo.png|jpg = default logo
```

```json
{
    "name": "Blue Invoice",
    "description": "One-line description shown on the template card",
    "page_size": "A4",
    "orientation": "portrait",
    "page_height": "fixed",
    "design_width_px": 0,
    "margins": { "top": 18, "right": 18, "bottom": 22, "left": 18, "footer": 9 },
    "fields": ["title", "date", "client_name", "line_items", "total", "custom_fields"],
    "defaults": { "footer_right": "Thank you for your business" },
    "palettes": {
        "blue": { "label": "Blue", "primary": "#2563eb", "primary_dark": "#1e40af", "primary_bright": "#60a5fa",
                  "cover_start": "#172554", "cover_mid": "#2563eb", "cover_end": "#1e3a8a" }
    },
    "default_palette": "blue",
    "logo_boxes": { "brand": [30, 11] },
    "sections": { "items": ["line_items", "total"], "notes": ["description", "custom_fields"] },
    "source_profiles": []
}
```

- `design_width_px`: width of the HTML design the template was made from (e.g. `980` for a 980 px web page; `0` = off). The PDF page then gets that width (at 96 px per inch — 980 px = 259.3 mm) and **every length in `style.css` and in `style`/`width`/`height` attributes (mm, pt, px) grows by the same factor** (980 px on a 210 mm page → × 1.2347), so the PDF at 100 % zoom is exactly as wide and as big as the HTML page. Write the template in `page_size` units as usual; the page height keeps the `page_size` proportion (cover) or follows `page_height`. The Woodford template uses `980`.
- `page_height`: `fixed` (default — every page has the `page_size` height and long content flows onto the next page) or `fit` — every `<div class="page">` section gets **one page exactly as tall as its content**, like the sections of an HTML design (no empty space below short sections; long sections get a taller page). The width, margins, background and footer still come from the template's `inner` named page; the part before the first `<div class="page">` (e.g. the cover) gets a first page as tall as that part (never taller than `page_size`). The Woodford template uses `fit`.
- `fields` may only use keys from `App\Data\FieldDictionary` (unknown keys are logged and ignored). They drive the field-mapping panel.
- `sections` `{name: [keys…]}` — the template gets `section_<name>` = `"1"` when at least one of those keys has data, so a whole part can be left out: `{{#section_items}} <div class="page">…</div> {{/section_items}}`. **No data** = empty, `0`, `0%`, `0 s`, `0 ms`, `—`, or a list without included rows. Page numbers count only the printed pages.
- `card_groups` `{name: {class, keys, per_row | stack, first_class, last_class}}` — boxes that disappear one by one when their value is missing (a real `0` stays). Grid groups get `card_<name>_<key>` = `"<class>-<boxes in the row>"` (+ `" gap"`) and `brk_<name>_<key>` = `"1"` where a new row starts; rows are balanced (4 → 2 + 2). Stack groups get `"<class>"` plus `first_class` / `last_class`. Example: `{{#brk_glance_fcp}}<div class="clear"></div>{{/brk_glance_fcp}}{{#card_glance_fcp}}<div class="card {{ card_glance_fcp }}">…</div>{{/card_glance_fcp}}`.
- Colors: `theme_success` (+ `theme_success_bright`, `_line`, `_bg`, `_fill`) is the "good / done" color — `#34d399` unless a palette has a `success` key or the user picks one under *Custom colors*. `assets/check.svg` and `assets/pill-green-*.svg` are recolored with it. `footer_left` / `footer_right` are printed without `https://`.
- `logo_boxes` `{box: [max width, max height]}` in mm → the template can use `{{ logo_brand_width }}` / `{{ logo_brand_height }}` in `style="…"`.
- `source_profiles` lists the source profile ids this template is recommended for (first match is pre-selected).
- Placeholders: see [Template syntax](#template-syntax). Only color and logo-size keys are allowed inside `<style>` / `style="…"`.
- The template appears immediately on the Stock Templates page and in the editor. Test with **Preview** and **Draft PDF** before generating; `php tests/run-tests.php templates` validates every template folder.

## Adding a source profile

A source profile teaches the extractor one PDF family (e.g. a report tool's weekly export). Without a profile the generic detectors still extract `Label: value` pairs, tables, dates and amounts.

1. Upload a sample PDF and open it once — the extracted lines are shown under "Other text in the PDF" in the editor, which helps to write rules.
2. Create `source-profiles/<id>.json`:

```json
{
    "id": "acme-invoice",
    "name": "ACME invoice export",
    "fingerprint": { "all_of": ["ACME Billing", "Invoice number"] },
    "sections": [
        { "id": "head",  "anchor": null },
        { "id": "items", "anchor": "^Items$" }
    ],
    "fields": [
        { "section": "head", "type": "line_after", "match": "^ACME Billing$", "key": "client_name" },
        { "section": "head", "type": "regex", "pattern": "^Date: (.+)$", "groups": { "date": 1 }, "transform": ["date_iso"] }
    ],
    "lists": [
        { "section": "items", "type": "row_regex", "key": "line_items",
          "pattern": "^(.+?)\\s+(\\d+)\\s+\\$([\\d.,]+)$", "columns": ["name", "quantity", "total"] }
    ]
}
```

3. Keys (and list columns) must exist in `App\Data\FieldDictionary`; to recommend a template for this family, add the profile id to that template's `source_profiles`; an invalid profile is skipped and the reason is written to `storage/logs/extraction-*.log`. Rule and transform types are listed in [Extraction](#extraction). `source-profiles/wp-maintenance-weekly.json` is a complete real example.
4. Add a fixture PDF and a test in `tests/unit/` (see `ExtractionUnitTest.php`) so the profile keeps working.

## Security

- Web root is `public/` only; every other folder is outside it (plus deny `.htaccess` files).
- Sessions in `storage/sessions/`, cookie `HttpOnly; SameSite=Lax` (+ `Secure` on HTTPS), strict mode, id regenerated at login and at password change, 30-minute idle timeout.
- A password change signs out every other session of that user (a signature of the password hash is kept in the session).
- CSRF token on every form and every mutating AJAX request (`_csrf` field or `X-CSRF-Token` header); failures return 419.
- Login lockout: 5 failures → 15 minutes per username + IP, plus 20 per IP; separate lockout for the password form. Generic "Invalid username or password." message; timing equalised for unknown users.
- Logout is POST + CSRF only.
- Uploads: size, extension, `%PDF-` signature, MIME, PDF bomb guard; random server-side names; per-user folders; every file access checks the owner (another user's id → 404).
- Logos: extension, MIME and `getimagesize()` must agree.
- Output: all values HTML-escaped; JSON in pages is escaped; CSS values are whitelisted (`#rrggbb`, `12.5mm`).
- Headers: CSP (`script-src 'self'`), `nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy`, `no-store` on private pages.
- External programs (`pdftotext`, `pdfinfo`) run with argument arrays (no shell) and a timeout.
- Logs never contain passwords, hashes or tokens.

## Troubleshooting

| Problem | Fix |
| --- | --- |
| Blank page / HTTP 500 | Look at `storage/logs/php-YYYY-MM-DD.log` and `app-*.log`; run `php bin/system-check.php`. Most often a missing extension (`mbstring`, `gd`, `intl`, `fileinfo`). |
| "Class … not found" / vendor missing | Run `composer install` or unzip `vendor-bundle.zip` into the project folder. |
| You see the file list or PHP source of the project | The document root is wrong — point it at `public/`. |
| Upload fails for files over ~2 MB | Raise `upload_max_filesize` (12M) and `post_max_size` (13M) in `php.ini` / vhost; Nginx: `client_max_body_size 12m`. |
| "The file is too big" but it is small | `max_upload_size` in `config/app.php`. |
| "This PDF has no selectable text" | Scanned PDF — OCR it first (e.g. with a PDF editor), then upload again. |
| Extracted values are wrong or empty | Install poppler (`apt install poppler-utils`, or poppler for Windows + `pdftotext_path`), check which profile matched in the editor, fix the values in Review & Edit, or add a source profile. |
| Settings shows "PDF engine: smalot" | `pdftotext_path` is not executable — fine, just slower on large files. |
| PDF generation fails / takes long | `memory_limit` ≥ 256M, `max_execution_time` ≥ 60; check `storage/logs/generation-*.log`; make sure `storage/tmp/mpdf` is writable. After changing fonts delete `storage/tmp/mpdf/`. |
| Boxes (□) instead of characters in the PDF | The font lacks those glyphs — see [Fonts](#fonts). |
| "Your session has expired" too often | Raise `session_timeout`; on shared hosting make sure `storage/sessions/` is writable (the app does not use the system session folder). |
| "Security check failed" (419) | The page was open too long or cookies are blocked — reload the page and try again. |
| Password change says the account file cannot be written | Give PHP write access to `config/users.csv` ([Permissions](#file-permissions)). |
| Locked out after wrong passwords | Wait 15 minutes, or delete the matching entries in `storage/logs/login-attempts.json`. |
| Logo is refused | JPG, PNG, WebP or SVG, ≤ `max_logo_size`, a real image (not a renamed file). SVG needs a width/height or viewBox and no DOCTYPE. WebP needs GD with WebP support (XAMPP has it). |
| Cleanup does nothing | Run with `--dry-run` to see the reasons; files are only removed after the retention days; check `storage/logs/cleanup-*.log`. |
| `php` is not recognised (Windows) | Use `C:\xampp\php\php.exe` or `start-server.bat`, which finds it. |
| Port 8000 is busy | Close the other server or run `php -S localhost:8080 -t public`. |

## Tests

```bash
php tests/run-tests.php              # everything (unit + HTTP + CLI)
php tests/run-tests.php unit
php tests/run-tests.php http
php tests/run-tests.php extraction   # extraction + template suites
php tests/run-tests.php editor       # Review & Edit suites
php tests/run-tests.php templates    # template choice / mapping / preview suites
php tests/run-tests.php profiles     # website profiles, logo & colors suites
php tests/run-tests.php generation   # generation progress & generated files suites
php tests/run-tests.php final        # dashboard, settings, password change, cleanup suites
```

On Windows double-click `run-tests.bat`. The runner uses a throw-away sandbox (`tests/.tmp/`) and starts its own `php -S` servers, so real users and storage are never touched. Tests that need poppler are skipped when it is not installed.

---

## Technical reference

### Uploads

- Upload page: drag & drop or **Choose File** — the file is sent immediately with a real progress bar, then validated.
- Checks, in order: PHP upload error code → size (`max_upload_size`, 10 MB) → file name / `.pdf` extension (dangerous double extensions such as `x.php.pdf` are refused) → `%PDF-` signature → MIME type (fileinfo) → **PDF bomb guard** (`max_pdf_pages` = 50, `max_extracted_text_bytes` = 5 MB, password-protected and damaged files refused).
- Bomb guard engine: poppler (`pdfinfo` + `pdftotext`, when `pdftotext_path` is executable) or smalot/pdfparser. With smalot the text size is first estimated from the decoded content streams in linear time, so crafted files cannot tie up the server.
- Files are stored as `storage/uploads/USR_xxx/src_<12 hex>.pdf`; the original name is display metadata only. Index: `storage/metadata/USR_xxx/uploads.json` (locked writes).
- Rename changes the display title only. Delete removes the PDF, its index entry and its extracted working data.
- View / Download go through `public/preview.php` (session + ownership, `nosniff`, `inline` or `attachment`). Another user's id always returns 404.
- The pre-built `vendor/` bundle ships only the DejaVu fonts of mPDF (the templates use Inter + DejaVu); `composer install` restores the full font set.

### Extraction

Pipeline: **positioned text → cleaning → source profile → rules → generic detectors → normalization → `source_src_xxx.json`**.

- **Engines** (`app/Pdf/Extract/`): `PopplerPdfExtractor` runs `pdftotext -bbox-layout` (argument array, no shell, 20 s timeout) when `pdftotext_path` is executable; otherwise `SmalotPdfExtractor` walks the page content itself (q/Q/cm transforms, text matrices, font widths, its own ToUnicode reader) — needed because the reference PDF positions every text run through nested `cm` transforms. If the preferred engine fails on a file, the other one is tried. Both produce identical results for the Woodford reference PDF.
- **Text cleaning** (`TextCleaner`): lines = runs on the same baseline (±2 pt), a gap ≥ 24 pt starts a new cell, word gaps ≥ 0.15 em become spaces, whitelisted word-join repair (`updatesto` → `updates to`), NFC, dash/space normalisation, de-hyphenation, page-number footers dropped.
- **Source profiles** (`source-profiles/*.json`): one JSON file per PDF family, matched by fingerprint phrases. Sections are found in any order and may be missing; `"page_start": true` means the anchor must be the first line of a page (so a sub-heading with the same words is ignored). Any rule may add `"after"` / `"before"` line patterns to use only part of its section (e.g. plugin rows between "Last plugin updates" and "Last theme updates"); `"optional": true` on a list rule means "often absent — do not ask for review". Rule types: `cell_after_label`, `cell_above_label`, `line_after`, `regex`, `last_line`, `contains`; list rules: `bullets`, `row_regex`, `values_above_labels`, `fixed_rows`; transforms: `int`, `decimal1`, `decimal2_percent`, `decimal3`, `seconds1`, `strip_trailing_zero_percent`, `score_0_100`, `date_iso`, `title_case`, `yes_no_online`, `traffic_label_short`, `sentence` (capital first letter), `sort_desc:<column>`, `limit:<config key or number>`. Profiles are validated when loaded — a rule may only write keys from `App\Data\FieldDictionary`; an invalid file is skipped and logged. **Adding a PDF family = adding one JSON file.**
- **Report variants:** `wp-maintenance-weekly` reads every report of that tool (tested with the Woodford and PLC World PDFs): reports without traffic pages, with another section order, with plugin **and theme** update lists.
- **Generic detectors** (no profile, or values a profile did not fill): `Label: value` lines and dashboard rows `Label | value | Label | value` (known labels → canonical keys — including maintenance-report labels such as Sessions, Unique Visitors, Uptime, PageSpeed Score, FCP/LCP/TTI/CLS/TBT, Plugins/Themes updated, Last backup, WordPress/PHP version — others → `custom_*` fields), table-like rows → `line_items`, dates, amounts, e-mail / phone / URL, and the most prominent line as `title`. Everything is flagged for review; unused lines are listed under “Other text in the PDF”.
- **Normalization** (`DataNormalizer`): canonical keys and labels, `source` per value (`profile_rule`, `kv`, `derived`, `inferred`, `default`, …), derived values (`reporting_period`, `security_status`, `backup_status`, `uptime_note`, `maintenance_tasks_count`, `footer_left` = website address • Confidential Maintenance Report), the plugin-count cross-check, and template defaults. Icon-only values (green check marks) are `inferred` and marked **Please confirm**.
- **Scanned PDFs** get the OCR notice; nothing is guessed.
- Data is saved per user as `storage/metadata/USR_xxx/source_src_xxx.json` with an untouched `original` and an editable `working` copy.

### Templates & PDF generation

```text
stock-templates/<id>/
    template.html     body fragment (mPDF markup allowed, e.g. <htmlpagefooter>)
    style.css         injected by the renderer
    config.json       name, description, page_size, orientation, fields, defaults, palettes, source_profiles,
                      default_palette, margins {top,right,bottom,left,footer} in mm,
                      logo_boxes {"cover": [max width, max height]} in mm → logo_cover_width / logo_cover_height
    preview.png       optional thumbnail (600 px wide capture of the cover)
    preview-full.png  optional large preview (all pages of a sample report)
    preview.css       optional browser-only CSS for the live preview
    assets/           images referenced as assets/… (resolved to file paths for mPDF)
```

#### Template syntax

Built-in engine, nothing else is supported:

| Syntax | Meaning |
| --- | --- |
| `{{ key }}` | value, always HTML-escaped; unknown keys render empty |
| `{{#list}} … {{/list}}` | repeat for every row; `{{ @index }}` = row number; keys not on the row are read from the outer data |
| `{{#key}} … {{/key}}` | show once when the scalar value is not empty and not `0` |
| `{{^key}} … {{/key}}` | show when the value is empty / `0` / an empty list |

Only color keys (`theme_*`, `*_color`) and logo size keys (`logo_<box>_width` / `_height`) may be used inside `<style>` or `style="…"`; only valid `#rrggbb` values and sizes like `12.5mm` are printed there. `assets/logo.jpg|png` is the template's default logo.
`config.json` → `fields` must use keys from `FieldDictionary`; unknown keys are logged and ignored. `palettes` hold `primary`, `primary_dark`, `primary_bright`, `cover_start`, `cover_mid`, `cover_end`; soft/faint tints are blended in PHP.

**mPDF notes** (learned while converting the Woodford design): no grid/flex/`var()`/`rgba()`; multi-column rows are floats with fixed widths (content-box) or tables; block backgrounds inside table cells are not drawn (use cell backgrounds or background images); do **not** put `size:` inside a named `@page` rule (mPDF loops); use named pages (`page: inner`) so overflow pages keep their background; `<thead>` repeats on continuation pages.

**Fonts:** `resources/fonts/` holds the static Inter TTFs (SIL OFL, `OFL.txt`) registered as `inter` (R/B), `intersemibold`, `interextrabold`. DejaVu Sans (bundled with mPDF) draws symbols and replaces Inter when the files are missing. mPDF's font cache lives in `storage/tmp/mpdf/<font set>/`.

**Design width** (`design_width_px`): `PdfGenerator` multiplies the page format, the margins and every `mm`/`pt`/`px`/`cm`/`in` value inside `<style>` and in `style`, `width`, `height` and `sheet-size` attributes before rendering (unitless numbers such as SVG coordinates are left alone).

**Fitted page heights** (`page_height: fit`): `PdfGenerator` renders twice — first every section on a 2 m tall probe page to read where it ends (named anchors), then with a `<pagebreak sheet-size="…">` per section; if a section still spills onto a second page it grows by a few mm and is rendered again (max. 4 tries).

**Generation** (`GenerationService` → `PdfGenerator`): working data → template data (display formats, derived colors, PageSpeed ring PNG drawn with GD and deleted afterwards) → HTML → mPDF → `storage/generated/USR_xxx/<Website> Maintenance Report <14 Sept 2026>.pdf` (website profile name, the report kind, the reporting end date or today; ` 2`, ` 3` … when taken) → `generated.json` → download through `preview.php`.

### Review & Edit API

`public/editor.php` shows the extracted data as an editor (rendered by `assets/js/editor.js` from a JSON model embedded in the page). Every change is saved on its own through `public/data.php`:

```text
POST data.php            (session + X-CSRF-Token; JSON body or form fields)
{ "id": "src_…", "action": "set_value", "key": "website_name", "value": "Woodford Oil" }
→ { ok, message, data: { model, result, saved_at }, errors }
```

| Action | Fields | Effect on the `working` copy |
| --- | --- | --- |
| `load` | – | current model |
| `set_value` / `set_label` | `key`, `value` / `label` | validated value (source `manual`) / name of a custom field |
| `confirm` / `recalculate` | `key` | clear “Please confirm” / use the calculated value again |
| `include` / `include_many` | `key` + `included` / `items[]` + `included` | tick or untick rows (unticked rows are left out of the report) |
| `remove` / `restore` | `key` | Removed tab |
| `add_field` / `move_field` | `label`, `value` / `key`, `before` | new `custom_*` field / order inside its group |
| `list_set` / `list_add` / `list_include` / `list_remove` / `list_restore` / `list_move` / `list_confirm` | `list`, `rid`, `column`, `value`, `before`, `included` | list rows (row id `rid`) |
| `reset` | – | `working` = `original` (template / colour choices kept) |

- `App\Editor\DocumentEditor` applies one operation atomically (`JsonStore` lock) and re-runs the calculated values; `FieldValidator` checks each type (numbers, percentages, scores, times with units, dates, web addresses, e-mail, phone, amounts). Invalid input → HTTP 422 with a friendly message; unknown keys or rows → 409; another user's file → 404.
- Values reach the page only as JSON inside `<script type="application/json">` with `<`, `>`, `&`, quotes escaped, and are inserted with `textContent` / `input.value`; the template engine escapes them again for the PDF.
- Removed (`removed: true`) and unticked (`included: false`) rows never reach the template or the calculated values. Custom fields are available to templates as `custom_fields` (+ `custom_fields_count`); the Woodford template prints them as **Additional Details**.

### Template choice, live preview & mapping

| Endpoint | Purpose |
| --- | --- |
| `data.php` `set_template` (`template`) / `set_mapping` (`field`, `source` or `-`) | choose template; choose which data feeds a template field (`-` = leave empty) |
| `live-preview.php?id=` | HTML preview of the working copy (same-origin frame, CSP `default-src 'none'`); uses the same design width scale, fitted pages, left-out sections and page footers as the PDF |
| `draft.php` (POST `action=draft`) | real mPDF draft → `storage/tmp/drafts/USR_xxx/<id>.pdf`, served by `preview.php?type=draft` |
| `asset.php?kind=thumb&t=` / `?kind=tpl&t=&f=` | template thumbnail / template asset (login required, images only) |

- `ReportComposer` is the single pipeline for PDF, draft and preview: derived values → template defaults → `FieldMapper` → theme → system images → `TemplateRenderer`.
- `FieldMapper` suggests a source for each template field (exact key, normalized key or label, related keys, similar custom labels) unless a choice is stored in `field_mappings[template][field]`.
- In the preview context the renderer removes mPDF-only tags (`htmlpagefooter`, `sethtmlpage…`), maps the Inter fonts to the web font and appends the template's `preview.css`.
- Stock templates: `woodford-weekly-maintenance` (WordPress maintenance reports), `simple-light-report` (any PDF; details, line items, summary).

### Website profiles, logo & colors

```text
storage/metadata/USR_xxx/blueprints.json          website profiles (never cleaned up)
storage/metadata/USR_xxx/assets/{bp_id}/logo.*    profile logos (never cleaned up)
storage/metadata/USR_xxx/drafts/{src_id}/logo.*   logo uploaded for one report (moved on generation)
```

| Endpoint | Purpose |
| --- | --- |
| `extract.php` + `profile_mode=existing&profile=bp_…` / `profile_mode=new&website_name=…&website_url=…` / `profile_mode=keep` | website profile chosen before extraction (applied to the extracted data) |
| `blueprints.php` GET · POST `action=rename|delete` | list / rename / delete profiles (session + CSRF + owner) |
| `logo.php` POST multipart `action=upload, id, logo` | JPG / PNG / WebP / SVG ≤ `max_logo_size`: extension, MIME and `getimagesize()` must agree; WebP is stored as PNG (transparency kept); SVG is rebuilt by `SvgSanitizer` (only drawing elements; no scripts, event attributes, external links, `foreignObject`, DOCTYPE/entities, CSS imports) and served with a sandbox CSP |
| `data.php` `set_palette`, `set_custom_colors` (`accent`, `cover`), `remove_logo`, `restore_logo`, `apply_profile`, `set_new_website`, `dismiss_profile_hint` | branding of the working copy |
| `asset.php?kind=logo&bp=…` / `&src=…` | profile / draft logo, owner only |

- **Lifecycle:** a new website is stored in the report only; the first successful generation creates the profile (`BlueprintManager::record`) and moves the draft logo; later generations update template, colors, mapping, remembered texts and `last_used_at`. Deleting a profile removes its logo folder, never generated PDFs.
- **Pre-applied from a profile:** template, palette or custom colors, logo, field mapping, website name and remembered texts (`text_defaults`, PDF values win).
- **Custom colors:** `#rrggbb` accent + cover; darker/lighter shades are computed in PHP; covers with luminance > 0.45 show a readability warning.

### Generation progress & Generated Files

```text
POST generate.php  action=start, id=src_…                     → job created, "Reading PDF data" done
POST generate.php  action=step, job=job_…, step=template|pdf|save
POST generate.php  action=generate, id=src_…                  → all steps in one request
GET  progress.php?job=job_…                                    → {job: {status, next, steps[{key,label,state,ms,time}]}}
```

- Jobs live in `storage/progress/{job}.json`; work files (data snapshot, HTML, PDF) in `storage/tmp/jobs/USR_xxx/{job}/` and are removed when the job ends. Steps run strictly in order (checked under a file lock) and only for the job's owner; the session is released before any work starts.
- Why one request per step: PHP's built-in server (`php -S`, used by `start-server.bat`) handles one request at a time, so a progress poll could never run next to a long generation. With one request per step the dialog is truthful on every server.
- `downloads.php` — Generated Files page; `?format=json` for Refresh; POST `action=rename` (title only) / `action=delete` (file + entry). View / download go through `preview.php?type=generated`.

### Dashboard & Settings

| Endpoint | Purpose |
| --- | --- |
| `dashboard.php` | counts from `uploads.json`, `generated.json`, `blueprints.json`; latest files (uploaded + generated) with actions |
| `settings.php` GET | account card, password form, `SystemCheck::run()` report (read-only) |
| `settings.php` POST `action=password` (`current_password`, `new_password`, `confirm_password`) | `AuthManager::changePassword()`; HTML → redirect with a flash message, JSON → `{ok, message, data: {errors}}`; 422 on validation errors |

- The session stores a short signature of the password hash (`pw_sig`); when it no longer matches the account file, that session is ended — this is how other devices are signed out.
- Password-form failures use their own lockout key, so a locked form never blocks a normal login.

## Project structure

```text
public/            web root: login, dashboard, upload, editor, templates, downloads, settings + JSON endpoints, assets/
app/Core/          Config, JsonStore, Logger, Process, Response, SessionManager, SecurityManager, SystemCheck, View
app/Auth/          AuthManager (login, password change), CsvUserRepository, User, LoginResult
app/Files/         UserStorage, UploadRepository, PdfUploader, SourceFiles, FileStreamer, presenters,
                   GeneratedRepository, GeneratedFileManager, StorageCleaner
app/Pdf/           PdfInspector (bomb guard), PdfGenerator (mPDF), Extract/ (poppler + smalot extractors, TextCleaner)
app/Data/          FieldDictionary (canonical keys), DocumentPresenter
app/Editor/        DocumentEditor, FieldValidator, EditorPresenter, TemplateChoice, BrandingChoice, EditorException
app/Extraction/    SourceProfileManager, ProfileRuleEngine, GenericDetector, DataNormalizer, Transforms, ExtractionService, SourceDataRepository
app/Templates/     TemplateManager, PlaceholderEngine, TemplateRenderer, TemplateData, PaletteManager, RingImage, FieldMapper
app/Generation/    GenerationService, ProgressTracker, ReportComposer
app/Profiles/      BlueprintManager, LogoStore, Branding, ProfileException
app/Views/         layouts, pages, partials
app/Helpers/       e(), url(), asset(), csrf_field(), icon() …
bin/               create-user.php, system-check.php, cleanup-storage.php
config/            app.php, users.csv (+ users.example.csv)
source-profiles/   extraction rules per PDF family (wp-maintenance-weekly.json)
stock-templates/   premium templates (woodford-weekly-maintenance, simple-light-report)
resources/fonts/   Inter TTF files for mPDF (+ OFL.txt)
docs/              phase3-woodford-mapping.md (PDF field → template field table)
storage/           uploads, generated, metadata, progress, tmp, sessions, logs
tests/             unit, HTTP and CLI tests (lib/: runner helpers, PdfFactory; fixtures/woodford.pdf)
start-server.bat   Windows: start locally  ·  run-tests.bat: run the tests
```

## Credits

- Inter typeface — SIL Open Font License 1.1 (`public/assets/fonts/OFL.txt`, `resources/fonts/OFL.txt`)
- DejaVu fonts (bundled with mPDF) — Bitstream Vera / DejaVu license
- mPDF (GPL-2.0-only) and smalot/pdfparser (LGPL-3.0), installed with Composer
- Lucide icons — ISC License

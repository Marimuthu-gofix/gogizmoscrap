# The Hostinger site (PHP)

Hostinger shared hosting runs PHP, not Python, inside `public_html`. This
folder is the part of the buyback tracker that lives there:

- `index.php` + `php/`: the PHP edition of the dashboard. Same page, same
  styles and script as the Python dashboard. It reads the MySQL table
  directly and hands the scrape buttons to GitHub Actions.
- `public/`: the dashboard's styles, script and icons (copies of
  `../static/`; `tests/test_buyback.py` checks they match).
- `.htaccess`: maps the page's routes and keeps `php/` private.
- `../hostinger_api/`: the price store API. On the server it is the `api/`
  folder next to `index.php`.

Scraping never happens on Hostinger. The Python scraper (GitHub Actions,
Render or your PC) posts prices to `/api`; this site only stores and shows
them.

## Deploy

**Automatic (git push).** The GitHub workflow
`.github/workflows/deploy-hostinger.yml` uploads these files to the server
whenever a push to `main` changes `hostinger_site/` or `hostinger_api/`, and
can be run by hand from the Actions tab. It uploads only changed files and
never touches `api/config.php` or anything else on the server. Setup, once:

1. hPanel > Files > FTP Accounts: create an FTP account whose directory is
   the site's `public_html` (for example a subdomain such as
   `buyback.your-domain.com`), and note the host, user name and password.
2. GitHub > repository > Settings > Secrets and variables > Actions: add the
   secrets `FTP_SERVER` (host name or IP, without `ftp://`), `FTP_USERNAME`
   and `FTP_PASSWORD`, and the variable `SITE_URL` (for example
   `https://buyback.your-domain.com`).
3. Push. The run ends by checking `/api/health` and the dashboard page.

If the host refuses an encrypted connection, add a repository variable
`FTP_PROTOCOL` = `ftp`. If the FTP account's directory is not the site's
`public_html`, set the variable `FTP_SERVER_DIR` to the right folder.

**By hand (zip).**

```powershell
python hostinger_site/make_zip.py
```

This writes `buyback_hostinger_site.zip` in the repository root. In hPanel >
Files > File Manager open `public_html`, upload the zip and extract it
there, replacing files. The zip has no `config.php` and no keys, so the
`api/config.php` already on the server is left alone.

First time only: copy `api/config.example.php` to `api/config.php` and fill
in the database lines and `api_key` (see `api/SETUP.txt`).

Then open the site. The dashboard should load with the pill
"Hostinger (live) · MySQL".

| Open | Expect |
|---|---|
| `https://YOUR-SITE/` | the dashboard (sign-in first) |
| `https://YOUR-SITE/api/health` | `{"status":"ok"}` |
| `https://YOUR-SITE/api/SETUP.txt` | 403 Forbidden |
| `https://YOUR-SITE/php/data.php` | 403 Forbidden |

## Sign-in and roles

Nobody sees the dashboard without signing in.

| Role | Can do |
|---|---|
| Admin | See prices, download Excel, start scrapes, manage accounts |
| User | See prices and download Excel |

- **First visit.** With no accounts yet, the site shows "Create the admin
  account". It asks for a setup code, which is the `api_key` from
  `api/config.php`, so only the site's owner can claim it. Do this right
  after uploading.
- **More people.** The admin opens the user menu (top right) > Accounts to
  add people, switch them between Admin and User, set a new password for
  them, or delete them. The last admin cannot be removed or demoted.
- **Own password.** Everyone can change theirs under the user menu >
  Change password.
- Accounts are rows in the `buyback_users` table of the same database;
  passwords are stored as hashes only. A session ends after 8 hours without
  use. Eight wrong passwords in ten minutes pause sign-in for that visitor.
- The price API in `api/` is separate: scrapers keep using the `X-API-Key`
  header and are not affected.

Forgot the only admin password? In phpMyAdmin delete the admin's row from
`buyback_users`. With no admin left, the next visit offers the setup page
again, protected by the same setup code.

## Where the scrape buttons run

**GitHub Actions (recommended).** Add a token to `api/config.php` and every
scrape button starts the repository's "Full scrape" workflow for the chosen
platform, brand and model. The page shows the run's progress and reloads
when it finishes.

```php
'github_token' => 'github_pat_...',
'github_repo' => 'Marimuthu-gofix/gogizmoscrap',
```

Create the token on GitHub: Settings > Developer settings > Personal access
tokens > Fine-grained tokens > Generate new token. Repository access: only
this repository. Permissions: **Actions: Read and write**. Copy the token
into `config.php` on the server only; it never goes into git.

A run takes a minute to start while GitHub prepares a machine. A full scrape
of both platforms takes about 50 minutes. Only one scrape runs at a time.

**Render app (alternative).** Without `github_token`, the buttons are
forwarded to `'scraper_url' => 'https://...'` (a Render app running
`dashboard.py`). With neither set, the buttons explain what to add.

The dashboard caches the computed comparison in `php/cache/` and refreshes
it whenever the table changes, so a page load is one small query.

Requirements: PHP 8.0 or newer with `pdo_mysql`, `mbstring` and `zip` (all
on by default on Hostinger). Without `zip` the Excel export falls back to
CSV.

## Try it on a PC

With PHP installed (for example XAMPP) and a `hostinger_api/config.php`
pointing at a MySQL or SQLite database (see `../hostinger_api/README.md`):

```powershell
php -d extension=zip -S 127.0.0.1:5055 -t hostinger_site hostinger_site/php/dev-server.php
```

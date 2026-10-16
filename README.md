# Buyback Price Tracker

Collects the **"Get Upto" buyback price** of every used phone model and
storage variant on **Cashify** and **DOFY**, stores every check, and shows
the two platforms side by side on a dashboard: which one pays more for each
phone, by how much, and how prices move over time.

Only Cashify and DOFY are covered: they are the platforms that publish
buyback prices without a login. Amazon and Flipkart only show an exchange
discount while buying a new phone, and Croma and Reliance Digital do their
exchanges in the store.

The prices are the maximum offer for a phone in the best condition; the
final quote on each site depends on the condition questions.

These sites may restrict automated access in their terms. Keep the request
rate low (the default delay is 0.5 s between requests to the same site) and
use the data for internal price comparison.

## How it fits together

```
GitHub Actions (Mon + Thu, or a dashboard button)        your PC (optional)
        |  python buyback.py scrape                        |  same command
        v                                                  v
   Hostinger:  /api  (hostinger_api/, PHP + MySQL)  <------+
        |
        +--> Hostinger dashboard  (hostinger_site/, PHP, sign-in)
        +--> Flask dashboard      (dashboard.py, on a PC or Render)
```

| Piece | What it is |
|---|---|
| `buyback.py` | the scraper and its command line (plain HTTP, no browser) |
| `price_store.py` | sends rows to the Hostinger store and reads them back |
| `dashboard.py` + `templates/` + `static/` | the Flask dashboard |
| `hostinger_api/` | the price store API (PHP + MySQL) |
| `hostinger_site/` | the PHP dashboard for Hostinger, with sign-in and roles |
| `.github/workflows/scrape.yml` | scheduled and on-demand scrapes on GitHub |
| `.github/workflows/deploy-hostinger.yml` | uploads the PHP site on every push |
| `tests/` | parser, matching and dashboard tests |

## How the prices are read

| Platform | Source | Requests |
|---|---|---|
| Cashify | An anonymous token from Cashify's own OAuth endpoint lists every model of a brand (the JSON the brand page loads). Each model page links its variants, and each variant page carries the price in its HTML (`itemProp="price"`). | 1 per brand, 1 per model, 1 per variant |
| DOFY | Each brand page lists its models, and each model page lists every variant with its `MaximumValue`, both in the page's `__NEXT_DATA__` JSON. | 1 per brand, 1 per model |

A full run reads about 1,340 Cashify models and 1,330 DOFY models (around
5,000 prices) and takes about 50 minutes, DOFY being the slower site; the
two platforms are crawled side by side, each paced on its own.

Models are matched across the two sites by brand and model name (ignoring
case, repeated brand words like "Xiaomi Redmi 10" vs "Redmi 10", and a "5G"
suffix when that is unambiguous), then variants by storage, and by RAM when
both sites state it. Cashify writes "4 GB/128 GB" where DOFY writes
"128 GB" for iPhones; those are paired.

## Install

```powershell
python -m venv .venv
.\.venv\Scripts\Activate.ps1
python -m pip install -r requirements.txt
```

The scraper needs nothing beyond the standard library; the packages are for
the dashboard (Flask), Excel export (openpyxl) and charts (matplotlib).

## Scrape

```powershell
python buyback.py scrape                                   # both platforms, every brand
python buyback.py scrape --platform dofy --brand Apple     # one platform, one brand
python buyback.py scrape --brand "Apple,Samsung" --model "S24"
python buyback.py scrape --max-models 5 --db test.sqlite3  # quick test
```

Prices go to `buyback_history.sqlite3` (or `DB_PATH`). With `PRICE_API_URL`
and `PRICE_API_KEY` set (environment or `.env`), every row is also sent to
the Hostinger store as it is scraped. One row is saved per variant per
check: date, platform, brand, model, variant, price, numeric price, URL,
status and error. Models or variants a site has no price for are stored as
`status = 'error'` rows, so they show up under Recent Checks.

Other commands:

```powershell
python buyback.py export-csv --output buyback_history.csv
python buyback.py plot --model "iPhone 13" --variant 128 --output iphone13.png
python buyback.py push-history          # copy the local history to the store once
```

## Dashboard (Flask)

```powershell
python dashboard.py        # or double-click run_dashboard.bat
```

Then open http://127.0.0.1:5050. It shows:

- five summary cards: variants tracked, models and brands, variants priced on
  both platforms, prices in the last update, and how often each platform
  pays more;
- the **Cashify vs DOFY** table, one row per variant, folded by model, with
  the best offer and the difference in rupees and percent; sortable by every
  column;
- filters: search (every word must match, e.g. `s24 256`), brands, and
  *On both platforms / Cashify pays more / DOFY pays more / Only on Cashify /
  Only on DOFY*;
- a details drawer per row with both listings and links to the source pages;
- **Recent Checks**: every check including errors, filterable by platform
  and status;
- **Export Excel**: all models, the current view, or one model. The workbook
  has a *Cashify vs DOFY* sheet and a *Prices* sheet (one line per platform
  price);
- **Check All Prices** and **Scrape Selected** (platform, brand, model),
  which run the scraper in the background with a progress bar.

It reads the local SQLite file, or the Hostinger store when `PRICE_API_URL`
and `PRICE_API_KEY` are set (the pill in the header says which).

## Hostinger (PHP site and price store)

- `hostinger_api/` is the price store: see [hostinger_api/README.md](hostinger_api/README.md)
  and [hostinger_api/SETUP.txt](hostinger_api/SETUP.txt).
- `hostinger_site/` is the PHP edition of the dashboard with sign-in
  (admin / user): see [hostinger_site/README.md](hostinger_site/README.md).
- `python hostinger_site/make_zip.py` builds a zip to extract into
  `public_html`, and the workflow `deploy-hostinger.yml` uploads the site
  automatically on every push that changes it.

## Scheduled scrape on GitHub Actions (free)

`.github/workflows/scrape.yml` scrapes both platforms every Monday and
Thursday at 02:00 India time on GitHub's machines and posts the prices to
the Hostinger store. Setup, once:

1. On GitHub open the repository > Settings > Secrets and variables >
   Actions and add two secrets: `PRICE_API_URL` (`https://YOUR-SITE/api`)
   and `PRICE_API_KEY` (the `api_key` from `api/config.php` on Hostinger).
2. Push. The first run happens on the next Monday or Thursday, or start one
   now from the Actions tab > "Full scrape" > Run workflow, where you can
   also limit it to one platform, brand or model.

The Hostinger dashboard's buttons start the same workflow once
`github_token` and `github_repo` are in `api/config.php`.

Each run keeps its SQLite file as a downloadable backup for two weeks.
In a public repository GitHub pauses scheduled workflows after 60 days
without commits. The workflow's last step, "Keep the schedule switched on",
re-enables it after every run to prevent that. It is a common workaround,
not a GitHub guarantee: if the schedule is ever paused anyway, open Actions >
Full scrape and press Enable workflow (or make the repository private, where
the rule does not apply).

## Local settings (.env)

Copy `.env.example` to `.env` to keep `PRICE_API_URL` and `PRICE_API_KEY` on
your PC. The file is read automatically by the dashboard and the scraper, is
ignored by git and is never deployed. Delete it to work with the local
SQLite file only.

## Render (optional)

`Dockerfile` and `render.yaml` run the Flask dashboard on Render, with
working scrape buttons. Set `PRICE_API_URL` and `PRICE_API_KEY` in Render so
the data lives in the Hostinger store (Render's disk is wiped on every
deploy). Then `'scraper_url' => 'https://your-app.onrender.com'` in
`api/config.php` makes the Hostinger buttons use it instead of GitHub.

## Daily schedule on a PC (optional)

Windows Task Scheduler > Create Basic Task > Daily > Start a Program:

```text
Program:    D:\buybackmoblieprice\.venv\Scripts\python.exe
Arguments:  D:\buybackmoblieprice\buyback.py scrape
Start in:   D:\buybackmoblieprice
```

## Tests

```powershell
python -m pip install pytest
python -m pytest
```

The suite checks the page parsers, the cross-site matching, the Flask routes
and exports, that `hostinger_site/public/` matches `static/`, and (when PHP
is installed) that the PHP dashboard pairs prices exactly like the Python
one.

## When a site changes

The fragile assumptions are marked `FRAGILE SITE ASSUMPTION` in
`buyback.py`: Cashify's token endpoint, its variant list markup and its
`itemProp="price"`; DOFY's `__NEXT_DATA__` fields (`selectBrand`,
`selectModel`, `selectVariant`, `MaximumValue`). If prices start recording
as errors, look at the `error` column under Recent Checks and update the
matching parser; `tests/test_buyback.py` has sample markup for each.

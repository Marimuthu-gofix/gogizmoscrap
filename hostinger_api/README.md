# Buyback price store API (PHP + MySQL)

A small JSON API that keeps every scraped buyback price in MySQL on
Hostinger, so prices survive no matter where the scraper ran (GitHub
Actions, Render or a PC). The Hostinger dashboard reads the same table
directly; the Flask dashboard reads it through `/latest` and `/checks`.

Setup steps: [SETUP.txt](SETUP.txt).

## Routes

Every route except `/health` needs the header `X-API-Key: <api_key>`.

| Route | What it does |
|---|---|
| `GET /health` | `{"status":"ok"}` |
| `GET /status` | `{count, max_id, max_date}` |
| `GET /prices?after_id=0&limit=5000` | rows with `id > after_id`, oldest first |
| `POST /prices` | body `{"rows": [...]}`, at most 5000 rows; duplicates are ignored |
| `GET /latest` | newest successful price of every (platform, brand, model, variant) |
| `GET /checks?offset=0&limit=25&status=&platform=&q=&brand[]=` | check history, newest first, `{rows, total}` |

A row is:

```json
{
  "date": "2026-10-16T06:26:57+00:00",
  "platform": "Cashify",
  "brand": "Apple",
  "model": "Apple iPhone 15",
  "variant": "6 GB/128 GB",
  "price": "₹41,820",
  "price_value": 41820,
  "currency": "INR",
  "url": "https://www.cashify.in/sell-old-mobile-phone/used-apple-iphone-15-6-gb-128-gb",
  "status": "ok",
  "error": null
}
```

The table `buyback_prices` is created on first use, with a unique key on
(date, platform, brand, model, variant), so sending the same rows twice is
harmless.

## Using it from the scraper

```powershell
$env:PRICE_API_URL = "https://YOUR-DOMAIN/api"
$env:PRICE_API_KEY = "the api_key"
python buyback.py scrape --platform all
```

Or put both lines in the `.env` file (see `.env.example`). To copy an
existing local history into the store once:

```powershell
python buyback.py push-history
```

## Trying it on a PC

`config.php` may point at SQLite instead of MySQL:

```php
return ['dsn' => 'sqlite:' . __DIR__ . '/local_store.sqlite3', 'user' => '', 'password' => '', 'api_key' => 'local-test-key'];
```

Then run the PHP dev server described in `hostinger_site/README.md`; the API
answers at `http://127.0.0.1:5055/api`.

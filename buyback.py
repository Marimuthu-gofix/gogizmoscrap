"""Buyback price tracker for used mobile phones.

Collects the "Get Upto" buyback price of every phone model and storage
variant on Cashify and DOFY, the two platforms that publish these prices
without a login, and keeps every observation in SQLite. When PRICE_API_URL
and PRICE_API_KEY are set (see price_store.py) every row also goes to the
remote price store that the Hostinger dashboard reads.

Both sites are read over plain HTTP; no browser is needed:

  Cashify  an anonymous token from the site's own OAuth endpoint lists the
           models of each brand (JSON). Each model page links its storage
           variants and each variant page shows the price in its HTML.
  DOFY     each brand page lists its models and each model page carries the
           price of every variant, both in the page's embedded __NEXT_DATA__.

Keep the delay between requests conservative; these sites may restrict
automated access in their terms.
"""
from __future__ import annotations

import argparse
import base64
import csv
import gzip
import html as html_lib
import json
import logging
import os
import random
import re
import sqlite3
import sys
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Callable

from price_store import ROW_FIELDS, RemotePriceStore

LOGGER = logging.getLogger("buyback")
ROOT = Path(__file__).resolve().parent
TABLE = "buyback_prices"
USER_AGENT = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36"
PLATFORMS = {"cashify": "Cashify", "dofy": "DOFY"}

# One spelling per brand, whichever site the name came from ("Google Pixel",
# "IQOO", "Oppo" and "motorola" are how DOFY writes them).
BRAND_NAMES = {
    "apple": "Apple", "samsung": "Samsung", "google": "Google", "google pixel": "Google", "pixel": "Google",
    "oneplus": "OnePlus", "oppo": "OPPO", "vivo": "vivo", "xiaomi": "Xiaomi", "mi": "Xiaomi", "redmi": "Xiaomi",
    "poco": "POCO", "realme": "realme", "motorola": "Motorola", "moto": "Motorola", "iqoo": "iQOO",
    "nothing": "Nothing", "cmf": "CMF", "infinix": "Infinix", "tecno": "Tecno", "nokia": "Nokia", "lava": "Lava",
    "honor": "Honor", "huawei": "Huawei", "lg": "LG", "nubia": "Nubia", "asus": "Asus", "lenovo": "Lenovo",
}
SIZE_RE = re.compile(r"(\d+(?:\.\d+)?)\s*(TB|GB|MB)\b", re.IGNORECASE)


def normalize_space(value: str | None) -> str:
    return re.sub(r"\s+", " ", (value or "").replace("\xa0", " ")).strip()


def canonical_brand(name: str | None) -> str:
    cleaned = normalize_space(name)
    return BRAND_NAMES.get(cleaned.lower(), cleaned)


def clean_variant(text: str | None) -> str:
    """"4 GB / 128 GB" -> "4 GB/128 GB", "128gb" -> "128 GB"."""
    cleaned = normalize_space(text)
    cleaned = SIZE_RE.sub(lambda m: f"{m.group(1)} {m.group(2).upper()}", cleaned)
    return re.sub(r"\s*/\s*", "/", cleaned)


# Brand words DOFY writes in other casings ("Oneplus 10T", "Poco C31").
WORD_SPELLINGS = {"oneplus": "OnePlus", "poco": "POCO", "iqoo": "iQOO", "pixel": "Pixel"}


def split_model_variant(name: str | None) -> tuple[str, str]:
    """Cashify names single-variant models "Google Pixel 10 (12 GB/256 GB)":
    split that into the model and the variant. Other names pass through."""
    cleaned = normalize_space(name)
    bracket = re.match(r"^(.*?)\s*\(([^()]*)\)\s*$", cleaned)
    if bracket and SIZE_RE.search(bracket.group(2)):
        return tidy_model_name(bracket.group(1)), clean_variant(bracket.group(2))
    return tidy_model_name(cleaned), ""


def tidy_model_name(name: str | None) -> str:
    return " ".join(WORD_SPELLINGS.get(word.lower(), word) for word in normalize_space(name).split(" "))


def format_inr(value: float) -> str:
    """17420 -> "₹17,420" in the Indian digit grouping the sites use."""
    whole = str(int(round(value)))
    if len(whole) > 3:
        head, tail = whole[:-3], whole[-3:]
        head = re.sub(r"(\d)(?=(\d{2})+$)", r"\1,", head)
        whole = f"{head},{tail}"
    return f"₹{whole}"


def now_iso() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="seconds")


# ------------------------------------------------------------------- HTTP
class HttpClient:
    """Plain HTTP with gzip, retries and a minimum gap between requests.

    One client per site, so the delay paces each site on its own while the
    two platforms are crawled side by side.
    """

    def __init__(self, delay: float, timeout: float = 45, retries: int = 3) -> None:
        self.delay = max(0.0, delay)
        self.timeout = timeout
        self.retries = retries
        self._last = 0.0
        self.requests = 0

    def _wait_turn(self) -> None:
        gap = self.delay - (time.monotonic() - self._last)
        if gap > 0:
            time.sleep(gap)
        self._last = time.monotonic()

    def request(self, method: str, url: str, body: bytes | None = None, headers: dict[str, str] | None = None) -> bytes:
        merged = {"user-agent": USER_AGENT, "accept-encoding": "gzip", "accept-language": "en-IN,en;q=0.9", **(headers or {})}
        for attempt in range(self.retries + 1):
            self._wait_turn()
            self.requests += 1
            request = urllib.request.Request(url, data=body, method=method, headers=merged)
            try:
                with urllib.request.urlopen(request, timeout=self.timeout) as response:
                    raw = response.read()
                    if response.headers.get("content-encoding") == "gzip":
                        raw = gzip.decompress(raw)
                    return raw
            except urllib.error.HTTPError as exc:
                retryable = exc.code in {429, 500, 502, 503, 504}
                if not retryable or attempt == self.retries:
                    detail = exc.read()[:200].decode("utf-8", "replace")
                    raise HttpError(exc.code, f"{method} {url} failed with HTTP {exc.code}: {detail}") from exc
            except (urllib.error.URLError, TimeoutError, ConnectionError) as exc:
                if attempt == self.retries:
                    raise HttpError(0, f"{method} {url} failed: {exc}") from exc
            pause = 2 ** (attempt + 1) + random.random()
            LOGGER.warning("Retrying %s in %.0fs (attempt %s of %s)", url, pause, attempt + 2, self.retries + 1)
            time.sleep(pause)
        raise AssertionError("unreachable")

    def get_text(self, url: str, headers: dict[str, str] | None = None) -> str:
        return self.request("GET", url, headers=headers).decode("utf-8", "replace")

    def post_json(self, url: str, payload: Any, headers: dict[str, str] | None = None) -> Any:
        merged = {"content-type": "application/json", "accept": "application/json", **(headers or {})}
        raw = self.request("POST", url, json.dumps(payload).encode("utf-8"), merged)
        return json.loads(raw.decode("utf-8"))


class HttpError(RuntimeError):
    def __init__(self, status: int, message: str) -> None:
        super().__init__(message)
        self.status = status


# ---------------------------------------------------------------- storage
# Optional remote store (see hostinger_api/README.md). Every saved row also
# goes there when PRICE_API_URL and PRICE_API_KEY are set.
REMOTE_STORE = RemotePriceStore.from_env()


def default_db_path(fallback: str | Path = "buyback_history.sqlite3") -> Path:
    return Path(os.environ.get("DB_PATH") or fallback)


def connect_db(path: Path) -> sqlite3.Connection:
    path.parent.mkdir(parents=True, exist_ok=True)
    conn = sqlite3.connect(path, check_same_thread=False)
    conn.execute(
        f"""
        CREATE TABLE IF NOT EXISTS {TABLE} (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            date TEXT NOT NULL,
            platform TEXT NOT NULL,
            brand TEXT NOT NULL,
            model TEXT NOT NULL,
            variant TEXT NOT NULL DEFAULT '',
            price TEXT,
            price_value REAL,
            currency TEXT,
            url TEXT NOT NULL,
            status TEXT NOT NULL,
            error TEXT
        )
        """
    )
    conn.execute(f"CREATE INDEX IF NOT EXISTS idx_{TABLE}_lookup ON {TABLE} (platform, brand, model, variant, date)")
    conn.commit()
    return conn


class PriceSaver:
    """Writes rows to SQLite (and the remote store). Both platform crawls
    share one saver, so writes are serialised with a lock."""

    def __init__(self, conn: sqlite3.Connection) -> None:
        self.conn = conn
        self._lock = threading.Lock()

    def save(self, row: dict[str, Any]) -> None:
        clean = {field: row.get(field) for field in ROW_FIELDS}
        clean["variant"] = clean.get("variant") or ""
        with self._lock:
            self.conn.execute(
                f"INSERT INTO {TABLE} ({', '.join(ROW_FIELDS)}) VALUES ({', '.join('?' for _ in ROW_FIELDS)})",
                [clean[field] for field in ROW_FIELDS],
            )
            self.conn.commit()
        if REMOTE_STORE is not None:
            REMOTE_STORE.add(clean)

    def save_price(self, platform: str, brand: str, model: str, variant: str, price_value: float | None, url: str) -> bool:
        if price_value is None or price_value <= 0:
            self.save_error(platform, brand, model, variant, url, "No buyback price is offered for this variant")
            return False
        self.save({
            "date": now_iso(), "platform": platform, "brand": brand, "model": model, "variant": variant,
            "price": format_inr(price_value), "price_value": float(price_value), "currency": "INR",
            "url": url, "status": "ok", "error": None,
        })
        return True

    def save_error(self, platform: str, brand: str, model: str, variant: str, url: str, message: str) -> None:
        self.save({
            "date": now_iso(), "platform": platform, "brand": brand, "model": model, "variant": variant,
            "price": None, "price_value": None, "currency": "INR", "url": url, "status": "error", "error": message[:1000],
        })


def flush_remote_store() -> None:
    if REMOTE_STORE is None:
        return
    if REMOTE_STORE.flush():
        LOGGER.info("All rows were sent to %s", REMOTE_STORE.base_url)
    else:
        LOGGER.warning("%s rows are still waiting to be sent to %s", REMOTE_STORE.pending(), REMOTE_STORE.base_url)


# --------------------------------------------------------------- progress
class Progress:
    """Counts models done per platform and logs one machine-readable
    `PROGRESS {...}` line after every model. The dashboard turns those lines
    into its progress bar; people can ignore them."""

    def __init__(self) -> None:
        self._lock = threading.Lock()
        self._platforms: dict[str, dict[str, Any]] = {}

    def plan(self, platform: str, total: int) -> None:
        with self._lock:
            self._platforms[platform] = {"done": 0, "total": total, "rows": 0, "errors": 0}
        self._emit(f"{platform}: {total} models planned")

    def step(self, platform: str, current: str, rows: int, errors: int) -> None:
        with self._lock:
            entry = self._platforms.setdefault(platform, {"done": 0, "total": 0, "rows": 0, "errors": 0})
            entry["done"] += 1
            entry["rows"] += rows
            entry["errors"] += errors
        self._emit(f"{platform} {current}")

    def totals(self) -> dict[str, int]:
        with self._lock:
            return {key: sum(int(entry[key]) for entry in self._platforms.values()) for key in ("done", "total", "rows", "errors")}

    def _emit(self, current: str) -> None:
        totals = self.totals()
        percent = round(100 * totals["done"] / totals["total"], 1) if totals["total"] else 0
        LOGGER.info("PROGRESS %s", json.dumps({**totals, "percent": percent, "current": current}, ensure_ascii=False))


# ------------------------------------------------------------------ filter
def parse_brand_filter(value: str | None) -> set[str] | None:
    """"all" -> None (every brand); "apple, Google Pixel" -> {"apple", "google"}."""
    wanted = {canonical_brand(part).lower() for part in (value or "").split(",") if part.strip()}
    if not wanted or "all" in wanted:
        return None
    return wanted


def brand_wanted(brand: str, brands: set[str] | None) -> bool:
    return brands is None or canonical_brand(brand).lower() in brands


def model_wanted(model: str, model_filter: str | None) -> bool:
    return not model_filter or normalize_space(model_filter).lower() in normalize_space(model).lower()


# ----------------------------------------------------------------- Cashify
CASHIFY_BASE = "https://www.cashify.in"
CASHIFY_SELL_URL = f"{CASHIFY_BASE}/sell-old-mobile-phone"
CASHIFY_API = f"{CASHIFY_BASE}/api"
# FRAGILE SITE ASSUMPTION: the anonymous token Cashify's own pages ask for
# before calling the catalog API. It lasts about an hour.
CASHIFY_TOKEN_URL = "https://qr6y.api.cashify.in/v1/oauth/token?grant_type=implicit&client_id=gadget-pro&client_version=v1"
CASHIFY_TOKEN_FILE = ROOT / ".cashify_token.json"
# The catalog API wants a location. Chennai, as in the spare-price tracker.
CASHIFY_PINCODE = 600027
CASHIFY_REGION_ID = 333
CASHIFY_VARIANT_URL_RE = re.compile(r"-\d+(?:\.\d+)?-(?:gb|tb|mb)(?:-\d+(?:\.\d+)?-(?:gb|tb|mb))?$")


def cashify_token_expiry(token: str) -> float:
    """Unix time the bearer token expires, or 0 if it cannot be read."""
    try:
        payload = token.replace("Bearer ", "").split(".")[1]
        payload += "=" * (-len(payload) % 4)
        return float(json.loads(base64.urlsafe_b64decode(payload)).get("exp") or 0)
    except Exception:
        return 0.0


def cashify_token(client: HttpClient, refresh: bool = False) -> str | None:
    """A bearer token for Cashify's catalog API, cached on disk until it is
    ten minutes from expiring."""
    if not refresh:
        try:
            saved = json.loads(CASHIFY_TOKEN_FILE.read_text(encoding="utf-8")).get("token") or ""
        except (OSError, ValueError):
            saved = ""
        if saved and cashify_token_expiry(saved) - time.time() > 600:
            return saved
    url = f"{CASHIFY_TOKEN_URL}&_t={int(time.time() * 1000)}"
    try:
        data = json.loads(client.request("POST", url, b"", {"origin": CASHIFY_BASE, "referer": CASHIFY_BASE + "/"}).decode("utf-8"))
    except (HttpError, ValueError) as exc:
        LOGGER.warning("Could not get a Cashify API token: %s", exc)
        return None
    token = str(data.get("access_token") or "")
    if not token:
        return None
    token = token if token.startswith("Bearer ") else f"Bearer {token}"
    try:
        CASHIFY_TOKEN_FILE.write_text(json.dumps({"token": token, "exp": cashify_token_expiry(token)}), encoding="utf-8")
    except OSError:
        LOGGER.debug("Could not cache the Cashify token", exc_info=True)
    return token


def cashify_brand_slugs(client: HttpClient) -> list[str]:
    """Brand pages linked from the sell landing page, e.g. "sell-apple"."""
    html = client.get_text(CASHIFY_SELL_URL)
    return sorted(set(re.findall(r'href="/sell-old-mobile-phone/(sell-[a-z0-9-]+)"', html)))


def cashify_brand_name(slug: str) -> str:
    return canonical_brand(slug.removeprefix("sell-").replace("-", " "))


def cashify_models_from_api(client: HttpClient, token: str, slug: str) -> list[dict[str, str]]:
    """Every model on a brand page, from the JSON the page itself loads."""
    seo = f"/sell-old-mobile-phone/{slug}"
    models: list[dict[str, str]] = []
    offset = 0
    while True:
        body = {
            "serid": 2, "souid": 1, "plid": 20, "bid": None, "pcid": None, "ps": 500, "os": offset,
            "pin": CASHIFY_PINCODE, "regid": CASHIFY_REGION_ID, "fid": None, "pid": None, "seo": seo,
        }
        try:
            data = client.post_json(
                f"{CASHIFY_API}/pd01/v1/product-search-template?key=product_list&seo={urllib.parse.quote(seo, safe='')}",
                body, {"x-authorization": token},
            )
        except HttpError as exc:
            if exc.status == 401:
                raise PermissionError("Cashify API token was rejected") from exc
            raise
        items = data.get("dt") or []
        for item in items:
            name = normalize_space(str(item.get("pn") or ""))
            seo_slug = str(item.get("pseo") or "").strip()
            if name and seo_slug:
                models.append({"model": name, "url": f"{CASHIFY_SELL_URL}/used-{seo_slug}"})
        offset += len(items)
        if not items or offset >= int(data.get("hits") or 0):
            return models


def cashify_models_from_html(client: HttpClient, slug: str) -> list[dict[str, str]]:
    """Fallback when no token is available: the model links in the brand
    page's HTML. That is the first page of models only, so it is partial."""
    html = client.get_text(f"{CASHIFY_SELL_URL}/{slug}")
    models = []
    for path in sorted(set(re.findall(r'href="/sell-old-mobile-phone/(used-[a-z0-9-]+)"', html))):
        if CASHIFY_VARIANT_URL_RE.search(path):
            continue
        models.append({"model": "", "url": f"{CASHIFY_SELL_URL}/{path}"})
    return models


def cashify_variant_links(html: str) -> list[tuple[str, str]]:
    """(url, label) for every variant a model page offers."""
    # FRAGILE SITE ASSUMPTION: "Choose a variant" is a list of
    # <li id="variant-<id>"><a href="/sell-old-mobile-phone/used-...">..<span>128 GB</span>
    links = []
    for chunk in re.findall(r'<li[^>]*\bid="variant-\d+"[^>]*>(.*?)</li>', html, flags=re.S):
        href = re.search(r'href="(/sell-old-mobile-phone/[^"#?]+)"', chunk)
        labels = [normalize_space(html_lib.unescape(text)) for text in re.findall(r"<span[^>]*>([^<]+)</span>", chunk)]
        if href:
            links.append((CASHIFY_BASE + href.group(1), next((label for label in labels if label), "")))
    return list(dict.fromkeys(links))


def cashify_page_price(html: str) -> tuple[str, str, float | None]:
    """(model, variant, price) from a Cashify variant page.

    The page heading reads "Sell Old Apple iPhone 12 (4 GB/128 GB)" and the
    price sits in the page's schema.org offer: itemProp="price">17420<.
    """
    # FRAGILE SITE ASSUMPTION: one <h1> naming the variant and one schema.org
    # itemProp="price" per variant page. Pages of phones Cashify no longer
    # buys have neither.
    heading = re.search(r"<h1[^>]*>(.*?)</h1>", html, flags=re.S)
    title = normalize_space(html_lib.unescape(re.sub(r"<[^>]+>", "", heading.group(1)))) if heading else ""
    title = re.sub(r"^Sell\s+(Old\s+)?", "", title, flags=re.IGNORECASE)
    model, variant = title, ""
    bracket = re.match(r"^(.*?)\s*\(([^()]*)\)\s*$", title)
    if bracket:
        model, variant = bracket.group(1), bracket.group(2)
    price = re.search(r'itemProp="price"[^>]*>\s*([\d.,]+)\s*<', html)
    value = float(price.group(1).replace(",", "")) if price else None
    return normalize_space(model), clean_variant(variant), value


def scrape_cashify(
    saver: PriceSaver, progress: Progress, delay: float, brands: set[str] | None,
    model_filter: str | None, max_models: int | None,
) -> None:
    client = HttpClient(delay)
    token = cashify_token(client)
    slugs = [slug for slug in cashify_brand_slugs(client) if brand_wanted(cashify_brand_name(slug), brands)]
    LOGGER.info("Cashify brands to read: %s", ", ".join(cashify_brand_name(slug) for slug in slugs) or "none")

    plan: list[dict[str, str]] = []
    for slug in slugs:
        brand = cashify_brand_name(slug)
        try:
            if token:
                try:
                    models = cashify_models_from_api(client, token, slug)
                except PermissionError:
                    token = cashify_token(client, refresh=True)
                    if not token:
                        raise
                    models = cashify_models_from_api(client, token, slug)
            else:
                LOGGER.warning("No Cashify token; reading %s models from the page (first page only)", brand)
                models = cashify_models_from_html(client, slug)
        except Exception as exc:
            LOGGER.error("Cashify %s model list failed: %s", brand, exc)
            saver.save_error("Cashify", brand, f"{brand} (model list)", "", f"{CASHIFY_SELL_URL}/{slug}", f"{type(exc).__name__}: {exc}")
            continue
        chosen = [{**entry, "brand": brand} for entry in models if model_wanted(entry["model"] or entry["url"], model_filter)]
        LOGGER.info("Cashify %s: %s models (%s selected)", brand, len(models), len(chosen))
        plan.extend(chosen)
    if max_models is not None:
        plan = plan[:max_models]
    progress.plan("Cashify", len(plan))

    for index, entry in enumerate(plan, start=1):
        brand, model_url = entry["brand"], entry["url"]
        model, name_variant = split_model_variant(entry["model"] or model_url.rsplit("/used-", 1)[-1].replace("-", " ").title())
        saved = errors = 0
        try:
            model_html = client.get_text(model_url)
            variants = cashify_variant_links(model_html)
            if not variants:
                # A model with a single variant is its own price page.
                variants = [(model_url, name_variant)]
            for variant_url, label in variants:
                try:
                    page = model_html if variant_url == model_url else client.get_text(variant_url)
                    page_model, variant, value = cashify_page_price(page)
                    variant = variant or clean_variant(label)
                    if not entry["model"] and page_model:
                        model = tidy_model_name(page_model)
                    if saver.save_price("Cashify", brand, model, variant, value, variant_url):
                        saved += 1
                    else:
                        errors += 1
                except Exception as exc:
                    errors += 1
                    LOGGER.error("Cashify %s %s failed: %s", model, label, exc)
                    saver.save_error("Cashify", brand, model, clean_variant(label), variant_url, f"{type(exc).__name__}: {exc}")
            LOGGER.info("Saved Cashify %s (%s variants, %s/%s)", model, saved, index, len(plan))
        except Exception as exc:
            errors += 1
            LOGGER.error("Cashify %s failed: %s", model, exc)
            saver.save_error("Cashify", brand, model, "", model_url, f"{type(exc).__name__}: {exc}")
        progress.step("Cashify", model, saved, errors)
    LOGGER.info("Cashify finished: %s requests", client.requests)


# -------------------------------------------------------------------- DOFY
DOFY_BASE = "https://www.dofy.in/in-en/india"
DOFY_PHONES_URL = f"{DOFY_BASE}/sell-old-phone"


def dofy_slug(enum_name: str) -> str:
    """DOFY builds its addresses from EnumName: "Google_Pixel" -> "google-pixel"."""
    return re.sub(r"-+", "-", re.sub(r"[^a-z0-9]+", "-", enum_name.lower())).strip("-")


def dofy_page_data(client: HttpClient, url: str) -> dict[str, Any]:
    """The pageProps of a DOFY page, from its embedded __NEXT_DATA__ JSON."""
    # FRAGILE SITE ASSUMPTION: DOFY is a Next.js site whose pages embed their
    # data: selectBrand (phones page), selectModel (brand page) and
    # selectVariant with MaximumValue (model page).
    html = client.get_text(url)
    match = re.search(r'<script id="__NEXT_DATA__"[^>]*>(.*?)</script>', html, flags=re.S)
    if not match:
        raise ValueError("The page has no __NEXT_DATA__ block")
    return json.loads(match.group(1)).get("props", {}).get("pageProps", {}) or {}


def dofy_listed(items: Any) -> list[dict[str, Any]]:
    return [item for item in (items or []) if isinstance(item, dict) and item.get("DisplayInList", True) is not False]


def scrape_dofy(
    saver: PriceSaver, progress: Progress, delay: float, brands: set[str] | None,
    model_filter: str | None, max_models: int | None,
) -> None:
    client = HttpClient(delay)
    brand_list = [
        {"brand": canonical_brand(item.get("Name") or item.get("DisplayName")), "slug": dofy_slug(str(item.get("EnumName") or item.get("Name")))}
        for item in dofy_listed(dofy_page_data(client, DOFY_PHONES_URL).get("selectBrand"))
    ]
    brand_list = [entry for entry in brand_list if entry["slug"] and brand_wanted(entry["brand"], brands)]
    LOGGER.info("DOFY brands to read: %s", ", ".join(entry["brand"] for entry in brand_list) or "none")

    plan: list[dict[str, str]] = []
    for entry in brand_list:
        brand_url = f"{DOFY_PHONES_URL}/{entry['slug']}"
        try:
            models = dofy_listed(dofy_page_data(client, brand_url).get("selectModel"))
        except Exception as exc:
            LOGGER.error("DOFY %s model list failed: %s", entry["brand"], exc)
            saver.save_error("DOFY", entry["brand"], f"{entry['brand']} (model list)", "", brand_url, f"{type(exc).__name__}: {exc}")
            continue
        chosen = []
        for item in models:
            name = normalize_space(str(item.get("Name") or item.get("DisplayName") or ""))
            enum = str(item.get("EnumName") or name)
            if name and model_wanted(name, model_filter):
                chosen.append({"brand": entry["brand"], "model": tidy_model_name(name), "url": f"{brand_url}/{dofy_slug(enum)}"})
        LOGGER.info("DOFY %s: %s models (%s selected)", entry["brand"], len(models), len(chosen))
        plan.extend(chosen)
    if max_models is not None:
        plan = plan[:max_models]
    progress.plan("DOFY", len(plan))

    for index, entry in enumerate(plan, start=1):
        saved = errors = 0
        try:
            variants = dofy_listed(dofy_page_data(client, entry["url"]).get("selectVariant"))
            if not variants:
                raise ValueError("The model page lists no variants")
            for item in variants:
                variant = clean_variant(str(item.get("Name") or ""))
                value = item.get("MaximumValue")
                value = float(value) if isinstance(value, (int, float)) else None
                if saver.save_price("DOFY", entry["brand"], entry["model"], variant, value, entry["url"]):
                    saved += 1
                else:
                    errors += 1
            LOGGER.info("Saved DOFY %s (%s variants, %s/%s)", entry["model"], saved, index, len(plan))
        except Exception as exc:
            errors += 1
            LOGGER.error("DOFY %s failed: %s", entry["model"], exc)
            saver.save_error("DOFY", entry["brand"], entry["model"], "", entry["url"], f"{type(exc).__name__}: {exc}")
        progress.step("DOFY", entry["model"], saved, errors)
    LOGGER.info("DOFY finished: %s requests", client.requests)


SCRAPERS: dict[str, Callable[..., None]] = {"cashify": scrape_cashify, "dofy": scrape_dofy}


def scrape(
    platform: str, brand: str | None, model_filter: str | None, max_models: int | None,
    delay: float, db_path: Path,
) -> int:
    """Crawl the chosen platforms side by side (each one paced on its own)."""
    platforms = list(PLATFORMS) if platform == "all" else [platform]
    brands = parse_brand_filter(brand)
    conn = connect_db(db_path)
    saver = PriceSaver(conn)
    progress = Progress()
    LOGGER.info(
        "Scraping %s for %s%s into %s",
        " and ".join(PLATFORMS[name] for name in platforms), "all brands" if brands is None else ", ".join(sorted(brands)),
        f" matching {model_filter!r}" if model_filter else "", db_path,
    )
    failed = []
    with ThreadPoolExecutor(max_workers=len(platforms), thread_name_prefix="scrape") as pool:
        futures = {
            name: pool.submit(SCRAPERS[name], saver, progress, delay, brands, model_filter, max_models)
            for name in platforms
        }
        for name, future in futures.items():
            try:
                future.result()
            except Exception:
                failed.append(PLATFORMS[name])
                LOGGER.exception("%s crawl failed", PLATFORMS[name])
    conn.close()
    flush_remote_store()
    totals = progress.totals()
    LOGGER.info("Done: %s models, %s prices saved, %s errors", totals["done"], totals["rows"], totals["errors"])
    return 1 if failed else 0


# ---------------------------------------------------------------- reports
def export_csv(db_path: Path, output_path: Path) -> None:
    conn = connect_db(db_path)
    cursor = conn.execute(f"SELECT {', '.join(ROW_FIELDS)} FROM {TABLE} ORDER BY id")
    with output_path.open("w", newline="", encoding="utf-8") as handle:
        writer = csv.writer(handle)
        writer.writerow(ROW_FIELDS)
        writer.writerows(cursor)
    conn.close()
    LOGGER.info("Wrote %s", output_path)


def plot_history(db_path: Path, output_path: Path, brand: str | None, model: str | None, variant: str | None) -> None:
    """One line per platform and variant, price over time."""
    import matplotlib

    matplotlib.use("Agg")
    import matplotlib.pyplot as plt

    conn = connect_db(db_path)
    where, params = ["status = 'ok'"], []
    for column, value in (("brand", brand), ("model", model), ("variant", variant)):
        if value:
            where.append(f"LOWER({column}) LIKE ?")
            params.append(f"%{value.lower()}%")
    rows = conn.execute(
        f"SELECT date, platform, brand, model, variant, price_value FROM {TABLE} WHERE {' AND '.join(where)} ORDER BY date", params
    ).fetchall()
    conn.close()
    if not rows:
        raise SystemExit("No successful prices match those filters.")
    series: dict[str, list[tuple[datetime, float]]] = {}
    for date, platform, row_brand, row_model, row_variant, value in rows:
        label = f"{platform}: {row_model} {row_variant}".strip()
        series.setdefault(label, []).append((datetime.fromisoformat(date), float(value)))
    if len(series) > 12:
        LOGGER.warning("%s series match; plotting the first 12. Narrow it with --model / --variant.", len(series))
    figure, axis = plt.subplots(figsize=(11, 6))
    for label, points in list(series.items())[:12]:
        axis.plot([p[0] for p in points], [p[1] for p in points], marker="o", label=label)
    axis.set_ylabel("Buyback price (INR)")
    axis.set_title("Buyback price history")
    axis.grid(alpha=0.3)
    axis.legend(fontsize=8)
    figure.autofmt_xdate()
    figure.tight_layout()
    figure.savefig(output_path, dpi=120)
    LOGGER.info("Wrote %s", output_path)


def push_history(db_path: Path, batch_size: int, after_id: int) -> int:
    """Upload the local SQLite history to the remote store, oldest first."""
    if REMOTE_STORE is None:
        raise SystemExit("Set PRICE_API_URL and PRICE_API_KEY first (see hostinger_api/README.md).")
    if not REMOTE_STORE.health():
        raise SystemExit(f"{REMOTE_STORE.base_url}/health did not answer; check the URL and that the API is uploaded.")
    conn = connect_db(db_path)
    total = conn.execute(f"SELECT COUNT(*) FROM {TABLE} WHERE id > ?", (after_id,)).fetchone()[0]
    LOGGER.info("Uploading %s rows from %s to %s", total, db_path, REMOTE_STORE.base_url)
    sent = inserted = 0
    last_id = after_id
    while True:
        rows = conn.execute(
            f"SELECT id, {', '.join(ROW_FIELDS)} FROM {TABLE} WHERE id > ? ORDER BY id LIMIT ?", (last_id, batch_size)
        ).fetchall()
        if not rows:
            break
        result = REMOTE_STORE.post_rows([dict(zip(ROW_FIELDS, row[1:])) for row in rows])
        sent += len(rows)
        inserted += int(result.get("inserted") or 0)
        last_id = rows[-1][0]
        LOGGER.info("Uploaded %s/%s rows (%s new so far, last id %s)", sent, total, inserted, last_id)
    conn.close()
    status = REMOTE_STORE.status()
    LOGGER.info("Done. Remote store now holds %s rows (latest %s)", status.get("count"), status.get("max_date"))
    return 0


# --------------------------------------------------------------------- CLI
def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Track Cashify and DOFY buyback prices for used phones.")
    parser.add_argument("--log-level", default="INFO", help="DEBUG, INFO, WARNING or ERROR")
    commands = parser.add_subparsers(dest="command", required=True)

    run = commands.add_parser("scrape", help="Collect current buyback prices")
    run.add_argument("--platform", choices=["all", *PLATFORMS], default="all")
    run.add_argument("--brand", default="all", help='"all", or brand names separated by commas, e.g. "Apple,Samsung"')
    run.add_argument("--model", default="", help="Only models whose name contains this text")
    run.add_argument("--max-models", type=int, default=None, help="Stop after this many models per platform (for testing)")
    run.add_argument("--delay", type=float, default=0.5, help="Seconds between requests to the same site")
    run.add_argument("--db", type=Path, default=None, help="SQLite file (default: DB_PATH or buyback_history.sqlite3)")

    export = commands.add_parser("export-csv", help="Write the whole history to a CSV file")
    export.add_argument("--db", type=Path, default=None)
    export.add_argument("--output", type=Path, default=Path("buyback_history.csv"))

    plot = commands.add_parser("plot", help="Chart price history to a PNG file")
    plot.add_argument("--db", type=Path, default=None)
    plot.add_argument("--output", type=Path, default=Path("buyback_prices.png"))
    plot.add_argument("--brand")
    plot.add_argument("--model")
    plot.add_argument("--variant")

    push = commands.add_parser("push-history", help="Upload the local history to the remote price store")
    push.add_argument("--db", type=Path, default=None)
    push.add_argument("--batch-size", type=int, default=1000)
    push.add_argument("--after-id", type=int, default=0)
    return parser


def main(argv: list[str] | None = None) -> int:
    args = build_parser().parse_args(argv)
    if hasattr(sys.stdout, "reconfigure"):
        # Model names carry "₹" and other non-ASCII text; line buffering lets
        # the dashboard read progress as it happens.
        sys.stdout.reconfigure(encoding="utf-8", line_buffering=True)
    logging.basicConfig(
        level=getattr(logging, str(args.log_level).upper(), logging.INFO),
        format="%(asctime)s %(levelname)s %(message)s",
        stream=sys.stdout,
    )
    db_path = args.db or default_db_path()
    if args.command == "scrape":
        max_models = args.max_models if args.max_models and args.max_models > 0 else None
        return scrape(args.platform, args.brand, args.model.strip() or None, max_models, args.delay, db_path)
    if args.command == "export-csv":
        export_csv(db_path, args.output)
        return 0
    if args.command == "plot":
        plot_history(db_path, args.output, args.brand, args.model, args.variant)
        return 0
    if args.command == "push-history":
        return push_history(db_path, args.batch_size, args.after_id)
    return 2


if __name__ == "__main__":
    raise SystemExit(main())

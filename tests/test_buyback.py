"""Tests for the parsers, the Cashify/DOFY matching and the two dashboards.

    python -m pytest

The PHP parity test runs only when a PHP binary is found (PHP_BINARY, `php`
on PATH, or XAMPP's default location).
"""
from __future__ import annotations

import json
import os
import shutil
import subprocess
import sys
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

import buyback  # noqa: E402
import dashboard  # noqa: E402


# ------------------------------------------------------------------ parsing
def test_clean_variant_normalises_sizes_and_slashes() -> None:
    assert buyback.clean_variant("4 GB / 128 GB") == "4 GB/128 GB"
    assert buyback.clean_variant("128gb") == "128 GB"
    assert buyback.clean_variant(" 12 GB/1 tb ") == "12 GB/1 TB"


def test_split_model_variant_handles_cashify_single_variant_names() -> None:
    assert buyback.split_model_variant("Google Pixel 10 (12 GB/256 GB)") == ("Google Pixel 10", "12 GB/256 GB")
    assert buyback.split_model_variant("OnePlus 7T Pro McLaren Edition") == ("OnePlus 7T Pro McLaren Edition", "")
    # Brackets without a size are part of the name.
    assert buyback.split_model_variant("Nokia 3310 (2017)") == ("Nokia 3310 (2017)", "")


def test_tidy_model_name_fixes_dofy_brand_casing() -> None:
    assert buyback.tidy_model_name("Oneplus 10T") == "OnePlus 10T"
    assert buyback.tidy_model_name("Poco C81x") == "POCO C81x"
    assert buyback.tidy_model_name("Google pixel 10 Pro Fold") == "Google Pixel 10 Pro Fold"


def test_canonical_brand_and_inr_format() -> None:
    assert buyback.canonical_brand("Google Pixel") == "Google"
    assert buyback.canonical_brand("IQOO") == "iQOO"
    assert buyback.canonical_brand("motorola") == "Motorola"
    assert buyback.format_inr(17420) == "₹17,420"
    assert buyback.format_inr(112250) == "₹1,12,250"
    assert buyback.format_inr(950) == "₹950"


def test_dofy_slug_matches_dofy_addresses() -> None:
    assert buyback.dofy_slug("Google_Pixel") == "google-pixel"
    assert buyback.dofy_slug("Samsung_Galaxy_Z_Fold_8_Ultra") == "samsung-galaxy-z-fold-8-ultra"
    assert buyback.dofy_slug("Apple_iPhone_SE_2022") == "apple-iphone-se-2022"


CASHIFY_MODEL_PAGE = """
<ul><li class="w-[47%]" id="variant-32548"><a class="w-full" href="/sell-old-mobile-phone/used-apple-iphone-12-4-gb-64-gb">
<div><div></div><span class="subtitle5 text-center">64 GB</span></div></a></li>
<li class="w-[47%]" id="variant-32549"><a class="w-full" href="/sell-old-mobile-phone/used-apple-iphone-12-4-gb-128-gb">
<div><div></div><span class="subtitle5 text-center">128 GB</span></div></a></li></ul>
"""
CASHIFY_VARIANT_PAGE = """
<h1 class="h1 sm:pt-4 line-clamp-1">Sell Old Apple iPhone 12 (4 GB/128 GB)</h1>
<div class="inherit body4 mb-2">Get Upto</div><span class="display5 text-error">₹17,420</span>
<div class="hidden" itemProp="offers"><span itemProp="priceCurrency">INR</span><span class="inherit" itemProp="price">17420</span></div>
"""


def test_cashify_variant_links() -> None:
    assert buyback.cashify_variant_links(CASHIFY_MODEL_PAGE) == [
        ("https://www.cashify.in/sell-old-mobile-phone/used-apple-iphone-12-4-gb-64-gb", "64 GB"),
        ("https://www.cashify.in/sell-old-mobile-phone/used-apple-iphone-12-4-gb-128-gb", "128 GB"),
    ]


def test_cashify_page_price() -> None:
    assert buyback.cashify_page_price(CASHIFY_VARIANT_PAGE) == ("Apple iPhone 12", "4 GB/128 GB", 17420.0)
    assert buyback.cashify_page_price("<html>no offer here</html>") == ("", "", None)


def test_brand_and_model_filters() -> None:
    brands = buyback.parse_brand_filter("Apple, Google Pixel")
    assert brands == {"apple", "google"}
    assert buyback.brand_wanted("Google", brands)
    assert not buyback.brand_wanted("Samsung", brands)
    assert buyback.parse_brand_filter("all") is None
    assert buyback.model_wanted("Apple iPhone 13 Pro", "iphone 13")


def test_saver_writes_rows_and_errors(tmp_path: Path) -> None:
    conn = buyback.connect_db(tmp_path / "t.sqlite3")
    saver = buyback.PriceSaver(conn)
    assert saver.save_price("DOFY", "Apple", "Apple iPhone 13", "128 GB", 28010, "https://example.test/a")
    assert not saver.save_price("DOFY", "Apple", "Apple iPhone 6", "16 GB", 0, "https://example.test/b")
    rows = conn.execute("SELECT platform, price, price_value, status, error FROM buyback_prices ORDER BY id").fetchall()
    assert rows[0] == ("DOFY", "₹28,010", 28010.0, "ok", None)
    assert rows[1][3] == "error" and "No buyback price" in rows[1][4]


# ----------------------------------------------------------------- matching
def row(platform: str, brand: str, model: str, variant: str, price: float, date: str = "2026-10-16T06:00:00+00:00") -> dict:
    return {
        "platform": platform, "brand": brand, "model": model, "variant": variant, "price_value": price,
        "price": f"₹{price}", "currency": "INR", "date": date, "url": f"https://example.test/{platform}/{model}/{variant}",
        "status": "ok", "error": None,
    }


FIXTURE = [
    # Apple: Cashify names RAM, DOFY does not -> paired on storage.
    row("Cashify", "Apple", "Apple iPhone 13", "4 GB/128 GB", 24260),
    row("Cashify", "Apple", "Apple iPhone 13", "4 GB/256 GB", 24890),
    row("DOFY", "Apple", "Apple iPhone 13", "128 GB", 28010),
    row("DOFY", "Apple", "Apple iPhone 13", "256 GB", 29430),
    row("DOFY", "Apple", "Apple iPhone 13", "512 GB", 30850),
    # Samsung: "5G" only on Cashify -> paired through the loose key.
    row("Cashify", "Samsung", "Samsung Galaxy S24 5G", "8 GB/128 GB", 33390),
    row("DOFY", "Samsung", "Samsung Galaxy S24", "8 GB/128 GB", 33590),
    # vivo sells a T1 and a T1 5G: both exist on both sites and stay apart.
    row("Cashify", "vivo", "Vivo T1", "4 GB/128 GB", 6000),
    row("Cashify", "vivo", "Vivo T1 5G", "4 GB/128 GB", 7000),
    row("DOFY", "Vivo", "Vivo T1", "4 GB/128 GB", 6100),
    row("DOFY", "Vivo", "Vivo T1 5G", "4 GB/128 GB", 7000),
    # Different RAM on the same storage is not the same phone.
    row("Cashify", "Xiaomi", "Xiaomi Redmi Note 13", "6 GB/128 GB", 9000),
    row("DOFY", "Xiaomi", "Redmi Note 13", "8 GB/128 GB", 9500),
    # Only on one platform.
    row("DOFY", "Google Pixel", "Google Pixel 11", "12 GB/256 GB", 52000),
]


def entries_by_key() -> dict[tuple[str, str], dict]:
    return {(entry["model"], entry["variant"]): entry for entry in dashboard.compare_rows(FIXTURE)}


def test_model_keys() -> None:
    assert dashboard.model_key("Samsung Galaxy S24+ 5G") == "galaxy s24 plus 5g"
    assert dashboard.model_key("Samsung Galaxy S24+ 5G", loose=True) == "galaxy s24 plus"
    assert dashboard.model_key("Xiaomi Redmi Note 13") == dashboard.model_key("Redmi Note 13")
    assert dashboard.variant_sizes("6 GB/1 TB") == (6.0, 1024.0)
    assert dashboard.variant_sizes("128 GB") == (None, 128.0)


def test_compare_pairs_apple_on_storage() -> None:
    entries = entries_by_key()
    pair = entries[("Apple iPhone 13", "4 GB/128 GB")]
    assert (pair["cashify_value"], pair["dofy_value"], pair["best"], pair["gap"]) == (24260, 28010, "DOFY", 3750)
    assert entries[("Apple iPhone 13", "512 GB")]["platforms"] == "dofy"


def test_compare_uses_loose_key_only_when_unambiguous() -> None:
    entries = entries_by_key()
    samsung = entries[("Samsung Galaxy S24 5G", "8 GB/128 GB")]
    assert samsung["platforms"] == "both" and samsung["best"] == "DOFY"
    assert entries[("Vivo T1", "4 GB/128 GB")]["dofy_value"] == 6100
    assert entries[("Vivo T1 5G", "4 GB/128 GB")]["best"] == "Same"


def test_compare_keeps_different_ram_apart() -> None:
    entries = entries_by_key()
    assert entries[("Xiaomi Redmi Note 13", "6 GB/128 GB")]["platforms"] == "cashify"
    assert entries[("Redmi Note 13", "8 GB/128 GB")]["platforms"] == "dofy"
    assert entries[("Google Pixel 11", "12 GB/256 GB")]["brand"] == "Google"


def test_last_update_summary_counts_one_scrape() -> None:
    rows = [
        row("DOFY", "Apple", "A", "1 GB", 1, "2026-10-16T06:00:00+00:00"),
        row("DOFY", "Apple", "B", "1 GB", 1, "2026-10-16T06:10:00+00:00"),
        row("DOFY", "Apple", "C", "1 GB", 1, "2026-10-12T06:10:00+00:00"),
    ]
    assert dashboard.last_update_summary(rows) == {"count": 2, "date": "2026-10-16T06:10:00+00:00"}


# --------------------------------------------------------------- dashboards
def test_flask_dashboard_and_exports(tmp_path: Path) -> None:
    db = tmp_path / "dash.sqlite3"
    conn = buyback.connect_db(db)
    saver = buyback.PriceSaver(conn)
    for item in FIXTURE:
        saver.save({**item})
    conn.close()
    dashboard.app.config["DB_PATH"] = str(db)
    dashboard._PRICE_DATA["stamp"] = None
    client = dashboard.app.test_client()
    try:
        page = client.get("/?compare=both")
        assert page.status_code == 200
        html = page.get_data(as_text=True)
        assert "Apple iPhone 13" in html and "Rs 28,010" in html
        assert client.get("/?q=zzz&hpage=50").status_code == 200
        csv_text = client.get("/export.csv?scope=all").get_data(as_text=True)
        assert csv_text.splitlines()[0].startswith("Brand,Model,Variant,Cashify Price,DOFY Price")
        xlsx = client.get("/export.xlsx?scope=model&model=Apple iPhone 13")
        assert xlsx.status_code == 200 and xlsx.data[:2] == b"PK"
        assert "Apple iPhone 13" in client.get("/models.json").get_json()
    finally:
        dashboard.app.config.pop("DB_PATH", None)
        dashboard._PRICE_DATA["stamp"] = None


def test_php_assets_match_static() -> None:
    for name in ("dashboard.css", "dashboard.js", "favicon.svg", "favicon-32.png", "favicon.ico", "apple-touch-icon.png"):
        assert (ROOT / "static" / name).read_bytes() == (ROOT / "hostinger_site" / "public" / name).read_bytes(), name


def php_binary() -> str | None:
    candidates = [os.environ.get("PHP_BINARY"), shutil.which("php"), r"C:\xampp\php\php.exe"]
    return next((c for c in candidates if c and Path(c).is_file()), None)


@pytest.mark.skipif(php_binary() is None, reason="PHP is not installed")
def test_php_comparison_matches_python(tmp_path: Path) -> None:
    fixture = tmp_path / "rows.json"
    fixture.write_text(json.dumps(FIXTURE), encoding="utf-8")
    script = tmp_path / "compare.php"
    script.write_text(
        "<?php\nrequire " + json.dumps(str(ROOT / "hostinger_site" / "php" / "data.php")) + ";\n"
        "$rows = json_decode(file_get_contents($argv[1]), true);\n"
        "$out = [];\n"
        "foreach (bb_compare_rows($rows) as $e) { $out[] = [$e['group'], $e['model'], $e['variant'], $e['cashify_value'], $e['dofy_value'], $e['best'], $e['platforms']]; }\n"
        "echo json_encode($out);\n",
        encoding="utf-8",
    )
    result = subprocess.run([php_binary(), str(script), str(fixture)], capture_output=True, text=True, check=True)
    php = sorted(json.loads(result.stdout), key=json.dumps)
    python = sorted(
        ([e["group"], e["model"], e["variant"], e["cashify_value"], e["dofy_value"], e["best"], e["platforms"]]
         for e in dashboard.compare_rows(FIXTURE)),
        key=json.dumps,
    )
    assert json.loads(json.dumps(python)) == php

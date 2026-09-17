#!/usr/bin/env python3
"""Refresh python/model_pricing.json from OpenRouter's public model catalog.

OpenRouter reports its own per-request cost, but Gemini (called directly) does not, so
we keep a local USD-per-million-token table. OpenRouter's catalog lists Google's models
at Google's list prices, so it doubles as the source for bare Gemini ids: the entry for
"google/gemini-2.5-flash" is also stored under "gemini-2.5-flash".

Run manually whenever prices change:  python3 python/refresh_model_pricing.py
"""
import json
import sys
import urllib.request
from pathlib import Path

CATALOG_URL = "https://openrouter.ai/api/v1/models"
OUT_PATH = Path(__file__).resolve().parent / "model_pricing.json"

# Google aliases that the Gemini API accepts but OpenRouter does not list. Each maps
# to the catalog id whose price applies. Gemini's response carries "modelVersion", so
# jobs decoded through an alias are stored under the concrete version when available.
GEMINI_ALIASES = {
    "gemini-flash-latest": "google/gemini-3.8-flash",
    "gemini-flash-lite-latest": "google/gemini-3.5-flash-lite",
    "gemini-pro-latest": "google/gemini-3.1-pro-preview",
    "gemini-3-pro-preview": "google/gemini-3.1-pro-preview",
}


def main() -> int:
    with urllib.request.urlopen(CATALOG_URL, timeout=30) as response:
        catalog = json.loads(response.read().decode("utf-8"))

    pricing: dict[str, dict] = {}
    for model in catalog.get("data", []):
        model_id = str(model.get("id") or "")
        prices = model.get("pricing") or {}
        try:
            prompt_usd = float(prices.get("prompt") or 0) * 1_000_000
            completion_usd = float(prices.get("completion") or 0) * 1_000_000
        except (TypeError, ValueError):
            continue
        if not model_id:
            continue
        entry = {"input_per_m": round(prompt_usd, 6), "output_per_m": round(completion_usd, 6)}
        pricing[model_id] = entry
        if model_id.startswith("google/"):
            pricing[model_id[len("google/"):]] = entry

    for alias, target in GEMINI_ALIASES.items():
        if target in pricing:
            pricing[alias] = dict(pricing[target], alias_of=target)

    OUT_PATH.write_text(json.dumps(pricing, indent=1, sort_keys=True) + "\n", encoding="utf-8")
    print(f"Wrote {len(pricing)} price entries to {OUT_PATH}")
    return 0


if __name__ == "__main__":
    sys.exit(main())

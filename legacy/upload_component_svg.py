#!/usr/bin/env python3
"""
Upload component SVG and parts JSON to WooCommerce term meta.

For a given VIN serial and category title, finds the correct leaf product_cat
via the WC REST API, then POSTs the svg_code and parts array to the custom
/wp-json/custom/v1/set-component-meta endpoint.

Usage (test):
    python upload_component_svg.py LSFAM11C4RA133898 airbag
"""

import json
import sys
import requests
from pathlib import Path

# ── Config ────────────────────────────────────────────────────────────────────
WORDPRESS_URL  = 'https://shane.maxusvanparts.co.uk'
CONSUMER_KEY   = 'ck_f1afc5dfb58879e9f5cb2a00e2d0a80c3d72275c'
CONSUMER_SECRET = 'cs_4d5dd541b8f50d4c562462bd4fc0c1c814c7c4b3'
MVP_API_SECRET  = 'mvp-comp-2026-xK9pLq'   # must match MVP_COMPONENT_API_SECRET in functions.php

JSON_DIR = Path(__file__).parent / 'epcdata_json'

AUTH = (CONSUMER_KEY, CONSUMER_SECRET)
WC_BASE = f'{WORDPRESS_URL}/wp-json/wc/v3'
CUSTOM_BASE = f'{WORDPRESS_URL}/wp-json/custom/v1'
# ─────────────────────────────────────────────────────────────────────────────


def wc_get(path, params=None):
    params = params or {}
    params.setdefault('per_page', 100)
    r = requests.get(f'{WC_BASE}{path}', params=params, auth=AUTH, timeout=20)
    r.raise_for_status()
    return r.json()


def find_category_by_slug(slug):
    """Return the first WC product category whose slug matches exactly."""
    results = wc_get('/products/categories', {'slug': slug})
    return results[0] if results else None


def find_children(parent_id):
    """Return all direct child categories of parent_id."""
    return wc_get('/products/categories', {'parent': parent_id})


def load_json(serial):
    path = JSON_DIR / f'{serial.upper()}.json'
    if not path.exists():
        raise FileNotFoundError(f'JSON not found: {path}')
    with open(path, encoding='utf-8') as f:
        return json.load(f)


def upload_component(serial: str, category_title: str):
    print(f'\n=== Upload: {serial} / category "{category_title}" ===\n')

    # 1. Load the JSON tree
    data = load_json(serial)

    # 2. Find the matching category in JSON
    vin_serial = serial.upper()
    cat_entry = next(
        (c for c in data.get('categories', [])
         if c.get('title', '').strip().lower() == category_title.strip().lower()),
        None
    )
    if not cat_entry:
        print(f'✗ Category "{category_title}" not found in {serial}.json')
        sys.exit(1)

    components = cat_entry.get('components', [])
    if not components:
        print(f'✗ No components found under "{category_title}"')
        sys.exit(1)

    print(f'✓ Found {len(components)} component(s) under "{category_title}"')

    # 3. Find the VIN-level WC category
    vin_cat = find_category_by_slug(vin_serial.lower())
    if not vin_cat:
        print(f'✗ WC category with slug "{vin_serial.lower()}" not found')
        sys.exit(1)
    print(f'✓ VIN category: {vin_cat["name"]} (id={vin_cat["id"]})')

    # 4. Find the mid-level category (child of VIN, matching category_title)
    mid_cats = find_children(vin_cat['id'])
    mid_cat = next(
        (c for c in mid_cats
         if category_title.strip().lower() in c['name'].lower()),
        None
    )
    if not mid_cat:
        names = [c['name'] for c in mid_cats]
        print(f'✗ Mid-level category matching "{category_title}" not found. Children: {names}')
        sys.exit(1)
    print(f'✓ Mid-level category: {mid_cat["name"]} (id={mid_cat["id"]})')

    # 5. Get leaf categories (children of mid)
    leaf_cats = find_children(mid_cat['id'])
    print(f'✓ Leaf categories under mid: {[c["name"] for c in leaf_cats]}')

    # 6. For each component in JSON, match to a leaf category and upload
    results = []
    for comp in components:
        comp_title = comp.get('title', '')
        svg_code   = comp.get('svg_code', '')
        parts      = comp.get('parts', [])

        if not svg_code and not parts:
            print(f'  – Skipping "{comp_title}" (no svg or parts)')
            continue

        # Match leaf: title after " - " (e.g. "QE471A001 - AirBag" → "AirBag")
        comp_suffix = comp_title.split(' - ', 1)[-1].strip().lower() if ' - ' in comp_title else comp_title.strip().lower()

        leaf = next(
            (c for c in leaf_cats
             if comp_suffix in c['name'].strip().lower() or c['name'].strip().lower() in comp_suffix),
            None
        )

        # Fallback: if only one leaf, use it
        if not leaf and len(leaf_cats) == 1:
            leaf = leaf_cats[0]
            print(f'  ℹ  Using only leaf "{leaf["name"]}" for "{comp_title}" (single match fallback)')

        if not leaf:
            print(f'  ✗ No leaf match for component "{comp_title}" (suffix="{comp_suffix}")')
            continue

        print(f'\n  Component : {comp_title}')
        print(f'  Leaf cat  : {leaf["name"]} (id={leaf["id"]})')
        print(f'  Parts     : {len(parts)}')
        print(f'  SVG       : {"yes" if svg_code else "no"}')

        # 7. POST to custom endpoint (no WP user auth needed — uses shared secret)
        payload = {
            'term_id':    leaf['id'],
            'parts_json': json.dumps(parts),
            'secret':     MVP_API_SECRET,
        }
        if svg_code:
            payload['svg_code'] = svg_code

        r = requests.post(
            f'{CUSTOM_BASE}/set-component-meta',
            json=payload,
            timeout=30,
        )

        if r.status_code == 200:
            resp = r.json()
            print(f'  ✓ Saved: {resp.get("updated")}')
            results.append({'component': comp_title, 'term_id': leaf['id'], 'success': True})
        else:
            print(f'  ✗ Failed {r.status_code}: {r.text[:200]}')
            results.append({'component': comp_title, 'term_id': leaf['id'], 'success': False, 'error': r.text[:200]})

    print(f'\n=== Done: {sum(1 for r in results if r["success"])}/{len(results)} uploaded ===\n')
    return results


if __name__ == '__main__':
    serial = sys.argv[1] if len(sys.argv) > 1 else 'LSFAM11C4RA133898'
    category = sys.argv[2] if len(sys.argv) > 2 else 'airbag'
    upload_component(serial, category)

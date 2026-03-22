#!/usr/bin/env python3
"""
Test: Push airbag component SVG + parts JSON into WooCommerce leaf category term meta.
LSFAM11C4RA133898 → airbag → QE471A001 - AirBag

Steps:
  1. Read LSFAM11C4RA133898.json, extract the 'airbag' category components
  2. Find the VIN-level WC category by slug
  3. Walk down: VIN → airbag mid-category → leaf component category
  4. PUT component_svg_code and component_parts_json as term meta via WC REST API

Once pushed, the PHP in functions.php (section 13) will render the diagram
on the leaf category page automatically.
"""

import json
import sys
from pathlib import Path

import requests

# === Config ===
WORDPRESS_URL   = 'https://shane.maxusvanparts.co.uk'
CONSUMER_KEY    = 'ck_f1afc5dfb58879e9f5cb2a00e2d0a80c3d72275c'
CONSUMER_SECRET = 'cs_4d5dd541b8f50d4c562462bd4fc0c1c814c7c4b3'

SERIAL         = 'LSFAM11C4RA133898'
TARGET_CAT     = 'airbag'   # JSON category title to process

AUTH = (CONSUMER_KEY, CONSUMER_SECRET)
BASE = WORDPRESS_URL + '/wp-json/wc/v3'


def wc_get(endpoint, params=None):
    params = {**(params or {}), 'per_page': 100}
    r = requests.get(f'{BASE}{endpoint}', auth=AUTH, params=params, timeout=30)
    r.raise_for_status()
    return r.json()


def wc_put(endpoint, data):
    r = requests.put(f'{BASE}{endpoint}', auth=AUTH, json=data, timeout=30)
    r.raise_for_status()
    return r.json()


def find_category_by_slug(slug, parent=None):
    """Search WC categories for exact slug match, optionally under a parent."""
    params = {'slug': slug}
    if parent is not None:
        params['parent'] = parent
    results = wc_get('/products/categories', params)
    return next((c for c in results if c['slug'] == slug), None)


def get_children(parent_id):
    return wc_get('/products/categories', {'parent': parent_id})


# ── Step 1: Load JSON ─────────────────────────────────────────────────────────
json_path = Path(__file__).parent / 'epcdata_json' / f'{SERIAL}.json'
if not json_path.exists():
    print(f'ERROR: JSON file not found: {json_path}')
    sys.exit(1)

with open(json_path, encoding='utf-8') as f:
    tree = json.load(f)

airbag_cat_data = next(
    (c for c in tree.get('categories', []) if c.get('title', '').lower() == TARGET_CAT),
    None
)
if not airbag_cat_data:
    print(f'ERROR: category "{TARGET_CAT}" not found in JSON')
    sys.exit(1)

components = airbag_cat_data.get('components', [])
print(f'Found {len(components)} component(s) in "{TARGET_CAT}"')


# ── Step 2: Find VIN category in WC ─────────────────────────────────────────
vin_slug = SERIAL.lower()
print(f'\nLooking up VIN category: slug="{vin_slug}"')
vin_cat = find_category_by_slug(vin_slug)
if not vin_cat:
    # Try searching
    all_cats = wc_get('/products/categories', {'search': vin_slug})
    vin_cat = next((c for c in all_cats if c['slug'] == vin_slug), None)
if not vin_cat:
    print(f'ERROR: VIN category "{vin_slug}" not found in WooCommerce')
    sys.exit(1)

vin_id = vin_cat['id']
print(f'  ✓ VIN category: id={vin_id}  name={vin_cat["name"]}')


# ── Step 3: Find airbag mid-level category (child of VIN) ────────────────────
print(f'\nLooking for "{TARGET_CAT}" mid-category under VIN id={vin_id}')
mid_cats = get_children(vin_id)
airbag_mid = next(
    (c for c in mid_cats if TARGET_CAT in c['slug'].lower()),
    None
)
if not airbag_mid:
    print(f'ERROR: no mid-level category with "{TARGET_CAT}" in slug found under VIN')
    print('  Available:', [c['slug'] for c in mid_cats])
    sys.exit(1)

mid_id = airbag_mid['id']
print(f'  ✓ Mid category: id={mid_id}  slug={airbag_mid["slug"]}')


# ── Step 4: Get leaf categories (children of mid) ────────────────────────────
leaf_cats = get_children(mid_id)
print(f'\nLeaf categories under mid id={mid_id}:')
for lc in leaf_cats:
    print(f'  id={lc["id"]}  slug={lc["slug"]}  name={lc["name"]}')

if not leaf_cats:
    print('ERROR: no leaf categories found')
    sys.exit(1)


# ── Step 5: Match each JSON component to a leaf and push meta ────────────────
print()
for component in components:
    comp_title = component.get('title', '')
    svg_code   = component.get('svg_code', '')
    parts      = component.get('parts', [])

    if not svg_code:
        print(f'SKIP  "{comp_title}" — no SVG')
        continue

    # Match leaf: compare component title keywords against leaf slug/name
    comp_words = set(comp_title.lower().replace('-', ' ').split())
    best = None
    best_score = 0
    for leaf in leaf_cats:
        leaf_words = set(leaf['slug'].replace('-', ' ').split())
        score = len(comp_words & leaf_words)
        if score > best_score:
            best_score = score
            best = leaf

    # Fallback: only one leaf available
    if best is None and len(leaf_cats) == 1:
        best = leaf_cats[0]

    if best is None:
        print(f'WARN  "{comp_title}" — no leaf match found, skipping')
        continue

    print(f'PUSH  "{comp_title}"')
    print(f'      → leaf id={best["id"]}  slug={best["slug"]}')
    print(f'      SVG: {len(svg_code):,} chars   Parts: {len(parts)}')

    result = wc_put(f'/products/categories/{best["id"]}', {
        'meta_data': [
            {'key': 'component_svg_code',   'value': svg_code},
            {'key': 'component_parts_json', 'value': json.dumps(parts)},
            {'key': 'component_title',      'value': comp_title},
        ]
    })

    stored_keys = [m['key'] for m in result.get('meta_data', [])]
    if 'component_svg_code' in stored_keys:
        print(f'      ✓ Stored OK')
    else:
        print(f'      ✗ Meta NOT in response — check WC API exposes term meta_data')
        print(f'      Response keys: {stored_keys}')

print('\nDone.')

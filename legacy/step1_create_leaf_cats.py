#!/usr/bin/env python3
"""
STEP 1 â€” Create leaf WooCommerce categories from a serial JSON file.

Rules:
  - VIN category must exist   â†’ halt if not found
  - Mid-categories must exist â†’ halt if any is missing (never create mid-cats)
  - Leaf categories (components) are created if not already present
  - Writes {SERIAL}_cat_map.json for use by steps 2 and 3

Usage:
    python step1_create_leaf_cats.py LSFAM11C4RA133898             # live run
    python step1_create_leaf_cats.py LSFAM11C4RA133898 --dry-run  # check only, no changes
"""

import argparse
import json
import sys
sys.stdout.reconfigure(encoding='utf-8', errors='replace')
import time
import requests
from pathlib import Path

# â”€â”€ Config â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
WORDPRESS_URL   = 'https://shane.maxusvanparts.co.uk'
CONSUMER_KEY    = 'ck_f1afc5dfb58879e9f5cb2a00e2d0a80c3d72275c'
CONSUMER_SECRET = 'cs_4d5dd541b8f50d4c562462bd4fc0c1c814c7c4b3'

JSON_DIR  = Path(__file__).parent / 'epcdata_json'
AUTH      = (CONSUMER_KEY, CONSUMER_SECRET)
WC_BASE   = f'{WORDPRESS_URL}/wp-json/wc/v3'
# â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€


def wc_get(path, params=None):
    p = {'per_page': 100}
    if params:
        p.update(params)
    r = requests.get(f'{WC_BASE}{path}', params=p, auth=AUTH, timeout=20)
    r.raise_for_status()
    return r.json()


def wc_post(path, body):
    r = requests.post(f'{WC_BASE}{path}', json=body, auth=AUTH, timeout=20)
    r.raise_for_status()
    return r.json()


def get_all_children(parent_id):
    """Fetch ALL child categories of parent_id (handles >100 via pagination)."""
    results, page = [], 1
    while True:
        batch = wc_get('/products/categories', {'parent': parent_id, 'page': page, 'per_page': 100})
        if not batch:
            break
        results.extend(batch)
        if len(batch) < 100:
            break
        page += 1
    return results


def find_by_name(cats, name):
    """Case-insensitive name match."""
    nl = name.strip().lower()
    return next((c for c in cats if c['name'].strip().lower() == nl), None)


import re as _re

def _normalize(s):
    """Lowercase, & â†’ and, replace punctuation with space, collapse spaces."""
    s = s.lower().strip()
    s = s.replace('&', 'and')
    s = _re.sub(r'[^a-z0-9 ]', ' ', s)
    s = _re.sub(r'\s+', ' ', s).strip()
    return s


def _compact(s):
    """Like _normalize but with all spaces removed â€” handles concatenated WC slugs."""
    return _normalize(s).replace(' ', '')


def find_by_name_fuzzy(cats, name):
    """Match mid-cats tolerating & vs 'and', punctuation differences, and concatenation."""
    nl, nc = _normalize(name), _compact(name)
    for c in cats:
        wc, wcc = _normalize(c['name']), _compact(c['name'])
        if nl == wc or nl in wc or wc in nl or nc == wcc or nc in wcc or wcc in nc:
            return c
    return None


def leaf_name_from_title(comp_title):
    """'QE471A001 - AirBag' â†’ 'AirBag'"""
    if ' - ' in comp_title:
        return comp_title.split(' - ', 1)[1].strip()
    return comp_title.strip()


def run(serial, dry_run=False):
    serial = serial.upper()
    if dry_run:
        print('*** DRY RUN â€” no categories will be created ***')
    json_path = JSON_DIR / f'{serial}.json'
    if not json_path.exists():
        sys.exit(f'âœ-- JSON not found: {json_path}')

    data = json.loads(json_path.read_text(encoding='utf-8'))
    categories = data.get('categories', [])
    if not categories:
        sys.exit('âœ-- No categories found in JSON')

    mode = ' [DRY RUN]' if dry_run else ''
    print(f'\n=== STEP 1: Create leaf categories â€” {serial}{mode} ===')
    print(f'  Mid-categories in JSON : {len(categories)}')
    print(f'  Components (leaf) total: {sum(len(c.get("components",[])) for c in categories)}\n')

    # â”€â”€ 1. Find VIN category â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    print(f'Looking up VIN category (slug={serial.lower()}) ...')
    results = wc_get('/products/categories', {'slug': serial.lower()})
    if not results:
        sys.exit(f'âœ-- HALT: VIN category with slug "{serial.lower()}" not found in WooCommerce. '
                 f'Run the serial import first.')
    vin_cat = results[0]
    print(f'  âœ“ VIN: {vin_cat["name"]} (id={vin_cat["id"]})\n')

    # â”€â”€ 2. Preload mid-cats (children of VIN) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    print('Loading mid-categories under VIN ...')
    mid_cats_wc = get_all_children(vin_cat['id'])
    print(f'  Found {len(mid_cats_wc)} mid-categories in WC\n')

    # â”€â”€ 3. Process each JSON category â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    cat_map = {
        'serial':     serial,
        'vin_cat_id': vin_cat['id'],
        'categories': [],
    }

    errors     = []
    created    = 0
    existing   = 0

    for cat_entry in categories:
        mid_title = cat_entry.get('title', '').strip()
        components = cat_entry.get('components', [])

        # â”€â”€ Must find mid-cat â€” NEVER create â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        mid_wc = find_by_name_fuzzy(mid_cats_wc, mid_title)
        if not mid_wc:
            msg = (f'âœ-- HALT: Mid-category "{mid_title}" not found under VIN "{serial}". '
                   f'Available: {[c["name"] for c in mid_cats_wc]}')
            sys.exit(msg)

        print(f'[{mid_wc["name"]}] (id={mid_wc["id"]}) â€” {len(components)} component(s)')

        # Preload existing leaf cats under this mid-cat
        leaf_cats_wc = get_all_children(mid_wc['id'])
        leaf_index   = {c['name'].strip().lower(): c for c in leaf_cats_wc}

        cat_entry_map = {
            'json_title': mid_title,
            'mid_cat_id': mid_wc['id'],
            'components': [],
        }

        for comp in components:
            comp_title = comp.get('title', '').strip()
            leaf_name  = leaf_name_from_title(comp_title)
            leaf_key   = leaf_name.lower()

            if leaf_key in leaf_index:
                leaf_id = leaf_index[leaf_key]['id']
                print(f'  = {leaf_name} (id={leaf_id}) â€” already exists')
                existing += 1
            else:
                if dry_run:
                    print(f'  + [DRY RUN] Would create "{leaf_name}" under "{mid_wc["name"]}" (id={mid_wc["id"]})')
                    leaf_id = None
                    created += 1
                else:
                    # Create the leaf category
                    print(f'  + Creating "{leaf_name}" under mid id={mid_wc["id"]} ...', end=' ', flush=True)
                    try:
                        new_cat = wc_post('/products/categories', {
                            'name':   leaf_name,
                            'parent': mid_wc['id'],
                        })
                        leaf_id = new_cat['id']
                        leaf_index[leaf_key] = new_cat
                        print(f'id={leaf_id}')
                        created += 1
                        time.sleep(0.3)  # gentle rate limiting
                    except requests.HTTPError as e:
                        body = {}
                        try:
                            body = e.response.json()
                        except Exception:
                            pass
                        if body.get('code') == 'term_exists' and body.get('data', {}).get('resource_id'):
                            leaf_id = body['data']['resource_id']
                            leaf_index[leaf_key] = {'id': leaf_id, 'name': leaf_name}
                            print(f'already exists (id={leaf_id})')
                            created += 1
                        else:
                            err = f'Failed to create "{leaf_name}": {e.response.text[:200]}'
                            print(f'\n  âœ-- {err}')
                            errors.append(err)
                            leaf_id = None

            cat_entry_map['components'].append({
                'json_title': comp_title,
                'leaf_name':  leaf_name,
                'leaf_cat_id': leaf_id,
            })

        cat_map['categories'].append(cat_entry_map)

    # â”€â”€ 4. Save cat_map (skipped in dry-run) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    print(f'\n=== Done{" [DRY RUN â€” no changes made]" if dry_run else ""} ===')
    print(f'  {"Would create" if dry_run else "Created"}  : {created}')
    print(f'  Existing : {existing}')
    print(f'  Errors   : {len(errors)}')
    if not dry_run:
        map_path = Path(__file__).parent / f'{serial}_cat_map.json'
        map_path.write_text(json.dumps(cat_map, indent=2, ensure_ascii=False), encoding='utf-8')
        print(f'  Map saved: {map_path}')
    else:
        print('  Map file : not written (dry run)')

    if errors:
        print('\nErrors:')
        for e in errors:
            print(f'  âœ-- {e}')
        sys.exit(1)


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description='Step 1: create leaf WC categories from JSON')
    parser.add_argument('serial', help='Vehicle serial / VIN (e.g. LSFAM11C4RA133898)')
    parser.add_argument('--dry-run', action='store_true',
                        help='Validate everything and report what would be created without making changes')
    args = parser.parse_args()
    run(args.serial, dry_run=args.dry_run)

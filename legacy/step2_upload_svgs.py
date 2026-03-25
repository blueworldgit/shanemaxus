#!/usr/bin/env python3
"""
STEP 2 -- Upload SVG diagrams and parts JSON to each leaf category's term meta.

Reads {SERIAL}_cat_map.json (produced by step1_create_leaf_cats.py) and the
original JSON file, then POSTs svg_code + parts_json to the custom WP endpoint
for every leaf category.

Usage:
    python step2_upload_svgs.py LSFAM11C4RA133898
"""

import argparse
import json
import sys
sys.stdout.reconfigure(encoding='utf-8', errors='replace')
import time
import requests
from pathlib import Path

# ── Config ─────────────────────────────────────────────────────────────────
WORDPRESS_URL  = 'https://shane.maxusvanparts.co.uk'
CONSUMER_KEY   = 'ck_f1afc5dfb58879e9f5cb2a00e2d0a80c3d72275c'
CONSUMER_SECRET = 'cs_4d5dd541b8f50d4c562462bd4fc0c1c814c7c4b3'
MVP_API_SECRET  = 'mvp-comp-2026-xK9pLq'   # must match MVP_COMPONENT_API_SECRET in functions.php

JSON_DIR    = Path(__file__).parent / 'epcdata_json'
AUTH        = (CONSUMER_KEY, CONSUMER_SECRET)
CUSTOM_BASE = f'{WORDPRESS_URL}/wp-json/custom/v1'
# ───────────────────────────────────────────────────────────────────────────


def run(serial, dry_run=False):
    serial = serial.upper()

    # ── Load cat map ──────────────────────────────────────────────────────
    map_path = Path(__file__).parent / f'{serial}_cat_map.json'
    if not map_path.exists():
        sys.exit(f'[!!] Cat map not found: {map_path}\n  Run step1_create_leaf_cats.py first.')
    cat_map = json.loads(map_path.read_text(encoding='utf-8'))

    # ── Load original JSON ────────────────────────────────────────────────
    json_path = JSON_DIR / f'{serial}.json'
    data      = json.loads(json_path.read_text(encoding='utf-8'))

    # Build lookup: json_title -> component dict
    comp_index = {}
    for cat_entry in data.get('categories', []):
        for comp in cat_entry.get('components', []):
            comp_index[comp['title'].strip()] = comp

    mode = ' [DRY RUN]' if dry_run else ''
    print(f'\n=== STEP 2: Upload SVGs + parts JSON -- {serial}{mode} ===\n')

    ok, skipped, failed = 0, 0, 0

    for cat_entry in cat_map['categories']:
        mid_title = cat_entry['json_title']
        print(f'[{mid_title}]')

        for comp_map in cat_entry['components']:
            comp_title = comp_map['json_title']
            leaf_id    = comp_map['leaf_cat_id']
            leaf_name  = comp_map['leaf_name']

            if leaf_id is None:
                print(f'  – Skipped "{comp_title}" (no leaf_cat_id)')
                skipped += 1
                continue

            comp = comp_index.get(comp_title)
            if not comp:
                print(f'  – Skipped "{comp_title}" (not found in JSON)')
                skipped += 1
                continue

            svg_code = comp.get('svg_code', '').strip()
            parts    = comp.get('parts', [])

            if not svg_code and not parts:
                print(f'  – Skipped "{comp_title}" (no svg or parts)')
                skipped += 1
                continue

            payload = {
                'term_id':    leaf_id,
                'parts_json': json.dumps(parts),
                'secret':     MVP_API_SECRET,
            }
            if svg_code:
                payload['svg_code'] = svg_code

            if dry_run:
                print(f'  ^ [DRY RUN] Would upload: {leaf_name} (id={leaf_id})  parts={len(parts)}  svg={"yes" if svg_code else "no"}')
                ok += 1
                continue

            svg_len  = len(svg_code)
            # Scale timeout with payload size: 90s base + 1s per 10 KB of SVG
            timeout  = 90 + svg_len // 10_000

            print(f'  ^ {leaf_name} (id={leaf_id})  parts={len(parts)}  svg={"yes" if svg_code else "no"}', end=' ', flush=True)

            for attempt in range(1, 4):   # up to 3 attempts
                try:
                    r = requests.post(
                        f'{CUSTOM_BASE}/set-component-meta',
                        json=payload,
                        timeout=timeout,
                    )
                    if r.status_code == 200:
                        updated = r.json().get('updated', [])
                        print(f'[OK] saved {updated}')
                        ok += 1
                        break
                    else:
                        print(f'[!!] {r.status_code}: {r.text[:150]}')
                        failed += 1
                        break   # HTTP errors won't improve with a retry
                except requests.ConnectionError as e:
                    if attempt < 3:
                        print(f'[retry {attempt}]', end=' ', flush=True)
                        time.sleep(5 * attempt)
                    else:
                        print(f'[!!] connection error after 3 attempts: {e}')
                        failed += 1
                except requests.RequestException as e:
                    print(f'[!!] request error: {e}')
                    failed += 1
                    break

            time.sleep(0.2)

    print(f'\n=== Done{" [DRY RUN]" if dry_run else ""} ===')
    print(f'  {"Would upload" if dry_run else "Uploaded"} : {ok}')
    print(f'  Skipped  : {skipped}')
    print(f'  Failed   : {failed}')

    if failed:
        sys.exit(1)


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description='Step 2: upload SVGs and parts JSON to leaf categories')
    parser.add_argument('serial', help='Vehicle serial / VIN (e.g. LSFAM11C4RA133898)')
    parser.add_argument('--dry-run', action='store_true',
                        help='Show what would be uploaded without making any changes')
    args = parser.parse_args()
    run(args.serial, dry_run=args.dry_run)

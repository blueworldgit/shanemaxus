#!/usr/bin/env python3
"""
STEP 3 -- Attach existing WooCommerce products to their leaf categories.

For each part in the JSON:
  - Finds the WC product by original_sku == part_number (via custom endpoint)
  - If found and leaf category not already assigned -> adds it via WC API
  - NEVER creates products, NEVER removes existing categories

Reads {SERIAL}_cat_map.json (produced by step1_create_leaf_cats.py).

Usage:
    python step3_attach_products.py LSFAM11C4RA133898
"""

import argparse
import json
import sys
sys.stdout.reconfigure(encoding='utf-8', errors='replace')
from concurrent.futures import ThreadPoolExecutor, as_completed
import requests
from pathlib import Path

# ── Config ─────────────────────────────────────────────────────────────────
WORDPRESS_URL   = 'https://shane.maxusvanparts.co.uk'
CONSUMER_KEY    = 'ck_f1afc5dfb58879e9f5cb2a00e2d0a80c3d72275c'
CONSUMER_SECRET = 'cs_4d5dd541b8f50d4c562462bd4fc0c1c814c7c4b3'

JSON_DIR        = Path(__file__).parent / 'epcdata_json'
AUTH            = (CONSUMER_KEY, CONSUMER_SECRET)
MVP_API_SECRET  = 'mvp-comp-2026-xK9pLq'
WC_BASE         = f'{WORDPRESS_URL}/wp-json/wc/v3'
CUSTOM_BASE     = f'{WORDPRESS_URL}/wp-json/custom/v1'
WORKERS         = 20   # parallel workers for PUTs and category fetches
BULK_CHUNK      = 500  # SKUs per bulk request
SESSION         = requests.Session()
# ───────────────────────────────────────────────────────────────────────────


def find_product_by_sku(original_sku, vehicle_serial):
    """Use the custom endpoint to look up a product by original_sku meta."""
    r = SESSION.get(
        f'{CUSTOM_BASE}/products-by-sku',
        params={'original_sku': original_sku, 'secret': MVP_API_SECRET},
        timeout=20,
    )
    if r.status_code == 404:
        return None
    r.raise_for_status()
    data = r.json()
    # endpoint returns {"found": N, "products": [...]}
    if isinstance(data, dict):
        products = data.get('products') or []
        if products:
            return products[0]
        return None
    # legacy: plain list
    if isinstance(data, list) and data:
        return data[0]
    return None


def bulk_fetch_skus(all_skus):
    """POST all SKUs to bulk endpoint in chunks. Returns dict: sku -> product info (only found ones)."""
    skus = list(all_skus)
    result = {}
    total_chunks = (len(skus) + BULK_CHUNK - 1) // BULK_CHUNK
    for i in range(0, len(skus), BULK_CHUNK):
        chunk = skus[i:i + BULK_CHUNK]
        chunk_num = i // BULK_CHUNK + 1
        print(f'    Bulk request {chunk_num}/{total_chunks} ({len(chunk)} SKUs)...')
        r = SESSION.post(
            f'{CUSTOM_BASE}/products-by-skus-bulk',
            json={'skus': chunk, 'secret': MVP_API_SECRET},
            timeout=60,
        )
        r.raise_for_status()
        result.update(r.json())
    return result


def batch_fetch_categories(product_infos):
    """Batch-fetch category IDs for a list of product dicts ({id, parent_id, type}).
    Returns dict: product_id (int) -> set of category ids.
    For variations the categories come from the parent product.
    """
    # Decide which WC product IDs to actually fetch
    direct_ids = set()
    parent_ids = set()  # variation parents
    for p in product_infos:
        if p.get('type') == 'variation' and p.get('parent_id'):
            parent_ids.add(int(p['parent_id']))
        else:
            direct_ids.add(int(p['id']))
    all_target_ids = list(direct_ids | parent_ids)

    fetched = {}   # wc product id -> set of cat ids
    total   = len(all_target_ids)

    def _fetch_batch(batch):
        r = SESSION.get(
            f'{WC_BASE}/products',
            params={'include': ','.join(str(x) for x in batch), 'per_page': 100},
            auth=AUTH,
            timeout=30,
        )
        r.raise_for_status()
        return {int(prod['id']): {int(c['id']) for c in prod.get('categories', [])} for prod in r.json()}

    batches = [all_target_ids[i:i + 100] for i in range(0, total, 100)]
    done = 0
    with ThreadPoolExecutor(max_workers=WORKERS) as ex:
        futures = {ex.submit(_fetch_batch, b): b for b in batches}
        for fut in as_completed(futures):
            fetched.update(fut.result())
            done += len(futures[fut])
            print(f'    {done}/{total} products fetched...')

    # Map each original product_id to its (possibly parent's) category set
    result = {}
    for p in product_infos:
        pid = int(p['id'])
        if p.get('type') == 'variation' and p.get('parent_id'):
            result[pid] = fetched.get(int(p['parent_id']), set())
        else:
            result[pid] = fetched.get(pid, set())
    return result


def add_category_to_product(product_id, product_info, current_ids, leaf_cat_id):
    """Add leaf_cat_id to the product (or its parent if a variation). Returns (updated, new_ids)."""
    if leaf_cat_id in current_ids:
        return False, current_ids

    # For variations, PUT goes to the parent
    target_id = int(product_info.get('parent_id') or product_id) \
        if product_info.get('type') == 'variation' else product_id

    new_cats = [{'id': cid} for cid in current_ids] + [{'id': leaf_cat_id}]
    r = SESSION.put(
        f'{WC_BASE}/products/{target_id}',
        json={'categories': new_cats},
        auth=AUTH,
        timeout=20,
    )
    r.raise_for_status()
    return True, current_ids | {leaf_cat_id}


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
    print(f'\n=== STEP 3: Attach products to leaf categories -- {serial}{mode} ===\n')

    # ── Collect all unique SKUs across the entire file upfront ────────────
    all_skus = set()
    for cat_entry in cat_map['categories']:
        for comp_map in cat_entry['components']:
            if comp_map['leaf_cat_id'] is None:
                continue
            comp = comp_index.get(comp_map['json_title'], {})
            for part in comp.get('parts', []):
                sku = (part.get('part_number') or '').strip().upper()
                if sku:
                    all_skus.add(sku)

    print(f'  Fetching {len(all_skus)} unique SKUs (bulk)...')

    # ── Bulk SKU lookup (1-few requests total) ────────────────────────────
    try:
        sku_cache = bulk_fetch_skus(all_skus)   # sku -> product dict (only found ones)
    except requests.RequestException as e:
        sys.exit(f'[!!] Bulk SKU fetch failed: {e}')
    sku_errors = {}

    # ── Fallback: for not-found SKUs with a hyphen, try the base SKU ─────
    # e.g. C00073044-BLA -> try C00073044 (parent variable product)
    missing = {sku for sku in all_skus if sku not in sku_cache and '-' in sku}
    if missing:
        base_map = {}   # base_sku -> [full_skus]
        for sku in missing:
            base = sku.rsplit('-', 1)[0]
            base_map.setdefault(base, []).append(sku)

        print(f'  Fallback lookup for {len(base_map)} base SKUs ({len(missing)} variant SKUs)...')
        try:
            base_results = bulk_fetch_skus(set(base_map.keys()))
        except requests.RequestException as e:
            print(f'  [!!] Fallback bulk fetch failed: {e}')
            base_results = {}

        for base_sku, product in base_results.items():
            for full_sku in base_map[base_sku]:
                sku_cache[full_sku] = product   # map variant -> parent product

    # ── Batch-fetch current categories for all found products (dry-run and live) ─
    product_cat_cache = {}
    found_products = [p for p in sku_cache.values() if p]
    if found_products:
        print(f'  Fetching current categories for {len(found_products)} products (batched)...')
        try:
            product_cat_cache = batch_fetch_categories(found_products)
        except requests.RequestException as e:
            print(f'  [!!] Failed to batch-fetch categories: {e}')

    # ── Pass 1: walk cat_map, record what needs doing ─────────────────────
    # pending_updates: effective_prod_id -> set of leaf_cat_ids to add
    # effective_prod_id is parent_id for variations, else prod_id
    pending_updates  = {}   # effective_id -> set of leaf_cat_ids to add
    product_info_map = {}   # effective_id -> product dict
    total_parts  = 0
    found_count  = 0
    already_ok   = 0
    not_found    = 0
    missing_skus = []
    seen_skus    = set()

    for cat_entry in cat_map['categories']:
        mid_title = cat_entry['json_title']
        print(f'[{mid_title}]')

        for comp_map in cat_entry['components']:
            comp_title = comp_map['json_title']
            leaf_id    = comp_map['leaf_cat_id']
            leaf_name  = comp_map['leaf_name']

            if leaf_id is None:
                print(f'  - Skipped "{comp_title}" (no leaf_cat_id)')
                continue

            comp  = comp_index.get(comp_title, {})
            parts = comp.get('parts', [])
            if not parts:
                continue

            print(f'  [{leaf_name}] {len(parts)} parts')

            for part in parts:
                sku = (part.get('part_number') or '').strip().upper()
                if not sku or sku in seen_skus:
                    continue
                seen_skus.add(sku)
                total_parts += 1

                product = sku_cache.get(sku)
                if not product:
                    print(f'    - {sku}: not found in WC (no product)')
                    not_found += 1
                    missing_skus.append(sku)
                    continue

                found_count += 1
                prod_id = int(product['id'])
                eff_id = int(product.get('parent_id') or prod_id) \
                    if product.get('type') == 'variation' else prod_id
                current_ids = product_cat_cache.get(prod_id, set())

                if leaf_id in current_ids:
                    print(f'    = {sku} (id={prod_id}): already in leaf "{leaf_name}"')
                    already_ok += 1
                    continue

                print(f'    + {"[DRY RUN] Would attach" if dry_run else "Queued"} {sku} (id={prod_id}) -> leaf "{leaf_name}" (cat_id={leaf_id})')
                if not dry_run:
                    pending_updates.setdefault(eff_id, set()).add(leaf_id)
                    product_info_map[eff_id] = product
                    # Optimistically update cache so same product doesn't double-queue
                    product_cat_cache[prod_id] = current_ids | {leaf_id}

    if dry_run:
        print(f'\n=== Done [DRY RUN] ===')
        print(f'  Unique SKUs processed          : {total_parts}')
        print(f'  Products found                 : {found_count}')
        print(f'  Already correct                : {already_ok}')
        print(f'  Products not found in WC       : {not_found}')
        if missing_skus:
            print(f'  Missing SKUs: {", ".join(missing_skus)}')
        return

    # ── Pass 2: parallel PUTs (one per product, all leaf cats merged) ──────
    attached = 0
    failed   = 0

    def _do_put(eff_id):
        leaf_ids    = pending_updates[eff_id]
        current_ids = product_cat_cache.get(eff_id, set())
        new_cats    = [{'id': cid} for cid in (current_ids | leaf_ids)]
        r = SESSION.put(
            f'{WC_BASE}/products/{eff_id}',
            json={'categories': new_cats},
            auth=AUTH,
            timeout=20,
        )
        r.raise_for_status()
        return eff_id, len(leaf_ids)

    if pending_updates:
        total_links = sum(len(v) for v in pending_updates.values())
        print(f'\n  Attaching {len(pending_updates)} products ({total_links} leaf-cat assignments) in parallel...')
        with ThreadPoolExecutor(max_workers=WORKERS) as ex:
            futures = {ex.submit(_do_put, eid): eid for eid in pending_updates}
            for fut in as_completed(futures):
                eid = futures[fut]
                try:
                    eid, n = fut.result()
                    attached += n
                    print(f'    [OK] product id={eid}: added {n} leaf cat(s)')
                except requests.RequestException as e:
                    failed += 1
                    print(f'    [!!] product id={eid}: update error: {e}')

    print(f'\n=== Done ===')
    print(f'  Unique SKUs processed          : {total_parts}')
    print(f'  Products found                 : {found_count}')
    print(f'  Newly attached (leaf-cat links) : {attached}')
    print(f'  Already correct                : {already_ok}')
    print(f'  Products not found in WC       : {not_found}')
    if missing_skus:
        print(f'  Missing SKUs: {", ".join(missing_skus)}')
    print(f'  Errors                         : {failed}')

    if failed:
        sys.exit(1)


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description='Step 3: attach existing products to leaf categories')
    parser.add_argument('serial', help='Vehicle serial / VIN (e.g. LSFAM11C4RA133898)')
    parser.add_argument('--dry-run', action='store_true',
                        help='Show what would be attached without making any changes')
    args = parser.parse_args()
    run(args.serial, dry_run=args.dry_run)

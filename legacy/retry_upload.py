#!/usr/bin/env python3
"""Retry a single failed step-2 upload by component title and leaf_cat_id."""
import json
import time
import sys
sys.stdout.reconfigure(encoding='utf-8', errors='replace')
import requests
from pathlib import Path

WORDPRESS_URL   = 'https://shane.maxusvanparts.co.uk'
CONSUMER_KEY    = 'ck_f1afc5dfb58879e9f5cb2a00e2d0a80c3d72275c'
CONSUMER_SECRET = 'cs_4d5dd541b8f50d4c562462bd4fc0c1c814c7c4b3'
MVP_API_SECRET  = 'mvp-comp-2026-xK9pLq'
CUSTOM_BASE     = f'{WORDPRESS_URL}/wp-json/custom/v1'
AUTH            = (CONSUMER_KEY, CONSUMER_SECRET)

SERIAL      = 'LSFAM120XNA160733'
COMP_TITLE  = 'SE5A5A001 - Side Panel-Outer Assembly'
LEAF_CAT_ID = 7665
MAX_RETRIES = 3
RETRY_DELAY = 5   # seconds between retries
TIMEOUT     = 90  # longer timeout for large payloads

JSON_DIR = Path(__file__).parent / 'epcdata_json'

data = json.loads((JSON_DIR / f'{SERIAL}.json').read_text(encoding='utf-8'))

comp = None
for cat in data['categories']:
    for c in cat['components']:
        if c['title'].strip() == COMP_TITLE:
            comp = c
            break

if not comp:
    sys.exit(f'Component not found: {COMP_TITLE}')

print(f'Component : {comp["title"]}')
print(f'Parts     : {len(comp["parts"])}')
print(f'SVG length: {len(comp["svg_code"])} chars')
print(f'Leaf cat  : {LEAF_CAT_ID}\n')

payload = {
    'term_id':    LEAF_CAT_ID,
    'parts_json': json.dumps(comp['parts']),
    'svg_code':   comp['svg_code'],
    'secret':     MVP_API_SECRET,
}

for attempt in range(1, MAX_RETRIES + 1):
    print(f'Attempt {attempt}/{MAX_RETRIES} ...', end=' ', flush=True)
    try:
        r = requests.post(
            f'{CUSTOM_BASE}/set-component-meta',
            json=payload,
            timeout=TIMEOUT,
        )
        if r.status_code == 200:
            print(f'[OK] saved {r.json().get("updated", [])}')
            sys.exit(0)
        else:
            print(f'[!!] HTTP {r.status_code}: {r.text[:200]}')
    except requests.RequestException as e:
        print(f'[!!] {e}')

    if attempt < MAX_RETRIES:
        print(f'  Retrying in {RETRY_DELAY}s...')
        time.sleep(RETRY_DELAY)

print('\nAll attempts failed.')
sys.exit(1)

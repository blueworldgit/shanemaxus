#!/usr/bin/env python3
import json, sys
from pathlib import Path

serial = sys.argv[1] if len(sys.argv) > 1 else 'LSFAM11C4RA133898'
data = json.loads((Path(__file__).parent / f'epcdata_json/{serial}.json').read_text(encoding='utf-8'))
cats = data.get('categories', [])
total_comps = sum(len(c.get('components', [])) for c in cats)
total_parts = sum(len(p.get('parts', [])) for c in cats for p in c.get('components', []))
print(f'Serial : {serial}')
print(f'Mid-categories : {len(cats)}')
print(f'Components (leaf) : {total_comps}')
print(f'Total parts rows  : {total_parts}')
print()
for c in cats:
    comps = c.get('components', [])
    print(f'  [{c.get("title")}]  {len(comps)} component(s)')
    for comp in comps:
        parts   = comp.get('parts', [])
        has_svg = bool(comp.get('svg_code', '').strip())
        print(f'    - {comp.get("title")}  parts={len(parts)}  svg={has_svg}')

"""
generate_json_trees.py
----------------------
Build a JSON parts tree for one (or all) vehicle serials from the epcdata folder.

Tree shape:
  serial
  └── categories[]          ← parent  (category folder, e.g. "air intake system")
        └── components[]    ← child   (HTML file, e.g. "Air filter.html")
              ├── title     ← legend-title from inside the HTML
              ├── svg_code  ← the SVG diagram
              └── parts[]   ← grandchildren (individual SKUs)
                    ├── part_number
                    ├── usage_name
                    ├── call_out_order
                    ├── unit_qty
                    ├── lr
                    └── remark

Usage
-----
  # Single serial:
  python generate_json_trees.py LSFAL11A4PA157987

  # All serials in epcdata/:
  python generate_json_trees.py --all

  # Explicit paths:
  python generate_json_trees.py LSFAL11A4PA157987 --epcdata ./epcdata --output ./epcdata_json
"""

import os
import sys
import json
import logging
import argparse
from bs4 import BeautifulSoup

# ---------------------------------------------------------------------------
# Logging
# ---------------------------------------------------------------------------
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler("generate_json_trees.log"),
        logging.StreamHandler()
    ]
)
logger = logging.getLogger(__name__)


# ---------------------------------------------------------------------------
# HTML parsing
# ---------------------------------------------------------------------------

def parse_html_file(html_path):
    """
    Parse one EPC HTML file.

    Returns a dict shaped as:
      {
        "title":    "JE140A001 - Air filter",   # from legend-title span
        "svg_code": "<svg>...</svg>",
        "parts": [
          {
            "call_out_order": 1,
            "part_number":    "C00041192",
            "usage_name":     "MOUNT-AIR CLEANER",
            "unit_qty":       "1.0",
            "lr":             "",
            "remark":         ""
          },
          ...
        ]
      }
    """
    with open(html_path, 'r', encoding='utf-8') as f:
        html = f.read()

    soup = BeautifulSoup(html, 'html.parser')

    # ── Title ──────────────────────────────────────────────────────────────
    legend_title = soup.find('span', id='legend-title')
    title_content = (
        legend_title.text.strip()
        if legend_title
        else os.path.basename(html_path).replace('.html', '')
    )

    # ── SVG diagram ─────────────────────────────────────────────────────────
    svg_element = soup.find('svg', attrs={"xmlns": "http://www.w3.org/2000/svg"})
    svg_content = str(svg_element) if svg_element else "<svg></svg>"

    # ── Extra metadata: L/R orientation & remarks (the "float" table) ───────
    extra = []
    try:
        container = soup.find('div', class_='condition-entity')
        if container:
            right_div = container.find(
                'div', class_='parts-table-tbody parts-table-tbody-dflz'
            )
            if right_div:
                right_rows = right_div.find_all('div', class_='parts-item')
                for item in right_rows:
                    if 'dn' in item.get('class', []):
                        continue
                    first_col = item.find(
                        lambda tag: tag.name == "span" and tag.get("class") == ["column"]
                    )
                    orientation = first_col.text.strip() if first_col else ""
                    note_col = item.select_one('.text-column-note span')
                    remark = note_col.text.strip() if note_col else ""
                    extra.append({'orientation': orientation, 'remark': remark})
    except Exception as e:
        logger.warning(f"Extra info extraction error in {html_path}: {e}")

    # ── Main parts (the "lock" table, identified by data-callout attr) ───────
    all_callout_items = soup.find_all(
        lambda tag: tag.name == "div"
        and "parts-item" in tag.get("class", [])
        and tag.has_attr("data-callout")
    )
    filtered_items = [i for i in all_callout_items if 'dn' not in i.get('class', [])]

    parts = []
    valid_count = 0
    for idx, item in enumerate(filtered_items):
        orientation = extra[idx]['orientation'] if idx < len(extra) else ""
        remark      = extra[idx]['remark']      if idx < len(extra) else ""

        order_elem    = item.select_one('.column.ordernumber')
        order_number  = order_elem.text.strip() if order_elem else ""

        part_num_elem = item.select_one('.part-number a.text-link')
        part_number   = part_num_elem.text.strip() if part_num_elem else ""

        desc_elem     = item.select_one('.column.describe')
        description   = desc_elem.text.strip() if desc_elem else ""

        qty_elem      = item.select_one('.column.quantity')
        quantity      = qty_elem.text.strip() if qty_elem else "1"

        # Skip rows missing any required field
        if not order_number or not part_number or not description:
            continue

        parts.append({
            "call_out_order": int(order_number) if order_number.isdigit() else valid_count + 1,
            "part_number":    part_number,
            "usage_name":     description,
            "unit_qty":       quantity,
            "lr":             orientation,
            "remark":         remark
        })
        valid_count += 1

    return {
        "title":    title_content,
        "svg_code": svg_content,
        "parts":    parts
    }


# ---------------------------------------------------------------------------
# Tree builder
# ---------------------------------------------------------------------------

def build_serial_tree(serial_dir):
    """
    Walk a single serial directory and return its full JSON-serialisable tree.

    serial_dir layout:
      LSFAL11A4PA157987/
        air intake system/
          Air filter.html
        airbag/
          Steering Wheel and AirBag.html
        ...
    """
    serial_name = os.path.basename(serial_dir)
    logger.info(f"Building tree for serial: {serial_name}")

    tree = {
        "serial":     serial_name,
        "categories": []           # parents
    }

    for dirpath, dirnames, filenames in os.walk(serial_dir):
        # Skip the serial root itself
        if os.path.abspath(dirpath) == os.path.abspath(serial_dir):
            continue

        html_files = sorted(f for f in filenames if f.lower().endswith('.html'))
        if not html_files:
            continue

        # Parent = category directory name
        category_name = os.path.basename(dirpath).replace('_', ' ')

        components = []   # children
        for filename in html_files:
            file_path = os.path.join(dirpath, filename)
            try:
                component = parse_html_file(file_path)  # grandchildren inside
                components.append(component)
                logger.info(
                    f"  [{category_name}]  {component['title']}  "
                    f"({len(component['parts'])} SKUs)"
                )
            except Exception as e:
                logger.error(f"  Failed to parse {file_path}: {e}")

        if components:
            tree["categories"].append({
                "title":      category_name,   # parent
                "components": components        # children (each has parts[] grandchildren)
            })

    total_cats  = len(tree["categories"])
    total_comps = sum(len(c["components"]) for c in tree["categories"])
    total_parts = sum(
        len(comp["parts"])
        for cat  in tree["categories"]
        for comp in cat["components"]
    )
    logger.info(
        f"Serial {serial_name}: "
        f"{total_cats} categories | {total_comps} components | {total_parts} SKUs"
    )
    return tree


# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------

def main():
    script_dir = os.path.dirname(os.path.abspath(__file__))
    default_epcdata = os.path.join(script_dir, 'epcdata')
    default_output  = os.path.join(script_dir, 'epcdata_json')

    parser = argparse.ArgumentParser(
        description="Generate JSON part trees from EPC HTML data."
    )
    parser.add_argument(
        'serial',
        nargs='?',
        help='Vehicle serial number to process (e.g. LSFAL11A4PA157987). '
             'Omit to use --all.'
    )
    parser.add_argument(
        '--all',
        action='store_true',
        help='Process every serial directory found inside --epcdata.'
    )
    parser.add_argument(
        '--epcdata',
        default=default_epcdata,
        help=f'Path to the epcdata folder (default: {default_epcdata})'
    )
    parser.add_argument(
        '--output',
        default=default_output,
        help=f'Folder where JSON files are written (default: {default_output})'
    )
    args = parser.parse_args()

    if not args.serial and not args.all:
        parser.print_help()
        sys.exit(1)

    if not os.path.isdir(args.epcdata):
        logger.error(f"epcdata directory not found: {args.epcdata}")
        sys.exit(1)

    os.makedirs(args.output, exist_ok=True)

    # Determine which serials to process
    if args.all:
        serials = sorted(
            d for d in os.listdir(args.epcdata)
            if os.path.isdir(os.path.join(args.epcdata, d))
        )
        if not serials:
            logger.warning(f"No serial directories found in {args.epcdata}")
            sys.exit(0)
        logger.info(f"Processing all {len(serials)} serial(s): {serials}")
    else:
        serials = [args.serial]

    for serial in serials:
        serial_path = os.path.join(args.epcdata, serial)
        if not os.path.isdir(serial_path):
            logger.error(
                f"Serial directory not found: {serial_path}\n"
                f"Available serials: "
                + ", ".join(
                    d for d in os.listdir(args.epcdata)
                    if os.path.isdir(os.path.join(args.epcdata, d))
                )
            )
            continue

        tree = build_serial_tree(serial_path)

        output_file = os.path.join(args.output, f"{serial}.json")
        with open(output_file, 'w', encoding='utf-8') as f:
            json.dump(tree, f, ensure_ascii=False, indent=2)

        logger.info(f"Saved → {output_file}")

    logger.info("Done.")


if __name__ == "__main__":
    main()


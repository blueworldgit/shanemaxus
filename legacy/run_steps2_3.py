#!/usr/bin/env python3
"""
run_steps2_3.py
---------------
Run step2_upload_svgs.py then step3_attach_products.py for a given serial,
in sequence. Step 3 only runs if Step 2 exits successfully.

Usage:
    python run_steps2_3.py LSFAM120XNA160733
    python run_steps2_3.py LSFAM120XNA160733 --dry-run
"""

import argparse
import subprocess
import sys
from pathlib import Path

SCRIPT_DIR = Path(__file__).parent


def run_step(label, script, serial, dry_run):
    cmd = [sys.executable, str(SCRIPT_DIR / script), serial]
    if dry_run:
        cmd.append('--dry-run')
    print(f'\n{"="*60}')
    print(f'  Starting {label}')
    print(f'{"="*60}\n')
    result = subprocess.run(cmd, cwd=SCRIPT_DIR)
    if result.returncode != 0:
        print(f'\n[ABORT] {label} failed with exit code {result.returncode}. Step 3 will not run.')
        sys.exit(result.returncode)
    print(f'\n[OK] {label} completed successfully.')


def main():
    parser = argparse.ArgumentParser(description='Run step2 then step3 in sequence')
    parser.add_argument('serial', help='Vehicle serial / VIN (e.g. LSFAM120XNA160733)')
    parser.add_argument('--dry-run', action='store_true',
                        help='Pass --dry-run to both steps (no changes made)')
    args = parser.parse_args()

    serial = args.serial.upper()
    mode = ' [DRY RUN]' if args.dry_run else ''

    print(f'\nSerial : {serial}{mode}')
    print('Will run: step2_upload_svgs.py  →  step3_attach_products.py')
    confirm = input('\nPress Enter to start, or Ctrl+C to cancel: ')  # noqa: F841

    run_step('STEP 2 – Upload SVGs', 'step2_upload_svgs.py', serial, args.dry_run)
    run_step('STEP 3 – Attach Products', 'step3_attach_products.py', serial, args.dry_run)

    print(f'\n{"="*60}')
    print(f'  All steps completed for {serial}{mode}')
    print(f'{"="*60}\n')


if __name__ == '__main__':
    main()

#!/usr/bin/env bash
# Only legacy tests that do not open a real database or send notifications.
set -euo pipefail
cd "$(dirname "$0")/../.."
if ! command -v php >/dev/null 2>&1; then
  echo 'BLOCKED: PHP CLI is required. No PHP tests executed.' >&2
  exit 2
fi
tests=(
  tests/tabel_angsuran_test.php
  tests/gadai_tenor_6_bulan_test.php
  tests/denda_test.php
  tests/denda_jaminan_test.php
  tests/denda_permissions_test.php
  tests/denda_scope_test.php
  tests/notifikasi_shu_test.php
)
for test_file in "${tests[@]}"; do
  php "$test_file"
done
echo 'PASS: 7 legacy PHP test scripts; database integration and revamp parity NOT covered.'

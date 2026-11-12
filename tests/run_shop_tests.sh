#!/bin/bash
# Do'konPro module tests: logic (mock shop DB + Telegram stub) then the web UI. No real shop data is used.
cd "$(dirname "$0")/.." || exit 1
php tests/shop_test.php || exit 1
php tests/shop_ui_test.php

#!/bin/sh
# Runs the automated checks in a fresh, throwaway WordPress — nothing to
# install except Node. Usage:  sh tests/run.sh          (PHP 8.2)
#                              sh tests/run.sh 7.4      (any other PHP)
set -e
PHP_VERSION="${1:-8.2}"
PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"

npx -y @wp-playground/cli@3.1.55 php \
  --php "$PHP_VERSION" \
  --mount "$PLUGIN_DIR:/wordpress/wp-content/plugins/factcrescendo-publisher" \
  -- /wordpress/wp-content/plugins/factcrescendo-publisher/tests/run-tests.php \
  2>&1 | grep -v '^npm warn'

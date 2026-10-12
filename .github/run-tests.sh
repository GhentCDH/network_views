#!/usr/bin/env bash
# Builds a Drupal project with this module and runs the coding standards
# checks, PHPStan and the tests. Used by .github/workflows/tests.yml; can also
# be run locally in a container with PHP 8.3 (with pdo_sqlite) and Composer.
#
# Environment:
#   DRUPAL_CORE           Composer constraint for drupal/core (e.g. ^10.3, ^11).
#   VN_PHPSTAN            Optional; 1 runs PHPStan (with Drupal 11: on older
#                         cores, newer core classes are reported missing).
#   SIMPLETEST_BASE_URL   URL of the web server that this script starts for the
#                         functional tests (default http://localhost:8080).
#   RELATIONSHIP_NODES    Composer constraint for drupal/relationship_nodes
#                         (default dev-dev).
#   BUILD_DIR             Where the Drupal project is built (default /tmp/vn-build).
set -euo pipefail

MODULE_DIR="$(cd "$(dirname "$0")/.." && pwd)"
BUILD_DIR="${BUILD_DIR:-/tmp/vn-build}"
DRUPAL_CORE="${DRUPAL_CORE:-^11}"

rm -rf "$BUILD_DIR"
composer create-project --no-interaction --no-install drupal/recommended-project "$BUILD_DIR" "$DRUPAL_CORE"
cd "$BUILD_DIR"
composer config minimum-stability dev
composer config prefer-stable true
composer config --no-plugins allow-plugins.symfony/runtime true
composer config --no-plugins allow-plugins.tbachert/spi false
composer config repositories.views_network '{"type": "path", "url": "'"$MODULE_DIR"'", "options": {"symlink": false}}'
# relationship_nodes is needed by the views_network_relationship_nodes
# submodule and its tests. Its dev branch is used until the changes since
# 1.0.0-beta2 are released: 1.0.0-beta2 fails on Drupal 11.4.
RELATIONSHIP_NODES="${RELATIONSHIP_NODES:-dev-dev}"
composer config repositories.relationship_nodes '{"type": "vcs", "url": "https://github.com/GhentCDH/relationship_nodes"}'
composer require --no-interaction -W \
  "drupal/core-recommended:$DRUPAL_CORE" \
  "drupal/core-composer-scaffold:$DRUPAL_CORE" \
  "drupal/views_network:*@dev" \
  "drupal/relationship_nodes:$RELATIONSHIP_NODES"
composer require --no-interaction -W --dev "drupal/core-dev:$DRUPAL_CORE"

MODULE="$BUILD_DIR/web/modules/contrib/views_network"

echo "== Coding standards"
vendor/bin/phpcs --standard=Drupal,DrupalPractice --extensions=php,module,install,inc,yml "$MODULE"

if [ "${VN_PHPSTAN:-0}" = 1 ]; then
  echo "== PHPStan"
  vendor/bin/phpstan analyse --no-progress --memory-limit=1G -c "$MODULE/phpstan.neon.dist" "$MODULE"
fi

mkdir -p "$BUILD_DIR/web/sites/simpletest/browser_output"
cd "$BUILD_DIR/web"
export SIMPLETEST_DB="sqlite://localhost/$BUILD_DIR/test.sqlite"
export SIMPLETEST_BASE_URL="${SIMPLETEST_BASE_URL:-http://localhost:8080}"
export BROWSERTEST_OUTPUT_DIRECTORY="$BUILD_DIR/web/sites/simpletest/browser_output"
# Deprecations in dependencies are reported, but do not fail the tests (as
# with PHPUnit 10 and later on Drupal 11).
export SYMFONY_DEPRECATIONS_HELPER=weak

# The functional tests need a web server for the test site.
port="${SIMPLETEST_BASE_URL##*:}"
php -S "0.0.0.0:${port%%/*}" -t "$BUILD_DIR/web" "$BUILD_DIR/web/.ht.router.php" > "$BUILD_DIR/webserver.log" 2>&1 &
server_pid=$!
trap 'kill "$server_pid"' EXIT

echo "== Tests"
"$BUILD_DIR/vendor/bin/phpunit" -c "$BUILD_DIR/web/core/phpunit.xml.dist" "$MODULE"

#!/bin/sh
# Usage: dev/test.sh [phpunit|parser|lint] [path]
set -e
cd "$(dirname "$0")"
mode="${1:-phpunit}"
path="${2:-}"
case "$mode" in
  phpunit)
    docker compose exec -T -e COMPOSER_ALLOW_SUPERUSER=1 mediawiki sh -c \
      "cd /var/www/html && composer phpunit -- --configuration tests/phpunit/suite.xml ${path:-extensions/BibleGet/tests/phpunit}" ;;
  parser)
    docker compose exec -T mediawiki sh -c \
      "cd /var/www/html && php tests/parser/parserTests.php --file=${path:-extensions/BibleGet/tests/parser/bibleget.txt}" ;;
  lint)
    docker compose exec -T -e COMPOSER_ALLOW_SUPERUSER=1 mediawiki sh -c \
      "cd /var/www/html/extensions/BibleGet && composer install --no-interaction -q && composer test && npm ci --no-audit --no-fund && npm test" ;;
  *) echo "Usage: $0 [phpunit|parser|lint] [path]" >&2; exit 2 ;;
esac

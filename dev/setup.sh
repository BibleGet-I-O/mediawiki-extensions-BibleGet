#!/bin/sh
# Install (or restore after a container recreate) the test wiki and core's composer dev dependencies.
set -e
cd "$(dirname "$0")"
mkdir -p data && { chmod 777 data 2>/dev/null || true; }
docker compose up -d
docker compose exec -T mediawiki sh -c '
  set -e
  cd /var/www/html
  if [ -f /var/www/data/LocalSettings.php ]; then
    cp /var/www/data/LocalSettings.php LocalSettings.php
  elif [ ! -f LocalSettings.php ]; then
    php maintenance/run.php install --dbtype=sqlite --dbpath=/var/www/data --server=http://localhost:8043 \
      --scriptpath="" --lang=en --pass=testwiki-admin-pass "BibleGet Test" Admin
    echo "require \"/var/www/LocalSettings.append.php\";" >> LocalSettings.php
    cp LocalSettings.php /var/www/data/LocalSettings.php
  fi
  chown -R www-data:www-data /var/www/data images
  # Create or update the BibleGet tables (also repairs a database restored from an older run)
  php maintenance/run.php update --quick >/dev/null
  if [ ! -x vendor/bin/phpunit ]; then
    apt-get -qq update >/dev/null && DEBIAN_FRONTEND=noninteractive apt-get -qq install -y unzip git nodejs npm >/dev/null
    if [ ! -x /usr/local/bin/composer ]; then
      php -r "copy(\"https://getcomposer.org/installer\", \"/tmp/composer-setup.php\");"
      php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
    fi
    COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --no-progress -q
  fi
  echo "test wiki ready ($(grep -o "\"version\": *\"[^\"]*\"" extensions/BibleGet/extension.json))"
'

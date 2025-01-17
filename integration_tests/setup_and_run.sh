#!/bin/sh

# Exit script wit ERRORLEVEL if any command fails
set -e

# Keep current directory ref
CURRENT_DIR=$(pwd)

# Got back to current dir if changed
cd "$CURRENT_DIR/integration_tests"

# Clear vendor cache
rm -rf ./vendor

# Setup composer
# Hash update information - https://getcomposer.org/download/
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php -r "if (hash_file('sha384', 'composer-setup.php') === '55ce33d7678c5a611085589f1f3ddf8b3c52d662cd01d4ba75c0ee0459970c2200a51f492d557530c71c15d8dba01eae') { echo 'Installer verified'; } else { echo 'Installer corrupt'; unlink('composer-setup.php'); } echo PHP_EOL;"
php composer-setup.php
php -r "unlink('composer-setup.php');"

php composer.phar install

cp "$ENGINE_INTEGRATION_TESTS_CONFIG" .env

php bin/codecept build

php bin/codecept run

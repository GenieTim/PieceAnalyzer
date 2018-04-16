#!/bin/bash

cd $(dirname $(dirname "$0"))

composer install --no-dev --optimize-autoloader
php bin/console cache:clear --env=prod --no-debug
php bin/console assets:install --env=prod --no-debug
yarn run encore production
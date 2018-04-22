#!/bin/bash
# Copyright (c) 2018 Tim Bernhard
# Pulls from remote, installs dependencies and executes the update command.

cd $(dirname $(dirname "$0"))

git pull
composer install --no-dev --optimize-autoloader
php bin/console cache:clear --env=prod --no-debug
php bin/console assets:install --env=prod --no-debug
yarn run encore production
./bin/fix-permissions.sh
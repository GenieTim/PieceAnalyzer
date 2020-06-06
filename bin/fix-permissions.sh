#!/bin/sh

cd "$(dirname $0)/.."

chown -R root:www-data .
chmod -R 0700 .
chmod 0750 .
chmod -R 0750 config public vendor src var templates translations node_modules assets .env
chmod -R 0770 var/cache var/log
chmod g+r .htaccess

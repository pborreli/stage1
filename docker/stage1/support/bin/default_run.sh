#!/bin/bash

# @todo move to symfony2 specific stuff

if [ ! -z "$DEBUG" ]; then
    set -x
fi

DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"

source /usr/local/lib/stage1.sh

declare -a services=(mysql php5-fpm nginx)
declare -a tries=(index.php app.php)

for file in ${tries[@]}; do
    if [ -f /var/www/web/$file ]; then
        sed -e "s/%frontcontroller%/$file/" -i /etc/nginx/sites-enabled/default
        break;
    fi
done;

for service in ${services[@]}; do
    /etc/init.d/$service start 2>&1 > /dev/null
done;

cd /var/www/

mkdir -p app/logs app/cache
touch app/logs/prod.log

chmod -R 777 app/logs app/cache

if [ -n "$(stage1_get_config_run)" ]; then
    stage1_get_config_run | while read cmd; do
        stage1_announce running "$cmd"
        stage1_exec_bg "$cmd"
    done
fi

tail -F /var/log/nginx/*.log /var/www/app/logs/*.log /var/log/php5-fpm.log
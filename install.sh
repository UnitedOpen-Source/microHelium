#!/bin/bash
# Instaling script for Helium

sudo apt-get update
sudo apt-get upgrade
sudo apt-get install build-essential libssl-dev curl php5 php5-cli php5-mcrypt php5-gd php5-mbstring git nodejs npm mysql-server
curl -sS https://getcomposer.org/installer | sudo php -- --install-dir=/usr/local/bin --filename=composer
composer install
php artisan serve

#!/usr/bin/env bash
set -euo pipefail

service mariadb start >/dev/null 2>&1 || true

for _ in $(seq 1 30); do
    mariadb-admin ping --silent >/dev/null 2>&1 && break
    sleep 1
done

mariadb -uroot <<'SQL'
CREATE DATABASE IF NOT EXISTS app;
CREATE USER IF NOT EXISTS 'schema'@'%' IDENTIFIED BY 'schema';
GRANT ALL PRIVILEGES ON *.* TO 'schema'@'%' WITH GRANT OPTION;
FLUSH PRIVILEGES;
SQL

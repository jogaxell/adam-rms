#!/bin/bash

# This file is used by the docker container to build the database schema and seed the database with initial data

cd /var/www/html

# Validate expected environment variables
echo "AdamRMS - Checking for Environment Variables"
if [[ ! -v DB_HOSTNAME ]] || [[ ! -v DB_DATABASE ]] || [[ ! -v DB_USERNAME ]] || [[ ! -v DB_PASSWORD ]]; then
    echo "AdamRMS - Expected Environment Variables not set"
    exit 1
fi

# Wait for the database. On a host reboot every container with a restart policy starts at once and
# compose's depends_on is not applied, so the database may still be starting up at this point.
echo "AdamRMS - Waiting for database at ${DB_HOSTNAME}:${DB_PORT:-3306}"
attempts=0
until php -r 'mysqli_report(MYSQLI_REPORT_OFF); exit(@mysqli_connect(getenv("DB_HOSTNAME"), getenv("DB_USERNAME"), getenv("DB_PASSWORD"), getenv("DB_DATABASE"), (int) (getenv("DB_PORT") ?: 3306)) ? 0 : 1);'; do
    attempts=$((attempts + 1))
    if [[ $attempts -ge 60 ]]; then
        echo "AdamRMS - Database not reachable after 120 seconds, exiting"
        exit 1
    fi
    sleep 2
done

# Database migration & seed. Exit on failure rather than serve on a half-migrated schema,
# so the container's restart policy retries instead.
echo "AdamRMS - Starting Migration Script"

if ! php vendor/bin/phinx migrate -e production; then
    echo "AdamRMS - Database migration failed, exiting"
    exit 1
fi
if ! php vendor/bin/phinx seed:run -e production; then
    echo "AdamRMS - Database seeding failed, exiting"
    exit 1
fi

if [[ -v DEV_MODE ]] && [[ "${DEV_MODE}" == 'true' ]]; then
    echo "AdamRMS - Running in DEV MODE"
fi

# Start Server
echo "AdamRMS - Starting Apache2"
exec apache2-foreground

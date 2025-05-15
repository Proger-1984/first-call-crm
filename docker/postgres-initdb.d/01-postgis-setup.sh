#!/bin/bash
set -e

# Включаем расширение PostGIS для всех баз данных
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-EOSQL
    CREATE EXTENSION IF NOT EXISTS postgis;
    CREATE EXTENSION IF NOT EXISTS postgis_topology;
EOSQL

echo "PostGIS extensions have been enabled for database: $POSTGRES_DB" 
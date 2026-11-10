#!/bin/sh
# Entrypoint of db_replica: a hot standby (read-only) of db, built with the
# official postgres image only (docs/performance.md, 6.6).
#
# First start (empty data volume):
#   1. waits for the primary;
#   2. creates or updates the replication role on the primary (credentials
#      from REPLICATION_USER / REPLICATION_PASSWORD, never committed);
#   3. copies the primary with pg_basebackup -R (writes standby.signal and
#      primary_conninfo) and a replication slot, so the primary keeps the WAL
#      the replica still needs (bounded by max_slot_wal_keep_size on the
#      primary).
# Every start: runs postgres as a hot standby. REPLICA_APPLY_DELAY (e.g. 3s)
# delays replay on purpose to demonstrate replication lag; empty/0 = off.
# Reset: docker compose rm -sf db_replica && docker volume rm <project>_replica_pgdata_laravel
set -eu

: "${PGDATA:=/var/lib/postgresql/data}"
PRIMARY_HOST="${PRIMARY_HOST:-db}"
SLOT="${REPLICATION_SLOT:-replica_slot}"

until pg_isready -h "$PRIMARY_HOST" -U "$POSTGRES_USER" -d "$POSTGRES_DB" >/dev/null 2>&1; do
  echo "replica: waiting for the primary ($PRIMARY_HOST)..."
  sleep 2
done

if [ ! -s "$PGDATA/PG_VERSION" ]; then
  echo "replica: empty data directory, creating it from the primary"

  # Idempotent role setup on the primary. psql variables keep the password
  # out of the SQL text; \gexec runs the generated statement only if needed.
  PGPASSWORD="$POSTGRES_PASSWORD" psql -h "$PRIMARY_HOST" -U "$POSTGRES_USER" -d "$POSTGRES_DB" \
    -v ON_ERROR_STOP=1 -v repl_user="$REPLICATION_USER" -v repl_pass="$REPLICATION_PASSWORD" -v slot="$SLOT" <<'SQL'
SELECT format('CREATE ROLE %I WITH REPLICATION LOGIN PASSWORD %L', :'repl_user', :'repl_pass')
 WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'repl_user') \gexec
SELECT format('ALTER ROLE %I WITH REPLICATION LOGIN PASSWORD %L', :'repl_user', :'repl_pass') \gexec
-- A slot left over from a deleted replica would keep WAL forever: drop it.
SELECT pg_drop_replication_slot(slot_name) FROM pg_replication_slots
 WHERE slot_name = :'slot' AND NOT active;
SQL

  mkdir -p "$PGDATA"
  chown postgres:postgres "$PGDATA"
  chmod 700 "$PGDATA"
  su-exec postgres env PGPASSWORD="$REPLICATION_PASSWORD" \
    pg_basebackup -h "$PRIMARY_HOST" -U "$REPLICATION_USER" -D "$PGDATA" \
      -R -X stream -C -S "$SLOT" --checkpoint=fast
  echo "replica: base backup done"
fi

DELAY="${REPLICA_APPLY_DELAY:-0}"
[ -z "$DELAY" ] && DELAY=0
echo "replica: starting as hot standby (recovery_min_apply_delay=$DELAY)"
exec su-exec postgres postgres \
  -c hot_standby=on \
  -c hot_standby_feedback=on \
  -c recovery_min_apply_delay="$DELAY"

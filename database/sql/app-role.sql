-- The production app role (Phase 1, task 1.11, ruling P1-R16).
--
-- WHAT IT IS FOR
--   The site connects as `bookflix_app`: it can read and write rows, refresh the popularity view, and nothing else. It cannot create, alter or drop a table,
--   an index, an extension or a schema, cannot TRUNCATE, and cannot rewrite the `migrations` table. A leaked app credential, or an injection bug, therefore cannot
--   change the structure of the database. Migrations run as the database OWNER (see "RUNNING IT" and docs/SECURITY.md).
--
-- RUNNING IT (once, then again after migrations that add tables or recreate the media_popularity view)
--   1. In Neon's SQL editor, connected as the database owner (the role the migrations run as), run this whole file. It is safe to run again: every statement is
--      idempotent. Create this role with SQL, as here, NOT in Neon's Console or API: roles made there join `neon_superuser`, which can do everything.
--   2. Set the password yourself, in the same editor, and never put it in a file or in the repository:
--          ALTER ROLE bookflix_app PASSWORD '<a long random password>';
--   3. Point the app at it: DB_USERNAME=bookflix_app with the POOLED host (the one with `-pooler` in its name). Point the owner connection (DB_OWNER_*) at the owner
--      with the DIRECT host: `php artisan migrate --force --database=pgsql_owner` (a pooler in transaction mode does not suit migrations).
--
-- WHAT THE APP DOES AT RUNTIME THAT NEEDS MORE THAN READING AND WRITING ROWS (checked in app/, routes/, config/, database/seeders and ml/)
--   * `create temporary table popularity_scores` (+ an index on it, + drop): the daily `bookflix:score-popularity`. The TEMPORARY right on the database is
--     granted to PUBLIC by default, and a session owns its own temporary tables, so no grant is needed here. If you ever revoke TEMPORARY from PUBLIC, grant it back:
--         GRANT TEMPORARY ON DATABASE <database> TO bookflix_app;
--   * `REFRESH MATERIALIZED VIEW CONCURRENTLY media_popularity` (the ML service, hourly): covered by the MAINTAIN right granted below (PostgreSQL 17+). On an older
--     server the role cannot refresh the view: the owner must (or the ML service must connect as the owner).
--   * `SET LOCAL hnsw.ef_search` (pgvector search): any role may set it.
--   * Row locks (SELECT ... FOR UPDATE): covered by UPDATE. Cache::lock uses the cache store. No advisory locks, no LOCK TABLE, no TRUNCATE, no VACUUM/ANALYZE/
--     CREATE INDEX, no CREATE EXTENSION anywhere in application code. The queue, cache and session stores, and Horizon, only read and write rows.
--   Anything that creates, alters or drops an object (migrations, seeders that need it, the extensions) must run as the owner.
--
-- NOTE FOR A SERVER OLDER THAN POSTGRESQL 15: new databases let every role create objects in schema public. The block below revokes that from PUBLIC.

-- The role: login only, no password, no powers. CREATE ROLE's defaults are already NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS.
-- They are not re-asserted with ALTER ROLE: only a superuser may name SUPERUSER, and only a role holding REPLICATION or BYPASSRLS may name those, so
-- the statement would fail for Neon's owner role, which is not a superuser. Check them instead:
--     SELECT rolsuper, rolcreatedb, rolcreaterole, rolreplication, rolbypassrls FROM pg_roles WHERE rolname = 'bookflix_app';
DO $$
BEGIN
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'bookflix_app') THEN
        CREATE ROLE bookflix_app LOGIN;
    END IF;
END
$$;

-- Connect to this database, and look inside schema public, but never create anything there.
DO $$
BEGIN
    EXECUTE format('GRANT CONNECT ON DATABASE %I TO bookflix_app', current_database());

    IF current_setting('server_version_num')::int < 150000 THEN
        REVOKE CREATE ON SCHEMA public FROM PUBLIC;
    END IF;
END
$$;

GRANT USAGE ON SCHEMA public TO bookflix_app;
REVOKE CREATE ON SCHEMA public FROM bookflix_app;

-- Rows: read and write every table that exists now ...
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO bookflix_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO bookflix_app;

-- ... and every table and sequence the role running this script (the owner, who runs the migrations) creates from now on.
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO bookflix_app;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO bookflix_app;

-- The migration history is the owner's: the app may read it and no more.
REVOKE INSERT, UPDATE, DELETE ON TABLE migrations FROM bookflix_app;

-- The popularity refresh (ML service) without owning the view: MAINTAIN covers REFRESH MATERIALIZED VIEW, with or without CONCURRENTLY (PostgreSQL 17+).
-- A migration that drops and recreates the view loses this grant, so run this file again after such a migration.
DO $$
BEGIN
    IF current_setting('server_version_num')::int >= 170000 AND to_regclass('public.media_popularity') IS NOT NULL THEN
        EXECUTE 'GRANT MAINTAIN ON TABLE public.media_popularity TO bookflix_app';
    END IF;
END
$$;

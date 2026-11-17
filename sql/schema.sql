-- SQLite database file (relative to the project root): ../data/trendradar.sqlite
-- The API cache is the only persistent server-side table used by this project.

PRAGMA foreign_keys = ON;
PRAGMA user_version = 1;

CREATE TABLE IF NOT EXISTS api_cache (
    cache_key TEXT PRIMARY KEY NOT NULL,
    body TEXT NOT NULL,
    fetched_at TEXT NOT NULL,
    expires_at INTEGER NOT NULL CHECK (expires_at >= 0)
);

CREATE INDEX IF NOT EXISTS idx_api_cache_expires_at
    ON api_cache (expires_at);

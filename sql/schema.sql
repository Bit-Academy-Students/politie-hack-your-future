CREATE TABLE IF NOT EXISTS sources (
    source_id INTEGER PRIMARY KEY,
    provider TEXT NOT NULL,
    feed_name TEXT NOT NULL,
    country_code TEXT NOT NULL,
    endpoint_url TEXT NOT NULL,
    format TEXT NOT NULL CHECK (format IN ('json', 'xml')),
    UNIQUE (provider, feed_name, country_code)
);

CREATE TABLE IF NOT EXISTS apps (
    app_id TEXT PRIMARY KEY NOT NULL,
    bundle_id TEXT,
    name TEXT NOT NULL,
    title TEXT,
    description TEXT,
    store_url TEXT,
    developer_name TEXT,
    developer_url TEXT,
    category_id TEXT,
    category_term TEXT,
    category_label TEXT,
    category_url TEXT,
    -- Store the API decimal string exactly; SQLite has no fixed-decimal type.
    price_amount TEXT,
    currency TEXT,
    price_label TEXT,
    released_at TEXT,
    rights TEXT,
    preview_url TEXT,
    last_seen_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS app_images (
    app_id TEXT NOT NULL REFERENCES apps(app_id) ON DELETE CASCADE,
    height INTEGER NOT NULL CHECK (height > 0),
    url TEXT NOT NULL,
    PRIMARY KEY (app_id, height)
);

CREATE TABLE IF NOT EXISTS feed_snapshots (
    snapshot_id INTEGER PRIMARY KEY,
    source_id INTEGER NOT NULL REFERENCES sources(source_id),
    fetched_at TEXT NOT NULL,
    feed_updated_at TEXT,
    raw_payload TEXT NOT NULL,
    UNIQUE (source_id, snapshot_id)
);

CREATE TABLE IF NOT EXISTS source_items (
    item_id INTEGER PRIMARY KEY,
    source_id INTEGER NOT NULL REFERENCES sources(source_id),
    source_key TEXT NOT NULL,
    item_type TEXT NOT NULL CHECK (item_type IN ('app', 'search_query')),
    display_name TEXT NOT NULL,
    app_id TEXT REFERENCES apps(app_id),
    UNIQUE (source_id, source_key),
    UNIQUE (source_id, item_id),
    CHECK ((item_type = 'app' AND app_id IS NOT NULL)
        OR (item_type = 'search_query' AND app_id IS NULL))
);

CREATE TABLE IF NOT EXISTS trend_observations (
    observation_id INTEGER PRIMARY KEY,
    source_id INTEGER NOT NULL,
    snapshot_id INTEGER NOT NULL,
    item_id INTEGER NOT NULL,
    title TEXT NOT NULL,
    source_published_at TEXT,
    list_position INTEGER NOT NULL CHECK (list_position > 0),
    chart_rank INTEGER CHECK (chart_rank > 0),
    traffic_label TEXT,
    traffic_lower_bound INTEGER CHECK (traffic_lower_bound >= 0),
    image_url TEXT,
    image_source TEXT,
    UNIQUE (snapshot_id, item_id),
    UNIQUE (snapshot_id, list_position),
    -- These also enforce that the snapshot and item belong to the same source.
    FOREIGN KEY (source_id, snapshot_id)
        REFERENCES feed_snapshots(source_id, snapshot_id) ON DELETE CASCADE,
    FOREIGN KEY (source_id, item_id)
        REFERENCES source_items(source_id, item_id)
);

CREATE TABLE IF NOT EXISTS related_articles (
    article_id INTEGER PRIMARY KEY,
    observation_id INTEGER NOT NULL
        REFERENCES trend_observations(observation_id) ON DELETE CASCADE,
    position INTEGER NOT NULL CHECK (position > 0),
    title TEXT NOT NULL,
    url TEXT NOT NULL,
    publisher TEXT,
    snippet TEXT,
    image_url TEXT,
    UNIQUE (observation_id, position)
);

-- Additive upgrade: running this schema again preserves existing data.
-- Steam games use apps.app_id = 'steam:<Steam app ID>' to avoid Apple ID collisions.
CREATE TABLE IF NOT EXISTS steam_player_counts (
    observation_id INTEGER PRIMARY KEY
        REFERENCES trend_observations(observation_id) ON DELETE CASCADE,
    current_players INTEGER NOT NULL
        CHECK (typeof(current_players) = 'integer' AND current_players >= 0),
    measured_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_snapshots_source_time
    ON feed_snapshots(source_id, fetched_at);
CREATE INDEX IF NOT EXISTS idx_observations_item
    ON trend_observations(item_id, snapshot_id);
CREATE INDEX IF NOT EXISTS idx_source_items_app
    ON source_items(app_id);
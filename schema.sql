-- schema.sql — IoT Dashboard v1.2 database schema
-- Apply via install.php (recommended) or manually:
--   sqlite3 db/iot.sqlite < schema.sql

PRAGMA journal_mode = WAL;
PRAGMA foreign_keys = ON;

-- ── Devices ───────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS devices (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT    NOT NULL,
    description TEXT    NOT NULL DEFAULT '',
    ip_address  TEXT    NOT NULL,
    location    TEXT    NOT NULL DEFAULT '',
    is_public   INTEGER NOT NULL DEFAULT 0,   -- 1 = visible on the public page
    active      INTEGER NOT NULL DEFAULT 1,   -- 0 = excluded from polling
    created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
);

-- ── Ping status log ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ping_status (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    device_id  INTEGER NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
    status     TEXT    NOT NULL CHECK(status IN ('online','offline')),
    latency_ms REAL,                          -- round-trip time in ms, NULL if offline
    pinged_at  TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_ping_device_time ON ping_status(device_id, pinged_at DESC);

-- ── View: latest status per device ───────────────────────────────────────────
CREATE VIEW IF NOT EXISTS device_latest_status AS
SELECT
    d.id, d.name, d.description, d.ip_address, d.location,
    d.is_public, d.active, d.created_at,
    p.status, p.latency_ms, p.pinged_at
FROM devices d
LEFT JOIN ping_status p ON p.id = (
    SELECT id FROM ping_status
    WHERE device_id = d.id
    ORDER BY pinged_at DESC LIMIT 1
);

-- ── Users ─────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    username      TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,              -- bcrypt via PHP password_hash()
    created_at    TEXT NOT NULL DEFAULT (datetime('now'))
);

-- ── Application settings (key/value store) ────────────────────────────────────
CREATE TABLE IF NOT EXISTS settings (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL DEFAULT ''
);

-- Default values (see install.php for the full list)

-- ── Alert log ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS alert_log (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    device_id  INTEGER NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
    alert_type TEXT    NOT NULL CHECK(alert_type IN ('offline','online')),
    sent_at    TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_alert_device ON alert_log(device_id, sent_at DESC);

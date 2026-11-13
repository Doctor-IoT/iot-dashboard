<?php
/**
 * db.php - Database access layer (PDO/SQLite)
 *
 * All database interaction goes through this class.
 * The PDO connection is created lazily on first use (singleton pattern).
 *
 * SQLite WAL mode is enabled for better concurrent read/write performance.
 * Foreign key enforcement is turned on at connection time.
 */
require_once __DIR__ . '/config.php';

class DB {
    private static ?PDO $pdo = null;

    /**
     * Returns the shared PDO connection, opening the database on first call.
     * Throws RuntimeException if the SQLite file cannot be opened.
     */
    public static function pdo(): PDO {
        if (self::$pdo === null) {
            $dir = dirname(DB_PATH);
            if (!is_dir($dir)) @mkdir($dir, 0750, true);
            try {
                self::$pdo = new PDO('sqlite:' . DB_PATH);
                self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                self::$pdo->exec('PRAGMA foreign_keys=ON; PRAGMA journal_mode=WAL;');
            } catch (Exception $e) {
                throw new RuntimeException(
                    'IoT Dashboard: cannot open SQLite database (' . DB_PATH . '): ' . $e->getMessage()
                );
            }
        }
        return self::$pdo;
    }

    // ─── Devices ──────────────────────────────────────────────────────────────

    /**
     * Returns all public, active devices with their latest ping status.
     * Returns an empty array if the database is not yet initialised.
     */
    public static function getPublicDevices(): array {
        try {
            $st = self::pdo()->query(
                "SELECT d.id, d.name, d.description, d.location,
                        p.status, p.pinged_at, p.latency_ms,
                        (SELECT MAX(ps2.pinged_at)
                         FROM ping_status ps2
                         WHERE ps2.device_id = d.id AND ps2.status = 'online'
                        ) AS last_online_at,
                        CASE
                            WHEN p.pinged_at IS NULL THEN 1
                            WHEN (JULIANDAY('now') - JULIANDAY(p.pinged_at)) * 1440 > " . STALE_MINUTES . " THEN 1
                            ELSE 0
                        END AS is_stale
                 FROM devices d
                 LEFT JOIN ping_status p ON p.id = (
                     SELECT id FROM ping_status
                     WHERE device_id = d.id
                     ORDER BY pinged_at DESC LIMIT 1
                 )
                 WHERE d.is_public=1 AND d.active=1
                 ORDER BY d.name"
            );
            return $st->fetchAll();
        } catch (Exception $e) {
            return [];   // database not yet initialised: return empty list
        }
    }

    /**
     * Returns all devices (public and private) with their latest ping status,
     * plus last_online_at and is_stale flag.
     */
    public static function getAllDevices(): array {
        $st = self::pdo()->query(
            "SELECT d.*, p.status, p.pinged_at, p.latency_ms,
                    (SELECT MAX(ps2.pinged_at)
                     FROM ping_status ps2
                     WHERE ps2.device_id = d.id AND ps2.status = 'online'
                    ) AS last_online_at,
                    CASE
                        WHEN p.pinged_at IS NULL THEN 1
                        WHEN (JULIANDAY('now') - JULIANDAY(p.pinged_at)) * 1440 > " . STALE_MINUTES . " THEN 1
                        ELSE 0
                    END AS is_stale
             FROM devices d
             LEFT JOIN ping_status p ON p.id = (
                 SELECT id FROM ping_status
                 WHERE device_id = d.id
                 ORDER BY pinged_at DESC LIMIT 1
             )
             ORDER BY d.name"
        );
        return $st->fetchAll();
    }

    /**
     * Returns the UTC timestamp of the last successful poller contact,
     * or null if the poller has never connected.
     */
    public static function getPollerLastSeen(): ?string {
        $v = self::getSetting('poller_last_seen', '');
        return $v !== '' ? $v : null;
    }

    /**
     * Returns a single device row from the devices table, or false if not found.
     */
    public static function getDevice(int $id): array|false {
        $st = self::pdo()->prepare(
            "SELECT * FROM devices WHERE id=?"
        );
        $st->execute([$id]);
        return $st->fetch();
    }

    /**
     * Inserts a new device and returns its auto-incremented ID.
     */
    public static function insertDevice(array $d): int {
        $st = self::pdo()->prepare(
            "INSERT INTO devices (name, description, ip_address, location, is_public, active)
             VALUES (:name, :description, :ip_address, :location, :is_public, :active)"
        );
        $st->execute($d);
        return (int) self::pdo()->lastInsertId();
    }

    /**
     * Updates an existing device. The updated_at column is intentionally omitted
     * because the deployed schema (created by install.php) does not include it.
     */
    public static function updateDevice(int $id, array $d): void {
        $d['id'] = $id;
        $st = self::pdo()->prepare(
            "UPDATE devices
             SET name=:name, description=:description, ip_address=:ip_address,
                 location=:location, is_public=:is_public, active=:active
             WHERE id=:id"
        );
        $st->execute($d);
    }

    public static function deleteDevice(int $id): void {
        $st = self::pdo()->prepare("DELETE FROM devices WHERE id=?");
        $st->execute([$id]);
    }

    // ─── Ping status ──────────────────────────────────────────────────────────

    /**
     * Inserts one ping result and prunes the history to the last 1000 records
     * per device to keep the database size bounded.
     */
    public static function insertPingStatus(int $device_id, string $status, ?float $latency): void {
        $st = self::pdo()->prepare(
            "INSERT INTO ping_status (device_id, status, latency_ms) VALUES (?,?,?)"
        );
        $st->execute([$device_id, $status, $latency]);

        // Keep only the most recent 1000 records per device
        self::pdo()->prepare(
            "DELETE FROM ping_status WHERE device_id=? AND id NOT IN
             (SELECT id FROM ping_status WHERE device_id=? ORDER BY pinged_at DESC LIMIT 1000)"
        )->execute([$device_id, $device_id]);
    }

    /**
     * Returns the last $limit ping records for a device, newest first.
     */
    public static function getPingHistory(int $device_id, int $limit = 60): array {
        $st = self::pdo()->prepare(
            "SELECT status, latency_ms, pinged_at FROM ping_status
             WHERE device_id=? ORDER BY pinged_at DESC LIMIT ?"
        );
        $st->execute([$device_id, $limit]);
        return $st->fetchAll();
    }

    // ─── Users ────────────────────────────────────────────────────────────────

    public static function getUserByUsername(string $username): array|false {
        $st = self::pdo()->prepare("SELECT * FROM users WHERE username=?");
        $st->execute([$username]);
        return $st->fetch();
    }

    // ─── Settings ─────────────────────────────────────────────────────────────

    /**
     * Reads a single setting value by key. Returns $default if not found.
     */
    public static function getSetting(string $key, string $default = ''): string {
        try {
            $st = self::pdo()->prepare("SELECT value FROM settings WHERE key=?");
            $st->execute([$key]);
            $row = $st->fetch();
            return $row !== false ? (string)$row['value'] : $default;
        } catch (Exception $e) { return $default; }
    }

    /**
     * Inserts or updates a setting value (upsert via ON CONFLICT).
     */
    public static function setSetting(string $key, string $value): void {
        self::pdo()->prepare(
            "INSERT INTO settings (key, value) VALUES (?,?)
             ON CONFLICT(key) DO UPDATE SET value=excluded.value"
        )->execute([$key, $value]);
    }

    /**
     * Returns all settings as an associative array keyed by setting name.
     */
    public static function getAllSettings(): array {
        try {
            return self::pdo()->query("SELECT key, value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Exception $e) { return []; }
    }

    // ─── Logo ─────────────────────────────────────────────────────────────────

    /**
     * Saves the logo binary data and MIME type into the settings table.
     * The logo is stored as a base64-encoded string under 'logo_data',
     * and the MIME type under 'logo_mime'.
     *
     * @param string $binary  Raw binary content of the image file.
     * @param string $mime    MIME type, e.g. 'image/png'.
     */
    public static function saveLogo(string $binary, string $mime): void {
        self::setSetting('logo_data', base64_encode($binary));
        self::setSetting('logo_mime', $mime);
    }

    /**
     * Returns ['mime' => '...', 'data' => '<binary string>'] if a custom logo
     * has been uploaded, or null if no logo is stored.
     */
    public static function getLogo(): ?array {
        $data = self::getSetting('logo_data', '');
        $mime = self::getSetting('logo_mime', '');
        if ($data === '' || $mime === '') return null;
        $binary = base64_decode($data, true);
        if ($binary === false) return null;
        return ['mime' => $mime, 'data' => $binary];
    }

    /**
     * Removes the custom logo, restoring the default SVG icon.
     */
    public static function deleteLogo(): void {
        try {
            self::pdo()->prepare("DELETE FROM settings WHERE key IN ('logo_data','logo_mime')")->execute();
        } catch (Exception $e) { /* ignore */ }
    }

    // ─── Alert log ────────────────────────────────────────────────────────────

    /**
     * Returns the most recent alert record for a device, or null if none exists.
     * Used to decide whether a new alert needs to be sent.
     */
    public static function getLastAlert(int $device_id): array|null {
        try {
            $st = self::pdo()->prepare(
                "SELECT alert_type, sent_at FROM alert_log
                 WHERE device_id=? ORDER BY sent_at DESC LIMIT 1"
            );
            $st->execute([$device_id]);
            $row = $st->fetch();
            return $row ?: null;
        } catch (Exception $e) { return null; }
    }

    /**
     * Records that an alert of the given type was sent for the device.
     */
    public static function insertAlert(int $device_id, string $alert_type): void {
        self::pdo()->prepare(
            "INSERT INTO alert_log (device_id, alert_type) VALUES (?,?)"
        )->execute([$device_id, $alert_type]);
    }

    /**
     * Returns the timestamp of the first consecutive offline ping in the current
     * offline episode (i.e. after the last online ping). Used to calculate how
     * long the device has been down before sending an alert.
     */
    public static function getOfflineSince(int $device_id): string|null {
        try {
            $st = self::pdo()->prepare(
                "SELECT MIN(pinged_at) FROM ping_status
                 WHERE device_id=? AND pinged_at > COALESCE(
                     (SELECT MAX(pinged_at) FROM ping_status
                      WHERE device_id=? AND status='online'),
                     '1970-01-01'
                 ) AND status='offline'"
            );
            $st->execute([$device_id, $device_id]);
            $ts = $st->fetchColumn();
            return $ts ?: null;
        } catch (Exception $e) { return null; }
    }

    /**
     * Returns how many minutes the device has been continuously offline in the
     * current episode. The calculation is done entirely inside SQLite using
     * CURRENT_TIMESTAMP so there is no PHP timezone/strtotime mismatch.
     *
     * Returns null if the device is not currently offline.
     */
    public static function getOfflineMinutes(int $device_id): ?float {
        try {
            $st = self::pdo()->prepare(
                "SELECT ROUND(
                     (JULIANDAY('now') - JULIANDAY(MIN(pinged_at))) * 1440.0
                 , 2) AS minutes
                 FROM ping_status
                 WHERE device_id=?
                   AND status='offline'
                   AND pinged_at > COALESCE(
                       (SELECT MAX(pinged_at) FROM ping_status
                        WHERE device_id=? AND status='online'),
                       '1970-01-01'
                   )"
            );
            $st->execute([$device_id, $device_id]);
            $val = $st->fetchColumn();
            return ($val !== false && $val !== null) ? (float)$val : null;
        } catch (Exception $e) { return null; }
    }
}

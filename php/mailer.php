<?php
/**
 * mailer.php - Email alert system for IoT Dashboard
 *
 * Sends alert emails using PHP's native mail() function, which is available
 * on most shared hosting providers (Aruba, Netsons, etc.).
 *
 * Alert logic (called by api.php after every ping result):
 *   OFFLINE - sends an alert when a device has been offline for >= N minutes
 *             (configurable via settings) and no alert has already been sent
 *             for the current offline episode.
 *   ONLINE  - sends a recovery notification when a device comes back online
 *             after a previous offline alert was sent.
 *
 * Duplicate suppression is handled by the alert_log table: one alert per
 * offline episode, one recovery per episode.
 */

require_once __DIR__ . '/db.php';

/**
 * Checks whether an alert email should be sent after a new ping result and,
 * if so, sends it and records the event in alert_log.
 *
 * @param int    $device_id  ID of the device that was just pinged
 * @param string $new_status 'online' | 'offline'
 * @param string $device_name Human-readable device name for the email body
 */
function check_and_send_alert(int $device_id, string $new_status, string $device_name): void
{
    // Skip if alerts are disabled in settings
    if (DB::getSetting('alert_enabled', '1') !== '1') return;

    $email1 = trim(DB::getSetting('alert_email_primary', ''));
    $email2 = trim(DB::getSetting('alert_email_secondary', ''));
    if ($email1 === '') return;   // no recipient configured

    // Build recipient list (primary + optional secondary)
    $recipients = array_filter([$email1, $email2]);

    $last_alert = DB::getLastAlert($device_id);

    if ($new_status === 'offline') {
        // Do not re-send if an offline alert was already sent for this episode
        if ($last_alert && $last_alert['alert_type'] === 'offline') return;

        // All timing done inside SQLite (JULIANDAY) to avoid PHP timezone issues
        $threshold_min = (int) DB::getSetting('alert_offline_minutes', '3');
        $elapsed_min   = DB::getOfflineMinutes($device_id);
        if ($elapsed_min === null || $elapsed_min < $threshold_min) return;

        $offline_since = DB::getOfflineSince($device_id);  // for the email body only

        // Send offline alert
        $subject = "WARNING OFFLINE: {$device_name}";
        $body    = build_offline_body($device_name, (string)$offline_since, (int)round($elapsed_min));
        foreach ($recipients as $to) {
            send_mail($to, $subject, $body);
        }
        DB::insertAlert($device_id, 'offline');

    } elseif ($new_status === 'online') {
        // Send recovery notification only if a previous offline alert was sent
        if (!$last_alert || $last_alert['alert_type'] !== 'offline') return;

        $subject = "RESTORED ONLINE: {$device_name}";
        $body    = build_online_body($device_name);
        foreach ($recipients as $to) {
            send_mail($to, $subject, $body);
        }
        DB::insertAlert($device_id, 'online');
    }
}

/**
 * Checks whether the poller is stale and sends an alert email if needed.
 * Also sends a recovery email when the poller comes back after a stale period.
 *
 * Uses two settings keys to track state across page loads:
 *   poller_alert_sent_at  — UTC timestamp of the last stale alert sent
 *   poller_alert_active   — '1' while a stale alert is "open" (recovery not yet sent)
 *
 * Call this once per page load from any public or private page.
 * It is intentionally silent on failure (never throws).
 */
function check_and_send_poller_alert(): void
{
    try {
        // Skip if alerts are disabled or no recipient configured
        if (DB::getSetting('alert_enabled', '1') !== '1') return;
        $email1 = trim(DB::getSetting('alert_email_primary', ''));
        if ($email1 === '') return;
        $recipients = array_filter([$email1, trim(DB::getSetting('alert_email_secondary', ''))]);

        $poller_last_seen = DB::getPollerLastSeen(); // UTC string or null

        // ── Determine current staleness ───────────────────────────────────────
        $is_stale = false;
        $age_min  = null;
        if ($poller_last_seen === null) {
            // Poller never connected — treat as stale only after STALE_MINUTES
            // from the first possible time (we have no timestamp, skip alert
            // until we have something to report)
            return;
        } else {
            $dt      = new DateTime($poller_last_seen, new DateTimeZone('UTC'));
            $age_min = (time() - $dt->getTimestamp()) / 60;
            $is_stale = $age_min > STALE_MINUTES;
        }

        $alert_active = DB::getSetting('poller_alert_active', '0') === '1';

        if ($is_stale) {
            // Cooldown: do not resend within 1 hour of the last alert
            $last_sent = DB::getSetting('poller_alert_sent_at', '');
            if ($last_sent !== '') {
                $dt_last   = new DateTime($last_sent, new DateTimeZone('UTC'));
                $mins_ago  = (time() - $dt_last->getTimestamp()) / 60;
                if ($mins_ago < 60) return;
            }

            // Send stale alert
            $subject = 'WARNING: IoT Dashboard poller is not responding';
            $body    = build_poller_stale_body((int) round($age_min), $poller_last_seen);
            foreach ($recipients as $to) {
                send_mail($to, $subject, $body);
            }
            DB::setSetting('poller_alert_sent_at', gmdate('Y-m-d H:i:s'));
            DB::setSetting('poller_alert_active',  '1');

        } elseif ($alert_active) {
            // Poller is healthy again — send recovery email
            $subject = 'RECOVERED: IoT Dashboard poller is active again';
            $body    = build_poller_recovered_body($poller_last_seen);
            foreach ($recipients as $to) {
                send_mail($to, $subject, $body);
            }
            DB::setSetting('poller_alert_active', '0');
        }

    } catch (Throwable $e) {
        // Never let this break the page load
    }
}

// ─── Email body builders ──────────────────────────────────────────────────────

function build_offline_body(string $name, string $since, int $minutes): string
{
    $ts   = date('d/m/Y H:i', strtotime($since));
    $site = 'https://www.sparclab.it/iot-dashboard/public/';
    return <<<TEXT
IoT Dashboard - SPARC LAB
==========================

ALERT: device "{$name}" is OFFLINE.

First offline ping detected: {$ts}
Offline for approximately: {$minutes} minutes

Please check the device and visit the dashboard for details:
{$site}

--
This message was generated automatically by IoT Dashboard SPARC LAB.
To disable notifications or change email addresses, log in and go to:
{$site}settings.php
TEXT;
}

function build_online_body(string $name): string
{
    $ts   = date('d/m/Y H:i');
    $site = 'https://www.sparclab.it/iot-dashboard/public/';
    return <<<TEXT
IoT Dashboard - SPARC LAB
==========================

RECOVERY: device "{$name}" is back ONLINE.

Recovery time: {$ts}

Dashboard:
{$site}

--
This message was generated automatically by IoT Dashboard SPARC LAB.
TEXT;
}

function build_poller_stale_body(int $age_min, string $last_seen_utc): string
{
    $dt   = new DateTime($last_seen_utc, new DateTimeZone('UTC'));
    $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
    $ts   = $dt->format('d/m/Y H:i');
    $site = defined('SITE_PUBLIC_URL') ? SITE_PUBLIC_URL : 'https://www.sparclab.it/iot-dashboard/public/';
    return <<<TEXT
IoT Dashboard - SPARC LAB
==========================

WARNING: the monitoring poller has stopped sending data.

Last data received: {$ts}
Approximate silence: {$age_min} minutes

Device statuses shown on the dashboard may be outdated.

Please check that the poller service is running on the monitoring machine:
  - systemctl status iot-poller   (if running as a systemd service)
  - Check /var/log/iot-poller.log for errors

Dashboard:
{$site}

--
This message was generated automatically by IoT Dashboard SPARC LAB.
To disable notifications, log in and go to: {$site}settings.php
TEXT;
}

function build_poller_recovered_body(string $last_seen_utc): string
{
    $dt   = new DateTime($last_seen_utc, new DateTimeZone('UTC'));
    $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
    $ts   = $dt->format('d/m/Y H:i');
    $site = defined('SITE_PUBLIC_URL') ? SITE_PUBLIC_URL : 'https://www.sparclab.it/iot-dashboard/public/';
    return <<<TEXT
IoT Dashboard - SPARC LAB
==========================

RECOVERED: the monitoring poller is active again.

Last data received: {$ts}
Device statuses are now up to date.

Dashboard:
{$site}

--
This message was generated automatically by IoT Dashboard SPARC LAB.
TEXT;
}

// ─── Mail sender ──────────────────────────────────────────────────────────────

/**
 * Sends a plain-text email via PHP mail().
 * Sanitises all header fields to prevent header injection.
 * Subject is UTF-8 encoded using base64 (RFC 2047).
 *
 * @return bool true if mail() accepted the message (delivery not guaranteed)
 */
function send_mail(string $to, string $subject, string $body): bool
{
    $from_name  = DB::getSetting('alert_from_name',  'IoT Dashboard SPARC LAB');
    $from_email = DB::getSetting('alert_from_email', 'noreply@sparclab.it');

    // Sanitise header fields against injection
    $from_name  = preg_replace('/[\r\n]/', '', $from_name);
    $from_email = preg_replace('/[\r\n<>]/', '', $from_email);
    $to         = preg_replace('/[\r\n<>]/', '', $to);
    $subject    = preg_replace('/[\r\n]/', '', $subject);

    $headers  = "From: {$from_name} <{$from_email}>\r\n";
    $headers .= "Reply-To: {$from_email}\r\n";
    $headers .= "X-Mailer: IoT-Dashboard/1.3\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: 8bit\r\n";

    // Encode subject as UTF-8 Base64 (RFC 2047) for non-ASCII characters
    $encoded_subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    return @mail($to, $encoded_subject, $body, $headers);
}

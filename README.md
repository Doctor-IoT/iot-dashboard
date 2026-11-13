# IoT Status Dashboard

A lightweight IoT device monitoring dashboard built with PHP + SQLite, designed to run on shared hosting (cPanel / Apache).

**Version:** 1.4  
**Author:** Francesco Orfei / SPARC LAB  
**Live demo:** [www.sparclab.it/iot-dashboard/](https://www.sparclab.it/iot-dashboard/)

---

## Features

- **Public status page** — real-time online/offline status cards, sortable by name, status or location
- **Private dashboard** — full device table with latency and sortable columns (authenticated)
- **Device management** — CRUD for devices via admin panel (add, edit, delete)
- **Ping history** — per-device history of the last 60 pings
- **Email alerts** — sends an alert when a device has been offline for a configurable number of minutes; sends a recovery email when it comes back online; supports a primary and an optional secondary recipient
- **Alert log** — last 10 alerts shown in the Settings page
- **Python polling script** — runs every minute (crontab or systemd timer), pings all active devices and posts results to the API
- **Multilingual (EN / IT)** — built-in language switcher; language stored in session and cookie; automatically detected from the browser's Accept-Language header
- **Secure by default** — CSRF protection, bcrypt passwords, rate-limited login, session hardening, API key in HTTP header only

---

## Requirements

| Component | Version |
|-----------|---------|
| PHP | 7.4 + (8.0+ recommended) |
| PDO SQLite | must be enabled |
| Apache | mod_rewrite optional |
| Python | 3.9+ (for the poller) |
| `requests` | `pip3 install requests` |

---

## Directory structure

```
iot-dashboard/
├── db/                     # SQLite database (created by install.php)
│   ├── iot.sqlite
│   ├── .htaccess           # blocks HTTP access to db/
│   └── index.php           # 403 guard
├── php/                    # Back-end includes (not web-accessible)
│   ├── config.php          # DB path, API key, session constants
│   ├── auth.php            # Session, CSRF, login/logout
│   ├── db.php              # DB class (PDO helpers)
│   ├── mailer.php          # Alert email logic
│   ├── i18n.php            # Language helper (__(), i18n_switcher())
│   └── lang/
│       ├── en.php          # English strings
│       └── it.php          # Italian strings
├── public/                 # Document root (web-accessible)
│   ├── index.php           # Public status page
│   ├── dashboard.php       # Private dashboard
│   ├── admin.php           # Device management
│   ├── settings.php        # Email notification settings
│   ├── login.php           # Standalone login
│   ├── logout.php          # Session destroy + redirect
│   ├── device_history.php  # Per-device ping history
│   ├── api.php             # JSON API for the poller
│   ├── logo.php            # Serves the custom logo or the built-in SVG default
│   ├── install.php         # ⚠ One-time setup — DELETE after use
│   ├── migrate.php         # ⚠ DB migration to v1.4 — DELETE after use
│   ├── check_env.php       # ⚠ Hosting compatibility check — DELETE after use
│   └── assets/
│       └── style.css
├── poller/                 # Python polling script
│   ├── poller.py
│   ├── poller.service      # systemd service unit (optional)
│   ├── poller.timer        # systemd timer unit (optional)
│   └── requirements.txt
├── schema.sql              # Reference SQL schema
├── .gitignore
└── README.md
```

---

## Installation

### 1. Upload files

Upload the entire `iot-dashboard/` folder to your hosting (e.g. `public_html/iot-dashboard/`).  
Make sure the web server's document root points to `public/` or that the whole folder is accessible.

### 2. Run the environment check (optional)

Open in a browser:
```
https://your-site.com/iot-dashboard/public/check_env.php?pass=delete_me_after_check
```
Confirm that all checks pass, then **delete `check_env.php` via FTP**.

### 3. Edit `php/config.php`

```php
define('DB_API_KEY',    'replace_with_a_long_random_secret');
define('POLL_API_URL',  'https://your-site.com/iot-dashboard/public/api.php');
```

> **Security:** `DB_API_KEY` must be a long random string.  
> Generate one with: `python3 -c "import secrets; print(secrets.token_hex(32))"`

### 4. Run the installer

Open in a browser:
```
https://your-site.com/iot-dashboard/public/install.php?pass=CHANGE_THIS_PASSPHRASE
```
Edit `install.php` first to set:
- `$INSTALL_PASS` — passphrase for the URL
- `$ADMIN_PASSWORD` — admin account password

After a successful install, **delete `install.php` via FTP**.

### 5. Sign in

Go to `https://your-site.com/iot-dashboard/public/` and click **Private area**.  
Username: `admin` | Password: the one you set in step 4.

---

## Upgrading from v1.2 to v1.4

Upload all updated files, then run the migration script:

```
https://your-site.com/iot-dashboard/public/migrate.php?pass=CHANGE_THIS_PASSPHRASE
```

The script is idempotent and safe to run on an existing database.  
**Delete `migrate.php` via FTP after use.**

### What's new in v1.4

- **Custom logo** — upload a PNG / JPG / SVG / WebP logo from Settings; shown in every page header and in the login boxes; remove it to restore the built-in default
- Logo stored securely in the SQLite database (no filesystem writes needed)
- New files: `public/logo.php`; new DB methods: `DB::saveLogo()`, `DB::getLogo()`, `DB::deleteLogo()`

### What's new in v1.3

- **Language switcher (EN / IT)** — available in the header of every page
- Language stored in session and browser cookie; automatically detected from `Accept-Language`
- **Alert timezone bug fix** — elapsed offline time now computed entirely in SQLite (JULIANDAY) to avoid PHP timezone/strtotime mismatch
- New files: `php/i18n.php`, `php/lang/en.php`, `php/lang/it.php`

## Upgrading from v1.1 to v1.2

If you already have a running installation on v1.1, run the migration script. It adds the `settings` and `alert_log` tables without touching existing data.  
**Delete `migrate.php` via FTP after use.**

---

## Polling script setup

### Environment variables

| Variable | Description |
|----------|-------------|
| `IOT_API_URL` | Full URL to `api.php`, e.g. `https://your-site.com/iot-dashboard/public/api.php` |
| `IOT_API_KEY` | Must match `DB_API_KEY` in `php/config.php` |
| `IOT_PING_TIMEOUT` | Seconds per ping (default: `2`) |
| `IOT_PING_COUNT` | Packets per ping (default: `1`) |

### Crontab (simplest)

```bash
crontab -e
# Add:
* * * * * IOT_API_URL=https://your-site.com/iot-dashboard/public/api.php IOT_API_KEY=your_secret /usr/bin/python3 /opt/iot-poller/poller.py >> /var/log/iot-poller.log 2>&1
```

### systemd (recommended on Ubuntu/Debian)

```bash
cp poller/poller.service /etc/systemd/system/
cp poller/poller.timer   /etc/systemd/system/
# Edit the ExecStart line in poller.service to match your paths and credentials
systemctl daemon-reload
systemctl enable --now iot-poller.timer
```

> **Security:** The API key is passed in the `X-Api-Key` HTTP header, never in the URL, to prevent it appearing in server access logs.

---

## Email alerts

Configure in the **Settings** page (`settings.php`):

| Setting | Description |
|---------|-------------|
| Primary email | Receives all offline and recovery alerts |
| Secondary email | Optional additional recipient |
| Offline threshold | Minutes before an alert is sent (default: 3) |
| Sender name / email | Must be authorised by your hosting (to avoid spam filtering) |

Alert deduplication: only one offline alert is sent per outage episode; a recovery email is sent only when the previous alert was an offline alert.

PHP's built-in `mail()` function is used — no external SMTP library required.

### Poller-silence alert

An additional alert is sent when the poller itself stops sending data (e.g. the monitoring machine goes offline or the cron job fails). The check runs:

- **Automatically** on every page load of `index.php` / `dashboard.php` (lazy check)
- **Via cron** using `cron-poller-check.php` (recommended for timely notifications)

#### Setting up the cron job

**Option A — command-line cron (Linux server / VPS):**
```bash
# Check every minute; log output to /var/log/iot-cron.log
* * * * * /usr/bin/php /path/to/iot-dashboard/cron-poller-check.php >> /var/log/iot-cron.log 2>&1
```

**Option B — hosting panel with URL-based cron:**

1. Open `cron-poller-check.php` and set a secret token:
   ```php
   define('CRON_SECRET', 'your-secret-token-here');
   ```
2. Upload the file to `public/` (or keep it at the root and adjust your panel's path)
3. Configure the cron URL in your hosting panel:
   ```
   https://www.yoursite.com/iot-dashboard/public/cron-poller-check.php?token=your-secret-token-here
   ```
4. Set the interval to every 1 or 5 minutes

The script sends one warning email per hour while the poller is silent, and a recovery email when it comes back. The alert threshold is `STALE_MINUTES` in `php/config.php` (default: 5 minutes).

---

## Security notes

- `db/` and `php/` are blocked from HTTP access via `.htaccess`
- The API key is validated with `hash_equals()` (constant-time comparison)
- Login is rate-limited: 5 failed attempts trigger a 5-minute lockout
- CSRF tokens are generated with `random_bytes(32)` and verified with `hash_equals()`
- Passwords are stored as bcrypt hashes (`PASSWORD_BCRYPT`)
- Sessions use `HttpOnly`, `SameSite=Lax`, and `session_regenerate_id()` on login

---

## License

MIT — free to use and modify.

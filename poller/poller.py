#!/usr/bin/env python3
"""
poller.py — IoT Dashboard polling script
Version: 1.1
Author:  Francesco Orfei / doctoriot

How it works:
  1. Downloads the list of active devices from the web API (GET /api.php?action=devices_list)
  2. Sends ICMP pings to each IP address (with retry on failure, see IOT_PING_RETRIES)
  3. Submits the results in bulk to the API (POST /api.php?action=ping_results_bulk)

Retry logic (v1.1):
  If a ping fails, it is retried up to IOT_PING_RETRIES times with a short pause
  (IOT_RETRY_DELAY seconds) between attempts. The device is marked offline only
  when ALL retries fail. Latency is the average of all successful responses.
  This eliminates most false-offline events caused by occasional packet loss.

Python dependencies:
  pip3 install requests

Manual execution:
  python3 /opt/iot-poller/poller.py

Crontab (every minute):
  * * * * * /usr/bin/python3 /opt/iot-poller/poller.py >> /var/log/iot-poller.log 2>&1
"""

import os
import sys
import re
import json
import time
import logging
import subprocess
import platform
import requests
from datetime import datetime

# ─── Configuration ────────────────────────────────────────────────────────────
# Set these environment variables on the system, or change the defaults below.

API_BASE_URL = os.environ.get(
    'IOT_API_URL',
    'https://your-web-server.example.com/api.php'   # <-- change this
)
API_KEY = os.environ.get(
    'IOT_API_KEY',
    'CHANGE_THIS_SECRET_KEY'                         # <-- must match DB_API_KEY in config.php
)

PING_TIMEOUT    = int(os.environ.get('IOT_PING_TIMEOUT', '2'))    # seconds per single ping attempt
PING_COUNT      = int(os.environ.get('IOT_PING_COUNT',   '1'))    # ICMP packets per attempt
PING_RETRIES    = int(os.environ.get('IOT_PING_RETRIES', '2'))    # extra attempts after first failure (0 = no retry)
RETRY_DELAY     = float(os.environ.get('IOT_RETRY_DELAY', '1'))   # seconds between retries
REQUEST_TIMEOUT = 10   # HTTP request timeout in seconds

# ─── Logging ─────────────────────────────────────────────────────────────────
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s [%(levelname)s] %(message)s',
    datefmt='%Y-%m-%d %H:%M:%S'
)
log = logging.getLogger(__name__)


# ─── Helper functions ─────────────────────────────────────────────────────────

def _api_headers() -> dict:
    """Common headers for all API calls (key in header, never in URL)."""
    return {'X-Api-Key': API_KEY, 'Content-Type': 'application/json'}


def get_devices() -> list[dict]:
    """Fetches the list of active devices from the API."""
    url = f"{API_BASE_URL}?action=devices_list"
    try:
        resp = requests.get(url, headers=_api_headers(), timeout=REQUEST_TIMEOUT)
        resp.raise_for_status()
        data = resp.json()
        devices = data.get('devices', [])
        log.info(f"Received {len(devices)} device(s) to ping.")
        return devices
    except requests.RequestException as e:
        log.error(f"Failed to fetch device list: {e}")
        return []


def _ping_once(ip: str) -> tuple[str, float | None]:
    """
    Sends a single ICMP ping sequence to ip.
    Returns ('online', latency_ms) on success, ('offline', None) on failure.
    """
    system = platform.system().lower()

    if system == 'windows':
        cmd = ['ping', '-n', str(PING_COUNT), '-w', str(PING_TIMEOUT * 1000), ip]
    else:
        cmd = ['ping', '-c', str(PING_COUNT), '-W', str(PING_TIMEOUT), ip]

    try:
        t0 = time.time()
        result = subprocess.run(
            cmd,
            capture_output=True,
            text=True,
            timeout=PING_TIMEOUT + 2
        )
        elapsed_ms = (time.time() - t0) * 1000

        if result.returncode == 0:
            latency = _parse_latency(result.stdout, elapsed_ms)
            return 'online', latency
        else:
            return 'offline', None

    except subprocess.TimeoutExpired:
        return 'offline', None
    except Exception as e:
        log.warning(f"Ping error for {ip}: {e}")
        return 'unknown', None


def ping(ip: str) -> tuple[str, float | None]:
    """
    Pings ip with up to (1 + PING_RETRIES) attempts.

    - Returns immediately on the first success (fail-fast-on-success).
    - Waits RETRY_DELAY seconds between attempts.
    - Device is 'online' if at least one attempt succeeds.
    - Latency is the average of all successful responses.
    - Device is 'offline' only when every attempt fails.
    """
    max_attempts = 1 + PING_RETRIES
    latencies: list[float] = []
    last_status = 'offline'

    for attempt in range(1, max_attempts + 1):
        status, latency = _ping_once(ip)

        if status == 'online':
            latencies.append(latency)
            if attempt > 1:
                log.debug(f"  {ip}: online on attempt {attempt}/{max_attempts}")
            last_status = 'online'
            break  # succeed fast — no need for more attempts

        last_status = status  # 'offline' or 'unknown'

        if attempt < max_attempts:
            log.debug(f"  {ip}: attempt {attempt} failed, retrying in {RETRY_DELAY}s…")
            time.sleep(RETRY_DELAY)

    avg_latency = round(sum(latencies) / len(latencies), 2) if latencies else None
    return last_status, avg_latency


def _parse_latency(output: str, fallback_ms: float) -> float:
    """Extracts latency from ping output text; falls back to total round-trip time."""
    # Linux/macOS: "time=12.3 ms"
    m = re.search(r'time[=<]([\d.]+)\s*ms', output)
    if m:
        return float(m.group(1))
    # Windows (Italian locale): "tempo=12ms"
    m = re.search(r'tempo[=<]([\d.]+)\s*ms', output, re.IGNORECASE)
    if m:
        return float(m.group(1))
    return round(fallback_ms, 2)


def send_results(results: list[dict]) -> bool:
    """Sends ping results in bulk to the API."""
    url = f"{API_BASE_URL}?action=ping_results_bulk"
    try:
        resp = requests.post(
            url,
            json=results,
            headers=_api_headers(),
            timeout=REQUEST_TIMEOUT
        )
        resp.raise_for_status()
        data = resp.json()
        log.info(f"Results saved: {data.get('saved', '?')}/{len(results)}")
        return True
    except requests.RequestException as e:
        log.error(f"Failed to send results: {e}")
        return False


# ─── Main ─────────────────────────────────────────────────────────────────────

def main():
    log.info(f"=== Polling cycle started (retries={PING_RETRIES}, delay={RETRY_DELAY}s) ===")
    start = time.time()

    devices = get_devices()
    if not devices:
        log.warning("No devices to ping. Exiting.")
        sys.exit(0)

    results = []
    for dev in devices:
        device_id = dev.get('id')
        ip        = dev.get('ip_address', '')
        name      = dev.get('name', str(device_id))

        if not ip:
            log.warning(f"Device #{device_id} ({name}): missing IP address, skipping.")
            continue

        status, latency = ping(ip)
        log.info(f"  {name} ({ip}): {status}" +
                 (f" [{latency:.1f} ms]" if latency is not None else ""))

        results.append({
            'device_id':  device_id,
            'status':     status,
            'latency_ms': latency
        })

    if results:
        send_results(results)

    elapsed = time.time() - start
    log.info(f"=== Polling cycle completed in {elapsed:.2f}s ===")


if __name__ == '__main__':
    main()

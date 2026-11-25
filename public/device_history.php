<?php
/**
 * device_history.php — Ping history + latency chart for a single device (private area).
 */
require_once __DIR__ . '/../php/auth.php';
require_once __DIR__ . '/../php/db.php';
require_once __DIR__ . '/../php/i18n.php';
require_login();
i18n_init();

$id     = (int) ($_GET['id'] ?? 0);
$device = DB::getDevice($id);
if (!$device) {
    header('Location: dashboard.php');
    exit;
}
$history = DB::getPingHistory($id, 60);

// Build chart data arrays (chronological order for the chart)
$chart_labels   = [];
$chart_latency  = [];   // null = offline/unknown
$chart_statuses = [];   // 'online' | 'offline' | 'unknown'
foreach (array_reverse($history) as $h) {
    // Shorten timestamp to HH:MM for X-axis labels
    $chart_labels[]   = substr($h['pinged_at'], 11, 5);
    $chart_latency[]  = $h['latency_ms'] !== null ? (float)$h['latency_ms'] : null;
    $chart_statuses[] = $h['status'];
}
$chart_json = json_encode([
    'labels'   => $chart_labels,
    'latency'  => $chart_latency,
    'statuses' => $chart_statuses,
]);
?>
<!DOCTYPE html>
<html lang="<?= i18n_lang() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= __('history_title', ['device' => htmlspecialchars($device['name'])]) ?></title>
<link rel="stylesheet" href="assets/style.css">
<style>
.chart-wrap {
    background: var(--bg2);
    border-radius: var(--radius);
    padding: 20px 24px 16px;
    margin-bottom: 28px;
}
.chart-title {
    font-size: .82rem; text-transform: uppercase; letter-spacing: .05em;
    color: var(--text-muted); font-weight: 600; margin-bottom: 14px;
}
#latencyChart {
    display: block;
    width: 100%;
    height: 180px;
}
.chart-legend {
    display: flex; gap: 16px; margin-top: 10px; flex-wrap: wrap;
}
.chart-legend span {
    font-size: .78rem; color: var(--text-muted);
    display: flex; align-items: center; gap: 5px;
}
.chart-legend .dot {
    width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0;
}
.dot-online  { background: #38bdf8; }
.dot-offline { background: #ef4444; }
.dot-null    { background: var(--bg3); }
</style>
</head>
<body>
<header>
    <h1><?= site_logo() ?><span class="h1-sep" aria-hidden="true"></span>
        <span class="h1-text"><?= __('history_title', ['device' => htmlspecialchars($device['name'])]) ?></span>
    </h1>
    <div class="header-actions">
        <?= i18n_switcher() ?>
        <a href="dashboard.php" class="btn-secondary"><?= __('history_back') ?></a>
    </div>
</header>
<main>
    <p><strong>IP:</strong> <code><?= htmlspecialchars($device['ip_address']) ?></code>
       &nbsp;|&nbsp; <strong><?= __('col_location') ?>:</strong> <?= htmlspecialchars($device['location']) ?></p>

    <?php if (empty($history)): ?>
        <p class="empty"><?= __('history_empty') ?></p>
    <?php else: ?>

    <!-- ── Latency chart ──────────────────────────────────────────────────── -->
    <div class="chart-wrap">
        <div class="chart-title"><?= __('history_chart_title') ?></div>
        <canvas id="latencyChart"></canvas>
        <div class="chart-legend">
            <span><span class="dot dot-online"></span> <?= __('history_chart_latency') ?></span>
            <span><span class="dot dot-offline"></span> <?= __('history_chart_offline') ?></span>
            <span><span class="dot dot-null"></span> <?= __('history_chart_no_data') ?></span>
        </div>
    </div>

    <!-- ── History table ──────────────────────────────────────────────────── -->
    <table class="devices-table">
        <thead>
            <tr>
                <th><?= __('history_col_time') ?></th>
                <th><?= __('history_col_status') ?></th>
                <th><?= __('history_col_latency') ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($history as $h): ?>
            <tr>
                <td><?= htmlspecialchars($h['pinged_at']) ?></td>
                <td><span class="badge badge-<?= $h['status'] ?>"><?= strtoupper($h['status']) ?></span></td>
                <td><?= $h['latency_ms'] !== null ? number_format($h['latency_ms'], 1) . ' ms' : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</main>
<footer>
    <p>© <?= date('Y') ?> <a href="https://doctoriot.wordpress.com/" target="_blank" rel="noopener">Doctor IoT</a>
    &nbsp;·&nbsp; <?= __('footer_version') ?></p>
</footer>

<script>
(function () {
    var raw = <?= $chart_json ?>;
    if (!raw || !raw.labels || !raw.labels.length) return;

    var canvas = document.getElementById('latencyChart');
    if (!canvas) return;

    // High-DPI support
    var dpr    = window.devicePixelRatio || 1;
    var W      = canvas.offsetWidth  || canvas.parentElement.offsetWidth || 800;
    var H      = 180;
    canvas.width  = W * dpr;
    canvas.height = H * dpr;
    canvas.style.width  = W + 'px';
    canvas.style.height = H + 'px';

    var ctx = canvas.getContext('2d');
    ctx.scale(dpr, dpr);

    // ── Layout constants ──────────────────────────────────────────────────
    var PAD_L  = 52;   // left  (Y-axis labels)
    var PAD_R  = 12;
    var PAD_T  = 14;
    var PAD_B  = 36;   // bottom (X-axis labels)
    var gW     = W - PAD_L - PAD_R;
    var gH     = H - PAD_T - PAD_B;

    // ── Colour palette (CSS vars approximated) ────────────────────────────
    var C_BG        = '#1e293b';
    var C_GRID      = 'rgba(51,65,85,.6)';
    var C_LINE      = '#38bdf8';
    var C_LINE_FILL = 'rgba(56,189,248,.12)';
    var C_OFFLINE   = '#ef4444';
    var C_TEXT      = '#94a3b8';
    var C_POINT_ON  = '#38bdf8';
    var C_POINT_OFF = '#ef4444';

    // ── Data ──────────────────────────────────────────────────────────────
    var labels   = raw.labels;
    var latency  = raw.latency;
    var statuses = raw.statuses;
    var n        = labels.length;

    // Max latency for Y scale (min 10 ms, round up to next 10)
    var maxLat = 10;
    for (var i = 0; i < n; i++) {
        if (latency[i] !== null && latency[i] > maxLat) maxLat = latency[i];
    }
    maxLat = Math.ceil(maxLat / 10) * 10 * 1.1;

    function xOf(i) { return PAD_L + (i / (n - 1)) * gW; }
    function yOf(v) { return PAD_T + gH - (v / maxLat) * gH; }

    // ── Background ────────────────────────────────────────────────────────
    ctx.fillStyle = C_BG;
    ctx.fillRect(0, 0, W, H);

    // ── Grid lines ────────────────────────────────────────────────────────
    ctx.strokeStyle = C_GRID;
    ctx.lineWidth   = 1;
    var ySteps = 4;
    for (var s = 0; s <= ySteps; s++) {
        var yv  = (s / ySteps) * maxLat;
        var yPx = yOf(yv);
        ctx.beginPath();
        ctx.moveTo(PAD_L, yPx);
        ctx.lineTo(PAD_L + gW, yPx);
        ctx.stroke();
        // Y label
        ctx.fillStyle  = C_TEXT;
        ctx.font       = '10px system-ui, sans-serif';
        ctx.textAlign  = 'right';
        ctx.textBaseline = 'middle';
        ctx.fillText(Math.round(yv) + ' ms', PAD_L - 6, yPx);
    }

    // ── Offline zones (vertical red lines for offline points) ─────────────
    for (var i = 0; i < n; i++) {
        if (statuses[i] !== 'online') {
            var xPx = xOf(i);
            ctx.strokeStyle = 'rgba(239,68,68,.25)';
            ctx.lineWidth   = 1;
            ctx.beginPath();
            ctx.moveTo(xPx, PAD_T);
            ctx.lineTo(xPx, PAD_T + gH);
            ctx.stroke();
        }
    }

    // ── Fill area under the latency line ──────────────────────────────────
    var pts = [];
    for (var i = 0; i < n; i++) {
        if (latency[i] !== null) pts.push({ x: xOf(i), y: yOf(latency[i]) });
    }

    if (pts.length > 1) {
        ctx.beginPath();
        ctx.moveTo(pts[0].x, pts[0].y);
        for (var p = 1; p < pts.length; p++) {
            ctx.lineTo(pts[p].x, pts[p].y);
        }
        ctx.lineTo(pts[pts.length - 1].x, PAD_T + gH);
        ctx.lineTo(pts[0].x, PAD_T + gH);
        ctx.closePath();
        ctx.fillStyle = C_LINE_FILL;
        ctx.fill();

        // ── Latency line ──────────────────────────────────────────────────
        ctx.beginPath();
        ctx.moveTo(pts[0].x, pts[0].y);
        for (var p = 1; p < pts.length; p++) {
            ctx.lineTo(pts[p].x, pts[p].y);
        }
        ctx.strokeStyle = C_LINE;
        ctx.lineWidth   = 2;
        ctx.lineJoin    = 'round';
        ctx.stroke();
    }

    // ── Data points ───────────────────────────────────────────────────────
    var DOT = 3.5;
    for (var i = 0; i < n; i++) {
        var xPx = xOf(i);
        if (latency[i] !== null) {
            var yPx = yOf(latency[i]);
            ctx.beginPath();
            ctx.arc(xPx, yPx, DOT, 0, Math.PI * 2);
            ctx.fillStyle   = C_POINT_ON;
            ctx.fill();
        } else if (statuses[i] !== 'online') {
            // Small red tick at the bottom for offline
            ctx.beginPath();
            ctx.arc(xPx, PAD_T + gH - 4, DOT, 0, Math.PI * 2);
            ctx.fillStyle = C_POINT_OFF;
            ctx.fill();
        }
    }

    // ── X-axis labels (show every Nth, depending on how many points) ──────
    var step = Math.max(1, Math.ceil(n / 10));
    ctx.fillStyle    = C_TEXT;
    ctx.font         = '10px system-ui, sans-serif';
    ctx.textAlign    = 'center';
    ctx.textBaseline = 'top';
    for (var i = 0; i < n; i += step) {
        ctx.fillText(labels[i], xOf(i), PAD_T + gH + 6);
    }
    // Always show the last label
    if ((n - 1) % step !== 0) {
        ctx.fillText(labels[n - 1], xOf(n - 1), PAD_T + gH + 6);
    }

    // ── Tooltip on hover ─────────────────────────────────────────────────
    canvas.addEventListener('mousemove', function (e) {
        var rect = canvas.getBoundingClientRect();
        var mx   = e.clientX - rect.left;
        // Find nearest data point
        var best = -1, bestDist = 999;
        for (var i = 0; i < n; i++) {
            var d = Math.abs(xOf(i) - mx);
            if (d < bestDist) { bestDist = d; best = i; }
        }
        if (best < 0 || bestDist > 24) {
            canvas.title = '';
            return;
        }
        var lat = latency[best] !== null ? latency[best].toFixed(1) + ' ms' : '—';
        canvas.title = labels[best] + '  ·  ' + statuses[best].toUpperCase() + '  ·  ' + lat;
    });
})();
</script>
</body>
</html>

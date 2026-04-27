<?php
session_start();
include("config.php");
include("header.php");

if (!isset($_SESSION['email'])) {
    header("Location: login.php");
    exit;
}

$email = $_SESSION['email'];

/* Resolve traveler */
$stmt = $conn->prepare("SELECT traveler_id, name FROM traveler WHERE email = ? LIMIT 1");
$stmt->bind_param("s", $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) { header("Location: login.php"); exit; }
$traveler_id  = (int) $user['traveler_id'];
$travelerName = $user['name'];

/* ----- Helper: run a parameterized query, return all rows ----- */
function q_all(mysqli $conn, string $sql, string $types, array $params): array {
    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}
function q_one(mysqli $conn, string $sql, string $types, array $params): ?array {
    $rows = q_all($conn, $sql, $types, $params);
    return $rows ? $rows[0] : null;
}

/* ----- KPIs ----- */
$kpi_trips = (int) (q_one($conn,
    "SELECT COUNT(*) AS c FROM itinerary
      WHERE traveler_id = ? AND deleted_at IS NULL",
    "i", [$traveler_id])['c'] ?? 0);

$kpi_days = (int) (q_one($conn,
    "SELECT COUNT(*) AS c
       FROM itinerary_day d
       JOIN itinerary i ON i.itinerary_id = d.itinerary_id
      WHERE i.traveler_id = ? AND i.deleted_at IS NULL",
    "i", [$traveler_id])['c'] ?? 0);

$kpi_activities = (int) (q_one($conn,
    "SELECT COUNT(*) AS c
       FROM itinerary_activity ia
       JOIN itinerary_day d ON d.day_id = ia.day_id
       JOIN itinerary i ON i.itinerary_id = d.itinerary_id
      WHERE i.traveler_id = ? AND i.deleted_at IS NULL",
    "i", [$traveler_id])['c'] ?? 0);

$kpi_lodging = (float) (q_one($conn,
    "SELECT COALESCE(SUM(acc.cost_per_night), 0) AS s
       FROM itinerary i
       JOIN itinerary_day d ON d.itinerary_id = i.itinerary_id
       JOIN accommodation acc ON acc.accommodation_id = d.accommodation_id
      WHERE i.traveler_id = ? AND i.deleted_at IS NULL",
    "i", [$traveler_id])['s'] ?? 0);

$kpi_attr = (float) (q_one($conn,
    "SELECT COALESCE(SUM(a.entry_fee), 0) AS s
       FROM itinerary_activity ia
       JOIN attraction a ON ia.activity_id = a.attraction_id AND ia.activity_type = 'attraction'
       JOIN itinerary_day d ON d.day_id = ia.day_id
       JOIN itinerary i ON i.itinerary_id = d.itinerary_id
      WHERE i.traveler_id = ? AND i.deleted_at IS NULL",
    "i", [$traveler_id])['s'] ?? 0);

$kpi_evt = (float) (q_one($conn,
    "SELECT COALESCE(SUM(e.price), 0) AS s
       FROM itinerary_activity ia
       JOIN event e ON ia.activity_id = e.event_id AND ia.activity_type = 'event'
       JOIN itinerary_day d ON d.day_id = ia.day_id
       JOIN itinerary i ON i.itinerary_id = d.itinerary_id
      WHERE i.traveler_id = ? AND i.deleted_at IS NULL",
    "i", [$traveler_id])['s'] ?? 0);

$kpi_total_spend = $kpi_lodging + $kpi_attr + $kpi_evt;
$kpi_avg_spend   = $kpi_trips > 0 ? $kpi_total_spend / $kpi_trips : 0;

/* ----- Trips per destination (bar) ----- */
$trips_per_dest = q_all($conn,
    "SELECT d.city, d.country, COUNT(*) AS trips
       FROM itinerary i
       JOIN destination d ON d.destination_id = i.destination_id
      WHERE i.traveler_id = ? AND i.deleted_at IS NULL
      GROUP BY d.destination_id
      ORDER BY trips DESC",
    "i", [$traveler_id]);

/* ----- Trips created per month (line) ----- */
$trips_per_month = q_all($conn,
    "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS trips
       FROM itinerary
      WHERE traveler_id = ? AND deleted_at IS NULL
      GROUP BY ym
      ORDER BY ym ASC",
    "i", [$traveler_id]);

/* ----- Spend by category (donut) ----- */
$spend_by_cat = [
    ['label' => 'Lodging',     'value' => round($kpi_lodging, 2)],
    ['label' => 'Attractions', 'value' => round($kpi_attr,    2)],
    ['label' => 'Events',      'value' => round($kpi_evt,     2)],
];

/* ----- Top 5 most-added attractions (bar) ----- */
$top_attractions = q_all($conn,
    "SELECT a.name, COUNT(*) AS times_added
       FROM itinerary_activity ia
       JOIN attraction a ON ia.activity_id = a.attraction_id AND ia.activity_type = 'attraction'
       JOIN itinerary_day d ON d.day_id = ia.day_id
       JOIN itinerary i ON i.itinerary_id = d.itinerary_id
      WHERE i.traveler_id = ? AND i.deleted_at IS NULL
      GROUP BY a.attraction_id
      ORDER BY times_added DESC
      LIMIT 5",
    "i", [$traveler_id]);

/* ----- Avg spend per destination (bar) ----- */
$avg_spend_per_dest = q_all($conn,
    "SELECT d.city,
            (
              COALESCE((SELECT SUM(acc.cost_per_night)
                          FROM itinerary_day dd
                          JOIN accommodation acc ON acc.accommodation_id = dd.accommodation_id
                         WHERE dd.itinerary_id = i.itinerary_id), 0)
              + COALESCE((SELECT SUM(a2.entry_fee)
                            FROM itinerary_activity ia2
                            JOIN itinerary_day dd2 ON dd2.day_id = ia2.day_id
                            JOIN attraction a2 ON a2.attraction_id = ia2.activity_id AND ia2.activity_type='attraction'
                           WHERE dd2.itinerary_id = i.itinerary_id), 0)
              + COALESCE((SELECT SUM(e2.price)
                            FROM itinerary_activity ia3
                            JOIN itinerary_day dd3 ON dd3.day_id = ia3.day_id
                            JOIN event e2 ON e2.event_id = ia3.activity_id AND ia3.activity_type='event'
                           WHERE dd3.itinerary_id = i.itinerary_id), 0)
            ) AS trip_total
       FROM itinerary i
       JOIN destination d ON d.destination_id = i.destination_id
      WHERE i.traveler_id = ? AND i.deleted_at IS NULL",
    "i", [$traveler_id]);

// Aggregate in PHP: avg per city
$avg_by_city = [];
foreach ($avg_spend_per_dest as $r) {
    $city = $r['city'];
    if (!isset($avg_by_city[$city])) $avg_by_city[$city] = ['sum' => 0, 'n' => 0];
    $avg_by_city[$city]['sum'] += (float) $r['trip_total'];
    $avg_by_city[$city]['n']   += 1;
}
$avg_by_city_rows = [];
foreach ($avg_by_city as $city => $agg) {
    $avg_by_city_rows[] = ['city' => $city, 'avg' => round($agg['sum'] / max(1, $agg['n']), 2)];
}
usort($avg_by_city_rows, function ($a, $b) { return $b['avg'] <=> $a['avg']; });

/* ----- Budget adherence: overall planned vs spent ----- */
$total_budget = (float) (q_one($conn,
    "SELECT COALESCE(SUM(budget), 0) AS s
       FROM itinerary
      WHERE traveler_id = ? AND deleted_at IS NULL AND budget > 0",
    "i", [$traveler_id])['s'] ?? 0);

/* Encode all chart payloads as JSON for Chart.js */
$json = function ($v) { return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); };
?>

<div class="page-wrapper">

    <!-- HERO -->
    <div class="analytics-hero d-flex justify-content-between align-items-center flex-wrap">
        <div>
            <h2><i class="bi bi-bar-chart-fill"></i>&nbsp; Your travel insights</h2>
            <p>How <?php echo htmlspecialchars($travelerName); ?> has been planning so far.</p>
        </div>
        <div class="text-end">
            <div class="small" style="opacity: 0.85;">As of</div>
            <div class="fw-bold" style="font-size: 1.1rem;"><?php echo date('M j, Y'); ?></div>
        </div>
    </div>

    <?php if ($kpi_trips === 0): ?>
        <div class="alert alert-info text-center">
            <i class="bi bi-info-circle me-1"></i>
            No data yet. Plan your first trip to populate the dashboard.
            <a href="preferences.php" class="btn btn-sm btn-primary ms-2">Plan a trip</a>
        </div>
    <?php else: ?>

    <!-- KPI strip -->
    <div class="row g-3 mb-4">
        <div class="col-md-2 col-6">
            <div class="card kpi-card kpi-pink border-0 shadow-sm p-3 text-center">
                <i class="bi bi-suitcase-lg-fill mb-1" style="font-size:1.2rem;opacity:.85;"></i>
                <div class="kpi-label">Trips</div>
                <div class="kpi-value"><?php echo $kpi_trips; ?></div>
            </div>
        </div>
        <div class="col-md-2 col-6">
            <div class="card kpi-card kpi-violet border-0 shadow-sm p-3 text-center">
                <i class="bi bi-calendar3 mb-1" style="font-size:1.2rem;opacity:.85;"></i>
                <div class="kpi-label">Days</div>
                <div class="kpi-value"><?php echo $kpi_days; ?></div>
            </div>
        </div>
        <div class="col-md-2 col-6">
            <div class="card kpi-card kpi-blue border-0 shadow-sm p-3 text-center">
                <i class="bi bi-stars mb-1" style="font-size:1.2rem;opacity:.85;"></i>
                <div class="kpi-label">Activities</div>
                <div class="kpi-value"><?php echo $kpi_activities; ?></div>
            </div>
        </div>
        <div class="col-md-2 col-6">
            <div class="card kpi-card kpi-emerald border-0 shadow-sm p-3 text-center">
                <i class="bi bi-cash-stack mb-1" style="font-size:1.2rem;opacity:.85;"></i>
                <div class="kpi-label">Total spend</div>
                <div class="kpi-value">$<?php echo number_format($kpi_total_spend, 0); ?></div>
            </div>
        </div>
        <div class="col-md-2 col-6">
            <div class="card kpi-card kpi-amber border-0 shadow-sm p-3 text-center">
                <i class="bi bi-graph-up mb-1" style="font-size:1.2rem;opacity:.85;"></i>
                <div class="kpi-label">Avg / trip</div>
                <div class="kpi-value">$<?php echo number_format($kpi_avg_spend, 0); ?></div>
            </div>
        </div>
        <div class="col-md-2 col-6">
            <div class="card kpi-card kpi-rose border-0 shadow-sm p-3 text-center">
                <i class="bi bi-piggy-bank-fill mb-1" style="font-size:1.2rem;opacity:.85;"></i>
                <div class="kpi-label">Budget</div>
                <div class="kpi-value">$<?php echo number_format($total_budget, 0); ?></div>
            </div>
        </div>
    </div>

    <h5 class="section-title"><i class="bi bi-geo-alt-fill"></i> Where you're going</h5>
    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <div class="card chart-card border-0 shadow-sm">
                <h6 class="fw-bold mb-3">Trips per destination</h6>
                <canvas id="chartTripsByDest" height="220"></canvas>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card chart-card border-0 shadow-sm">
                <h6 class="fw-bold mb-3">Spend by category</h6>
                <canvas id="chartSpendByCat" height="220"></canvas>
            </div>
        </div>
    </div>

    <h5 class="section-title"><i class="bi bi-clock-history"></i> Patterns over time</h5>
    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <div class="card chart-card border-0 shadow-sm">
                <h6 class="fw-bold mb-3">Trips planned per month</h6>
                <canvas id="chartTripsByMonth" height="220"></canvas>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card chart-card border-0 shadow-sm">
                <h6 class="fw-bold mb-3">Top 5 attractions you keep adding</h6>
                <canvas id="chartTopAttractions" height="220"></canvas>
            </div>
        </div>
    </div>

    <h5 class="section-title"><i class="bi bi-table"></i> Average trip cost by destination</h5>
    <div class="card chart-card border-0 shadow-sm mb-3">
        <table class="table mb-0">
            <thead><tr><th>City</th><th class="text-end">Avg trip total</th></tr></thead>
            <tbody>
                <?php foreach ($avg_by_city_rows as $r): ?>
                    <tr>
                        <td><i class="bi bi-pin-map-fill text-danger me-1"></i><?php echo htmlspecialchars($r['city']); ?></td>
                        <td class="text-end fw-semibold">$<?php echo number_format($r['avg'], 2); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($avg_by_city_rows)): ?>
                    <tr><td colspan="2" class="text-muted text-center">No data.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function() {
    const tripsByDest    = <?php echo $json($trips_per_dest); ?>;
    const tripsByMonth   = <?php echo $json($trips_per_month); ?>;
    const spendByCat     = <?php echo $json($spend_by_cat); ?>;
    const topAttractions = <?php echo $json($top_attractions); ?>;

    const palette = ['#ff385c', '#ffb3c6', '#7c3aed', '#0ea5e9', '#22c55e', '#f59e0b', '#ef4444', '#14b8a6'];

    if (document.getElementById('chartTripsByDest') && tripsByDest.length) {
        new Chart(document.getElementById('chartTripsByDest'), {
            type: 'bar',
            data: {
                labels: tripsByDest.map(r => r.city),
                datasets: [{
                    label: 'Trips',
                    data:  tripsByDest.map(r => Number(r.trips)),
                    backgroundColor: '#ff385c',
                    borderRadius: 6
                }]
            },
            options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
        });
    }

    if (document.getElementById('chartTripsByMonth') && tripsByMonth.length) {
        new Chart(document.getElementById('chartTripsByMonth'), {
            type: 'line',
            data: {
                labels: tripsByMonth.map(r => r.ym),
                datasets: [{
                    label: 'Trips',
                    data:  tripsByMonth.map(r => Number(r.trips)),
                    borderColor: '#ff385c',
                    backgroundColor: 'rgba(255,56,92,0.15)',
                    fill: true,
                    tension: 0.35
                }]
            },
            options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
        });
    }

    if (document.getElementById('chartSpendByCat')) {
        const nonZero = spendByCat.filter(r => Number(r.value) > 0);
        if (nonZero.length) {
            new Chart(document.getElementById('chartSpendByCat'), {
                type: 'doughnut',
                data: {
                    labels: nonZero.map(r => r.label),
                    datasets: [{ data: nonZero.map(r => Number(r.value)), backgroundColor: palette }]
                },
                options: { plugins: { legend: { position: 'bottom' } } }
            });
        }
    }

    if (document.getElementById('chartTopAttractions') && topAttractions.length) {
        new Chart(document.getElementById('chartTopAttractions'), {
            type: 'bar',
            data: {
                labels: topAttractions.map(r => r.name),
                datasets: [{
                    label: 'Times added',
                    data:  topAttractions.map(r => Number(r.times_added)),
                    backgroundColor: '#7c3aed',
                    borderRadius: 6
                }]
            },
            options: { indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { precision: 0 } } } }
        });
    }
})();
</script>

<?php include("footer.php"); ?>

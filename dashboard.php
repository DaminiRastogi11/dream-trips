<?php
session_start();
include("config.php");
include("header.php");

if (!isset($_SESSION['email'])) {
    header("Location: login.php");
    exit;
}

$email = $_SESSION['email'];

/* Get traveler */
$stmt = $conn->prepare("SELECT traveler_id, name FROM traveler WHERE email = ? LIMIT 1");
$stmt->bind_param("s", $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
$travelerName = $user ? $user['name'] : "Traveler";
$traveler_id  = $user ? (int) $user['traveler_id'] : 0;

/* ============== KPI ROLLUPS (one query, three numbers) ============== */
$stmt = $conn->prepare(
    "SELECT
        COUNT(*) AS trips_planned,
        COALESCE(SUM(i.budget), 0) AS total_budget
     FROM itinerary i
     WHERE i.traveler_id = ? AND i.deleted_at IS NULL"
);
$stmt->bind_param("i", $traveler_id);
$stmt->execute();
$kpi = $stmt->get_result()->fetch_assoc() ?: ['trips_planned' => 0, 'total_budget' => 0];
$stmt->close();

/* Days planned across all active itineraries */
$stmt = $conn->prepare(
    "SELECT COUNT(*) AS days_planned
     FROM itinerary_day d
     JOIN itinerary i ON i.itinerary_id = d.itinerary_id
     WHERE i.traveler_id = ? AND i.deleted_at IS NULL"
);
$stmt->bind_param("i", $traveler_id);
$stmt->execute();
$days_planned = (int) ($stmt->get_result()->fetch_assoc()['days_planned'] ?? 0);
$stmt->close();

/* Total spend across active trips (sum of accommodation + activities) */
$stmt = $conn->prepare(
    "SELECT
        COALESCE(SUM(acc.cost_per_night), 0) AS lodging
     FROM itinerary i
     JOIN itinerary_day d ON d.itinerary_id = i.itinerary_id
     LEFT JOIN accommodation acc ON acc.accommodation_id = d.accommodation_id
     WHERE i.traveler_id = ? AND i.deleted_at IS NULL"
);
$stmt->bind_param("i", $traveler_id);
$stmt->execute();
$lodging_total = (float) ($stmt->get_result()->fetch_assoc()['lodging'] ?? 0);
$stmt->close();

$stmt = $conn->prepare(
    "SELECT
        COALESCE(SUM(CASE WHEN ia.activity_type='attraction' THEN a.entry_fee ELSE 0 END), 0) AS attr_total,
        COALESCE(SUM(CASE WHEN ia.activity_type='event'      THEN e.price     ELSE 0 END), 0) AS evt_total
     FROM itinerary i
     JOIN itinerary_day  d  ON d.itinerary_id = i.itinerary_id
     JOIN itinerary_activity ia ON ia.day_id = d.day_id
     LEFT JOIN attraction a ON ia.activity_type='attraction' AND ia.activity_id=a.attraction_id
     LEFT JOIN event      e ON ia.activity_type='event'      AND ia.activity_id=e.event_id
     WHERE i.traveler_id = ? AND i.deleted_at IS NULL"
);
$stmt->bind_param("i", $traveler_id);
$stmt->execute();
$ax = $stmt->get_result()->fetch_assoc() ?: ['attr_total' => 0, 'evt_total' => 0];
$stmt->close();

$total_spend = $lodging_total + (float)$ax['attr_total'] + (float)$ax['evt_total'];

/* ============== NEXT UPCOMING TRIP ============== */
$stmt = $conn->prepare(
    "SELECT i.itinerary_id, i.start_date, d.city, d.country
     FROM itinerary i
     JOIN destination d ON d.destination_id = i.destination_id
     WHERE i.traveler_id = ? AND i.deleted_at IS NULL
       AND i.start_date IS NOT NULL AND i.start_date >= CURDATE()
     ORDER BY i.start_date ASC LIMIT 1"
);
$stmt->bind_param("i", $traveler_id);
$stmt->execute();
$next_trip = $stmt->get_result()->fetch_assoc();
$stmt->close();

$days_until = null;
if ($next_trip) {
    $diff = (strtotime($next_trip['start_date']) - strtotime(date('Y-m-d'))) / 86400;
    $days_until = (int) round($diff);
}

/* ============== RECENT TRIPS (last 3 active) ============== */
$stmt = $conn->prepare(
    "SELECT i.itinerary_id, i.created_at, i.start_date, d.city, d.country
     FROM itinerary i
     JOIN destination d ON d.destination_id = i.destination_id
     WHERE i.traveler_id = ? AND i.deleted_at IS NULL
     ORDER BY i.itinerary_id DESC LIMIT 3"
);
$stmt->bind_param("i", $traveler_id);
$stmt->execute();
$recent = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<div class="page-wrapper">

    <!-- WELCOME + SEARCH -->
    <h2 class="mb-1" style="font-weight: 800;">
        Welcome back, <?php echo htmlspecialchars($travelerName); ?>.
    </h2>
    <p class="text-muted mb-4">Where are we headed next?</p>

    <div class="search-container mb-4">
        <input type="text" id="destinationSearch" class="search-input" placeholder="Where do you want to go?">
        <button class="search-btn" onclick="goSearch()">Search</button>
    </div>

    <!-- KPI CARDS -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card kpi-card border-0 shadow-sm p-3 text-center">
                <div class="kpi-label">Trips planned</div>
                <div class="kpi-value"><?php echo (int) $kpi['trips_planned']; ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card kpi-card border-0 shadow-sm p-3 text-center">
                <div class="kpi-label">Days planned</div>
                <div class="kpi-value"><?php echo $days_planned; ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card kpi-card border-0 shadow-sm p-3 text-center">
                <div class="kpi-label">Total planned spend</div>
                <div class="kpi-value">$<?php echo number_format($total_spend, 0); ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card kpi-card border-0 shadow-sm p-3 text-center">
                <div class="kpi-label">Combined budget</div>
                <div class="kpi-value">$<?php echo number_format((float)$kpi['total_budget'], 0); ?></div>
            </div>
        </div>
    </div>

    <!-- NEXT UPCOMING TRIP -->
    <?php if ($next_trip): ?>
        <div class="hero d-flex flex-column flex-md-row align-items-center justify-content-between mb-4">
            <div>
                <div class="text-uppercase small text-muted fw-bold mb-1">Up next</div>
                <h3 class="fw-bold mb-1">
                    <?php echo htmlspecialchars($next_trip['city'] . ', ' . $next_trip['country']); ?>
                </h3>
                <p class="mb-3">
                    Starting <strong><?php echo date("D, M j, Y", strtotime($next_trip['start_date'])); ?></strong>
                    &mdash;
                    <?php if ($days_until === 0): ?>
                        <strong>today</strong>
                    <?php elseif ($days_until === 1): ?>
                        in <strong>1 day</strong>
                    <?php else: ?>
                        in <strong><?php echo $days_until; ?> days</strong>
                    <?php endif; ?>
                </p>
                <a href="summary.php?itinerary_id=<?php echo (int) $next_trip['itinerary_id']; ?>"
                   class="btn btn-primary px-4 py-2 me-2">Open itinerary</a>
                <a href="itinerary_builder.php?itinerary_id=<?php echo (int) $next_trip['itinerary_id']; ?>"
                   class="btn btn-outline-primary px-4 py-2">Continue planning</a>
            </div>
            <img src="https://images.unsplash.com/photo-1507525428034-b723cf961d3e?auto=compress&cs=tinysrgb&w=700"
                 class="img-fluid rounded-4 shadow hero-img mt-4 mt-md-0"
                 style="max-width: 40%; border-radius: 22px;">
        </div>
    <?php else: ?>
        <div class="hero d-flex flex-column flex-md-row align-items-center justify-content-between mb-4">
            <div>
                <h3 class="fw-bold mb-2">Plan your next getaway in minutes.</h3>
                <p class="mb-3">
                    Tell us your budget and interests, then build a day-by-day itinerary.
                </p>
                <a href="preferences.php" class="btn btn-primary px-4 py-2 me-2">Plan a Trip</a>
                <a href="destinations.php" class="btn btn-outline-primary px-4 py-2">Browse destinations</a>
            </div>
            <img src="https://images.unsplash.com/photo-1507525428034-b723cf961d3e?auto=compress&cs=tinysrgb&w=700"
                 class="img-fluid rounded-4 shadow hero-img mt-4 mt-md-0"
                 style="max-width: 40%; border-radius: 22px;">
        </div>
    <?php endif; ?>

    <!-- RECENT TRIPS -->
    <h4 class="fw-bold mb-3">Recent trips</h4>
    <?php if (count($recent) === 0): ?>
        <div class="card border-0 shadow-sm p-4 text-center">
            <p class="mb-3">You haven't planned any trips yet.</p>
            <div>
                <a href="preferences.php" class="btn btn-primary">Plan your first trip</a>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($recent as $t): ?>
                <div class="col-md-4">
                    <div class="card travel-card border-0 shadow-sm p-3">
                        <div class="city mb-1"><?php echo htmlspecialchars($t['city']); ?></div>
                        <div class="text-muted small mb-2">
                            <?php echo htmlspecialchars($t['country']); ?>
                            <?php if (!empty($t['start_date'])): ?>
                                &middot; <?php echo date("M j, Y", strtotime($t['start_date'])); ?>
                            <?php else: ?>
                                &middot; created <?php echo date("M j", strtotime($t['created_at'])); ?>
                            <?php endif; ?>
                        </div>
                        <div class="d-grid gap-2">
                            <a href="summary.php?itinerary_id=<?php echo (int) $t['itinerary_id']; ?>"
                               class="btn btn-outline-primary btn-sm">View itinerary</a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="text-center mt-3">
            <a href="trips.php" class="btn btn-outline-primary">See all trips &rarr;</a>
        </div>
    <?php endif; ?>

    <div class="text-center mt-4 mb-2">
        <a href="destinations.php" class="text-muted">Browse all destinations &rarr;</a>
    </div>

</div>

<script>
function goSearch() {
    let query = document.getElementById("destinationSearch").value.trim();
    if (query.length > 0) {
        window.location.href = "destinations.php?search=" + encodeURIComponent(query);
    }
}
document.getElementById("destinationSearch").addEventListener("keydown", function(e) {
    if (e.key === "Enter") goSearch();
});
</script>

<?php include("footer.php"); ?>

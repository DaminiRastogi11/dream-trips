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
$stmt = $conn->prepare("SELECT traveler_id FROM traveler WHERE email = ? LIMIT 1");
$stmt->bind_param("s", $email);
$stmt->execute();
$travRes = $stmt->get_result();
$trav = $travRes ? $travRes->fetch_assoc() : null;
$stmt->close();

if (!$trav) {
    echo "<div class='page-wrapper text-center'><h3>User not found.</h3></div>";
    include("footer.php");
    exit;
}

$traveler_id = (int) $trav['traveler_id'];

/* FIX 2C: Search mode takes priority over preferences */
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
$is_search = ($search_term !== '');

/* FIX 2B: Load this traveler's saved preferences (climate, visa).
   Budget filtering moved to per-trip on the Itinerary Builder. */
$pref_climate = '';
$pref_visa    = '';
$has_prefs    = false;

if (!$is_search) {
    $stmt = $conn->prepare(
        "SELECT preferred_climate, visa_required
         FROM traveler_preferences
         WHERE traveler_id = ? LIMIT 1"
    );
    $stmt->bind_param("i", $traveler_id);
    $stmt->execute();
    $prefRes = $stmt->get_result();
    if ($prefRes && $prefRow = $prefRes->fetch_assoc()) {
        $has_prefs    = true;
        $pref_climate = (string) $prefRow['preferred_climate'];
        $pref_visa    = (string) $prefRow['visa_required'];
    }
    $stmt->close();
}

/* Build the destination query */
$destinations = [];
$used_fallback = false;

if ($is_search) {
    /* FIX 2C: search across city/country */
    $like = '%' . $search_term . '%';
    $stmt = $conn->prepare(
        "SELECT * FROM destination
         WHERE city LIKE ? OR country LIKE ?
         ORDER BY destination_id"
    );
    $stmt->bind_param("ss", $like, $like);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $destinations[] = $row;
    }
    $stmt->close();

} else {
    /* FIX 2B: filter by preferences (if any) */
    $where  = [];
    $types  = "";
    $params = [];

    if ($has_prefs) {
        if ($pref_climate !== '') {
            $where[]  = "d.climate = ?";
            $types   .= "s";
            $params[] = $pref_climate;
        }
        if ($pref_visa === 'Yes' || $pref_visa === 'No') {
            $where[]  = "d.visa_required = ?";
            $types   .= "s";
            $params[] = $pref_visa;
        }
    }

    $sql = "SELECT d.* FROM destination d";
    if (!empty($where)) {
        $sql .= " WHERE " . implode(" AND ", $where);
    }
    $sql .= " ORDER BY d.destination_id";

    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $destinations[] = $row;
    }
    $stmt->close();

    /* Fallback: zero matches with prefs => show everything */
    if ($has_prefs && count($destinations) === 0) {
        $used_fallback = true;
        $res = mysqli_query($conn, "SELECT * FROM destination ORDER BY destination_id");
        while ($row = mysqli_fetch_assoc($res)) {
            $destinations[] = $row;
        }
    }
}

/* FIX 3D: fallback image if image_url is missing */
$placeholder_image = "https://images.unsplash.com/photo-1488646953014-85cb44e25828?auto=compress&cs=tinysrgb&w=1200";
?>

<div class="page-wrapper">

    <!-- STEP PROGRESS TRACKER -->
    <div class="steps-wrapper my-3">
        <div class="step">Preferences</div>
        <div class="step active">Destinations</div>
        <div class="step">Activities</div>
        <div class="step">Itinerary</div>
    </div>

    <h2 class="page-title mb-3">Where would you like to go?</h2>
    <p class="page-subtitle mb-4">
        Browse destinations and pick one to see attractions and events tailored to your trip.
    </p>

    <?php if ($is_search): ?>
        <div class="alert alert-info py-2 d-flex justify-content-between align-items-center">
            <span>Showing results for: <strong><?php echo htmlspecialchars($search_term); ?></strong></span>
            <a href="destinations.php" class="btn btn-sm btn-outline-primary">Clear search</a>
        </div>
    <?php elseif ($has_prefs && !$used_fallback): ?>
        <div class="alert alert-info py-2 d-flex justify-content-between align-items-center">
            <span>Showing destinations matching your preferences.</span>
            <a href="preferences.php" class="btn btn-sm btn-outline-primary">Change preferences</a>
        </div>
    <?php elseif ($used_fallback): ?>
        <div class="alert alert-warning py-2 d-flex justify-content-between align-items-center">
            <span>No destinations match your preferences. Showing all destinations instead.</span>
            <a href="preferences.php" class="btn btn-sm btn-outline-primary">Change preferences</a>
        </div>
    <?php endif; ?>

    <?php if (count($destinations) === 0): ?>
        <p class="text-center text-muted">No destinations found.</p>
    <?php endif; ?>

    <div class="row g-4">
        <?php foreach ($destinations as $d): ?>
            <div class="col-md-4">
                <div class="card travel-card">
                    <?php
                        // FIX 3D: use destination.image_url instead of hardcoded if/else
                        $img = !empty($d['image_url']) ? $d['image_url'] : $placeholder_image;
                    ?>
                    <img src="<?php echo htmlspecialchars($img); ?>" class="card-img-top" alt="">
                    <div class="card-body">
                        <div class="city mb-1"><?php echo htmlspecialchars($d['city']); ?></div>
                        <div class="text-muted mb-2">
                            <?php echo htmlspecialchars($d['country']); ?> &bull;
                            <?php echo htmlspecialchars($d['climate']); ?> climate
                        </div>
                        <div class="small mb-3">
                            Visa <?php echo $d['visa_required'] === 'Yes' ? 'required' : 'not required'; ?>
                        </div>
                        <a href="attractions.php?destination_id=<?php echo (int) $d['destination_id']; ?>"
                           class="btn btn-primary w-100">
                            View attractions &amp; events
                        </a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <a href="preferences.php" class="btn btn-outline-primary mt-4">Back to Preferences</a>
</div>

<?php include("footer.php"); ?>

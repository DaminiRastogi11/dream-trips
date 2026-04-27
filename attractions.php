<?php
session_start();
include("config.php");
include("header.php");

if (!isset($_SESSION['email'])) {
    header("Location: login.php");
    exit;
}

if (!isset($_GET['destination_id'])) {
    echo "<div class='page-wrapper'><p>Invalid destination.</p></div>";
    include("footer.php");
    exit;
}

$destination_id = intval($_GET['destination_id']);
$email = $_SESSION['email'];

/* Get traveler (FIX 1A) */
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

/* FIX 2A:
   Do NOT insert into itinerary on every page load. Just look up
   any existing itinerary for this (traveler, destination) so we
   can pass its id to the builder. Itinerary creation is deferred
   to itinerary_builder.php and only happens when the user clicks
   "Add to Day" for the first time. */
$existing_itinerary_id = null;
$stmt = $conn->prepare(
    "SELECT itinerary_id FROM itinerary
     WHERE traveler_id = ? AND destination_id = ? AND deleted_at IS NULL
     ORDER BY itinerary_id DESC LIMIT 1"
);
$stmt->bind_param("ii", $traveler_id, $destination_id);
$stmt->execute();
$existRes = $stmt->get_result();
if ($existRes && $existRow = $existRes->fetch_assoc()) {
    $existing_itinerary_id = (int) $existRow['itinerary_id'];
}
$stmt->close();

/* Get destination info (FIX 1A) */
$stmt = $conn->prepare("SELECT * FROM destination WHERE destination_id = ? LIMIT 1");
$stmt->bind_param("i", $destination_id);
$stmt->execute();
$destRes = $stmt->get_result();
$dest = $destRes ? $destRes->fetch_assoc() : null;
$stmt->close();

if (!$dest) {
    echo "<div class='page-wrapper'><p>Destination not found.</p></div>";
    include("footer.php");
    exit;
}

/* Attractions for this destination (FIX 1A) */
$stmt_att = $conn->prepare("SELECT * FROM attraction WHERE destination_id = ?");
$stmt_att->bind_param("i", $destination_id);
$stmt_att->execute();
$attRes = $stmt_att->get_result();

/* Events for this destination (FIX 1A) */
$stmt_evt = $conn->prepare("SELECT * FROM event WHERE destination_id = ?");
$stmt_evt->bind_param("i", $destination_id);
$stmt_evt->execute();
$eventRes = $stmt_evt->get_result();

/* Build "Add to Day" URL — passes destination_id always (FIX 2A: needed
   so itinerary_builder.php can lazily create the itinerary if necessary)
   and itinerary_id when known (FIX 2D: ensures multi-trip users get
   the right one). */
function build_add_url($type, $activity_id, $destination_id, $itinerary_id) {
    $url  = "itinerary_builder.php?type=" . urlencode($type);
    $url .= "&id=" . (int) $activity_id;
    $url .= "&destination_id=" . (int) $destination_id;
    if ($itinerary_id) {
        $url .= "&itinerary_id=" . (int) $itinerary_id;
    }
    return $url;
}
?>

<div class="page-wrapper">
    <!-- STEP PROGRESS TRACKER -->
    <div class="steps-wrapper my-3">
        <div class="step">Preferences</div>
        <div class="step">Destinations</div>
        <div class="step active">Activities</div>
        <div class="step">Itinerary</div>
    </div>
    <h2 class="page-title mb-1">
        Things to do in <?php echo htmlspecialchars($dest['city']); ?>
    </h2>
    <p class="page-subtitle mb-4">
        Pick attractions &amp; events you&rsquo;d like to add to your itinerary.
    </p>

    <div class="row">
        <!-- Attractions -->
        <div class="col-md-6">
            <h4 class="mb-3">Attractions</h4>

            <?php if ($attRes->num_rows == 0): ?>
                <p class="text-muted">No attractions added yet.</p>
            <?php else: ?>
                <?php while ($a = $attRes->fetch_assoc()): ?>
                    <div class="card mb-3 shadow-sm">
                        <div class="card-body">
                            <h5 class="card-title mb-1"><?php echo htmlspecialchars($a['name']); ?></h5>
                            <div class="small text-muted mb-1">
                                <?php echo htmlspecialchars($a['category'] ?? ''); ?> &bull;
                                Rating: <?php echo htmlspecialchars((string) $a['rating']); ?>/5
                            </div>
                            <div class="small mb-2">
                                Entry fee: $<?php echo htmlspecialchars((string) $a['entry_fee']); ?> &bull;
                                Avg time: <?php echo htmlspecialchars((string) $a['avg_time_hours']); ?> hrs
                            </div>
                            <a href="<?php echo htmlspecialchars(build_add_url('attraction', $a['attraction_id'], $destination_id, $existing_itinerary_id)); ?>"
                               class="btn btn-primary btn-sm">
                                Add to Day
                            </a>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php endif; ?>
        </div>

        <!-- Events -->
        <div class="col-md-6">
            <h4 class="mb-3">Events</h4>

            <?php if ($eventRes->num_rows == 0): ?>
                <p class="text-muted">No events listed.</p>
            <?php else: ?>
                <?php while ($e = $eventRes->fetch_assoc()): ?>
                    <div class="card mb-3 shadow-sm">
                        <div class="card-body">
                            <h5 class="card-title mb-1"><?php echo htmlspecialchars($e['name']); ?></h5>
                            <div class="small text-muted mb-1">
                                <?php echo htmlspecialchars($e['description'] ?? ''); ?>
                            </div>
                            <div class="small mb-2">
                                Dates:
                                <?php echo htmlspecialchars($e['start_date'] ?? '–'); ?> &ndash;
                                <?php echo htmlspecialchars($e['end_date'] ?? '–'); ?> &bull;
                                Price: $<?php echo htmlspecialchars((string) $e['price']); ?>
                            </div>
                            <a href="<?php echo htmlspecialchars(build_add_url('event', $e['event_id'], $destination_id, $existing_itinerary_id)); ?>"
                               class="btn btn-primary btn-sm">
                                Add to Day
                            </a>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php endif; ?>
        </div>
    </div>

    <a href="destinations.php" class="btn btn-outline-primary mt-4">Back to Destinations</a>
</div>

<?php
$stmt_att->close();
$stmt_evt->close();
include("footer.php");
?>

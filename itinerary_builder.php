<?php
session_start();
include("config.php");
include("header.php");

if (!isset($_SESSION['email'])) {
    header("Location: login.php");
    exit;
}

$email = $_SESSION['email'];

/* 1. Get traveler (FIX 1A) */
$stmt = $conn->prepare("SELECT traveler_id FROM traveler WHERE email = ? LIMIT 1");
$stmt->bind_param("s", $email);
$stmt->execute();
$travRes = $stmt->get_result();
$trav = $travRes ? $travRes->fetch_assoc() : null;
$stmt->close();

if (!$trav) {
    echo "<div class='page-wrapper text-center'><h3 class='text-danger'>Traveler not found.</h3></div>";
    include("footer.php");
    exit;
}

$traveler_id = (int) $trav['traveler_id'];

/* Helper: load an itinerary (with city/country) for this traveler.
   Returns null if not found or not owned. (FIX 1A + FIX 2D ownership check) */
function load_itinerary_for_user(mysqli $conn, int $itinerary_id, int $traveler_id) {
    $stmt = $conn->prepare(
        "SELECT i.*, d.city, d.country
         FROM itinerary i
         JOIN destination d ON d.destination_id = i.destination_id
         WHERE i.itinerary_id = ? AND i.traveler_id = ? AND i.deleted_at IS NULL
         LIMIT 1"
    );
    $stmt->bind_param("ii", $itinerary_id, $traveler_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row;
}

/* Helper: most recent itinerary for traveler (legacy fallback). */
function load_latest_itinerary_for_user(mysqli $conn, int $traveler_id) {
    $stmt = $conn->prepare(
        "SELECT i.*, d.city, d.country
         FROM itinerary i
         JOIN destination d ON d.destination_id = i.destination_id
         WHERE i.traveler_id = ? AND i.deleted_at IS NULL
         ORDER BY i.itinerary_id DESC
         LIMIT 1"
    );
    $stmt->bind_param("i", $traveler_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row;
}

/* Helper: most recent itinerary for (traveler, destination) — FIX 2A reuse */
function load_itinerary_for_destination(mysqli $conn, int $traveler_id, int $destination_id) {
    $stmt = $conn->prepare(
        "SELECT i.*, d.city, d.country
         FROM itinerary i
         JOIN destination d ON d.destination_id = i.destination_id
         WHERE i.traveler_id = ? AND i.destination_id = ? AND i.deleted_at IS NULL
         ORDER BY i.itinerary_id DESC
         LIMIT 1"
    );
    $stmt->bind_param("ii", $traveler_id, $destination_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row;
}

/* 2. Resolve which itinerary to use (FIX 2D + FIX 2A) */
$req_itinerary_id   = isset($_GET['itinerary_id'])   ? intval($_GET['itinerary_id'])   : 0;
$req_destination_id = isset($_GET['destination_id']) ? intval($_GET['destination_id']) : 0;
$is_adding_activity = isset($_GET['type']) && isset($_GET['id']);

/* Allow itinerary_id to come via POST too (for accommodation / new day forms) */
if (!$req_itinerary_id && isset($_POST['itinerary_id'])) {
    $req_itinerary_id = intval($_POST['itinerary_id']);
}

$itinerary = null;

if ($req_itinerary_id > 0) {
    /* FIX 2D: explicit itinerary_id, validate ownership */
    $itinerary = load_itinerary_for_user($conn, $req_itinerary_id, $traveler_id);
    if (!$itinerary) {
        echo "<div class='page-wrapper text-center'>
                <h3 class='text-danger'>Invalid itinerary.</h3>
                <a href='trips.php' class='btn btn-outline-primary mt-3'>Back to My Trips</a>
              </div>";
        include("footer.php");
        exit;
    }
} elseif ($req_destination_id > 0) {
    /* No itinerary_id but a destination_id was passed (came from attractions.php). */
    $itinerary = load_itinerary_for_destination($conn, $traveler_id, $req_destination_id);

    if (!$itinerary && $is_adding_activity) {
        /* FIX 2A: only NOW (when actually adding an activity) do we create
           a new itinerary row. Browsing alone never creates ghost rows. */
        $stmt = $conn->prepare(
            "INSERT INTO itinerary (traveler_id, destination_id, created_at)
             VALUES (?, ?, NOW())"
        );
        $stmt->bind_param("ii", $traveler_id, $req_destination_id);
        $stmt->execute();
        $new_id = $stmt->insert_id;
        $stmt->close();

        $itinerary = load_itinerary_for_user($conn, $new_id, $traveler_id);
    }

    if (!$itinerary) {
        /* User is just browsing with destination_id but never added anything;
           fall through to "no active itinerary" message. */
    }
}

/* Last-resort legacy fallback: most-recent itinerary for this user */
if (!$itinerary) {
    $itinerary = load_latest_itinerary_for_user($conn, $traveler_id);
}

if (!$itinerary) {
    echo "<div class='page-wrapper text-center'>
            <h3 class='fw-bold mb-2'>No active itinerary</h3>
            <p class='text-muted mb-3'>Start by choosing your preferences and destination.</p>
            <a href='preferences.php' class='btn btn-primary'>Start a New Trip</a>
          </div>";
    include("footer.php");
    exit;
}

$itinerary_id   = (int) $itinerary['itinerary_id'];
$destination_id = (int) $itinerary['destination_id'];

/* Helper: get or create a day (FIX 1A) */
function get_or_create_day(mysqli $conn, int $itinerary_id, int $day_number): array {
    $stmt = $conn->prepare(
        "SELECT * FROM itinerary_day
         WHERE itinerary_id = ? AND day_number = ?
         LIMIT 1"
    );
    $stmt->bind_param("ii", $itinerary_id, $day_number);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $row = $res->fetch_assoc()) {
        $stmt->close();
        return $row;
    }
    $stmt->close();

    $ins = $conn->prepare(
        "INSERT INTO itinerary_day (itinerary_id, day_number) VALUES (?, ?)"
    );
    $ins->bind_param("ii", $itinerary_id, $day_number);
    $ins->execute();
    $id = $ins->insert_id;
    $ins->close();

    return [
        'day_id'           => $id,
        'itinerary_id'     => $itinerary_id,
        'day_number'       => $day_number,
        'accommodation_id' => null,
        'notes'            => ''
    ];
}

/* 3. Current day handling — keyed per itinerary so multi-trip users don't collide */
if (!isset($_SESSION['current_day_per_itinerary'])) {
    $_SESSION['current_day_per_itinerary'] = [];
}
if (!isset($_SESSION['current_day_per_itinerary'][$itinerary_id])) {
    $_SESSION['current_day_per_itinerary'][$itinerary_id] = 1;
}

if (isset($_GET['set_day'])) {
    $day_req = max(1, intval($_GET['set_day']));
    $_SESSION['current_day_per_itinerary'][$itinerary_id] = $day_req;
}

$current_day_number = (int) $_SESSION['current_day_per_itinerary'][$itinerary_id];

/* 4. Add New Day (POST) — CSRF protected (FIX 1C) */
if (isset($_POST['new_day'])) {
    verify_csrf_token();

    $stmt = $conn->prepare(
        "SELECT MAX(day_number) AS max_day FROM itinerary_day WHERE itinerary_id = ?"
    );
    $stmt->bind_param("i", $itinerary_id);
    $stmt->execute();
    $maxRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $next = ($maxRow && $maxRow['max_day']) ? ((int) $maxRow['max_day']) + 1 : 1;

    $ins = $conn->prepare(
        "INSERT INTO itinerary_day (itinerary_id, day_number) VALUES (?, ?)"
    );
    $ins->bind_param("ii", $itinerary_id, $next);
    $ins->execute();
    $ins->close();

    $_SESSION['current_day_per_itinerary'][$itinerary_id] = $next;
    header("Location: itinerary_builder.php?itinerary_id=" . $itinerary_id);
    exit;
}

/* 4b. Remove Day (POST) — CSRF protected. Refuses to delete the last
   remaining day so the itinerary is never left with zero days. */
if (isset($_POST['remove_day'])) {
    verify_csrf_token();

    $day_to_remove = max(1, intval($_POST['day_number_to_remove'] ?? 0));

    // How many days does this itinerary have?
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS c FROM itinerary_day WHERE itinerary_id = ?"
    );
    $stmt->bind_param("i", $itinerary_id);
    $stmt->execute();
    $cnt = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();

    if ($cnt > 1 && $day_to_remove > 0) {
        // Resolve day_id (also re-confirms the day belongs to this itinerary)
        $stmt = $conn->prepare(
            "SELECT day_id FROM itinerary_day
             WHERE itinerary_id = ? AND day_number = ? LIMIT 1"
        );
        $stmt->bind_param("ii", $itinerary_id, $day_to_remove);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            $day_id_to_remove = (int) $row['day_id'];

            // Explicit activity cleanup (works even on schemas without ON DELETE CASCADE)
            $del = $conn->prepare("DELETE FROM itinerary_activity WHERE day_id = ?");
            $del->bind_param("i", $day_id_to_remove);
            $del->execute();
            $del->close();

            $del = $conn->prepare("DELETE FROM itinerary_day WHERE day_id = ?");
            $del->bind_param("i", $day_id_to_remove);
            $del->execute();
            $del->close();

            /* RENUMBER remaining days so they stay 1..N (no gaps).
               Otherwise deleting Day 1 of [1,2,3] would leave the
               user staring at Day 2 and Day 3, which looks broken. */
            $sel = $conn->prepare(
                "SELECT day_id FROM itinerary_day
                 WHERE itinerary_id = ? ORDER BY day_number ASC"
            );
            $sel->bind_param("i", $itinerary_id);
            $sel->execute();
            $remaining = $sel->get_result()->fetch_all(MYSQLI_ASSOC);
            $sel->close();

            $upd = $conn->prepare("UPDATE itinerary_day SET day_number = ? WHERE day_id = ?");
            $new_num = 1;
            foreach ($remaining as $rd) {
                $did = (int) $rd['day_id'];
                $upd->bind_param("ii", $new_num, $did);
                $upd->execute();
                $new_num++;
            }
            $upd->close();

            // After renumbering, just snap the user to Day 1.
            $_SESSION['current_day_per_itinerary'][$itinerary_id] = 1;

            $_SESSION['flash_message'] = "Day {$day_to_remove} removed. Days renumbered.";
        }
    } else {
        $_SESSION['flash_message'] = "You can't remove the only remaining day.";
    }

    header("Location: itinerary_builder.php?itinerary_id=" . $itinerary_id);
    exit;
}

/* 4c. Save trip details (start_date + budget) — CSRF protected */
if (isset($_POST['save_trip_details'])) {
    verify_csrf_token();

    $start_raw = trim($_POST['start_date'] ?? '');
    $start_db  = ($start_raw !== '') ? $start_raw : null;   // "" → NULL
    $budget    = max(0, (float) ($_POST['budget'] ?? 0));

    // Validate date shape (YYYY-MM-DD) loosely; bad input → keep null
    if ($start_db !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_db)) {
        $start_db = null;
    }

    $upd = $conn->prepare(
        "UPDATE itinerary SET start_date = ?, budget = ? WHERE itinerary_id = ?"
    );
    $upd->bind_param("sdi", $start_db, $budget, $itinerary_id);
    $upd->execute();
    $upd->close();

    // Refresh in-memory copy
    $itinerary['start_date'] = $start_db;
    $itinerary['budget']     = $budget;

    $_SESSION['flash_message'] = "Trip details saved.";
    header("Location: itinerary_builder.php?itinerary_id=" . $itinerary_id);
    exit;
}

/* 5. Ensure at least Day 1 exists */
$stmt = $conn->prepare(
    "SELECT COUNT(*) AS c FROM itinerary_day WHERE itinerary_id = ?"
);
$stmt->bind_param("i", $itinerary_id);
$stmt->execute();
$cntRow = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$cntRow || (int) $cntRow['c'] === 0) {
    $ins = $conn->prepare(
        "INSERT INTO itinerary_day (itinerary_id, day_number) VALUES (?, 1)"
    );
    $ins->bind_param("i", $itinerary_id);
    $ins->execute();
    $ins->close();
    $_SESSION['current_day_per_itinerary'][$itinerary_id] = 1;
    $current_day_number = 1;
}

/* 6. Get row for current day */
$currentDayRow = get_or_create_day($conn, $itinerary_id, $current_day_number);
$current_day_id = (int) $currentDayRow['day_id'];

/* 7. Set accommodation per day (POST) — CSRF protected (FIX 1C) */
$info_message = "";
if (isset($_SESSION['flash_message'])) {
    $info_message = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
}

if (isset($_POST['set_accommodation'])) {
    verify_csrf_token();

    $acc_id = intval($_POST['accommodation_id']);
    $upd = $conn->prepare(
        "UPDATE itinerary_day SET accommodation_id = ? WHERE day_id = ?"
    );
    $upd->bind_param("ii", $acc_id, $current_day_id);
    $upd->execute();
    $upd->close();

    $info_message = "Accommodation updated for Day {$current_day_number}.";
}

/* 8. Handle 'Add to Day' from attractions.php (GET) — FIX 1A.
   Use Post-Redirect-Get pattern so a refresh doesn't re-add. */
if ($is_adding_activity) {
    $type_raw      = $_GET['type'];
    $activity_type = ($type_raw === 'event') ? 'event' : 'attraction';
    $activity_id   = intval($_GET['id']);

    $ins = $conn->prepare(
        "INSERT INTO itinerary_activity (day_id, activity_type, activity_id)
         VALUES (?, ?, ?)"
    );
    $ins->bind_param("isi", $current_day_id, $activity_type, $activity_id);
    $ins->execute();
    $ins->close();

    $_SESSION['flash_message'] = "Activity added to Day {$current_day_number}.";
    header("Location: itinerary_builder.php?itinerary_id=" . $itinerary_id);
    exit;
}

/* 9. Fetch all days (for tabs) */
$stmt = $conn->prepare(
    "SELECT * FROM itinerary_day WHERE itinerary_id = ? ORDER BY day_number"
);
$stmt->bind_param("i", $itinerary_id);
$stmt->execute();
$daysRes = $stmt->get_result();
$days = [];
while ($row = $daysRes->fetch_assoc()) {
    $days[] = $row;
}
$stmt->close();

/* 10. Fetch activities for current day (FIX 1A) */
$stmt = $conn->prepare(
    "SELECT ia.*,
            a.name      AS attraction_name,
            a.entry_fee AS attraction_price,
            e.name      AS event_name,
            e.price     AS event_price
     FROM itinerary_activity ia
     LEFT JOIN attraction a
       ON ia.activity_type = 'attraction' AND ia.activity_id = a.attraction_id
     LEFT JOIN event e
       ON ia.activity_type = 'event'      AND ia.activity_id = e.event_id
     WHERE ia.day_id = ?
     ORDER BY ia.itinerary_activity_id"
);
$stmt->bind_param("i", $current_day_id);
$stmt->execute();
$actRes = $stmt->get_result();
$activities = [];
while ($row = $actRes->fetch_assoc()) {
    $activities[] = $row;
}
$stmt->close();

/* 11. Accommodation list for this destination (FIX 1A) */
$stmt = $conn->prepare("SELECT * FROM accommodation WHERE destination_id = ?");
$stmt->bind_param("i", $destination_id);
$stmt->execute();
$accListRes = $stmt->get_result();
$accommodations = [];
while ($row = $accListRes->fetch_assoc()) {
    $accommodations[] = $row;
}
$stmt->close();

/* Current day's accommodation */
$current_acc = null;
if (!empty($currentDayRow['accommodation_id'])) {
    $aid = (int) $currentDayRow['accommodation_id'];
    $stmt = $conn->prepare("SELECT * FROM accommodation WHERE accommodation_id = ?");
    $stmt->bind_param("i", $aid);
    $stmt->execute();
    $accRes = $stmt->get_result();
    $current_acc = $accRes ? $accRes->fetch_assoc() : null;
    $stmt->close();
}
?>

<div class="page-wrapper">

    <!-- STEP PROGRESS TRACKER -->
    <div class="steps-wrapper my-3">
        <div class="step">Preferences</div>
        <div class="step">Destinations</div>
        <div class="step">Activities</div>
        <div class="step active">Itinerary</div>
    </div>

    <h2 class="page-title mb-3">Itinerary Builder</h2>
    <p class="page-subtitle mb-4">
        Organize your trip day by day. Choose accommodation for each day,
        add attractions and events, then view your full itinerary.
    </p>

    <div class="mb-3">
        <span class="badge bg-light text-dark border">
            Destination:
            <strong><?php echo htmlspecialchars($itinerary['city'] . " (" . $itinerary['country'] . ")"); ?></strong>
        </span>
    </div>

    <!-- TRIP DETAILS (start date + budget) -->
    <?php
        $cur_start  = $itinerary['start_date'] ?? null;
        $cur_budget = (float) ($itinerary['budget'] ?? 0);
    ?>
    <div class="card p-3 mb-3 border-0 shadow-sm">
        <form method="post" class="row g-2 align-items-end">
            <input type="hidden" name="csrf_token"   value="<?php echo generate_csrf_token(); ?>">
            <input type="hidden" name="itinerary_id" value="<?php echo $itinerary_id; ?>">

            <div class="col-md-4">
                <label class="form-label mb-1 small text-muted">Trip start date</label>
                <input type="date" name="start_date" class="form-control"
                       value="<?php echo htmlspecialchars($cur_start ?? ''); ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label mb-1 small text-muted">Budget (USD)</label>
                <input type="number" name="budget" class="form-control" min="0" step="1"
                       value="<?php echo htmlspecialchars(number_format($cur_budget, 2, '.', '')); ?>">
            </div>
            <div class="col-md-4">
                <button type="submit" name="save_trip_details" class="btn btn-outline-primary w-100">
                    Save trip details
                </button>
            </div>
        </form>
    </div>

    <!-- Helper: format Day N (Mon, Apr 27) when start_date is set -->
    <?php
    function fmt_day_label($day_number, $start_date) {
        $base = "Day " . (int) $day_number;
        if ($start_date) {
            $ts = strtotime($start_date . " +" . ((int)$day_number - 1) . " day");
            if ($ts) {
                return $base . " (" . date("D, M j", $ts) . ")";
            }
        }
        return $base;
    }
    ?>

    <!-- Day Tabs (FIX 2D: itinerary_id is preserved in every link) -->
    <div class="d-flex align-items-center mb-3 flex-wrap">
        <div class="me-3 mb-2">
            <?php foreach ($days as $day): ?>
                <?php $isActive = ($day['day_number'] == $current_day_number); ?>
                <a href="itinerary_builder.php?itinerary_id=<?php echo $itinerary_id; ?>&set_day=<?php echo (int) $day['day_number']; ?>"
                   class="btn btn-sm <?php echo $isActive ? 'btn-primary' : 'btn-outline-primary'; ?> me-1 mb-1">
                    <?php echo htmlspecialchars(fmt_day_label($day['day_number'], $cur_start)); ?>
                </a>
            <?php endforeach; ?>
        </div>
        <form method="post" class="mb-2 me-1">
            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
            <input type="hidden" name="itinerary_id" value="<?php echo $itinerary_id; ?>">
            <button type="submit" name="new_day" class="btn btn-sm btn-outline-secondary">
                + Add New Day
            </button>
        </form>

        <?php if (count($days) > 1): ?>
            <!-- Remove the currently-active day (CSRF protected, only shown when >1 day exists) -->
            <form method="post" class="mb-2"
                  onsubmit="return confirm('Remove Day <?php echo (int) $current_day_number; ?> and all its activities? This cannot be undone.');">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="itinerary_id" value="<?php echo $itinerary_id; ?>">
                <input type="hidden" name="day_number_to_remove" value="<?php echo (int) $current_day_number; ?>">
                <button type="submit" name="remove_day" class="btn btn-sm btn-outline-danger">
                    &times; Remove Day <?php echo (int) $current_day_number; ?>
                </button>
            </form>
        <?php endif; ?>
    </div>

    <!-- Info message -->
    <?php if ($info_message !== ""): ?>
        <div class="alert alert-success py-2"><?php echo htmlspecialchars($info_message); ?></div>
    <?php endif; ?>

    <!-- Accommodation selector -->
    <div class="mb-4">
        <h5>Accommodation for <?php echo htmlspecialchars(fmt_day_label($current_day_number, $cur_start)); ?></h5>

        <?php if (count($accommodations) === 0): ?>
            <p class="text-muted">No accommodation options configured for this destination.</p>
        <?php else: ?>
            <form method="post" class="row g-2 align-items-center">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="itinerary_id" value="<?php echo $itinerary_id; ?>">
                <div class="col-md-6">
                    <select name="accommodation_id" class="form-select" required>
                        <option value="">Select accommodation</option>
                        <?php foreach ($accommodations as $acc): ?>
                            <option value="<?php echo (int) $acc['accommodation_id']; ?>"
                                <?php if ($current_acc && $current_acc['accommodation_id'] == $acc['accommodation_id']) echo "selected"; ?>>
                                <?php echo htmlspecialchars($acc['name']); ?>
                                ($<?php echo htmlspecialchars((string) $acc['cost_per_night']); ?>/night)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" name="set_accommodation" class="btn btn-outline-primary w-100">
                        Save for this Day
                    </button>
                </div>
                <div class="col-md-3">
                    <?php if ($current_acc): ?>
                        <span class="small text-muted">
                            Current: <?php echo htmlspecialchars($current_acc['name']); ?>
                        </span>
                    <?php else: ?>
                        <span class="small text-muted">No accommodation selected yet.</span>
                    <?php endif; ?>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <!-- Activities table for current day -->
    <h5 class="mb-2">Activities for <?php echo htmlspecialchars(fmt_day_label($current_day_number, $cur_start)); ?></h5>

    <?php if (count($activities) === 0): ?>
        <p class="text-muted">
            No activities added yet for this day.
            Go back to <a href="destinations.php">Destinations</a> &rarr; Attractions to add more.
        </p>
    <?php else: ?>
        <table class="table table-bordered bg-white align-middle">
            <thead class="table-light">
                <tr>
                    <th style="width: 120px;">Type</th>
                    <th>Name</th>
                    <th style="width: 120px;">Price</th>
                    <th style="width: 120px;">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($activities as $row): ?>
                <?php
                if ($row['activity_type'] === 'attraction') {
                    $name      = $row['attraction_name'];
                    $price     = $row['attraction_price'];
                    $typeLabel = 'Attraction';
                } else {
                    $name      = $row['event_name'];
                    $price     = $row['event_price'];
                    $typeLabel = 'Event';
                }
                ?>
                <tr>
                    <td><?php echo $typeLabel; ?></td>
                    <td><?php echo htmlspecialchars($name ?? ''); ?></td>
                    <td><?php echo $price !== null ? '$' . number_format($price, 2) : '-'; ?></td>
                    <td>
                        <!-- FIX 2E: remove activity (POST + CSRF, ownership re-checked server-side) -->
                        <form method="post" action="remove_activity.php"
                              onsubmit="return confirm('Remove this activity from the day?');"
                              style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                            <input type="hidden" name="itinerary_activity_id"
                                   value="<?php echo (int) $row['itinerary_activity_id']; ?>">
                            <input type="hidden" name="itinerary_id" value="<?php echo $itinerary_id; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">Remove</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div class="mt-4 text-center">
        <!-- Goes back to the attractions page for THIS itinerary's destination
             (not the generic destinations grid) -->
        <a href="attractions.php?destination_id=<?php echo (int) $destination_id; ?>"
           class="action-btn outline me-2">
           Back to Attractions
        </a>
        <a href="summary.php?itinerary_id=<?php echo (int) $itinerary_id; ?>"
           class="action-btn primary">
           View Full Itinerary
        </a>
    </div>
</div>

<?php include("footer.php"); ?>

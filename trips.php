<?php
session_start();
include("config.php");
include("header.php");

if (!isset($_SESSION['email'])) {
    header("Location: login.php");
    exit;
}

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

/* Tab: ?view=trash to see soft-deleted trips, otherwise active ones. */
$view = (isset($_GET['view']) && $_GET['view'] === 'trash') ? 'trash' : 'active';

/* Always assign per-user sequential numbers based on creation order
   across BOTH active and deleted trips, so #1 means "your first ever
   trip" no matter which tab you're looking at. */
$stmt = $conn->prepare(
    "SELECT i.*, d.city, d.country
     FROM itinerary i
     JOIN destination d ON d.destination_id = i.destination_id
     WHERE i.traveler_id = ?
     ORDER BY i.itinerary_id ASC"
);
$stmt->bind_param("i", $traveler_id);
$stmt->execute();
$res = $stmt->get_result();
$all = [];
$n = 1;
while ($row = $res->fetch_assoc()) {
    $row['user_trip_number'] = $n++;
    $all[] = $row;
}
$stmt->close();

/* Counts for the tab labels */
$active_count = 0;
$trash_count  = 0;
foreach ($all as $row) {
    if (!empty($row['deleted_at'])) { $trash_count++; } else { $active_count++; }
}

/* Filter for the current view, newest first for display */
$trips = array_values(array_filter($all, function ($r) use ($view) {
    return $view === 'trash' ? !empty($r['deleted_at']) : empty($r['deleted_at']);
}));
$trips = array_reverse($trips);
?>

<div class="page-wrapper">
<?php if (isset($_GET['deleted'])): ?>
    <div class="alert alert-success">Trip moved to Trash. You can restore it from the Trash tab.</div>
<?php endif; ?>
<?php if (isset($_GET['restored'])): ?>
    <div class="alert alert-success">Trip restored.</div>
<?php endif; ?>

    <h2 class="page-title mb-2">My Trips</h2>
    <p class="page-subtitle mb-4">
        View all your itineraries or open a specific trip.
    </p>

    <!-- Tabs: Active / Trash -->
    <ul class="nav nav-pills mb-3 justify-content-center">
        <li class="nav-item me-2">
            <a class="nav-link <?php echo $view === 'active' ? 'active' : ''; ?>"
               href="trips.php" style="border-radius: 999px;">
                Active <span class="badge bg-light text-dark ms-1"><?php echo $active_count; ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $view === 'trash' ? 'active' : ''; ?>"
               href="trips.php?view=trash" style="border-radius: 999px;">
                Trash <span class="badge bg-light text-dark ms-1"><?php echo $trash_count; ?></span>
            </a>
        </li>
    </ul>

    <?php if (count($trips) === 0): ?>
        <div class="text-center">
            <h4 class="fw-bold">
                <?php echo $view === 'trash' ? 'Trash is empty.' : 'No trips found'; ?>
            </h4>
            <?php if ($view !== 'trash'): ?>
                <p>You haven&rsquo;t created any itineraries yet.</p>
                <a href="preferences.php" class="btn btn-primary">Start Planning</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <table class="table table-bordered table-hover bg-white shadow-sm"
               style="border-radius:12px; overflow:hidden;">
            <thead class="table-light">
                <tr>
                    <th style="width:90px;">Trip</th>
                    <th>Destination</th>
                    <th>Trip start</th>
                    <th>Budget</th>
                    <th>Created</th>
                    <th style="width:280px;">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($trips as $trip): ?>
                <tr>
                    <td class="text-center fw-bold">Trip #<?php echo (int) $trip['user_trip_number']; ?></td>
                    <td><?php echo htmlspecialchars($trip['city'] . " (" . $trip['country'] . ")"); ?></td>
                    <td>
                        <?php echo $trip['start_date']
                            ? date("M d, Y", strtotime($trip['start_date']))
                            : '<span class="text-muted">&mdash;</span>'; ?>
                    </td>
                    <td>
                        <?php echo ((float) $trip['budget']) > 0
                            ? '$' . number_format($trip['budget'], 0)
                            : '<span class="text-muted">&mdash;</span>'; ?>
                    </td>
                    <td><?php echo date("M d, Y", strtotime($trip['created_at'])); ?></td>
                    <td class="text-center">
                        <?php if ($view === 'trash'): ?>
                            <!-- Restore -->
                            <form method="POST" action="delete_itinerary.php" style="display:inline;">
                                <input type="hidden" name="csrf_token"   value="<?php echo generate_csrf_token(); ?>">
                                <input type="hidden" name="itinerary_id" value="<?php echo (int) $trip['itinerary_id']; ?>">
                                <input type="hidden" name="action"       value="restore">
                                <button type="submit" class="btn btn-outline-primary btn-sm px-3">Restore</button>
                            </form>
                        <?php else: ?>
                            <a href="summary.php?itinerary_id=<?php echo (int) $trip['itinerary_id']; ?>"
                               class="btn btn-primary btn-sm px-2 me-1">View</a>
                            <a href="itinerary_builder.php?itinerary_id=<?php echo (int) $trip['itinerary_id']; ?>"
                               class="btn btn-outline-primary btn-sm px-2 me-1">Edit</a>
                            <form method="POST" action="delete_itinerary.php"
                                  style="display:inline;"
                                  onsubmit="return confirm('Move this trip to Trash? You can restore it later.');">
                                <input type="hidden" name="csrf_token"   value="<?php echo generate_csrf_token(); ?>">
                                <input type="hidden" name="itinerary_id" value="<?php echo (int) $trip['itinerary_id']; ?>">
                                <button type="submit" class="btn btn-outline-danger btn-sm px-2">Delete</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <a href="dashboard.php" class="btn btn-outline-primary mt-3">Back to Dashboard</a>
</div>

<?php include("footer.php"); ?>

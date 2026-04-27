<?php
session_start();
include("config.php");
include("header.php");

if (!isset($_SESSION['email'])) {
    header("Location: login.php");
    exit;
}

$email = $_SESSION['email'];

/* Traveler (FIX 1A) */
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

/* 1. Determine itinerary (from GET or latest) (FIX 1A) */
if (isset($_GET['itinerary_id'])) {
    $itinerary_id = intval($_GET['itinerary_id']);

    $stmt = $conn->prepare(
        "SELECT i.*, d.city, d.country
         FROM itinerary i
         JOIN destination d ON d.destination_id = i.destination_id
         WHERE i.itinerary_id = ? AND i.traveler_id = ? AND i.deleted_at IS NULL
         LIMIT 1"
    );
    $stmt->bind_param("ii", $itinerary_id, $traveler_id);
    $stmt->execute();
    $check = $stmt->get_result();
    $itinerary = $check ? $check->fetch_assoc() : null;
    $stmt->close();

    if (!$itinerary) {
        echo "<div class='page-wrapper text-center'>
                <h3 class='text-danger'>Invalid itinerary.</h3>
                <a href='trips.php' class='btn btn-outline-primary mt-3'>Back to My Trips</a>
              </div>";
        include("footer.php");
        exit;
    }
} else {
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
    $itinerary = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$itinerary) {
        header("Location: trips.php");
        exit;
    }
    $itinerary_id = (int) $itinerary['itinerary_id'];
}

/* SHARE LINK: mint a token on demand. */
if (isset($_POST['create_share_link'])) {
    verify_csrf_token();
    if (empty($itinerary['share_token'])) {
        $token = bin2hex(random_bytes(16)); // 32 hex chars
        $upd = $conn->prepare("UPDATE itinerary SET share_token = ? WHERE itinerary_id = ?");
        $upd->bind_param("si", $token, $itinerary_id);
        $upd->execute();
        $upd->close();
        $itinerary['share_token'] = $token;
    }
}

if (isset($_POST['revoke_share_link'])) {
    verify_csrf_token();
    $upd = $conn->prepare("UPDATE itinerary SET share_token = NULL WHERE itinerary_id = ?");
    $upd->bind_param("i", $itinerary_id);
    $upd->execute();
    $upd->close();
    $itinerary['share_token'] = null;
}

/* 2. Fetch itinerary days (FIX 1A) */
$stmt = $conn->prepare(
    "SELECT * FROM itinerary_day WHERE itinerary_id = ? ORDER BY day_number"
);
$stmt->bind_param("i", $itinerary_id);
$stmt->execute();
$daysResult = $stmt->get_result();
$days = [];
while ($row = $daysResult->fetch_assoc()) {
    $days[] = $row;
}
$stmt->close();

/* Pre-fetch helpers (FIX 1A) */
$accStmt = $conn->prepare("SELECT * FROM accommodation WHERE accommodation_id = ?");
$actStmt = $conn->prepare(
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
?>

<div class="page-wrapper">

    <h2 class="page-title mb-2">Your Full Itinerary</h2>
    <p class="page-subtitle mb-2">
        Destination: <?php echo htmlspecialchars($itinerary['city'] . " (" . $itinerary['country'] . ")"); ?>
        <?php if (!empty($itinerary['start_date'])): ?>
            &nbsp;&bull;&nbsp; Starts:
            <strong><?php echo date("D, M j, Y", strtotime($itinerary['start_date'])); ?></strong>
        <?php endif; ?>
    </p>

    <table class="table table-bordered table-summary align-middle bg-white">
        <thead>
        <tr>
            <th>Day</th>
            <th>Destination</th>
            <th>Accommodation</th>
            <th>Activities</th>
            <th>Notes</th>
            <th>Total Cost (Day)</th>
        </tr>
        </thead>
        <tbody>
        <?php
        $total_trip_cost = 0;

        foreach ($days as $day) {

            $activities_html = "<ul class='mb-0'>";
            $day_cost        = 0;

            // Accommodation
            $acc_display = "&mdash;";
            if (!empty($day['accommodation_id'])) {
                $aid = (int) $day['accommodation_id'];
                $accStmt->bind_param("i", $aid);
                $accStmt->execute();
                $accRes = $accStmt->get_result();
                $acc = $accRes ? $accRes->fetch_assoc() : null;

                if ($acc) {
                    $acc_display = htmlspecialchars($acc['name']) .
                                   "<br>$" . htmlspecialchars((string) $acc['cost_per_night']) . "/night";
                    $day_cost += (float) $acc['cost_per_night'];
                }
            }

            // Activities
            $did = (int) $day['day_id'];
            $actStmt->bind_param("i", $did);
            $actStmt->execute();
            $act_res = $actStmt->get_result();

            while ($act = $act_res->fetch_assoc()) {
                if ($act['activity_type'] == 'attraction') {
                    $name  = $act['attraction_name'];
                    $price = $act['attraction_price'];
                    $activities_html .= "<li>" . htmlspecialchars($name ?? '') .
                                        " (Attraction) - $" . htmlspecialchars((string) $price) . "</li>";
                    $day_cost += (float) $price;
                } else {
                    $name  = $act['event_name'];
                    $price = $act['event_price'];
                    $label = "Event";
                    if ($price !== null && $price > 0) {
                        $activities_html .= "<li>" . htmlspecialchars($name ?? '') .
                                            " ($label) - $" . htmlspecialchars((string) $price) . "</li>";
                        $day_cost += (float) $price;
                    } else {
                        $activities_html .= "<li>" . htmlspecialchars($name ?? '') . " ($label)</li>";
                    }
                }
            }
            $activities_html .= "</ul>";

            $total_trip_cost += $day_cost;

            echo "<tr>
                    <td>Day " . (int) $day['day_number'] . "</td>
                    <td>" . htmlspecialchars($itinerary['city']) .
                    " (" . htmlspecialchars($itinerary['country']) . ")</td>
                    <td>" . $acc_display . "</td>
                    <td>" . $activities_html . "</td>
                    <td>" . htmlspecialchars($day['notes'] ?? '') . "</td>
                    <td>$" . number_format($day_cost, 2) . "</td>
                  </tr>";
        }
        ?>
        <tr class="total-row">
            <td colspan="5" class="text-end">TOTAL TRIP COST</td>
            <td><?php echo "$" . number_format($total_trip_cost, 2); ?></td>
        </tr>
        </tbody>
    </table>

    <!-- BUDGET vs SPENT -->
    <?php
        $budget = (float) ($itinerary['budget'] ?? 0);
        if ($budget > 0):
            $pct = min(100, round(($total_trip_cost / $budget) * 100));
            if ($pct < 75)        { $bar_class = 'bg-success'; }
            elseif ($pct < 100)   { $bar_class = 'bg-warning'; }
            else                  { $bar_class = 'bg-danger';  }
    ?>
    <div class="my-4">
        <div class="d-flex justify-content-between mb-1">
            <span class="fw-semibold">Budget</span>
            <span>
                $<?php echo number_format($total_trip_cost, 2); ?>
                of $<?php echo number_format($budget, 2); ?>
                (<?php echo $pct; ?>%)
            </span>
        </div>
        <div class="progress" role="progressbar" aria-valuenow="<?php echo $pct; ?>"
             aria-valuemin="0" aria-valuemax="100" style="height: 14px; border-radius: 999px;">
            <div class="progress-bar <?php echo $bar_class; ?>"
                 style="width: <?php echo $pct; ?>%"></div>
        </div>
        <?php if ($pct >= 100): ?>
            <div class="small text-danger mt-1">
                You're over budget by $<?php echo number_format($total_trip_cost - $budget, 2); ?>.
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

<!-- SHARE LINK -->
<div class="card p-3 mb-3 border-0 shadow-sm">
    <?php if (!empty($itinerary['share_token'])):
        $share_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://')
                   . $_SERVER['HTTP_HOST']
                   . dirname($_SERVER['PHP_SELF'])
                   . '/view_shared.php?token=' . htmlspecialchars($itinerary['share_token']);
    ?>
        <label class="form-label fw-semibold mb-1">Share this trip (read-only)</label>
        <div class="input-group">
            <input id="shareUrl" type="text" class="form-control"
                   value="<?php echo $share_url; ?>" readonly>
            <button type="button" class="btn btn-outline-primary"
                    onclick="navigator.clipboard.writeText(document.getElementById('shareUrl').value); this.innerText='Copied!';">
                Copy
            </button>
            <form method="POST" style="display:inline;"
                  onsubmit="return confirm('Revoke this share link?');">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="itinerary_id" value="<?php echo (int) $itinerary_id; ?>">
                <button type="submit" name="revoke_share_link" class="btn btn-outline-danger ms-2">
                    Revoke
                </button>
            </form>
        </div>
        <div class="small text-muted mt-1">
            Anyone with this link can view (but not edit) your itinerary.
        </div>
    <?php else: ?>
        <form method="POST" class="d-flex align-items-center justify-content-between">
            <div>
                <strong>Share this trip</strong>
                <div class="small text-muted">Generate a public read-only link.</div>
            </div>
            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
            <input type="hidden" name="itinerary_id" value="<?php echo (int) $itinerary_id; ?>">
            <button type="submit" name="create_share_link" class="btn btn-outline-primary">
                Create share link
            </button>
        </form>
    <?php endif; ?>
</div>

<div class="mt-4 text-center">

    <a href="trips.php"
       class="action-btn outline me-2">
       Back to My Trips
    </a>

    <a href="preferences.php"
       class="action-btn primary me-2">
       Plan Another Trip
    </a>

    <!-- FIX 1D: delete is now a POST form with CSRF, not a GET link. -->
    <form method="POST" action="delete_itinerary.php"
          style="display:inline;"
          onsubmit="return confirm('Are you sure you want to delete this trip permanently?');">
        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
        <input type="hidden" name="itinerary_id" value="<?php echo (int) $itinerary_id; ?>">
        <button type="submit" class="action-btn danger">Delete This Trip</button>
    </form>

</div>

</div>

<?php
$accStmt->close();
$actStmt->close();
include("footer.php");
?>

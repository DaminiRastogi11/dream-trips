<?php
/* Public, read-only itinerary viewer.
   Anyone with a valid share_token can view; no login required. */
session_start();
include("config.php");
include("header.php");

$token = isset($_GET['token']) ? trim($_GET['token']) : '';
if ($token === '' || !preg_match('/^[a-f0-9]{32}$/', $token)) {
    echo "<div class='page-wrapper text-center'><h3 class='text-danger'>Invalid share link.</h3></div>";
    include("footer.php");
    exit;
}

$stmt = $conn->prepare(
    "SELECT i.*, d.city, d.country, t.name AS owner_name
     FROM itinerary i
     JOIN destination d ON d.destination_id = i.destination_id
     JOIN traveler    t ON t.traveler_id    = i.traveler_id
     WHERE i.share_token = ? AND i.deleted_at IS NULL
     LIMIT 1"
);
$stmt->bind_param("s", $token);
$stmt->execute();
$itinerary = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$itinerary) {
    echo "<div class='page-wrapper text-center'>
            <h3 class='text-danger'>This share link is no longer valid.</h3>
            <p class='text-muted'>The owner may have revoked it or removed the trip.</p>
          </div>";
    include("footer.php");
    exit;
}

$itinerary_id = (int) $itinerary['itinerary_id'];

$stmt = $conn->prepare(
    "SELECT * FROM itinerary_day WHERE itinerary_id = ? ORDER BY day_number"
);
$stmt->bind_param("i", $itinerary_id);
$stmt->execute();
$days = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$accStmt = $conn->prepare("SELECT * FROM accommodation WHERE accommodation_id = ?");
$actStmt = $conn->prepare(
    "SELECT ia.*,
            a.name AS attraction_name, a.entry_fee AS attraction_price,
            e.name AS event_name,      e.price     AS event_price
     FROM itinerary_activity ia
     LEFT JOIN attraction a ON ia.activity_type='attraction' AND ia.activity_id=a.attraction_id
     LEFT JOIN event      e ON ia.activity_type='event'      AND ia.activity_id=e.event_id
     WHERE ia.day_id = ?
     ORDER BY ia.itinerary_activity_id"
);
?>

<div class="page-wrapper">

    <div class="alert alert-info py-2">
        Shared by <strong><?php echo htmlspecialchars($itinerary['owner_name']); ?></strong>
        &mdash; this is a read-only view of their trip.
    </div>

    <h2 class="page-title mb-2">
        <?php echo htmlspecialchars($itinerary['city'] . " (" . $itinerary['country'] . ")"); ?>
    </h2>
    <p class="page-subtitle mb-4">
        <?php if (!empty($itinerary['start_date'])): ?>
            Starts <strong><?php echo date("D, M j, Y", strtotime($itinerary['start_date'])); ?></strong>
        <?php endif; ?>
    </p>

    <table class="table table-bordered table-summary align-middle bg-white">
        <thead>
            <tr>
                <th>Day</th><th>Accommodation</th><th>Activities</th><th>Day cost</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $total = 0;
        $start = $itinerary['start_date'] ?? null;
        foreach ($days as $day) {
            $day_cost = 0;
            $acc_disp = "&mdash;";

            if (!empty($day['accommodation_id'])) {
                $aid = (int) $day['accommodation_id'];
                $accStmt->bind_param("i", $aid);
                $accStmt->execute();
                $acc = $accStmt->get_result()->fetch_assoc();
                if ($acc) {
                    $acc_disp = htmlspecialchars($acc['name']) . "<br>$" . number_format($acc['cost_per_night'], 2) . "/night";
                    $day_cost += (float) $acc['cost_per_night'];
                }
            }

            $did = (int) $day['day_id'];
            $actStmt->bind_param("i", $did);
            $actStmt->execute();
            $acts = $actStmt->get_result()->fetch_all(MYSQLI_ASSOC);

            $acts_html = "<ul class='mb-0'>";
            foreach ($acts as $a) {
                if ($a['activity_type'] === 'attraction') {
                    $acts_html .= "<li>" . htmlspecialchars($a['attraction_name'] ?? '') . " (Attraction) - $" . htmlspecialchars((string)$a['attraction_price']) . "</li>";
                    $day_cost += (float) $a['attraction_price'];
                } else {
                    $price = (float) $a['event_price'];
                    if ($price > 0) {
                        $acts_html .= "<li>" . htmlspecialchars($a['event_name'] ?? '') . " (Event) - $" . htmlspecialchars((string)$price) . "</li>";
                        $day_cost += $price;
                    } else {
                        $acts_html .= "<li>" . htmlspecialchars($a['event_name'] ?? '') . " (Event)</li>";
                    }
                }
            }
            $acts_html .= "</ul>";

            $total += $day_cost;

            $label = "Day " . (int) $day['day_number'];
            if ($start) {
                $ts = strtotime($start . " +" . ((int)$day['day_number'] - 1) . " day");
                if ($ts) $label .= "<br><span class='small text-muted'>" . date("D, M j", $ts) . "</span>";
            }

            echo "<tr><td>{$label}</td><td>{$acc_disp}</td><td>{$acts_html}</td><td>$" . number_format($day_cost, 2) . "</td></tr>";
        }
        ?>
            <tr class="total-row">
                <td colspan="3" class="text-end">TOTAL</td>
                <td>$<?php echo number_format($total, 2); ?></td>
            </tr>
        </tbody>
    </table>

    <?php $accStmt->close(); $actStmt->close(); ?>

    <div class="text-center mt-3">
        <a href="login.php" class="btn btn-outline-primary">
            Sign in to plan your own trip
        </a>
    </div>
</div>

<?php include("footer.php"); ?>

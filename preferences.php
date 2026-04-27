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

$message = "";

if (isset($_POST['save'])) {
    // CSRF check
    verify_csrf_token();

    // (Budget moved to the Itinerary Builder so it's per-trip, not a
    // global preference. We keep the column in the DB for backward
    // compatibility but no longer ask for it here.)
    $budget    = 0;
    $climate   = isset($_POST['climate'])   ? $_POST['climate']   : '';
    $visa      = isset($_POST['visa'])      ? $_POST['visa']      : '';
    $trip_type = isset($_POST['trip_type']) ? $_POST['trip_type'] : '';

    $sql = "INSERT INTO traveler_preferences
                (traveler_id, max_budget, preferred_climate, visa_required, trip_type)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                preferred_climate = VALUES(preferred_climate),
                visa_required     = VALUES(visa_required),
                trip_type         = VALUES(trip_type)";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iisss", $traveler_id, $budget, $climate, $visa, $trip_type);
    $stmt->execute();
    $stmt->close();

    header("Location: destinations.php");
    exit;
}

/* Pre-fill form with current preferences if any */
$cur_climate = 'Mild';
$cur_visa = 'Either';
$cur_trip_type = 'Leisure';

$stmt = $conn->prepare("SELECT preferred_climate, visa_required, trip_type
                        FROM traveler_preferences WHERE traveler_id = ? LIMIT 1");
$stmt->bind_param("i", $traveler_id);
$stmt->execute();
$prefRes = $stmt->get_result();
if ($prefRes && $prefRow = $prefRes->fetch_assoc()) {
    $cur_climate   = $prefRow['preferred_climate'];
    $cur_visa      = $prefRow['visa_required'];
    $cur_trip_type = $prefRow['trip_type'];
}
$stmt->close();
?>

<div class="page-wrapper">
    <!-- STEP PROGRESS TRACKER -->
    <div class="steps-wrapper my-3">
        <div class="step active">Preferences</div>
        <div class="step">Destinations</div>
        <div class="step">Activities</div>
        <div class="step">Itinerary</div>
    </div>

    <h2 class="page-title mb-3" style="
    	font-size: 2rem;
    	font-weight: 800;
    	text-align: center;
     ">
    	Tell us about your ideal trip
    </h2>

    <p class="page-subtitle" style="
    	text-align: center;
    	font-size: 1rem;
    	color: #555;
    ">
   	 These preferences help us personalize destinations and activities for you.
    </p>

    <form method="post" class="row g-3">
        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">

        <div class="col-md-4">
            <label class="form-label">Preferred Climate</label>
            <select name="climate" class="form-select">
                <option value=""         <?php if ($cur_climate === '') echo 'selected'; ?>>Any</option>
                <option value="Mild"     <?php if ($cur_climate === 'Mild') echo 'selected'; ?>>Mild</option>
                <option value="Hot"      <?php if ($cur_climate === 'Hot') echo 'selected'; ?>>Hot</option>
                <option value="Seasonal" <?php if ($cur_climate === 'Seasonal') echo 'selected'; ?>>Seasonal</option>
                <option value="Cold"     <?php if ($cur_climate === 'Cold') echo 'selected'; ?>>Cold</option>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label">Visa Preference</label>
            <select name="visa" class="form-select">
                <option value="Either" <?php if ($cur_visa === 'Either') echo 'selected'; ?>>Either</option>
                <option value="No"     <?php if ($cur_visa === 'No') echo 'selected'; ?>>No visa required</option>
                <option value="Yes"    <?php if ($cur_visa === 'Yes') echo 'selected'; ?>>Visa required</option>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label">Trip Type</label>
            <select name="trip_type" class="form-select">
                <option value="Leisure"   <?php if ($cur_trip_type === 'Leisure') echo 'selected'; ?>>Leisure</option>
                <option value="Adventure" <?php if ($cur_trip_type === 'Adventure') echo 'selected'; ?>>Adventure</option>
                <option value="Romantic"  <?php if ($cur_trip_type === 'Romantic') echo 'selected'; ?>>Romantic</option>
                <option value="Family"    <?php if ($cur_trip_type === 'Family') echo 'selected'; ?>>Family</option>
            </select>
        </div>

        <div class="col-12 mt-3">
            <p class="text-muted small mb-3">
                You'll set your <strong>budget</strong> and <strong>start date</strong> on the next step,
                inside the Itinerary Builder.
            </p>
            <button type="submit" name="save" class="btn btn-primary">Save &amp; Continue</button>
        </div>
    </form>
</div>

<?php include("footer.php"); ?>

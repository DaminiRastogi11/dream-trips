<?php
session_start();
include("config.php");

// Must be logged in
if (!isset($_SESSION['email'])) {
    header("Location: login.php");
    exit;
}

// POST + CSRF only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: itinerary_builder.php");
    exit;
}

verify_csrf_token();

$itinerary_activity_id = isset($_POST['itinerary_activity_id']) ? intval($_POST['itinerary_activity_id']) : 0;
$itinerary_id          = isset($_POST['itinerary_id'])          ? intval($_POST['itinerary_id'])          : 0;

if ($itinerary_activity_id <= 0 || $itinerary_id <= 0) {
    header("Location: itinerary_builder.php" . ($itinerary_id ? "?itinerary_id=" . $itinerary_id : ""));
    exit;
}

// Resolve traveler
$email = $_SESSION['email'];
$stmt = $conn->prepare("SELECT traveler_id FROM traveler WHERE email = ? LIMIT 1");
$stmt->bind_param("s", $email);
$stmt->execute();
$travRes = $stmt->get_result();
$trav = $travRes ? $travRes->fetch_assoc() : null;
$stmt->close();

if (!$trav) {
    header("Location: login.php");
    exit;
}
$traveler_id = (int) $trav['traveler_id'];

/* Ownership check: confirm the activity belongs to a day that belongs
   to an itinerary owned by THIS user, and that the itinerary_id matches. */
$stmt = $conn->prepare(
    "SELECT ia.itinerary_activity_id
     FROM itinerary_activity ia
     JOIN itinerary_day  d ON d.day_id = ia.day_id
     JOIN itinerary      i ON i.itinerary_id = d.itinerary_id
     WHERE ia.itinerary_activity_id = ?
       AND i.itinerary_id = ?
       AND i.traveler_id  = ?
     LIMIT 1"
);
$stmt->bind_param("iii", $itinerary_activity_id, $itinerary_id, $traveler_id);
$stmt->execute();
$res = $stmt->get_result();
$valid = ($res && $res->num_rows > 0);
$stmt->close();

if (!$valid) {
    header("Location: itinerary_builder.php?itinerary_id=" . $itinerary_id);
    exit;
}

// Delete it
$stmt = $conn->prepare("DELETE FROM itinerary_activity WHERE itinerary_activity_id = ?");
$stmt->bind_param("i", $itinerary_activity_id);
$stmt->execute();
$stmt->close();

$_SESSION['flash_message'] = "Activity removed.";
header("Location: itinerary_builder.php?itinerary_id=" . $itinerary_id);
exit;
?>

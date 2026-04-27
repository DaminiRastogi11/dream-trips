<?php
session_start();
include("config.php");

// User must be logged in
if (!isset($_SESSION['email'])) {
    header("Location: login.php");
    exit;
}

// FIX 1D: only accept POST + CSRF (no more drive-by GET deletion)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: trips.php");
    exit;
}

verify_csrf_token();

if (!isset($_POST['itinerary_id'])) {
    header("Location: trips.php");
    exit;
}

$itinerary_id = intval($_POST['itinerary_id']);

// Get traveler_id of logged-in user (FIX 1A)
$email = $_SESSION['email'];
$stmt = $conn->prepare("SELECT traveler_id FROM traveler WHERE email = ? LIMIT 1");
$stmt->bind_param("s", $email);
$stmt->execute();
$travRes = $stmt->get_result();
$trav = $travRes ? $travRes->fetch_assoc() : null;
$stmt->close();

if (!$trav) {
    header("Location: trips.php");
    exit;
}

$traveler_id = (int) $trav['traveler_id'];

// Make sure the itinerary belongs to this user (FIX 1A)
$stmt = $conn->prepare(
    "SELECT itinerary_id FROM itinerary
     WHERE itinerary_id = ? AND traveler_id = ?
     LIMIT 1"
);
$stmt->bind_param("ii", $itinerary_id, $traveler_id);
$stmt->execute();
$check = $stmt->get_result();
$ok = ($check && $check->num_rows > 0);
$stmt->close();

if (!$ok) {
    // Invalid attempt — redirect safely
    header("Location: trips.php");
    exit;
}

/* SOFT DELETE: just set deleted_at. We keep the rows around so the
   user can restore them, and so historical analytics aren't lost.
   Permanent deletion is reserved for an admin/cleanup job. */

$action = isset($_POST['action']) ? $_POST['action'] : 'soft_delete';

if ($action === 'restore') {
    $stmt = $conn->prepare(
        "UPDATE itinerary SET deleted_at = NULL WHERE itinerary_id = ?"
    );
    $stmt->bind_param("i", $itinerary_id);
    $stmt->execute();
    $stmt->close();
    header("Location: trips.php?restored=1");
    exit;
}

// Default: soft-delete
$stmt = $conn->prepare(
    "UPDATE itinerary SET deleted_at = NOW() WHERE itinerary_id = ?"
);
$stmt->bind_param("i", $itinerary_id);
$stmt->execute();
$stmt->close();

header("Location: trips.php?deleted=1");
exit;
?>

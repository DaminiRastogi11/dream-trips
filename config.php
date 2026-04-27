<?php
// config.php
$host = "localhost";
$user = "root";
$pass = "";
$db   = "travel_itinerary_db";

$conn = mysqli_connect($host, $user, $pass, $db);

if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

date_default_timezone_set('America/Chicago'); // or your timezone

/* ---------------------------------------------
   CSRF protection helpers
---------------------------------------------- */

/**
 * Generate (or reuse) a CSRF token for the current session
 * and return it. Stored in $_SESSION['csrf_token'].
 *
 * Note: relies on session_start() having been called by
 * the page that invokes this function.
 */
function generate_csrf_token() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify the CSRF token submitted via POST against the
 * one stored in the session. If they don't match (or
 * either is missing), kill the request immediately.
 */
function verify_csrf_token() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $posted  = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    $session = isset($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : '';

    if (!$posted || !$session || !hash_equals($session, $posted)) {
        die("Invalid CSRF token. Please go back and try again.");
    }
}
?>

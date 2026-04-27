<?php
session_start();
include("config.php");
include("header.php");

if (!isset($_SESSION['email'])) {
    header("Location: login.php");
    exit;
}

$email = $_SESSION['email'];

/* Load current user */
$stmt = $conn->prepare(
    "SELECT traveler_id, name, email, password, created_at FROM traveler WHERE email = ? LIMIT 1"
);
$stmt->bind_param("s", $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    header("Location: login.php");
    exit;
}

$traveler_id = (int) $user['traveler_id'];

$message      = "";
$message_type = "info";

/* Stats for the sidebar */
$stmt = $conn->prepare(
    "SELECT COUNT(*) AS trips, COALESCE(SUM(budget),0) AS budget
       FROM itinerary WHERE traveler_id = ? AND deleted_at IS NULL"
);
$stmt->bind_param("i", $traveler_id);
$stmt->execute();
$st = $stmt->get_result()->fetch_assoc() ?: ['trips' => 0, 'budget' => 0];
$stmt->close();

$stmt = $conn->prepare(
    "SELECT COUNT(*) AS days FROM itinerary_day d
       JOIN itinerary i ON i.itinerary_id = d.itinerary_id
      WHERE i.traveler_id = ? AND i.deleted_at IS NULL"
);
$stmt->bind_param("i", $traveler_id);
$stmt->execute();
$days_planned = (int) ($stmt->get_result()->fetch_assoc()['days'] ?? 0);
$stmt->close();

/* ---------- Save name / email ---------- */
if (isset($_POST['save_profile'])) {
    verify_csrf_token();

    $new_name  = trim($_POST['name']  ?? '');
    $new_email = trim($_POST['email'] ?? '');
    $current   = $_POST['current_password'] ?? '';

    if ($new_name === '' || $new_email === '') {
        $message = "Name and email are required.";
        $message_type = "danger";
    } elseif ($current === '' || !password_verify($current, $user['password'])) {
        $message = "Current password is incorrect.";
        $message_type = "danger";
    } else {
        $email_taken = false;
        if (strcasecmp($new_email, $user['email']) !== 0) {
            $check = $conn->prepare(
                "SELECT traveler_id FROM traveler WHERE email = ? AND traveler_id != ? LIMIT 1"
            );
            $check->bind_param("si", $new_email, $traveler_id);
            $check->execute();
            $email_taken = ($check->get_result()->num_rows > 0);
            $check->close();
        }

        if ($email_taken) {
            $message = "That email is already in use by another account.";
            $message_type = "danger";
        } else {
            $upd = $conn->prepare("UPDATE traveler SET name = ?, email = ? WHERE traveler_id = ?");
            $upd->bind_param("ssi", $new_name, $new_email, $traveler_id);
            $upd->execute();
            $upd->close();

            $_SESSION['email'] = $new_email;
            $stmt = $conn->prepare("SELECT traveler_id, name, email, password, created_at FROM traveler WHERE traveler_id = ?");
            $stmt->bind_param("i", $traveler_id);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $message = "Profile updated.";
            $message_type = "success";
        }
    }
}

/* ---------- Change password ---------- */
if (isset($_POST['change_password'])) {
    verify_csrf_token();

    $current = $_POST['current_password'] ?? '';
    $new1    = $_POST['new_password']     ?? '';
    $new2    = $_POST['new_password2']    ?? '';

    if ($current === '' || !password_verify($current, $user['password'])) {
        $message = "Current password is incorrect.";
        $message_type = "danger";
    } elseif (strlen($new1) < 6) {
        $message = "New password must be at least 6 characters.";
        $message_type = "danger";
    } elseif ($new1 !== $new2) {
        $message = "New passwords don't match.";
        $message_type = "danger";
    } else {
        $hash = password_hash($new1, PASSWORD_DEFAULT);
        $upd = $conn->prepare("UPDATE traveler SET password = ? WHERE traveler_id = ?");
        $upd->bind_param("si", $hash, $traveler_id);
        $upd->execute();
        $upd->close();

        $stmt = $conn->prepare("SELECT traveler_id, name, email, password, created_at FROM traveler WHERE traveler_id = ?");
        $stmt->bind_param("i", $traveler_id);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $message = "Password updated.";
        $message_type = "success";
    }
}

/* Pretty initials for the avatar */
$initials = strtoupper(substr(trim($user['name']), 0, 1));
$parts = preg_split('/\s+/', trim($user['name']));
if (count($parts) > 1) {
    $initials = strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1));
}
?>

<div class="page-wrapper">

    <?php if ($message !== ""): ?>
        <div class="alert alert-<?php echo htmlspecialchars($message_type); ?> py-2">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <!-- Hero header with avatar -->
    <div class="profile-hero d-flex align-items-center mb-4">
        <div class="profile-avatar"><?php echo htmlspecialchars($initials); ?></div>
        <div class="ms-4">
            <div class="profile-name"><?php echo htmlspecialchars($user['name']); ?></div>
            <div class="profile-email"><?php echo htmlspecialchars($user['email']); ?></div>
            <div class="profile-since small mt-1">
                Member since <?php echo date("M Y", strtotime($user['created_at'])); ?>
            </div>
        </div>
    </div>

    <div class="row g-4">

        <!-- Sidebar stats -->
        <div class="col-md-4">
            <div class="card profile-stats border-0 shadow-sm p-4 mb-3">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <div class="kpi-label">Trips planned</div>
                        <div class="kpi-value"><?php echo (int) $st['trips']; ?></div>
                    </div>
                    <div class="profile-stat-icon">&#9992;</div>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <div class="kpi-label">Days mapped</div>
                        <div class="kpi-value"><?php echo $days_planned; ?></div>
                    </div>
                    <div class="profile-stat-icon">&#128197;</div>
                </div>
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="kpi-label">Combined budget</div>
                        <div class="kpi-value">$<?php echo number_format((float) $st['budget'], 0); ?></div>
                    </div>
                    <div class="profile-stat-icon">&#128176;</div>
                </div>
            </div>

            <div class="card profile-quick border-0 shadow-sm p-3">
                <a href="trips.php" class="quick-link">My Trips &rarr;</a>
                <a href="analytics.php" class="quick-link">Analytics &rarr;</a>
                <a href="preferences.php" class="quick-link">Plan a new trip &rarr;</a>
                <a href="logout.php" class="quick-link text-danger">Logout &rarr;</a>
            </div>
        </div>

        <!-- Forms -->
        <div class="col-md-8">

            <div class="card profile-form border-0 shadow-sm p-4 mb-4">
                <h5 class="mb-1 fw-bold">Account details</h5>
                <p class="text-muted small mb-3">
                    Update your name or email. We'll ask for your current password to confirm.
                </p>
                <!-- autocomplete=off prevents the browser from prefilling the password slot -->
                <form method="post" autocomplete="off" class="row g-3">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">

                    <div class="col-md-6">
                        <label class="form-label">Name</label>
                        <input type="text" name="name" class="form-control"
                               autocomplete="name"
                               value="<?php echo htmlspecialchars($user['name']); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control"
                               autocomplete="email"
                               value="<?php echo htmlspecialchars($user['email']); ?>" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Current password</label>
                        <div class="pw-wrapper">
                            <!-- autocomplete=new-password tricks the browser out of autofilling here -->
                            <input type="password" name="current_password"
                                   id="profCurPw1" class="form-control"
                                   autocomplete="new-password" value="" required>
                            <button type="button" class="pw-toggle" onclick="togglePw('profCurPw1', this)">Show</button>
                        </div>
                    </div>
                    <div class="col-12">
                        <button type="submit" name="save_profile" class="btn btn-primary px-4">
                            Save changes
                        </button>
                    </div>
                </form>
            </div>

            <div class="card profile-form border-0 shadow-sm p-4" id="change-password">
                <h5 class="mb-1 fw-bold">Change password</h5>
                <p class="text-muted small mb-3">
                    Use at least 6 characters. We recommend a mix of letters and numbers.
                </p>
                <form method="post" autocomplete="off" class="row g-3">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">

                    <div class="col-md-12">
                        <label class="form-label">Current password</label>
                        <div class="pw-wrapper">
                            <input type="password" name="current_password"
                                   id="profCurPw2" class="form-control"
                                   autocomplete="new-password" value="" required>
                            <button type="button" class="pw-toggle" onclick="togglePw('profCurPw2', this)">Show</button>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">New password</label>
                        <div class="pw-wrapper">
                            <input type="password" name="new_password"
                                   id="profNewPw1" class="form-control"
                                   autocomplete="new-password" value="" minlength="6" required>
                            <button type="button" class="pw-toggle" onclick="togglePw('profNewPw1', this)">Show</button>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Confirm new password</label>
                        <div class="pw-wrapper">
                            <input type="password" name="new_password2"
                                   id="profNewPw2" class="form-control"
                                   autocomplete="new-password" value="" minlength="6" required>
                            <button type="button" class="pw-toggle" onclick="togglePw('profNewPw2', this)">Show</button>
                        </div>
                    </div>
                    <div class="col-12">
                        <button type="submit" name="change_password" class="btn btn-primary px-4">
                            Update password
                        </button>
                    </div>
                </form>
            </div>

        </div>
    </div>

</div>

<script>
function togglePw(id, btn) {
    const el = document.getElementById(id);
    if (!el) return;
    if (el.type === 'password') {
        el.type = 'text';
        btn.textContent = 'Hide';
    } else {
        el.type = 'password';
        btn.textContent = 'Show';
    }
}

/* Belt-and-braces: clear any value the browser autofilled into password
   inputs after the page loads. Triggered after a short delay to beat
   the autofill from running. */
window.addEventListener('load', function () {
    setTimeout(function () {
        document.querySelectorAll('input[type=password]').forEach(function (el) {
            if (el.value && !el.dataset.userTyped) {
                el.value = '';
            }
        });
    }, 150);
});
document.addEventListener('input', function (e) {
    if (e.target && e.target.matches('input[type=password]')) {
        e.target.dataset.userTyped = '1';
    }
});
</script>

<?php include("footer.php"); ?>

<?php
session_start();
include("config.php");
include("header.php");

if (isset($_SESSION['email'])) {
    header("Location: dashboard.php");
    exit;
}

$message = "";

if (isset($_POST['register'])) {
    // CSRF check
    verify_csrf_token();

    $name  = isset($_POST['name'])  ? trim($_POST['name'])  : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $pass  = isset($_POST['password']) ? $_POST['password'] : '';

    if ($name === '' || $email === '' || $pass === '') {
        $message = "Please fill in all fields.";
    } else {
        // Check if email already exists
        $stmt = $conn->prepare("SELECT traveler_id FROM traveler WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $checkRes = $stmt->get_result();

        if ($checkRes && $checkRes->num_rows > 0) {
            $message = "Email already registered. Please login.";
            $stmt->close();
        } else {
            $stmt->close();
            $hash = password_hash($pass, PASSWORD_DEFAULT);

            $insert = $conn->prepare("INSERT INTO traveler (name, email, password) VALUES (?, ?, ?)");
            $insert->bind_param("sss", $name, $email, $hash);
            if ($insert->execute()) {
                $message = "Registration successful. You can now login.";
            } else {
                $message = "Error: " . htmlspecialchars($conn->error);
            }
            $insert->close();
        }
    }
}
?>

<div class="d-flex justify-content-center align-items-center" style="min-height: 80vh;">
    <div class="card auth-card p-4 bg-white">
        <h3 class="mb-3 text-center">Create Account</h3>

        <?php if ($message != ""): ?>
            <div class="alert alert-info py-2"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
            <div class="mb-3">
                <label class="form-label">Name</label>
                <input type="text" name="name" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Password</label>
                <div class="pw-wrapper">
                    <input type="password" name="password" id="signupPw" class="form-control"
                           autocomplete="new-password" minlength="6" required>
                    <button type="button" class="pw-toggle"
                            onclick="(function(b){var e=document.getElementById('signupPw');if(e.type==='password'){e.type='text';b.textContent='Hide';}else{e.type='password';b.textContent='Show';}})(this)">
                        Show
                    </button>
                </div>
            </div>
            <button type="submit" name="register" class="btn btn-primary w-100 mt-2">Sign Up</button>
        </form>

        <p class="mt-3 mb-0 text-center">
            Already have an account? <a href="login.php">Login</a>
        </p>
    </div>
</div>

<?php include("footer.php"); ?>

<?php
session_start();
include("config.php");
include("header.php");

if (isset($_SESSION['email'])) {
    header("Location: dashboard.php");
    exit;
}

$message = "";

if (isset($_POST['login'])) {
    // CSRF check
    verify_csrf_token();

    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $pass  = isset($_POST['password']) ? $_POST['password'] : '';

    if ($email !== '' && $pass !== '') {
        $stmt = $conn->prepare("SELECT traveler_id, email, password FROM traveler WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res && $res->num_rows === 1) {
            $row = $res->fetch_assoc();
            if (password_verify($pass, $row['password'])) {
                // FIX 1B: regenerate session ID after successful auth
                session_regenerate_id(true);

                $_SESSION['email'] = $row['email'];
                header("Location: dashboard.php");
                exit;
            } else {
                $message = "Invalid password.";
            }
        } else {
            $message = "Account not found.";
        }
        $stmt->close();
    } else {
        $message = "Please enter both email and password.";
    }
}
?>

<div class="d-flex justify-content-center align-items-center" style="min-height: 80vh;">
    <div class="card auth-card p-4 bg-white ">
        <h3 class="mb-3 text-center">Login</h3>

        <?php if ($message != ""): ?>
            <div class="alert alert-danger py-2"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
            <div class="mb-3">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Password</label>
                <div class="pw-wrapper">
                    <input type="password" name="password" id="loginPw" class="form-control"
                           autocomplete="current-password" required>
                    <button type="button" class="pw-toggle"
                            onclick="(function(b){var e=document.getElementById('loginPw');if(e.type==='password'){e.type='text';b.textContent='Hide';}else{e.type='password';b.textContent='Show';}})(this)">
                        Show
                    </button>
                </div>
            </div>
            <button type="submit" name="login" class="btn btn-primary w-100 mt-2">Login</button>
        </form>

        <p class="mt-3 mb-0 text-center">
            New here? <a href="signup.php">Create an account</a>
        </p>
    </div>
</div>

<?php include("footer.php"); ?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Dream Trips</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-airbnb">
    <div class="container">
        <a class="navbar-brand" href="dashboard.php">&#9992; Dream Trips</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navMenu">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li>
                <li class="nav-item"><a class="nav-link" href="preferences.php">Plan a Trip</a></li>
                <li class="nav-item"><a class="nav-link" href="trips.php">My Trips</a></li>
                <li class="nav-item"><a class="nav-link" href="analytics.php">Analytics</a></li>

                <!-- Profile dropdown (replaces stand-alone Logout) -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle"
                       href="#" id="profileDropdown"
                       role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        Profile
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="profileDropdown">
                        <li><a class="dropdown-item" href="profile.php">Edit Profile</a></li>
                        <li><a class="dropdown-item" href="profile.php#change-password">Change Password</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="logout.php">Logout</a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>

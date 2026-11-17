<?php
/**
 * Login Page
 * Authenticates users with password_verify() and regenerates session ID.
 */
require_once __DIR__ . '/includes/auth.php';

if ($existing = current_user()) {
    redirect(role_home_path($existing['role']));
}

$pdo = Database::getConnection();
$error = null;
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Please enter both your email address and password.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $error = 'Invalid email address or password.';
        } elseif ((int)$user['is_active'] !== 1) {
            $error = 'Your account has been deactivated. Please contact SwiftDrive administration.';
        } else {
            login_user($user);
            set_flash('success', 'Welcome back, ' . $user['full_name'] . '!');
            $intended = $_SESSION['intended_url'] ?? '';
            unset($_SESSION['intended_url']);

            if ($intended !== '' && str_starts_with($intended, '/') && !str_contains($intended, 'login.php')) {
                header('Location: ' . $intended);
                exit;
            }
            redirect(role_home_path($user['role']));
        }
    }
}

$pageTitle = 'Sign In';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-5">
            <div class="card border shadow-sm">
                <div class="card-body p-4 p-lg-5">
                    <div class="text-center mb-4">
                        <i class="bi bi-car-front-fill text-orange fs-1"></i>
                        <h1 class="h3 fw-bold mt-2">Sign In to SwiftDrive</h1>
                        <p class="text-muted small mb-0">Access your bookings, staff dispatch console, or fleet administration.</p>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger small" role="alert">
                            <i class="bi bi-exclamation-triangle-fill me-1"></i><?= e($error) ?>
                        </div>
                    <?php endif; ?>

                    <form action="<?= e(url('login.php')) ?>" method="post" novalidate>
                        <?= csrf_field() ?>
                        <div class="mb-3">
                            <label for="email" class="form-label fw-semibold">Email Address</label>
                            <input type="email" id="email" name="email" class="form-control" value="<?= e($email) ?>" placeholder="you@example.lk" required autofocus>
                        </div>
                        <div class="mb-4">
                            <label for="password" class="form-label fw-semibold">Password</label>
                            <div class="input-group">
                                <input type="password" id="password" name="password" class="form-control" placeholder="Enter your password" required>
                                <button type="button" class="btn password-toggle-btn" data-toggle-password="password" aria-label="Show password" aria-pressed="false">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-accent w-100 py-2 mb-3">
                            <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
                        </button>
                    </form>

                    <div class="text-center small">
                        New to SwiftDrive Rentals? <a href="<?= e(url('register.php')) ?>" class="fw-semibold text-decoration-none">Create a Customer Account</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>


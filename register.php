<?php
/**
 * Customer Registration Page
 * Enforces role = 'customer' hardcoded server-side (never allows staff/admin registration).
 * Uses password_hash(..., PASSWORD_DEFAULT).
 */
require_once __DIR__ . '/includes/auth.php';

if ($existing = current_user()) {
    redirect(role_home_path($existing['role']));
}

$pdo = Database::getConnection();
$errors = [];
$form = [
    'full_name'       => '',
    'email'           => '',
    'phone'           => '',
    'nic_or_passport' => '',
    'license_number'  => '',
    'address'         => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $form['full_name']       = trim($_POST['full_name'] ?? '');
    $form['email']           = strtolower(trim($_POST['email'] ?? ''));
    $form['phone']           = trim($_POST['phone'] ?? '');
    $form['nic_or_passport'] = trim($_POST['nic_or_passport'] ?? '');
    $form['license_number']  = trim($_POST['license_number'] ?? '');
    $form['address']         = trim($_POST['address'] ?? '');
    $password                = $_POST['password'] ?? '';
    $passwordConfirm         = $_POST['password_confirm'] ?? '';

    if ($form['full_name'] === '' || mb_strlen($form['full_name']) < 3) {
        $errors[] = 'Full name must be at least 3 characters.';
    }
    if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if ($form['phone'] === '' || !preg_match('/^[0-9+\-\s()]{7,25}$/', $form['phone'])) {
        $errors[] = 'Please enter a valid phone number.';
    }
    if ($form['nic_or_passport'] === '' || mb_strlen($form['nic_or_passport']) < 5) {
        $errors[] = 'NIC or Passport number is required for rental verification.';
    }
    if ($form['license_number'] === '' || mb_strlen($form['license_number']) < 4) {
        $errors[] = 'Valid driving licence number is required.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long.';
    }
    if ($password !== $passwordConfirm) {
        $errors[] = 'Password confirmation does not match.';
    }

    if (empty($errors)) {
        // Check duplicate email
        $chk = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
        $chk->execute(['email' => $form['email']]);
        if ($chk->fetch()) {
            $errors[] = 'An account with that email address already exists. Please sign in instead.';
        } else {
            try {
                // SECURITY: Public registration strictly enforces role = 'customer'
                $stmt = $pdo->prepare("
                    INSERT INTO users (full_name, email, phone, nic_or_passport, license_number, address, password_hash, role, is_active)
                    VALUES (:full_name, :email, :phone, :nic, :lic, :address, :hash, 'customer', 1)
                ");
                $stmt->execute([
                    'full_name' => $form['full_name'],
                    'email'     => $form['email'],
                    'phone'     => $form['phone'],
                    'nic'       => $form['nic_or_passport'],
                    'lic'       => $form['license_number'],
                    'address'   => $form['address'] !== '' ? $form['address'] : null,
                    'hash'      => password_hash($password, PASSWORD_DEFAULT),
                ]);

                $newUserId = (int)$pdo->lastInsertId();
                $userStmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
                $userStmt->execute(['id' => $newUserId]);
                $newUser = $userStmt->fetch();

                login_user($newUser);
                set_flash('success', 'Account created! Welcome to SwiftDrive Rentals, ' . $newUser['full_name'] . '.');
                redirect('vehicles.php');
            } catch (Throwable $e) {
                Database::logError('Customer registration error: ' . $e->getMessage());
                $errors[] = 'Unable to complete registration at this time. Please try again.';
            }
        }
    }
}

$pageTitle = 'Create Customer Account';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-7">
            <div class="card border shadow-sm">
                <div class="card-body p-4 p-lg-5">
                    <div class="mb-4">
                        <span class="text-orange fw-bold text-uppercase small">Customer Portal Registration</span>
                        <h1 class="h3 fw-bold mt-1">Create Your SwiftDrive Account</h1>
                        <p class="text-muted small mb-0">Register once to reserve vehicles online, track bookings, and download rental receipts.</p>
                    </div>

                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger small" role="alert">
                            <strong class="d-block mb-1">Please correct the following:</strong>
                            <ul class="mb-0">
                                <?php foreach ($errors as $err): ?>
                                    <li><?= e($err) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <form action="<?= e(url('register.php')) ?>" method="post" novalidate>
                        <?= csrf_field() ?>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="full_name" class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                                <input type="text" id="full_name" name="full_name" class="form-control" value="<?= e($form['full_name']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label for="email" class="form-label fw-semibold">Email Address <span class="text-danger">*</span></label>
                                <input type="email" id="email" name="email" class="form-control" value="<?= e($form['email']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label for="phone" class="form-label fw-semibold">Mobile / WhatsApp Number <span class="text-danger">*</span></label>
                                <input type="tel" id="phone" name="phone" class="form-control" placeholder="+94 77 123 4567" value="<?= e($form['phone']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label for="nic_or_passport" class="form-label fw-semibold">NIC or Passport Number <span class="text-danger">*</span></label>
                                <input type="text" id="nic_or_passport" name="nic_or_passport" class="form-control" placeholder="e.g. 199612304567 or N1234567" value="<?= e($form['nic_or_passport']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label for="license_number" class="form-label fw-semibold">Driving Licence Number <span class="text-danger">*</span></label>
                                <input type="text" id="license_number" name="license_number" class="form-control" placeholder="e.g. B-4421980" value="<?= e($form['license_number']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label for="address" class="form-label fw-semibold">Residential / Hotel Address</label>
                                <input type="text" id="address" name="address" class="form-control" placeholder="Negombo / Colombo address" value="<?= e($form['address']) ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="password" class="form-label fw-semibold">Password (min 8 chars) <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="password" id="password" name="password" class="form-control" required minlength="8">
                                    <button type="button" class="btn password-toggle-btn" data-toggle-password="password" aria-label="Show password" aria-pressed="false">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="password_confirm" class="form-label fw-semibold">Confirm Password <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="password" id="password_confirm" name="password_confirm" class="form-control" required minlength="8">
                                    <button type="button" class="btn password-toggle-btn" data-toggle-password="password_confirm" aria-label="Show password" aria-pressed="false">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="col-12 mt-4">
                                <button type="submit" class="btn btn-accent w-100 py-2">
                                    <i class="bi bi-person-plus-fill me-2"></i>Create Customer Account
                                </button>
                            </div>
                        </div>
                    </form>

                    <div class="text-center small mt-4">
                        Already have an account? <a href="<?= e(url('login.php')) ?>" class="fw-semibold text-decoration-none">Sign In</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>


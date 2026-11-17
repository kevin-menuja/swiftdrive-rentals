<?php
/**
 * Customer Profile Management Page
 * Allows authenticated users to update contact details, NIC/Passport, driving licence, and password.
 */
require_once __DIR__ . '/../includes/auth.php';

$user = require_login();
$pdo  = Database::getConnection();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = $_POST['action'] ?? 'update_profile';

    if ($action === 'update_profile') {
        $fullName      = trim($_POST['full_name'] ?? '');
        $phone         = trim($_POST['phone'] ?? '');
        $nicOrPassport = trim($_POST['nic_or_passport'] ?? '');
        $licenseNumber = trim($_POST['license_number'] ?? '');
        $address       = trim($_POST['address'] ?? '');

        if ($fullName === '' || mb_strlen($fullName) < 3) {
            $errors[] = 'Full name must be at least 3 characters.';
        }
        if ($phone === '' || !preg_match('/^[0-9+\-\s()]{7,25}$/', $phone)) {
            $errors[] = 'Please enter a valid contact phone number.';
        }

        if (empty($errors)) {
            $stmt = $pdo->prepare("
                UPDATE users
                SET full_name = :full_name,
                    phone = :phone,
                    nic_or_passport = :nic,
                    license_number = :lic,
                    address = :address
                WHERE id = :id
            ");
            $stmt->execute([
                'full_name' => $fullName,
                'phone'     => $phone,
                'nic'       => $nicOrPassport !== '' ? $nicOrPassport : null,
                'lic'       => $licenseNumber !== '' ? $licenseNumber : null,
                'address'   => $address !== '' ? $address : null,
                'id'        => (int)$user['id'],
            ]);

            $_SESSION['user_name'] = $fullName;
            set_flash('success', 'Your profile details have been updated.');
            redirect('customer/profile.php');
        }
    } elseif ($action === 'change_password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword     = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        $pwStmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = :id LIMIT 1");
        $pwStmt->execute(['id' => (int)$user['id']]);
        $hash = $pwStmt->fetchColumn();

        if (!$hash || !password_verify($currentPassword, $hash)) {
            $errors[] = 'Your current password is incorrect.';
        }
        if (strlen($newPassword) < 8) {
            $errors[] = 'New password must be at least 8 characters long.';
        }
        if ($newPassword !== $confirmPassword) {
            $errors[] = 'New password confirmation does not match.';
        }

        if (empty($errors)) {
            $upd = $pdo->prepare("UPDATE users SET password_hash = :hash WHERE id = :id");
            $upd->execute([
                'hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                'id'   => (int)$user['id'],
            ]);
            set_flash('success', 'Your password has been updated securely.');
            redirect('customer/profile.php');
        }
    }
}

// Refresh user row
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
$stmt->execute(['id' => (int)$user['id']]);
$user = $stmt->fetch();

$pageTitle = 'My Profile';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="page-header-band">
    <div class="container">
        <span class="text-orange fw-bold text-uppercase small">Account Settings</span>
        <h1 class="fw-bold mb-1">My Profile &amp; Driver Credentials</h1>
        <p class="mb-0 text-light opacity-75">Manage your contact details, NIC/Passport, driving licence number, and password.</p>
    </div>
</section>

<div class="container pb-5">
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <ul class="mb-0 small">
                <?php foreach ($errors as $err): ?>
                    <li><?= e($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card border shadow-sm">
                <div class="card-header bg-white py-3">
                    <h2 class="h5 fw-bold mb-0"><i class="bi bi-person-vcard text-orange me-2"></i>Personal &amp; Driver Licence Information</h2>
                </div>
                <div class="card-body p-4">
                    <form action="<?= e(url('customer/profile.php')) ?>" method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_profile">

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="full_name" class="form-label fw-semibold small">Full Name</label>
                                <input type="text" id="full_name" name="full_name" class="form-control" value="<?= e($user['full_name']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label for="email" class="form-label fw-semibold small">Email Address (Login ID)</label>
                                <input type="email" id="email" class="form-control bg-light" value="<?= e($user['email']) ?>" disabled>
                            </div>
                            <div class="col-md-6">
                                <label for="phone" class="form-label fw-semibold small">Phone / WhatsApp Number</label>
                                <input type="tel" id="phone" name="phone" class="form-control" value="<?= e($user['phone']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label for="nic_or_passport" class="form-label fw-semibold small">NIC / Passport Number</label>
                                <input type="text" id="nic_or_passport" name="nic_or_passport" class="form-control" value="<?= e($user['nic_or_passport']) ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="license_number" class="form-label fw-semibold small">Driving Licence Number</label>
                                <input type="text" id="license_number" name="license_number" class="form-control" value="<?= e($user['license_number']) ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="address" class="form-label fw-semibold small">Address in Sri Lanka</label>
                                <input type="text" id="address" name="address" class="form-control" value="<?= e($user['address']) ?>">
                            </div>
                            <div class="col-12 mt-3">
                                <button type="submit" class="btn btn-accent">
                                    <i class="bi bi-check2-circle me-1"></i>Save Profile Changes
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card border shadow-sm">
                <div class="card-header bg-white py-3">
                    <h2 class="h5 fw-bold mb-0"><i class="bi bi-key text-orange me-2"></i>Change Password</h2>
                </div>
                <div class="card-body p-4">
                    <form action="<?= e(url('customer/profile.php')) ?>" method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="change_password">
                        <div class="mb-3">
                            <label for="current_password" class="form-label fw-semibold small">Current Password</label>
                            <div class="input-group">
                                <input type="password" id="current_password" name="current_password" class="form-control" required>
                                <button type="button" class="btn password-toggle-btn" data-toggle-password="current_password" aria-label="Show password" aria-pressed="false">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="new_password" class="form-label fw-semibold small">New Password (min 8 chars)</label>
                            <div class="input-group">
                                <input type="password" id="new_password" name="new_password" class="form-control" minlength="8" required>
                                <button type="button" class="btn password-toggle-btn" data-toggle-password="new_password" aria-label="Show password" aria-pressed="false">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="confirm_password" class="form-label fw-semibold small">Confirm New Password</label>
                            <div class="input-group">
                                <input type="password" id="confirm_password" name="confirm_password" class="form-control" minlength="8" required>
                                <button type="button" class="btn password-toggle-btn" data-toggle-password="confirm_password" aria-label="Show password" aria-pressed="false">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-navy w-100">Update Password</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>


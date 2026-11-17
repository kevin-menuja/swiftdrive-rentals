<?php
/**
 * Administrator Staff Account Management Page
 * Allows administrators to create new staff accounts, update details, and activate/deactivate staff access
 * while preserving historical booking/inspection audit trails.
 */
require_once __DIR__ . '/../includes/auth.php';

$user = require_role('admin');
$pdo  = Database::getConnection();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_staff') {
        $fullName = trim($_POST['full_name'] ?? '');
        $email    = strtolower(trim($_POST['email'] ?? ''));
        $phone    = trim($_POST['phone'] ?? '');
        $nic      = trim($_POST['nic_or_passport'] ?? '');
        $password = $_POST['password'] ?? '';
        $role     = ($_POST['role'] ?? 'staff') === 'admin' ? 'admin' : 'staff';

        if ($fullName === '' || mb_strlen($fullName) < 3) {
            $errors[] = 'Staff full name must be at least 3 characters.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Valid email address is required.';
        }
        if (strlen($password) < 8) {
            $errors[] = 'Initial password must be at least 8 characters.';
        }

        if (empty($errors)) {
            $chk = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
            $chk->execute(['email' => $email]);
            if ($chk->fetch()) {
                $errors[] = 'A user with that email address already exists.';
            } else {
                $ins = $pdo->prepare("
                    INSERT INTO users (full_name, email, phone, nic_or_passport, password_hash, role, is_active)
                    VALUES (:name, :email, :phone, :nic, :hash, :role, 1)
                ");
                $ins->execute([
                    'name'  => $fullName,
                    'email' => $email,
                    'phone' => $phone !== '' ? $phone : '+94 31 222 8890',
                    'nic'   => $nic !== '' ? $nic : null,
                    'hash'  => password_hash($password, PASSWORD_DEFAULT),
                    'role'  => $role,
                ]);
                set_flash('success', ucfirst($role) . ' account created for ' . $fullName . ' (' . $email . ').');
                redirect('admin/staff.php');
            }
        }
    } elseif ($action === 'toggle_active') {
        $targetId = (int)($_POST['user_id'] ?? 0);
        $newState = (int)($_POST['is_active'] ?? 1) === 1 ? 1 : 0;

        if ($targetId === (int)$user['id'] && $newState === 0) {
            set_flash('danger', 'You cannot deactivate your own currently logged-in administrator account.');
        } else {
            $upd = $pdo->prepare("UPDATE users SET is_active = :active WHERE id = :id AND role IN ('staff', 'admin')");
            $upd->execute(['active' => $newState, 'id' => $targetId]);
            set_flash('success', 'Staff account status updated (all historical booking and inspection records remain intact).');
        }
        redirect('admin/staff.php');
    }
}

$staffMembers = $pdo->query("
    SELECT u.*,
           (SELECT COUNT(*) FROM bookings b WHERE b.confirmed_by = u.id) AS confirmed_count,
           (SELECT COUNT(*) FROM inquiries i WHERE i.handled_by = u.id) AS inquiries_count
    FROM users u
    WHERE u.role IN ('staff', 'admin')
    ORDER BY FIELD(u.role, 'admin', 'staff'), u.id ASC
")->fetchAll();

$pageTitle = 'Manage Staff Accounts';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="page-header-band">
    <div class="container d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <span class="text-orange fw-bold text-uppercase small">Access &amp; Personnel Administration</span>
            <h1 class="fw-bold mb-1">Manage Staff &amp; Administrator Accounts</h1>
            <p class="mb-0 text-light opacity-75">Provision new staff credentials and activate or deactivate accounts without losing historical records.</p>
        </div>
        <a href="<?= e(url('admin/index.php')) ?>" class="btn btn-outline-light">
            <i class="bi bi-arrow-left me-1"></i>Admin Dashboard
        </a>
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
        <div class="col-lg-4">
            <div class="card border shadow-sm">
                <div class="card-header bg-navy text-white py-3">
                    <h2 class="h6 fw-bold mb-0"><i class="bi bi-person-plus-fill text-orange me-2"></i>Provision New Staff Account</h2>
                </div>
                <div class="card-body p-4">
                    <form action="<?= e(url('admin/staff.php')) ?>" method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="create_staff">

                        <div class="mb-3">
                            <label for="full_name" class="form-label small fw-semibold">Full Name <span class="text-danger">*</span></label>
                            <input type="text" id="full_name" name="full_name" class="form-control form-control-sm" required>
                        </div>
                        <div class="mb-3">
                            <label for="email" class="form-label small fw-semibold">Work Email <span class="text-danger">*</span></label>
                            <input type="email" id="email" name="email" class="form-control form-control-sm" placeholder="name@swiftdrive.lk" required>
                        </div>
                        <div class="mb-3">
                            <label for="phone" class="form-label small fw-semibold">Phone Number</label>
                            <input type="tel" id="phone" name="phone" class="form-control form-control-sm" placeholder="+94 77 123 4567" required>
                        </div>
                        <div class="mb-3">
                            <label for="nic_or_passport" class="form-label small fw-semibold">NIC Number</label>
                            <input type="text" id="nic_or_passport" name="nic_or_passport" class="form-control form-control-sm">
                        </div>
                        <div class="mb-3">
                            <label for="role" class="form-label small fw-semibold">Account Role</label>
                            <select id="role" name="role" class="form-select form-select-sm">
                                <option value="staff">Operations Staff</option>
                                <option value="admin">Administrator</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label small fw-semibold">Initial Password (min 8 chars) <span class="text-danger">*</span></label>
                            <input type="password" id="password" name="password" class="form-control form-control-sm" minlength="8" required>
                        </div>
                        <button type="submit" class="btn btn-accent w-100">Create Staff Account</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card border shadow-sm">
                <div class="card-header bg-white py-3">
                    <h2 class="h6 fw-bold mb-0"><i class="bi bi-people-fill text-orange me-2"></i>Staff &amp; Administrator Directory</h2>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle small mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-3">Name &amp; Email</th>
                                    <th>Role</th>
                                    <th>Activity Audit</th>
                                    <th>Status</th>
                                    <th class="pe-3 text-end">Access Control</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($staffMembers as $s): ?>
                                    <tr>
                                        <td class="ps-3">
                                            <strong><?= e($s['full_name']) ?></strong><br>
                                            <span class="text-muted"><?= e($s['email']) ?> &bull; <?= e($s['phone']) ?></span>
                                        </td>
                                        <td>
                                            <span class="badge <?= $s['role'] === 'admin' ? 'bg-danger' : 'bg-primary' ?> text-uppercase">
                                                <?= e($s['role']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?= (int)$s['confirmed_count'] ?> bookings handled<br>
                                            <span class="text-muted"><?= (int)$s['inquiries_count'] ?> inquiries answered</span>
                                        </td>
                                        <td>
                                            <?php if ((int)$s['is_active'] === 1): ?>
                                                <span class="badge bg-success">Active</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Deactivated</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="pe-3 text-end">
                                            <?php if ((int)$s['id'] === (int)$user['id']): ?>
                                                <span class="text-muted">Current Session</span>
                                            <?php else: ?>
                                                <form action="<?= e(url('admin/staff.php')) ?>" method="post" class="d-inline">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="toggle_active">
                                                    <input type="hidden" name="user_id" value="<?= (int)$s['id'] ?>">
                                                    <input type="hidden" name="is_active" value="<?= (int)$s['is_active'] === 1 ? 0 : 1 ?>">
                                                    <button type="submit" class="btn btn-sm <?= (int)$s['is_active'] === 1 ? 'btn-outline-danger' : 'btn-outline-success' ?>">
                                                        <?= (int)$s['is_active'] === 1 ? 'Deactivate' : 'Activate' ?>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>


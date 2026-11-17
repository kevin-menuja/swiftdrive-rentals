<?php
/**
 * Administrator Customers, Bookings, Payments & Inquiries Audit Page
 * Provides unified oversight of customer accounts (including soft activation toggle),
 * complete payment transaction ledger, bookings, and inquiries.
 */
require_once __DIR__ . '/../includes/auth.php';

$user = require_role('admin');
$pdo  = Database::getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'toggle_customer') {
        $cid = (int)($_POST['customer_id'] ?? 0);
        $act = (int)($_POST['is_active'] ?? 1) === 1 ? 1 : 0;
        $upd = $pdo->prepare("UPDATE users SET is_active = :act WHERE id = :id AND role = 'customer'");
        $upd->execute(['act' => $act, 'id' => $cid]);
        set_flash('success', 'Customer account status updated (booking history preserved).');
        redirect('admin/customers.php');
    }
}

$customers = $pdo->query("
    SELECT u.*,
           COUNT(b.id) AS bookings_count,
           COALESCE(SUM(b.amount_paid_lkr), 0) AS lifetime_paid_lkr
    FROM users u
    LEFT JOIN bookings b ON b.customer_id = u.id
    WHERE u.role = 'customer'
    GROUP BY u.id
    ORDER BY u.created_at DESC
")->fetchAll();

$payments = $pdo->query("
    SELECT p.*, b.booking_reference, u.full_name AS customer_name
    FROM payments p
    JOIN bookings b ON b.id = p.booking_id
    JOIN users u ON u.id = p.customer_id
    ORDER BY p.created_at DESC
")->fetchAll();

$pageTitle = 'Customers & Financial Audit';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="page-header-band">
    <div class="container d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <span class="text-orange fw-bold text-uppercase small">Administration Audit</span>
            <h1 class="fw-bold mb-1">Customers, Payments, Bookings &amp; Inquiries</h1>
            <p class="mb-0 text-light opacity-75">Inspect registered customer profiles, payment ledgers, reservations, and support tickets.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= e(url('staff/bookings.php')) ?>" class="btn btn-accent">All Bookings</a>
            <a href="<?= e(url('staff/inquiries.php')) ?>" class="btn btn-outline-light">All Inquiries</a>
            <a href="<?= e(url('admin/index.php')) ?>" class="btn btn-outline-light">Dashboard</a>
        </div>
    </div>
</section>

<div class="container pb-5">
    <!-- Customer Directory -->
    <div class="card border shadow-sm mb-5">
        <div class="card-header bg-white py-3">
            <h2 class="h5 fw-bold mb-0"><i class="bi bi-people text-orange me-2"></i>Registered Customers (<?= count($customers) ?>)</h2>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle small mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">Customer</th>
                            <th>NIC / Driving Licence</th>
                            <th>Address</th>
                            <th>Bookings</th>
                            <th>Total Paid (LKR)</th>
                            <th>Status</th>
                            <th class="pe-3 text-end">Account Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($customers as $c): ?>
                            <tr>
                                <td class="ps-3">
                                    <strong><?= e($c['full_name']) ?></strong><br>
                                    <span class="text-muted"><?= e($c['email']) ?> &bull; <?= e($c['phone']) ?></span>
                                </td>
                                <td>
                                    NIC: <code><?= e($c['nic_or_passport'] ?: '—') ?></code><br>
                                    Lic: <code><?= e($c['license_number'] ?: '—') ?></code>
                                </td>
                                <td><?= e($c['address'] ?: '—') ?></td>
                                <td><span class="badge bg-navy"><?= (int)$c['bookings_count'] ?> bookings</span></td>
                                <td class="fw-bold text-success"><?= e(format_lkr($c['lifetime_paid_lkr'])) ?></td>
                                <td>
                                    <span class="badge <?= (int)$c['is_active'] === 1 ? 'bg-success' : 'bg-secondary' ?>">
                                        <?= (int)$c['is_active'] === 1 ? 'Active' : 'Deactivated' ?>
                                    </span>
                                </td>
                                <td class="pe-3 text-end">
                                    <form action="<?= e(url('admin/customers.php')) ?>" method="post" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle_customer">
                                        <input type="hidden" name="customer_id" value="<?= (int)$c['id'] ?>">
                                        <input type="hidden" name="is_active" value="<?= (int)$c['is_active'] === 1 ? 0 : 1 ?>">
                                        <button type="submit" class="btn btn-sm <?= (int)$c['is_active'] === 1 ? 'btn-outline-danger' : 'btn-outline-success' ?>">
                                            <?= (int)$c['is_active'] === 1 ? 'Deactivate' : 'Activate' ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Complete Payment Ledger -->
    <div class="card border shadow-sm" id="payments">
        <div class="card-header bg-white py-3">
            <h2 class="h5 fw-bold mb-0"><i class="bi bi-credit-card text-orange me-2"></i>Complete Payment Ledger (<?= count($payments) ?>)</h2>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle small mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">Payment Ref</th>
                            <th>Booking Ref</th>
                            <th>Customer</th>
                            <th>Type &amp; Method</th>
                            <th>Authorization Details</th>
                            <th>Amount (LKR)</th>
                            <th>Status</th>
                            <th class="pe-3">Timestamp</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $p): ?>
                            <tr>
                                <td class="ps-3 font-monospace fw-semibold"><?= e($p['payment_reference']) ?></td>
                                <td>
                                    <a href="<?= e(url('customer/booking_details.php?id=' . (int)$p['booking_id'])) ?>" class="font-monospace">
                                        <?= e($p['booking_reference']) ?>
                                    </a>
                                </td>
                                <td><?= e($p['customer_name']) ?></td>
                                <td>
                                    <strong><?= e(ucfirst(str_replace('_', ' ', $p['payment_type']))) ?></strong><br>
                                    <span class="text-muted"><?= e($p['payment_method']) ?></span>
                                </td>
                                <td><?= e($p['simulation_scenario']) ?></td>
                                <td class="fw-bold"><?= e(format_lkr($p['amount_lkr'])) ?></td>
                                <td>
                                    <span class="badge <?= $p['payment_status'] === 'succeeded' ? 'bg-success' : 'bg-danger' ?>">
                                        <?= e(strtoupper($p['payment_status'])) ?>
                                    </span>
                                </td>
                                <td class="pe-3"><?= e(date('d M Y H:i', strtotime($p['created_at']))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>


<?php
/**
 * Administrator Console Dashboard
 * Displays database-backed fleet, revenue, customer, staff, and booking metrics.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pricing.php';

$user = require_role('admin');
$pdo  = Database::getConnection();

$totals = [
    'total_vehicles'     => (int)$pdo->query("SELECT COUNT(*) FROM vehicles")->fetchColumn(),
    'available_vehicles' => (int)$pdo->query("SELECT COUNT(*) FROM vehicles WHERE is_active = 1 AND maintenance_status = 'available'")->fetchColumn(),
    'maint_vehicles'     => (int)$pdo->query("SELECT COUNT(*) FROM vehicles WHERE maintenance_status = 'maintenance'")->fetchColumn(),
    'total_customers'    => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'customer'")->fetchColumn(),
    'total_staff'        => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'staff'")->fetchColumn(),
    'total_bookings'     => (int)$pdo->query("SELECT COUNT(*) FROM bookings")->fetchColumn(),
    'collected_revenue'  => (float)$pdo->query("SELECT COALESCE(SUM(amount_lkr), 0) FROM payments WHERE payment_status = 'succeeded'")->fetchColumn(),
    'open_inquiries'     => (int)$pdo->query("SELECT COUNT(*) FROM inquiries WHERE status != 'resolved'")->fetchColumn(),
];

// Recent payments
$recentPayments = $pdo->query("
    SELECT p.*, b.booking_reference, u.full_name AS customer_name
    FROM payments p
    JOIN bookings b ON b.id = p.booking_id
    JOIN users u ON u.id = p.customer_id
    ORDER BY p.created_at DESC
    LIMIT 6
")->fetchAll();

// Fleet utilization by category
$catSummary = $pdo->query("
    SELECT c.name, COUNT(v.id) AS vehicle_count,
           SUM(CASE WHEN v.is_active = 1 AND v.maintenance_status = 'available' THEN 1 ELSE 0 END) AS ready_count
    FROM vehicle_categories c
    LEFT JOIN vehicles v ON v.category_id = c.id
    GROUP BY c.id
    ORDER BY c.display_order ASC
")->fetchAll();

$pageTitle = 'Administrator Console';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="page-header-band">
    <div class="container d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <span class="text-orange fw-bold text-uppercase small">System Administration</span>
            <h1 class="fw-bold mb-1">SwiftDrive Fleet &amp; Operations Console</h1>
            <p class="mb-0 text-light opacity-75">Database-backed totals, fleet CRUD, staff account management, and financial audit logs.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="<?= e(url('admin/vehicles.php')) ?>" class="btn btn-accent">
                <i class="bi bi-car-front me-1"></i>Manage Fleet Vehicles
            </a>
            <a href="<?= e(url('admin/staff.php')) ?>" class="btn btn-outline-light">
                <i class="bi bi-person-badge me-1"></i>Staff Accounts
            </a>
            <a href="<?= e(url('admin/customers.php')) ?>" class="btn btn-outline-light">
                <i class="bi bi-journal-text me-1"></i>Customers, Payments &amp; Audit
            </a>
        </div>
    </div>
</section>

<div class="container pb-5">
    <!-- Database-Backed KPI Cards -->
    <div class="row g-4 mb-4">
        <div class="col-sm-6 col-lg-3">
            <div class="kpi-card accent">
                <div class="text-muted small">Collected Revenue (LKR)</div>
                <div class="fs-4 fw-bold text-dark mt-1"><?= e(format_lkr($totals['collected_revenue'])) ?></div>
                <div class="small text-muted mt-1">Across <?= $totals['total_bookings'] ?> total reservations</div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="kpi-card">
                <div class="text-muted small">Fleet Readiness</div>
                <div class="fs-4 fw-bold text-success mt-1"><?= $totals['available_vehicles'] ?> / <?= $totals['total_vehicles'] ?> Ready</div>
                <div class="small text-warning mt-1"><?= $totals['maint_vehicles'] ?> in workshop maintenance</div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="kpi-card accent">
                <div class="text-muted small">Registered Customers</div>
                <div class="fs-4 fw-bold text-dark mt-1"><?= $totals['total_customers'] ?> Accounts</div>
                <a href="<?= e(url('admin/customers.php')) ?>" class="small text-decoration-none">View Customer Directory &rarr;</a>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="kpi-card">
                <div class="text-muted small">Operations Staff</div>
                <div class="fs-4 fw-bold text-primary mt-1"><?= $totals['total_staff'] ?> Staff Members</div>
                <a href="<?= e(url('admin/staff.php')) ?>" class="small text-decoration-none">Manage Staff &amp; Access &rarr;</a>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Category Breakdown -->
        <div class="col-lg-5">
            <div class="card border shadow-sm h-100">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h2 class="h6 fw-bold mb-0"><i class="bi bi-pie-chart text-orange me-2"></i>Fleet Breakdown by Category</h2>
                    <a href="<?= e(url('admin/vehicles.php')) ?>" class="btn btn-sm btn-outline-navy">Fleet CRUD</a>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm align-middle small mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">Category</th>
                                <th class="text-center">Total Fleet</th>
                                <th class="pe-3 text-end">Available Now</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($catSummary as $cs): ?>
                                <tr>
                                    <td class="ps-3 fw-semibold"><?= e($cs['name']) ?></td>
                                    <td class="text-center"><?= (int)$cs['vehicle_count'] ?></td>
                                    <td class="pe-3 text-end"><span class="badge bg-success"><?= (int)$cs['ready_count'] ?> Ready</span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Recent Payment Transactions -->
        <div class="col-lg-7">
            <div class="card border shadow-sm h-100">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h2 class="h6 fw-bold mb-0"><i class="bi bi-cash-stack text-orange me-2"></i>Recent Payment Transactions</h2>
                    <a href="<?= e(url('admin/customers.php?tab=payments')) ?>" class="btn btn-sm btn-outline-navy">All Payments</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle small mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-3">Payment Ref</th>
                                    <th>Booking</th>
                                    <th>Customer</th>
                                    <th>Amount</th>
                                    <th class="pe-3">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentPayments as $p): ?>
                                    <tr>
                                        <td class="ps-3 font-monospace"><?= e($p['payment_reference']) ?></td>
                                        <td><a href="<?= e(url('customer/booking_details.php?id=' . (int)$p['booking_id'])) ?>" class="font-monospace"><?= e($p['booking_reference']) ?></a></td>
                                        <td><?= e($p['customer_name']) ?></td>
                                        <td class="fw-semibold"><?= e(format_lkr($p['amount_lkr'])) ?></td>
                                        <td class="pe-3">
                                            <span class="badge <?= $p['payment_status'] === 'succeeded' ? 'bg-success' : 'bg-danger' ?>">
                                                <?= e(strtoupper($p['payment_status'])) ?>
                                            </span>
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


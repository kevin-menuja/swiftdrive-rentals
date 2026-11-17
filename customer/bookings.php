<?php
/**
 * Customer My Bookings Page
 * Displays all bookings belonging strictly to the logged-in customer (`customer_id = :uid`).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pricing.php';

$user = require_role('customer');
$pdo  = Database::getConnection();

$statusFilter = trim($_GET['status'] ?? '');
$where = ['b.customer_id = :customer_id'];
$params = ['customer_id' => (int)$user['id']];

if ($statusFilter !== '' && in_array($statusFilter, ['pending', 'confirmed', 'picked_up', 'completed', 'cancelled', 'rejected'], true)) {
    $where[] = 'b.booking_status = :status';
    $params['status'] = $statusFilter;
}

$sql = "
    SELECT b.*,
           v.brand, v.model, v.registration_number, v.primary_image,
           c.name AS category_name,
           pl.name AS pickup_location_name,
           rl.name AS return_location_name
    FROM bookings b
    JOIN vehicles v ON v.id = b.vehicle_id
    JOIN vehicle_categories c ON c.id = v.category_id
    JOIN locations pl ON pl.id = b.pickup_location_id
    JOIN locations rl ON rl.id = b.return_location_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY b.created_at DESC, b.id DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$bookings = $stmt->fetchAll();

// Fetch overall customer KPI summary (unfiltered)
$kpiStmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_bookings,
        SUM(CASE WHEN booking_status IN ('pending', 'confirmed', 'picked_up') THEN 1 ELSE 0 END) AS active_bookings,
        COALESCE(SUM(amount_paid_lkr), 0) AS total_paid_lkr,
        COALESCE(SUM(CASE WHEN booking_status NOT IN ('cancelled', 'rejected') THEN balance_due_lkr ELSE 0 END), 0) AS total_due_lkr
    FROM bookings
    WHERE customer_id = :cid
");
$kpiStmt->execute(['cid' => (int)$user['id']]);
$customerKpis = $kpiStmt->fetch();

$pageTitle = 'Customer Dashboard & My Bookings';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="page-header-band">
    <div class="container d-flex flex-wrap justify-content-between align-items-end gap-3">
        <div>
            <span class="text-orange fw-bold text-uppercase small">Customer Portal &bull; <?= e($user['full_name']) ?></span>
            <h1 class="fw-bold mb-1">My Vehicle Reservations</h1>
            <p class="mb-0 text-light opacity-75">Track your rental status, pay deposits, view dispatch inspections, or print receipts.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="<?= e(url('customer/profile.php')) ?>" class="btn btn-outline-light">
                <i class="bi bi-person-vcard me-1"></i>Profile &amp; Licence
            </a>
            <a href="<?= e(url('vehicles.php')) ?>" class="btn btn-accent">
                <i class="bi bi-plus-circle me-1"></i>New Vehicle Booking
            </a>
        </div>
    </div>
</section>

<div class="container pb-5">
    <!-- Customer KPI Summary Row -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="kpi-card h-100">
                <div class="small text-muted">Total Bookings</div>
                <div class="fs-4 fw-bold text-dark"><?= (int)($customerKpis['total_bookings'] ?? 0) ?></div>
                <div class="small text-muted">All-time reservations</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="kpi-card accent h-100">
                <div class="small text-muted">Active / Upcoming</div>
                <div class="fs-4 fw-bold text-dark"><?= (int)($customerKpis['active_bookings'] ?? 0) ?></div>
                <div class="small text-muted">Pending, Confirmed or Out</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="kpi-card h-100">
                <div class="small text-muted">Total Paid</div>
                <div class="fs-5 fw-bold text-success"><?= e(format_lkr((float)($customerKpis['total_paid_lkr'] ?? 0))) ?></div>
                <div class="small text-muted">Verified deposits &amp; payments</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="kpi-card accent h-100">
                <div class="small text-muted">Outstanding Balance</div>
                <div class="fs-5 fw-bold text-danger"><?= e(format_lkr((float)($customerKpis['total_due_lkr'] ?? 0))) ?></div>
                <div class="small text-muted">Payable online or at handover</div>
            </div>
        </div>
    </div>

    <!-- Status Filter Pills -->
    <div class="d-flex flex-wrap gap-2 mb-4">
        <?php
        $tabs = [
            ''          => 'All Bookings',
            'pending'   => 'Pending',
            'confirmed' => 'Confirmed',
            'picked_up' => 'Active Out on Road',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
        ];
        foreach ($tabs as $key => $label):
        ?>
            <a href="<?= e(url('customer/bookings.php' . ($key !== '' ? '?status=' . $key : ''))) ?>"
               class="btn btn-sm <?= $statusFilter === $key ? 'btn-navy' : 'btn-outline-secondary' ?>">
                <?= e($label) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if (empty($bookings)): ?>
        <div class="card border shadow-sm text-center p-5">
            <i class="bi bi-calendar2-x text-muted mb-3" style="font-size: 3rem;"></i>
            <h2 class="h5 fw-bold">No Bookings Found</h2>
            <p class="text-muted small mb-4">You don’t have any reservations matching this filter yet.</p>
            <div>
                <a href="<?= e(url('vehicles.php')) ?>" class="btn btn-accent">Browse Available Fleet</a>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-4">
            <?php foreach ($bookings as $b): ?>
                <div class="col-12">
                    <div class="card border shadow-sm">
                        <div class="card-body p-3 p-lg-4">
                            <div class="row g-3 align-items-center">
                                <div class="col-md-3">
                                    <img src="<?= e(url($b['primary_image'])) ?>" alt="<?= e($b['brand'] . ' ' . $b['model']) ?>" class="img-fluid rounded border w-100" style="max-height: 150px; object-fit: cover;">
                                </div>
                                <div class="col-md-6">
                                    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                        <span class="badge bg-dark font-monospace"><?= e($b['booking_reference']) ?></span>
                                        <span class="badge <?= booking_status_badge($b['booking_status']) ?>"><?= e(booking_status_label($b['booking_status'])) ?></span>
                                        <span class="badge <?= payment_status_badge($b['payment_status']) ?>">Payment: <?= e(strtoupper(str_replace('_', ' ', $b['payment_status']))) ?></span>
                                    </div>
                                    <h3 class="h5 fw-bold mb-1"><?= e($b['brand'] . ' ' . $b['model']) ?> <small class="text-muted fw-normal">(<?= e($b['registration_number']) ?>)</small></h3>
                                    <div class="small text-muted mb-2">
                                        <div><i class="bi bi-calendar-event text-orange me-1"></i><strong>Pickup:</strong> <?= e(date('d M Y, H:i', strtotime($b['pickup_datetime']))) ?> — <?= e($b['pickup_location_name']) ?></div>
                                        <div><i class="bi bi-calendar-check text-orange me-1"></i><strong>Return:</strong> <?= e(date('d M Y, H:i', strtotime($b['return_datetime']))) ?> — <?= e($b['return_location_name']) ?></div>
                                    </div>
                                    <div class="small">
                                        <span class="text-muted">Duration:</span> <strong><?= (int)$b['total_days'] ?> days</strong>
                                        <?php if ((int)$b['full_weeks'] > 0): ?>
                                            <span class="badge bg-success-subtle text-success border ms-1">Weekly Bundle Applied (<?= (int)$b['full_weeks'] ?>w + <?= (int)$b['remaining_days'] ?>d)</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-md-3 text-md-end border-top border-md-top-0 pt-3 pt-md-0">
                                    <div class="small text-muted">Total Rental Amount</div>
                                    <div class="fs-5 fw-bold text-dark"><?= e(format_lkr($b['total_amount_lkr'])) ?></div>
                                    <div class="small mb-3">
                                        <span class="text-success">Paid: <?= e(format_lkr($b['amount_paid_lkr'])) ?></span> &bull;
                                        <span class="text-danger fw-semibold">Due: <?= e(format_lkr($b['balance_due_lkr'])) ?></span>
                                    </div>
                                    <div class="d-grid gap-2">
                                        <a href="<?= e(url('customer/booking_details.php?id=' . (int)$b['id'])) ?>" class="btn btn-sm btn-accent">
                                            <i class="bi bi-eye me-1"></i>Manage / Payment / Receipt
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>


<?php
/**
 * Staff Operations Dashboard
 * Displays real-time counters for pending bookings, active road rentals,
 * confirmed pickups, and unresolved inquiries.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pricing.php';

$user = require_role(['staff', 'admin']);
$pdo  = Database::getConnection();

// Fetch KPI counts
$stats = [
    'pending_bookings'   => (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE booking_status = 'pending'")->fetchColumn(),
    'confirmed_bookings' => (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE booking_status = 'confirmed'")->fetchColumn(),
    'active_rentals'     => (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE booking_status = 'picked_up'")->fetchColumn(),
    'open_inquiries'     => (int)$pdo->query("SELECT COUNT(*) FROM inquiries WHERE status IN ('new', 'in_progress')")->fetchColumn(),
];

// Fetch recent actionable bookings
$actionableBookings = $pdo->query("
    SELECT b.*, u.full_name AS customer_name, u.phone AS customer_phone,
           v.brand, v.model, v.registration_number,
           pl.name AS pickup_location_name
    FROM bookings b
    JOIN users u ON u.id = b.customer_id
    JOIN vehicles v ON v.id = b.vehicle_id
    JOIN locations pl ON pl.id = b.pickup_location_id
    WHERE b.booking_status IN ('pending', 'confirmed', 'picked_up')
    ORDER BY FIELD(b.booking_status, 'pending', 'picked_up', 'confirmed'), b.pickup_datetime ASC
    LIMIT 10
")->fetchAll();

// Fetch recent open inquiries
$recentInquiries = $pdo->query("
    SELECT * FROM inquiries
    WHERE status IN ('new', 'in_progress')
    ORDER BY created_at DESC
    LIMIT 5
")->fetchAll();

$pageTitle = 'Staff Operations Portal';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="page-header-band">
    <div class="container d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <span class="text-orange fw-bold text-uppercase small">Negombo &amp; BIA Dispatch Console</span>
            <h1 class="fw-bold mb-1">Staff Operations Dashboard</h1>
            <p class="mb-0 text-light opacity-75">Manage reservations, verify deposits, record vehicle pickup/return inspections, and respond to customer inquiries.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= e(url('staff/bookings.php')) ?>" class="btn btn-accent">
                <i class="bi bi-calendar2-check me-1"></i>Manage All Bookings
            </a>
            <a href="<?= e(url('staff/inquiries.php')) ?>" class="btn btn-outline-light">
                <i class="bi bi-envelope-paper me-1"></i>Inquiries (<?= $stats['open_inquiries'] ?>)
            </a>
        </div>
    </div>
</section>

<div class="container pb-5">
    <!-- KPI Row -->
    <div class="row g-4 mb-4">
        <div class="col-sm-6 col-lg-3">
            <div class="kpi-card accent">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">Pending Confirmation</div>
                        <div class="fs-2 fw-bold text-dark"><?= $stats['pending_bookings'] ?></div>
                    </div>
                    <i class="bi bi-hourglass-split fs-2 text-orange"></i>
                </div>
                <a href="<?= e(url('staff/bookings.php?status=pending')) ?>" class="small text-decoration-none">Review Pending &rarr;</a>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="kpi-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">Confirmed (Ready for Pickup)</div>
                        <div class="fs-2 fw-bold text-primary"><?= $stats['confirmed_bookings'] ?></div>
                    </div>
                    <i class="bi bi-key fs-2 text-primary"></i>
                </div>
                <a href="<?= e(url('staff/bookings.php?status=confirmed')) ?>" class="small text-decoration-none">Dispatch Pickups &rarr;</a>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="kpi-card accent">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">Active Out on Road</div>
                        <div class="fs-2 fw-bold text-info"><?= $stats['active_rentals'] ?></div>
                    </div>
                    <i class="bi bi-speedometer2 fs-2 text-info"></i>
                </div>
                <a href="<?= e(url('staff/bookings.php?status=picked_up')) ?>" class="small text-decoration-none">Log Returns &rarr;</a>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="kpi-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">Open Customer Inquiries</div>
                        <div class="fs-2 fw-bold text-dark"><?= $stats['open_inquiries'] ?></div>
                    </div>
                    <i class="bi bi-chat-dots fs-2 text-orange"></i>
                </div>
                <a href="<?= e(url('staff/inquiries.php')) ?>" class="small text-decoration-none">Answer Inquiries &rarr;</a>
            </div>
        </div>
    </div>

    <!-- Actionable Bookings Queue -->
    <div class="card border shadow-sm mb-4">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h2 class="h5 fw-bold mb-0"><i class="bi bi-clipboard2-check text-orange me-2"></i>Priority Dispatch &amp; Reservation Queue</h2>
            <a href="<?= e(url('staff/bookings.php')) ?>" class="btn btn-sm btn-outline-navy">View Full Booking Log</a>
        </div>
        <div class="card-body p-0">
            <?php if (empty($actionableBookings)): ?>
                <div class="p-4 text-muted small">No pending, confirmed, or active bookings at the moment.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle small mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">Reference</th>
                                <th>Customer</th>
                                <th>Vehicle</th>
                                <th>Pickup / Return Window</th>
                                <th>Payment Status</th>
                                <th>Booking Status</th>
                                <th class="pe-3 text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($actionableBookings as $b): ?>
                                <tr>
                                    <td class="ps-3 font-monospace fw-bold"><?= e($b['booking_reference']) ?></td>
                                    <td>
                                        <strong><?= e($b['customer_name']) ?></strong><br>
                                        <span class="text-muted"><?= e($b['customer_phone']) ?></span>
                                    </td>
                                    <td>
                                        <strong><?= e($b['brand'] . ' ' . $b['model']) ?></strong><br>
                                        <code><?= e($b['registration_number']) ?></code>
                                    </td>
                                    <td>
                                        <div><strong>In:</strong> <?= e(date('d M Y H:i', strtotime($b['pickup_datetime']))) ?></div>
                                        <div><strong>Out:</strong> <?= e(date('d M Y H:i', strtotime($b['return_datetime']))) ?></div>
                                        <span class="text-muted"><?= e($b['pickup_location_name']) ?></span>
                                    </td>
                                    <td>
                                        <span class="badge <?= payment_status_badge($b['payment_status']) ?>">
                                            <?= e(strtoupper(str_replace('_', ' ', $b['payment_status']))) ?>
                                        </span>
                                        <div class="text-muted mt-1">Paid: <?= e(format_lkr($b['amount_paid_lkr'])) ?></div>
                                    </td>
                                    <td>
                                        <span class="badge <?= booking_status_badge($b['booking_status']) ?>">
                                            <?= e(booking_status_label($b['booking_status'])) ?>
                                        </span>
                                    </td>
                                    <td class="pe-3 text-end">
                                        <a href="<?= e(url('staff/bookings.php?manage_id=' . (int)$b['id'])) ?>" class="btn btn-sm btn-accent">
                                            <?php if ($b['booking_status'] === 'pending'): ?>
                                                Confirm / Reject
                                            <?php elseif ($b['booking_status'] === 'confirmed'): ?>
                                                Record Pickup
                                            <?php else: ?>
                                                Record Return
                                            <?php endif; ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Open Customer Inquiries -->
    <div class="card border shadow-sm">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h2 class="h5 fw-bold mb-0"><i class="bi bi-envelope-exclamation text-orange me-2"></i>Recent Open Inquiries</h2>
            <a href="<?= e(url('staff/inquiries.php')) ?>" class="btn btn-sm btn-outline-navy">Manage All Inquiries</a>
        </div>
        <div class="card-body p-0">
            <?php if (empty($recentInquiries)): ?>
                <div class="p-4 text-muted small">All customer inquiries have been resolved!</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle small mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">Sender</th>
                                <th>Subject &amp; Message</th>
                                <th>Status</th>
                                <th>Received</th>
                                <th class="pe-3 text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentInquiries as $inq): ?>
                                <tr>
                                    <td class="ps-3">
                                        <strong><?= e($inq['full_name']) ?></strong><br>
                                        <span class="text-muted"><?= e($inq['email']) ?></span>
                                    </td>
                                    <td>
                                        <strong><?= e($inq['subject']) ?></strong><br>
                                        <span class="text-muted"><?= e(mb_strimwidth($inq['message'], 0, 95, '...')) ?></span>
                                    </td>
                                    <td>
                                        <span class="badge <?= $inq['status'] === 'new' ? 'bg-warning text-dark' : 'bg-primary' ?>">
                                            <?= e(strtoupper(str_replace('_', ' ', $inq['status']))) ?>
                                        </span>
                                    </td>
                                    <td><?= e(date('d M Y H:i', strtotime($inq['created_at']))) ?></td>
                                    <td class="pe-3 text-end">
                                        <a href="<?= e(url('staff/inquiries.php?id=' . (int)$inq['id'])) ?>" class="btn btn-sm btn-outline-primary">Review &amp; Reply</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>


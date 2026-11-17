<?php
/**
 * Customer Booking Details, Online Payment Processing, Cancellation & Printable Receipt
 *
 * SECURITY:
 * - Customers can access ONLY their own bookings (`b.customer_id = :uid`).
 * - Staff and Administrators can also view this receipt/detail view when inspecting bookings.
 * - Payment processing and cancellation run inside database transactions with `FOR UPDATE` row locks.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pricing.php';

$user = require_login();
$pdo  = Database::getConnection();
$config = require __DIR__ . '/../config/config.php';

$bookingId = (int)($_GET['id'] ?? $_POST['booking_id'] ?? 0);

// Fetch booking and enforce ownership for customers
$sql = "
    SELECT b.*,
           u.full_name AS customer_name, u.email AS customer_email, u.phone AS customer_phone,
           u.nic_or_passport AS customer_nic, u.license_number AS customer_license,
           v.brand, v.model, v.year, v.registration_number, v.transmission, v.fuel_type, v.seats, v.primary_image,
           c.name AS category_name, c.excess_km_rate_lkr,
           pl.name AS pickup_location_name, pl.address AS pickup_location_address,
           rl.name AS return_location_name, rl.address AS return_location_address
    FROM bookings b
    JOIN users u ON u.id = b.customer_id
    JOIN vehicles v ON v.id = b.vehicle_id
    JOIN vehicle_categories c ON c.id = v.category_id
    JOIN locations pl ON pl.id = b.pickup_location_id
    JOIN locations rl ON rl.id = b.return_location_id
    WHERE b.id = :id
    LIMIT 1
";
$stmt = $pdo->prepare($sql);
$stmt->execute(['id' => $bookingId]);
$booking = $stmt->fetch();

if (!$booking) {
    set_flash('warning', 'Booking record not found.');
    redirect(role_home_path($user['role']));
}

// Strict ownership enforcement: a customer can NEVER view or modify another customer's booking
if ($user['role'] === 'customer' && (int)$booking['customer_id'] !== (int)$user['id']) {
    http_response_code(403);
    set_flash('danger', 'Access Denied: You can only view and manage your own bookings.');
    redirect('customer/bookings.php');
}

// Handle POST Actions: Payment Processing OR Customer Cancellation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'simulate_payment') {
        $paymentScope = $_POST['payment_scope'] ?? 'deposit'; // 'deposit', 'full_payment', 'balance_settlement'
        $paymentMethod = $_POST['payment_method'] ?? 'Online Card Gateway';
        $simOutcome = $_POST['simulation_outcome'] ?? 'approve'; // 'approve' or 'decline'

        if (!in_array($paymentMethod, ['Online Card Gateway', 'Bank Transfer Reference', 'Cash at Negombo Counter'], true)) {
            $paymentMethod = 'Online Card Gateway';
        }

        try {
            $pdo->beginTransaction();

            // Lock booking row to prevent duplicate concurrent payment submissions
            $lockStmt = $pdo->prepare("SELECT * FROM bookings WHERE id = :id FOR UPDATE");
            $lockStmt->execute(['id' => $bookingId]);
            $locked = $lockStmt->fetch();

            if (!$locked || in_array($locked['booking_status'], ['cancelled', 'rejected'], true)) {
                $pdo->rollBack();
                set_flash('danger', 'Payments cannot be processed for cancelled or rejected bookings.');
                redirect('customer/booking_details.php?id=' . $bookingId);
            }

            if ((float)$locked['balance_due_lkr'] <= 0.00 || $locked['payment_status'] === 'fully_paid') {
                $pdo->rollBack();
                set_flash('info', 'This booking is already fully paid. Duplicate payment prevented.');
                redirect('customer/booking_details.php?id=' . $bookingId);
            }

            // Determine payment amount based on current state
            $amountPaidSoFar = (float)$locked['amount_paid_lkr'];
            $depositRequired = (float)$locked['deposit_required_lkr'];
            $balanceDue      = (float)$locked['balance_due_lkr'];

            if ($amountPaidSoFar <= 0.0 && $paymentScope === 'deposit') {
                $payAmount   = min($depositRequired, $balanceDue);
                $paymentType = 'deposit';
            } elseif ($amountPaidSoFar <= 0.0 && $paymentScope === 'full_payment') {
                $payAmount   = $balanceDue;
                $paymentType = 'full_payment';
            } else {
                // Paying remaining balance
                $payAmount   = $balanceDue;
                $paymentType = 'balance_settlement';
            }

            $payRef = 'PAY-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $isSuccess = ($simOutcome === 'approve');
            $payStatus = $isSuccess ? 'succeeded' : 'failed';
            $scenarioLabel = $isSuccess
                ? 'Online Payment Authorization (Approved)'
                : 'Payment Declined / Insufficient Funds';

            // Insert payment record
            $insPay = $pdo->prepare("
                INSERT INTO payments (
                    payment_reference, booking_id, customer_id, payment_type,
                    payment_method, amount_lkr, payment_status, simulation_scenario, notes
                ) VALUES (
                    :ref, :bid, :cid, :ptype, :pmethod, :amt, :pstatus, :scenario, :notes
                )
            ");
            $insPay->execute([
                'ref'      => $payRef,
                'bid'      => $bookingId,
                'cid'      => (int)$locked['customer_id'],
                'ptype'    => $paymentType,
                'pmethod'  => $paymentMethod,
                'amt'      => $payAmount,
                'pstatus'  => $payStatus,
                'scenario' => $scenarioLabel,
                'notes'    => ucfirst(str_replace('_', ' ', $paymentType)) . ' for ' . $locked['booking_reference'],
            ]);

            if ($isSuccess) {
                $newPaid    = round($amountPaidSoFar + $payAmount, 2);
                $newBalance = max(0.00, round((float)$locked['total_amount_lkr'] - $newPaid, 2));
                $newPayStat = ($newBalance <= 0.00) ? 'fully_paid' : 'deposit_paid';

                $updBook = $pdo->prepare("
                    UPDATE bookings
                    SET amount_paid_lkr = :paid,
                        balance_due_lkr = :bal,
                        payment_status = :pstat
                    WHERE id = :id
                ");
                $updBook->execute([
                    'paid'  => $newPaid,
                    'bal'   => $newBalance,
                    'pstat' => $newPayStat,
                    'id'    => $bookingId,
                ]);

                $pdo->commit();
                set_flash('success', 'Payment ' . $payRef . ' (' . format_lkr($payAmount) . ') succeeded! Booking payment status updated to ' . strtoupper(str_replace('_', ' ', $newPayStat)) . '.');
            } else {
                // If booking had no prior payment, mark payment_status = 'failed'
                if ($amountPaidSoFar <= 0.0) {
                    $updBook = $pdo->prepare("UPDATE bookings SET payment_status = 'failed' WHERE id = :id");
                    $updBook->execute(['id' => $bookingId]);
                }
                $pdo->commit();
                set_flash('danger', 'Payment Declined (' . $payRef . '): The transaction could not be authorized. No funds were deducted and you may retry below.');
            }

            redirect('customer/booking_details.php?id=' . $bookingId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Database::logError('Payment processing error: ' . $e->getMessage());
            set_flash('danger', 'A database error occurred while processing the payment.');
            redirect('customer/booking_details.php?id=' . $bookingId);
        }
    } elseif ($action === 'cancel_booking') {
        $reason = trim($_POST['cancellation_reason'] ?? 'Customer requested cancellation via portal');

        try {
            $pdo->beginTransaction();
            $lockStmt = $pdo->prepare("SELECT * FROM bookings WHERE id = :id FOR UPDATE");
            $lockStmt->execute(['id' => $bookingId]);
            $locked = $lockStmt->fetch();

            if (!$locked || !PricingAndBookingService::canTransition($locked['booking_status'], 'cancelled')) {
                $pdo->rollBack();
                set_flash('danger', 'This booking cannot be cancelled in its current status (' . $locked['booking_status'] . ').');
                redirect('customer/booking_details.php?id=' . $bookingId);
            }

            // Check 24-hour cutoff rule for confirmed bookings
            $cutoffHours = (int)($config['cancellation_cutoff_hours'] ?? 24);
            $hoursUntilPickup = (strtotime($locked['pickup_datetime']) - time()) / 3600;

            if ($locked['booking_status'] === 'confirmed' && $hoursUntilPickup < $cutoffHours && $user['role'] === 'customer') {
                $pdo->rollBack();
                set_flash('danger', 'Policy Restriction: Confirmed bookings within ' . $cutoffHours . ' hours of scheduled pickup cannot be self-cancelled online. Please contact our Negombo dispatch desk.');
                redirect('customer/booking_details.php?id=' . $bookingId);
            }

            $newPaymentStatus = ((float)$locked['amount_paid_lkr'] > 0) ? 'refunded' : $locked['payment_status'];

            $upd = $pdo->prepare("
                UPDATE bookings
                SET booking_status = 'cancelled',
                    payment_status = :pstat,
                    cancellation_reason = :reason,
                    cancelled_at = NOW()
                WHERE id = :id
            ");
            $upd->execute([
                'pstat'  => $newPaymentStatus,
                'reason' => $reason !== '' ? $reason : 'Cancelled by customer',
                'id'     => $bookingId,
            ]);

            $pdo->commit();
            set_flash('info', 'Booking ' . $locked['booking_reference'] . ' has been cancelled and the vehicle dates have been released.');
            redirect('customer/booking_details.php?id=' . $bookingId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Database::logError('Cancel booking error: ' . $e->getMessage());
            set_flash('danger', 'Could not cancel booking due to a server error.');
            redirect('customer/booking_details.php?id=' . $bookingId);
        }
    }
}

// Reload fresh booking data, payments, and inspection record
$stmt->execute(['id' => $bookingId]);
$booking = $stmt->fetch();

$payStmt = $pdo->prepare("SELECT * FROM payments WHERE booking_id = :bid ORDER BY created_at DESC, id DESC");
$payStmt->execute(['bid' => $bookingId]);
$payments = $payStmt->fetchAll();

$inspStmt = $pdo->prepare("SELECT * FROM booking_inspections WHERE booking_id = :bid LIMIT 1");
$inspStmt->execute(['bid' => $bookingId]);
$inspection = $inspStmt->fetch();

$cutoffHours = (int)($config['cancellation_cutoff_hours'] ?? 24);
$hoursUntilPickup = (strtotime($booking['pickup_datetime']) - time()) / 3600;
$canSelfCancel = in_array($booking['booking_status'], ['pending', 'confirmed'], true)
    && ($booking['booking_status'] === 'pending' || $hoursUntilPickup >= $cutoffHours || $user['role'] !== 'customer');

$pageTitle = 'Booking ' . $booking['booking_reference'];
require_once __DIR__ . '/../includes/header.php';
?>

<section class="page-header-band no-print">
    <div class="container d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <span class="text-orange fw-bold text-uppercase small">Reservation &amp; Receipt</span>
            <h1 class="fw-bold mb-1">Booking <?= e($booking['booking_reference']) ?></h1>
            <p class="mb-0 text-light opacity-75">Created on <?= e(date('d M Y, H:i', strtotime($booking['created_at']))) ?></p>
        </div>
        <div class="d-flex gap-2">
            <button type="button" onclick="window.print()" class="btn btn-light">
                <i class="bi bi-printer me-1"></i>Print Official Receipt
            </button>
            <a href="<?= e(url($user['role'] === 'customer' ? 'customer/bookings.php' : 'staff/bookings.php')) ?>" class="btn btn-outline-light">
                <i class="bi bi-arrow-left me-1"></i>Back to Bookings
            </a>
        </div>
    </div>
</section>

<div class="container pb-5">
    <div class="row g-4">
        <!-- Left Column: Printable Receipt & Inspection Breakdown -->
        <div class="col-lg-8">
            <div class="card border shadow-sm receipt-box mb-4">
                <div class="card-body p-4">
                    <!-- Receipt Header -->
                    <div class="d-flex flex-wrap justify-content-between align-items-start border-bottom pb-3 mb-4">
                        <div>
                            <h2 class="h4 fw-bold mb-1">SwiftDrive Rentals (Pvt) Ltd</h2>
                            <div class="small text-muted">
                                No. 148, Poruthota Road, Ettukala, Negombo, Sri Lanka<br>
                                Tel: +94 31 222 8890 &bull; reservations@swiftdrive.lk
                            </div>
                        </div>
                        <div class="text-sm-end mt-3 mt-sm-0">
                            <div class="small text-muted">Booking Reference</div>
                            <div class="fs-5 fw-bold font-monospace"><?= e($booking['booking_reference']) ?></div>
                            <div class="mt-1">
                                <span class="badge <?= booking_status_badge($booking['booking_status']) ?>"><?= e(booking_status_label($booking['booking_status'])) ?></span>
                                <span class="badge <?= payment_status_badge($booking['payment_status']) ?>"><?= e(strtoupper(str_replace('_', ' ', $booking['payment_status']))) ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Customer & Vehicle Summary -->
                    <div class="row g-4 mb-4">
                        <div class="col-sm-6">
                            <h3 class="h6 fw-bold text-uppercase text-muted small">Hirer Details</h3>
                            <div><strong><?= e($booking['customer_name']) ?></strong></div>
                            <div class="small text-muted"><?= e($booking['customer_email']) ?></div>
                            <div class="small text-muted">Tel: <?= e($booking['customer_phone']) ?></div>
                            <div class="small text-muted">NIC/Passport: <?= e($booking['customer_nic'] ?: 'Verified at Counter') ?></div>
                            <div class="small text-muted">Licence No: <?= e($booking['customer_license'] ?: 'Verified at Counter') ?></div>
                        </div>
                        <div class="col-sm-6">
                            <h3 class="h6 fw-bold text-uppercase text-muted small">Reserved Vehicle</h3>
                            <div><strong><?= e($booking['brand'] . ' ' . $booking['model']) ?> (<?= (int)$booking['year'] ?>)</strong></div>
                            <div class="small text-muted">Category: <?= e($booking['category_name']) ?> &bull; Reg: <code><?= e($booking['registration_number']) ?></code></div>
                            <div class="small text-muted"><?= e($booking['transmission']) ?> &bull; <?= e($booking['fuel_type']) ?> &bull; <?= (int)$booking['seats'] ?> Seats</div>
                            <div class="small text-muted">Included Mileage: <?= number_format((int)$booking['total_days'] * 150) ?> km (150 km/day)</div>
                        </div>
                    </div>

                    <!-- Schedule & Locations -->
                    <div class="row g-3 bg-light rounded p-3 border mb-4 small">
                        <div class="col-md-6">
                            <strong class="text-dark d-block"><i class="bi bi-box-arrow-up-right text-orange me-1"></i>Scheduled Pickup</strong>
                            <div><?= e(date('l, d M Y — H:i', strtotime($booking['pickup_datetime']))) ?></div>
                            <div class="text-muted"><?= e($booking['pickup_location_name']) ?></div>
                        </div>
                        <div class="col-md-6">
                            <strong class="text-dark d-block"><i class="bi bi-box-arrow-in-down-left text-orange me-1"></i>Scheduled Return</strong>
                            <div><?= e(date('l, d M Y — H:i', strtotime($booking['return_datetime']))) ?></div>
                            <div class="text-muted"><?= e($booking['return_location_name']) ?></div>
                        </div>
                    </div>

                    <!-- Itemized Financial Breakdown -->
                    <h3 class="h6 fw-bold mb-3">Itemized LKR Financial Breakdown</h3>
                    <div class="table-responsive">
                        <table class="table table-bordered small align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Description</th>
                                    <th>Calculation Basis</th>
                                    <th class="text-end">Amount (LKR)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><strong>Base Vehicle Rental</strong></td>
                                    <td>
                                        <?= (int)$booking['total_days'] ?> billed day(s)
                                        <?php if ((int)$booking['full_weeks'] > 0): ?>
                                            — <strong><?= (int)$booking['full_weeks'] ?> wk(s)</strong> @ <?= e(format_lkr($booking['weekly_rate_snapshot_lkr'])) ?>
                                            <?php if ((int)$booking['remaining_days'] > 0): ?>
                                                + <strong><?= (int)$booking['remaining_days'] ?> day(s)</strong> @ <?= e(format_lkr($booking['daily_rate_snapshot_lkr'])) ?>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            @ <?= e(format_lkr($booking['daily_rate_snapshot_lkr'])) ?> / day
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end"><?= e(format_lkr($booking['base_rental_lkr'])) ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Location Handover Surcharge</strong></td>
                                    <td>Pickup &amp; Return Hub Logistics</td>
                                    <td class="text-end"><?= e(format_lkr($booking['location_surcharge_lkr'])) ?></td>
                                </tr>
                                <?php if ((float)$booking['late_penalty_lkr'] > 0): ?>
                                    <tr class="table-warning">
                                        <td><strong>Late-Return Penalty</strong></td>
                                        <td><?= $inspection ? (int)$inspection['late_hours'] . ' hour(s) beyond 1h grace @ 15% daily rate/hr' : 'Late return charge' ?></td>
                                        <td class="text-end"><?= e(format_lkr($booking['late_penalty_lkr'])) ?></td>
                                    </tr>
                                <?php endif; ?>
                                <?php if ((float)$booking['extra_charges_lkr'] > 0): ?>
                                    <tr class="table-warning">
                                        <td><strong>Return Inspection Extras (Mileage / Fuel / Damage)</strong></td>
                                        <td>
                                            <?php if ($inspection): ?>
                                                Excess <?= (int)$inspection['excess_km'] ?> km (<?= e(format_lkr($inspection['excess_km_charge_lkr'])) ?>)
                                                + Fuel deficit <?= (int)$inspection['fuel_deficit_eighths'] ?>/8 (<?= e(format_lkr($inspection['fuel_charge_lkr'])) ?>)
                                                <?php if ((float)$inspection['damage_fee_lkr'] > 0): ?>
                                                    + Damage (<?= e(format_lkr($inspection['damage_fee_lkr'])) ?>)
                                                <?php endif; ?>
                                            <?php else: ?>
                                                Post-rental adjustments
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end"><?= e(format_lkr($booking['extra_charges_lkr'])) ?></td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <th colspan="2" class="text-end">Total Rental Amount:</th>
                                    <th class="text-end fs-6"><?= e(format_lkr($booking['total_amount_lkr'])) ?></th>
                                </tr>
                                <tr>
                                    <th colspan="2" class="text-end text-primary">Required 25% Reservation Deposit:</th>
                                    <th class="text-end text-primary"><?= e(format_lkr($booking['deposit_required_lkr'])) ?></th>
                                </tr>
                                <tr>
                                    <th colspan="2" class="text-end text-success">Total Amount Paid to Date:</th>
                                    <th class="text-end text-success"><?= e(format_lkr($booking['amount_paid_lkr'])) ?></th>
                                </tr>
                                <tr>
                                    <th colspan="2" class="text-end text-danger">Remaining Balance Due:</th>
                                    <th class="text-end text-danger fs-6"><?= e(format_lkr($booking['balance_due_lkr'])) ?></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <?php if (!empty($booking['customer_notes']) || !empty($booking['staff_notes']) || !empty($booking['cancellation_reason'])): ?>
                        <div class="mt-3 small">
                            <?php if (!empty($booking['customer_notes'])): ?>
                                <div class="mb-1"><strong>Customer Notes:</strong> <?= e($booking['customer_notes']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($booking['staff_notes'])): ?>
                                <div class="mb-1 text-primary"><strong>Staff Dispatch Notes:</strong> <?= e($booking['staff_notes']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($booking['cancellation_reason'])): ?>
                                <div class="text-danger"><strong>Cancellation / Rejection Note:</strong> <?= e($booking['cancellation_reason']) ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Pickup & Return Vehicle Condition Log (if recorded) -->
            <?php if ($inspection): ?>
                <div class="card border shadow-sm mb-4">
                    <div class="card-header bg-white py-3">
                        <h3 class="h6 fw-bold mb-0"><i class="bi bi-clipboard2-pulse text-orange me-2"></i>Official Pickup &amp; Return Vehicle Inspection Record</h3>
                    </div>
                    <div class="card-body p-4 small">
                        <div class="row g-4">
                            <div class="col-md-6 border-end">
                                <h4 class="h6 fw-bold text-primary">Pickup Handover Log</h4>
                                <div><strong>Actual Handover:</strong> <?= $inspection['pickup_actual_at'] ? e(date('d M Y, H:i', strtotime($inspection['pickup_actual_at']))) : 'Pending' ?></div>
                                <div><strong>Odometer at Pickup:</strong> <?= $inspection['pickup_mileage_km'] !== null ? number_format((int)$inspection['pickup_mileage_km']) . ' km' : '—' ?></div>
                                <div><strong>Fuel Level at Pickup:</strong> <?= e(fuel_eighths_label($inspection['pickup_fuel_eighths'] !== null ? (int)$inspection['pickup_fuel_eighths'] : null)) ?></div>
                                <div class="mt-2"><strong>Condition Notes:</strong><br><?= e($inspection['pickup_condition_notes'] ?: 'No issues noted.') ?></div>
                            </div>
                            <div class="col-md-6">
                                <h4 class="h6 fw-bold text-success">Return Inspection Log</h4>
                                <?php if ($inspection['return_actual_at']): ?>
                                    <div><strong>Actual Return:</strong> <?= e(date('d M Y, H:i', strtotime($inspection['return_actual_at']))) ?></div>
                                    <div><strong>Odometer at Return:</strong> <?= number_format((int)$inspection['return_mileage_km']) ?> km (Driven: <?= number_format((int)$inspection['return_mileage_km'] - (int)$inspection['pickup_mileage_km']) ?> km)</div>
                                    <div><strong>Fuel Level at Return:</strong> <?= e(fuel_eighths_label((int)$inspection['return_fuel_eighths'])) ?></div>
                                    <div class="mt-2"><strong>Condition Notes:</strong><br><?= e($inspection['return_condition_notes'] ?: 'Returned in good order.') ?></div>
                                <?php else: ?>
                                    <p class="text-muted mb-0">Vehicle is currently out on active rental. Return inspection will be logged upon drop-off.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Payment Transaction History -->
            <div class="card border shadow-sm">
                <div class="card-header bg-white py-3">
                    <h3 class="h6 fw-bold mb-0"><i class="bi bi-credit-card-2-front text-orange me-2"></i>Payment &amp; Deposit Transaction Log</h3>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($payments)): ?>
                        <div class="p-4 text-muted small">No payment attempts recorded for this booking yet.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover small align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th class="ps-3">Reference</th>
                                        <th>Type</th>
                                        <th>Method &amp; Details</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th class="pe-3">Timestamp</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($payments as $p): ?>
                                        <tr>
                                            <td class="ps-3 font-monospace"><?= e($p['payment_reference']) ?></td>
                                            <td><?= e(ucfirst(str_replace('_', ' ', $p['payment_type']))) ?></td>
                                            <td>
                                                <strong><?= e($p['payment_method']) ?></strong><br>
                                                <span class="text-muted"><?= e($p['simulation_scenario']) ?></span>
                                            </td>
                                            <td class="fw-semibold"><?= e(format_lkr($p['amount_lkr'])) ?></td>
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
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right Column: Online Payment Portal & Cancellation Controls -->
        <div class="col-lg-4 no-print">
            <!-- Online Payment Card -->
            <div class="card border shadow-sm mb-4">
                <div class="card-header bg-navy text-white py-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <h3 class="h6 fw-bold mb-0"><i class="bi bi-shield-check text-orange me-2"></i>Online Payment Portal</h3>
                        <span class="badge bg-success">Secure Checkout</span>
                    </div>
                </div>
                <div class="card-body p-4">
                    <p class="text-muted small mb-3">
                        Select your payment amount and channel below to pay your 25% reservation deposit or settle your remaining balance.
                    </p>

                    <?php if (in_array($booking['booking_status'], ['cancelled', 'rejected'], true)): ?>
                        <div class="alert alert-secondary small mb-0">
                            This booking is <strong><?= e($booking['booking_status']) ?></strong>; payments are disabled.
                        </div>
                    <?php elseif ((float)$booking['balance_due_lkr'] <= 0.00): ?>
                        <div class="alert alert-success small mb-0">
                            <i class="bi bi-check-circle-fill me-1"></i>
                            <strong>Fully Settled!</strong> All rental charges (<?= e(format_lkr($booking['total_amount_lkr'])) ?>) have been paid.
                        </div>
                    <?php else: ?>
                        <form action="<?= e(url('customer/booking_details.php?id=' . $bookingId)) ?>" method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="simulate_payment">

                            <div class="mb-3">
                                <label for="payment_scope" class="form-label fw-semibold small">Select Payment Amount</label>
                                <select id="payment_scope" name="payment_scope" class="form-select form-select-sm">
                                    <?php if ((float)$booking['amount_paid_lkr'] <= 0.00): ?>
                                        <option value="deposit">
                                            25% Reservation Deposit (<?= e(format_lkr($booking['deposit_required_lkr'])) ?>)
                                        </option>
                                        <option value="full_payment">
                                            Full 100% Upfront Payment (<?= e(format_lkr($booking['balance_due_lkr'])) ?>)
                                        </option>
                                    <?php else: ?>
                                        <option value="balance_settlement">
                                            Remaining Balance Settlement (<?= e(format_lkr($booking['balance_due_lkr'])) ?>)
                                        </option>
                                    <?php endif; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label for="payment_method" class="form-label fw-semibold small">Payment Channel</label>
                                <select id="payment_method" name="payment_method" class="form-select form-select-sm">
                                    <option value="Online Card Gateway">Online Card Gateway (Visa / Master)</option>
                                    <option value="Bank Transfer Reference">Online Bank Transfer (LankaQR / CEFT)</option>
                                    <option value="Cash at Negombo Counter">Counter Cash / POS Deposit</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold small d-block">Payment Authorization</label>
                                <div class="form-check mb-1">
                                    <input class="form-check-input" type="radio" name="simulation_outcome" id="sim_approve" value="approve" checked>
                                    <label class="form-check-label small text-success fw-semibold" for="sim_approve">
                                        Authorize Payment (Approved)
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="simulation_outcome" id="sim_decline" value="decline">
                                    <label class="form-check-label small text-danger fw-semibold" for="sim_decline">
                                        Decline Payment (Insufficient Funds)
                                    </label>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-accent w-100 py-2">
                                <i class="bi bi-lock-fill me-1"></i>Process Payment
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Booking Cancellation Box -->
            <div class="card border shadow-sm">
                <div class="card-header bg-white py-3">
                    <h3 class="h6 fw-bold mb-0"><i class="bi bi-x-octagon text-danger me-2"></i>Cancellation Policy &amp; Control</h3>
                </div>
                <div class="card-body p-4 small">
                    <p class="text-muted mb-3">
                        Bookings in <code>pending</code> status may be cancelled at any time. Confirmed bookings may be self-cancelled up to <strong><?= $cutoffHours ?> hours prior to scheduled pickup</strong>.
                    </p>

                    <?php if ($canSelfCancel): ?>
                        <form action="<?= e(url('customer/booking_details.php?id=' . $bookingId)) ?>" method="post" onsubmit="return confirm('Are you sure you want to cancel this reservation?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="cancel_booking">
                            <div class="mb-3">
                                <label for="cancellation_reason" class="form-label fw-semibold">Reason for Cancellation</label>
                                <input type="text" id="cancellation_reason" name="cancellation_reason" class="form-control form-control-sm" placeholder="e.g., Flight rescheduled / Change of travel plans" required>
                            </div>
                            <button type="submit" class="btn btn-outline-danger btn-sm w-100">
                                <i class="bi bi-x-circle me-1"></i>Cancel Reservation
                            </button>
                        </form>
                    <?php else: ?>
                        <div class="alert alert-secondary mb-0">
                            <?php if (in_array($booking['booking_status'], ['cancelled', 'rejected', 'completed', 'picked_up'], true)): ?>
                                This reservation is currently <strong><?= e(booking_status_label($booking['booking_status'])) ?></strong> and cannot be cancelled.
                            <?php else: ?>
                                Self-cancellation is locked because scheduled pickup is within <?= $cutoffHours ?> hours. Please call our Negombo desk (+94 31 222 8890).
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>


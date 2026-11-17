<?php
/**
 * Staff Booking Management & Pickup/Return Inspection Console
 * Enforces strict booking status transition rules:
 * - pending   -> confirmed | rejected | cancelled
 * - confirmed -> picked_up (requires odometer mileage, fuel level 1/8..8/8, condition notes) | cancelled
 * - picked_up -> completed (records return mileage, fuel level, condition notes, and automatically
 *                computes late-return penalties, excess mileage charges, and fuel deficit charges)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pricing.php';

$user = require_role(['staff', 'admin']);
$pdo  = Database::getConnection();

// Handle POST state transitions & inspections
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $bookingId = (int)($_POST['booking_id'] ?? 0);
    $action    = $_POST['action'] ?? '';

    try {
        $pdo->beginTransaction();

        // Lock booking row + join category excess km rate
        $bStmt = $pdo->prepare("
            SELECT b.*, v.mileage_km AS vehicle_current_mileage, c.excess_km_rate_lkr
            FROM bookings b
            JOIN vehicles v ON v.id = b.vehicle_id
            JOIN vehicle_categories c ON c.id = v.category_id
            WHERE b.id = :id
            FOR UPDATE
        ");
        $bStmt->execute(['id' => $bookingId]);
        $booking = $bStmt->fetch();

        if (!$booking) {
            $pdo->rollBack();
            set_flash('danger', 'Booking record not found.');
            redirect('staff/bookings.php');
        }

        if ($action === 'confirm_booking') {
            if (!PricingAndBookingService::canTransition($booking['booking_status'], 'confirmed')) {
                $pdo->rollBack();
                set_flash('danger', 'Invalid Status Transition: Cannot confirm a booking currently in "' . $booking['booking_status'] . '" status.');
                redirect('staff/bookings.php?manage_id=' . $bookingId);
            }

            // Re-verify no overlapping booking on the same vehicle before confirming
            if (PricingAndBookingService::hasOverlappingBooking($pdo, (int)$booking['vehicle_id'], $booking['pickup_datetime'], $booking['return_datetime'], $bookingId)) {
                $pdo->rollBack();
                set_flash('danger', 'Cannot confirm booking: Another reservation overlaps these dates for this vehicle.');
                redirect('staff/bookings.php?manage_id=' . $bookingId);
            }

            $staffNotes = trim($_POST['staff_notes'] ?? '');
            $upd = $pdo->prepare("
                UPDATE bookings
                SET booking_status = 'confirmed',
                    confirmed_by = :staff_id,
                    staff_notes = :notes
                WHERE id = :id
            ");
            $upd->execute([
                'staff_id' => (int)$user['id'],
                'notes'    => $staffNotes !== '' ? $staffNotes : $booking['staff_notes'],
                'id'       => $bookingId,
            ]);

            $pdo->commit();
            set_flash('success', 'Booking ' . $booking['booking_reference'] . ' has been CONFIRMED.');
            redirect('staff/bookings.php?manage_id=' . $bookingId);
        } elseif ($action === 'reject_booking') {
            if (!PricingAndBookingService::canTransition($booking['booking_status'], 'rejected')) {
                $pdo->rollBack();
                set_flash('danger', 'Invalid Status Transition: Only pending bookings can be rejected (current status: ' . $booking['booking_status'] . ').');
                redirect('staff/bookings.php?manage_id=' . $bookingId);
            }

            $reason = trim($_POST['cancellation_reason'] ?? '');
            if ($reason === '') {
                $pdo->rollBack();
                set_flash('danger', 'Please provide a rejection reason for the customer.');
                redirect('staff/bookings.php?manage_id=' . $bookingId);
            }

            $newPayStat = ((float)$booking['amount_paid_lkr'] > 0) ? 'refunded' : $booking['payment_status'];
            $upd = $pdo->prepare("
                UPDATE bookings
                SET booking_status = 'rejected',
                    payment_status = :pstat,
                    cancellation_reason = :reason,
                    cancelled_at = NOW(),
                    confirmed_by = :staff_id
                WHERE id = :id
            ");
            $upd->execute([
                'pstat'    => $newPayStat,
                'reason'   => $reason,
                'staff_id' => (int)$user['id'],
                'id'       => $bookingId,
            ]);

            $pdo->commit();
            set_flash('warning', 'Booking ' . $booking['booking_reference'] . ' was REJECTED and its dates have been released.');
            redirect('staff/bookings.php?manage_id=' . $bookingId);
        } elseif ($action === 'record_pickup') {
            if (!PricingAndBookingService::canTransition($booking['booking_status'], 'picked_up')) {
                $pdo->rollBack();
                set_flash('danger', 'Invalid Status Transition: Vehicle handover (pickup) can only be recorded for CONFIRMED bookings (current status: ' . $booking['booking_status'] . ').');
                redirect('staff/bookings.php?manage_id=' . $bookingId);
            }

            $pickupMileage = (int)($_POST['pickup_mileage_km'] ?? 0);
            $pickupFuel    = (int)($_POST['pickup_fuel_eighths'] ?? 8);
            $pickupNotes   = trim($_POST['pickup_condition_notes'] ?? '');
            $pickupActual  = trim($_POST['pickup_actual_at'] ?? '') ?: date('Y-m-d H:i:s');

            if ($pickupMileage <= 0) {
                $pdo->rollBack();
                set_flash('danger', 'Please enter a valid positive odometer reading (km) at pickup.');
                redirect('staff/bookings.php?manage_id=' . $bookingId);
            }
            if ($pickupFuel < 1 || $pickupFuel > 8) {
                $pdo->rollBack();
                set_flash('danger', 'Fuel level must be between 1/8 and 8/8.');
                redirect('staff/bookings.php?manage_id=' . $bookingId);
            }

            $actualSql = (new DateTimeImmutable($pickupActual))->format('Y-m-d H:i:s');
            $includedAllowance = (int)$booking['total_days'] * 150;

            // Upsert booking_inspections row
            $insStmt = $pdo->prepare("
                INSERT INTO booking_inspections (
                    booking_id, pickup_recorded_by, pickup_actual_at,
                    pickup_mileage_km, pickup_fuel_eighths, pickup_condition_notes,
                    included_km_allowance
                ) VALUES (
                    :bid, :staff_id, :actual_at, :mileage, :fuel, :notes, :allowance
                )
                ON DUPLICATE KEY UPDATE
                    pickup_recorded_by = VALUES(pickup_recorded_by),
                    pickup_actual_at = VALUES(pickup_actual_at),
                    pickup_mileage_km = VALUES(pickup_mileage_km),
                    pickup_fuel_eighths = VALUES(pickup_fuel_eighths),
                    pickup_condition_notes = VALUES(pickup_condition_notes),
                    included_km_allowance = VALUES(included_km_allowance)
            ");
            $insStmt->execute([
                'bid'       => $bookingId,
                'staff_id'  => (int)$user['id'],
                'actual_at' => $actualSql,
                'mileage'   => $pickupMileage,
                'fuel'      => $pickupFuel,
                'notes'     => $pickupNotes !== '' ? $pickupNotes : 'Standard handover inspection completed.',
                'allowance' => $includedAllowance,
            ]);

            // Update booking status and vehicle odometer
            $updB = $pdo->prepare("UPDATE bookings SET booking_status = 'picked_up' WHERE id = :id");
            $updB->execute(['id' => $bookingId]);

            $updV = $pdo->prepare("UPDATE vehicles SET mileage_km = GREATEST(mileage_km, :m) WHERE id = :vid");
            $updV->execute(['m' => $pickupMileage, 'vid' => (int)$booking['vehicle_id']]);

            $pdo->commit();
            set_flash('success', 'Vehicle pickup recorded for ' . $booking['booking_reference'] . '! Odometer (' . number_format($pickupMileage) . ' km) and fuel (' . $pickupFuel . '/8) saved.');
            redirect('staff/bookings.php?manage_id=' . $bookingId);
        } elseif ($action === 'record_return') {
            if (!PricingAndBookingService::canTransition($booking['booking_status'], 'completed')) {
                $pdo->rollBack();
                set_flash('danger', 'Invalid Status Transition: Vehicle return can only be recorded for bookings in PICKED_UP status (current status: ' . $booking['booking_status'] . ').');
                redirect('staff/bookings.php?manage_id=' . $bookingId);
            }

            // Fetch existing pickup inspection record
            $iStmt = $pdo->prepare("SELECT * FROM booking_inspections WHERE booking_id = :bid LIMIT 1");
            $iStmt->execute(['bid' => $bookingId]);
            $insp = $iStmt->fetch();

            if (!$insp || $insp['pickup_mileage_km'] === null) {
                $pdo->rollBack();
                set_flash('danger', 'Pickup inspection record is missing for this booking.');
                redirect('staff/bookings.php?manage_id=' . $bookingId);
            }

            $returnMileage = (int)($_POST['return_mileage_km'] ?? 0);
            $returnFuel    = (int)($_POST['return_fuel_eighths'] ?? 8);
            $returnNotes   = trim($_POST['return_condition_notes'] ?? '');
            $returnActual  = trim($_POST['return_actual_at'] ?? '') ?: date('Y-m-d H:i:s');
            $damageFee     = max(0.0, (float)($_POST['damage_fee_lkr'] ?? 0));
            $settleBalance = isset($_POST['settle_final_balance']) && $_POST['settle_final_balance'] === '1';

            if ($returnMileage < (int)$insp['pickup_mileage_km']) {
                $pdo->rollBack();
                set_flash('danger', 'Return odometer reading (' . number_format($returnMileage) . ' km) cannot be less than the pickup odometer reading (' . number_format((int)$insp['pickup_mileage_km']) . ' km).');
                redirect('staff/bookings.php?manage_id=' . $bookingId);
            }
            if ($returnFuel < 1 || $returnFuel > 8) {
                $pdo->rollBack();
                set_flash('danger', 'Return fuel level must be between 1/8 and 8/8.');
                redirect('staff/bookings.php?manage_id=' . $bookingId);
            }

            $actualReturnSql = (new DateTimeImmutable($returnActual))->format('Y-m-d H:i:s');

            // Calculate late return penalty, excess mileage, and fuel refill charges
            $charges = PricingAndBookingService::calculateReturnInspectionCharges(
                $booking,
                (int)$insp['pickup_mileage_km'],
                $returnMileage,
                (int)$insp['pickup_fuel_eighths'],
                $returnFuel,
                $actualReturnSql,
                (float)$booking['excess_km_rate_lkr'],
                $damageFee
            );

            // Update booking_inspections
            $updInsp = $pdo->prepare("
                UPDATE booking_inspections
                SET return_recorded_by = :staff_id,
                    return_actual_at = :return_at,
                    return_mileage_km = :ret_km,
                    return_fuel_eighths = :ret_fuel,
                    return_condition_notes = :ret_notes,
                    included_km_allowance = :allowance,
                    excess_km = :excess_km,
                    excess_km_charge_lkr = :excess_charge,
                    fuel_deficit_eighths = :fuel_def,
                    fuel_charge_lkr = :fuel_charge,
                    late_hours = :late_hrs,
                    late_penalty_lkr = :late_pen,
                    damage_fee_lkr = :dmg_fee
                WHERE booking_id = :bid
            ");
            $updInsp->execute([
                'staff_id'      => (int)$user['id'],
                'return_at'     => $actualReturnSql,
                'ret_km'        => $returnMileage,
                'ret_fuel'      => $returnFuel,
                'ret_notes'     => $returnNotes !== '' ? $returnNotes : 'Returned and inspected.',
                'allowance'     => $charges['included_km_allowance'],
                'excess_km'     => $charges['excess_km'],
                'excess_charge' => $charges['excess_km_charge_lkr'],
                'fuel_def'      => $charges['fuel_deficit_eighths'],
                'fuel_charge'   => $charges['fuel_charge_lkr'],
                'late_hrs'      => $charges['late_hours'],
                'late_pen'      => $charges['late_penalty_lkr'],
                'dmg_fee'       => $charges['damage_fee_lkr'],
                'bid'           => $bookingId,
            ]);

            // Recalculate total booking amount with late penalty and extra charges
            $newTotal = round(
                (float)$booking['base_rental_lkr']
                + (float)$booking['location_surcharge_lkr']
                + $charges['late_penalty_lkr']
                + $charges['extra_charges_lkr'],
                2
            );
            $paidSoFar  = (float)$booking['amount_paid_lkr'];
            $newBalance = max(0.0, round($newTotal - $paidSoFar, 2));
            $newPayStat = ($newBalance <= 0.0) ? 'fully_paid' : ($paidSoFar > 0 ? 'deposit_paid' : 'unpaid');

            // If staff ticked "Collect final balance at counter now", record counter settlement payment
            if ($settleBalance && $newBalance > 0.0) {
                $payRef = 'PAY-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(3)));
                $insPay = $pdo->prepare("
                    INSERT INTO payments (
                        payment_reference, booking_id, customer_id, payment_type,
                        payment_method, amount_lkr, payment_status, simulation_scenario, notes
                    ) VALUES (
                        :ref, :bid, :cid, 'balance_settlement',
                        'Cash at Negombo Counter', :amt, 'succeeded',
                        'Counter Return Settlement Logged by Staff', :notes
                    )
                ");
                $insPay->execute([
                    'ref'   => $payRef,
                    'bid'   => $bookingId,
                    'cid'   => (int)$booking['customer_id'],
                    'amt'   => $newBalance,
                    'notes' => 'Final settlement collected at vehicle return by ' . $user['full_name'],
                ]);
                $paidSoFar  = $newTotal;
                $newBalance = 0.00;
                $newPayStat = 'fully_paid';
            }

            $updBook = $pdo->prepare("
                UPDATE bookings
                SET booking_status = 'completed',
                    late_penalty_lkr = :late_pen,
                    extra_charges_lkr = :extras,
                    total_amount_lkr = :total,
                    amount_paid_lkr = :paid,
                    balance_due_lkr = :bal,
                    payment_status = :pstat
                WHERE id = :id
            ");
            $updBook->execute([
                'late_pen' => $charges['late_penalty_lkr'],
                'extras'   => $charges['extra_charges_lkr'],
                'total'    => $newTotal,
                'paid'     => $paidSoFar,
                'bal'      => $newBalance,
                'pstat'    => $newPayStat,
                'id'       => $bookingId,
            ]);

            // Update vehicle mileage
            $updV = $pdo->prepare("UPDATE vehicles SET mileage_km = GREATEST(mileage_km, :m) WHERE id = :vid");
            $updV->execute(['m' => $returnMileage, 'vid' => (int)$booking['vehicle_id']]);

            $pdo->commit();
            set_flash(
                'success',
                'Vehicle return completed for ' . $booking['booking_reference'] . '! Distance driven: '
                . number_format($charges['distance_driven_km']) . ' km, Late Penalty: '
                . format_lkr($charges['late_penalty_lkr']) . ', Extra Charges: '
                . format_lkr($charges['extra_charges_lkr']) . '.'
            );
            redirect('staff/bookings.php?manage_id=' . $bookingId);
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        Database::logError('Staff booking action error: ' . $e->getMessage());
        set_flash('danger', 'An unexpected database error occurred while updating the booking.');
        redirect('staff/bookings.php');
    }
}

// Fetch selected booking to manage if `manage_id` is passed
$manageId = (int)($_GET['manage_id'] ?? 0);
$managedBooking = null;
$managedInspection = null;

if ($manageId > 0) {
    $mStmt = $pdo->prepare("
        SELECT b.*,
               u.full_name AS customer_name, u.email AS customer_email, u.phone AS customer_phone,
               u.nic_or_passport AS customer_nic, u.license_number AS customer_license,
               v.brand, v.model, v.registration_number, v.mileage_km AS vehicle_current_mileage,
               c.name AS category_name, c.excess_km_rate_lkr,
               pl.name AS pickup_location_name, rl.name AS return_location_name
        FROM bookings b
        JOIN users u ON u.id = b.customer_id
        JOIN vehicles v ON v.id = b.vehicle_id
        JOIN vehicle_categories c ON c.id = v.category_id
        JOIN locations pl ON pl.id = b.pickup_location_id
        JOIN locations rl ON rl.id = b.return_location_id
        WHERE b.id = :id
        LIMIT 1
    ");
    $mStmt->execute(['id' => $manageId]);
    $managedBooking = $mStmt->fetch();

    if ($managedBooking) {
        $iStmt = $pdo->prepare("SELECT * FROM booking_inspections WHERE booking_id = :bid LIMIT 1");
        $iStmt->execute(['bid' => $manageId]);
        $managedInspection = $iStmt->fetch();
    }
}

// Filter all bookings list
$statusFilter = trim($_GET['status'] ?? '');
$searchRef    = trim($_GET['q'] ?? '');
$where = ['1=1'];
$params = [];

if ($statusFilter !== '' && in_array($statusFilter, ['pending', 'confirmed', 'picked_up', 'completed', 'cancelled', 'rejected'], true)) {
    $where[] = 'b.booking_status = :status';
    $params['status'] = $statusFilter;
}
if ($searchRef !== '') {
    $where[] = '(b.booking_reference LIKE :q1 OR u.full_name LIKE :q2 OR v.registration_number LIKE :q3)';
    $like = '%' . $searchRef . '%';
    $params['q1'] = $like;
    $params['q2'] = $like;
    $params['q3'] = $like;
}

$listStmt = $pdo->prepare("
    SELECT b.*, u.full_name AS customer_name, u.phone AS customer_phone,
           v.brand, v.model, v.registration_number,
           pl.name AS pickup_location_name
    FROM bookings b
    JOIN users u ON u.id = b.customer_id
    JOIN vehicles v ON v.id = b.vehicle_id
    JOIN locations pl ON pl.id = b.pickup_location_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY b.created_at DESC, b.id DESC
");
$listStmt->execute($params);
$bookings = $listStmt->fetchAll();

$pageTitle = 'Manage Bookings & Dispatch';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="page-header-band">
    <div class="container d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <span class="text-orange fw-bold text-uppercase small">Staff Dispatch &amp; Fleet Operations</span>
            <h1 class="fw-bold mb-1">Manage Reservations, Pickups &amp; Returns</h1>
            <p class="mb-0 text-light opacity-75">Confirm or reject pending reservations and record odometer, fuel level, and condition notes at handover.</p>
        </div>
        <a href="<?= e(url('staff/index.php')) ?>" class="btn btn-outline-light">
            <i class="bi bi-speedometer2 me-1"></i>Staff Dashboard
        </a>
    </div>
</section>

<div class="container pb-5">
    <?php if ($managedBooking): ?>
        <!-- Active Booking Action Console -->
        <div class="card border-2 border-primary shadow mb-5">
            <div class="card-header bg-navy text-white py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <span class="badge bg-orange-soft text-uppercase me-2">Selected Booking</span>
                    <strong class="fs-5 font-monospace"><?= e($managedBooking['booking_reference']) ?></strong>
                    — <?= e($managedBooking['brand'] . ' ' . $managedBooking['model']) ?> (<code><?= e($managedBooking['registration_number']) ?></code>)
                </div>
                <div class="d-flex gap-2">
                    <a href="<?= e(url('customer/booking_details.php?id=' . (int)$managedBooking['id'])) ?>" class="btn btn-sm btn-outline-light">
                        <i class="bi bi-receipt me-1"></i>View Printable Receipt
                    </a>
                    <a href="<?= e(url('staff/bookings.php')) ?>" class="btn btn-sm btn-light">Close Panel</a>
                </div>
            </div>

            <div class="card-body p-4">
                <div class="row g-4">
                    <div class="col-lg-5 border-end">
                        <h3 class="h6 fw-bold text-uppercase text-muted small">Reservation Overview</h3>
                        <p class="small mb-2">
                            <strong>Customer:</strong> <?= e($managedBooking['customer_name']) ?> (<?= e($managedBooking['customer_phone']) ?>)<br>
                            <strong>NIC / Licence:</strong> <?= e($managedBooking['customer_nic'] ?: 'N/A') ?> / <?= e($managedBooking['customer_license'] ?: 'N/A') ?><br>
                            <strong>Scheduled Pickup:</strong> <?= e(date('d M Y, H:i', strtotime($managedBooking['pickup_datetime']))) ?> (<?= e($managedBooking['pickup_location_name']) ?>)<br>
                            <strong>Scheduled Return:</strong> <?= e(date('d M Y, H:i', strtotime($managedBooking['return_datetime']))) ?> (<?= e($managedBooking['return_location_name']) ?>)<br>
                            <strong>Billed Duration:</strong> <?= (int)$managedBooking['total_days'] ?> days (Included allowance: <?= number_format((int)$managedBooking['total_days'] * 150) ?> km)
                        </p>

                        <div class="p-3 bg-light rounded border small mb-3">
                            <div class="d-flex justify-content-between"><span>Booking Status:</span> <span class="badge <?= booking_status_badge($managedBooking['booking_status']) ?>"><?= e(booking_status_label($managedBooking['booking_status'])) ?></span></div>
                            <div class="d-flex justify-content-between mt-1"><span>Payment Status:</span> <span class="badge <?= payment_status_badge($managedBooking['payment_status']) ?>"><?= e(strtoupper(str_replace('_', ' ', $managedBooking['payment_status']))) ?></span></div>
                            <hr class="my-2">
                            <div class="d-flex justify-content-between"><span>Total Rental Amount:</span> <strong><?= e(format_lkr($managedBooking['total_amount_lkr'])) ?></strong></div>
                            <div class="d-flex justify-content-between text-success"><span>Amount Paid:</span> <strong><?= e(format_lkr($managedBooking['amount_paid_lkr'])) ?></strong></div>
                            <div class="d-flex justify-content-between text-danger"><span>Balance Due:</span> <strong><?= e(format_lkr($managedBooking['balance_due_lkr'])) ?></strong></div>
                        </div>

                        <?php if (!empty($managedBooking['customer_notes'])): ?>
                            <div class="alert alert-info py-2 small mb-0">
                                <strong>Customer Request:</strong> <?= e($managedBooking['customer_notes']) ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="col-lg-7">
                        <?php if ($managedBooking['booking_status'] === 'pending'): ?>
                            <h3 class="h5 fw-bold mb-3"><i class="bi bi-check2-square text-orange me-2"></i>Step 1: Confirm or Reject Pending Reservation</h3>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <form action="<?= e(url('staff/bookings.php')) ?>" method="post" class="p-3 border rounded bg-light h-100">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="booking_id" value="<?= (int)$managedBooking['id'] ?>">
                                        <input type="hidden" name="action" value="confirm_booking">
                                        <h4 class="h6 fw-bold text-primary">Confirm Reservation</h4>
                                        <div class="mb-3">
                                            <label for="staff_notes" class="form-label small fw-semibold">Dispatch Preparation Notes</label>
                                            <textarea id="staff_notes" name="staff_notes" rows="2" class="form-control form-control-sm" placeholder="e.g., Vehicle assigned & washed at Negombo HQ..."><?= e($managedBooking['staff_notes']) ?></textarea>
                                        </div>
                                        <button type="submit" class="btn btn-primary btn-sm w-100">
                                            <i class="bi bi-check-circle me-1"></i>Confirm Booking
                                        </button>
                                    </form>
                                </div>

                                <div class="col-md-6">
                                    <form action="<?= e(url('staff/bookings.php')) ?>" method="post" class="p-3 border border-danger-subtle rounded bg-light h-100" onsubmit="return confirm('Reject this booking and release its dates?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="booking_id" value="<?= (int)$managedBooking['id'] ?>">
                                        <input type="hidden" name="action" value="reject_booking">
                                        <h4 class="h6 fw-bold text-danger">Reject Reservation</h4>
                                        <div class="mb-3">
                                            <label for="cancellation_reason" class="form-label small fw-semibold">Rejection Reason (Required)</label>
                                            <textarea id="cancellation_reason" name="cancellation_reason" rows="2" class="form-control form-control-sm" placeholder="e.g., Unverified driving licence / Deposit not received..." required></textarea>
                                        </div>
                                        <button type="submit" class="btn btn-outline-danger btn-sm w-100">
                                            <i class="bi bi-x-circle me-1"></i>Reject Booking
                                        </button>
                                    </form>
                                </div>
                            </div>

                        <?php elseif ($managedBooking['booking_status'] === 'confirmed'): ?>
                            <h3 class="h5 fw-bold mb-3"><i class="bi bi-key-fill text-orange me-2"></i>Step 2: Record Vehicle Pickup &amp; Pre-Rental Inspection</h3>
                            <form action="<?= e(url('staff/bookings.php')) ?>" method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="booking_id" value="<?= (int)$managedBooking['id'] ?>">
                                <input type="hidden" name="action" value="record_pickup">

                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label for="pickup_mileage_km" class="form-label small fw-semibold">Pickup Odometer (km) <span class="text-danger">*</span></label>
                                        <input type="number" id="pickup_mileage_km" name="pickup_mileage_km" class="form-control" value="<?= (int)$managedBooking['vehicle_current_mileage'] ?>" min="1" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="pickup_fuel_eighths" class="form-label small fw-semibold">Fuel Tank Level <span class="text-danger">*</span></label>
                                        <select id="pickup_fuel_eighths" name="pickup_fuel_eighths" class="form-select" required>
                                            <?php for ($f = 8; $f >= 1; $f--): ?>
                                                <option value="<?= $f ?>" <?= $f === 8 ? 'selected' : '' ?>><?= e(fuel_eighths_label($f)) ?></option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="pickup_actual_at" class="form-label small fw-semibold">Handover Date &amp; Time</label>
                                        <input type="datetime-local" id="pickup_actual_at" name="pickup_actual_at" class="form-control" value="<?= e(date('Y-m-d\TH:i')) ?>" required>
                                    </div>
                                    <div class="col-12">
                                        <label for="pickup_condition_notes" class="form-label small fw-semibold">Exterior / Interior Condition &amp; Tyre Notes <span class="text-danger">*</span></label>
                                        <textarea id="pickup_condition_notes" name="pickup_condition_notes" rows="2" class="form-control" placeholder="e.g., Spare wheel, jack & AAC permit verified; no exterior scratches; AC tested cold." required>Spare wheel, jack &amp; documents verified; clean exterior and interior.</textarea>
                                    </div>
                                    <div class="col-12">
                                        <button type="submit" class="btn btn-accent px-4">
                                            <i class="bi bi-check2-circle me-1"></i>Dispatch Vehicle &amp; Transition to Picked Up
                                        </button>
                                    </div>
                                </div>
                            </form>

                        <?php elseif ($managedBooking['booking_status'] === 'picked_up'): ?>
                            <h3 class="h5 fw-bold mb-2"><i class="bi bi-box-arrow-in-down-left text-orange me-2"></i>Step 3: Record Vehicle Return, Mileage/Fuel &amp; Late Penalty Calculator</h3>
                            <?php if ($managedInspection): ?>
                                <div class="alert alert-secondary py-2 small mb-3">
                                    <strong>Pickup Baseline:</strong> Odometer: <strong><?= number_format((int)$managedInspection['pickup_mileage_km']) ?> km</strong> &bull;
                                    Fuel at Pickup: <strong><?= e(fuel_eighths_label((int)$managedInspection['pickup_fuel_eighths'])) ?></strong> &bull;
                                    Included Allowance: <strong><?= number_format((int)$managedBooking['total_days'] * 150) ?> km</strong> (Excess @ <?= e(format_lkr($managedBooking['excess_km_rate_lkr'])) ?>/km)
                                </div>
                            <?php endif; ?>

                            <form action="<?= e(url('staff/bookings.php')) ?>" method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="booking_id" value="<?= (int)$managedBooking['id'] ?>">
                                <input type="hidden" name="action" value="record_return">

                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label for="return_mileage_km" class="form-label small fw-semibold">Return Odometer (km) <span class="text-danger">*</span></label>
                                        <input type="number" id="return_mileage_km" name="return_mileage_km" class="form-control"
                                               value="<?= (int)($managedInspection['pickup_mileage_km'] ?? $managedBooking['vehicle_current_mileage']) + 280 ?>"
                                               min="<?= (int)($managedInspection['pickup_mileage_km'] ?? 1) ?>" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="return_fuel_eighths" class="form-label small fw-semibold">Return Fuel Level <span class="text-danger">*</span></label>
                                        <select id="return_fuel_eighths" name="return_fuel_eighths" class="form-select" required>
                                            <?php
                                            $baseFuel = (int)($managedInspection['pickup_fuel_eighths'] ?? 8);
                                            for ($f = 8; $f >= 1; $f--):
                                            ?>
                                                <option value="<?= $f ?>" <?= $f === $baseFuel ? 'selected' : '' ?>><?= e(fuel_eighths_label($f)) ?></option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="return_actual_at" class="form-label small fw-semibold">Actual Return Date &amp; Time</label>
                                        <input type="datetime-local" id="return_actual_at" name="return_actual_at" class="form-control"
                                               value="<?= e(date('Y-m-d\TH:i', strtotime($managedBooking['return_datetime']))) ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="damage_fee_lkr" class="form-label small fw-semibold">Optional Cleaning / Minor Damage Fee (LKR)</label>
                                        <input type="number" id="damage_fee_lkr" name="damage_fee_lkr" class="form-control" value="0.00" min="0" step="100">
                                    </div>
                                    <div class="col-md-6 d-flex align-items-end">
                                        <div class="form-check mb-2">
                                            <input class="form-check-input" type="checkbox" id="settle_final_balance" name="settle_final_balance" value="1" checked>
                                            <label class="form-check-label small fw-semibold" for="settle_final_balance">
                                                Mark final balance settled at counter upon return
                                            </label>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <label for="return_condition_notes" class="form-label small fw-semibold">Return Condition &amp; Inspection Notes <span class="text-danger">*</span></label>
                                        <textarea id="return_condition_notes" name="return_condition_notes" rows="2" class="form-control" required>Returned to yard; exterior and interior checked and cleared.</textarea>
                                    </div>
                                    <div class="col-12">
                                        <button type="submit" class="btn btn-success px-4">
                                            <i class="bi bi-flag-fill me-1"></i>Complete Return &amp; Finalize Charges
                                        </button>
                                    </div>
                                </div>
                            </form>

                        <?php else: ?>
                            <div class="alert alert-secondary mb-0">
                                <h4 class="h6 fw-bold">Lifecycle Completed / Closed</h4>
                                <p class="small mb-2">This reservation is in <strong><?= e(booking_status_label($managedBooking['booking_status'])) ?></strong> status. No further state transitions are permitted.</p>
                                <a href="<?= e(url('customer/booking_details.php?id=' . (int)$managedBooking['id'])) ?>" class="btn btn-sm btn-navy">Open Full Receipt &amp; Inspection Log</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Search & Status Filter Bar -->
    <div class="card border shadow-sm mb-4">
        <div class="card-body py-3">
            <form action="<?= e(url('staff/bookings.php')) ?>" method="get" class="row g-2 align-items-center">
                <div class="col-md-5">
                    <input type="text" name="q" class="form-control form-control-sm" placeholder="Search by reference (SDR-...), customer name, or reg no..." value="<?= e($searchRef) ?>">
                </div>
                <div class="col-md-4">
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All Booking Statuses</option>
                        <?php foreach (['pending', 'confirmed', 'picked_up', 'completed', 'cancelled', 'rejected'] as $st): ?>
                            <option value="<?= $st ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= e(booking_status_label($st)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-accent flex-grow-1"><i class="bi bi-search me-1"></i>Filter</button>
                    <a href="<?= e(url('staff/bookings.php')) ?>" class="btn btn-sm btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Bookings Table -->
    <div class="card border shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle small mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">Reference</th>
                            <th>Customer</th>
                            <th>Vehicle</th>
                            <th>Rental Window</th>
                            <th>Total / Balance</th>
                            <th>Payment</th>
                            <th>Status</th>
                            <th class="pe-3 text-end">Manage</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bookings as $b): ?>
                            <tr class="<?= $manageId === (int)$b['id'] ? 'table-primary' : '' ?>">
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
                                    <div><?= e(date('d M Y H:i', strtotime($b['pickup_datetime']))) ?></div>
                                    <div class="text-muted">to <?= e(date('d M Y H:i', strtotime($b['return_datetime']))) ?> (<?= (int)$b['total_days'] ?>d)</div>
                                </td>
                                <td>
                                    <strong><?= e(format_lkr($b['total_amount_lkr'])) ?></strong><br>
                                    <span class="text-muted">Due: <?= e(format_lkr($b['balance_due_lkr'])) ?></span>
                                </td>
                                <td>
                                    <span class="badge <?= payment_status_badge($b['payment_status']) ?>">
                                        <?= e(strtoupper(str_replace('_', ' ', $b['payment_status']))) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?= booking_status_badge($b['booking_status']) ?>">
                                        <?= e(booking_status_label($b['booking_status'])) ?>
                                    </span>
                                </td>
                                <td class="pe-3 text-end">
                                    <div class="btn-group btn-group-sm">
                                        <a href="<?= e(url('staff/bookings.php?manage_id=' . (int)$b['id'])) ?>" class="btn btn-accent">
                                            Inspect / Action
                                        </a>
                                        <a href="<?= e(url('customer/booking_details.php?id=' . (int)$b['id'])) ?>" class="btn btn-outline-secondary" title="Receipt">
                                            <i class="bi bi-receipt"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>


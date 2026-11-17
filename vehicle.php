<?php
/**
 * Vehicle Details & Interactive Booking Initiation Page
 * Shows images, full specifications, daily/weekly rates, upcoming reserved windows,
 * rental conditions, and a live price breakdown calculator that submits to customer/book.php.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/pricing.php';

$pdo = Database::getConnection();
$vehicleId = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT v.*, c.name AS category_name, c.slug AS category_slug, c.excess_km_rate_lkr,
           l.name AS base_location_name, l.address AS base_location_address
    FROM vehicles v
    JOIN vehicle_categories c ON c.id = v.category_id
    JOIN locations l ON l.id = v.base_location_id
    WHERE v.id = :id AND v.is_active = 1
    LIMIT 1
");
$stmt->execute(['id' => $vehicleId]);
$vehicle = $stmt->fetch();

if (!$vehicle) {
    set_flash('warning', 'The requested vehicle could not be found or is no longer active.');
    redirect('vehicles.php');
}

// Fetch gallery images
$imgStmt = $pdo->prepare("SELECT * FROM vehicle_images WHERE vehicle_id = :vid ORDER BY is_primary DESC, id ASC");
$imgStmt->execute(['vid' => $vehicleId]);
$images = $imgStmt->fetchAll();

// Fetch active locations
$locations = $pdo->query("SELECT * FROM locations WHERE is_active = 1 ORDER BY id ASC")->fetchAll();

// Fetch upcoming reserved date windows so customers can see when this vehicle is already booked
$resStmt = $pdo->prepare("
    SELECT pickup_datetime, return_datetime, booking_status
    FROM bookings
    WHERE vehicle_id = :vid
      AND booking_status IN ('pending', 'confirmed', 'picked_up')
      AND return_datetime >= NOW()
    ORDER BY pickup_datetime ASC
    LIMIT 8
");
$resStmt->execute(['vid' => $vehicleId]);
$upcomingReservations = $resStmt->fetchAll();

$defaultPickup = trim($_GET['pickup_datetime'] ?? '') ?: (new DateTimeImmutable('+2 days 09:00'))->format('Y-m-d\TH:i');
$defaultReturn = trim($_GET['return_datetime'] ?? '') ?: (new DateTimeImmutable('+5 days 09:00'))->format('Y-m-d\TH:i');
$selectedPickupLoc = (int)($_GET['pickup_location_id'] ?? $vehicle['base_location_id']);
$selectedReturnLoc = (int)($_GET['return_location_id'] ?? $selectedPickupLoc);

$initialPickupSurcharge = 0.0;
$initialReturnSurcharge = 0.0;
foreach ($locations as $loc) {
    if ((int)$loc['id'] === $selectedPickupLoc) {
        $initialPickupSurcharge = (float)$loc['surcharge_lkr'];
    }
    if ((int)$loc['id'] === $selectedReturnLoc) {
        $initialReturnSurcharge = (float)$loc['surcharge_lkr'];
    }
}

$initialQuote = PricingAndBookingService::calculateQuote(
    $vehicle,
    new DateTimeImmutable($defaultPickup),
    new DateTimeImmutable($defaultReturn),
    $initialPickupSurcharge,
    $initialReturnSurcharge
);

$user = current_user();
$features = array_filter(array_map('trim', explode(',', $vehicle['features_list'] ?? '')));

$pageTitle = $vehicle['brand'] . ' ' . $vehicle['model'];
require_once __DIR__ . '/includes/header.php';
?>

<section class="page-header-band">
    <div class="container">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb small mb-2">
                <li class="breadcrumb-item"><a href="<?= e(url('index.php')) ?>" class="text-light opacity-75">Home</a></li>
                <li class="breadcrumb-item"><a href="<?= e(url('vehicles.php')) ?>" class="text-light opacity-75">Vehicle Fleet</a></li>
                <li class="breadcrumb-item active text-orange" aria-current="page"><?= e($vehicle['brand'] . ' ' . $vehicle['model']) ?></li>
            </ol>
        </nav>
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-2">
            <div>
                <span class="badge bg-orange-soft border border-warning text-uppercase mb-2"><?= e($vehicle['category_name']) ?></span>
                <h1 class="fw-bold mb-0"><?= e($vehicle['brand'] . ' ' . $vehicle['model']) ?> (<?= (int)$vehicle['year'] ?>)</h1>
            </div>
            <div>
                <?php if ($vehicle['maintenance_status'] === 'available'): ?>
                    <span class="badge bg-success fs-6 px-3 py-2"><i class="bi bi-check-circle me-1"></i>Available for Booking</span>
                <?php else: ?>
                    <span class="badge bg-warning text-dark fs-6 px-3 py-2"><i class="bi bi-tools me-1"></i>Currently in Workshop Maintenance</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<div class="container pb-5">
    <div class="row g-4">
        <!-- Left Column: Gallery, Specifications, Conditions & Availability Windows -->
        <div class="col-lg-7">
            <div class="card border shadow-sm overflow-hidden mb-4">
                <div class="bg-navy text-center">
                    <img id="vehicle-main-image" src="<?= e(url($vehicle['primary_image'])) ?>" alt="<?= e($vehicle['brand'] . ' ' . $vehicle['model']) ?>" class="img-fluid" style="max-height: 380px; width: 100%; object-fit: cover;">
                </div>
                <?php if (count($images) > 1): ?>
                    <div class="card-body bg-light py-2 d-flex gap-2 overflow-auto">
                        <?php foreach ($images as $idx => $img): ?>
                            <img src="<?= e(url($img['image_path'])) ?>"
                                 alt="<?= e($img['caption'] ?? $vehicle['model']) ?>"
                                 data-gallery-src="<?= e(url($img['image_path'])) ?>"
                                 data-gallery-alt="<?= e($img['caption'] ?? ($vehicle['brand'] . ' ' . $vehicle['model'])) ?>"
                                 class="rounded border gallery-thumb-btn <?= $idx === 0 ? 'active' : '' ?>"
                                 style="height: 64px; width: 100px; object-fit: cover;">
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Key Specifications -->
            <div class="card border shadow-sm mb-4">
                <div class="card-body p-4">
                    <h2 class="h5 fw-bold mb-3">Vehicle Specifications &amp; Equipment</h2>
                    <p class="text-secondary"><?= e($vehicle['description']) ?></p>

                    <div class="row g-3 my-3">
                        <div class="col-6 col-sm-4">
                            <div class="p-3 bg-light rounded border">
                                <div class="text-muted small">Transmission</div>
                                <strong class="text-dark"><i class="bi bi-gear text-orange me-1"></i><?= e($vehicle['transmission']) ?></strong>
                            </div>
                        </div>
                        <div class="col-6 col-sm-4">
                            <div class="p-3 bg-light rounded border">
                                <div class="text-muted small">Fuel / Powertrain</div>
                                <strong class="text-dark"><i class="bi bi-fuel-pump text-orange me-1"></i><?= e($vehicle['fuel_type']) ?></strong>
                            </div>
                        </div>
                        <div class="col-6 col-sm-4">
                            <div class="p-3 bg-light rounded border">
                                <div class="text-muted small">Seating Capacity</div>
                                <strong class="text-dark"><i class="bi bi-people text-orange me-1"></i><?= (int)$vehicle['seats'] ?> Passengers</strong>
                            </div>
                        </div>
                        <div class="col-6 col-sm-4">
                            <div class="p-3 bg-light rounded border">
                                <div class="text-muted small">Luggage Hold</div>
                                <strong class="text-dark"><i class="bi bi-briefcase text-orange me-1"></i><?= (int)$vehicle['luggage_capacity'] ?> Large Bags</strong>
                            </div>
                        </div>
                        <div class="col-6 col-sm-4">
                            <div class="p-3 bg-light rounded border">
                                <div class="text-muted small">Registration No.</div>
                                <strong class="text-dark"><code><?= e($vehicle['registration_number']) ?></code></strong>
                            </div>
                        </div>
                        <div class="col-6 col-sm-4">
                            <div class="p-3 bg-light rounded border">
                                <div class="text-muted small">Odometer Reading</div>
                                <strong class="text-dark"><?= number_format((int)$vehicle['mileage_km']) ?> km</strong>
                            </div>
                        </div>
                    </div>

                    <h3 class="h6 fw-bold mt-4 mb-2">Included Features</h3>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($features as $feat): ?>
                            <span class="badge bg-light text-dark border px-3 py-2">
                                <i class="bi bi-check2 text-orange me-1"></i><?= e($feat) ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Rental Conditions & Scheduled Reservations -->
            <div class="card border shadow-sm">
                <div class="card-body p-4">
                    <h2 class="h5 fw-bold mb-3"><i class="bi bi-calendar-week text-orange me-2"></i>Availability Calendar &amp; Rental Conditions</h2>

                    <?php if (empty($upcomingReservations)): ?>
                        <div class="alert alert-success small mb-3">
                            <i class="bi bi-check-circle-fill me-1"></i>
                            This vehicle has no conflicting upcoming reservations and is ready for immediate scheduling.
                        </div>
                    <?php else: ?>
                        <p class="small text-muted mb-2">
                            The following date intervals are already reserved (statuses: <code>pending</code>, <code>confirmed</code>, or <code>picked_up</code>). Please select dates outside these windows:
                        </p>
                        <div class="table-responsive mb-3">
                            <table class="table table-sm table-bordered small mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Reserved From (Pickup)</th>
                                        <th>Reserved Until (Return)</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($upcomingReservations as $res): ?>
                                        <tr>
                                            <td><?= e(date('d M Y, H:i', strtotime($res['pickup_datetime']))) ?></td>
                                            <td><?= e(date('d M Y, H:i', strtotime($res['return_datetime']))) ?></td>
                                            <td><span class="badge <?= booking_status_badge($res['booking_status']) ?>"><?= e(booking_status_label($res['booking_status'])) ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <div class="bg-light rounded p-3 border small">
                        <strong class="d-block mb-1">Key Rental Conditions for this <?= e($vehicle['category_name']) ?> Vehicle:</strong>
                        <ul class="mb-0 ps-3">
                            <li><strong>Daily Allowance:</strong> 150 km/day included; excess mileage charged at <strong><?= e(format_lkr($vehicle['excess_km_rate_lkr'])) ?>/km</strong>.</li>
                            <li><strong>Weekly Rate Rule:</strong> Every complete 7-day block is automatically discounted to <strong><?= e(format_lkr($vehicle['weekly_rate_lkr'])) ?>/week</strong>.</li>
                            <li><strong>Reservation Deposit:</strong> <strong>25% deposit</strong> payable via our online payment gateway to lock confirmation.</li>
                            <li><strong>Late Return Penalty:</strong> 60-minute grace period; thereafter 15% of daily rate (<?= e(format_lkr((float)$vehicle['daily_rate_lkr'] * 0.15)) ?>) per late hour.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Column: Interactive Quote & Booking Form -->
        <div class="col-lg-5">
            <div class="card border shadow-sm sticky-lg-top" style="top: 85px;">
                <div class="card-header bg-navy text-white p-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <span class="small text-light opacity-75 d-block">Daily Rate</span>
                            <strong class="fs-4 text-white"><?= e(format_lkr($vehicle['daily_rate_lkr'])) ?></strong>
                        </div>
                        <div class="text-end">
                            <span class="small text-orange d-block fw-semibold">7-Day Weekly Bundle</span>
                            <strong class="fs-5 text-white"><?= e(format_lkr($vehicle['weekly_rate_lkr'])) ?></strong>
                        </div>
                    </div>
                </div>

                <div class="card-body p-4">
                    <?php if ($vehicle['maintenance_status'] !== 'available'): ?>
                        <div class="alert alert-warning mb-0">
                            <h3 class="h6 fw-bold"><i class="bi bi-wrench-adjustable-circle me-1"></i>Currently Undergoing Scheduled Maintenance</h3>
                            <p class="small mb-2">This vehicle is currently marked as <strong><?= e(strtoupper($vehicle['maintenance_status'])) ?></strong> at our Negombo workshop and cannot accept online bookings until cleared by an administrator.</p>
                            <a href="<?= e(url('vehicles.php?category=' . urlencode($vehicle['category_slug']))) ?>" class="btn btn-sm btn-navy">Browse Similar Available Vehicles</a>
                        </div>
                    <?php else: ?>
                        <h2 class="h5 fw-bold mb-3"><i class="bi bi-calendar-plus text-orange me-2"></i>Calculate Price &amp; Reserve</h2>

                        <form action="<?= e(url('customer/book.php')) ?>" method="post"
                              data-quote-calculator="1"
                              data-daily-rate="<?= (float)$vehicle['daily_rate_lkr'] ?>"
                              data-weekly-rate="<?= (float)$vehicle['weekly_rate_lkr'] ?>"
                              data-deposit-rate="0.25">
                            <?= csrf_field() ?>
                            <input type="hidden" name="vehicle_id" value="<?= (int)$vehicle['id'] ?>">

                            <div class="mb-3">
                                <label for="pickup_location_id" class="form-label fw-semibold small">Pickup Location</label>
                                <select id="pickup_location_id" name="pickup_location_id" class="form-select" required>
                                    <?php foreach ($locations as $loc): ?>
                                        <option value="<?= (int)$loc['id'] ?>"
                                                data-surcharge="<?= (float)$loc['surcharge_lkr'] ?>"
                                                <?= $selectedPickupLoc === (int)$loc['id'] ? 'selected' : '' ?>>
                                            <?= e($loc['name']) ?> <?= (float)$loc['surcharge_lkr'] > 0 ? '(+' . e(format_lkr($loc['surcharge_lkr'])) . ')' : '(Free)' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label for="return_location_id" class="form-label fw-semibold small">Return Location</label>
                                <select id="return_location_id" name="return_location_id" class="form-select" required>
                                    <?php foreach ($locations as $loc): ?>
                                        <option value="<?= (int)$loc['id'] ?>"
                                                data-surcharge="<?= (float)$loc['surcharge_lkr'] ?>"
                                                <?= $selectedPickupLoc === (int)$loc['id'] ? 'selected' : '' ?>>
                                            <?= e($loc['name']) ?> <?= (float)$loc['surcharge_lkr'] > 0 ? '(+' . e(format_lkr($loc['surcharge_lkr'])) . ')' : '(Free)' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="row g-2 mb-3">
                                <div class="col-sm-6">
                                    <label for="pickup_datetime" class="form-label fw-semibold small">Pickup Date &amp; Time</label>
                                    <input type="datetime-local" id="pickup_datetime" name="pickup_datetime" class="form-control" value="<?= e($defaultPickup) ?>" required>
                                </div>
                                <div class="col-sm-6">
                                    <label for="return_datetime" class="form-label fw-semibold small">Return Date &amp; Time</label>
                                    <input type="datetime-local" id="return_datetime" name="return_datetime" class="form-control" value="<?= e($defaultReturn) ?>" required>
                                </div>
                            </div>

                            <div id="quote-date-error" class="alert alert-danger py-2 small d-none" role="alert"></div>

                            <!-- Live Price Breakdown Box -->
                            <div class="bg-light border rounded p-3 mb-3 small">
                                <div class="d-flex justify-content-between mb-1">
                                    <span class="text-muted">Billed Duration (24h blocks):</span>
                                    <strong id="quote-duration-text"><?= (int)$initialQuote['total_days'] ?> Days</strong>
                                </div>
                                <div class="d-flex justify-content-between mb-1">
                                    <span class="text-muted">Base Rental Tariff:</span>
                                    <strong id="quote-base-lkr"><?= e(format_lkr($initialQuote['base_rental_lkr'])) ?></strong>
                                </div>
                                <div id="quote-savings-row" class="d-flex justify-content-between mb-1 text-success <?= $initialQuote['weekly_savings_lkr'] > 0 ? '' : 'd-none' ?>">
                                    <span>Weekly Bundle Savings:</span>
                                    <strong id="quote-savings-lkr">- <?= e(format_lkr($initialQuote['weekly_savings_lkr'])) ?></strong>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span class="text-muted">Location Handover Surcharge:</span>
                                    <strong id="quote-surcharge-lkr"><?= e(format_lkr($initialQuote['location_surcharge_lkr'])) ?></strong>
                                </div>
                                <hr class="my-2">
                                <div class="d-flex justify-content-between align-items-center fs-6 mb-2">
                                    <strong>Estimated Total:</strong>
                                    <strong id="quote-total-lkr" class="text-dark"><?= e(format_lkr($initialQuote['total_amount_lkr'])) ?></strong>
                                </div>
                                <div class="d-flex justify-content-between text-primary">
                                    <span>Required 25% Deposit Now:</span>
                                    <strong id="quote-deposit-lkr"><?= e(format_lkr($initialQuote['deposit_required_lkr'])) ?></strong>
                                </div>
                                <div class="d-flex justify-content-between text-muted">
                                    <span>Remaining Balance at Pickup/Return:</span>
                                    <span id="quote-balance-lkr"><?= e(format_lkr($initialQuote['remaining_balance_lkr'])) ?></span>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="customer_notes" class="form-label fw-semibold small">Flight Number / Special Requests (Optional)</label>
                                <textarea id="customer_notes" name="customer_notes" rows="2" class="form-control form-control-sm" placeholder="e.g., Arriving on UL-504 at 08:30, child seat requested..."></textarea>
                            </div>

                            <?php if (!$user): ?>
                                <div class="alert alert-info py-2 small mb-3">
                                    <i class="bi bi-person-lock me-1"></i>
                                    Please <a href="<?= e(url('login.php')) ?>" class="fw-bold">Sign In</a> or <a href="<?= e(url('register.php')) ?>" class="fw-bold">Register</a> as a customer to complete your reservation.
                                </div>
                                <a href="<?= e(url('login.php')) ?>" class="btn btn-accent w-100 py-2">
                                    Sign In to Reserve Vehicle
                                </a>
                            <?php elseif ($user['role'] !== 'customer'): ?>
                                <div class="alert alert-secondary py-2 small mb-0">
                                    <i class="bi bi-info-circle me-1"></i>
                                    You are signed in as <strong><?= e(strtoupper($user['role'])) ?></strong>. Please sign in with a customer account to create a reservation.
                                </div>
                            <?php else: ?>
                                <button type="submit" class="btn btn-accent w-100 py-2 fs-6">
                                    <i class="bi bi-calendar2-check me-2"></i>Confirm Dates &amp; Create Booking
                                </button>
                            <?php endif; ?>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>


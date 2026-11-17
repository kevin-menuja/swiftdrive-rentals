<?php
/**
 * Home Page - SwiftDrive Rentals (Negombo, Sri Lanka)
 * Features: Hero banner, prominent availability search, featured vehicles,
 * 4 fleet categories, company benefits, and verified customer reviews.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/pricing.php';

$pdo = Database::getConnection();

// Fetch active locations for the availability search bar
$locations = $pdo->query("SELECT id, name, code, surcharge_lkr FROM locations WHERE is_active = 1 ORDER BY id ASC")->fetchAll();

// Fetch categories with live available vehicle count
$categories = $pdo->query("
    SELECT c.*,
           COUNT(CASE WHEN v.is_active = 1 AND v.maintenance_status = 'available' THEN 1 END) AS active_fleet_count,
           MIN(CASE WHEN v.is_active = 1 THEN v.daily_rate_lkr END) AS min_daily_rate
    FROM vehicle_categories c
    LEFT JOIN vehicles v ON v.category_id = c.id
    GROUP BY c.id
    ORDER BY c.display_order ASC
")->fetchAll();

// Fetch featured vehicles (active & available)
$featuredVehicles = $pdo->query("
    SELECT v.*, c.name AS category_name, c.slug AS category_slug, l.name AS location_name
    FROM vehicles v
    JOIN vehicle_categories c ON c.id = v.category_id
    JOIN locations l ON l.id = v.base_location_id
    WHERE v.is_active = 1
    ORDER BY v.is_featured DESC, v.maintenance_status ASC, v.daily_rate_lkr ASC
    LIMIT 6
")->fetchAll();

// Fetch customer reviews
$reviews = $pdo->query("SELECT * FROM reviews ORDER BY id ASC LIMIT 3")->fetchAll();

$defaultPickup = (new DateTimeImmutable('+1 day 09:00'))->format('Y-m-d\TH:i');
$defaultReturn = (new DateTimeImmutable('+4 days 09:00'))->format('Y-m-d\TH:i');

$pageTitle = 'Self-Drive & Airport Car Hire in Negombo';
require_once __DIR__ . '/includes/header.php';
?>

<!-- Hero & Availability Search Section -->
<section class="hero-section">
    <div class="container">
        <div class="row align-items-center g-4 g-lg-5">
            <div class="col-lg-6">
                <div class="hero-badge mb-3">
                    <i class="bi bi-geo-alt-fill"></i> Negombo Beach Road HQ &amp; BIA Katunayake Airport
                </div>
                <h1 class="display-5 fw-bold mb-3">
                    Explore Sri Lanka at Your Own Pace with <span class="text-orange">SwiftDrive</span>
                </h1>
                <p class="lead text-light opacity-75 mb-4">
                    From fuel-saving Japanese hybrids for Negombo coastal drives to 7-seater Prado SUVs and air-conditioned KDH tour vans. Real-time availability, transparent LKR weekly bundles, and instant online booking.
                </p>
                <div class="d-flex flex-wrap gap-3">
                    <a href="<?= e(url('vehicles.php')) ?>" class="btn btn-accent btn-lg px-4">
                        <i class="bi bi-search me-2"></i>Browse Full Fleet
                    </a>
                    <a href="<?= e(url('terms.php')) ?>" class="btn btn-outline-light btn-lg px-4">
                        Pricing &amp; Deposit Rules
                    </a>
                </div>
                <div class="row g-3 mt-4 pt-2 border-top border-light border-opacity-10 small text-light opacity-75">
                    <div class="col-4">
                        <strong class="d-block text-white fs-6 fs-sm-5">150 km/day</strong>
                        Generous Mileage
                    </div>
                    <div class="col-4">
                        <strong class="d-block text-white fs-6 fs-sm-5">25% Deposit</strong>
                        Locks Reservation
                    </div>
                    <div class="col-4">
                        <strong class="d-block text-white fs-6 fs-sm-5">24/7 Support</strong>
                        Islandwide Help
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="search-panel">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                        <h2 class="h4 fw-bold mb-0"><i class="bi bi-calendar2-range text-orange me-2"></i>Check Real-Time Availability</h2>
                        <span class="badge bg-navy">Instant Quote</span>
                    </div>
                    <p class="text-muted small mb-3">
                        Filter vehicles available for your exact pickup and return dates. Vehicles with overlapping reservations or workshop maintenance are automatically excluded.
                    </p>
                    <form action="<?= e(url('vehicles.php')) ?>" method="get">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="home_pickup_location" class="form-label fw-semibold small">Pickup Location</label>
                                <select id="home_pickup_location" name="location_id" class="form-select">
                                    <option value="">All Negombo &amp; BIA Hubs</option>
                                    <?php foreach ($locations as $loc): ?>
                                        <option value="<?= (int)$loc['id'] ?>">
                                            <?= e($loc['name']) ?><?= (float)$loc['surcharge_lkr'] > 0 ? ' (+' . e(format_lkr($loc['surcharge_lkr'])) . ')' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="home_category" class="form-label fw-semibold small">Vehicle Category</label>
                                <select id="home_category" name="category" class="form-select">
                                    <option value="">All Categories</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?= e($cat['slug']) ?>"><?= e($cat['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="home_pickup_dt" class="form-label fw-semibold small">Pickup Date &amp; Time</label>
                                <input type="datetime-local" id="home_pickup_dt" name="pickup_datetime" class="form-control" value="<?= e($defaultPickup) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label for="home_return_dt" class="form-label fw-semibold small">Return Date &amp; Time</label>
                                <input type="datetime-local" id="home_return_dt" name="return_datetime" class="form-control" value="<?= e($defaultReturn) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label for="home_transmission" class="form-label fw-semibold small">Transmission</label>
                                <select id="home_transmission" name="transmission" class="form-select">
                                    <option value="">Any Transmission</option>
                                    <option value="Automatic">Automatic</option>
                                    <option value="Manual">Manual</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="home_seats" class="form-label fw-semibold small">Minimum Seats</label>
                                <select id="home_seats" name="min_seats" class="form-select">
                                    <option value="">Any Capacity</option>
                                    <option value="4">4+ Seats (Compact / Sedan)</option>
                                    <option value="5">5+ Seats (Family / SUV)</option>
                                    <option value="7">7+ Seats (7-Seater SUV / Van)</option>
                                    <option value="10">10+ Seats (Group Tour Van)</option>
                                </select>
                            </div>
                            <div class="col-12 mt-3">
                                <button type="submit" class="btn btn-accent w-100 py-2 fs-6">
                                    <i class="bi bi-search me-2"></i>Find Available Vehicles
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Vehicle Categories Section -->
<section class="py-5">
    <div class="container">
        <div class="d-flex flex-wrap justify-content-between align-items-end mb-4">
            <div>
                <span class="text-orange fw-bold text-uppercase small">Tailored For Every Journey</span>
                <h2 class="fw-bold mb-0">Explore Our Four Fleet Categories</h2>
            </div>
            <a href="<?= e(url('vehicles.php')) ?>" class="btn btn-outline-navy btn-sm">View All Vehicles <i class="bi bi-arrow-right ms-1"></i></a>
        </div>

        <div class="row g-4">
            <?php foreach ($categories as $cat): ?>
                <div class="col-md-6 col-lg-3">
                    <div class="card h-100 border-0 shadow-sm">
                        <div class="card-body d-flex flex-column">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <span class="badge bg-navy px-3 py-2"><?= (int)$cat['active_fleet_count'] ?> Available</span>
                                <i class="bi bi-car-front fs-3 text-orange"></i>
                            </div>
                            <h3 class="h5 fw-bold"><?= e($cat['name']) ?></h3>
                            <p class="text-muted small flex-grow-1"><?= e($cat['description']) ?></p>
                            <div class="pt-3 border-top d-flex justify-content-between align-items-center">
                                <div class="small">
                                    <span class="text-muted d-block">Starting from</span>
                                    <strong class="text-dark"><?= $cat['min_daily_rate'] ? e(format_lkr($cat['min_daily_rate'])) . '/day' : 'Contact Us' ?></strong>
                                </div>
                                <a href="<?= e(url('vehicles.php?category=' . urlencode($cat['slug']))) ?>" class="btn btn-sm btn-accent">
                                    Explore
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Featured Vehicles Section -->
<section class="py-5 bg-white border-top border-bottom">
    <div class="container">
        <div class="d-flex flex-wrap justify-content-between align-items-end mb-4">
            <div>
                <span class="text-orange fw-bold text-uppercase small">Negombo &amp; BIA Katunayake Fleet</span>
                <h2 class="fw-bold mb-0">Featured Rental Vehicles</h2>
            </div>
            <a href="<?= e(url('vehicles.php')) ?>" class="btn btn-navy btn-sm">Filter &amp; Compare All <i class="bi bi-sliders ms-1"></i></a>
        </div>

        <div class="row g-4">
            <?php foreach ($featuredVehicles as $v): ?>
                <div class="col-md-6 col-lg-4">
                    <article class="vehicle-card">
                        <div class="vehicle-card-img-wrap">
                            <img src="<?= e(url($v['primary_image'])) ?>" alt="<?= e($v['brand'] . ' ' . $v['model']) ?>" loading="lazy">
                            <span class="vehicle-category-pill"><?= e($v['category_name']) ?></span>
                            <?php if ($v['maintenance_status'] !== 'available'): ?>
                                <span class="badge bg-warning text-dark position-absolute top-0 end-0 m-2">In Maintenance</span>
                            <?php endif; ?>
                        </div>
                        <div class="p-3 p-lg-4 d-flex flex-column flex-grow-1">
                            <div class="d-flex justify-content-between align-items-start mb-1">
                                <h3 class="h5 fw-bold mb-0"><?= e($v['brand'] . ' ' . $v['model']) ?></h3>
                                <span class="badge bg-light text-dark border"><?= (int)$v['year'] ?></span>
                            </div>
                            <p class="text-muted small mb-3">
                                <i class="bi bi-geo-alt text-orange me-1"></i><?= e($v['location_name']) ?>
                            </p>

                            <div class="d-flex flex-wrap gap-2 mb-3">
                                <span class="vehicle-spec-pill"><i class="bi bi-gear"></i> <?= e($v['transmission']) ?></span>
                                <span class="vehicle-spec-pill"><i class="bi bi-fuel-pump"></i> <?= e($v['fuel_type']) ?></span>
                                <span class="vehicle-spec-pill"><i class="bi bi-people"></i> <?= (int)$v['seats'] ?> Seats</span>
                                <span class="vehicle-spec-pill"><i class="bi bi-briefcase"></i> <?= (int)$v['luggage_capacity'] ?> Bags</span>
                            </div>

                            <div class="mt-auto pt-3 border-top">
                                <div class="d-flex justify-content-between align-items-baseline mb-3">
                                    <div>
                                        <span class="price-highlight"><?= e(format_lkr($v['daily_rate_lkr'])) ?></span>
                                        <span class="text-muted small">/ day</span>
                                    </div>
                                    <div class="text-end small">
                                        <span class="text-muted d-block">Weekly (7 Days)</span>
                                        <strong class="text-success"><?= e(format_lkr($v['weekly_rate_lkr'])) ?></strong>
                                    </div>
                                </div>

                                <div class="d-grid">
                                    <a href="<?= e(url('vehicle.php?id=' . (int)$v['id'])) ?>" class="btn <?= $v['maintenance_status'] === 'available' ? 'btn-accent' : 'btn-outline-secondary' ?>">
                                        <?= $v['maintenance_status'] === 'available' ? 'View Specs &amp; Book Now' : 'View Details (Maintenance)' ?>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </article>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Simple 4-Step Rental Process Section -->
<section class="py-5 border-bottom">
    <div class="container">
        <div class="text-center max-w-700 mx-auto mb-5">
            <span class="text-orange fw-bold text-uppercase small">Simple 4-Step Process</span>
            <h2 class="fw-bold mb-2">How SwiftDrive Self-Drive &amp; Airport Rental Works</h2>
            <p class="text-muted small mb-0">From online availability checking to documented vehicle handover in Negombo or Katunayake Airport.</p>
        </div>

        <div class="row g-4">
            <div class="col-sm-6 col-lg-3">
                <div class="process-step-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="process-step-badge">01</span>
                        <i class="bi bi-calendar2-check fs-3 text-orange"></i>
                    </div>
                    <h3 class="h6 fw-bold">Select Dates &amp; Hub</h3>
                    <p class="small text-muted mb-0">
                        Choose your pickup and return dates at Negombo Beach HQ, BIA Airport Arrivals Counter R-04, or Negombo Railway Station.
                    </p>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="process-step-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="process-step-badge">02</span>
                        <i class="bi bi-calculator-fill fs-3 text-orange"></i>
                    </div>
                    <h3 class="h6 fw-bold">Instant LKR Quote</h3>
                    <p class="small text-muted mb-0">
                        Review transparent daily or automatic 7-day weekly bundle rates, location surcharges, and your 25% reservation deposit.
                    </p>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="process-step-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="process-step-badge">03</span>
                        <i class="bi bi-shield-check fs-3 text-orange"></i>
                    </div>
                    <h3 class="h6 fw-bold">Pay 25% Deposit Online</h3>
                    <p class="small text-muted mb-0">
                        Lock your vehicle with a 25% deposit or full settlement via our online payment portal and download your receipt.
                    </p>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="process-step-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="process-step-badge">04</span>
                        <i class="bi bi-key-fill fs-3 text-orange"></i>
                    </div>
                    <h3 class="h6 fw-bold">Inspect &amp; Drive Away</h3>
                    <p class="small text-muted mb-0">
                        Our dispatch staff verify your driving licence, log starting odometer &amp; fuel level, and hand over your keys.
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Why Choose SwiftDrive Benefits -->
<section class="py-5">
    <div class="container">
        <div class="text-center max-w-700 mx-auto mb-5">
            <span class="text-orange fw-bold text-uppercase small">Why Negombo Travellers Choose Us</span>
            <h2 class="fw-bold">Engineered for Stress-Free Sri Lankan Road Trips</h2>
        </div>

        <div class="row g-4">
            <div class="col-md-6 col-lg-3">
                <div class="kpi-card accent h-100">
                    <i class="bi bi-airplane-engines fs-2 text-orange mb-2 d-block"></i>
                    <h3 class="h6 fw-bold">15 Mins from BIA Airport</h3>
                    <p class="small text-muted mb-0">
                        Pick up directly at Katunayake Arrivals Counter R-04 or at our Poruthota Road beach headquarters in Negombo.
                    </p>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="kpi-card h-100">
                    <i class="bi bi-calculator fs-2 text-orange mb-2 d-block"></i>
                    <h3 class="h6 fw-bold">Automatic Weekly Bundles</h3>
                    <p class="small text-muted mb-0">
                        Renting for 7+ days? Our booking engine automatically applies discounted weekly tariffs plus exact remainder-day pricing.
                    </p>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="kpi-card accent h-100">
                    <i class="bi bi-shield-lock fs-2 text-orange mb-2 d-block"></i>
                    <h3 class="h6 fw-bold">Zero Double-Bookings</h3>
                    <p class="small text-muted mb-0">
                        Protected by transactional vehicle-level locking and real-time pickup/return dispatch inspection logs.
                    </p>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="kpi-card h-100">
                    <i class="bi bi-receipt-cutoff fs-2 text-orange mb-2 d-block"></i>
                    <h3 class="h6 fw-bold">Clear Deposit &amp; Receipts</h3>
                    <p class="small text-muted mb-0">
                        Reserve with a 25% deposit, view full mileage and fuel condition logs, and print official receipts anytime.
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Customer Reviews -->
<section class="py-5 bg-white border-top">
    <div class="container">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
            <div>
                <span class="text-orange fw-bold text-uppercase small">Customer Feedback</span>
                <h2 class="fw-bold mb-0">Recent Hirer Experiences</h2>
            </div>
            <span class="badge bg-light text-dark border">
                <i class="bi bi-patch-check-fill text-orange me-1"></i>Verified Hirer Feedback
            </span>
        </div>

        <div class="row g-4">
            <?php foreach ($reviews as $rev): ?>
                <div class="col-md-4">
                    <div class="card h-100 border shadow-sm">
                        <div class="card-body d-flex flex-column">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div class="text-warning">
                                    <?php for ($i = 0; $i < (int)$rev['rating']; $i++): ?>
                                        <i class="bi bi-star-fill"></i>
                                    <?php endfor; ?>
                                </div>
                                <span class="badge bg-light text-secondary border small">Verified Rental</span>
                            </div>
                            <p class="small text-dark fst-italic flex-grow-1">"<?= e($rev['comment']) ?>"</p>
                            <div class="pt-3 border-top small">
                                <strong class="d-block"><?= e($rev['reviewer_name']) ?></strong>
                                <span class="text-muted"><?= e($rev['reviewer_origin']) ?> &bull; Hired <?= e($rev['vehicle_label']) ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>


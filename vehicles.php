<?php
/**
 * Vehicle Catalogue Page
 * Supports searching, filtering by category, price range, transmission, seats,
 * base pickup location, and availability dates (excluding vehicles with overlapping
 * reservations or in maintenance when dates are supplied), plus sorting and pagination.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/pricing.php';

$pdo = Database::getConnection();

// Fetch filter metadata
$categories = $pdo->query("SELECT * FROM vehicle_categories ORDER BY display_order ASC")->fetchAll();
$locations  = $pdo->query("SELECT * FROM locations WHERE is_active = 1 ORDER BY id ASC")->fetchAll();

// Read & sanitize query parameters
$q              = trim($_GET['q'] ?? '');
$categorySlug   = trim($_GET['category'] ?? '');
$transmission   = trim($_GET['transmission'] ?? '');
$minSeats       = (int)($_GET['min_seats'] ?? 0);
$minPrice       = ($_GET['min_price'] ?? '') !== '' ? (float)$_GET['min_price'] : null;
$maxPrice       = ($_GET['max_price'] ?? '') !== '' ? (float)$_GET['max_price'] : null;
$locationId     = (int)($_GET['location_id'] ?? 0);
$pickupDatetime = trim($_GET['pickup_datetime'] ?? '');
$returnDatetime = trim($_GET['return_datetime'] ?? '');
$sort           = trim($_GET['sort'] ?? 'recommended');
$page           = max(1, (int)($_GET['page'] ?? 1));
$perPage        = 6;

$dateFilterActive = false;
$dateFilterError  = null;
$pickupSql        = null;
$returnSql        = null;

if ($pickupDatetime !== '' || $returnDatetime !== '') {
    $dateVal = PricingAndBookingService::validateRentalDates($pickupDatetime, $returnDatetime, false);
    if (!$dateVal['valid']) {
        $dateFilterError = $dateVal['error'];
    } else {
        $dateFilterActive = true;
        $pickupSql        = $dateVal['pickup_sql'];
        $returnSql        = $dateVal['return_sql'];
    }
}

// Build parameterized WHERE clause
$where  = ['v.is_active = 1'];
$params = [];

if ($q !== '') {
    $where[]            = '(v.brand LIKE :q1 OR v.model LIKE :q2 OR v.description LIKE :q3 OR c.name LIKE :q4)';
    $like               = '%' . $q . '%';
    $params['q1']       = $like;
    $params['q2']       = $like;
    $params['q3']       = $like;
    $params['q4']       = $like;
}

if ($categorySlug !== '') {
    $where[]            = 'c.slug = :cat_slug';
    $params['cat_slug'] = $categorySlug;
}

if (in_array($transmission, ['Automatic', 'Manual'], true)) {
    $where[]                = 'v.transmission = :transmission';
    $params['transmission'] = $transmission;
}

if ($minSeats > 0) {
    $where[]             = 'v.seats >= :min_seats';
    $params['min_seats'] = $minSeats;
}

if ($minPrice !== null && $minPrice >= 0) {
    $where[]             = 'v.daily_rate_lkr >= :min_price';
    $params['min_price'] = $minPrice;
}

if ($maxPrice !== null && $maxPrice > 0) {
    $where[]             = 'v.daily_rate_lkr <= :max_price';
    $params['max_price'] = $maxPrice;
}

if ($locationId > 0) {
    $where[]               = 'v.base_location_id = :location_id';
    $params['location_id'] = $locationId;
}

// If availability dates are provided and valid, strictly exclude vehicles in maintenance
// or vehicles that have an overlapping reservation in ('pending', 'confirmed', 'picked_up')
if ($dateFilterActive) {
    $where[] = "v.maintenance_status = 'available'";
    $where[] = "NOT EXISTS (
        SELECT 1 FROM bookings b
        WHERE b.vehicle_id = v.id
          AND b.booking_status IN ('pending', 'confirmed', 'picked_up')
          AND b.pickup_datetime < :overlap_return
          AND b.return_datetime > :overlap_pickup
    )";
    $params['overlap_return'] = $returnSql;
    $params['overlap_pickup'] = $pickupSql;
}

$whereSql = implode(' AND ', $where);

$orderBySql = match ($sort) {
    'price_asc'  => 'v.daily_rate_lkr ASC, v.id ASC',
    'price_desc' => 'v.daily_rate_lkr DESC, v.id ASC',
    'seats_desc' => 'v.seats DESC, v.daily_rate_lkr ASC',
    'newest'     => 'v.year DESC, v.id ASC',
    default      => "CASE WHEN v.maintenance_status = 'available' THEN 0 ELSE 1 END ASC, v.is_featured DESC, v.daily_rate_lkr ASC",
};

// Count total matching rows for pagination
$countSql = "
    SELECT COUNT(*)
    FROM vehicles v
    JOIN vehicle_categories c ON c.id = v.category_id
    WHERE $whereSql
";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalMatching = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalMatching / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

// Fetch current page of vehicles
$listSql = "
    SELECT v.*, c.name AS category_name, c.slug AS category_slug, l.name AS location_name
    FROM vehicles v
    JOIN vehicle_categories c ON c.id = v.category_id
    JOIN locations l ON l.id = v.base_location_id
    WHERE $whereSql
    ORDER BY $orderBySql
    LIMIT :limit OFFSET :offset
";
$listStmt = $pdo->prepare($listSql);
foreach ($params as $k => $val) {
    $listStmt->bindValue(':' . $k, $val);
}
$listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStmt->execute();
$vehicles = $listStmt->fetchAll();

// Helper to preserve query parameters in pagination links
function build_catalogue_query(array $overrides = []): string
{
    $merged = array_merge($_GET, $overrides);
    $filtered = array_filter($merged, fn($v) => $v !== '' && $v !== null);
    return http_build_query($filtered);
}

$pageTitle = 'Vehicle Fleet Catalogue';
require_once __DIR__ . '/includes/header.php';
?>

<section class="page-header-band">
    <div class="container">
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-3">
            <div>
                <span class="text-orange fw-bold text-uppercase small">Negombo &amp; BIA Katunayake Inventory</span>
                <h1 class="fw-bold mb-1">Vehicle Fleet Catalogue</h1>
                <p class="mb-0 text-light opacity-75">Search by availability dates, category, transmission, seat capacity, and LKR daily budget.</p>
            </div>
            <div class="text-end">
                <span class="badge bg-light text-dark fs-6 px-3 py-2">
                    <?= $totalMatching ?> <?= $totalMatching === 1 ? 'Vehicle' : 'Vehicles' ?> Found
                </span>
            </div>
        </div>
    </div>
</section>

<div class="container pb-5">
    <?php if ($dateFilterError): ?>
        <div class="alert alert-danger d-flex align-items-center" role="alert">
            <i class="bi bi-exclamation-octagon-fill fs-5 me-2"></i>
            <div>
                <strong>Invalid Date Filter:</strong> <?= e($dateFilterError) ?> Showing all active fleet vehicles below.
            </div>
        </div>
    <?php elseif ($dateFilterActive): ?>
        <div class="alert alert-success d-flex flex-wrap justify-content-between align-items-center gap-2" role="alert">
            <div>
                <i class="bi bi-calendar2-check-fill me-2"></i>
                <strong>Availability Filter Active:</strong> Showing only vehicles available from
                <strong><?= e(date('d M Y, H:i', strtotime($pickupSql))) ?></strong> to
                <strong><?= e(date('d M Y, H:i', strtotime($returnSql))) ?></strong>.
            </div>
            <a href="<?= e(url('vehicles.php?' . build_catalogue_query(['pickup_datetime' => '', 'return_datetime' => '', 'page' => 1]))) ?>" class="btn btn-sm btn-outline-success">
                Clear Date Filter
            </a>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Sidebar Filter Form -->
        <div class="col-lg-3">
            <div class="card border shadow-sm">
                <div class="card-header bg-navy text-white py-3 d-flex justify-content-between align-items-center">
                    <h2 class="h6 fw-bold mb-0"><i class="bi bi-funnel-fill text-orange me-2"></i>Filter Fleet</h2>
                    <a href="<?= e(url('vehicles.php')) ?>" class="text-warning small text-decoration-none">Reset All</a>
                </div>
                <div class="card-body">
                    <form action="<?= e(url('vehicles.php')) ?>" method="get">
                        <div class="mb-3">
                            <label for="filter_q" class="form-label fw-semibold small">Keyword Search</label>
                            <input type="text" id="filter_q" name="q" class="form-control form-control-sm" placeholder="e.g. Toyota, Prado, Hybrid" value="<?= e($q) ?>">
                        </div>

                        <div class="mb-3">
                            <label for="filter_category" class="form-label fw-semibold small">Category</label>
                            <select id="filter_category" name="category" class="form-select form-select-sm">
                                <option value="">All Categories</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= e($cat['slug']) ?>" <?= $categorySlug === $cat['slug'] ? 'selected' : '' ?>>
                                        <?= e($cat['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="filter_location" class="form-label fw-semibold small">Base Hub Location</label>
                            <select id="filter_location" name="location_id" class="form-select form-select-sm">
                                <option value="">All Hubs</option>
                                <?php foreach ($locations as $loc): ?>
                                    <option value="<?= (int)$loc['id'] ?>" <?= $locationId === (int)$loc['id'] ? 'selected' : '' ?>>
                                        <?= e($loc['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="filter_pickup" class="form-label fw-semibold small">Pickup Date &amp; Time</label>
                            <input type="datetime-local" id="filter_pickup" name="pickup_datetime" class="form-control form-control-sm" value="<?= e($pickupDatetime) ?>">
                        </div>

                        <div class="mb-3">
                            <label for="filter_return" class="form-label fw-semibold small">Return Date &amp; Time</label>
                            <input type="datetime-local" id="filter_return" name="return_datetime" class="form-control form-control-sm" value="<?= e($returnDatetime) ?>">
                        </div>

                        <div class="mb-3">
                            <label for="filter_trans" class="form-label fw-semibold small">Transmission</label>
                            <select id="filter_trans" name="transmission" class="form-select form-select-sm">
                                <option value="">Any Transmission</option>
                                <option value="Automatic" <?= $transmission === 'Automatic' ? 'selected' : '' ?>>Automatic</option>
                                <option value="Manual" <?= $transmission === 'Manual' ? 'selected' : '' ?>>Manual</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="filter_seats" class="form-label fw-semibold small">Minimum Seats</label>
                            <select id="filter_seats" name="min_seats" class="form-select form-select-sm">
                                <option value="0">Any Capacity</option>
                                <option value="4" <?= $minSeats === 4 ? 'selected' : '' ?>>4+ Seats</option>
                                <option value="5" <?= $minSeats === 5 ? 'selected' : '' ?>>5+ Seats</option>
                                <option value="7" <?= $minSeats === 7 ? 'selected' : '' ?>>7+ Seats</option>
                                <option value="10" <?= $minSeats === 10 ? 'selected' : '' ?>>10+ Seats</option>
                            </select>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label for="filter_min_p" class="form-label fw-semibold small">Min LKR/day</label>
                                <input type="number" id="filter_min_p" name="min_price" class="form-control form-control-sm" placeholder="6000" min="0" step="500" value="<?= $minPrice !== null ? e((string)$minPrice) : '' ?>">
                            </div>
                            <div class="col-6">
                                <label for="filter_max_p" class="form-label fw-semibold small">Max LKR/day</label>
                                <input type="number" id="filter_max_p" name="max_price" class="form-control form-control-sm" placeholder="45000" min="0" step="500" value="<?= $maxPrice !== null ? e((string)$maxPrice) : '' ?>">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="filter_sort" class="form-label fw-semibold small">Sort By</label>
                            <select id="filter_sort" name="sort" class="form-select form-select-sm">
                                <option value="recommended" <?= $sort === 'recommended' ? 'selected' : '' ?>>Recommended</option>
                                <option value="price_asc" <?= $sort === 'price_asc' ? 'selected' : '' ?>>Daily Rate: Low to High</option>
                                <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>Daily Rate: High to Low</option>
                                <option value="seats_desc" <?= $sort === 'seats_desc' ? 'selected' : '' ?>>Seat Capacity: High to Low</option>
                                <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Model Year: Newest</option>
                            </select>
                        </div>

                        <button type="submit" class="btn btn-accent w-100 btn-sm py-2">
                            <i class="bi bi-search me-1"></i>Apply Filters
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Vehicle Results Grid -->
        <div class="col-lg-9">
            <?php if (empty($vehicles)): ?>
                <div class="card border shadow-sm text-center p-5">
                    <div class="mb-3">
                        <i class="bi bi-car-front text-muted" style="font-size: 3rem;"></i>
                    </div>
                    <h3 class="h5 fw-bold">No Matching Vehicles Available</h3>
                    <p class="text-muted small max-w-500 mx-auto mb-4">
                        We couldn’t find any vehicles matching your current filter criteria or requested dates. Try broadening your price range, selecting "All Categories", or adjusting your pickup/return dates.
                    </p>
                    <div>
                        <a href="<?= e(url('vehicles.php')) ?>" class="btn btn-accent">Reset All Filters</a>
                    </div>
                </div>
            <?php else: ?>
                <div class="row g-4">
                    <?php foreach ($vehicles as $v): ?>
                        <?php
                        $detailParams = ['id' => (int)$v['id']];
                        if ($dateFilterActive) {
                            $detailParams['pickup_datetime'] = $pickupDatetime;
                            $detailParams['return_datetime'] = $returnDatetime;
                        }
                        if ($locationId > 0) {
                            $detailParams['pickup_location_id'] = $locationId;
                        }
                        $detailUrl = 'vehicle.php?' . http_build_query($detailParams);
                        ?>
                        <div class="col-md-6">
                            <article class="vehicle-card">
                                <div class="vehicle-card-img-wrap">
                                    <img src="<?= e(url($v['primary_image'])) ?>" alt="<?= e($v['brand'] . ' ' . $v['model']) ?>" loading="lazy">
                                    <span class="vehicle-category-pill"><?= e($v['category_name']) ?></span>
                                    <?php if ($v['maintenance_status'] !== 'available'): ?>
                                        <span class="badge bg-warning text-dark position-absolute top-0 end-0 m-2">
                                            <i class="bi bi-tools me-1"></i>In Maintenance
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-success position-absolute top-0 end-0 m-2">
                                            <i class="bi bi-check-circle me-1"></i>Available
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <div class="p-3 p-lg-4 d-flex flex-column flex-grow-1">
                                    <div class="d-flex justify-content-between align-items-start mb-1">
                                        <h3 class="h5 fw-bold mb-0"><?= e($v['brand'] . ' ' . $v['model']) ?></h3>
                                        <span class="badge bg-light text-dark border"><?= (int)$v['year'] ?></span>
                                    </div>
                                    <div class="text-muted small mb-2">
                                        <i class="bi bi-geo-alt text-orange me-1"></i>Base: <?= e($v['location_name']) ?>
                                        &bull; Reg: <code><?= e($v['registration_number']) ?></code>
                                    </div>

                                    <p class="small text-secondary mb-3"><?= e($v['description']) ?></p>

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
                                                <span class="text-muted d-block">Weekly Rate (7 Days)</span>
                                                <strong class="text-success"><?= e(format_lkr($v['weekly_rate_lkr'])) ?></strong>
                                            </div>
                                        </div>

                                        <div class="d-grid">
                                            <a href="<?= e(url($detailUrl)) ?>" class="btn <?= $v['maintenance_status'] === 'available' ? 'btn-accent' : 'btn-outline-secondary' ?>">
                                                <?= $v['maintenance_status'] === 'available' ? 'Inspect Specs &amp; Book Vehicle' : 'View Vehicle Details (In Workshop)' ?>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </article>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <nav class="mt-4 d-flex justify-content-center" aria-label="Catalogue pagination">
                        <ul class="pagination">
                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= e(url('vehicles.php?' . build_catalogue_query(['page' => $page - 1]))) ?>">Previous</a>
                            </li>
                            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                    <a class="page-link" href="<?= e(url('vehicles.php?' . build_catalogue_query(['page' => $p]))) ?>"><?= $p ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= e(url('vehicles.php?' . build_catalogue_query(['page' => $page + 1]))) ?>">Next</a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>


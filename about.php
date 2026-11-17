<?php
/**
 * About Page - Company Overview & Negombo Pickup/Drop-off Hubs
 */
require_once __DIR__ . '/includes/auth.php';

$pdo = Database::getConnection();
$locations = $pdo->query("SELECT * FROM locations WHERE is_active = 1 ORDER BY id ASC")->fetchAll();
$fleetCount = (int)$pdo->query("SELECT COUNT(*) FROM vehicles WHERE is_active = 1")->fetchColumn();

$pageTitle = 'About Us & Negombo Locations';
require_once __DIR__ . '/includes/header.php';
?>

<section class="page-header-band">
    <div class="container">
        <span class="text-orange fw-bold text-uppercase small">Established in Negombo, Sri Lanka</span>
        <h1 class="fw-bold mb-2">About SwiftDrive Rentals</h1>
        <p class="mb-0 text-light opacity-75">Connecting Negombo’s coastal hospitality belt and Bandaranaike International Airport with dependable self-drive mobility.</p>
    </div>
</section>

<div class="container pb-5">
    <div class="row g-4 g-lg-5 align-items-center mb-5">
        <div class="col-lg-7">
            <h2 class="h3 fw-bold mb-3">Our Story &amp; Operational Standards</h2>
            <p>
                Headquartered on <strong>Poruthota Road in Ettukala, Negombo</strong>—just 15 minutes from Bandaranaike International Airport (BIA Katunayake)—<strong>SwiftDrive Rentals (Pvt) Ltd</strong> was founded to modernize vehicle hire along Sri Lanka’s western coast.
            </p>
            <p>
                Whether you are an international tourist arriving on a red-eye flight, a local family planning an upcountry holiday to Kandy and Nuwara Eliya, or a corporate executive requiring a prestige saloon, our digital platform eliminates guesswork. Every reservation is backed by real-time availability checks, transparent Sri Lankan Rupee (LKR) daily and weekly tariffs, and documented pre-rental and post-rental inspections.
            </p>
            <div class="row g-3 mt-3">
                <div class="col-sm-4">
                    <div class="kpi-card accent">
                        <div class="fs-3 fw-bold text-dark"><?= $fleetCount ?>+</div>
                        <div class="small text-muted">Inspected Fleet Vehicles</div>
                    </div>
                </div>
                <div class="col-sm-4">
                    <div class="kpi-card">
                        <div class="fs-3 fw-bold text-dark">4 Hubs</div>
                        <div class="small text-muted">Negombo &amp; BIA Katunayake</div>
                    </div>
                </div>
                <div class="col-sm-4">
                    <div class="kpi-card accent">
                        <div class="fs-3 fw-bold text-dark">100%</div>
                        <div class="small text-muted">Comprehensive Rental Cover</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card border-0 shadow-sm bg-navy text-white p-4">
                <h3 class="h5 fw-bold mb-3"><i class="bi bi-compass text-orange me-2"></i>Negombo Operations Centre</h3>
                <p class="small text-light opacity-75">
                    Our main yard and service workshop is situated along the Lewis Place / Poruthota hotel strip in Negombo, allowing rapid dispatch to beach resorts, Negombo Railway Station, and the Airport Expressway interchange.
                </p>
                <ul class="list-unstyled small d-flex flex-column gap-2 mb-4">
                    <li><i class="bi bi-check2-circle text-orange me-2"></i>AA Ceylon / Temporary Sri Lankan Driving Permit guidance</li>
                    <li><i class="bi bi-check2-circle text-orange me-2"></i>Multi-point brake, tyre, and AC inspection before every handover</li>
                    <li><i class="bi bi-check2-circle text-orange me-2"></i>Documented 8-point fuel &amp; odometer dispatch logs</li>
                    <li><i class="bi bi-check2-circle text-orange me-2"></i>24-hour emergency replacement vehicle guarantee</li>
                </ul>
                <a href="<?= e(url('contact.php')) ?>" class="btn btn-accent">Contact Our Negombo Desk</a>
            </div>
        </div>
    </div>

    <h2 class="h3 fw-bold mb-4">Our Pickup &amp; Drop-Off Locations</h2>
    <div class="row g-4">
        <?php foreach ($locations as $loc): ?>
            <div class="col-md-6">
                <div class="card h-100 border shadow-sm">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <h3 class="h5 fw-bold mb-0"><?= e($loc['name']) ?></h3>
                            <span class="badge bg-navy"><?= e($loc['code']) ?></span>
                        </div>
                        <p class="small text-muted mb-2"><i class="bi bi-geo-alt-fill text-orange me-2"></i><?= e($loc['address']) ?></p>
                        <div class="d-flex flex-wrap gap-3 small mb-3">
                            <span><i class="bi bi-telephone text-orange me-1"></i><?= e($loc['phone']) ?></span>
                            <span><i class="bi bi-clock text-orange me-1"></i><?= e($loc['opening_hours']) ?></span>
                        </div>
                        <div class="pt-2 border-top d-flex justify-content-between align-items-center small">
                            <span>Location Handover Surcharge:</span>
                            <strong class="<?= (float)$loc['surcharge_lkr'] > 0 ? 'text-dark' : 'text-success' ?>">
                                <?= (float)$loc['surcharge_lkr'] > 0 ? e(format_lkr($loc['surcharge_lkr'])) : 'Free (LKR 0.00)' ?>
                            </strong>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>


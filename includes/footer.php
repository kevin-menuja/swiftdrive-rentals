<?php
/**
 * Shared Footer Component
 */
?>
</main>

<footer class="footer-swiftdrive no-print">
    <div class="container">
        <div class="row g-4">
            <div class="col-lg-4">
                <h5 class="text-white fw-bold mb-3">
                    <i class="bi bi-car-front-fill text-orange me-2"></i>SwiftDrive Rentals
                </h5>
                <p class="small mb-3">
                    Negombo’s trusted self-drive and airport transfer fleet operator. Serving coastal travellers, corporate teams, and Sri Lankan families with transparent LKR rates and 24/7 roadside assistance.
                </p>
                <div class="small">
                    <div><i class="bi bi-geo-alt text-orange me-2"></i>No. 148, Poruthota Road, Ettukala, Negombo</div>
                    <div class="mt-1"><i class="bi bi-airplane text-orange me-2"></i>Arrivals Counter R-04, BIA Katunayake</div>
                    <div class="mt-1"><i class="bi bi-envelope text-orange me-2"></i>reservations@swiftdrive.lk</div>
                </div>
            </div>

            <div class="col-6 col-lg-2">
                <h6 class="text-white fw-semibold mb-3">Explore</h6>
                <ul class="list-unstyled small d-flex flex-column gap-2">
                    <li><a href="<?= e(url('index.php')) ?>">Home</a></li>
                    <li><a href="<?= e(url('vehicles.php')) ?>">Vehicle Catalogue</a></li>
                    <li><a href="<?= e(url('about.php')) ?>">About &amp; Hubs</a></li>
                    <li><a href="<?= e(url('terms.php')) ?>">Rental Terms</a></li>
                    <li><a href="<?= e(url('contact.php')) ?>">Contact Us</a></li>
                </ul>
            </div>

            <div class="col-6 col-lg-3">
                <h6 class="text-white fw-semibold mb-3">Fleet Categories</h6>
                <ul class="list-unstyled small d-flex flex-column gap-2">
                    <li><a href="<?= e(url('vehicles.php?category=economy')) ?>">Economy Hybrid Cars</a></li>
                    <li><a href="<?= e(url('vehicles.php?category=suv')) ?>">SUVs &amp; 4WD Crossovers</a></li>
                    <li><a href="<?= e(url('vehicles.php?category=van')) ?>">Passenger &amp; Tour Vans</a></li>
                    <li><a href="<?= e(url('vehicles.php?category=luxury')) ?>">Luxury &amp; Executive Cars</a></li>
                </ul>
            </div>

            <div class="col-lg-3">
                <h6 class="text-white fw-semibold mb-3">Pricing &amp; Security Policy</h6>
                <p class="small mb-0">
                    <i class="bi bi-shield-check text-orange me-1"></i>
                    All bookings require a <strong>25% reservation deposit</strong>. Multi-week rentals automatically receive our discounted 7-day bundle tariff.
                </p>
            </div>
        </div>

        <hr class="border-secondary border-opacity-25 my-4">

        <div class="d-flex flex-wrap justify-content-between align-items-center small text-secondary">
            <div>&copy; <?= date('Y') ?> SwiftDrive Rentals (Pvt) Ltd, Negombo, Sri Lanka. All rights reserved.</div>
            <div>24/7 Negombo &amp; BIA Katunayake Airport Mobility</div>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script src="<?= e(url('assets/js/app.js')) ?>"></script>
</body>
</html>


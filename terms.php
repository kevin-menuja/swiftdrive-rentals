<?php
/**
 * Rental Terms & Transparent Pricing Rules Page
 * Clearly documents rental-day rounding, weekly bundle rates, reservation statuses,
 * date overlap convention, cancellation cutoffs, and late/fuel/mileage fees.
 */
require_once __DIR__ . '/includes/auth.php';

$config = require __DIR__ . '/config/config.php';
$pageTitle = 'Rental Terms, Pricing & Policies';
require_once __DIR__ . '/includes/header.php';
?>

<section class="page-header-band">
    <div class="container">
        <span class="text-orange fw-bold text-uppercase small">Transparent Policies</span>
        <h1 class="fw-bold mb-2">Rental Terms, Pricing &amp; Deposit Conditions</h1>
        <p class="mb-0 text-light opacity-75">Clear, documented calculation rules for daily/weekly rates, deposits, cancellations, and return inspections.</p>
    </div>
</section>

<div class="container pb-5">
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card border shadow-sm mb-4">
                <div class="card-body p-4">
                    <h2 class="h4 fw-bold mb-3"><i class="bi bi-calculator text-orange me-2"></i>1. Rental-Day Rounding &amp; Weekly Rate Calculation Rules</h2>
                    <p class="small text-muted">
                        All rental quotes are calculated on the server using stored vehicle tariffs in Sri Lankan Rupees (LKR):
                    </p>
                    <ul class="small d-flex flex-column gap-2">
                        <li>
                            <strong>24-Hour Billing Block Rounding:</strong> Rental duration is measured from the scheduled pickup timestamp to the scheduled return timestamp. Any partial 24-hour period is rounded up to the next whole rental day:
                            <code>total_days = max(1, ceil((return_timestamp - pickup_timestamp) / 86400))</code>.
                        </li>
                        <li>
                            <strong>Automatic Weekly Bundle Discount:</strong> Every full 7-day block is billed at the vehicle’s discounted <strong>Weekly Rate</strong> rather than 7 individual daily rates:
                            <code>full_weeks = intdiv(total_days, 7)</code> and <code>remaining_days = total_days % 7</code>.
                        </li>
                        <li>
                            <strong>Remainder Day Cap:</strong> Extra days beyond complete weeks are billed at <code>remaining_days &times; daily_rate_lkr</code>, capped at the weekly rate if 6 daily rates would exceed a full week’s tariff.
                        </li>
                        <li>
                            <strong>Location Surcharges:</strong> Pickup or return at Negombo Beach HQ or Negombo Downtown incurs <strong>LKR 0.00</strong> surcharge. Airport Concourse (BIA Katunayake) handovers carry a flat <strong>LKR 2,500.00</strong> airport logistics fee, and Waikkal Resort Desk handovers carry <strong>LKR 1,500.00</strong>.
                        </li>
                    </ul>
                </div>
            </div>

            <div class="card border shadow-sm mb-4">
                <div class="card-body p-4">
                    <h2 class="h4 fw-bold mb-3"><i class="bi bi-calendar-check text-orange me-2"></i>2. Availability &amp; Date Interval Overlap Convention</h2>
                    <ul class="small d-flex flex-column gap-2">
                        <li>
                            <strong>Half-Open Interval Convention <code>[pickup_datetime, return_datetime)</code>:</strong> Two bookings overlap if and only if <code>existing.pickup_datetime &lt; requested.return_datetime AND existing.return_datetime &gt; requested.pickup_datetime</code>. This allows a vehicle returned at 10:00 AM to be booked for a new pickup starting at 10:00 AM.
                        </li>
                        <li>
                            <strong>Statuses That Reserve Availability:</strong> Bookings in <span class="badge bg-warning text-dark">pending</span>, <span class="badge bg-primary">confirmed</span>, or <span class="badge bg-info text-dark">picked_up</span> status actively lock the vehicle for that period.
                        </li>
                        <li>
                            <strong>Statuses That Release Availability:</strong> Once a booking transitions to <span class="badge bg-success">completed</span>, <span class="badge bg-secondary">cancelled</span>, or <span class="badge bg-danger">rejected</span>, those dates are immediately freed for new bookings.
                        </li>
                        <li>
                            <strong>Concurrency Protection:</strong> When a booking is submitted, the server opens an InnoDB transaction, locks the vehicle row using <code>SELECT ... FOR UPDATE</code>, and re-verifies that no overlapping reservation was created milliseconds earlier.
                        </li>
                    </ul>
                </div>
            </div>

            <div class="card border shadow-sm mb-4">
                <div class="card-body p-4">
                    <h2 class="h4 fw-bold mb-3"><i class="bi bi-credit-card text-orange me-2"></i>3. Reservation Deposit &amp; Cancellation Rules</h2>
                    <ul class="small d-flex flex-column gap-2">
                        <li>
                            <strong>25% Reservation &amp; Security Deposit:</strong> To secure a booking, customers pay a <strong>25% deposit</strong> of the total rental amount (or may choose to pay 100% upfront). The remaining 75% balance is settled upon vehicle collection or return.
                        </li>
                        <li>
                            <strong>Customer Self-Service Cancellation Policy:</strong>
                            Customers may cancel bookings in <code>pending</code> or <code>confirmed</code> status directly from their portal provided the scheduled pickup time is <strong>at least <?= (int)$config['cancellation_cutoff_hours'] ?> hours in the future</strong> (or at any time if the booking is still <code>pending</code> and unpaid).
                        </li>
                        <li>
                            <strong>Within 24 Hours of Pickup:</strong> Confirmed bookings within 24 hours of scheduled pickup cannot be self-cancelled online and require contacting our Negombo dispatch desk so staff can coordinate fleet scheduling.
                        </li>
                    </ul>
                </div>
            </div>

            <div class="card border shadow-sm">
                <div class="card-body p-4">
                    <h2 class="h4 fw-bold mb-3"><i class="bi bi-speedometer2 text-orange me-2"></i>4. Mileage, Fuel Level &amp; Late-Return Penalty Calculator</h2>
                    <p class="small text-muted">
                        Upon pickup and return, SwiftDrive staff record the exact odometer reading (km), fuel tank level (in eighths from <code>1/8</code> to <code>8/8</code>), and physical condition notes:
                    </p>
                    <ul class="small d-flex flex-column gap-2 mb-0">
                        <li>
                            <strong>Mileage Allowance:</strong> Every rental includes <strong><?= (int)$config['excess_km_daily_allowance'] ?> km per billed rental day</strong> (e.g., 450 km for a 3-day hire). Excess distance is calculated automatically at return using the category rate (Economy: LKR 55/km, SUV: LKR 85/km, Van: LKR 95/km, Luxury: LKR 140/km).
                        </li>
                        <li>
                            <strong>Same-to-Same Fuel Policy:</strong> Vehicles must be returned with at least the same fuel level recorded at pickup. Each <code>1/8</code> tank deficit is billed at a flat <strong><?= e(format_lkr($config['fuel_refill_unit_charge'])) ?></strong> refuelling service rate.
                        </li>
                        <li>
                            <strong>Late-Return Penalty Calculator:</strong> We grant a <strong>60-minute grace period</strong> past the scheduled return time. Returns more than 60 minutes late incur an hourly late fee equal to <strong>15% of the vehicle’s daily rate per late hour</strong> (rounded up to the next hour).
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm bg-navy text-white p-4 mb-4">
                <h3 class="h5 fw-bold mb-3"><i class="bi bi-file-earmark-person text-orange me-2"></i>Driver Eligibility Checklist</h3>
                <ul class="list-unstyled small d-flex flex-column gap-2 mb-0">
                    <li><i class="bi bi-check-lg text-orange me-2"></i>Minimum age: 21 years (25 years for Luxury &amp; 4WD Prado)</li>
                    <li><i class="bi bi-check-lg text-orange me-2"></i>Valid Sri Lankan Driving Licence or International Driving Permit (IDP) with AAC endorsement</li>
                    <li><i class="bi bi-check-lg text-orange me-2"></i>National Identity Card (NIC) or Valid Passport presented at pickup</li>
                </ul>
            </div>

            <div class="card border shadow-sm p-4">
                <h3 class="h6 fw-bold mb-2">Have a Special Rental Question?</h3>
                <p class="small text-muted mb-3">Need a baby seat, airport meet-and-greet signage, or a 30+ day corporate lease quote?</p>
                <a href="<?= e(url('contact.php')) ?>" class="btn btn-accent w-100">Send an Inquiry</a>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>


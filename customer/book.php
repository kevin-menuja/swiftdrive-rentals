<?php
/**
 * Booking Creation Endpoint (Server-Side Transactional Handler)
 * Enforces customer role, CSRF protection, server-side date validation,
 * vehicle row locking (FOR UPDATE), overlap re-check, and authoritative LKR pricing.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pricing.php';

$user = require_role('customer');
$pdo  = Database::getConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('vehicles.php');
}

verify_csrf();

$vehicleId        = (int)($_POST['vehicle_id'] ?? 0);
$pickupLocationId = (int)($_POST['pickup_location_id'] ?? 0);
$returnLocationId = (int)($_POST['return_location_id'] ?? 0);
$pickupDatetime   = trim($_POST['pickup_datetime'] ?? '');
$returnDatetime   = trim($_POST['return_datetime'] ?? '');
$customerNotes    = trim($_POST['customer_notes'] ?? '');

$result = PricingAndBookingService::createBookingTransactional(
    $pdo,
    (int)$user['id'],
    $vehicleId,
    $pickupLocationId,
    $returnLocationId,
    $pickupDatetime,
    $returnDatetime,
    $customerNotes
);

if (!$result['success']) {
    set_flash('danger', $result['error']);
    if ($vehicleId > 0) {
        redirect('vehicle.php?id=' . $vehicleId . '&pickup_datetime=' . urlencode($pickupDatetime) . '&return_datetime=' . urlencode($returnDatetime));
    }
    redirect('vehicles.php');
}

set_flash(
    'success',
    'Booking ' . $result['booking_reference'] . ' created! Complete your 25% reservation deposit ('
    . format_lkr($result['quote']['deposit_required_lkr']) . ') below to secure confirmation.'
);

redirect('customer/booking_details.php?id=' . (int)$result['booking_id']);


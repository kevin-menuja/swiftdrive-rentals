<?php
/**
 * Central Business Logic: Pricing, Date Overlap Detection, Concurrency-Safe Booking & Transitions
 *
 * DOCUMENTED CONVENTIONS:
 * 1. Date Interval Overlap Convention:
 *    Bookings use half-open intervals [pickup_datetime, return_datetime).
 *    Two intervals [A_start, A_end) and [B_start, B_end) overlap if and only if:
 *        A_start < B_end  AND  A_end > B_start
 *    This allows back-to-back turnaround at the exact boundary timestamp (e.g. Return at 10:00, next Pickup at 10:00).
 *
 * 2. Reservation Statuses:
 *    Statuses that lock/reserve vehicle availability: 'pending', 'confirmed', 'picked_up'.
 *    Statuses that release availability: 'completed', 'cancelled', 'rejected'.
 *
 * 3. Rental-Day Rounding & Weekly Rate Calculation Rules:
 *    - Duration in seconds = return_timestamp - pickup_timestamp.
 *    - Rental days are billed in 24-hour blocks rounded up: total_days = max(1, ceil(duration_seconds / 86400)).
 *    - Every complete 7-day block is billed at the discounted weekly_rate_lkr:
 *      full_weeks = intdiv(total_days, 7)
 *      remaining_days = total_days % 7
 *      raw_remainder_cost = remaining_days * daily_rate_lkr
 *      (If raw_remainder_cost > weekly_rate_lkr, it is capped at weekly_rate_lkr in the customer's favor.)
 *      base_rental_lkr = (full_weeks * weekly_rate_lkr) + min(raw_remainder_cost, weekly_rate_lkr)
 *    - Location Surcharge = pickup_location.surcharge_lkr + return_location.surcharge_lkr
 *    - Total Amount = base_rental_lkr + location_surcharge_lkr
 *    - Required Reservation Deposit = 25% of Total Amount (rounded to 2 decimal places).
 *
 * 4. Optional Extension: Late-Return Penalty & Fuel/Mileage Calculator:
 *    - Grace period: 1 hour (60 minutes) past scheduled return_datetime.
 *    - Beyond 60 minutes late: late_hours = ceil((actual_return_ts - scheduled_return_ts) / 3600).
 *    - Hourly late fee = 15% of vehicle's daily_rate_snapshot_lkr per late hour.
 *    - Mileage allowance = 150 km * total_days. Excess km billed at category excess_km_rate_lkr.
 *    - Fuel deficit = max(0, pickup_fuel_eighths - return_fuel_eighths) * LKR 3,500 per 1/8th tank.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

class PricingAndBookingService
{
    public const RESERVING_STATUSES = ['pending', 'confirmed', 'picked_up'];

    public const ALLOWED_TRANSITIONS = [
        'pending'   => ['confirmed', 'rejected', 'cancelled'],
        'confirmed' => ['picked_up', 'cancelled'],
        'picked_up' => ['completed'],
        'completed' => [],
        'cancelled' => [],
        'rejected'  => [],
    ];

    /**
     * Parse and validate pickup and return date/time strings.
     * Returns ['valid' => bool, 'error' => ?string, 'pickup_dt' => ?DateTimeImmutable, 'return_dt' => ?DateTimeImmutable]
     */
    public static function validateRentalDates(string $pickupStr, string $returnStr, bool $enforceFuturePickup = true): array
    {
        $pickupStr = trim($pickupStr);
        $returnStr = trim($returnStr);

        if ($pickupStr === '' || $returnStr === '') {
            return ['valid' => false, 'error' => 'Both pickup and return date/time are required.'];
        }

        try {
            $pickupDt = new DateTimeImmutable($pickupStr);
            $returnDt = new DateTimeImmutable($returnStr);
        } catch (Exception $e) {
            return ['valid' => false, 'error' => 'Invalid date/time format provided.'];
        }

        if ($enforceFuturePickup) {
            // Allow a 15-minute grace window for "immediate today" bookings
            $earliestAllowed = (new DateTimeImmutable('now'))->modify('-15 minutes');
            if ($pickupDt < $earliestAllowed) {
                return ['valid' => false, 'error' => 'Pickup date and time cannot be in the past.'];
            }
        }

        $diffSeconds = $returnDt->getTimestamp() - $pickupDt->getTimestamp();
        if ($diffSeconds < 3600) {
            return ['valid' => false, 'error' => 'Return date/time must be at least 1 hour after the pickup date/time.'];
        }

        if ($diffSeconds > (90 * 86400)) {
            return ['valid' => false, 'error' => 'Online reservations are limited to a maximum of 90 days. Please submit an inquiry for longer corporate leases.'];
        }

        return [
            'valid'     => true,
            'error'     => null,
            'pickup_dt' => $pickupDt,
            'return_dt' => $returnDt,
            'pickup_sql'=> $pickupDt->format('Y-m-d H:i:s'),
            'return_sql'=> $returnDt->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Calculate exact server-side rental quote and breakdown
     */
    public static function calculateQuote(
        array $vehicle,
        DateTimeImmutable $pickupDt,
        DateTimeImmutable $returnDt,
        float $pickupSurcharge = 0.0,
        float $returnSurcharge = 0.0
    ): array {
        $config = require __DIR__ . '/../config/config.php';
        $depositRate = (float)($config['deposit_rate'] ?? 0.25);

        $diffSeconds = max(3600, $returnDt->getTimestamp() - $pickupDt->getTimestamp());
        $totalDays   = (int)max(1, ceil($diffSeconds / 86400));

        $dailyRate  = (float)$vehicle['daily_rate_lkr'];
        $weeklyRate = (float)$vehicle['weekly_rate_lkr'];

        $fullWeeks     = intdiv($totalDays, 7);
        $remainingDays = $totalDays % 7;

        $remainderCost = $remainingDays * $dailyRate;
        $cappedRemainder = ($weeklyRate > 0 && $remainderCost > $weeklyRate) ? $weeklyRate : $remainderCost;

        $baseRental = round(($fullWeeks * $weeklyRate) + $cappedRemainder, 2);
        $standardUnbundled = round($totalDays * $dailyRate, 2);
        $weeklySavings = max(0.0, round($standardUnbundled - $baseRental, 2));

        $locationSurcharge = round($pickupSurcharge + $returnSurcharge, 2);
        $totalAmount       = round($baseRental + $locationSurcharge, 2);
        $depositRequired   = round($totalAmount * $depositRate, 2);
        $remainingBalance  = round($totalAmount - $depositRequired, 2);

        return [
            'total_days'             => $totalDays,
            'full_weeks'             => $fullWeeks,
            'remaining_days'         => $remainingDays,
            'daily_rate_lkr'         => $dailyRate,
            'weekly_rate_lkr'        => $weeklyRate,
            'base_rental_lkr'        => $baseRental,
            'weekly_savings_lkr'     => $weeklySavings,
            'location_surcharge_lkr' => $locationSurcharge,
            'total_amount_lkr'       => $totalAmount,
            'deposit_rate_pct'       => (int)round($depositRate * 100),
            'deposit_required_lkr'   => $depositRequired,
            'remaining_balance_lkr'  => $remainingBalance,
            'included_km'            => $totalDays * (int)($config['excess_km_daily_allowance'] ?? 150),
        ];
    }

    /**
     * Check if a vehicle has any overlapping reservation in [pickup_sql, return_sql)
     */
    public static function hasOverlappingBooking(
        PDO $pdo,
        int $vehicleId,
        string $pickupSql,
        string $returnSql,
        ?int $excludeBookingId = null
    ): bool {
        $sql = "SELECT COUNT(*) FROM bookings
                WHERE vehicle_id = :vehicle_id
                  AND booking_status IN ('pending', 'confirmed', 'picked_up')
                  AND pickup_datetime < :return_dt
                  AND return_datetime > :pickup_dt";
        $params = [
            'vehicle_id' => $vehicleId,
            'return_dt'  => $returnSql,
            'pickup_dt'  => $pickupSql,
        ];

        if ($excludeBookingId !== null) {
            $sql .= " AND id != :exclude_id";
            $params['exclude_id'] = $excludeBookingId;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Create a booking inside a database transaction with row-level locking (`SELECT ... FOR UPDATE`)
     * on the target vehicle to prevent concurrent double-bookings.
     */
    public static function createBookingTransactional(
        PDO $pdo,
        int $customerId,
        int $vehicleId,
        int $pickupLocationId,
        int $returnLocationId,
        string $pickupStr,
        string $returnStr,
        string $customerNotes = ''
    ): array {
        $dateValidation = self::validateRentalDates($pickupStr, $returnStr, true);
        if (!$dateValidation['valid']) {
            return ['success' => false, 'error' => $dateValidation['error']];
        }

        $pickupDt  = $dateValidation['pickup_dt'];
        $returnDt  = $dateValidation['return_dt'];
        $pickupSql = $dateValidation['pickup_sql'];
        $returnSql = $dateValidation['return_sql'];

        try {
            $pdo->beginTransaction();

            // 1. Lock the vehicle row with FOR UPDATE so concurrent requests serialize on this vehicle
            $vStmt = $pdo->prepare("SELECT * FROM vehicles WHERE id = :id FOR UPDATE");
            $vStmt->execute(['id' => $vehicleId]);
            $vehicle = $vStmt->fetch();

            if (!$vehicle || (int)$vehicle['is_active'] !== 1 || $vehicle['maintenance_status'] !== 'available') {
                $pdo->rollBack();
                return [
                    'success' => false,
                    'error'   => 'This vehicle is currently unavailable or in maintenance and cannot be reserved.',
                ];
            }

            // 2. Verify pickup and return locations
            $locStmt = $pdo->prepare("SELECT id, name, surcharge_lkr FROM locations WHERE id IN (:p_id, :r_id) AND is_active = 1");
            $locStmt->execute(['p_id' => $pickupLocationId, 'r_id' => $returnLocationId]);
            $locs = [];
            foreach ($locStmt->fetchAll() as $row) {
                $locs[(int)$row['id']] = $row;
            }

            if (!isset($locs[$pickupLocationId]) || !isset($locs[$returnLocationId])) {
                $pdo->rollBack();
                return ['success' => false, 'error' => 'Selected pickup or return location is invalid.'];
            }

            // 3. Re-check overlapping bookings while holding the vehicle row lock
            if (self::hasOverlappingBooking($pdo, $vehicleId, $pickupSql, $returnSql)) {
                $pdo->rollBack();
                return [
                    'success' => false,
                    'error'   => 'This vehicle is already reserved during the selected dates ('
                        . $pickupDt->format('d M Y H:i') . ' to ' . $returnDt->format('d M Y H:i')
                        . '). Please choose different dates or another vehicle.',
                ];
            }

            // 4. Calculate authoritative server-side pricing
            $quote = self::calculateQuote(
                $vehicle,
                $pickupDt,
                $returnDt,
                (float)$locs[$pickupLocationId]['surcharge_lkr'],
                (float)$locs[$returnLocationId]['surcharge_lkr']
            );

            // 5. Generate unique booking reference
            $reference = 'SDR-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(3)));

            $insertStmt = $pdo->prepare("
                INSERT INTO bookings (
                    booking_reference, customer_id, vehicle_id,
                    pickup_location_id, return_location_id,
                    pickup_datetime, return_datetime,
                    total_days, full_weeks, remaining_days,
                    daily_rate_snapshot_lkr, weekly_rate_snapshot_lkr,
                    base_rental_lkr, location_surcharge_lkr,
                    deposit_required_lkr, late_penalty_lkr, extra_charges_lkr,
                    total_amount_lkr, amount_paid_lkr, balance_due_lkr,
                    payment_status, booking_status, customer_notes
                ) VALUES (
                    :booking_reference, :customer_id, :vehicle_id,
                    :pickup_location_id, :return_location_id,
                    :pickup_datetime, :return_datetime,
                    :total_days, :full_weeks, :remaining_days,
                    :daily_rate_snapshot_lkr, :weekly_rate_snapshot_lkr,
                    :base_rental_lkr, :location_surcharge_lkr,
                    :deposit_required_lkr, 0.00, 0.00,
                    :total_amount_lkr, 0.00, :balance_due_lkr,
                    'unpaid', 'pending', :customer_notes
                )
            ");

            $insertStmt->execute([
                'booking_reference'       => $reference,
                'customer_id'             => $customerId,
                'vehicle_id'              => $vehicleId,
                'pickup_location_id'      => $pickupLocationId,
                'return_location_id'      => $returnLocationId,
                'pickup_datetime'         => $pickupSql,
                'return_datetime'         => $returnSql,
                'total_days'              => $quote['total_days'],
                'full_weeks'              => $quote['full_weeks'],
                'remaining_days'          => $quote['remaining_days'],
                'daily_rate_snapshot_lkr' => $quote['daily_rate_lkr'],
                'weekly_rate_snapshot_lkr'=> $quote['weekly_rate_lkr'],
                'base_rental_lkr'         => $quote['base_rental_lkr'],
                'location_surcharge_lkr'  => $quote['location_surcharge_lkr'],
                'deposit_required_lkr'    => $quote['deposit_required_lkr'],
                'total_amount_lkr'        => $quote['total_amount_lkr'],
                'balance_due_lkr'         => $quote['total_amount_lkr'],
                'customer_notes'          => trim($customerNotes) !== '' ? trim($customerNotes) : null,
            ]);

            $bookingId = (int)$pdo->lastInsertId();
            $pdo->commit();

            return [
                'success'           => true,
                'booking_id'        => $bookingId,
                'booking_reference' => $reference,
                'quote'             => $quote,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Database::logError('createBookingTransactional failed: ' . $e->getMessage());
            return [
                'success' => false,
                'error'   => 'An unexpected database error occurred while creating your booking. Please try again.',
            ];
        }
    }

    /**
     * Check if a booking status transition is valid
     */
    public static function canTransition(string $currentStatus, string $targetStatus): bool
    {
        $allowed = self::ALLOWED_TRANSITIONS[$currentStatus] ?? [];
        return in_array($targetStatus, $allowed, true);
    }

    /**
     * Calculate return inspection charges (Late penalty, Excess km, Fuel deficit)
     */
    public static function calculateReturnInspectionCharges(
        array $booking,
        int $pickupMileageKm,
        int $returnMileageKm,
        int $pickupFuelEighths,
        int $returnFuelEighths,
        string $actualReturnDatetimeStr,
        float $excessKmRateLkr,
        float $damageFeeLkr = 0.0
    ): array {
        $config = require __DIR__ . '/../config/config.php';
        $dailyAllowanceKm = (int)($config['excess_km_daily_allowance'] ?? 150);
        $fuelUnitCharge   = (float)($config['fuel_refill_unit_charge'] ?? 3500.00);
        $lateHourlyPct    = (float)($config['late_penalty_hourly_pct'] ?? 0.15);

        $totalDays          = max(1, (int)$booking['total_days']);
        $includedAllowance  = $totalDays * $dailyAllowanceKm;
        $distanceDriven     = max(0, $returnMileageKm - $pickupMileageKm);
        $excessKm           = max(0, $distanceDriven - $includedAllowance);
        $excessKmCharge     = round($excessKm * $excessKmRateLkr, 2);

        $fuelDeficitEighths = max(0, $pickupFuelEighths - $returnFuelEighths);
        $fuelCharge         = round($fuelDeficitEighths * $fuelUnitCharge, 2);

        // Late return calculation with 1-hour (3600s) grace period
        $scheduledReturnTs = strtotime($booking['return_datetime']);
        $actualReturnTs    = strtotime($actualReturnDatetimeStr) ?: time();
        $lateSeconds       = $actualReturnTs - $scheduledReturnTs;

        $lateHours   = 0;
        $latePenalty = 0.00;
        if ($lateSeconds > 3600) {
            $lateHours   = (int)ceil($lateSeconds / 3600);
            $hourlyFee   = (float)$booking['daily_rate_snapshot_lkr'] * $lateHourlyPct;
            $latePenalty = round($lateHours * $hourlyFee, 2);
        }

        $damageFee        = max(0.0, round($damageFeeLkr, 2));
        $extraCharges     = round($excessKmCharge + $fuelCharge + $damageFee, 2);

        return [
            'included_km_allowance' => $includedAllowance,
            'distance_driven_km'    => $distanceDriven,
            'excess_km'             => $excessKm,
            'excess_km_charge_lkr'  => $excessKmCharge,
            'fuel_deficit_eighths'  => $fuelDeficitEighths,
            'fuel_charge_lkr'       => $fuelCharge,
            'late_hours'            => $lateHours,
            'late_penalty_lkr'      => $latePenalty,
            'damage_fee_lkr'        => $damageFee,
            'extra_charges_lkr'     => $extraCharges,
        ];
    }
}


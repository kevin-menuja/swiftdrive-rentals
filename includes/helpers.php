<?php
/**
 * Application Helper Functions & CSRF Protection
 * Centralizes HTML escaping, currency formatting, flash messages, URL building, and CSRF validation.
 */

if (session_status() === PHP_SESSION_NONE) {
    // Configure secure session cookie parameters before starting session
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/**
 * Safely escape strings for HTML rendering (prevents XSS)
 */
function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Format decimal amount in Sri Lankan Rupees (LKR)
 */
function format_lkr($amount): string
{
    return 'LKR ' . number_format((float)$amount, 2, '.', ',');
}

/**
 * Generate or retrieve the current session CSRF token
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Output hidden CSRF input field for HTML forms
 */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/**
 * Verify CSRF token on state-changing POST requests
 */
function verify_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    $token = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$token)) {
        Database::logError('CSRF validation failed for URI: ' . ($_SERVER['REQUEST_URI'] ?? 'unknown'));
        set_flash('danger', 'Your session security token expired or was invalid. Please try submitting the form again.');
        $referer = $_SERVER['HTTP_REFERER'] ?? 'index.php';
        header('Location: ' . $referer);
        exit;
    }
}

/**
 * Store a flash notification message in session
 */
function set_flash(string $type, string $message): void
{
    $_SESSION['flash_messages'][] = [
        'type'    => $type,
        'message' => $message,
    ];
}

/**
 * Retrieve and clear all flash messages
 */
function get_flashes(): array
{
    $messages = $_SESSION['flash_messages'] ?? [];
    unset($_SESSION['flash_messages']);
    return $messages;
}

/**
 * Determine relative prefix ('', '../') based on current script directory depth
 */
function base_prefix(): string
{
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    if (preg_match('#/(customer|staff|admin)/[^/]+$#', $scriptName)) {
        return '../';
    }
    return '';
}

/**
 * Build a relative application URL safely from any root or subdirectory page
 */
function url(string $path = ''): string
{
    $prefix = base_prefix();
    $cleanPath = ltrim($path, '/');
    return $prefix . $cleanPath;
}

/**
 * Redirect and terminate script execution
 */
function redirect(string $path): void
{
    if (preg_match('#^https?://#i', $path)) {
        header('Location: ' . $path);
    } else {
        header('Location: ' . url($path));
    }
    exit;
}

/**
 * Return Bootstrap badge class for booking status
 */
function booking_status_badge(string $status): string
{
    return match ($status) {
        'pending'   => 'bg-warning text-dark',
        'confirmed' => 'bg-primary',
        'picked_up' => 'bg-info text-dark',
        'completed' => 'bg-success',
        'cancelled' => 'bg-secondary',
        'rejected'  => 'bg-danger',
        default     => 'bg-dark',
    };
}

/**
 * Return human-readable booking status label
 */
function booking_status_label(string $status): string
{
    return match ($status) {
        'pending'   => 'Pending Review',
        'confirmed' => 'Confirmed & Reserved',
        'picked_up' => 'Active Out on Rental',
        'completed' => 'Completed & Returned',
        'cancelled' => 'Cancelled',
        'rejected'  => 'Rejected',
        default     => ucfirst($status),
    };
}

/**
 * Return Bootstrap badge class for payment status
 */
function payment_status_badge(string $status): string
{
    return match ($status) {
        'fully_paid'   => 'bg-success',
        'deposit_paid' => 'bg-primary',
        'unpaid'       => 'bg-warning text-dark',
        'failed'       => 'bg-danger',
        'refunded'     => 'bg-secondary',
        default        => 'bg-secondary',
    };
}

/**
 * Return human-readable fuel level label from eighths (1..8)
 */
function fuel_eighths_label(?int $eighths): string
{
    if ($eighths === null) {
        return 'Not Recorded';
    }
    return match ($eighths) {
        8 => '8/8 (Full Tank - 100%)',
        7 => '7/8 (87.5%)',
        6 => '6/8 (Three-Quarter - 75%)',
        5 => '5/8 (62.5%)',
        4 => '4/8 (Half Tank - 50%)',
        3 => '3/8 (37.5%)',
        2 => '2/8 (Quarter Tank - 25%)',
        1 => '1/8 (Reserve - 12.5%)',
        default => $eighths . '/8',
    };
}


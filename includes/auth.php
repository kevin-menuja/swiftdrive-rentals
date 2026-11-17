<?php
/**
 * Authentication & Role-Based Authorization Module
 * Enforces server-side permissions, session regeneration, and account active checks.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

/**
 * Return currently logged-in user array or null
 */
function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    static $cachedUser = null;
    if ($cachedUser !== null && (int)$cachedUser['id'] === (int)$_SESSION['user_id']) {
        return $cachedUser;
    }

    $pdo = Database::getConnection();
    $stmt = $pdo->prepare('SELECT id, full_name, email, phone, nic_or_passport, license_number, address, role, is_active, created_at FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => (int)$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user || (int)$user['is_active'] !== 1) {
        // Account was deactivated or deleted while logged in
        logout_user();
        return null;
    }

    $cachedUser = $user;
    return $user;
}

/**
 * Log in user and regenerate session ID to prevent session fixation
 */
function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id']   = (int)$user['id'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['user_name'] = $user['full_name'];
    // Regenerate CSRF token upon privilege change
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * Destroy user session securely
 */
function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'] ?? '',
            (bool)$params['secure'],
            (bool)$params['httponly']
        );
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

/**
 * Require an authenticated user; redirect to login.php if unauthenticated
 */
function require_login(): array
{
    $user = current_user();
    if (!$user) {
        $_SESSION['intended_url'] = $_SERVER['REQUEST_URI'] ?? '';
        set_flash('warning', 'Please sign in to access that page.');
        redirect('login.php');
    }
    return $user;
}

/**
 * Require the logged-in user to have one of the allowed roles
 * @param array|string $roles e.g. 'admin' or ['staff', 'admin']
 */
function require_role(array|string $roles): array
{
    $user = require_login();
    $allowed = is_array($roles) ? $roles : [$roles];

    if (!in_array($user['role'], $allowed, true)) {
        http_response_code(403);
        set_flash('danger', 'Access Denied: Your account role (' . strtoupper($user['role']) . ') does not have permission to view that area.');
        redirect(role_home_path($user['role']));
    }
    return $user;
}

/**
 * Default dashboard route by user role
 */
function role_home_path(string $role): string
{
    return match ($role) {
        'admin'    => 'admin/index.php',
        'staff'    => 'staff/index.php',
        default    => 'customer/bookings.php',
    };
}


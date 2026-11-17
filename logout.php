<?php
/**
 * Logout Endpoint
 * Accepts POST with CSRF protection (or safe fallback redirect if accessed via GET).
 */
require_once __DIR__ . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    logout_user();
    session_start();
    set_flash('info', 'You have been signed out safely.');
}

redirect('login.php');


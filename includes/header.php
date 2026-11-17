<?php
/**
 * Shared Header & Responsive Navigation Bar
 * Displays role-aware links, accessibility skip link, and session flash messages.
 */
require_once __DIR__ . '/auth.php';

$currentUser = current_user();
$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
$currentDir  = basename(dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$pageTitle   = isset($pageTitle) ? $pageTitle . ' | SwiftDrive Rentals Negombo' : 'SwiftDrive Rentals | Car & Van Hire in Negombo, Sri Lanka';
$flashes     = get_flashes();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="SwiftDrive Rentals Negombo - Reliable economy cars, SUVs, vans, and luxury rentals in Negombo and Bandaranaike International Airport (BIA), Sri Lanka.">
    <title><?= e($pageTitle) ?></title>
    <!-- Bootstrap 5.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Custom Brand Styles -->
    <link href="<?= e(url('assets/css/style.css')) ?>" rel="stylesheet">
</head>
<body>
<a href="#main-content" class="skip-link">Skip to main content</a>

<!-- Top Contact Strip -->
<div class="bg-dark text-light py-1 small border-bottom border-secondary border-opacity-25 no-print">
    <div class="container d-flex flex-wrap justify-content-between align-items-center">
        <div>
            <i class="bi bi-geo-alt-fill text-orange me-1"></i> No. 148, Poruthota Road, Negombo &amp; BIA Katunayake Counter R-04
        </div>
        <div class="d-flex gap-3">
            <span><i class="bi bi-telephone-fill text-orange me-1"></i> +94 31 222 8890</span>
            <span class="d-none d-md-inline"><i class="bi bi-clock-fill text-orange me-1"></i> 24/7 Airport Dispatch</span>
        </div>
    </div>
</div>

<!-- Main Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark navbar-swiftdrive sticky-top no-print" aria-label="Main navigation">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2" href="<?= e(url('index.php')) ?>">
            <i class="bi bi-car-front-fill brand-accent fs-4"></i>
            <span>Swift<span class="brand-accent">Drive</span> Rentals</span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar" aria-controls="mainNavbar" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="mainNavbar">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item">
                    <a class="nav-link <?= ($currentPage === 'index.php' && !in_array($currentDir, ['customer','staff','admin'], true)) ? 'active' : '' ?>" href="<?= e(url('index.php')) ?>">Home</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= in_array($currentPage, ['vehicles.php', 'vehicle.php'], true) ? 'active' : '' ?>" href="<?= e(url('vehicles.php')) ?>">Vehicle Fleet</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $currentPage === 'about.php' ? 'active' : '' ?>" href="<?= e(url('about.php')) ?>">About &amp; Negombo Hubs</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $currentPage === 'terms.php' ? 'active' : '' ?>" href="<?= e(url('terms.php')) ?>">Rental Terms</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $currentPage === 'contact.php' ? 'active' : '' ?>" href="<?= e(url('contact.php')) ?>">Contact / Inquiry</a>
                </li>

                <?php if ($currentUser && $currentUser['role'] === 'customer'): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= $currentDir === 'customer' ? 'active' : '' ?>" href="<?= e(url('customer/bookings.php')) ?>">
                            <i class="bi bi-calendar-check me-1"></i>My Bookings
                        </a>
                    </li>
                <?php elseif ($currentUser && $currentUser['role'] === 'staff'): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= $currentDir === 'staff' ? 'active' : '' ?>" href="<?= e(url('staff/index.php')) ?>">
                            <i class="bi bi-speedometer2 me-1 text-orange"></i>Staff Portal
                        </a>
                    </li>
                <?php elseif ($currentUser && $currentUser['role'] === 'admin'): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= $currentDir === 'admin' ? 'active' : '' ?>" href="<?= e(url('admin/index.php')) ?>">
                            <i class="bi bi-shield-lock me-1 text-orange"></i>Admin Console
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $currentDir === 'staff' ? 'active' : '' ?>" href="<?= e(url('staff/index.php')) ?>">
                            <i class="bi bi-clipboard2-check me-1"></i>Dispatch Ops
                        </a>
                    </li>
                <?php endif; ?>
            </ul>

            <div class="d-flex align-items-center gap-2">
                <?php if ($currentUser): ?>
                    <div class="dropdown">
                        <button class="btn btn-outline-light btn-sm dropdown-toggle d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-person-circle text-orange"></i>
                            <span><?= e($currentUser['full_name']) ?></span>
                            <span class="badge bg-secondary text-uppercase"><?= e($currentUser['role']) ?></span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow">
                            <?php if ($currentUser['role'] === 'admin'): ?>
                                <li><a class="dropdown-item" href="<?= e(url('admin/index.php')) ?>"><i class="bi bi-speedometer2 me-2"></i>Admin Dashboard</a></li>
                                <li><a class="dropdown-item" href="<?= e(url('admin/vehicles.php')) ?>"><i class="bi bi-car-front me-2"></i>Manage Fleet</a></li>
                                <li><a class="dropdown-item" href="<?= e(url('admin/staff.php')) ?>"><i class="bi bi-people me-2"></i>Manage Staff</a></li>
                                <li><a class="dropdown-item" href="<?= e(url('staff/bookings.php')) ?>"><i class="bi bi-calendar2-week me-2"></i>Dispatch &amp; Bookings</a></li>
                                <li><hr class="dropdown-divider"></li>
                            <?php elseif ($currentUser['role'] === 'staff'): ?>
                                <li><a class="dropdown-item" href="<?= e(url('staff/index.php')) ?>"><i class="bi bi-speedometer2 me-2"></i>Staff Dashboard</a></li>
                                <li><a class="dropdown-item" href="<?= e(url('staff/bookings.php')) ?>"><i class="bi bi-calendar2-check me-2"></i>Manage Bookings</a></li>
                                <li><a class="dropdown-item" href="<?= e(url('staff/inquiries.php')) ?>"><i class="bi bi-envelope-paper me-2"></i>Customer Inquiries</a></li>
                                <li><hr class="dropdown-divider"></li>
                            <?php else: ?>
                                <li><a class="dropdown-item" href="<?= e(url('customer/bookings.php')) ?>"><i class="bi bi-journal-bookmark me-2"></i>My Bookings</a></li>
                            <?php endif; ?>
                            <li><a class="dropdown-item" href="<?= e(url('customer/profile.php')) ?>"><i class="bi bi-person-gear me-2"></i>My Profile</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <form action="<?= e(url('logout.php')) ?>" method="post" class="px-2">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-sm btn-danger w-100 text-start">
                                        <i class="bi bi-box-arrow-right me-2"></i>Sign Out
                                    </button>
                                </form>
                            </li>
                        </ul>
                    </div>
                <?php else: ?>
                    <a href="<?= e(url('login.php')) ?>" class="btn btn-outline-light btn-sm">Sign In</a>
                    <a href="<?= e(url('register.php')) ?>" class="btn btn-accent btn-sm">Register</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>

<main id="main-content">
<?php if (!empty($flashes)): ?>
    <div class="container mt-3 no-print">
        <?php foreach ($flashes as $flash): ?>
            <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show shadow-sm" role="alert">
                <?php if ($flash['type'] === 'success'): ?>
                    <i class="bi bi-check-circle-fill me-2"></i>
                <?php elseif ($flash['type'] === 'danger'): ?>
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <?php else: ?>
                    <i class="bi bi-info-circle-fill me-2"></i>
                <?php endif; ?>
                <?= e($flash['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>


<?php
/**
 * Administrator Fleet & Vehicle CRUD Page
 * Supports:
 * - Creating new vehicles with category, base location, LKR daily/weekly rates, specs, and validated image upload
 * - Editing existing vehicles, rates, maintenance status ('available', 'maintenance', 'retired'), and active/inactive state
 * - Adding/updating vehicle categories and excess km rates
 * - Preserving historical bookings when a vehicle is deactivated (soft deactivation via is_active = 0)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/upload.php';

$user = require_role('admin');
$pdo  = Database::getConnection();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_vehicle') {
        $vehicleId          = (int)($_POST['vehicle_id'] ?? 0);
        $categoryId         = (int)($_POST['category_id'] ?? 0);
        $baseLocationId     = (int)($_POST['base_location_id'] ?? 0);
        $regNumber          = strtoupper(trim($_POST['registration_number'] ?? ''));
        $brand              = trim($_POST['brand'] ?? '');
        $model              = trim($_POST['model'] ?? '');
        $year               = (int)($_POST['year'] ?? date('Y'));
        $transmission       = $_POST['transmission'] ?? 'Automatic';
        $fuelType           = $_POST['fuel_type'] ?? 'Petrol';
        $seats              = (int)($_POST['seats'] ?? 5);
        $luggage            = (int)($_POST['luggage_capacity'] ?? 2);
        $dailyRate          = (float)($_POST['daily_rate_lkr'] ?? 0);
        $weeklyRate         = (float)($_POST['weekly_rate_lkr'] ?? 0);
        $mileageKm          = max(0, (int)($_POST['mileage_km'] ?? 0));
        $maintenanceStatus  = $_POST['maintenance_status'] ?? 'available';
        $isActive           = isset($_POST['is_active']) ? 1 : 0;
        $isFeatured         = isset($_POST['is_featured']) ? 1 : 0;
        $description        = trim($_POST['description'] ?? '');
        $featuresList       = trim($_POST['features_list'] ?? 'Air Conditioning, Bluetooth Audio, ABS, Reverse Camera');
        $presetImage        = trim($_POST['preset_image'] ?? 'assets/images/vehicles/toyota-axio.jpg');

        if ($regNumber === '' || $brand === '' || $model === '') {
            $errors[] = 'Registration number, brand, and model are required.';
        }
        if ($dailyRate <= 0 || $weeklyRate <= 0) {
            $errors[] = 'Daily and weekly LKR rates must be greater than zero.';
        }
        if ($year < 2000 || $year > ((int)date('Y') + 1)) {
            $errors[] = 'Please enter a valid manufacture year.';
        }
        if (!in_array($maintenanceStatus, ['available', 'maintenance', 'retired'], true)) {
            $maintenanceStatus = 'available';
        }

        // Handle optional image file upload with strict MIME, size, and getimagesize validation
        $uploadResult = ['uploaded' => false, 'error' => null, 'path' => null];
        if (!empty($_FILES['vehicle_image']['name'])) {
            $uploadResult = UploadValidator::handleVehicleImageUpload($_FILES['vehicle_image']);
            if ($uploadResult['error']) {
                $errors[] = $uploadResult['error'];
            }
        }

        if (empty($errors)) {
            try {
                if ($vehicleId > 0) {
                    // Fetch current image if no new upload
                    $curStmt = $pdo->prepare("SELECT primary_image FROM vehicles WHERE id = :id");
                    $curStmt->execute(['id' => $vehicleId]);
                    $currentImg = $curStmt->fetchColumn() ?: $presetImage;
                    $finalImage = $uploadResult['uploaded'] ? $uploadResult['path'] : $currentImg;

                    $upd = $pdo->prepare("
                        UPDATE vehicles
                        SET category_id = :cat_id,
                            base_location_id = :loc_id,
                            registration_number = :reg,
                            brand = :brand,
                            model = :model,
                            year = :yr,
                            transmission = :trans,
                            fuel_type = :fuel,
                            seats = :seats,
                            luggage_capacity = :lug,
                            daily_rate_lkr = :daily,
                            weekly_rate_lkr = :weekly,
                            mileage_km = :mileage,
                            maintenance_status = :maint,
                            is_active = :active,
                            is_featured = :featured,
                            description = :descr,
                            features_list = :feats,
                            primary_image = :img
                        WHERE id = :id
                    ");
                    $upd->execute([
                        'cat_id'   => $categoryId,
                        'loc_id'   => $baseLocationId,
                        'reg'      => $regNumber,
                        'brand'    => $brand,
                        'model'    => $model,
                        'yr'       => $year,
                        'trans'    => $transmission,
                        'fuel'     => $fuelType,
                        'seats'    => $seats,
                        'lug'      => $luggage,
                        'daily'    => $dailyRate,
                        'weekly'   => $weeklyRate,
                        'mileage'  => $mileageKm,
                        'maint'    => $maintenanceStatus,
                        'active'   => $isActive,
                        'featured' => $isFeatured,
                        'descr'    => $description,
                        'feats'    => $featuresList,
                        'img'      => $finalImage,
                        'id'       => $vehicleId,
                    ]);

                    if ($uploadResult['uploaded']) {
                        $imgIns = $pdo->prepare("INSERT INTO vehicle_images (vehicle_id, image_path, caption, is_primary) VALUES (:vid, :path, :cap, 1)");
                        $imgIns->execute(['vid' => $vehicleId, 'path' => $finalImage, 'cap' => $brand . ' ' . $model]);
                    }

                    set_flash('success', 'Vehicle ' . $brand . ' ' . $model . ' (' . $regNumber . ') updated! Changes are immediately live in the customer catalogue.');
                } else {
                    $finalImage = $uploadResult['uploaded'] ? $uploadResult['path'] : $presetImage;

                    $ins = $pdo->prepare("
                        INSERT INTO vehicles (
                            category_id, base_location_id, registration_number, brand, model, year,
                            transmission, fuel_type, seats, luggage_capacity, air_conditioning,
                            daily_rate_lkr, weekly_rate_lkr, mileage_km, maintenance_status,
                            is_active, is_featured, description, features_list, primary_image
                        ) VALUES (
                            :cat_id, :loc_id, :reg, :brand, :model, :yr,
                            :trans, :fuel, :seats, :lug, 1,
                            :daily, :weekly, :mileage, :maint,
                            :active, :featured, :descr, :feats, :img
                        )
                    ");
                    $ins->execute([
                        'cat_id'   => $categoryId,
                        'loc_id'   => $baseLocationId,
                        'reg'      => $regNumber,
                        'brand'    => $brand,
                        'model'    => $model,
                        'yr'       => $year,
                        'trans'    => $transmission,
                        'fuel'     => $fuelType,
                        'seats'    => $seats,
                        'lug'      => $luggage,
                        'daily'    => $dailyRate,
                        'weekly'   => $weeklyRate,
                        'mileage'  => $mileageKm,
                        'maint'    => $maintenanceStatus,
                        'active'   => $isActive,
                        'featured' => $isFeatured,
                        'descr'    => $description,
                        'feats'    => $featuresList,
                        'img'      => $finalImage,
                    ]);

                    $newVid = (int)$pdo->lastInsertId();
                    $imgIns = $pdo->prepare("INSERT INTO vehicle_images (vehicle_id, image_path, caption, is_primary) VALUES (:vid, :path, :cap, 1)");
                    $imgIns->execute(['vid' => $newVid, 'path' => $finalImage, 'cap' => $brand . ' ' . $model]);

                    set_flash('success', 'New vehicle ' . $brand . ' ' . $model . ' (' . $regNumber . ') added to the fleet!');
                }
                redirect('admin/vehicles.php');
            } catch (Throwable $e) {
                Database::logError('Admin save_vehicle error: ' . $e->getMessage());
                $errors[] = 'Could not save vehicle (ensure Registration Number is unique).';
            }
        }
    } elseif ($action === 'quick_status') {
        $vid   = (int)($_POST['vehicle_id'] ?? 0);
        $maint = $_POST['maintenance_status'] ?? 'available';
        $act   = (int)($_POST['is_active'] ?? 1) === 1 ? 1 : 0;

        if (in_array($maint, ['available', 'maintenance', 'retired'], true)) {
            $upd = $pdo->prepare("UPDATE vehicles SET maintenance_status = :maint, is_active = :act WHERE id = :id");
            $upd->execute(['maint' => $maint, 'act' => $act, 'id' => $vid]);
            set_flash('success', 'Fleet status updated for vehicle #' . $vid . ' (historical bookings remain preserved).');
        }
        redirect('admin/vehicles.php');
    } elseif ($action === 'save_category') {
        $catId     = (int)($_POST['category_id'] ?? 0);
        $catName   = trim($_POST['name'] ?? '');
        $excessKm  = max(0.0, (float)($_POST['excess_km_rate_lkr'] ?? 60));
        $catDescr  = trim($_POST['description'] ?? '');

        if ($catId > 0 && $catName !== '') {
            $upd = $pdo->prepare("UPDATE vehicle_categories SET name = :n, excess_km_rate_lkr = :r, description = :d WHERE id = :id");
            $upd->execute(['n' => $catName, 'r' => $excessKm, 'd' => $catDescr, 'id' => $catId]);
            set_flash('success', 'Category "' . $catName . '" updated.');
        }
        redirect('admin/vehicles.php');
    }
}

$categories = $pdo->query("SELECT * FROM vehicle_categories ORDER BY display_order ASC")->fetchAll();
$locations  = $pdo->query("SELECT * FROM locations ORDER BY id ASC")->fetchAll();
$vehicles   = $pdo->query("
    SELECT v.*, c.name AS category_name, l.name AS location_name,
           (SELECT COUNT(*) FROM bookings b WHERE b.vehicle_id = v.id) AS total_bookings_count
    FROM vehicles v
    JOIN vehicle_categories c ON c.id = v.category_id
    JOIN locations l ON l.id = v.base_location_id
    ORDER BY v.id ASC
")->fetchAll();

$editId = (int)($_GET['edit_id'] ?? 0);
$editVehicle = null;
if ($editId > 0) {
    foreach ($vehicles as $v) {
        if ((int)$v['id'] === $editId) {
            $editVehicle = $v;
            break;
        }
    }
}

$pageTitle = 'Manage Fleet Vehicles & Categories';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="page-header-band">
    <div class="container d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <span class="text-orange fw-bold text-uppercase small">Administrator Fleet Management</span>
            <h1 class="fw-bold mb-1">Vehicle CRUD, Rates, Images &amp; Maintenance Status</h1>
            <p class="mb-0 text-light opacity-75">Add or edit fleet vehicles, adjust daily/weekly LKR tariffs, upload vehicle images, or toggle workshop maintenance.</p>
        </div>
        <a href="<?= e(url('admin/index.php')) ?>" class="btn btn-outline-light">
            <i class="bi bi-arrow-left me-1"></i>Admin Dashboard
        </a>
    </div>
</section>

<div class="container pb-5">
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <ul class="mb-0 small">
                <?php foreach ($errors as $err): ?>
                    <li><?= e($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- Add / Edit Vehicle Card -->
    <div class="card border shadow-sm mb-5">
        <div class="card-header bg-navy text-white py-3 d-flex justify-content-between align-items-center">
            <h2 class="h5 fw-bold mb-0">
                <i class="bi bi-car-front-fill text-orange me-2"></i>
                <?= $editVehicle ? 'Edit Vehicle: ' . e($editVehicle['brand'] . ' ' . $editVehicle['model'] . ' (' . $editVehicle['registration_number'] . ')') : 'Add New Vehicle to Fleet' ?>
            </h2>
            <?php if ($editVehicle): ?>
                <a href="<?= e(url('admin/vehicles.php')) ?>" class="btn btn-sm btn-light">Cancel Edit / Add New</a>
            <?php endif; ?>
        </div>
        <div class="card-body p-4">
            <form action="<?= e(url('admin/vehicles.php')) ?>" method="post" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_vehicle">
                <input type="hidden" name="vehicle_id" value="<?= (int)($editVehicle['id'] ?? 0) ?>">

                <div class="row g-3">
                    <div class="col-md-3">
                        <label for="registration_number" class="form-label small fw-semibold">Registration Number <span class="text-danger">*</span></label>
                        <input type="text" id="registration_number" name="registration_number" class="form-control form-control-sm" placeholder="WP CBK-1234" value="<?= e($editVehicle['registration_number'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label for="brand" class="form-label small fw-semibold">Brand / Make <span class="text-danger">*</span></label>
                        <input type="text" id="brand" name="brand" class="form-control form-control-sm" placeholder="Toyota" value="<?= e($editVehicle['brand'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label for="model" class="form-label small fw-semibold">Model Name <span class="text-danger">*</span></label>
                        <input type="text" id="model" name="model" class="form-control form-control-sm" placeholder="Yaris Cross Hybrid" value="<?= e($editVehicle['model'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-2">
                        <label for="year" class="form-label small fw-semibold">Year <span class="text-danger">*</span></label>
                        <input type="number" id="year" name="year" class="form-control form-control-sm" value="<?= (int)($editVehicle['year'] ?? 2022) ?>" required>
                    </div>

                    <div class="col-md-3">
                        <label for="category_id" class="form-label small fw-semibold">Category</label>
                        <select id="category_id" name="category_id" class="form-select form-select-sm" required>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= (int)$cat['id'] ?>" <?= (int)($editVehicle['category_id'] ?? 1) === (int)$cat['id'] ? 'selected' : '' ?>>
                                    <?= e($cat['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="base_location_id" class="form-label small fw-semibold">Base Hub Location</label>
                        <select id="base_location_id" name="base_location_id" class="form-select form-select-sm" required>
                            <?php foreach ($locations as $loc): ?>
                                <option value="<?= (int)$loc['id'] ?>" <?= (int)($editVehicle['base_location_id'] ?? 1) === (int)$loc['id'] ? 'selected' : '' ?>>
                                    <?= e($loc['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="transmission" class="form-label small fw-semibold">Transmission</label>
                        <select id="transmission" name="transmission" class="form-select form-select-sm">
                            <option value="Automatic" <?= ($editVehicle['transmission'] ?? '') === 'Automatic' ? 'selected' : '' ?>>Automatic</option>
                            <option value="Manual" <?= ($editVehicle['transmission'] ?? '') === 'Manual' ? 'selected' : '' ?>>Manual</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="fuel_type" class="form-label small fw-semibold">Fuel Type</label>
                        <select id="fuel_type" name="fuel_type" class="form-select form-select-sm">
                            <?php foreach (['Petrol', 'Diesel', 'Hybrid', 'Electric'] as $ft): ?>
                                <option value="<?= $ft ?>" <?= ($editVehicle['fuel_type'] ?? '') === $ft ? 'selected' : '' ?>><?= $ft ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <label for="seats" class="form-label small fw-semibold">Seats</label>
                        <input type="number" id="seats" name="seats" class="form-control form-control-sm" value="<?= (int)($editVehicle['seats'] ?? 5) ?>" min="2" max="20">
                    </div>
                    <div class="col-md-1">
                        <label for="luggage_capacity" class="form-label small fw-semibold">Bags</label>
                        <input type="number" id="luggage_capacity" name="luggage_capacity" class="form-control form-control-sm" value="<?= (int)($editVehicle['luggage_capacity'] ?? 3) ?>" min="1" max="15">
                    </div>

                    <div class="col-md-3">
                        <label for="daily_rate_lkr" class="form-label small fw-semibold">Daily Rate (LKR) <span class="text-danger">*</span></label>
                        <input type="number" id="daily_rate_lkr" name="daily_rate_lkr" class="form-control form-control-sm" step="100" min="1000" value="<?= e((string)($editVehicle['daily_rate_lkr'] ?? '12500.00')) ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label for="weekly_rate_lkr" class="form-label small fw-semibold">Weekly Rate (7 Days LKR) <span class="text-danger">*</span></label>
                        <input type="number" id="weekly_rate_lkr" name="weekly_rate_lkr" class="form-control form-control-sm" step="500" min="5000" value="<?= e((string)($editVehicle['weekly_rate_lkr'] ?? '78000.00')) ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label for="mileage_km" class="form-label small fw-semibold">Current Odometer (km)</label>
                        <input type="number" id="mileage_km" name="mileage_km" class="form-control form-control-sm" min="0" value="<?= (int)($editVehicle['mileage_km'] ?? 15000) ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="maintenance_status" class="form-label small fw-semibold">Maintenance Status</label>
                        <select id="maintenance_status" name="maintenance_status" class="form-select form-select-sm">
                            <option value="available" <?= ($editVehicle['maintenance_status'] ?? '') === 'available' ? 'selected' : '' ?>>Available for Rental</option>
                            <option value="maintenance" <?= ($editVehicle['maintenance_status'] ?? '') === 'maintenance' ? 'selected' : '' ?>>In Workshop Maintenance</option>
                            <option value="retired" <?= ($editVehicle['maintenance_status'] ?? '') === 'retired' ? 'selected' : '' ?>>Retired</option>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label for="description" class="form-label small fw-semibold">Vehicle Description <span class="text-danger">*</span></label>
                        <textarea id="description" name="description" rows="2" class="form-control form-control-sm" required><?= e($editVehicle['description'] ?? 'Well-maintained self-drive rental vehicle stationed at our Negombo hub.') ?></textarea>
                    </div>
                    <div class="col-md-6">
                        <label for="features_list" class="form-label small fw-semibold">Comma-Separated Features</label>
                        <textarea id="features_list" name="features_list" rows="2" class="form-control form-control-sm"><?= e($editVehicle['features_list'] ?? 'Air Conditioning, Reverse Camera, Bluetooth Audio, ABS, Airbags') ?></textarea>
                    </div>

                    <div class="col-md-4">
                        <label for="vehicle_image" class="form-label small fw-semibold">Upload Vehicle Image (JPG/PNG/WEBP, max 3MB)</label>
                        <input type="file" id="vehicle_image" name="vehicle_image" class="form-control form-control-sm" accept="image/jpeg,image/png,image/webp">
                    </div>
                    <div class="col-md-4">
                        <label for="preset_image" class="form-label small fw-semibold">Or Select Fleet Image</label>
                        <select id="preset_image" name="preset_image" class="form-select form-select-sm">
                            <?php
                            $presets = [
                                'assets/images/vehicles/wagon-r.jpg'       => 'Compact Hybrid (Wagon R)',
                                'assets/images/vehicles/toyota-axio.jpg'   => 'Hybrid Sedan (Toyota Axio)',
                                'assets/images/vehicles/suzuki-alto.jpg'   => 'Economy Hatchback (Alto)',
                                'assets/images/vehicles/toyota-raize.jpg'  => 'Compact SUV (Toyota Raize)',
                                'assets/images/vehicles/honda-vezel.jpg'   => 'Crossover SUV (Honda Vezel)',
                                'assets/images/vehicles/prado-txl.jpg'     => '4WD Luxury SUV (Prado)',
                                'assets/images/vehicles/toyota-kdh.jpg'    => 'Tour Van (Toyota KDH)',
                                'assets/images/vehicles/nissan-caravan.jpg'=> 'Commuter Van (Caravan)',
                                'assets/images/vehicles/mercedes-c200.jpg' => 'Luxury Saloon (Mercedes C200)',
                                'assets/images/vehicles/bmw-520i.jpg'      => 'Executive Saloon (BMW 520i)',
                            ];
                            foreach ($presets as $path => $label):
                            ?>
                                <option value="<?= e($path) ?>" <?= ($editVehicle['primary_image'] ?? '') === $path ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 d-flex align-items-end gap-4">
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" <?= !isset($editVehicle) || (int)$editVehicle['is_active'] === 1 ? 'checked' : '' ?>>
                            <label class="form-check-label small fw-semibold" for="is_active">Active in Catalogue</label>
                        </div>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="is_featured" name="is_featured" value="1" <?= isset($editVehicle) && (int)$editVehicle['is_featured'] === 1 ? 'checked' : '' ?>>
                            <label class="form-check-label small fw-semibold" for="is_featured">Featured on Home</label>
                        </div>
                    </div>

                    <div class="col-12 mt-3">
                        <button type="submit" class="btn btn-accent px-4">
                            <i class="bi bi-save me-1"></i><?= $editVehicle ? 'Update Vehicle Record' : 'Create Vehicle Record' ?>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Fleet Inventory Table -->
    <div class="card border shadow-sm mb-5">
        <div class="card-header bg-white py-3">
            <h2 class="h5 fw-bold mb-0"><i class="bi bi-list-ul text-orange me-2"></i>All Fleet Vehicles (<?= count($vehicles) ?>)</h2>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle small mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">Vehicle</th>
                            <th>Category &amp; Hub</th>
                            <th>Daily / Weekly (LKR)</th>
                            <th>Odometer</th>
                            <th>Bookings</th>
                            <th>Quick Maintenance &amp; Active Toggle</th>
                            <th class="pe-3 text-end">Edit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($vehicles as $v): ?>
                            <tr>
                                <td class="ps-3">
                                    <div class="d-flex align-items-center gap-2">
                                        <img src="<?= e(url($v['primary_image'])) ?>" alt="" class="rounded border" style="width: 56px; height: 36px; object-fit: cover;">
                                        <div>
                                            <strong><?= e($v['brand'] . ' ' . $v['model']) ?></strong> (<?= (int)$v['year'] ?>)<br>
                                            <code><?= e($v['registration_number']) ?></code> &bull; <?= e($v['transmission']) ?>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <strong><?= e($v['category_name']) ?></strong><br>
                                    <span class="text-muted"><?= e($v['location_name']) ?></span>
                                </td>
                                <td>
                                    <strong><?= e(format_lkr($v['daily_rate_lkr'])) ?></strong> /d<br>
                                    <span class="text-success"><?= e(format_lkr($v['weekly_rate_lkr'])) ?> /wk</span>
                                </td>
                                <td><?= number_format((int)$v['mileage_km']) ?> km</td>
                                <td><span class="badge bg-secondary"><?= (int)$v['total_bookings_count'] ?> records</span></td>
                                <td>
                                    <form action="<?= e(url('admin/vehicles.php')) ?>" method="post" class="d-flex gap-1 align-items-center">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="quick_status">
                                        <input type="hidden" name="vehicle_id" value="<?= (int)$v['id'] ?>">
                                        <select name="maintenance_status" class="form-select form-select-sm" style="width: 130px;">
                                            <option value="available" <?= $v['maintenance_status'] === 'available' ? 'selected' : '' ?>>Available</option>
                                            <option value="maintenance" <?= $v['maintenance_status'] === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
                                            <option value="retired" <?= $v['maintenance_status'] === 'retired' ? 'selected' : '' ?>>Retired</option>
                                        </select>
                                        <select name="is_active" class="form-select form-select-sm" style="width: 105px;">
                                            <option value="1" <?= (int)$v['is_active'] === 1 ? 'selected' : '' ?>>Active</option>
                                            <option value="0" <?= (int)$v['is_active'] === 0 ? 'selected' : '' ?>>Hidden</option>
                                        </select>
                                        <button type="submit" class="btn btn-sm btn-outline-primary">Apply</button>
                                    </form>
                                </td>
                                <td class="pe-3 text-end">
                                    <a href="<?= e(url('admin/vehicles.php?edit_id=' . (int)$v['id'])) ?>" class="btn btn-sm btn-navy">
                                        <i class="bi bi-pencil-square"></i> Edit
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Category Tariffs Management -->
    <div class="card border shadow-sm">
        <div class="card-header bg-white py-3">
            <h2 class="h5 fw-bold mb-0"><i class="bi bi-tags text-orange me-2"></i>Vehicle Categories &amp; Excess Mileage Rates</h2>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <?php foreach ($categories as $cat): ?>
                    <div class="col-md-6">
                        <form action="<?= e(url('admin/vehicles.php')) ?>" method="post" class="p-3 border rounded bg-light">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="save_category">
                            <input type="hidden" name="category_id" value="<?= (int)$cat['id'] ?>">
                            <div class="row g-2">
                                <div class="col-7">
                                    <label class="form-label small fw-semibold">Category Name</label>
                                    <input type="text" name="name" class="form-control form-control-sm" value="<?= e($cat['name']) ?>" required>
                                </div>
                                <div class="col-5">
                                    <label class="form-label small fw-semibold">Excess Rate (LKR/km)</label>
                                    <input type="number" name="excess_km_rate_lkr" class="form-control form-control-sm" step="5" value="<?= e((string)$cat['excess_km_rate_lkr']) ?>" required>
                                </div>
                                <div class="col-9">
                                    <input type="text" name="description" class="form-control form-control-sm" value="<?= e($cat['description']) ?>">
                                </div>
                                <div class="col-3">
                                    <button type="submit" class="btn btn-sm btn-navy w-100">Save</button>
                                </div>
                            </div>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>


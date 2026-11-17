<?php
/**
 * Contact & Customer Inquiry Page
 * Validates and stores inquiries in the MySQL `inquiries` table.
 */
require_once __DIR__ . '/includes/auth.php';

$pdo = Database::getConnection();
$user = current_user();

$errors = [];
$formData = [
    'full_name' => $user['full_name'] ?? '',
    'email'     => $user['email'] ?? '',
    'phone'     => $user['phone'] ?? '',
    'subject'   => '',
    'message'   => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $formData['full_name'] = trim($_POST['full_name'] ?? '');
    $formData['email']     = trim($_POST['email'] ?? '');
    $formData['phone']     = trim($_POST['phone'] ?? '');
    $formData['subject']   = trim($_POST['subject'] ?? '');
    $formData['message']   = trim($_POST['message'] ?? '');

    if ($formData['full_name'] === '' || mb_strlen($formData['full_name']) < 2) {
        $errors[] = 'Please enter your full name (at least 2 characters).';
    }
    if (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please provide a valid email address.';
    }
    if ($formData['phone'] === '' || !preg_match('/^[0-9+\-\s()]{7,25}$/', $formData['phone'])) {
        $errors[] = 'Please enter a valid contact phone number.';
    }
    if ($formData['subject'] === '' || mb_strlen($formData['subject']) < 4) {
        $errors[] = 'Please enter an inquiry subject (at least 4 characters).';
    }
    if ($formData['message'] === '' || mb_strlen($formData['message']) < 10) {
        $errors[] = 'Please enter your inquiry message (at least 10 characters).';
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO inquiries (user_id, full_name, email, phone, subject, message, status)
                VALUES (:user_id, :full_name, :email, :phone, :subject, :message, 'new')
            ");
            $stmt->execute([
                'user_id'   => $user ? (int)$user['id'] : null,
                'full_name' => $formData['full_name'],
                'email'     => $formData['email'],
                'phone'     => $formData['phone'],
                'subject'   => $formData['subject'],
                'message'   => $formData['message'],
            ]);

            set_flash('success', 'Thank you! Your inquiry has been logged with our Negombo reservations desk. Our staff will review and respond promptly.');
            redirect('contact.php');
        } catch (Throwable $e) {
            Database::logError('Inquiry insert error: ' . $e->getMessage());
            $errors[] = 'Could not save your inquiry due to a server error. Please try again.';
        }
    }
}

// If logged in customer, fetch their past submitted inquiries so they can see staff replies
$myInquiries = [];
if ($user) {
    $qStmt = $pdo->prepare("SELECT * FROM inquiries WHERE user_id = :uid OR email = :email ORDER BY created_at DESC LIMIT 10");
    $qStmt->execute(['uid' => (int)$user['id'], 'email' => $user['email']]);
    $myInquiries = $qStmt->fetchAll();
}

$pageTitle = 'Contact & Rental Inquiries';
require_once __DIR__ . '/includes/header.php';
?>

<section class="page-header-band">
    <div class="container">
        <span class="text-orange fw-bold text-uppercase small">24/7 Negombo &amp; BIA Support</span>
        <h1 class="fw-bold mb-2">Contact &amp; Rental Inquiry</h1>
        <p class="mb-0 text-light opacity-75">Have a question about airport collection, long-term corporate rates, or driving permits? Send us a message below.</p>
    </div>
</section>

<div class="container pb-5">
    <div class="row g-4 g-lg-5">
        <div class="col-lg-7">
            <div class="card border shadow-sm">
                <div class="card-body p-4">
                    <h2 class="h4 fw-bold mb-3">Send an Inquiry to Our Team</h2>

                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger" role="alert">
                            <strong class="d-block mb-1"><i class="bi bi-exclamation-octagon me-1"></i>Please fix the following errors:</strong>
                            <ul class="mb-0 small">
                                <?php foreach ($errors as $err): ?>
                                    <li><?= e($err) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <form action="<?= e(url('contact.php')) ?>" method="post" novalidate>
                        <?= csrf_field() ?>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="full_name" class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                                <input type="text" id="full_name" name="full_name" class="form-control" value="<?= e($formData['full_name']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label for="email" class="form-label fw-semibold">Email Address <span class="text-danger">*</span></label>
                                <input type="email" id="email" name="email" class="form-control" value="<?= e($formData['email']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label for="phone" class="form-label fw-semibold">Phone / WhatsApp Number <span class="text-danger">*</span></label>
                                <input type="tel" id="phone" name="phone" class="form-control" placeholder="+94 77 123 4567" value="<?= e($formData['phone']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label for="subject" class="form-label fw-semibold">Subject <span class="text-danger">*</span></label>
                                <input type="text" id="subject" name="subject" class="form-control" placeholder="e.g., BIA Airport Pickup / Monthly Lease" value="<?= e($formData['subject']) ?>" required>
                            </div>
                            <div class="col-12">
                                <label for="message" class="form-label fw-semibold">Your Message <span class="text-danger">*</span></label>
                                <textarea id="message" name="message" rows="5" class="form-control" placeholder="Tell us your travel dates, preferred vehicle category, or special requirements..." required><?= e($formData['message']) ?></textarea>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-accent px-4 py-2">
                                    <i class="bi bi-send-fill me-2"></i>Submit Inquiry
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <?php if (!empty($myInquiries)): ?>
                <div class="card border shadow-sm mt-4">
                    <div class="card-header bg-white py-3">
                        <h3 class="h6 fw-bold mb-0"><i class="bi bi-chat-left-text text-orange me-2"></i>Your Previous Inquiries &amp; Staff Responses</h3>
                    </div>
                    <div class="list-group list-group-flush">
                        <?php foreach ($myInquiries as $inq): ?>
                            <div class="list-group-item p-3">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <strong><?= e($inq['subject']) ?></strong>
                                    <span class="badge <?= $inq['status'] === 'resolved' ? 'bg-success' : ($inq['status'] === 'in_progress' ? 'bg-primary' : 'bg-warning text-dark') ?>">
                                        <?= e(strtoupper(str_replace('_', ' ', $inq['status']))) ?>
                                    </span>
                                </div>
                                <p class="small text-muted mb-2"><?= e($inq['message']) ?></p>
                                <?php if (!empty($inq['staff_reply'])): ?>
                                    <div class="bg-light border-start border-3 border-primary p-2 rounded small">
                                        <strong class="d-block text-dark">SwiftDrive Staff Reply:</strong>
                                        <?= e($inq['staff_reply']) ?>
                                    </div>
                                <?php endif; ?>
                                <div class="text-muted" style="font-size: 0.75rem;">Submitted <?= e(date('d M Y H:i', strtotime($inq['created_at']))) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="col-lg-5">
            <div class="card border-0 shadow-sm bg-navy text-white p-4 mb-4">
                <h3 class="h5 fw-bold mb-3"><i class="bi bi-headset text-orange me-2"></i>Direct Contact Channels</h3>
                <div class="d-flex flex-column gap-3 small">
                    <div>
                        <strong class="d-block text-orange">Negombo Beach Road Headquarters</strong>
                        No. 148, Poruthota Road, Ettukala, Negombo<br>
                        Tel: +94 31 222 8890 | Open Daily 06:00 – 22:30
                    </div>
                    <div>
                        <strong class="d-block text-orange">BIA Katunayake Airport Counter</strong>
                        Arrivals Concourse Counter R-04, Katunayake<br>
                        Tel: +94 11 225 4490 | Open 24 Hours Daily
                    </div>
                    <div>
                        <strong class="d-block text-orange">24/7 Emergency Roadside Assistance</strong>
                        Hotline: +94 77 112 2334
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>


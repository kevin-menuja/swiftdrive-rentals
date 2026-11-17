<?php
/**
 * Staff & Admin Inquiry Management Page
 * Allows staff/admin to review customer inquiries, record responses, and update status ('new', 'in_progress', 'resolved').
 */
require_once __DIR__ . '/../includes/auth.php';

$user = require_role(['staff', 'admin']);
$pdo  = Database::getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $inquiryId  = (int)($_POST['inquiry_id'] ?? 0);
    $status     = $_POST['status'] ?? 'in_progress';
    $staffReply = trim($_POST['staff_reply'] ?? '');

    if (!in_array($status, ['new', 'in_progress', 'resolved'], true)) {
        $status = 'in_progress';
    }

    $stmt = $pdo->prepare("
        UPDATE inquiries
        SET status = :status,
            staff_reply = :reply,
            handled_by = :staff_id
        WHERE id = :id
    ");
    $stmt->execute([
        'status'   => $status,
        'reply'    => $staffReply !== '' ? $staffReply : null,
        'staff_id' => (int)$user['id'],
        'id'       => $inquiryId,
    ]);

    set_flash('success', 'Inquiry #' . $inquiryId . ' updated to ' . strtoupper(str_replace('_', ' ', $status)) . '.');
    redirect('staff/inquiries.php');
}

$statusFilter = trim($_GET['status'] ?? '');
$where = '';
$params = [];
if (in_array($statusFilter, ['new', 'in_progress', 'resolved'], true)) {
    $where = 'WHERE i.status = :status';
    $params['status'] = $statusFilter;
}

$stmt = $pdo->prepare("
    SELECT i.*, u.full_name AS handler_name
    FROM inquiries i
    LEFT JOIN users u ON u.id = i.handled_by
    $where
    ORDER BY FIELD(i.status, 'new', 'in_progress', 'resolved'), i.created_at DESC
");
$stmt->execute($params);
$inquiries = $stmt->fetchAll();

$pageTitle = 'Customer Inquiries';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="page-header-band">
    <div class="container d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <span class="text-orange fw-bold text-uppercase small">Customer Support Desk</span>
            <h1 class="fw-bold mb-1">Manage Customer Inquiries</h1>
            <p class="mb-0 text-light opacity-75">Review questions from prospective hirers and record staff responses and resolution statuses.</p>
        </div>
        <a href="<?= e(url('staff/index.php')) ?>" class="btn btn-outline-light">
            <i class="bi bi-arrow-left me-1"></i>Back to Staff Portal
        </a>
    </div>
</section>

<div class="container pb-5">
    <div class="d-flex flex-wrap gap-2 mb-4">
        <a href="<?= e(url('staff/inquiries.php')) ?>" class="btn btn-sm <?= $statusFilter === '' ? 'btn-navy' : 'btn-outline-secondary' ?>">All Inquiries</a>
        <a href="<?= e(url('staff/inquiries.php?status=new')) ?>" class="btn btn-sm <?= $statusFilter === 'new' ? 'btn-navy' : 'btn-outline-secondary' ?>">New</a>
        <a href="<?= e(url('staff/inquiries.php?status=in_progress')) ?>" class="btn btn-sm <?= $statusFilter === 'in_progress' ? 'btn-navy' : 'btn-outline-secondary' ?>">In Progress</a>
        <a href="<?= e(url('staff/inquiries.php?status=resolved')) ?>" class="btn btn-sm <?= $statusFilter === 'resolved' ? 'btn-navy' : 'btn-outline-secondary' ?>">Resolved</a>
    </div>

    <div class="row g-4">
        <?php foreach ($inquiries as $inq): ?>
            <div class="col-12">
                <div class="card border shadow-sm">
                    <div class="card-body p-4">
                        <div class="row g-4">
                            <div class="col-lg-7">
                                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                    <span class="badge <?= $inq['status'] === 'resolved' ? 'bg-success' : ($inq['status'] === 'in_progress' ? 'bg-primary' : 'bg-warning text-dark') ?>">
                                        <?= e(strtoupper(str_replace('_', ' ', $inq['status']))) ?>
                                    </span>
                                    <strong class="fs-5"><?= e($inq['subject']) ?></strong>
                                </div>
                                <div class="small text-muted mb-3">
                                    <i class="bi bi-person me-1"></i><strong><?= e($inq['full_name']) ?></strong> &bull;
                                    <i class="bi bi-envelope me-1"></i><?= e($inq['email']) ?> &bull;
                                    <i class="bi bi-telephone me-1"></i><?= e($inq['phone']) ?> &bull;
                                    Received <?= e(date('d M Y, H:i', strtotime($inq['created_at']))) ?>
                                </div>
                                <div class="p-3 bg-light rounded border small mb-2">
                                    <?= nl2br(e($inq['message'])) ?>
                                </div>
                                <?php if ($inq['handler_name']): ?>
                                    <div class="small text-muted">Last updated by: <strong><?= e($inq['handler_name']) ?></strong></div>
                                <?php endif; ?>
                            </div>

                            <div class="col-lg-5 border-start">
                                <form action="<?= e(url('staff/inquiries.php')) ?>" method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="inquiry_id" value="<?= (int)$inq['id'] ?>">

                                    <div class="mb-2">
                                        <label for="reply_<?= (int)$inq['id'] ?>" class="form-label small fw-semibold">Staff Response / Notes</label>
                                        <textarea id="reply_<?= (int)$inq['id'] ?>" name="staff_reply" rows="3" class="form-control form-control-sm" placeholder="Write reply or resolution notes..."><?= e($inq['staff_reply']) ?></textarea>
                                    </div>
                                    <div class="row g-2 align-items-center">
                                        <div class="col-7">
                                            <select name="status" class="form-select form-select-sm" aria-label="Inquiry status">
                                                <option value="new" <?= $inq['status'] === 'new' ? 'selected' : '' ?>>Mark as New</option>
                                                <option value="in_progress" <?= $inq['status'] === 'in_progress' ? 'selected' : '' ?>>Mark as In Progress</option>
                                                <option value="resolved" <?= $inq['status'] === 'resolved' ? 'selected' : '' ?>>Mark as Resolved</option>
                                            </select>
                                        </div>
                                        <div class="col-5">
                                            <button type="submit" class="btn btn-sm btn-accent w-100">Save Update</button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>


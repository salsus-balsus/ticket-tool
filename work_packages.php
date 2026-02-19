<?php
require 'includes/config.php';
require 'includes/functions.php';

// Handle Create New Package
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_package'])) {
    $title = trim($_POST['title']);
    $assigned_to = trim($_POST['assigned_to']);
    
    if (!empty($title)) {
        $stmt = $pdo->prepare("INSERT INTO work_packages (title, assigned_to, status) VALUES (?, ?, 'Open')");
        $stmt->execute([$title, $assigned_to]);
        header("Location: work_packages.php");
        exit;
    }
}

// Fetch Packages with Progress Stats
// Progress: tickets completed = is_terminal=1; tests completed = overall_status='Pass'
$sql = "
    SELECT 
        wp.*,
        (SELECT COUNT(*) FROM work_package_items_tickets wit WHERE wit.package_id = wp.id) +
        (SELECT COUNT(*) FROM work_package_items_tests wie WHERE wie.package_id = wp.id) as total_items,
        
        (SELECT COUNT(*) FROM work_package_items_tickets wit 
         JOIN tickets t ON wit.ticket_id = t.id 
         JOIN ticket_statuses ts ON t.status_id = ts.id
         WHERE wit.package_id = wp.id AND ts.is_terminal = 1) +
        (SELECT COUNT(*) FROM work_package_items_tests wie 
         JOIN test_executions te ON wie.execution_id = te.id 
         WHERE wie.package_id = wp.id AND te.overall_status = 'Pass') as completed_items
    FROM work_packages wp
    ORDER BY wp.created_at DESC
";
try {
    $packages = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Fallback: use status_id=100 if is_terminal column missing
    $sql = "
        SELECT wp.*,
            (SELECT COUNT(*) FROM work_package_items_tickets wit WHERE wit.package_id = wp.id) +
            (SELECT COUNT(*) FROM work_package_items_tests wie WHERE wie.package_id = wp.id) as total_items,
            (SELECT COUNT(*) FROM work_package_items_tickets wit JOIN tickets t ON wit.ticket_id = t.id WHERE wit.package_id = wp.id AND t.status_id = 100) +
            (SELECT COUNT(*) FROM work_package_items_tests wie JOIN test_executions te ON wie.execution_id = te.id WHERE wie.package_id = wp.id AND te.overall_status = 'Pass') as completed_items
        FROM work_packages wp ORDER BY wp.created_at DESC
    ";
    $packages = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}
?>

<?php $pageTitle = 'Work Packages'; require 'includes/header.php'; ?>

<div class="page-wrapper">
    <div class="container-xl">
        <div class="page-header d-print-none">
            <div class="row g-2 align-items-center">
                <div class="col">
                    <h2 class="page-title">Work Packages</h2>
                </div>
                <div class="col-auto ms-auto">
                    <a href="#" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal-new-package">
                        <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><line x1="12" y1="5" x2="12" y2="19" /><line x1="5" y1="12" x2="19" y2="12" /></svg>
                        New Package
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="page-body">
        <div class="container-xl">
            <div class="row row-cards">
                <?php if (empty($packages)): ?>
                <div class="col-12">
                    <div class="empty">
                        <p class="empty-title">No work packages yet</p>
                        <p class="empty-subtitle">Create one to bundle tickets and tests.</p>
                        <div class="empty-action">
                            <a href="#" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal-new-package">New Work Package</a>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <?php foreach ($packages as $pkg): ?>
                    <?php 
                        $percent = 0;
                        if ($pkg['total_items'] > 0) {
                            $percent = round(($pkg['completed_items'] / $pkg['total_items']) * 100);
                        }
                        $statusColor = $pkg['status'] === 'Open' ? 'green' : 'secondary';
                    ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <a href="package_detail.php?id=<?= $pkg['id'] ?>"><?= htmlspecialchars($pkg['title']) ?></a>
                                </h3>
                                <div class="card-actions">
                                    <span class="badge bg-<?= $statusColor ?>"><?= $pkg['status'] ?></span>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="d-flex align-items-center mb-2">
                                    <span class="avatar me-2 rounded-circle"><?= strtoupper(substr($pkg['assigned_to'] ?? 'U', 0, 2)) ?></span>
                                    <div class="text-muted"><?= htmlspecialchars($pkg['assigned_to'] ?? 'Unassigned') ?></div>
                                    <div class="ms-auto text-muted">Created: <?= date('d.m.Y', strtotime($pkg['created_at'])) ?></div>
                                </div>
                                
                                <div class="mt-3">
                                    <div class="d-flex mb-1">
                                        <div>Progress</div>
                                        <div class="ms-auto"><?= $percent ?>%</div>
                                    </div>
                                    <div class="progress progress-sm">
                                        <div class="progress-bar bg-primary" style="width: <?= $percent ?>%" role="progressbar"></div>
                                    </div>
                                    <div class="text-muted small mt-1">
                                        <?= $pkg['completed_items'] ?> / <?= $pkg['total_items'] ?> items completed
                                    </div>
                                </div>
                            </div>
                            <div class="card-footer">
                                <a href="package_detail.php?id=<?= $pkg['id'] ?>" class="btn btn-outline-primary w-100">Manage Content</a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="modal modal-blur fade" id="modal-new-package" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <form method="POST">
          <div class="modal-header">
            <h5 class="modal-title">New Work Package</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div class="mb-3">
              <label class="form-label">Package Title</label>
              <input type="text" class="form-control" name="title" required placeholder="e.g. Q1 Release Logistics">
            </div>
            <div class="mb-3">
              <label class="form-label">Assigned To (Name)</label>
              <input type="text" class="form-control" name="assigned_to" placeholder="e.g. Thomas">
            </div>
          </div>
          <div class="modal-footer">
            <a href="#" class="btn btn-link link-secondary" data-bs-dismiss="modal">Cancel</a>
            <button type="submit" name="create_package" class="btn btn-primary ms-auto">Create Work Package</button>
          </div>
      </form>
    </div>
  </div>
</div>

<?php require 'includes/footer.php'; ?>
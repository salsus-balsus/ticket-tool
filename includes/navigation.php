<?php
if (!isset($pdo)) {
    require_once __DIR__ . '/config.php';
}
if (!function_exists('get_user_role')) {
    require_once __DIR__ . '/functions.php';
}
$nav_users = [];
$nav_effective_role = null;
try {
    $nav_users = $pdo->query("
        SELECT u.id, u.username, u.first_name, u.last_name, u.role_id, r.name AS role_name
        FROM app_users u
        LEFT JOIN roles r ON r.id = u.role_id
        ORDER BY COALESCE(u.first_name, u.username), u.last_name
    ")->fetchAll(PDO::FETCH_ASSOC);
    $dev_user_id = isset($_COOKIE['dev_user_id']) ? (int) $_COOKIE['dev_user_id'] : 0;
    if ($dev_user_id > 0) {
        foreach ($nav_users as $u) {
            if ((int) $u['id'] === $dev_user_id) {
                $nav_effective_role = $u['role_name'] ?? '-';
                break;
            }
        }
    } else {
        $role_id = get_user_role(0);
        $stmt = $pdo->prepare("SELECT name FROM roles WHERE id = ?");
        $stmt->execute([$role_id]);
        $nav_effective_role = $stmt->fetchColumn() ?: '-';
    }
} catch (PDOException $e) {}
$nav_current = basename($_SERVER['PHP_SELF'] ?? '');
$nav_active_dashboard = ($nav_current === 'index.php');
$nav_active_tickets = in_array($nav_current, ['tickets.php', 'ticket_detail.php', 'ticket_create.php', 'tickets_search.php']);
$nav_active_test = in_array($nav_current, ['testplan.php', 'test_case_detail.php']);
$nav_active_customer = ($nav_current === 'customer_scope.php');
$nav_active_work = ($nav_current === 'work_packages.php');
$nav_active_leaves = ($nav_current === 'leaves.php');
$nav_active_timesheet = ($nav_current === 'timesheet.php');
$nav_active_admin_leaves = ($nav_current === 'admin_leaves.php');
$nav_active_admin = (strpos($nav_current, 'admin_') === 0);
?>
<style>
    .navbar-brand-custom { display: flex; flex-direction: column; align-items: flex-start; padding: 0.25rem 0; }
    .navbar-brand-custom img { height: 2rem; width: auto; }
    .navbar-brand-custom .navbar-brand-sub { font-size: 0.65rem; color: var(--tblr-secondary); margin-top: 0.15rem; }
    
    /* Fix: Transparenter Border für alle Links verhindert das "Springen" der Höhe */
    .navbar .nav-link { border: 1px solid transparent; border-radius: var(--tblr-border-radius); }
    .navbar .nav-link.nav-link-active { border-color: var(--tblr-border-color); background: rgba(0,0,0,.03); }
    
    .navbar-nav-tabs { justify-content: center; }
    
    /* Consistent navbar size across all pages */
    header.navbar { 
        height: 4.5rem !important; 
        min-height: 4.5rem !important; 
        max-height: 4.5rem !important; 
        width: 100%; 
        box-sizing: border-box; 
    }
    
    /* Fix: Etwas breiter (1.25rem statt 2rem Padding) */
    .navbar-header-inner { 
        width: 100%; 
        max-width: 100%; 
        padding-left: 1.25rem !important; 
        padding-right: 1.25rem !important; 
    }

    /* Fix: Perfekte Zentrierung (Logo und Rechte Seite bekommen exakt gleich viel Platz) */
    @media (min-width: 768px) {
        .navbar-brand-custom { flex: 1 1 0%; min-width: 0; }
        #navbar-menu { flex: 0 0 auto !important; justify-content: center; }
        .navbar-right-section { flex: 1 1 0%; justify-content: flex-end; min-width: 0; }
    }
</style>

<header class="navbar navbar-expand-md navbar-light d-print-none mb-3 py-3">
    <div class="container-fluid navbar-header-inner">
        <a class="navbar-brand navbar-brand-custom text-decoration-none flex-shrink-0" href="index.php">
            <img src="extra/logo.png" alt="Logo"/>
            <span class="navbar-brand-sub">Organization Tool</span>
        </a>
        
        <div class="collapse navbar-collapse" id="navbar-menu">
            <ul class="navbar-nav navbar-nav-tabs">
                <li class="nav-item">
                    <a class="nav-link <?= $nav_active_dashboard ? 'nav-link-active' : '' ?>" href="index.php">
                        <span class="nav-link-title">Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $nav_active_tickets ? 'nav-link-active' : '' ?>" href="tickets.php">
                        <span class="nav-link-title">Tickets</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $nav_active_test ? 'nav-link-active' : '' ?>" href="testplan.php">
                        <span class="nav-link-title">Test Management</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $nav_active_customer ? 'nav-link-active' : '' ?>" href="customer_scope.php">
                        <span class="nav-link-title">Customer Scope</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $nav_active_work ? 'nav-link-active' : '' ?>" href="work_packages.php">
                        <span class="nav-link-title">Work Packages</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $nav_active_leaves ? 'nav-link-active' : '' ?>" href="leaves.php">
                        <span class="nav-link-title">Leaves</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $nav_active_timesheet ? 'nav-link-active' : '' ?>" href="timesheet.php">
                        <span class="nav-link-title">Timesheet</span>
                    </a>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?= $nav_active_admin ? 'nav-link-active' : '' ?>" href="#" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="nav-link-title">Admin</span>
                    </a>
                    <div class="dropdown-menu">
                        <a class="dropdown-item" href="admin_workflow.php">Workflow Settings</a>
                        <a class="dropdown-item" href="admin_inconsistent_status.php">Tickets with inconsistent status</a>
                        <a class="dropdown-item" href="admin_comment_authors.php">Comment authors (unmapped)</a>
                        <a class="dropdown-item" href="admin_users.php">Users &amp; roles</a>
                        <a class="dropdown-item" href="admin_employment.php">Employment (timesheet target hours)</a>
                        <a class="dropdown-item <?= $nav_active_admin_leaves ? 'active' : '' ?>" href="admin_leaves.php">Leaves (all)</a>
                    </div>
                </li>
            </ul>
        </div>
        
        <div class="navbar-nav flex-row order-md-last align-items-center flex-shrink-0 navbar-right-section">
            <?php if (!empty($nav_users)): ?>
            <div class="nav-item d-flex align-items-center gap-2">
                <form method="post" action="set_dev_user.php" class="d-flex align-items-center" id="nav-user-form">
                    <select name="user_id" class="form-select form-select-sm" style="width:auto; min-width:140px;" onchange="this.form.submit()" aria-label="User">
                        <option value="0" <?= empty($_COOKIE['dev_user_id']) || (int)$_COOKIE['dev_user_id'] === 0 ? 'selected' : '' ?>>— Angemeldet —</option>
                        <?php foreach ($nav_users as $u): ?>
                        <?php
                        $label = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: ($u['username'] ?? 'User');
                        ?>
                        <option value="<?= (int) $u['id'] ?>" <?= (isset($_COOKIE['dev_user_id']) && (int)$_COOKIE['dev_user_id'] === (int)$u['id']) ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <?php if ($nav_effective_role !== null): ?>
                <span class="badge bg-blue-lt"><?= e($nav_effective_role) ?></span>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="nav-item">
                <span class="badge bg-blue-lt"><?= e(get_effective_user() ?: 'Guest') ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>
</header>
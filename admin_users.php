<?php
/**
 * Admin: Users & roles. app_users: username → role_id.
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';

$msg = '';
$users = [];
$roles = [];
$roleColorsMap = [];
$dbError = null;

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS app_users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(255) NOT NULL,
            first_name VARCHAR(255) DEFAULT NULL,
            last_name VARCHAR(255) DEFAULT NULL,
            initials VARCHAR(31) DEFAULT NULL,
            role_id INT UNSIGNED NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_username (username),
            KEY idx_role (role_id)
        )
    ");
} catch (PDOException $e) {
    // Tabelle existiert evtl. schon
}
try {
    $hasDesc = $pdo->query("SHOW COLUMNS FROM roles LIKE 'description'")->fetch();
    if (!$hasDesc) {
        $pdo->exec("ALTER TABLE roles ADD COLUMN description VARCHAR(512) NULL DEFAULT NULL AFTER name");
    }
} catch (PDOException $e) {
    // roles table or column
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_user'])) {
        $username = trim($_POST['username'] ?? '');
        $first_name = trim($_POST['first_name'] ?? '') ?: null;
        $last_name = trim($_POST['last_name'] ?? '') ?: null;
        $initials = trim($_POST['initials'] ?? '') ?: null;
        $role_id = (int) ($_POST['role_id'] ?? 0);
        if ($username !== '' && $role_id > 0) {
            try {
                $stmt = $pdo->prepare("INSERT INTO app_users (username, first_name, last_name, initials, role_id) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$username, $first_name, $last_name, $initials, $role_id]);
                $msg = 'User created.';
            } catch (PDOException $e) {
                $msg = $e->getCode() == 23000 ? 'Username already exists.' : $e->getMessage();
            }
        } else {
            $msg = 'Username and role are required.';
        }
    }
    if (isset($_POST['update_role'])) {
        $id = (int) ($_POST['user_id'] ?? 0);
        $role_id = (int) ($_POST['role_id'] ?? 0);
        if ($id > 0 && $role_id > 0) {
            try {
                $stmt = $pdo->prepare("UPDATE app_users SET role_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$role_id, $id]);
                $msg = 'Role updated.';
            } catch (PDOException $e) {
                $msg = $e->getMessage();
            }
        }
    }
    if (isset($_POST['update_user'])) {
        $id = (int) ($_POST['user_id'] ?? 0);
        $username = trim($_POST['username'] ?? '');
        $first_name = trim($_POST['first_name'] ?? '') ?: null;
        $last_name = trim($_POST['last_name'] ?? '') ?: null;
        $initials = trim($_POST['initials'] ?? '') ?: null;
        $role_id = (int) ($_POST['role_id'] ?? 0);
        if ($id > 0 && $username !== '' && $role_id > 0) {
            try {
                $stmt = $pdo->prepare("UPDATE app_users SET username = ?, first_name = ?, last_name = ?, initials = ?, role_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$username, $first_name, $last_name, $initials, $role_id, $id]);
                $msg = 'User updated.';
            } catch (PDOException $e) {
                $msg = $e->getCode() == 23000 ? 'Username already exists.' : $e->getMessage();
            }
        } else {
            $msg = 'Username and role are required.';
        }
    }
    if (isset($_POST['delete_user'])) {
        $id = (int) ($_POST['user_id'] ?? 0);
        if ($id > 0) {
            try {
                $stmt = $pdo->prepare("DELETE FROM app_users WHERE id = ?");
                $stmt->execute([$id]);
                $msg = 'User removed.';
            } catch (PDOException $e) {
                $msg = $e->getMessage();
            }
        }
    }
    if (isset($_POST['save_all_role_colors'])) {
        $colors = $_POST['role_color'] ?? [];
        $descriptions = $_POST['role_description'] ?? [];
        $stmt = $pdo->prepare("UPDATE roles SET description = ?, color_code = ? WHERE id = ?");
        foreach ($colors as $rid => $color) {
            $rid = (int) $rid;
            if ($rid <= 0) continue;
            $desc = isset($descriptions[$rid]) ? trim((string) $descriptions[$rid]) : '';
            $stmt->execute([$desc !== '' ? $desc : null, trim($color ?? '') ?: null, $rid]);
        }
        $msg = 'Role colors saved.';
    }
    if (isset($_POST['update_users_bulk'])) {
        $ids = array_map('intval', $_POST['user_id'] ?? []);
        $usernames = $_POST['username_bulk'] ?? [];
        $first_names = $_POST['first_name_bulk'] ?? [];
        $last_names = $_POST['last_name_bulk'] ?? [];
        $initials_bulk = $_POST['initials_bulk'] ?? [];
        $role_ids = $_POST['role_id_bulk'] ?? [];
        $updated = 0;
        $stmt = $pdo->prepare("UPDATE app_users SET username = ?, first_name = ?, last_name = ?, initials = ?, role_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        foreach ($ids as $i => $id) {
            if ($id <= 0) continue;
            $username = trim($usernames[$i] ?? '');
            $role_id = (int) ($role_ids[$i] ?? 0);
            if ($username === '' || $role_id <= 0) continue;
            $first_name = trim($first_names[$i] ?? '') ?: null;
            $last_name = trim($last_names[$i] ?? '') ?: null;
            $initials = trim($initials_bulk[$i] ?? '') ?: null;
            try {
                $stmt->execute([$username, $first_name, $last_name, $initials, $role_id, $id]);
                $updated++;
            } catch (PDOException $e) {
                if ($e->getCode() != 23000) $msg = $e->getMessage();
            }
        }
        if ($updated > 0) $msg = $updated . ' user(s) updated.';
        if ($msg === '' && !empty($ids)) $msg = 'No changes saved (check username and role).';
    }
    if ($msg) {
        header('Location: admin_users.php?msg=' . urlencode($msg));
        exit;
    }
}

$edit_user_id = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$edit_all = isset($_GET['edit_all']);
$roleColorsEdit = isset($_GET['role_colors_edit']);
$addAsName = isset($_GET['add_as']) ? trim((string) $_GET['add_as']) : '';

if (isset($_GET['delete'])) {
    $del_id = (int) $_GET['delete'];
    if ($del_id > 0) {
        try {
            $pdo->prepare("DELETE FROM app_users WHERE id = ?")->execute([$del_id]);
            $msg = 'User removed.';
        } catch (PDOException $e) {
            $msg = $e->getMessage();
        }
        header('Location: admin_users.php?edit_all=1&msg=' . urlencode($msg ?? ''));
        exit;
    }
}

try {
    $roles = $pdo->query("SELECT id, name, COALESCE(description, '') AS description, color_code FROM roles ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($roles as $r) {
        $roleColorsMap[(int)$r['id']] = trim($r['color_code'] ?? '');
    }
    $users = $pdo->query("
        SELECT u.id, u.username, u.first_name, u.last_name, u.initials, u.role_id, r.name AS role_name
        FROM app_users u
        LEFT JOIN roles r ON u.role_id = r.id
        ORDER BY u.username
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

// Unmaintained: unique person_name from ticket_participants (Reporter + Responsible) not in app_users
$unmaintainedSearch = isset($_GET['unmaintained_q']) ? trim((string) $_GET['unmaintained_q']) : '';
$unmaintained = [];
try {
    $sqlUnmaintained = "
        SELECT tp.person_name,
               COUNT(DISTINCT tp.ticket_id) AS ticket_count
        FROM ticket_participants tp
        WHERE tp.role IN ('REP', 'RES')
        AND tp.person_name NOT IN (
            SELECT username FROM app_users WHERE username IS NOT NULL AND username != ''
            UNION
            SELECT TRIM(first_name) FROM app_users WHERE first_name IS NOT NULL AND TRIM(first_name) != ''
            UNION
            SELECT TRIM(CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,''))) FROM app_users WHERE (first_name IS NOT NULL OR last_name IS NOT NULL) AND TRIM(CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,''))) != ''
        )
        AND TRIM(tp.person_name) != ''
    ";
    $paramsUnmaintained = [];
    if ($unmaintainedSearch !== '') {
        $sqlUnmaintained .= " AND tp.person_name LIKE ?";
        $paramsUnmaintained[] = '%' . $unmaintainedSearch . '%';
    }
    $sqlUnmaintained .= " GROUP BY tp.person_name ORDER BY tp.person_name";
    $stmtUnmaintained = $pdo->prepare($sqlUnmaintained);
    $stmtUnmaintained->execute($paramsUnmaintained);
    $unmaintained = $stmtUnmaintained->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // ticket_participants might not exist yet
}

$roleColorOptions = [
    '#74c0fc' => 'Light Blue',
    '#8ce99a' => 'Light Green',
    '#fcc419' => 'Yellow',
    '#ffa8a8' => 'Light Red',
    ''        => '— none —',
    '#206bc4' => 'Blue',
    '#2fb344' => 'Green',
    '#f59f00' => 'Amber',
    '#e03131' => 'Red',
    '#ae3ec9' => 'Purple',
    '#1864ab' => 'Dark Blue',
    '#087f5b' => 'Dark Green',
    '#e8590c' => 'Dark Orange',
    '#c92a2a' => 'Dark Red',
    '#495057' => 'Dark Gray',
];
$pageTitle = 'Users &amp; roles – Admin';
require_once 'includes/header.php';
?>
        <div class="page-wrapper">
            <div class="page-body">
                <div class="container-xl">
                    <?php if (isset($_GET['msg'])): ?>
                    <div class="alert alert-info alert-dismissible"><?= e($_GET['msg']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>
                    <?php if ($dbError): ?>
                    <div class="alert alert-danger">Database error: <?= e($dbError) ?></div>
                    <?php endif; ?>

                    <div class="page-header d-print-none mb-4">
                        <h1 class="page-title">Users &amp; roles</h1>
                        <div class="text-muted">Create users and assign roles (for workflow permissions). Current login: <strong><?= e(get_effective_user() ?: '-') ?></strong></div>
                    </div>

                    <div class="card mb-4">
                        <?php if ($roleColorsEdit): ?>
                        <form method="post">
                        <?php endif; ?>
                        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <h3 class="card-title mb-0">Roles</h3>
                            <div class="d-flex gap-2">
                                <?php if ($roleColorsEdit): ?>
                                <a href="admin_users.php<?= isset($_GET['msg']) ? '?msg=' . urlencode($_GET['msg']) : '' ?>" class="btn btn-outline-secondary">Cancel</a>
                                <button type="submit" name="save_all_role_colors" class="btn btn-primary">Save</button>
                                <?php else: ?>
                                <a href="admin_users.php?role_colors_edit=1<?= isset($_GET['msg']) ? '&msg=' . urlencode($_GET['msg']) : '' ?>" class="btn btn-outline-secondary">Edit</a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <p class="text-muted small">Roles and colors (used for status Stage in Workflow Admin).</p>
                            <div class="table-responsive">
                                <table class="table table-sm table-vcenter" id="role-colors-table">
                                    <thead><tr><th>Role</th><th>Description</th><th>Color</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($roles as $r):
                                            $currentColor = trim($r['color_code'] ?? '');
                                            $inOptions = isset($roleColorOptions[$currentColor]);
                                            $colorLabel = $inOptions ? $roleColorOptions[$currentColor] : ($currentColor ?: '— none —');
                                        ?>
                                        <tr>
                                            <td><?= e($r['name']) ?></td>
                                            <td>
                                                <?php if ($roleColorsEdit): ?>
                                                <textarea name="role_description[<?= (int)$r['id'] ?>]" class="form-control form-control-sm" rows="2" placeholder="Optional" style="min-width: 220px; resize: vertical;"><?= e(trim($r['description'] ?? '')) ?></textarea>
                                                <?php else: ?>
                                                <?= nl2br(e(trim($r['description'] ?? ''))) ?: '—' ?>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($roleColorsEdit): ?>
                                                <div class="dropdown custom-color-picker">
                                                    <input type="hidden" name="role_color[<?= (int)$r['id'] ?>]" value="<?= e($currentColor) ?>">
                                                    <button type="button" class="btn btn-sm form-select text-start d-flex align-items-center justify-content-between bg-white" data-bs-toggle="dropdown" aria-expanded="false" style="min-width: 180px; width: auto; font-weight: normal;">
                                                        <span class="d-flex align-items-center gap-2">
                                                            <span class="color-swatch-sm border border-secondary" style="background-color: <?= $currentColor ? e($currentColor) : 'transparent' ?>;"></span>
                                                            <span class="color-name"><?= e($colorLabel) ?></span>
                                                        </span>
                                                    </button>
                                                    <div class="dropdown-menu p-2 shadow-sm" style="min-width: 220px;">
                                                        <div class="color-swatch-grid">
                                                            <?php foreach ($roleColorOptions as $optVal => $optLabel): ?>
                                                            <div class="color-swatch border <?= $currentColor === $optVal ? 'border-dark shadow-sm' : 'border-secondary-subtle' ?>"
                                                                 style="background-color: <?= $optVal ?: '#f8f9fa' ?>;"
                                                                 data-value="<?= e($optVal) ?>"
                                                                 data-name="<?= e($optLabel) ?>"
                                                                 title="<?= e($optLabel) ?>">
                                                                <?php if ($optVal === ''): ?>
                                                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-muted"><path d="M18 6l-12 12"></path><path d="M6 6l12 12"></path></svg>
                                                                <?php endif; ?>
                                                            </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                                <?php else: ?>
                                                <span class="d-inline-flex align-items-center gap-2">
                                                    <span class="color-swatch-sm border border-secondary" style="background-color: <?= $currentColor ? e($currentColor) : 'transparent' ?>;"></span>
                                                    <span><?= e($colorLabel) ?></span>
                                                </span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php if ($roleColorsEdit): ?>
                        </form>
                        <?php endif; ?>
                    </div>

                    <div class="card mb-4" id="add-user">
                        <div class="card-header">
                            <h3 class="card-title">Add user</h3>
                        </div>
                        <div class="card-body">
                            <form method="post" class="row g-3">
                                <div class="col-md-2">
                                    <label class="form-label">Username (login)</label>
                                    <input type="text" name="username" class="form-control" placeholder="e.g. LocalDevUser" required>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">First name</label>
                                    <input type="text" name="first_name" class="form-control" placeholder="Vorname" value="<?= e($addAsName) ?>">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Last name</label>
                                    <input type="text" name="last_name" class="form-control" placeholder="Nachname">
                                </div>
                                <div class="col-md-1">
                                    <label class="form-label">Initials</label>
                                    <input type="text" name="initials" class="form-control" placeholder="z.B. TM" maxlength="31">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Role</label>
                                    <select name="role_id" class="form-select" required>
                                        <?php foreach ($roles as $r): ?>
                                        <option value="<?= (int) $r['id'] ?>"><?= e($r['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2 d-flex align-items-end">
                                    <button type="submit" name="add_user" class="btn btn-primary">Add</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <h3 class="card-title mb-0">Users</h3>
                            <?php if (!empty($users)): ?>
                            <div class="d-flex gap-2">
                                <?php if ($edit_all): ?>
                                <a href="admin_users.php<?= isset($_GET['msg']) ? '?msg=' . urlencode($_GET['msg']) : '' ?>" class="btn btn-outline-secondary">Cancel</a>
                                <button type="submit" form="bulk-edit-form" name="update_users_bulk" class="btn btn-primary">Save</button>
                                <?php else: ?>
                                <a href="admin_users.php?edit_all=1<?= isset($_GET['msg']) ? '&msg=' . urlencode($_GET['msg']) : '' ?>" class="btn btn-outline-secondary">Edit</a>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <?php if (empty($users)): ?>
                            <p class="text-muted mb-0">No users yet. Add a user (username = login, e.g. as shown under “Current login”).</p>
                            <?php else: ?>
                            <?php if ($edit_all): ?>
                            <form method="post" id="bulk-edit-form">
                                <div class="table-responsive">
                                    <table class="table table-vcenter table-sm">
                                        <thead>
                                            <tr>
                                                <th>Username</th>
                                                <th>First name</th>
                                                <th>Last name</th>
                                                <th>Initials</th>
                                                <th>Role</th>
                                                <th></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($users as $u): ?>
                                            <tr>
                                                <td>
                                                    <input type="hidden" name="user_id[]" value="<?= (int) $u['id'] ?>">
                                                    <input type="text" name="username_bulk[]" class="form-control form-control-sm" value="<?= e($u['username']) ?>" required style="min-width:120px;">
                                                </td>
                                                <td><input type="text" name="first_name_bulk[]" class="form-control form-control-sm" value="<?= e($u['first_name'] ?? '') ?>" style="min-width:100px;"></td>
                                                <td><input type="text" name="last_name_bulk[]" class="form-control form-control-sm" value="<?= e($u['last_name'] ?? '') ?>" style="min-width:100px;"></td>
                                                <td><input type="text" name="initials_bulk[]" class="form-control form-control-sm" value="<?= e($u['initials'] ?? '') ?>" maxlength="31" style="min-width:60px;"></td>
                                                <td>
                                                    <div class="d-flex align-items-center gap-2">
                                                        <?php $roleColor = trim($roleColorsMap[(int)$u['role_id']] ?? ''); ?>
                                                        <span class="color-swatch-sm border border-secondary user-role-dot" id="user-role-dot-<?= (int)$u['id'] ?>" style="background-color: <?= $roleColor ? e($roleColor) : 'transparent' ?>;"></span>
                                                        <select name="role_id_bulk[]" class="form-select form-select-sm user-role-select" data-uid="<?= (int)$u['id'] ?>" required style="min-width:120px;">
                                                            <?php foreach ($roles as $r): ?>
                                                            <option value="<?= (int) $r['id'] ?>" <?= (int)$u['role_id'] === (int)$r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </td>
                                                <td>
                                                    <a href="admin_users.php?edit_all=1&amp;delete=<?= (int) $u['id'] ?>" class="btn btn-sm btn-icon btn-ghost-danger" title="Remove" onclick="return confirm('Remove this user?');"><svg xmlns="http://www.w3.org/2000/svg" class="icon" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path d="M4 7h16M10 11v6M14 11v6M5 7l1 12a2 2 0 002 2h8a2 2 0 002-2L19 7"/><path d="M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3"/></svg></a>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </form>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-vcenter table-sm">
                                    <thead>
                                        <tr>
                                            <th>Username</th>
                                            <th>First name</th>
                                            <th>Last name</th>
                                            <th>Initials</th>
                                            <th>Role</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($users as $u):
                                            $uRoleColor = trim($roleColorsMap[(int)$u['role_id']] ?? '');
                                            $uRoleColorStyle = ($uRoleColor !== '' && strpos($uRoleColor, '#') === 0) ? 'background-color:' . e($uRoleColor) . '20;color:' . e($uRoleColor) : 'background-color:#f1f3f5;color:#495057';
                                        ?>
                                        <tr>
                                            <td><?= e($u['username']) ?></td>
                                            <td><?= e($u['first_name'] ?? '-') ?></td>
                                            <td><?= e($u['last_name'] ?? '-') ?></td>
                                            <td><?= e($u['initials'] ?? '-') ?></td>
                                            <td><span class="badge" style="<?= $uRoleColorStyle ?>"><?= e($u['role_name'] ?? '-') ?></span></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php // Tickets: Responsible / Reporter not yet as user — vorerst nicht genutzt
                    if (false): ?>
                    <div class="card mt-4">
                        <div class="card-header">
                            <h3 class="card-title">Tickets: Responsible / Reporter not yet as user</h3>
                            <div class="card-actions">
                                <form method="get" class="d-flex flex-wrap gap-2 align-items-center">
                                    <input type="search" name="unmaintained_q" class="form-control form-control-sm" placeholder="Search name…" value="<?= e($unmaintainedSearch) ?>" style="min-width:160px;">
                                    <button type="submit" class="btn btn-sm btn-primary">Search</button>
                                </form>
                            </div>
                        </div>
                        <div class="card-body">
                            <p class="text-muted small mb-3">Unique person names from tickets (Reporter and Responsible) that are not in the user list above. One row per name. Add them via &quot;Add user&quot; if they should be able to log in or appear in dropdowns.</p>
                            <?php if (empty($unmaintained)): ?>
                            <p class="text-muted mb-0">No unmaintained names found<?= $unmaintainedSearch !== '' ? ' for this filter.' : ' (or ticket_participants table is empty).' ?></p>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-vcenter table-sm">
                                    <thead>
                                        <tr>
                                            <th>Person name</th>
                                            <th>Tickets</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($unmaintained as $row): ?>
                                        <tr>
                                            <td><?= e($row['person_name']) ?></td>
                                            <td><?= (int) $row['ticket_count'] ?></td>
                                            <td>
                                                <a href="admin_users.php?add_as=<?= urlencode($row['person_name']) ?>#add-user" class="btn btn-sm btn-outline-primary">Add as user</a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <style>
    .color-swatch-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 8px; }
    .color-swatch { width: 28px; height: 28px; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: transform 0.15s ease, box-shadow 0.15s ease; }
    .color-swatch:hover { transform: scale(1.15); box-shadow: 0 .125rem .25rem rgba(0,0,0,.075); }
    .color-swatch-sm { width: 14px; height: 14px; border-radius: 50%; display: inline-block; flex-shrink: 0; }
    </style>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var roleColorsMap = <?= json_encode($roleColorsMap) ?>;
        document.querySelectorAll('.user-role-select').forEach(function(select) {
            select.addEventListener('change', function() {
                var uid = this.getAttribute('data-uid');
                var roleId = this.value;
                var dot = document.getElementById('user-role-dot-' + uid);
                if (dot) {
                    dot.style.backgroundColor = (roleId && roleColorsMap[roleId]) ? roleColorsMap[roleId] : 'transparent';
                }
            });
        });
        document.querySelectorAll('.custom-color-picker .color-swatch').forEach(function(swatch) {
            swatch.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var picker = this.closest('.custom-color-picker');
                var val = this.getAttribute('data-value');
                var name = this.getAttribute('data-name');
                picker.querySelector('input[type="hidden"]').value = val;
                var btnSwatch = picker.querySelector('.color-swatch-sm');
                btnSwatch.style.backgroundColor = val || 'transparent';
                picker.querySelector('.color-name').textContent = name;
                picker.querySelectorAll('.color-swatch').forEach(function(s) {
                    s.classList.remove('border-dark', 'shadow-sm');
                    s.classList.add('border-secondary-subtle');
                });
                this.classList.remove('border-secondary-subtle');
                this.classList.add('border-dark', 'shadow-sm');
                var btn = picker.querySelector('[data-bs-toggle="dropdown"]');
                var dropdown = bootstrap.Dropdown.getInstance(btn);
                if (dropdown) dropdown.hide();
            });
        });
    });
    </script>
    <?php require_once 'includes/footer.php'; ?>
</body>
</html>

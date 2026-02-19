<?php
/**
 * import_leaves.php - Import vacation.csv into user_leaves
 * Maps CSV rows to app_users by first_name + last_name. Deletes CSV after successful import.
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(120);

require 'includes/config.php';

$csvFile = __DIR__ . '/data/vacation.csv';
$pageTitle = 'Import Abwesenheiten';
$pageHeadExtra = '<style>.log{background:#1e1e1e;color:#eee;padding:20px;border-radius:8px;font-family:monospace;font-size:13px;}.ok{color:#51cf66;}.warn{color:#fcc419;}.err{color:#ff6b6b;}.info{color:#339af0;}</style>';
require_once 'includes/header.php';

/**
 * Normalize string for fuzzy matching: lowercase, Umlaute to ASCII
 */
function normalizeName($s) {
    $s = mb_strtolower(trim($s ?? ''), 'UTF-8');
    $map = ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss'];
    foreach ($map as $from => $to) {
        $s = str_replace($from, $to, $s);
    }
    return $s;
}

?>
    <div class="page-wrapper">
        <div class="page-body">
            <div class="container-xl">
                <h1 class="page-title mb-4">Import Abwesenheiten (vacation.csv)</h1>

<?php
if (!file_exists($csvFile)) {
    echo "<div class='alert alert-warning'>Datei <code>data/vacation.csv</code> nicht gefunden. Import bereits ausgeführt oder Datei wurde manuell entfernt.</div>";
    echo "<p><a href='admin_leaves.php' class='btn btn-primary'>To leaves overview</a></p>";
    require_once 'includes/footer.php';
    exit;
}

$handle = fopen($csvFile, 'r');
if (!$handle) {
    echo "<div class='alert alert-danger'>CSV-Datei kann nicht geöffnet werden.</div>";
    require_once 'includes/footer.php';
    exit;
}

// Create user_leaves table if not exists (without status column)
$pdo->exec("
    CREATE TABLE IF NOT EXISTS user_leaves (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT UNSIGNED NOT NULL,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        comment VARCHAR(255) DEFAULT NULL,
        is_sickness TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_leave_user (user_id),
        CONSTRAINT fk_leave_app_user FOREIGN KEY (user_id) REFERENCES app_users (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");
try {
    $pdo->exec("ALTER TABLE user_leaves DROP COLUMN status");
} catch (PDOException $e) { /* column may already be missing */ }

$header = fgetcsv($handle, 0, ',');
$imported = 0;
$skipped = [];
try {
    $pdo->exec("ALTER TABLE user_leaves ADD COLUMN is_sickness TINYINT(1) NOT NULL DEFAULT 0 AFTER comment");
} catch (PDOException $e) { /* column may already exist */ }
$stmt = $pdo->prepare("INSERT INTO user_leaves (user_id, start_date, end_date, comment, is_sickness) VALUES (?, ?, ?, ?, 0)");

// Preload all app_users for matching (first_name, last_name -> id)
$users = $pdo->query("SELECT id, first_name, last_name FROM app_users")->fetchAll(PDO::FETCH_ASSOC);
$userByExact = [];
$userByNormalized = [];
foreach ($users as $u) {
    $fn = trim($u['first_name'] ?? '');
    $ln = trim($u['last_name'] ?? '');
    if ($fn !== '' || $ln !== '') {
        $full = $fn . ' ' . $ln;
        $userByExact[mb_strtolower($full, 'UTF-8')] = (int) $u['id'];
        $norm = normalizeName($fn) . ' ' . normalizeName($ln);
        $userByNormalized[$norm] = (int) $u['id'];
    }
}

while (($row = fgetcsv($handle, 0, ',')) !== false) {
    if (count($row) < 4) continue;
    $first = trim($row[0] ?? '');
    $last = trim($row[1] ?? '');
    $start = trim($row[2] ?? '');
    $end = trim($row[3] ?? '');
    $comment = isset($row[4]) ? trim($row[4], " \t\n\r\"") : null;
    if ($comment === '') $comment = null;

    if ($first === '' && $last === '') continue;
    if ($start === '' || $end === '') continue;

    $full = $first . ' ' . $last;
    $fullLower = mb_strtolower($full, 'UTF-8');
    $fullNorm = normalizeName($first) . ' ' . normalizeName($last);

    $userId = $userByExact[$fullLower] ?? $userByNormalized[$fullNorm] ?? null;

    if ($userId === null) {
        $skipped[] = $full . ' (' . $start . ' - ' . $end . ')';
        continue;
    }

    try {
        $stmt->execute([$userId, $start, $end, $comment]);
        $imported++;
    } catch (PDOException $e) {
        $skipped[] = $full . ': ' . $e->getMessage();
    }
}

fclose($handle);

// Delete CSV after successful import (only when at least one row was imported)
if ($imported > 0) {
    @unlink($csvFile);
}
?>

                <div class="alert alert-success">
                    <strong><?= $imported ?></strong> Einträge erfolgreich importiert.
                </div>
                <?php if (!empty($skipped)): ?>
                <div class="alert alert-warning">
                    <strong>Nicht zugeordnet (<?= count($skipped) ?>):</strong>
                    <ul class="mb-0 mt-2">
                        <?php foreach ($skipped as $s): ?>
                        <li><?= htmlspecialchars($s, ENT_QUOTES, 'UTF-8') ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="mb-0 mt-2 small">Füge fehlende Nutzer unter <a href="admin_users.php">Admin → Users &amp; roles</a> hinzu.<?= file_exists($csvFile) ? ' Die CSV wurde nicht gelöscht – lade diese Seite erneut für einen erneuten Import.' : ' Die CSV wurde nach dem Import gelöscht.' ?></p>
                </div>
                <?php endif; ?>
                <p><a href="admin_leaves.php" class="btn btn-primary">To leaves overview</a></p>
            </div>
        </div>
    </div>
<?php require_once 'includes/footer.php'; ?>

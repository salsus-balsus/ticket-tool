<?php
/**
 * ts_projects: Spalte "Code" entfernen, Namen auf Classic / Cloud Ready / Misc setzen, Zeile "Off" löschen.
 */
require_once __DIR__ . '/../includes/config.php';

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    // 1. Zeile "Off" löschen (Name oder Code = Off)
    $pdo->exec("DELETE FROM ts_projects WHERE name = 'Off' OR code = 'Off' OR LOWER(TRIM(COALESCE(name, code))) = 'off'");

    // 2. Namen auf Classic, Cloud Ready, Misc setzen (nach ID-Reihenfolge)
    $rows = $pdo->query("SELECT id, name FROM ts_projects ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $names = ['Classic', 'Cloud Ready', 'Misc'];
    foreach ($rows as $i => $row) {
        $name = array_key_exists($i, $names) ? $names[$i] : $row['name'];
        $pdo->prepare("UPDATE ts_projects SET name = ? WHERE id = ?")->execute([$name, $row['id']]);
    }

    // 3. Spalte code entfernen (nur wenn vorhanden)
    $hasCode = $pdo->query("SHOW COLUMNS FROM ts_projects LIKE 'code'")->rowCount() > 0;
    if ($hasCode) {
        $hasIndex = $pdo->query("SHOW INDEX FROM ts_projects WHERE Key_name = 'uq_ts_projects_code'")->rowCount() > 0;
        if ($hasIndex) {
            $pdo->exec("ALTER TABLE ts_projects DROP INDEX uq_ts_projects_code");
        }
        $pdo->exec("ALTER TABLE ts_projects DROP COLUMN code");
    }

    echo "ts_projects migration completed: names set, Off removed" . ($hasCode ? ", code dropped" : "") . ".\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

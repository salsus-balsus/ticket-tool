<?php
/**
 * Replace stage column with stage_role_id: fill from existing stage text (Quality→role 1, etc.), then drop stage.
 * Run once. Safe to re-run if stage already dropped (skips).
 */
require __DIR__ . '/../includes/config.php';

try {
    $cols = $pdo->query("SHOW COLUMNS FROM ticket_statuses")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('stage', $cols, true)) {
        echo "Column stage already removed. OK.";
        exit(0);
    }

    $roleNames = $pdo->query("SELECT id, TRIM(name) AS name FROM roles")->fetchAll(PDO::FETCH_ASSOC);
    $nameToId = [];
    foreach ($roleNames as $r) {
        $nameToId[strtolower(trim($r['name']))] = (int) $r['id'];
    }
    $stageToRole = [
        'quality'   => $nameToId['quality']   ?? 1,
        'business'  => $nameToId['business']  ?? null,
        'development' => $nameToId['development'] ?? $nameToId['dev'] ?? null,
        'dev'       => $nameToId['dev']       ?? $nameToId['development'] ?? null,
        'delivery'  => $nameToId['delivery']  ?? null,
        'end'       => $nameToId['end']       ?? null,
    ];

    $getRoleColor = $pdo->prepare("SELECT color_code FROM roles WHERE id = ? LIMIT 1");
    $stmtUpdate = $pdo->prepare("UPDATE ticket_statuses SET stage_role_id = ?, color_code = ? WHERE id = ?");
    $rows = $pdo->query("SELECT id, TRIM(stage) AS stage FROM ticket_statuses WHERE stage IS NOT NULL AND TRIM(stage) != ''")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $stageKey = strtolower($row['stage']);
        $roleId = $stageToRole[$stageKey] ?? $nameToId[$stageKey] ?? null;
        if ($roleId !== null) {
            $getRoleColor->execute([$roleId]);
            $color = trim($getRoleColor->fetchColumn() ?: '');
            $stmtUpdate->execute([$roleId, $color ?: null, $row['id']]);
        }
    }

    $pdo->exec("ALTER TABLE ticket_statuses DROP COLUMN stage");
    echo "OK: Filled stage_role_id from stage, dropped stage column.";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
    exit(1);
}

<?php
// debug_schema.php
require 'includes/config.php';

echo "<h1>Aktuelles Datenbank-Schema</h1>";
echo "<p>Kopiere alles im grauen Kasten und gib es dem Bot:</p>";
echo "<textarea style='width:100%; height:600px; font-family:monospace; padding:10px;'>";

try {
    // 1. Alle Tabellen holen
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $table) {
        // 2. Create Statement für jede Tabelle holen
        $stmt = $pdo->query("SHOW CREATE TABLE `$table`");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // 3. Ausgeben
        echo "-- Struktur für Tabelle: $table\n";
        echo $row['Create Table'] . ";\n\n";
    }

} catch (PDOException $e) {
    echo "Fehler: " . $e->getMessage();
}

echo "</textarea>";
?>
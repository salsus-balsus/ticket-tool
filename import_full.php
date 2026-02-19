<?php
/**
 * IMPORT FULL V3: Auto-Detect Column
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(300);

require 'includes/config.php';

// --- KONFIGURATION ---
$csvFile = 'data/tickets.csv'; 
$delimiter = ';';

// Mapping für die anderen (statischen) Spalten
// BITTE PRÜFEN: Stimmen Titel/Status noch?
$colMap = [
    'title' => 1,       
    'status' => 2,      
    'priority' => 3,    
    'object' => 5,      
    'desc' => 10
    // 'comments' => WIRD JETZT AUTOMATISCH GESUCHT!
];

$userMap = require __DIR__ . '/includes/author_map.php';

// STATUS MAPPING
$statusMap = [
    'Neu' => 10, 'New' => 10,
    'Techn. Evaluation' => 20, 'Evaluation' => 20,
    'Business Eval' => 30,
    'Dev Planning' => 40, 'Planning' => 40,
    'In Development' => 50, 'Development' => 50,
    'Basic Test' => 60, 'Test' => 60,
    'Transport' => 70,
    'Cust. Del. Test' => 80, 'SOX' => 80,
    'Note Creation' => 85,
    'Customer Action' => 90,
    'Solution Proposal' => 95,
    'Confirmed' => 100, 'Closed' => 100
];

echo "<style>
    body { font-family: monospace; line-height: 1.4; background: #222; color: #eee; padding: 20px; }
    .log-row { border-bottom: 1px solid #444; padding: 5px 0; }
    .success { color: #51cf66; }
    .warn { color: #fcc419; }
    .error { color: #ff6b6b; }
    .info { color: #339af0; }
</style>";

echo "<h1>🚀 Import Start (Auto-Detect)</h1>";

if (!file_exists($csvFile)) die("<h2 class='error'>❌ Datei $csvFile nicht gefunden!</h2>");

// 1. SPALTE AUTOMATISCH FINDEN
echo "<div class='info'>🔍 Suche Kommentar-Spalte...</div>";
$handle = fopen($csvFile, "r");
$detectedColIndex = -1;
$maxMatches = 0;

// Scan der ersten 10 Zeilen
for ($i=0; $i<10; $i++) {
    $row = fgetcsv($handle, 10000, $delimiter);
    if (!$row) break;
    foreach ($row as $idx => $val) {
        // Suche nach Datumsmuster (dd.mm.)
        if (preg_match_all('/(\d{1,2}\.\d{1,2}\.)/', $val, $m)) {
            $count = count($m[0]);
            if ($count > $maxMatches) {
                $maxMatches = $count;
                $detectedColIndex = $idx;
            }
        }
    }
}
rewind($handle);

if ($detectedColIndex == -1) {
    die("<h2 class='error'>❌ Konnte Kommentar-Spalte nicht finden! (Keine Datums-Muster erkannt)</h2>");
}

echo "<div class='success'>✅ Kommentar-Spalte gefunden auf Index: <strong>$detectedColIndex</strong> (Spalte " . chr(65 + $detectedColIndex) . ")</div><br>";

// 2. CLEAN SLATE
try {
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    $pdo->exec("TRUNCATE TABLE ticket_comments");
    $pdo->exec("TRUNCATE TABLE ticket_history");
    $pdo->exec("TRUNCATE TABLE ticket_relationships");
    $pdo->exec("TRUNCATE TABLE tickets");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
} catch (PDOException $e) {
    die("<div class='error'>❌ DB Fehler: " . $e->getMessage() . "</div>");
}

// 3. CACHE & IMPORT
$stmt = $pdo->query("SELECT id, name FROM objects");
$objectCache = [];
while ($row = $stmt->fetch()) {
    $objectCache[strtoupper(trim($row['name']))] = $row['id'];
}

$stats = ['tickets' => 0, 'comments_created' => 0, 'comments_skipped' => 0];
$pdo->beginTransaction();

try {
    while (($data = fgetcsv($handle, 10000, $delimiter)) !== FALSE) {
        $stats['tickets']++;
        if ($stats['tickets'] == 1) continue; 

        // Ticket Data
        $title = $data[$colMap['title']] ?? 'Ohne Titel';
        $statusRaw = trim($data[$colMap['status']] ?? '');
        $objectRaw = trim($data[$colMap['object']] ?? '');
        $desc = $data[$colMap['desc']] ?? ''; // Beschreibung separat
        
        $statusId = $statusMap[$statusRaw] ?? 10;
        $objectId = $objectCache[strtoupper($objectRaw)] ?? 1;

        $stmt = $pdo->prepare("INSERT INTO tickets (title, detailed_desc, type_id, status_id, object_id, created_by) VALUES (?, ?, 2, ?, ?, 'Import')");
        $stmt->execute([$title, $desc, $statusId, $objectId]);
        $ticketId = $pdo->lastInsertId();

        // Comment Parsing (Mit gefundener Spalte)
        if (!isset($data[$detectedColIndex])) continue;
        $rawText = $data[$detectedColIndex];
        
        $regex = '/(\d{1,2}\.\d{1,2}\.(?:\d{2,4})?)\s*(?:(\d{1,2}:\d{2})\s*)?(?:([A-Za-z]{2,5})\b)?[:\-\s]*(.*?)(?=\d{1,2}\.\d{1,2}\.(?:\d{2,4})?|$)/s';
        
        if (preg_match_all($regex, $rawText, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $dateStr = $m[1];
                $initial = isset($m[3]) ? strtoupper(trim($m[3])) : '';
                $text = trim($m[4]);

                if (empty($initial) || !isset($userMap[$initial])) {
                    $stats['comments_skipped']++;
                    continue; 
                }

                $authorName = $userMap[$initial];
                try {
                    $dbDate = (new DateTime($dateStr))->format('Y-m-d H:i:s');
                } catch (Exception $e) {
                    $dbDate = date('Y-m-d H:i:s');
                }

                $cStmt = $pdo->prepare("INSERT INTO ticket_comments (ticket_id, author, comment_text, created_at) VALUES (?, ?, ?, ?)");
                $cStmt->execute([$ticketId, $authorName, $text, $dbDate]);
                $stats['comments_created']++;
            }
        }
    }

    $pdo->commit();
    echo "<br><div style='border: 2px solid #51cf66; padding: 20px;'>";
    echo "<h2>✅ Import erfolgreich!</h2>";
    echo "Genutzte Spalte: <strong>$detectedColIndex</strong><br>";
    echo "Tickets: <strong>" . $stats['tickets'] . "</strong><br>";
    echo "Kommentare: <strong class='success'>" . $stats['comments_created'] . "</strong>";
    echo "</div>";

} catch (Exception $e) {
    $pdo->rollBack();
    die("<h1 class='error'>FATAL ERROR: " . $e->getMessage() . "</h1>");
}
?>
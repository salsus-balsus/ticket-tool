<?php
/**
 * import_scopes.php
 * Importiert konsolidierte Customer Scopes und ordnet sie einheitlichen Stati zu.
 */

require 'includes/config.php';

/**
 * Mappt den rohen Text auf die 5 einheitlichen Stati (Unified Status)
 */
function mapToUnifiedStatus($rawText) {
    $raw = strtolower($rawText);
    
    // 1. On Hold: Probleme oder expliziter Stopp
    if (str_contains($raw, 'hold') || str_contains($raw, 'problem')) {
        return 'On Hold';
    }
    
    // 2. Live: Abgeschlossen und produktiv
    if (str_contains($raw, 'production') || str_contains($raw, 'completed')) {
        return 'Live';
    }
    // "implemented" gilt als Live, außer es steht "not" davor
    if (str_contains($raw, 'implemented') && !str_contains($raw, 'not')) {
        return 'Live';
    }
    
    // 3. Verification: Im Test oder in der Abnahme
    if (str_contains($raw, 'verification') || str_contains($raw, 'test') || str_contains($raw, 'not tested')) {
        return 'Verification';
    }
    
    // 4. Planned: Zukunft/Roadmap (Jahreszahlen oder "planned")
    if (str_contains($raw, 'planned') || preg_match('/\b202\d\b/', $raw)) {
        return 'Planned';
    }
    
    // 5. In Progress: Standard für aktive Klärung/Entwicklung
    return 'In Progress';
}

/**
 * Extrahiert Phasen-Informationen (z.B. "Phase 1" oder "2026")
 */
function extractPhase($rawText) {
    if (preg_match('/(Phase\s?\d+)/i', $rawText, $matches)) {
        return $matches[1];
    }
    if (preg_match('/\b(202\d)\b/', $rawText, $matches)) {
        return $matches[1];
    }
    return null;
}

try {
    // CSV-Pfad ermitteln (prüft /data/ und Root)
    $csvPath = __DIR__ . '/data/updated_customer_scope.csv';
    if (!file_exists($csvPath)) {
        $csvPath = __DIR__ . '/updated_customer_scope.csv';
    }

    if (!file_exists($csvPath)) {
        throw new RuntimeException("CSV-Datei nicht gefunden: updated_customer_scope.csv");
    }

    // Datenbank vorbereiten (Clean Slate)
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    $pdo->exec("TRUNCATE TABLE customer_scopes");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

    $file = fopen($csvPath, 'r');
    $header = fgetcsv($file); // Header überspringen
    
    $customerCache = [];
    $objectCache = [];
    $count = 0;

    echo "Initialisiere Scope-Import mit Unified Status Mapping...\n";

    while (($row = fgetcsv($file)) !== FALSE) {
        if (empty($row[0])) continue; // Leere Zeilen überspringen

        $customerName = trim($row[0]);
        $objectName   = trim($row[1]);
        $infoDate     = !empty($row[2]) ? trim($row[2]) : null;
        $rawComment   = trim($row[3]);
        
        // 1. Customer ID (holen oder automatisch anlegen)
        if (!isset($customerCache[$customerName])) {
            $stmt = $pdo->prepare("SELECT id FROM customers WHERE name = ?");
            $stmt->execute([$customerName]);
            $c = $stmt->fetch();
            if ($c) {
                $customerCache[$customerName] = $c['id'];
            } else {
                $stmt = $pdo->prepare("INSERT INTO customers (name) VALUES (?)");
                $stmt->execute([$customerName]);
                $customerCache[$customerName] = $pdo->lastInsertId();
            }
        }
        
        // 2. Object ID (holen)
        if (!isset($objectCache[$objectName])) {
            $stmt = $pdo->prepare("SELECT id FROM objects WHERE name = ?");
            $stmt->execute([$objectName]);
            $o = $stmt->fetch();
            $objectCache[$objectName] = $o ? $o['id'] : null;
        }
        
        // Nur importieren, wenn das Objekt in unserer bereinigten DB existiert
        if ($objectCache[$objectName]) {
            $unifiedStatus = mapToUnifiedStatus($rawComment);
            $phase = extractPhase($rawComment);

            $insert = $pdo->prepare("
                INSERT INTO customer_scopes (customer_id, object_id, phase, status, info_date, comment) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $insert->execute([
                $customerCache[$customerName],
                $objectCache[$objectName],
                $phase,
                $unifiedStatus,
                $infoDate,
                $rawComment // Originaler Text bleibt als Kommentar erhalten
            ]);
            $count++;
        }
    }
    
    fclose($file);
    echo "Import erfolgreich! $count Einträge wurden mit einheitlichen Stati verarbeitet.\n";

} catch (Exception $e) {
    echo "FEHLER: " . $e->getMessage() . "\n";
}
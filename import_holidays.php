<?php
// Einbinden der Datenbankverbindung (Pfad ggf. anpassen)
require_once 'includes/config.php'; 

$json = file_get_contents('data/public_holiday.json');
$data = json_decode($json, true);

if (!$data || $data['status'] !== 'success' || empty($data['feiertage'])) {
    die("Fehler beim Einlesen der JSON-Datei.");
}

// Mapping der Länderkürzel zu vollen Namen
$states = [
    'bw' => 'Baden-Württemberg',
    'by' => 'Bayern',
    'be' => 'Berlin',
    'bb' => 'Brandenburg',
    'hb' => 'Bremen',
    'hh' => 'Hamburg',
    'he' => 'Hessen',
    'mv' => 'Mecklenburg-Vorpommern',
    'ni' => 'Niedersachsen',
    'nw' => 'Nordrhein-Westfalen',
    'rp' => 'Rheinland-Pfalz',
    'sl' => 'Saarland',
    'sn' => 'Sachsen',
    'st' => 'Sachsen-Anhalt',
    'sh' => 'Schleswig-Holstein',
    'th' => 'Thüringen'
];

try {
    // 1. TRUNCATE MUSS vor der Transaktion ausgeführt werden (impliziter Commit in MySQL!)
    $pdo->exec("TRUNCATE TABLE holidays");

    // Jetzt erst die Transaktion für die reinen Daten-Inserts starten
    $pdo->beginTransaction();

    // 2. Bundesländer in die DB schreiben (IGNORE verhindert Fehler, falls sie schon existieren)
    $stmtState = $pdo->prepare("INSERT IGNORE INTO federal_states (id, name) VALUES (?, ?)");
    foreach ($states as $code => $name) {
        $stmtState->execute([strtoupper($code), $name]);
    }

    // 3. Feiertage importieren
    $stmtHoliday = $pdo->prepare("
        INSERT INTO holidays (state_id, date, name, comment, is_augsburg, is_catholic) 
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    foreach ($data['feiertage'] as $holiday) {
        $date = $holiday['date'];
        $name = $holiday['fname'];
        $comment = $holiday['comment'];
        // Konvertiere mögliche NULL oder leere Strings sauber in 0 oder 1
        $is_augsburg = !empty($holiday['augsburg']) ? 1 : 0;
        $is_catholic = !empty($holiday['katholisch']) ? 1 : 0;
        $all_states = ($holiday['all_states'] === "1");

        // Für jedes Bundesland prüfen, ob der Feiertag dort gilt
        foreach ($states as $code => $stateName) {
            if ($all_states || (isset($holiday[$code]) && $holiday[$code] === "1")) {
                $stmtHoliday->execute([
                    strtoupper($code),
                    $date,
                    $name,
                    $comment,
                    $is_augsburg,
                    $is_catholic
                ]);
            }
        }
    }

    $pdo->commit();
    echo "Feiertage wurden erfolgreich importiert!";

} catch (Exception $e) {
    // Sicherheitscheck: Nur Rollback ausführen, wenn auch eine Transaktion läuft
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    die("Datenbankfehler: " . $e->getMessage());
}
?>
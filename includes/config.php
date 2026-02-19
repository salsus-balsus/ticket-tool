<?php
// config.php
// Einstellungen für Datenbank und Environment

$host = 'localhost';
$db   = 'ticket_db';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("<h3>Datenbank-Verbindungsfehler</h3>" . $e->getMessage());
}

// --- SSO / USER ERKENNUNG ---
// Versucht, den Windows-User zu lesen. Fallback auf "LocalDev".
if (isset($_SERVER['REMOTE_USER'])) {
    $parts = explode('\\', $_SERVER['REMOTE_USER']);
    $current_user = end($parts);
} elseif (isset($_SERVER['AUTH_USER'])) {
    $current_user = $_SERVER['AUTH_USER'];
} else {
    $current_user = 'LocalDevUser';
}

// --- HELPER FUNKTIONEN ---
// Schützt vor XSS-Attacken bei der Ausgabe
function e($text) {
    return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
}
?>
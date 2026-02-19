<?php
/**
 * Set dev user cookie for testing (used by navbar user dropdown).
 * When set, the app acts as that user (permissions, display).
 * Redirects back to referer or index.
 */
require_once 'includes/config.php';

$user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : (isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0);
$redirect = isset($_SERVER['HTTP_REFERER']) && strlen($_SERVER['HTTP_REFERER']) > 0
    ? $_SERVER['HTTP_REFERER']
    : 'index.php';

if ($user_id >= 0) {
    if ($user_id === 0) {
        setcookie('dev_user_id', '', time() - 3600, '/');
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id FROM app_users WHERE id = ?");
            $stmt->execute([$user_id]);
            if ($stmt->fetchColumn() !== false) {
                setcookie('dev_user_id', (string) $user_id, time() + 86400 * 7, '/');
            }
        } catch (PDOException $e) {}
    }
}

header('Location: ' . $redirect);
exit;

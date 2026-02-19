<?php
/**
 * Set dev role cookie for testing (used by header role dropdown).
 * Redirects back to referer or index.
 */
require_once 'includes/config.php';

$role_id = isset($_POST['role_id']) ? (int) $_POST['role_id'] : (isset($_GET['role_id']) ? (int) $_GET['role_id'] : 0);
$redirect = isset($_SERVER['HTTP_REFERER']) && strlen($_SERVER['HTTP_REFERER']) > 0
    ? $_SERVER['HTTP_REFERER']
    : 'index.php';

if ($role_id >= 0) {
    if ($role_id === 0) {
        setcookie('dev_role_id', '', time() - 3600, '/');
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id FROM roles WHERE id = ?");
            $stmt->execute([$role_id]);
            if ($stmt->fetchColumn() !== false) {
                setcookie('dev_role_id', (string) $role_id, time() + 86400 * 7, '/');
            }
        } catch (PDOException $e) {}
    }
}

header('Location: ' . $redirect);
exit;

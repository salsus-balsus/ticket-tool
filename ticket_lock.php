<?php
/**
 * ticket_lock.php - Quality lock overlay: set Obsolete / On Hold / Redirect or revoke.
 * POST only. Allowed only when ticket current owner role is Quality.
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: tickets.php');
    exit;
}

$ticket_id = isset($_POST['ticket_id']) ? (int) $_POST['ticket_id'] : 0;
$action = isset($_POST['action']) ? trim($_POST['action']) : '';
$lock_type = isset($_POST['lock_type']) ? strtoupper(trim($_POST['lock_type'])) : '';
$redirect_ticket_id = isset($_POST['redirect_ticket_id']) ? (int) $_POST['redirect_ticket_id'] : 0;

if ($ticket_id <= 0) {
    header('Location: ticket_detail.php?id=' . $ticket_id . '&msg=' . urlencode('Invalid request'));
    exit;
}

$allowed_lock_types = ['OBS', 'ONH', 'RED'];

try {
    $ticket = null;
    try {
        $stmt = $pdo->prepare("
            SELECT t.id, t.lock_type, r.name AS role_name
            FROM tickets t
            LEFT JOIN roles r ON t.current_role_id = r.id
            WHERE t.id = ?
        ");
        $stmt->execute([$ticket_id]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        if ((string) $e->getCode() === '42S22') {
            $stmt = $pdo->prepare("
                SELECT t.id, r.name AS role_name
                FROM tickets t
                LEFT JOIN roles r ON t.current_role_id = r.id
                WHERE t.id = ?
            ");
            $stmt->execute([$ticket_id]);
            $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($ticket) {
                $ticket['lock_type'] = null;
            }
        } else {
            throw $e;
        }
    }

    if (!$ticket) {
        header('Location: tickets.php');
        exit;
    }

    if (($ticket['role_name'] ?? '') !== 'Quality') {
        header('Location: ticket_detail.php?id=' . $ticket_id . '&msg=' . urlencode('Only Quality can set or revoke this lock'));
        exit;
    }

    $migration_msg = 'Lock feature requires DB migration. Run: migrations/tickets_lock_type.sql';

    if ($action === 'set_lock') {
        if (!in_array($lock_type, $allowed_lock_types, true)) {
            header('Location: ticket_detail.php?id=' . $ticket_id . '&msg=' . urlencode('Invalid lock type'));
            exit;
        }
        $redirect_id_for_update = null;
        if ($lock_type === 'RED') {
            if ($redirect_ticket_id <= 0) {
                header('Location: ticket_detail.php?id=' . $ticket_id . '&msg=' . urlencode('Please select the follow-up ticket for Redirect'));
                exit;
            }
            if ($redirect_ticket_id === $ticket_id) {
                header('Location: ticket_detail.php?id=' . $ticket_id . '&msg=' . urlencode('Redirect target cannot be this ticket'));
                exit;
            }
            $stmt = $pdo->prepare("SELECT id FROM tickets WHERE id = ?");
            $stmt->execute([$redirect_ticket_id]);
            if (!$stmt->fetch()) {
                header('Location: ticket_detail.php?id=' . $ticket_id . '&msg=' . urlencode('Selected ticket not found'));
                exit;
            }
            $redirect_id_for_update = $redirect_ticket_id;
        }
        try {
            if ($redirect_id_for_update !== null) {
                $stmt = $pdo->prepare("UPDATE tickets SET lock_type = ?, redirect_ticket_id = ? WHERE id = ?");
                $stmt->execute([$lock_type, $redirect_id_for_update, $ticket_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE tickets SET lock_type = ?, redirect_ticket_id = NULL WHERE id = ?");
                $stmt->execute([$lock_type, $ticket_id]);
            }
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '42S22') {
                header('Location: ticket_detail.php?id=' . $ticket_id . '&msg=' . urlencode($migration_msg));
                exit;
            }
            throw $e;
        }
        $lock_labels = ['OBS' => 'Obsolete', 'ONH' => 'On Hold', 'RED' => 'Redirect'];
        $msg = $lock_labels[$lock_type] . ' set';
        try {
            $pdo->prepare("INSERT INTO ticket_history (ticket_id, change_type, old_value, new_value, created_at, created_by) VALUES (?, 'lock_set', ?, ?, NOW(), ?)")
                ->execute([$ticket_id, $ticket['lock_type'] ?? '', $lock_type, get_effective_user() ?: 'System']);
        } catch (PDOException $e) {}
    } elseif ($action === 'revoke_lock') {
        try {
            $stmt = $pdo->prepare("UPDATE tickets SET lock_type = NULL, redirect_ticket_id = NULL WHERE id = ?");
            $stmt->execute([$ticket_id]);
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '42S22') {
                header('Location: ticket_detail.php?id=' . $ticket_id . '&msg=' . urlencode($migration_msg));
                exit;
            }
            throw $e;
        }
        $prev = $ticket['lock_type'] ?? '';
        $lock_labels = ['OBS' => 'Obsolete', 'ONH' => 'On Hold', 'RED' => 'Redirect'];
        $msg = 'Revoked ' . ($lock_labels[$prev] ?? $prev);
        try {
            $pdo->prepare("INSERT INTO ticket_history (ticket_id, change_type, old_value, new_value, created_at, created_by) VALUES (?, 'lock_revoke', ?, '', NOW(), ?)")
                ->execute([$ticket_id, $prev, get_effective_user() ?: 'System']);
        } catch (PDOException $e) {}
    } else {
        header('Location: ticket_detail.php?id=' . $ticket_id . '&msg=' . urlencode('Invalid action'));
        exit;
    }
} catch (PDOException $e) {
    // #region agent log
    $logPath = __DIR__ . '/.cursor/debug.log';
    $logLine = json_encode([
        'message' => 'ticket_lock PDOException',
        'data' => ['code' => $e->getCode(), 'message' => $e->getMessage(), 'action' => $action, 'ticket_id' => $ticket_id],
        'hypothesisId' => 'H1',
        'timestamp' => round(microtime(true) * 1000),
        'location' => 'ticket_lock.php:catch'
    ]) . "\n";
    @file_put_contents($logPath, $logLine, FILE_APPEND | LOCK_EX);
    // #endregion
    header('Location: ticket_detail.php?id=' . $ticket_id . '&msg=' . urlencode('Database error'));
    exit;
}

header('Location: ticket_detail.php?id=' . $ticket_id . '&msg=' . urlencode($msg));
exit;

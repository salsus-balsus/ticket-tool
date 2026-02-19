<?php
/**
 * ticket_action.php - Workflow Action Handler
 * POST only. Re-validates transitions server-side. Updates ticket and history.
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: tickets.php');
    exit;
}

$ticket_id = isset($_POST['ticket_id']) ? (int) $_POST['ticket_id'] : 0;
$next_status_id = isset($_POST['next_status_id']) ? (int) $_POST['next_status_id'] : 0;
$target_role_id = isset($_POST['target_role_id']) ? (int) $_POST['target_role_id'] : 0;

if ($ticket_id <= 0 || $next_status_id <= 0) {
    die('Invalid request');
}

$current_user_role_id = get_user_role(0);

try {
    // Fetch current ticket state
    $stmt = $pdo->prepare("SELECT id, status_id, type_id, title FROM tickets WHERE id = ?");
    $stmt->execute([$ticket_id]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ticket) {
        die('Ticket not found');
    }

    // Re-run get_allowed_transitions (security: server-side validation)
    $allowed = get_allowed_transitions(
        $ticket_id,
        (int) $ticket['status_id'],
        (int) $ticket['type_id'],
        $current_user_role_id
    );

    // Verify requested next_status_id is actually allowed
    $isAllowed = false;
    $targetOwnerRoleId = 0;
    foreach ($allowed as $tr) {
        $trNext = (int) ($tr['next_status_id'] ?? $tr['to_status_id'] ?? 0);
        if ($trNext === $next_status_id) {
            $isAllowed = true;
            $targetOwnerRoleId = (int) ($tr['target_owner_role_id'] ?? 0);
            break;
        }
    }

    if (!$isAllowed) {
        die('Unauthorized transition');
    }

    // Use target_role_id from form if provided, else from transition
    $new_role_id = $target_role_id > 0 ? $target_role_id : $targetOwnerRoleId;

    // Get status names for history
    $stmt = $pdo->prepare("SELECT name FROM ticket_statuses WHERE id IN (?, ?)");
    $stmt->execute([$ticket['status_id'], $next_status_id]);
    $names = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $names[] = $row['name'];
    }
    $fromName = $names[0] ?? 'Unknown';
    $toName = $names[1] ?? 'Unknown';

    // Update ticket
    $stmt = $pdo->prepare("UPDATE tickets SET status_id = ?, current_role_id = ? WHERE id = ?");
    $stmt->execute([$next_status_id, $new_role_id ?: null, $ticket_id]);

    // Insert history (ticket_history: ticket_id, change_type, old_value, new_value, created_at, created_by)
    $stmt = $pdo->prepare("INSERT INTO ticket_history (ticket_id, change_type, old_value, new_value, created_at, created_by) VALUES (?, 'status_change', ?, ?, NOW(), ?)");
    $stmt->execute([$ticket_id, $fromName, $toName, get_effective_user() ?: 'System']);

    // Notification Hook: record for target owner role (sets stage for email/browser notifications)
    if ($new_role_id > 0) {
        try {
            $roleName = '';
            $rStmt = $pdo->prepare("SELECT name FROM roles WHERE id = ?");
            $rStmt->execute([$new_role_id]);
            if ($row = $rStmt->fetch(PDO::FETCH_ASSOC)) {
                $roleName = $row['name'];
            }
            $msg = "Role [{$roleName}] (ID {$new_role_id}), you have a new task: Ticket #{$ticket_id}";
            $pdo->prepare("INSERT INTO notifications (role_id, ticket_id, message, created_at) VALUES (?, ?, ?, NOW())")->execute([$new_role_id, $ticket_id, $msg]);
        } catch (PDOException $e) {
            @file_put_contents(__DIR__ . '/data/notifications.log', date('Y-m-d H:i:s') . " | Role {$new_role_id} | Ticket #{$ticket_id} | New task\n", FILE_APPEND);
        }
    }

} catch (PDOException $e) {
    die('Database error: ' . $e->getMessage());
}

header('Location: ticket_detail.php?id=' . $ticket_id . '&msg=' . urlencode('Status updated'));
exit;

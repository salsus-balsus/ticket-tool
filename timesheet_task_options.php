<?php
/**
 * timesheet_task_options.php - JSON API for Task/Test Case modal.
 * GET: project_id, topic_id (contextual filter). Returns contextual + "my" tickets and test cases.
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

$userId = get_effective_user_id();
if ($userId === null) {
    echo json_encode(['contextualTickets' => [], 'contextualTestCases' => [], 'myTickets' => [], 'myTestCases' => []]);
    exit;
}

$projectId = isset($_GET['project_id']) ? (int) $_GET['project_id'] : 0;
$topicId = isset($_GET['topic_id']) ? (int) $_GET['topic_id'] : 0;

$contextualTickets = [];
$contextualTestCases = [];
$myTickets = [];
$myTestCases = [];

try {
    // Contextual: recent tickets (project/topic not on tickets table; use recent as fallback)
    $stmt = $pdo->query("
        SELECT t.id, COALESCE(t.ticket_no, t.id) AS ticket_no, t.title
        FROM tickets t
        ORDER BY t.id DESC
        LIMIT 50
    ");
    $contextualTickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Contextual test cases: recent
    $stmt = $pdo->query("
        SELECT tc.id, tc.title
        FROM test_cases tc
        ORDER BY tc.id DESC
        LIMIT 50
    ");
    $contextualTestCases = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // My tickets: where user is participant (REP/RES) or created_by if column exists
    $userRow = $pdo->prepare("SELECT username, first_name, last_name FROM app_users WHERE id = ?");
    $userRow->execute([$userId]);
    $userRow = $userRow->fetch(PDO::FETCH_ASSOC);
    $username = $userRow ? trim((string) ($userRow['username'] ?? '')) : '';
    $fullName = $userRow ? trim((string) (($userRow['first_name'] ?? '') . ' ' . ($userRow['last_name'] ?? ''))) : '';
    if ($username !== '' || $fullName !== '') {
        $hasCreatedBy = false;
        try {
            $chk = $pdo->query("SHOW COLUMNS FROM tickets LIKE 'created_by'");
            $hasCreatedBy = $chk && $chk->rowCount() > 0;
        } catch (PDOException $e) {}
        if ($hasCreatedBy && $username !== '') {
            $stmt = $pdo->prepare("
                SELECT DISTINCT t.id, COALESCE(t.ticket_no, t.id) AS ticket_no, t.title
                FROM tickets t
                LEFT JOIN ticket_participants tp ON tp.ticket_id = t.id
                WHERE t.created_by = ? OR tp.person_name = ? OR ( ? != '' AND tp.person_name = ? )
                ORDER BY t.id DESC
                LIMIT 50
            ");
            $stmt->execute([$username, $username, $fullName, $fullName]);
            $myTickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = $pdo->prepare("
                SELECT DISTINCT t.id, COALESCE(t.ticket_no, t.id) AS ticket_no, t.title
                FROM tickets t
                INNER JOIN ticket_participants tp ON tp.ticket_id = t.id
                WHERE tp.person_name = ? OR ( ? != '' AND tp.person_name = ? )
                ORDER BY t.id DESC
                LIMIT 50
            ");
            $stmt->execute([$username, $fullName, $fullName]);
            $myTickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    // My test cases: recent (no direct ownership in schema; use recent as placeholder)
    $stmt = $pdo->query("
        SELECT tc.id, tc.title
        FROM test_cases tc
        ORDER BY tc.id DESC
        LIMIT 30
    ");
    $myTestCases = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Return empty on error
}

echo json_encode([
    'contextualTickets' => $contextualTickets,
    'contextualTestCases' => $contextualTestCases,
    'myTickets' => $myTickets,
    'myTestCases' => $myTestCases,
]);

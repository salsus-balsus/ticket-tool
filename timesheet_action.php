<?php
/**
 * timesheet_action.php - JSON API for timesheet save/delete (used by Alpine.js fetch).
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

$effectiveUserId = get_effective_user_id();
if ($effectiveUserId === null) {
    echo json_encode(['success' => false, 'error' => 'Please select a user.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$input = $_POST;
if (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) $input = $decoded;
}

$action = trim($input['action'] ?? '');
if ($action === 'save_entry') {
    $entryId = (int) ($input['entry_id'] ?? 0);
    $entryDate = trim($input['entry_date'] ?? '');
    $startTime = trim($input['start_time'] ?? '');
    $endTime = trim($input['end_time'] ?? '');
    $projectId = (int) ($input['project_id'] ?? 0) ?: null;
    $topicId = (int) ($input['topic_id'] ?? 0) ?: null;
    $taskGroupId = (int) ($input['task_group_id'] ?? 0) ?: null;
    $description = trim($input['description'] ?? '') ?: null;
    $taskIdsInput = $input['task_ids'] ?? '';
    $taskIdsRaw = is_array($taskIdsInput) ? implode(',', array_map('intval', $taskIdsInput)) : trim((string) $taskIdsInput);

    if (!$entryDate || !$projectId || !$topicId) {
        echo json_encode(['success' => false, 'error' => 'Date, Project and Topic are required.']);
        exit;
    }
    $dateObj = date_parse_from_format('Y-m-d', $entryDate);
    if (!$dateObj || !checkdate($dateObj['month'], $dateObj['day'], $dateObj['year'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid date.']);
        exit;
    }
    $startTime = preg_match('/^\d{1,2}:\d{2}$/', $startTime) ? $startTime . ':00' : null;
    $endTime = preg_match('/^\d{1,2}:\d{2}$/', $endTime) ? $endTime . ':00' : null;
    $duration = 0;
    if ($startTime && $endTime) {
        $s = strtotime("1970-01-01 $startTime");
        $e = strtotime("1970-01-01 $endTime");
        if ($e > $s) $duration = round(($e - $s) / 3600, 2);
    }
    if ($duration <= 0) {
        echo json_encode(['success' => false, 'error' => 'Valid from/to time required for duration.']);
        exit;
    }

    try {
        if ($entryId > 0) {
            $stmt = $pdo->prepare("UPDATE time_entries SET entry_date=?, start_time=?, end_time=?, duration_hours=?, project_id=?, topic_id=?, task_group_id=?, description=? WHERE id=? AND user_id=?");
            $stmt->execute([$entryDate, $startTime, $endTime, $duration, $projectId, $topicId, $taskGroupId, $description, $entryId, $effectiveUserId]);
            $timeEntryId = $entryId;
            $replaceTickets = array_key_exists('task_ids', $input);
        } else {
            $stmt = $pdo->prepare("INSERT INTO time_entries (user_id, entry_date, start_time, end_time, duration_hours, project_id, topic_id, task_group_id, description) VALUES (?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$effectiveUserId, $entryDate, $startTime, $endTime, $duration, $projectId, $topicId, $taskGroupId, $description]);
            $timeEntryId = (int) $pdo->lastInsertId();
            $replaceTickets = true;
        }
        if ($replaceTickets) {
            $pdo->prepare("DELETE FROM time_entry_tickets WHERE time_entry_id=?")->execute([$timeEntryId]);
            $existingTickets = $pdo->query("SELECT id FROM tickets")->fetchAll(PDO::FETCH_COLUMN);
            $stmtTet = $pdo->prepare("INSERT IGNORE INTO time_entry_tickets (time_entry_id, ticket_id) VALUES (?,?)");
            foreach (array_filter(array_map('intval', preg_split('/[\s,]+/', $taskIdsRaw))) as $tid) {
                if (in_array($tid, $existingTickets)) $stmtTet->execute([$timeEntryId, $tid]);
            }
        }
        $startDisplay = $startTime ? substr($startTime, 0, 5) : '';
        $endDisplay = $endTime ? substr($endTime, 0, 5) : '';
        echo json_encode([
            'success' => true,
            'message' => $entryId > 0 ? 'Entry updated.' : 'Entry added.',
            'entry' => [
                'id' => $timeEntryId,
                'entry_date' => $entryDate,
                'start_time' => $startDisplay,
                'end_time' => $endDisplay,
                'duration_hours' => (float) $duration,
                'project_id' => $projectId,
                'topic_id' => $topicId,
                'task_group_id' => $taskGroupId,
                'description' => $description,
            ]
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'save_entry_tasks') {
    $entryId = (int) ($input['entry_id'] ?? 0);
    $taskIdsInput = $input['task_ids'] ?? '';
    $taskIdsRaw = is_array($taskIdsInput) ? implode(',', array_map('intval', $taskIdsInput)) : trim((string) $taskIdsInput);
    if ($entryId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid entry.']);
        exit;
    }
    try {
        $pdo->prepare("DELETE FROM time_entry_tickets WHERE time_entry_id=?")->execute([$entryId]);
        $existingTickets = $pdo->query("SELECT id FROM tickets")->fetchAll(PDO::FETCH_COLUMN);
        $stmtTet = $pdo->prepare("INSERT IGNORE INTO time_entry_tickets (time_entry_id, ticket_id) VALUES (?,?)");
        foreach (array_filter(array_map('intval', preg_split('/[\s,]+/', $taskIdsRaw))) as $tid) {
            if (in_array($tid, $existingTickets)) $stmtTet->execute([$entryId, $tid]);
        }
        echo json_encode(['success' => true, 'message' => 'Task links updated.']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'delete_entry') {
    $entryId = (int) ($input['entry_id'] ?? 0);
    if ($entryId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid entry.']);
        exit;
    }
    try {
        $pdo->prepare("DELETE FROM time_entries WHERE id=? AND user_id=?")->execute([$entryId, $effectiveUserId]);
        echo json_encode(['success' => true, 'message' => 'Entry deleted.']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action.']);

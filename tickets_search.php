<?php
/**
 * tickets_search.php - JSON API for generic ticket search (e.g. Redirect popup).
 * GET q=... & exclude_id=... (optional, exclude one ticket id from results).
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$exclude_id = isset($_GET['exclude_id']) ? (int) $_GET['exclude_id'] : 0;

$results = [];
if ($q === '') {
    echo json_encode(['tickets' => []]);
    exit;
}

try {
    $sql = "
        SELECT t.id, t.ticket_no, t.title, t.status_id, ts.name AS status_name
        FROM tickets t
        LEFT JOIN ticket_statuses ts ON t.status_id = ts.id
        WHERE (
            t.title LIKE ? OR t.goal LIKE ?
            OR CAST(t.id AS CHAR) LIKE ? OR CAST(t.ticket_no AS CHAR) LIKE ?
            OR EXISTS (SELECT 1 FROM ticket_participants tp WHERE tp.ticket_id = t.id AND tp.person_name LIKE ?)
        )
    ";
    $params = [];
    $like = '%' . $q . '%';
    $params = array_merge($params, [$like, $like, $like, $like, $like]);
    if ($exclude_id > 0) {
        $sql .= " AND t.id != ?";
        $params[] = $exclude_id;
    }
    $sql .= " ORDER BY t.id DESC LIMIT 30";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo json_encode(['error' => 'Database error', 'tickets' => []]);
    exit;
}

echo json_encode(['tickets' => $results]);

<?php
/**
 * import_timesheet.php - Upload and import timesheet CSVs.
 * CSV format (no name column): Date; from; to; hours; Project; Topic; Task Group; Description; Task ID (semicolon).
 * Matches export like data/timesheet output/Export_01_bis_02.csv.
 * Entries are imported for the currently logged-in user only.
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';

$msg = '';
$msgType = 'info';

$targetUserId = get_effective_user_id();
if ($targetUserId === null) {
    $msg = 'Please select a user (or log in) to use the timesheet import.';
    $msgType = 'danger';
}

// ts_projects: name -> id (ensure mapped names exist so import does not skip 9M Misc / 1C / 2C rows)
$projectRows = $pdo->query("SELECT id, name FROM ts_projects")->fetchAll(PDO::FETCH_ASSOC);
$projectIdByName = array_column($projectRows, 'id', 'name');
$requiredProjectNames = ['Misc', 'Classic', 'Cloud Ready'];
foreach ($requiredProjectNames as $pname) {
    if (!isset($projectIdByName[$pname])) {
        try {
            $pdo->prepare("INSERT INTO ts_projects (name) VALUES (?)")->execute([$pname]);
            $projectIdByName[$pname] = (int) $pdo->lastInsertId();
        } catch (PDOException $e) {
            // ignore duplicate or constraint errors
        }
    }
}

// CSV project label -> DB name (ts_projects.name)
$csvProjectToDbName = [
    '9M Misc'                    => 'Misc',
    '1C RDM - RDM Classic'       => 'Classic',
    '2C RDM C - RDM Cloud Ready' => 'Cloud Ready',
];

// ts_topics: code -> id (code extracted from cell e.g. "DV" from "DV Development")
$topicRows = $pdo->query("SELECT id, code FROM ts_topics WHERE code IS NOT NULL AND code != ''")->fetchAll(PDO::FETCH_ASSOC);
$topicIdByCode = [];
foreach ($topicRows as $r) {
    $topicIdByCode[strtoupper(trim($r['code']))] = (int) $r['id'];
}

// ts_task_groups: code -> id
$taskGroupRows = $pdo->query("SELECT id, code FROM ts_task_groups WHERE code IS NOT NULL AND code != ''")->fetchAll(PDO::FETCH_ASSOC);
$taskGroupIdByCode = [];
foreach ($taskGroupRows as $r) {
    $taskGroupIdByCode[strtoupper(trim($r['code']))] = (int) $r['id'];
}

// Codes that mean leave/absence -> skip row (managed via leaves.php)
$leaveTopicCodes = ['VA'];
$leaveTaskGroupCodes = ['VA'];

// Ticket number (CSV) -> tickets.id
$ticketIdByNo = [];
foreach ($pdo->query("SELECT id, COALESCE(ticket_no, id) AS ticket_no FROM tickets")->fetchAll(PDO::FETCH_ASSOC) as $tr) {
    $ticketIdByNo[(int) $tr['ticket_no']] = (int) $tr['id'];
}

// CSV columns (Export_01_bis_02.csv): 0=Date, 1=from, 2=to, 3=hours, 4=Project, 5=Topic, 6=Task Group, 7=Description, 8=Task ID
const CSV_DATE = 0;
const CSV_FROM = 1;
const CSV_TO = 2;
const CSV_HOURS = 3;
const CSV_PROJECT = 4;
const CSV_TOPIC = 5;
const CSV_TASK_GROUP = 6;
const CSV_DESCRIPTION = 7;
const CSV_TASK_ID = 8;

function extractCodeFromCell($cell) {
    $s = trim((string) $cell);
    $s = preg_replace('/\s+/', ' ', $s);
    if ($s === '') return null;
    if (preg_match('/^([A-Za-z0-9]{2})\b/', $s, $m)) return strtoupper($m[1]);
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file']) && $targetUserId !== null) {
    $file = $_FILES['csv_file'];

    if ($file['error'] === UPLOAD_ERR_OK) {
        $handle = fopen($file['tmp_name'], 'r');
        if ($handle === false) {
            $msg = 'Could not open file.';
            $msgType = 'danger';
        } else {
            $pdo->beginTransaction();
            try {
                $pdo->prepare("DELETE tet FROM time_entry_tickets tet INNER JOIN time_entries te ON te.id = tet.time_entry_id WHERE te.user_id = ?")->execute([$targetUserId]);
                $pdo->prepare("DELETE FROM time_entries WHERE user_id = ?")->execute([$targetUserId]);

                $stmtInsert = $pdo->prepare("
                    INSERT INTO time_entries
                    (user_id, entry_date, start_time, end_time, duration_hours, project_id, topic_id, task_group_id, description)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmtTicket = $pdo->prepare("INSERT IGNORE INTO time_entry_tickets (time_entry_id, ticket_id) VALUES (?, ?)");

                $delimiter = ';';
                $headerSkipped = false;
                $rowCount = 0;
                $skipped = 0;

                while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
                    if (!$headerSkipped) {
                        $headerSkipped = true;
                        continue;
                    }
                    if (count($data) < 9 || trim(implode('', array_map(function ($c) { return preg_replace('/\s+/', '', trim($c)); }, $data))) === '') {
                        continue;
                    }

                    $rawDate = trim(preg_replace('/\s+/', ' ', $data[CSV_DATE] ?? ''));
                    $date = null;
                    if ($rawDate !== '') {
                        $dt = DateTime::createFromFormat('d.m.Y', $rawDate);
                        if ($dt) {
                            $date = $dt->format('Y-m-d');
                        } else {
                            $parsed = strtotime(str_replace('/', '-', $rawDate));
                            if ($parsed) $date = date('Y-m-d', $parsed);
                        }
                    }
                    if (!$date) {
                        $skipped++;
                        continue;
                    }

                    $startRaw = preg_replace('/\s+/', '', trim($data[CSV_FROM] ?? ''));
                    $endRaw   = preg_replace('/\s+/', '', trim($data[CSV_TO] ?? ''));
                    $hoursRaw = trim($data[CSV_HOURS] ?? '');
                    $duration = (float) str_replace(',', '.', $hoursRaw);

                    if ($startRaw === '' || $endRaw === '' || $duration <= 0) {
                        $skipped++;
                        continue;
                    }

                    $startTime = preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $startRaw) ? $startRaw : null;
                    $endTime   = preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $endRaw)   ? $endRaw   : null;
                    if (!$startTime || !$endTime) {
                        $skipped++;
                        continue;
                    }
                    if (strlen($startTime) === 5) $startTime .= ':00';
                    if (strlen($endTime) === 5) $endTime .= ':00';

                    $projRaw = trim($data[CSV_PROJECT] ?? '');
                    $dbProjectName = $csvProjectToDbName[$projRaw] ?? null;
                    if ($dbProjectName === null) {
                        $skipped++;
                        continue;
                    }
                    $projectId = $projectIdByName[$dbProjectName] ?? null;
                    if ($projectId === null) {
                        $skipped++;
                        continue;
                    }

                    $topicCode = extractCodeFromCell($data[CSV_TOPIC] ?? '');
                    $taskGroupCode = extractCodeFromCell($data[CSV_TASK_GROUP] ?? '');
                    if ($topicCode !== null && in_array($topicCode, $leaveTopicCodes, true)) {
                        $skipped++;
                        continue;
                    }
                    if ($taskGroupCode !== null && in_array($taskGroupCode, $leaveTaskGroupCodes, true)) {
                        $skipped++;
                        continue;
                    }

                    $topicId = $taskGroupCode !== null ? ($topicIdByCode[$taskGroupCode] ?? null) : null;
                    $taskGroupId = $topicCode !== null ? ($taskGroupIdByCode[$topicCode] ?? null) : null;

                    $description = trim($data[CSV_DESCRIPTION] ?? '');

                    $rawTaskIds = trim($data[CSV_TASK_ID] ?? '');
                    $ticketIds = [];
                    foreach (array_map('trim', preg_split('/[\s,&]+/', $rawTaskIds, -1, PREG_SPLIT_NO_EMPTY)) as $part) {
                        if (is_numeric($part)) {
                            $ticketNo = (int) $part;
                            if (isset($ticketIdByNo[$ticketNo])) {
                                $ticketIds[] = $ticketIdByNo[$ticketNo];
                            } else {
                                $description .= ($description ? ' | ' : '') . 'Ref-Ticket: ' . $ticketNo . ' (not in DB)';
                            }
                        }
                    }

                    $stmtInsert->execute([
                        $targetUserId,
                        $date,
                        $startTime,
                        $endTime,
                        $duration,
                        $projectId,
                        $topicId,
                        $taskGroupId,
                        $description ?: null
                    ]);
                    $timeEntryId = (int) $pdo->lastInsertId();
                    foreach ($ticketIds as $tid) {
                        $stmtTicket->execute([$timeEntryId, $tid]);
                    }
                    $rowCount++;
                }

                $pdo->commit();
                fclose($handle);
                $userLabel = get_effective_user() ?: 'user';
                $msg = "Import successful: $rowCount entries for " . htmlspecialchars($userLabel) . ".";
                if ($skipped > 0) {
                    $msg .= " $skipped rows skipped (empty, leave, or invalid).";
                }
                $msgType = 'success';
            } catch (Exception $e) {
                $pdo->rollBack();
                fclose($handle);
                $msg = 'Import error: ' . htmlspecialchars($e->getMessage());
                $msgType = 'danger';
            }
        }
    } else {
        $msg = 'Please choose a valid CSV file.';
        $msgType = 'warning';
    }
}

$pageTitle = 'Timesheet Import';
require_once 'includes/header.php';
?>

<div class="page-wrapper">
    <div class="page-body">
        <div class="container-xl">

            <?php if ($msg): ?>
            <div class="alert alert-<?= $msgType ?> alert-dismissible">
                <?= $msg ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <div class="page-header d-print-none mb-4">
                <h1 class="page-title">Timesheet Import</h1>
                <div class="text-muted">CSV format (semicolon): Date; from; to; hours; Project; Topic; Task Group; Description; Task ID. No name column — entries are imported for the currently selected user.</div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Upload CSV</h3>
                </div>
                <div class="card-body">
                    <?php if ($targetUserId === null): ?>
                    <p class="text-danger mb-0">You must select a user to import timesheet entries.</p>
                    <?php else: ?>
                    <form method="post" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label class="form-label">Timesheet CSV (e.g. Export_01_bis_02.csv)</label>
                            <input type="file" name="csv_file" class="form-control" accept=".csv" required>
                            <small class="form-hint">UTF-8, semicolon-separated. Projects: 9M Misc / 1C RDM / 2C RDM C → Misc / Classic / Cloud Ready. Topic &amp; Task Group: code is extracted from the cell (e.g. DV, OR, AL). Leave rows (9O Off, VA Vacation) are skipped.</small>
                        </div>
                        <div class="form-footer">
                            <button type="submit" class="btn btn-primary">Start import</button>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

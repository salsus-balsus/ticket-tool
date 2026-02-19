<?php
/**
 * timesheet.php - Two-card timesheet: Overview (aggregates + target hours) and Excel-like entries table.
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/leave_colors.php';

$effectiveUserId = get_effective_user_id();
if ($effectiveUserId === null) {
    header('Location: index.php?msg=' . urlencode('Please select a user to use the timesheet.'));
    exit;
}

// --- Parameters ---
$year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
$month = isset($_GET['month']) ? (int) $_GET['month'] : (int) date('n');
if ($month < 1 || $month > 12) $month = (int) date('n');
if ($year < 2000 || $year > 2100) $year = (int) date('Y');
$overviewYear = $year;

$msg = '';
$msgType = '';

// --- Working days functions ---
function working_days_in_month($y, $m) {
    $n = 0;
    $days = cal_days_in_month(CAL_GREGORIAN, $m, $y);
    for ($d = 1; $d <= $days; $d++) {
        $w = (int) date('w', mktime(0, 0, 0, $m, $d, $y));
        if ($w >= 1 && $w <= 5) $n++;
    }
    return $n;
}

function working_days_in_month_considering_holidays($y, $m, array $holidayDates) {
    $n = 0;
    $days = cal_days_in_month(CAL_GREGORIAN, $m, $y);
    for ($d = 1; $d <= $days; $d++) {
        $dateStr = sprintf('%04d-%02d-%02d', $y, $m, $d);
        $w = (int) date('w', strtotime($dateStr));
        $isWeekday = ($w >= 1 && $w <= 5);
        $isHoliday = in_array($dateStr, $holidayDates, true);
        if ($isWeekday && !$isHoliday) $n++;
    }
    return $n;
}

// --- POST: save or delete entry ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');
    if ($action === 'save_entry') {
        $entryId = (int) ($_POST['entry_id'] ?? 0);
        $entryDate = trim($_POST['entry_date'] ?? '');
        $startTime = trim($_POST['start_time'] ?? '');
        $endTime = trim($_POST['end_time'] ?? '');
        $projectId = (int) ($_POST['project_id'] ?? 0) ?: null;
        $topicId = (int) ($_POST['topic_id'] ?? 0) ?: null;
        $taskGroupId = (int) ($_POST['task_group_id'] ?? 0) ?: null;
        $description = trim($_POST['description'] ?? '') ?: null;
        $taskIdsInput = $_POST['task_ids'] ?? '';
        $taskIdsRaw = is_array($taskIdsInput) ? implode(',', array_map('intval', $taskIdsInput)) : trim((string) $taskIdsInput);
        
        if (!$entryDate || !$projectId || !$topicId) {
            $msg = 'Date, Project and Topic are required.';
            $msgType = 'danger';
        } else {
            $dateObj = date_parse_from_format('Y-m-d', $entryDate);
            if (!$dateObj || !checkdate($dateObj['month'], $dateObj['day'], $dateObj['year'])) {
                $msg = 'Invalid date.';
                $msgType = 'danger';
            } else {
                $startTime = preg_match('/^\d{1,2}:\d{2}$/', $startTime) ? $startTime . ':00' : null;
                $endTime = preg_match('/^\d{1,2}:\d{2}$/', $endTime) ? $endTime . ':00' : null;
                $duration = 0;
                if ($startTime && $endTime) {
                    $s = strtotime("1970-01-01 $startTime");
                    $e = strtotime("1970-01-01 $endTime");
                    if ($e > $s) $duration = round(($e - $s) / 3600, 2);
                }
                if ($duration <= 0) {
                    $msg = 'Valid from/to time required for duration.';
                    $msgType = 'danger';
                } else {
                    try {
                        if ($entryId > 0) {
                            $stmt = $pdo->prepare("UPDATE time_entries SET entry_date=?, start_time=?, end_time=?, duration_hours=?, project_id=?, topic_id=?, task_group_id=?, description=? WHERE id=? AND user_id=?");
                            $stmt->execute([$entryDate, $startTime, $endTime, $duration, $projectId, $topicId, $taskGroupId, $description, $entryId, $effectiveUserId]);
                            $timeEntryId = $entryId;
                            $replaceTickets = isset($_POST['task_ids']);
                        } else {
                            $stmt = $pdo->prepare("INSERT INTO time_entries (user_id, entry_date, start_time, end_time, duration_hours, project_id, topic_id, task_group_id, description) VALUES (?,?,?,?,?,?,?,?,?)");
                            $stmt->execute([$effectiveUserId, $entryDate, $startTime, $endTime, $duration, $projectId, $topicId, $taskGroupId, $description]);
                            $timeEntryId = (int) $pdo->lastInsertId();
                            $replaceTickets = true;
                        }
                        if (!empty($replaceTickets)) {
                            if ($entryId > 0) $pdo->prepare("DELETE FROM time_entry_tickets WHERE time_entry_id=?")->execute([$timeEntryId]);
                            $existingTickets = $pdo->query("SELECT id FROM tickets")->fetchAll(PDO::FETCH_COLUMN);
                            $stmtTet = $pdo->prepare("INSERT IGNORE INTO time_entry_tickets (time_entry_id, ticket_id) VALUES (?,?)");
                            foreach (array_filter(array_map('intval', preg_split('/[\s,]+/', $taskIdsRaw))) as $tid) {
                                if (in_array($tid, $existingTickets)) $stmtTet->execute([$timeEntryId, $tid]);
                            }
                        }
                        echo json_encode(['success' => true, 'message' => $entryId > 0 ? 'Entry updated.' : 'Entry added.', 'entry' => ['id' => $timeEntryId, 'duration_hours' => $duration, 'start_time' => $startTime ? substr($startTime,0,5) : '', 'end_time' => $endTime ? substr($endTime,0,5) : '']]);
                        exit;
                    } catch (PDOException $e) {
                        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                        exit;
                    }
                }
            }
        }
        if($msgType === 'danger') {
            echo json_encode(['success' => false, 'error' => $msg]);
            exit;
        }
    } elseif ($action === 'delete_entry') {
        $entryId = (int) ($_POST['entry_id'] ?? 0);
        if ($entryId > 0) {
            try {
                $pdo->prepare("DELETE FROM time_entries WHERE id=? AND user_id=?")->execute([$entryId, $effectiveUserId]);
                echo json_encode(['success' => true, 'message' => 'Entry deleted.']);
                exit;
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                exit;
            }
        }
    }
}

$firstDay = sprintf('%04d-%02d-01', $year, $month);
$lastDay = date('Y-m-t', strtotime($firstDay));

// --- Public holidays ---
$basedStateId = '';
try {
    $stmt = $pdo->prepare("SELECT based_state_id FROM app_users WHERE id = ?");
    $stmt->execute([$effectiveUserId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['based_state_id'])) $basedStateId = $row['based_state_id'];
} catch (PDOException $e) {}
$holidayDates = [];
$holidayDatesByName = [];
if ($basedStateId !== '') {
    try {
        $stmt = $pdo->prepare("SELECT date, name FROM holidays WHERE state_id = ? AND date >= ? AND date <= ?");
        $stmt->execute([$basedStateId, $overviewYear . '-01-01', $overviewYear . '-12-31']);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $holidayDates[] = $row['date'];
            $holidayDatesByName[$row['date']] = $row['name'] ?? '';
        }
    } catch (PDOException $e) {}
}

// --- User employment ---
$employment = null;
try {
    $stmt = $pdo->prepare("
        SELECT hours_per_day, days_per_week
        FROM user_employment_data
        WHERE user_id = ? AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?)
        ORDER BY effective_from DESC LIMIT 1
    ");
    $stmt->execute([$effectiveUserId, $lastDay, $firstDay]);
    $employment = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// --- Leaves Data ---
$vacationDays = 0;
$vacationHours = 0;
$leaveDates = [];
try {
    $stmt = $pdo->prepare("
        SELECT * FROM user_leaves
        WHERE user_id = ? AND end_date >= ? AND start_date <= ?
    ");
    $stmt->execute([$effectiveUserId, date('Y-m-d', strtotime($firstDay . ' -1 month')), date('Y-m-d', strtotime($lastDay . ' +1 month'))]);
    $leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $hoursPerDay = $employment['hours_per_day'] ?? 8;
    
    foreach ($leaves as $l) {
        $s = max($l['start_date'], $firstDay);
        $e = min($l['end_date'], $lastDay);
        for ($d = strtotime($s); $d <= strtotime($e); $d += 86400) {
            $w = (int) date('w', $d);
            if ($w >= 1 && $w <= 5) { $vacationDays++; $vacationHours += $hoursPerDay; }
        }
        $label = trim((string) ($l['comment'] ?? '')) ?: 'Leave';
        $type = get_leave_type($l['is_sickness'] ?? 0);
        for ($d = strtotime($l['start_date']); $d <= strtotime($l['end_date']); $d += 86400) {
            $leaveDates[date('Y-m-d', $d)] = [
                'id' => $l['id'] ?? 0,
                'name' => $label,
                'type' => $type
            ];
        }
    }
} catch (PDOException $e) {}

// --- Master Data ---
$projects = $pdo->query("SELECT id, name FROM ts_projects ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$topics = $pdo->query("SELECT id, code, name FROM ts_topics ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);
$taskGroups = $pdo->query("SELECT id, code, name FROM ts_task_groups ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);

// --- MONTHLY AGGREGATES (For the new Overview) ---
$weeksInMonth = [];
for ($d = 1; $d <= cal_days_in_month(CAL_GREGORIAN, $month, $year); $d++) {
    $ts = mktime(0, 0, 0, $month, $d, $year);
    $w = date('W', $ts);
    $yIso = date('o', $ts);
    $weekKey = $yIso . '-W' . $w;
    if (!isset($weeksInMonth[$weekKey])) {
        $weeksInMonth[$weekKey] = $w;
    }
}

$monthlyByProjectByWeek = [];
foreach ($projects as $p) {
    $monthlyByProjectByWeek[$p['id']] = array_fill_keys(array_keys($weeksInMonth), 0.0);
}
$monthlyByProjectByWeek[0] = array_fill_keys(array_keys($weeksInMonth), 0.0);

$monthlyByTaskGroup = [];
$monthlyLeavesList = [];
$monthlyActualHours = 0.0;

try {
    // Projects per Week
    foreach ($weeksInMonth as $weekKey => $weekNum) {
        // Find start and end of this week
        $dto = new DateTime();
        $dto->setISODate((int)substr($weekKey, 0, 4), (int)$weekNum);
        $weekStart = $dto->format('Y-m-d');
        $dto->modify('+6 days');
        $weekEnd = $dto->format('Y-m-d');
        
        // Constrain week to current month
        $start = max($weekStart, $firstDay);
        $end = min($weekEnd, $lastDay);
        
        if ($start <= $end) {
            foreach ($projects as $p) {
                $stmt = $pdo->prepare("SELECT COALESCE(SUM(duration_hours), 0) FROM time_entries WHERE user_id = ? AND entry_date >= ? AND entry_date <= ? AND project_id = ?");
                $stmt->execute([$effectiveUserId, $start, $end, $p['id']]);
                $monthlyByProjectByWeek[$p['id']][$weekKey] += (float) $stmt->fetchColumn();
            }
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(duration_hours), 0) FROM time_entries WHERE user_id = ? AND entry_date >= ? AND entry_date <= ? AND project_id IS NULL");
            $stmt->execute([$effectiveUserId, $start, $end]);
            $monthlyByProjectByWeek[0][$weekKey] += (float) $stmt->fetchColumn();
        }
    }
    
    // Total Actual Hours
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(duration_hours), 0) FROM time_entries WHERE user_id = ? AND entry_date >= ? AND entry_date <= ?");
    $stmt->execute([$effectiveUserId, $firstDay, $lastDay]);
    $monthlyActualHours = (float) $stmt->fetchColumn();
    
    // Task Groups Monthly
    $stmt = $pdo->prepare("SELECT task_group_id, COALESCE(SUM(duration_hours), 0) AS total FROM time_entries WHERE user_id = ? AND entry_date >= ? AND entry_date <= ? GROUP BY task_group_id");
    $stmt->execute([$effectiveUserId, $firstDay, $lastDay]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $tid = $row['task_group_id'] !== null ? (int) $row['task_group_id'] : 0;
        $monthlyByTaskGroup[$tid] = (float) $row['total'];
    }
    
    // Leaves Monthly
    $stmt = $pdo->prepare("SELECT * FROM user_leaves WHERE user_id = ? AND end_date >= ? AND start_date <= ? ORDER BY start_date");
    $stmt->execute([$effectiveUserId, $firstDay, $lastDay]);
    $leavesMonth = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($leavesMonth as $l) {
        $start = max($l['start_date'], $firstDay);
        $end = min($l['end_date'], $lastDay);
        $effDays = 0;
        for ($d = strtotime($start); $d <= strtotime($end); $d += 86400) {
            $dateStr = date('Y-m-d', $d);
            $w = (int) date('w', $d);
            if ($w >= 1 && $w <= 5 && !in_array($dateStr, $holidayDates, true)) $effDays++;
        }
        $monthlyLeavesList[] = [
            'start_date' => $start,
            'end_date'   => $end,
            'comment'    => trim((string) ($l['comment'] ?? '')) ?: 'Leave',
            'is_sickness' => !empty($l['is_sickness']),
            'type'       => get_leave_type($l['is_sickness'] ?? 0),
            'effective_days' => $effDays,
        ];
    }
} catch (PDOException $e) {}

// Monthly Quotas
$wd = working_days_in_month_considering_holidays($year, $month, $holidayDates);
$monthlyToBeHours = $employment ? (float) $employment['hours_per_day'] * $wd : 0;
$monthlyToBeDays = $employment ? $wd : 0;
$monthlyDiffHours = $monthlyActualHours - $monthlyToBeHours;
$monthlyProgressPercent = $monthlyToBeHours > 0 ? min(100, round(($monthlyActualHours / $monthlyToBeHours) * 100)) : 0;


// --- YEARLY AGGREGATES (For the Modal) ---
$overviewFirst = $overviewYear . '-01-01';
$overviewLast = $overviewYear . '-12-31';
$overviewByProjectByMonth = []; 
$overviewHoursByMonth = [];     
$overviewByTaskGroup = [];      
$overviewLeavesList = [];       
$overviewQuotasPerMonth = [];   

foreach ($projects as $p) { $overviewByProjectByMonth[$p['id']] = array_fill(1, 12, 0.0); }
$overviewByProjectByMonth[0] = array_fill(1, 12, 0.0);
$overviewHoursByMonth = array_fill(1, 12, 0.0);

try {
    for ($m = 1; $m <= 12; $m++) {
        $mFirst = sprintf('%04d-%02d-01', $overviewYear, $m);
        $mLast = date('Y-m-t', strtotime($mFirst));
        foreach ($projects as $p) {
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(duration_hours), 0) AS total FROM time_entries WHERE user_id = ? AND entry_date >= ? AND entry_date <= ? AND project_id = ?");
            $stmt->execute([$effectiveUserId, $mFirst, $mLast, $p['id']]);
            $total = (float) $stmt->fetchColumn();
            $overviewByProjectByMonth[$p['id']][$m] = $total;
            $overviewHoursByMonth[$m] += $total;
        }
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(duration_hours), 0) AS total FROM time_entries WHERE user_id = ? AND entry_date >= ? AND entry_date <= ? AND project_id IS NULL");
        $stmt->execute([$effectiveUserId, $mFirst, $mLast]);
        $miscTotal = (float) $stmt->fetchColumn();
        $overviewByProjectByMonth[0][$m] = $miscTotal;
        $overviewHoursByMonth[$m] += $miscTotal;
        
        $wdYear = working_days_in_month_considering_holidays($overviewYear, $m, $holidayDates);
        $emp = null;
        $stmt = $pdo->prepare("SELECT hours_per_day FROM user_employment_data WHERE user_id = ? AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?) ORDER BY effective_from DESC LIMIT 1");
        $stmt->execute([$effectiveUserId, $mLast, $mFirst]);
        $emp = $stmt->fetch(PDO::FETCH_ASSOC);
        $tbHours = $emp ? (float) $emp['hours_per_day'] * $wdYear : null;
        $tbDays = $emp ? $wdYear : null;
        $overviewQuotasPerMonth[$m] = [
            'toBeHours' => $tbHours,
            'toBeDays'  => $tbDays,
            'actualHours' => $overviewHoursByMonth[$m],
            'actualDays'  => $tbHours > 0 ? round($overviewHoursByMonth[$m] / ((float) $emp['hours_per_day']), 1) : round($overviewHoursByMonth[$m] / 8, 1),
        ];
    }
    
    $stmt = $pdo->prepare("SELECT task_group_id, COALESCE(SUM(duration_hours), 0) AS total FROM time_entries WHERE user_id = ? AND entry_date >= ? AND entry_date <= ? GROUP BY task_group_id");
    $stmt->execute([$effectiveUserId, $overviewFirst, $overviewLast]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $tid = $row['task_group_id'] !== null ? (int) $row['task_group_id'] : 0;
        $overviewByTaskGroup[$tid] = (float) $row['total'];
    }
    
    $stmt = $pdo->prepare("SELECT * FROM user_leaves WHERE user_id = ? AND end_date >= ? AND start_date <= ? ORDER BY start_date");
    $stmt->execute([$effectiveUserId, $overviewFirst, $overviewLast]);
    $leavesYear = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($leavesYear as $l) {
        $start = max($l['start_date'], $overviewFirst);
        $end = min($l['end_date'], $overviewLast);
        $effDays = 0;
        for ($d = strtotime($start); $d <= strtotime($end); $d += 86400) {
            $dateStr = date('Y-m-d', $d);
            $w = (int) date('w', $d);
            if ($w >= 1 && $w <= 5 && !in_array($dateStr, $holidayDates, true)) $effDays++;
        }
        $overviewLeavesList[] = [
            'start_date' => $l['start_date'],
            'end_date'   => $l['end_date'],
            'comment'    => trim((string) ($l['comment'] ?? '')) ?: 'Leave',
            'is_sickness' => !empty($l['is_sickness']),
            'type'       => get_leave_type($l['is_sickness'] ?? 0),
            'effective_days' => $effDays,
        ];
    }
} catch (PDOException $e) {}


// --- Entries list ---
$entries = [];
try {
    $sql = "SELECT te.id, te.entry_date, te.start_time, te.end_time, te.duration_hours, te.project_id, te.topic_id, te.task_group_id, te.description
            FROM time_entries te WHERE te.user_id = ? AND te.entry_date >= ? AND te.entry_date <= ? ORDER BY te.entry_date ASC, te.start_time ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$effectiveUserId, $firstDay, $lastDay]);
    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($entries as &$e) {
        $st = $pdo->prepare("SELECT ticket_id FROM time_entry_tickets WHERE time_entry_id = ? ORDER BY ticket_id");
        $st->execute([$e['id']]);
        $e['ticket_ids'] = $st->fetchAll(PDO::FETCH_COLUMN);
    }
    unset($e);
} catch (PDOException $e) {}

$groupedByDate = [];
foreach ($entries as $row) {
    $d = $row['entry_date'];
    if (!isset($groupedByDate[$d])) $groupedByDate[$d] = [];
    $groupedByDate[$d][] = $row;
}

// --- JSON for Alpine.js ---
$daysArray = [];
$currentDate = $firstDay;
while (strtotime($currentDate) <= strtotime($lastDay)) {
    $timestamp = strtotime($currentDate);
    $isWeekend = (date('N', $timestamp) >= 6);

    $entriesJs = [];
    if (isset($groupedByDate[$currentDate])) {
        foreach ($groupedByDate[$currentDate] as $row) {
            $entriesJs[] = [
                'id' => (int) $row['id'],
                'entryDate' => $row['entry_date'],
                'startTime' => $row['start_time'] ? substr($row['start_time'], 0, 5) : '',
                'endTime' => $row['end_time'] ? substr($row['end_time'], 0, 5) : '',
                'durationHours' => (float) ($row['duration_hours'] ?? 0),
                'projectId' => isset($row['project_id']) ? (int) $row['project_id'] : '',
                'topicId' => (isset($row['topic_id']) && $row['topic_id'] !== null && $row['topic_id'] !== '') ? (int) $row['topic_id'] : '',
                'taskGroupId' => (isset($row['task_group_id']) && $row['task_group_id'] !== null && $row['task_group_id'] !== '') ? (int) $row['task_group_id'] : '',
                'description' => (string) ($row['description'] ?? ''),
                'ticketIds' => array_map('intval', $row['ticket_ids'] ?? []),
            ];
        }
    }

    $isLeave = isset($leaveDates[$currentDate]);
    $daysArray[] = [
        'date' => $currentDate,
        'dateText' => date('d.m.Y', $timestamp),
        'weekdayText' => date('l', $timestamp),
        'kw' => date('W', $timestamp), // Added Calendar Week here
        'isWeekend' => $isWeekend,
        'isLeave' => $isLeave,
        'leaveId' => $isLeave ? $leaveDates[$currentDate]['id'] : null,
        'leaveName' => $isLeave ? $leaveDates[$currentDate]['name'] : '',
        'leaveType' => $isLeave ? $leaveDates[$currentDate]['type'] : '',
        'isPublicHoliday' => in_array($currentDate, $holidayDates, true),
        'holidayName' => $holidayDatesByName[$currentDate] ?? '',
        'entries' => $entriesJs,
    ];
    $currentDate = date('Y-m-d', strtotime($currentDate . ' +1 day'));
}

$gridConfig = [
    'days' => $daysArray,
    'projects' => $projects,
    'topics' => $topics,
    'taskGroups' => $taskGroups,
    'leaveTypeColors' => $LEAVE_TYPE_COLORS,
    'isAdmin' => true, 
    'entriesCountLabel' => '(total)',
    'actionUrl' => 'timesheet.php',
    'taskOptionsUrl' => 'timesheet_task_options.php',
];

$pageTitle = 'Timesheet';
$pageHeadExtra = (isset($pageHeadExtra) ? $pageHeadExtra : '') . leave_colors_styles();
$pageHeadExtra .= '<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3/dist/cdn.min.js"></script>';
$pageHeadExtra .= '<script src="https://cdn.jsdelivr.net/npm/toastify-js@1.12.0"></script>';
$pageHeadExtra .= '<script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>';
$pageHeadExtra .= '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js@1.12.0/src/toastify.min.css"/>';

// Helper for Task Group colors (just for the progress bar visual)
$tgColors = ['bg-primary', 'bg-purple', 'bg-green', 'bg-orange', 'bg-pink', 'bg-yellow', 'bg-cyan', 'bg-teal'];

require_once 'includes/header.php';
?>
<script>
window.__TIMESHEET_GRID__ = <?= json_encode($gridConfig) ?>;
</script>
<div class="page-wrapper">
    <div class="page-body">
        <div class="container-xl">

            <div class="page-header d-print-none mb-4">
                <div class="row align-items-center">
                    <div class="col">
                        <h1 class="page-title">Timesheet</h1>
                        <div class="text-muted">Monthly Overview and time entries</div>
                    </div>
                    <div class="col-auto ms-auto d-print-none">
                        <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#yearlyModal">
                            <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-calendar-stats" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M11.795 21h-6.795a2 2 0 0 1 -2 -2v-12a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v4" /><path d="M18 14v4h4" /><path d="M18 18m-4 0a4 4 0 1 0 8 0a4 4 0 1 0 -8 0" /><path d="M15 3v4" /><path d="M7 3v4" /><path d="M3 11h16" /></svg>
                            Yearly Overview
                        </button>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">
                    <h3 class="card-title">Overview: <?= date('F Y', mktime(0,0,0,$month,1,$year)) ?></h3>
                    <div class="card-actions">
                        </div>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        
                        <div class="col-md-6">
                            <div class="card card-sm h-100">
                                <div class="card-header">
                                    <h4 class="card-title">Quotas</h4>
                                </div>
                                <div class="card-body d-flex flex-column justify-content-center">
                                    <div class="row text-center mb-3">
                                        <div class="col">
                                            <div class="text-muted small text-uppercase fw-bold">To be</div>
                                            <div class="h2 mb-0"><?= number_format($monthlyToBeHours, 1) ?> h</div>
                                        </div>
                                        <div class="col">
                                            <div class="text-muted small text-uppercase fw-bold">Actual</div>
                                            <div class="h2 mb-0"><?= number_format($monthlyActualHours, 1) ?> h</div>
                                        </div>
                                        <div class="col">
                                            <div class="text-muted small text-uppercase fw-bold">+/-</div>
                                            <div class="h2 mb-0 <?= $monthlyDiffHours < 0 ? 'text-danger' : 'text-success' ?>">
                                                <?= $monthlyDiffHours > 0 ? '+' : '' ?><?= number_format($monthlyDiffHours, 1) ?> h
                                            </div>
                                        </div>
                                    </div>
                                    <div class="progress" style="height: 10px;">
                                        <div class="progress-bar <?= $monthlyProgressPercent >= 100 ? 'bg-success' : 'bg-primary' ?>" 
                                             style="width: <?= $monthlyProgressPercent ?>%" 
                                             role="progressbar" 
                                             aria-valuenow="<?= $monthlyProgressPercent ?>" aria-valuemin="0" aria-valuemax="100">
                                             <span class="visually-hidden"><?= $monthlyProgressPercent ?>% Complete</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="card card-sm h-100">
                                <div class="card-header">
                                    <h4 class="card-title">Projects</h4>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-vcenter card-table table-sm table-striped mb-0">
                                        <thead>
                                            <tr>
                                                <th>Project</th>
                                                <?php foreach ($weeksInMonth as $kw): ?>
                                                <th class="text-end text-muted small">KW <?= $kw ?></th>
                                                <?php endforeach; ?>
                                                <th class="text-end">Sum</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($projects as $p):
                                                $row = $monthlyByProjectByWeek[$p['id']] ?? [];
                                                $sum = array_sum($row);
                                                if ($sum <= 0) continue; // Only show active projects this month
                                            ?>
                                            <tr>
                                                <td><?= e($p['name']) ?></td>
                                                <?php foreach ($weeksInMonth as $weekKey => $kw): ?>
                                                <td class="text-end"><?= $row[$weekKey] > 0 ? number_format($row[$weekKey], 1) : '<span class="text-muted opacity-25">-</span>' ?></td>
                                                <?php endforeach; ?>
                                                <td class="text-end fw-bold"><?= number_format($sum, 1) ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                            
                                            <?php
                                            $row0 = $monthlyByProjectByWeek[0] ?? [];
                                            $sum0 = array_sum($row0);
                                            if ($sum0 > 0): ?>
                                            <tr>
                                                <td>Miscellaneous</td>
                                                <?php foreach ($weeksInMonth as $weekKey => $kw): ?>
                                                <td class="text-end"><?= $row0[$weekKey] > 0 ? number_format($row0[$weekKey], 1) : '<span class="text-muted opacity-25">-</span>' ?></td>
                                                <?php endforeach; ?>
                                                <td class="text-end fw-bold"><?= number_format($sum0, 1) ?></td>
                                            </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="card card-sm h-100">
                                <div class="card-header">
                                    <h4 class="card-title">Task Groups</h4>
                                </div>
                                <div class="card-body">
                                    <?php
                                    $taskGroupNames = [];
                                    foreach ($taskGroups as $tg) { $taskGroupNames[$tg['id']] = $tg['name'] ?: $tg['code']; }
                                    $taskGroupNames[0] = '— none —';
                                    ksort($monthlyByTaskGroup);
                                    $totalTgHours = array_sum($monthlyByTaskGroup);
                                    
                                    if ($totalTgHours > 0): ?>
                                        <div class="progress mb-3" style="height: 12px;">
                                            <?php 
                                            $colorIdx = 0;
                                            foreach ($monthlyByTaskGroup as $tgId => $total):
                                                if ($total <= 0) continue;
                                                $pct = ($total / $totalTgHours) * 100;
                                                $color = $tgColors[$colorIdx % count($tgColors)];
                                            ?>
                                                <div class="progress-bar <?= $color ?>" style="width: <?= $pct ?>%" title="<?= e($taskGroupNames[$tgId] ?? '') ?>: <?= number_format($total, 1) ?>h"></div>
                                            <?php $colorIdx++; endforeach; ?>
                                        </div>
                                        
                                        <div class="row row-cols-2 g-2">
                                            <?php 
                                            $colorIdx = 0;
                                            foreach ($monthlyByTaskGroup as $tgId => $total):
                                                if ($total <= 0) continue;
                                                $name = $taskGroupNames[$tgId] ?? ('ID ' . $tgId);
                                                $color = str_replace('bg-', 'text-', $tgColors[$colorIdx % count($tgColors)]);
                                            ?>
                                            <div class="col d-flex align-items-center small">
                                                <span class="p-1 me-2 rounded-circle <?= $tgColors[$colorIdx % count($tgColors)] ?>"></span>
                                                <span class="text-truncate" style="max-width: 200px;" title="<?= e($name) ?>"><?= e($name) ?></span>
                                                <span class="ms-auto fw-bold"><?= number_format($total, 1) ?>h</span>
                                            </div>
                                            <?php $colorIdx++; endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <p class="text-muted small mb-0">No task group hours recorded this month.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="card card-sm h-100">
                                <div class="card-header">
                                    <h4 class="card-title">Leaves &amp; Sickness</h4>
                                </div>
                                <div class="card-body py-3">
                                    <ul class="list list-timeline list-timeline-simple mb-0">
                                        <?php foreach ($monthlyLeavesList as $l): 
                                            $bgClass = get_leave_type($l['is_sickness'] ?? 0) === 'Sickness' ? 'bg-red' : 'bg-blue';
                                        ?>
                                        <li>
                                            <div class="list-timeline-icon <?= $bgClass ?>"></div>
                                            <div class="list-timeline-content">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <div class="fw-bold">
                                                        <?= date('d.m.', strtotime($l['start_date'])) ?> 
                                                        <?= $l['start_date'] !== $l['end_date'] ? '– ' . date('d.m.', strtotime($l['end_date'])) : '' ?>
                                                    </div>
                                                    <span class="badge bg-secondary-lt text-secondary"><?= (int)$l['effective_days'] ?> d</span>
                                                </div>
                                                <div class="text-muted small mt-1"><?= e($l['comment']) ?></div>
                                            </div>
                                        </li>
                                        <?php endforeach; ?>
                                    </ul>
                                    <?php if (empty($monthlyLeavesList)): ?>
                                    <p class="text-muted small mb-0">No leaves or sickness in this month.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card" x-data="timesheetGrid(window.__TIMESHEET_GRID__)">
                <div class="card-header d-flex flex-wrap align-items-center gap-2">
                    <h3 class="card-title mb-0">Time entries</h3>
                    <div class="d-flex align-items-center gap-1 ms-auto">
                        <?php
                        $prevMonth = $month - 1;
                        $prevYear = $year;
                        if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }
                        $nextMonth = $month + 1;
                        $nextYear = $year;
                        if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }
                        ?>
                        <a href="timesheet.php?year=<?= $prevYear ?>&month=<?= $prevMonth ?>" class="btn btn-sm btn-icon btn-outline-secondary" title="Previous month" aria-label="Previous month">
                            <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path d="M15 6l-6 6l6 6"/></svg>
                        </a>
                        <form method="get" action="timesheet.php" class="d-flex gap-1 align-items-center">
                            <select name="month" class="form-select form-select-sm" style="width:auto;" onchange="this.form.submit()">
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?= $m ?>" <?= $m === (int)$month ? 'selected' : '' ?>><?= date('F', mktime(0, 0, 0, $m, 1)) ?></option>
                                <?php endfor; ?>
                            </select>
                            <select name="year" class="form-select form-select-sm" style="width:auto;" onchange="this.form.submit()">
                                <?php for ($y = $year - 2; $y <= $year + 2; $y++): ?>
                                <option value="<?= $y ?>" <?= $y === (int)$year ? 'selected' : '' ?>><?= $y ?></option>
                                <?php endfor; ?>
                            </select>
                        </form>
                        <a href="timesheet.php?year=<?= $nextYear ?>&month=<?= $nextMonth ?>" class="btn btn-sm btn-icon btn-outline-secondary" title="Next month" aria-label="Next month">
                            <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path d="M9 6l6 6l-6 6"/></svg>
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <style>
                        .entry-row { transition: opacity 0.2s ease-out, transform 0.2s ease-out; }
                        .entry-row.opacity-0 { opacity: 0; transform: translateY(-6px); }
                        .entry-row.opacity-100 { opacity: 1; transform: translateY(0); }
                        .drag-handle { cursor: grab; user-select: none; color: var(--tblr-muted); }
                        .drag-handle:active { cursor: grabbing; }
                    </style>
                    <div class="timesheet-grid">
                        <template x-for="block in groupedGrid" :key="block.id">
                            <div>
                                <template x-if="block.type === 'day' || expandedGroups[block.id]">
                                    <template x-for="day in (block.type === 'day' ? [block.day] : block.days)" :key="day.date">
                                        <div class="mb-3 border rounded shadow-sm" :class="{'bg-light': (day.isWeekend || day.isLeave) && !day.isPublicHoliday, 'timesheet-day-public-holiday': day.isPublicHoliday}" :data-day-date="day.date">
                                            <div class="px-3 py-2 border-bottom timesheet-day-header" :class="{'bg-secondary-lt': day.isWeekend || day.isLeave, 'bg-white': !day.isWeekend && !day.isLeave && !day.isPublicHoliday}">
                                                <strong class="small d-flex flex-wrap align-items-center gap-2 gap-md-4" :class="{'text-muted': (day.isWeekend || day.isLeave) && !day.isPublicHoliday, 'text-dark': !day.isWeekend && !day.isLeave && !day.isPublicHoliday}">
                                                    <span style="width: 80px;" x-text="day.weekdayText"></span>
                                                    <span style="width: 80px;" x-text="day.dateText"></span>
                                                    <span class="text-muted" x-text="'KW ' + day.kw"></span>
                                                    <template x-if="day.isPublicHoliday && day.holidayName">
                                                        <span class="ms-2 fw-bold" x-text="day.holidayName"></span>
                                                    </template>
                                                </strong>
                                            </div>
                                            <div class="px-3 py-1">
                                                <div class="d-flex flex-wrap align-items-center gap-2 py-2 border-bottom text-muted small">
                                                    <span class="order-first" style="width: 2rem;"></span>
                                                    <span class="order-first" style="width: 1.5rem;"></span>
                                                    <span style="width: 4.5rem;">From</span>
                                                    <span style="width: 4.5rem;">To</span>
                                                    <span style="width: 2.5rem;"></span>
                                                    <span style="width: 160px;">Project</span>
                                                    <span style="width: 150px;">Task Group</span>
                                                    <span style="width: 150px;">Topic</span>
                                                    <span style="min-width: 200px; max-width: 350px; flex-grow: 1;">Description</span>
                                                    <div class="ms-auto" style="width: 5rem;"></div>
                                                </div>
                                                <div class="entries-list" x-init="initSortable($el, day)">
                                                    <template x-for="(entry, entryIdx) in day.entries" :key="entry.id || 'new-' + entryIdx">
                                                        <div x-transition:enter="transition ease-out duration-200"
                                                             x-transition:enter-start="opacity-0"
                                                             x-transition:enter-end="opacity-100"
                                                             class="d-flex flex-wrap align-items-center gap-2 py-3 border-bottom entry-row">
                                                            <button type="button" class="btn btn-sm px-2 order-first" :class="(entry.deleteConfirmAt && (Date.now() - entry.deleteConfirmAt) < 3000) ? 'btn-danger' : 'btn-ghost-danger'"
                                                                :title="(entry.deleteConfirmAt && (Date.now() - entry.deleteConfirmAt) < 3000) ? 'Click again to delete' : 'Delete'"
                                                                x-text="(entry.deleteConfirmAt && (Date.now() - entry.deleteConfirmAt) < 3000) ? 'Sure?' : '×'"
                                                                @click="deleteEntry(entry, day)"></button>
                                                            <span class="drag-handle order-first" title="Drag to reorder">☰</span>
                                                            <input type="text" class="form-control form-control-sm" style="width: 4.5rem;" placeholder="08:30"
                                                                   x-model="entry.startTime" @blur="recalcDuration(entry); saveEntry(entry, day)" />
                                                            <input type="text" class="form-control form-control-sm" style="width: 4.5rem;" placeholder="17:00"
                                                                   x-model="entry.endTime" @blur="recalcDuration(entry); saveEntry(entry, day)" />
                                                            <span class="text-muted small text-end" style="width: 2.5rem;" x-text="entry.durationHours ? entry.durationHours.toFixed(2) : '—'"></span>
                                                            <select class="form-select form-select-sm" style="width: 160px;"
                                                                    x-model="entry.projectId" @change="saveEntry(entry, day)"
                                                                    x-init="fillSelectOptions($el, projects, 'id', 'name', 'name', entry.projectId)">
                                                                <option value="">— Project —</option>
                                                            </select>
                                                            <select class="form-select form-select-sm" style="width: 150px;"
                                                                    x-model="entry.taskGroupId" @change="saveEntry(entry, day)"
                                                                    x-init="fillSelectOptions($el, taskGroups, 'id', 'name', 'name', entry.taskGroupId)">
                                                                <option value="">— Task group —</option>
                                                            </select>
                                                            <select class="form-select form-select-sm" style="width: 150px;"
                                                                    x-model="entry.topicId" @change="saveEntry(entry, day)"
                                                                    x-init="fillSelectOptions($el, topics, 'id', 'name', 'name', entry.topicId)">
                                                                <option value="">— Topic —</option>
                                                            </select>
                                                            <input type="text" class="form-control form-control-sm" style="min-width: 200px; max-width: 350px; flex-grow: 1;" placeholder="Description"
                                                                   x-model="entry.description" @blur="saveEntry(entry, day)"
                                                                   @keydown.enter.prevent="onDescriptionEnter(day)" />
                                                            <div class="ms-auto d-flex align-items-center gap-2">
                                                                <button type="button" class="btn btn-sm btn-outline-secondary" @click="openTaskModal(entry)">
                                                                    <span x-text="(entry.ticketIds && entry.ticketIds.length > 0) ? entry.ticketIds.length + ' Tasks' : '+ Tasks'"></span>
                                                                </button>
                                                            </div>
                                                        </div>
                                                    </template>
                                                </div>
                                                <div class="d-flex justify-content-end py-2">
                                                    <button type="button" class="btn btn-sm btn-ghost-primary" @click="addPosition(day)">+ Add position</button>
                                                </div>
                                            </div>
                                        </div>
                                    </template>
                                </template>

                                <template x-if="block.type === 'weekend_group' && !expandedGroups[block.id]">
                                    <div class="mb-3 border rounded text-center py-2 small"
                                         style="cursor: pointer; background-color: #919294; color: #f5f6f7;" 
                                         @click="expandedGroups[block.id] = true" title="Click to add time on the weekend">
                                        <span x-text="block.days.length > 1 ? block.startDateText + ' - ' + block.endDateText : block.startDateText + ' \u2003 (' + block.days[0].weekdayText + ')'"></span>
                                        <strong>&nbsp;· Weekend</strong>
                                    </div>
                                </template>

                                <template x-if="block.type === 'leave_group' && !expandedGroups[block.id]">
                                    <div class="mb-3 border rounded text-center py-2 small d-flex justify-content-center align-items-center gap-2 leave-banner"
                                         :class="getLeaveColor(block.leaveType) === 'sickness' ? 'leave-banner-sickness' : ('bg-' + getLeaveColor(block.leaveType) + '-lt text-' + getLeaveColor(block.leaveType))"
                                         style="cursor: pointer;" @click="expandedGroups[block.id] = true" title="Click to add time on this leave day">
                                        <span x-text="block.days.length > 1 ? block.startDateText + ' - ' + block.endDateText : block.startDateText + ' \u2003 (' + block.days[0].weekdayText + ')'"></span>
                                        <strong>&nbsp;· <span x-text="block.leaveName"></span></strong>
                                        <a x-show="isAdmin" :href="'leaves.php?id=' + (block.leaveId || '')" @click.stop class="ms-2 text-dark text-decoration-none" title="Edit leave">✎</a>
                                    </div>
                                </template>

                            </div>
                        </template>
                    </div>

                    <div class="d-flex justify-content-end mt-4 pt-3 border-top">
                        <p class="m-0 text-muted small" x-text="totalEntriesCount() + ' entries ' + entriesCountLabel"></p>
                    </div>
                </div>
            </div>

            <div class="modal modal-blur fade" id="taskModal" tabindex="-1" x-ref="taskModalEl">
                <div class="modal-dialog modal-lg modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Link tasks &amp; test cases</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body" x-show="activeModalEntry">
                            <template x-if="activeModalEntry">
                                <div>
                                    <p class="small text-muted mb-2" x-text="'Entry: ' + (activeModalEntry.entryDate || '') + ' ' + (activeModalEntry.startTime || '') + '–' + (activeModalEntry.endTime || '')"></p>
                                    <div class="row mb-3">
                                        <div class="col-6">
                                            <h6 class="small text-uppercase text-muted">Contextual – Tickets</h6>
                                            <div class="border rounded p-2" style="max-height: 140px; overflow-y: auto;">
                                                <template x-for="t in (taskOptions.contextualTickets || [])" :key="t.id">
                                                    <label class="d-block small mb-1"><input type="checkbox" :checked="(activeModalEntry.ticketIds || []).indexOf(t.id) !== -1" @change="toggleTicketId(t.id)" class="me-2"/><span x-text="(t.ticket_no || t.id) + ' – ' + (t.title || '').substring(0, 40)"></span></label>
                                                </template>
                                                <span x-show="!(taskOptions.contextualTickets || []).length" class="text-muted small">—</span>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <h6 class="small text-uppercase text-muted">Contextual – Test cases</h6>
                                            <div class="border rounded p-2" style="max-height: 140px; overflow-y: auto;">
                                                <template x-for="tc in (taskOptions.contextualTestCases || [])" :key="tc.id">
                                                    <div class="small mb-1" x-text="(tc.id) + ' – ' + (tc.title || '').substring(0, 40)"></div>
                                                </template>
                                                <span x-show="!(taskOptions.contextualTestCases || []).length" class="text-muted small">—</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-6">
                                            <h6 class="small text-uppercase text-muted">My tickets</h6>
                                            <div class="border rounded p-2" style="max-height: 140px; overflow-y: auto;">
                                                <template x-for="t in (taskOptions.myTickets || [])" :key="t.id">
                                                    <label class="d-block small mb-1"><input type="checkbox" :checked="(activeModalEntry.ticketIds || []).indexOf(t.id) !== -1" @change="toggleTicketId(t.id)" class="me-2"/><span x-text="(t.ticket_no || t.id) + ' – ' + (t.title || '').substring(0, 40)"></span></label>
                                                </template>
                                                <span x-show="!(taskOptions.myTickets || []).length" class="text-muted small">—</span>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <h6 class="small text-uppercase text-muted">My test cases</h6>
                                            <div class="border rounded p-2" style="max-height: 140px; overflow-y: auto;">
                                                <template x-for="tc in (taskOptions.myTestCases || [])" :key="tc.id">
                                                    <div class="small mb-1" x-text="(tc.id) + ' – ' + (tc.title || '').substring(0, 40)"></div>
                                                </template>
                                                <span x-show="!(taskOptions.myTestCases || []).length" class="text-muted small">—</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-primary" @click="saveTaskModalAndClose()">Save</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal modal-blur fade" id="yearlyModal" tabindex="-1" aria-hidden="true">
              <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                  <div class="modal-header">
                    <h5 class="modal-title">Yearly Overview <?= $overviewYear ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                  </div>
                  <div class="modal-body bg-light">
                    <div class="row g-3">
                        <div class="col-md-12">
                            <div class="card card-sm">
                                <div class="card-header"><h4 class="card-title">Projects (Full Year)</h4></div>
                                <div class="table-responsive">
                                    <table class="table table-vcenter card-table table-sm table-striped mb-0">
                                        <thead>
                                            <tr>
                                                <th>Project</th>
                                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                                <th class="text-end"><?= date('M', mktime(0, 0, 0, $m, 1)) ?></th>
                                                <?php endfor; ?>
                                                <th class="text-end">Sum</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($projects as $p):
                                                $row = $overviewByProjectByMonth[$p['id']] ?? array_fill(1, 12, 0.0);
                                                $sum = array_sum($row);
                                                if ($sum <= 0) continue;
                                            ?>
                                            <tr>
                                                <td><?= e($p['name']) ?></td>
                                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                                <td class="text-end"><?= $row[$m] > 0 ? number_format($row[$m], 1) : '<span class="text-muted opacity-25">-</span>' ?></td>
                                                <?php endfor; ?>
                                                <td class="text-end fw-bold"><?= number_format($sum, 1) ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <?php
                                            $row0 = $overviewByProjectByMonth[0] ?? array_fill(1, 12, 0.0);
                                            $sum0 = array_sum($row0);
                                            if ($sum0 > 0): ?>
                                            <tr>
                                                <td>Miscellaneous</td>
                                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                                <td class="text-end"><?= $row0[$m] > 0 ? number_format($row0[$m], 1) : '<span class="text-muted opacity-25">-</span>' ?></td>
                                                <?php endfor; ?>
                                                <td class="text-end fw-bold"><?= number_format($sum0, 1) ?></td>
                                            </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-12">
                            <div class="card card-sm">
                                <div class="card-header"><h4 class="card-title">Quotas (Full Year)</h4></div>
                                <div class="table-responsive">
                                    <table class="table table-vcenter card-table table-sm mb-0">
                                        <thead>
                                            <tr>
                                                <th>Month</th>
                                                <th class="text-end">To be (h)</th>
                                                <th class="text-end">To be (d)</th>
                                                <th class="text-end">Actual (h)</th>
                                                <th class="text-end">Actual (d)</th>
                                                <th class="text-end">+/- (h)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php for ($m = 1; $m <= 12; $m++):
                                                $q = $overviewQuotasPerMonth[$m] ?? ['toBeHours' => null, 'toBeDays' => null, 'actualHours' => 0, 'actualDays' => 0];
                                                $diff = $q['toBeHours'] !== null ? round($q['actualHours'] - $q['toBeHours'], 1) : null;
                                            ?>
                                            <tr>
                                                <td><?= date('M Y', mktime(0, 0, 0, $m, 1, $overviewYear)) ?></td>
                                                <td class="text-end"><?= $q['toBeHours'] !== null ? number_format($q['toBeHours'], 1) : '—' ?></td>
                                                <td class="text-end"><?= $q['toBeDays'] !== null ? number_format($q['toBeDays'], 1) : '—' ?></td>
                                                <td class="text-end"><?= number_format($q['actualHours'], 1) ?></td>
                                                <td class="text-end"><?= number_format($q['actualDays'], 1) ?></td>
                                                <td class="text-end <?= $diff < 0 ? 'text-danger' : 'text-success' ?>"><?= $diff !== null ? ($diff > 0 ? '+' : '').number_format($diff, 1) : '—' ?></td>
                                            </tr>
                                            <?php endfor; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn me-auto" data-bs-dismiss="modal">Close</button>
                  </div>
                </div>
              </div>
            </div>

        </div>
    </div>
</div>

<script>
document.addEventListener('alpine:init', function() {
    Alpine.data('timesheetGrid', function(config) {
        config = config || {};
        return {
            days: Array.isArray(config.days) ? config.days : [],
            projects: Array.isArray(config.projects) ? config.projects : [],
            topics: Array.isArray(config.topics) ? config.topics : [],
            taskGroups: Array.isArray(config.taskGroups) ? config.taskGroups : [],
            isAdmin: config.isAdmin || false,
            entriesCountLabel: config.entriesCountLabel || '',
            actionUrl: config.actionUrl || 'timesheet.php',
            taskOptionsUrl: config.taskOptionsUrl || 'timesheet_task_options.php',

            activeModalEntry: null,
            taskOptions: { contextualTickets: [], contextualTestCases: [], myTickets: [], myTestCases: [] },

            expandedGroups: {}, 

            getLeaveColor: function(type) {
                var map = config.leaveTypeColors || { 'Vacation': 'blue', 'Sickness': 'sickness' };
                return map[type] || 'blue';
            },

            get groupedGrid() {
                var blocks = [];
                var currentGroup = null;

                this.days.forEach(function(day) {
                    var hasEntries = day.entries && day.entries.length > 0;

                    if (day.isLeave && !hasEntries) {
                        if (currentGroup && currentGroup.type === 'leave_group' && currentGroup.leaveId === day.leaveId) {
                            currentGroup.days.push(day);
                            currentGroup.endDateText = day.dateText;
                        } else {
                            if (currentGroup) blocks.push(currentGroup);
                            currentGroup = {
                                type: 'leave_group',
                                id: 'leave-' + day.leaveId + '-' + day.date,
                                leaveId: day.leaveId,
                                leaveName: day.leaveName,
                                leaveType: day.leaveType,
                                startDateText: day.dateText,
                                endDateText: day.dateText,
                                days: [day]
                            };
                        }
                    } else if (day.isWeekend && !hasEntries && !day.isLeave && !day.isPublicHoliday) {
                        if (currentGroup && currentGroup.type === 'weekend_group') {
                            currentGroup.days.push(day);
                            currentGroup.endDateText = day.dateText;
                        } else {
                            if (currentGroup) blocks.push(currentGroup);
                            currentGroup = {
                                type: 'weekend_group',
                                id: 'weekend-' + day.date,
                                startDateText: day.dateText,
                                endDateText: day.dateText,
                                days: [day]
                            };
                        }
                    } else {
                        if (currentGroup) {
                            blocks.push(currentGroup);
                            currentGroup = null;
                        }
                        blocks.push({
                            type: 'day',
                            id: 'day-' + day.date,
                            day: day
                        });
                    }
                });
                if (currentGroup) blocks.push(currentGroup);
                return blocks;
            },

            fillSelectOptions: function(el, list, valueKey, codeKey, nameKey, selectedValue) {
                if (!el || !list || !Array.isArray(list)) return;
                while (el.options.length > 1) el.remove(1);
                list.forEach(function(item) {
                    var opt = document.createElement('option');
                    opt.value = item[valueKey];
                    opt.textContent = codeKey === nameKey ? (item[nameKey] || '') : (item[codeKey] || '') + ' – ' + (item[nameKey] || '');
                    el.appendChild(opt);
                });
                var isEmpty = selectedValue === undefined || selectedValue === null || selectedValue === '' || selectedValue === 0;
                var val = isEmpty ? '' : String(selectedValue);
                el.value = val;
            },

            openTaskModal: function(entry) {
                var self = this;
                this.activeModalEntry = entry;
                this.taskOptions = { contextualTickets: [], contextualTestCases: [], myTickets: [], myTestCases: [] };
                this.fetchTaskOptions(entry);
                this.$nextTick(function() {
                    var el = document.getElementById('taskModal');
                    if (el && typeof bootstrap !== 'undefined') {
                        var m = bootstrap.Modal.getOrCreateInstance(el);
                        m.show();
                    }
                });
            },
            fetchTaskOptions: function(entry) {
                var self = this;
                var url = this.taskOptionsUrl + '?project_id=' + (entry.projectId || '') + '&topic_id=' + (entry.topicId || '');
                fetch(url).then(function(r) { return r.json(); }).then(function(data) {
                    self.taskOptions = data;
                }).catch(function() {});
            },
            toggleTicketId: function(id) {
                if (!this.activeModalEntry) return;
                var ids = (this.activeModalEntry.ticketIds || []).slice();
                var idx = ids.indexOf(id);
                if (idx === -1) ids.push(id); else ids.splice(idx, 1);
                this.activeModalEntry.ticketIds = ids;
            },
            saveTaskModalAndClose: function() {
                var entry = this.activeModalEntry;
                if (!entry) return;
                this.saveEntry(entry);
                var el = document.getElementById('taskModal');
                if (el && typeof bootstrap !== 'undefined') {
                    var m = bootstrap.Modal.getInstance(el);
                    if (m) m.hide();
                }
                this.activeModalEntry = null;
            },

            onDescriptionEnter: function(day) {
                var self = this;
                this.addPosition(day);
                this.$nextTick(function() {
                    var wrap = document.querySelector('[data-day-date="' + day.date + '"]');
                    if (wrap) {
                        var lastRow = wrap.querySelector('.entry-row:last-of-type');
                        if (lastRow) {
                            var startInput = lastRow.querySelector('input[placeholder="08:30"]');
                            if (startInput) startInput.focus();
                        }
                    }
                });
            },

            getDayForEntry: function(entry) {
                for (var i = 0; i < this.days.length; i++) {
                    if ((this.days[i].entries || []).indexOf(entry) !== -1) return this.days[i];
                }
                return null;
            },

            recalcDuration: function(entry) {
                var s = (entry.startTime || '').match(/^(\d{1,2}):(\d{2})$/);
                var e = (entry.endTime || '').match(/^(\d{1,2}):(\d{2})$/);
                if (s && e) {
                    var startM = parseInt(s[1], 10) * 60 + parseInt(s[2], 10);
                    var endM = parseInt(e[1], 10) * 60 + parseInt(e[2], 10);
                    if (endM > startM) entry.durationHours = Math.round((endM - startM) / 60 * 100) / 100;
                }
            },

            saveEntry: function(entry, day, opts) {
                day = day || this.getDayForEntry(entry);
                if (!day) return;
                if (!entry.entryDate) entry.entryDate = day.date;
                var projectId = entry.projectId === '' || entry.projectId === undefined ? null : parseInt(entry.projectId, 10);
                var topicId = entry.topicId === '' || entry.topicId === undefined ? null : parseInt(entry.topicId, 10);
                if (!projectId || !topicId || !entry.startTime || !entry.endTime) return;
                var silent = opts && opts.silent === true;

                entry.saving = true;

                var payload = {
                    action: 'save_entry',
                    entry_id: entry.id || 0,
                    entry_date: entry.entryDate,
                    start_time: entry.startTime,
                    end_time: entry.endTime,
                    project_id: projectId,
                    topic_id: topicId,
                    task_group_id: entry.taskGroupId === '' ? null : parseInt(entry.taskGroupId, 10),
                    description: entry.description || '',
                    task_ids: entry.ticketIds || []
                };
                
                var formData = new URLSearchParams();
                for (var key in payload) {
                    if (Array.isArray(payload[key])) {
                        payload[key].forEach(val => formData.append(key + '[]', val));
                    } else {
                        if (payload[key] !== null) formData.append(key, payload[key]);
                    }
                }

                fetch(this.actionUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                    body: formData.toString()
                }).then(function(r) { return r.json(); }).then(function(data) {
                    entry.saving = false;
                    if (data.success) {
                        if (data.entry && !entry.id) {
                            entry.id = data.entry.id;
                            entry.durationHours = data.entry.duration_hours;
                        }
                        if (!silent && typeof Toastify !== 'undefined') Toastify({ text: data.message, duration: 2000, style: { background: 'var(--tblr-success)' } }).showToast();
                    } else {
                        if (typeof Toastify !== 'undefined') Toastify({ text: data.error || 'Error', duration: 3000, style: { background: 'var(--tblr-danger)' } }).showToast();
                    }
                }).catch(function(err) {
                    entry.saving = false;
                    if (typeof Toastify !== 'undefined') Toastify({ text: 'Request failed', duration: 3000, style: { background: 'var(--tblr-danger)' } }).showToast();
                });
            },

            deleteEntry: function(entry, day) {
                var now = Date.now();
                var inConfirm = entry.deleteConfirmAt && (now - entry.deleteConfirmAt) < 3000;
                if (!inConfirm) {
                    entry.deleteConfirmAt = now;
                    setTimeout(function() { entry.deleteConfirmAt = null; }, 3000);
                    return;
                }
                entry.deleteConfirmAt = null;
                if (entry.id) {
                    entry.saving = true;
                    var formData = new URLSearchParams();
                    formData.append('action', 'delete_entry');
                    formData.append('entry_id', entry.id);

                    fetch(this.actionUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                        body: formData.toString()
                    }).then(function(r) { return r.json(); }).then(function(data) {
                        entry.saving = false;
                        if (data.success) {
                            day.entries = day.entries.filter(function(e) { return e !== entry; });
                            if (typeof Toastify !== 'undefined') Toastify({ text: data.message, duration: 2000, style: { background: 'var(--tblr-success)' } }).showToast();
                        } else {
                            if (typeof Toastify !== 'undefined') Toastify({ text: data.error || 'Error', duration: 3000, style: { background: 'var(--tblr-danger)' } }).showToast();
                        }
                    }).catch(function() {
                        entry.saving = false;
                        if (typeof Toastify !== 'undefined') Toastify({ text: 'Request failed', duration: 3000, style: { background: 'var(--tblr-danger)' } }).showToast();
                    });
                } else {
                    day.entries = day.entries.filter(function(e) { return e !== entry; });
                }
            },

            addPosition: function(day) {
                if (!day.entries) day.entries = [];
                var startTime = '';
                var endTime = '';
                if (day.entries.length > 0) {
                    var lastEnd = (day.entries[day.entries.length - 1].endTime || '').trim();
                    if (lastEnd && /^\d{1,2}:\d{2}$/.test(lastEnd)) {
                        startTime = lastEnd;
                        endTime = this.timeAddDuration(startTime, 0.5);
                    }
                }
                if (startTime === '' && endTime === '') {
                    startTime = '08:00';
                    endTime = '08:30';
                }
                day.entries.push({
                    id: null,
                    entryDate: day.date,
                    startTime: startTime,
                    endTime: endTime,
                    durationHours: 0.5,
                    projectId: '',
                    topicId: '',
                    taskGroupId: '',
                    description: '',
                    ticketIds: []
                });
            },
            timeStrToMinutes: function(timeStr) {
                var m = (timeStr || '').match(/^(\d{1,2}):(\d{2})$/);
                if (!m) return 0;
                return parseInt(m[1], 10) * 60 + parseInt(m[2], 10);
            },
            minutesToTimeStr: function(totalMinutes) {
                totalMinutes = Math.max(0, Math.min(24 * 60 - 1, Math.round(totalMinutes)));
                var h = Math.floor(totalMinutes / 60);
                var m = totalMinutes % 60;
                return (h < 10 ? '0' : '') + h + ':' + (m < 10 ? '0' : '') + m;
            },
            timeAddDuration: function(timeStr, durationHours) {
                var mins = this.timeStrToMinutes(timeStr) + Math.round((durationHours || 0) * 60);
                return this.minutesToTimeStr(mins);
            },
            cascadeTimes: function(day) {
                var self = this;
                var entries = day.entries || [];
                for (var i = 0; i < entries.length; i++) {
                    var entry = entries[i];
                    var dur = entry.durationHours;
                    if (typeof dur !== 'number' || isNaN(dur) || dur <= 0) dur = 0.5;
                    if (i === 0) {
                        var start = (entry.startTime || '').trim();
                        if (!/^\d{1,2}:\d{2}$/.test(start)) start = '08:00';
                        entry.startTime = start;
                        entry.endTime = this.timeAddDuration(start, dur);
                    } else {
                        var prevEnd = (entries[i - 1].endTime || '').trim();
                        if (!/^\d{1,2}:\d{2}$/.test(prevEnd)) prevEnd = '08:00';
                        entry.startTime = prevEnd;
                        entry.endTime = this.timeAddDuration(prevEnd, dur);
                    }
                    entry.durationHours = dur;
                    this.saveEntry(entry, day, { silent: true });
                }
                if (entries.length > 0 && typeof Toastify !== 'undefined') {
                    Toastify({ text: entries.length === 1 ? 'Entry updated' : entries.length + ' entries updated', duration: 2000, style: { background: 'var(--tblr-success)' } }).showToast();
                }
            },
            initSortable: function(el, day) {
                var self = this;
                if (typeof Sortable === 'undefined') return;
                var existing = Sortable.get(el);
                if (existing) existing.destroy();
                Sortable.create(el, {
                    handle: '.drag-handle',
                    animation: 150,
                    onEnd: function(evt) {
                        var arr = day.entries.slice();
                        var item = arr.splice(evt.oldIndex, 1)[0];
                        arr.splice(evt.newIndex, 0, item);
                        day.entries = arr;
                        self.cascadeTimes(day);
                    }
                });
            },

            totalEntriesCount: function() {
                var n = 0;
                (this.days || []).forEach(function(d) { n += (d.entries || []).length; });
                return n;
            }
        };
    });
});
</script>
<?php require_once 'includes/footer.php'; ?>
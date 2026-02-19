<?php
/**
 * import_tickets.php - Robust CSV import for data/tickets.csv
 * Column mapping from CSV header. Wipes tickets clean. Run via "Import Tickets" button.
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(300);

require 'includes/config.php';

// CSV column indices (0-based) - match data/tickets.csv header
define('COL_NO', 0);           // No. (ticket number)
define('COL_FIX_DEV', 1);      // Fix/Dev
define('COL_EXT_INT', 2);      // Ext/Int
define('COL_SHORT_DESC', 3);   // Short description
define('COL_GOAL', 4);         // Goal
define('COL_CREATED_AT', 5);   // Created at (via Makro)
define('COL_FOLDER', 6);       // Folder
define('COL_OBJECT_TYPE', 7);  // Object Type (object name, not group)
define('COL_PRIORITY', 8);     // Priority
define('COL_WORKING_PRIO', 9); // Working Prio
define('COL_STATUS', 10);      // Status
define('COL_COMMENTS_NEXT', 11);
define('COL_REPORTER', 12);    // Reporter
define('COL_RESPONSIBLE', 13); // Responsible
define('COL_SOLUTION_APPROACH', 14); // Solution approach / Comment (audit trail source)
define('COL_TRANSPORT', 15);
define('COL_DETAILED_DESC', 16);

$csvFile = 'data/tickets.csv';
$delimiter = ';';

$userMap = require __DIR__ . '/includes/author_map.php';

// Status -> status_id
$statusMap = [
    'Neu' => 10, 'New' => 10, 'Techn. Evaluation' => 20, 'Evaluation' => 20,
    'Business Eval' => 30, 'Dev Planning' => 40, 'Planning' => 40,
    'In Development' => 50, 'Development' => 50, 'Basic Test' => 60, 'Test' => 60,
    'Transport' => 70, 'Cust. Del. Test' => 80, 'SOX' => 80, 'Note Creation' => 85,
    'Customer Action' => 90, 'Solution Proposal' => 95, 'Solved' => 95,
    'Confirmed' => 100, 'Closed' => 100, 'Automatically Confirmed' => 100,
    'In Process' => 50, 'Done' => 100,
];

// Ext/Int -> type_id (1=Customer/Ext, 2=Internal/Int)
$sourceToType = ['Ext' => 1, 'Int' => 2];

$pageTitle = 'Import Tickets';
$pageHeadExtra = '<style>.log{background:#1e1e1e;color:#eee;padding:20px;border-radius:8px;font-family:monospace;font-size:13px;}.ok{color:#51cf66;}.warn{color:#fcc419;}.err{color:#ff6b6b;}.info{color:#339af0;}</style>';
require_once 'includes/header.php';
?>
    <div class="page-wrapper">
        <div class="container-xl">
            <h1 class="page-title mb-4">Import Tickets from CSV</h1>

<?php
if (!file_exists($csvFile)) {
    echo "<div class='alert alert-danger'>File $csvFile not found.</div>";
    exit;
}

$handle = fopen($csvFile, 'r');
if (!$handle) {
    echo "<div class='alert alert-danger'>Cannot open CSV.</div>";
    exit;
}

// Skip header
$header = fgetcsv($handle, 0, $delimiter);

// Ensure optional columns exist (new schema: goal, working_prio; no object_id, assignee, detailed_desc)
foreach (['source VARCHAR(10) NULL', 'fix_dev_type VARCHAR(10) NULL', 'ticket_no INT NULL', 'affected_object_count INT NULL', 'affected_object_note VARCHAR(255) NULL', 'customer_id INT NULL', 'goal TEXT NULL', 'working_prio INT NULL'] as $colDef) {
    $col = explode(' ', $colDef)[0];
    try { $pdo->exec("ALTER TABLE tickets ADD COLUMN $colDef"); } catch (PDOException $e) { /* exists */ }
}

// Create ticket_objects table (many-to-many)
$pdo->exec("CREATE TABLE IF NOT EXISTS ticket_objects (ticket_id INT NOT NULL, object_id INT NOT NULL, PRIMARY KEY (ticket_id, object_id), FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE, FOREIGN KEY (object_id) REFERENCES objects(id) ON DELETE CASCADE)");

// Create ticket_participants table (Reporter + Responsible in one table, role ENUM 'REP'/'RES')
$pdo->exec("CREATE TABLE IF NOT EXISTS ticket_participants (
    ticket_id INT NOT NULL,
    ticket_no INT NOT NULL,
    person_name VARCHAR(255) NOT NULL,
    role ENUM('REP','RES') NOT NULL,
    PRIMARY KEY (ticket_id, role, person_name),
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
)");

// Wipe clean (child tables before tickets due to FK)
try {
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    try { $pdo->exec("TRUNCATE TABLE ticket_participants"); } catch (PDOException $e) {}
    try { $pdo->exec("TRUNCATE TABLE ticket_objects"); } catch (PDOException $e) {}
    $pdo->exec("TRUNCATE TABLE ticket_comments");
    $pdo->exec("TRUNCATE TABLE ticket_history");
    $pdo->exec("TRUNCATE TABLE ticket_relationships");
    $pdo->exec("TRUNCATE TABLE tickets");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
} catch (PDOException $e) {
    echo "<div class='alert alert-danger'>DB truncate error: " . htmlspecialchars($e->getMessage()) . "</div>";
    exit;
}

// Load all objects for fuzzy matching (id, name)
$allObjects = [];
$stmt = $pdo->query("SELECT id, name FROM objects ORDER BY name");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $allObjects[] = ['id' => (int) $row['id'], 'name' => trim($row['name'])];
}

// Explicit mapping: CSV variations -> canonical object name (from objects table)
$objectNameMap = [
    'payment term' => 'Payment Terms',
    'payment terms details' => 'Payment Terms',
    'holiday calendar' => 'Holiday Cal & Pub. Hol.',
    'holiday cal' => 'Holiday Cal & Pub. Hol.',
    'public holiday' => 'Holiday Cal & Pub. Hol.',
    'characteristic' => 'Characteristics',
    'characteristics' => 'Characteristics',
    'class' => 'Classes',
    'classes' => 'Classes',
    'company' => 'Company Code',
    'company code' => 'Company Code',
    'company id' => 'Company ID',
    'posting period variant' => 'Posting Period Variant',
    'ppv' => 'Posting Period Variant',
    'material group' => 'Material Group',
    'purchasing group' => 'Purchasing Group',
    'purchasing org' => 'Purchasing Org.',
    'purchasing org.' => 'Purchasing Org.',
    'tax code' => 'Tax Code',
    'shipping point' => 'Shipping Point',
    'product hierarchy' => 'Product Hierarchy',
    'plant' => 'Plant',
    'sales office' => 'Sales Office',
    'object sales office' => 'Sales Office',
    'sales organization' => 'Sales Organization',
    'country & region' => 'Country & Region',
    'country and region' => 'Country & Region',
    'gen. ledger acc. group' => 'Gen. Ledger Acc. Group',
    'functional area' => 'Functional Area',
    'factory calendar' => 'Factory Calendar',
    'storage location' => 'Storage Location',
    'unit of measure' => 'Unit of Measure',
    'planning scope' => 'Planning Scope',
    'incoterms' => 'Incoterms',
    'distribution channel' => 'Distribution Channel',
    'shipping conditions' => 'Shipping Conditions',
    'shipping type' => 'Shipping Type',
    'sales group' => 'Sales Group',
    'mrp area' => 'MRP Area',
    'mrp controller' => 'MRP Controller',
    'mrp profile' => 'MRP Profile',
    'mrp type' => 'MRP Type',
    'material type' => 'Material Type',
    'material status' => 'Material Status',
    'division' => 'Division',
    'location' => 'Location',
    'bom usage' => 'BOM Usage',
    'laboratory/office' => 'Laboratory/Office',
    'personnel area' => 'Personnel Area',
    'language' => 'Language',
    'chart of accounts' => 'Chart of Accounts',
    'controlling area' => 'Controlling Area',
    'currency' => 'Currency',
    'document type' => 'Document Type',
    'exchange rate' => 'Exchange Rate',
    'operating concern' => 'Operating Concern',
    'valuation class' => 'Valuation Class',
    'vendor acc. group' => 'Vendor Acc. Group',
    'customer acc. group' => 'Customer Acc. Group',
];

/**
 * Fuzzy match: find best object from DB by name.
 * Uses: 1) exact, 2) explicit map, 3) contains, 4) similar_text
 */
function fuzzyMatchObject($objects, $nameMap, $search) {
    $search = trim($search);
    if (empty($search)) return null;
    $key = strtolower($search);
    if (isset($nameMap[$key]) && $nameMap[$key] !== null) {
        $canonical = $nameMap[$key];
        foreach ($objects as $o) {
            if (strcasecmp($o['name'], $canonical) === 0) return $o['id'];
        }
    }
    foreach ($objects as $o) {
        if (strcasecmp($o['name'], $search) === 0) return $o['id'];
    }
    foreach ($objects as $o) {
        if (stripos($o['name'], $search) !== false || stripos($search, $o['name']) !== false) return $o['id'];
    }
    $best = null;
    $bestScore = 0;
    foreach ($objects as $o) {
        similar_text(strtolower($search), strtolower($o['name']), $pct);
        if ($pct > $bestScore && $pct >= 60) {
            $bestScore = $pct;
            $best = $o['id'];
        }
    }
    return $best;
}

/**
 * Parse object string from CSV: split by comma, " and ", handle "X object types", "all object types"
 * Returns: ['object_ids' => [1,2], 'affected_count' => 11|null, 'affected_note' => 'all object types'|null]
 */
function parseObjectString($raw, $objects, $nameMap) {
    $raw = trim($raw);
    $result = ['object_ids' => [], 'affected_count' => null, 'affected_note' => null];
    if (empty($raw)) return $result;

    if (preg_match('/^(\d+)\s*object\s*types?$/i', $raw, $m)) {
        $result['affected_count'] = (int) $m[1];
        $result['affected_note'] = $raw;
        return $result;
    }
    if (preg_match('/^all\s*(?:object\s*types?)?$/i', $raw) || strtolower($raw) === 'all') {
        $result['affected_count'] = 0;
        $result['affected_note'] = 'all object types';
        return $result;
    }
    if (in_array(strtolower($raw), ['several', 'n/a', 'na', '-'])) {
        $result['affected_note'] = $raw;
        return $result;
    }

    $tokens = preg_split('/\s*,\s*|\s+and\s+|\s+&\s+/i', $raw);
    $seen = [];
    foreach ($tokens as $t) {
        $t = trim($t);
        if (empty($t)) continue;
        $id = fuzzyMatchObject($objects, $nameMap, $t);
        if ($id && !isset($seen[$id])) {
            $result['object_ids'][] = $id;
            $seen[$id] = true;
        }
    }
    if (empty($result['object_ids']) && !empty($raw)) {
        $id = fuzzyMatchObject($objects, $nameMap, $raw);
        if ($id) $result['object_ids'][] = $id;
        else $result['affected_note'] = $raw;
    }
    return $result;
}

// Regex for audit trail: DD.MM.YYYY, DD.MM.YY, DD.MM. or DD/MM/YYYY, DD/MM
$commentRegex = '/(\d{1,2})[.\/](\d{1,2})(?:[.\/](\d{2,4})?)?\s*(?:(\d{1,2}:\d{2})\s*)?(?:([A-Za-z]{2,5})\b)?[:\-\s]*(.*?)(?=\d{1,2}[.\/]\d{1,2}(?:[.\/]\d{2,4})?|$)/s';

$stats = ['tickets' => 0, 'comments' => 0, 'errors' => 0];
$importDate = date('Y-m-d H:i:s');
// New schema: no created_by (reporter lives in ticket_participants)
$insertTicketSql = "
    INSERT INTO tickets (title, goal, working_prio, priority, status_id, type_id, created_at, source, fix_dev_type, ticket_no, affected_object_count, affected_object_note, customer_id)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
";
$insertTicketFallbackSql = "
    INSERT INTO tickets (title, goal, working_prio, priority, status_id, type_id, created_at, source, fix_dev_type, ticket_no, customer_id)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
";
// Fallback for DBs that still have created_by: same columns + created_by (e.g. first reporter)
$insertTicketWithCreatedBySql = "
    INSERT INTO tickets (title, goal, working_prio, priority, status_id, type_id, created_at, created_by, source, fix_dev_type, ticket_no, affected_object_count, affected_object_note, customer_id)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
";
$insertTicketWithCreatedByFallbackSql = "
    INSERT INTO tickets (title, goal, working_prio, priority, status_id, type_id, created_at, created_by, source, fix_dev_type, ticket_no, customer_id)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
";
$insertParticipantStmt = $pdo->prepare("INSERT IGNORE INTO ticket_participants (ticket_id, ticket_no, person_name, role) VALUES (?, ?, ?, ?)");
$insertObjectStmt = $pdo->prepare("INSERT IGNORE INTO ticket_objects (ticket_id, object_id) VALUES (?, ?)");

while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
    $noRaw = trim($data[COL_NO] ?? '');
    if (empty($noRaw) || !is_numeric($noRaw)) continue;

    $ticketNo = (int) $noRaw;
    if ($ticketNo < 500) continue;  // Tickets unter 500 ignorieren

    $fixDev = trim($data[COL_FIX_DEV] ?? '');
    $extInt = trim($data[COL_EXT_INT] ?? '');
    $shortDesc = trim($data[COL_SHORT_DESC] ?? '') ?: 'Ohne Titel';
    $goal = trim($data[COL_GOAL] ?? '');
    $workingPrioRaw = trim($data[COL_WORKING_PRIO] ?? '');
    $workingPrio = $workingPrioRaw !== '' && is_numeric($workingPrioRaw) ? (int) $workingPrioRaw : null;
    $objectRaw = trim($data[COL_OBJECT_TYPE] ?? '');
    $priority = trim($data[COL_PRIORITY] ?? '');
    $statusRaw = trim($data[COL_STATUS] ?? '');
    $reporter = trim($data[COL_REPORTER] ?? '');
    $responsible = trim($data[COL_RESPONSIBLE] ?? '');
    $solutionCol = $data[COL_SOLUTION_APPROACH] ?? '';

    $createdAtMacro = trim($data[COL_CREATED_AT] ?? '');
    $createdAt = $importDate;
    if (!empty($createdAtMacro)) {
        try {
            $dt = new DateTime(str_replace(['.', '/'], ['-', '-'], $createdAtMacro));
            $createdAt = $dt->format('Y-m-d H:i:s');
        } catch (Exception $e) {}
    }

    $statusId = $statusMap[$statusRaw] ?? 10;
    $typeId = $sourceToType[$extInt] ?? 2;

    // Object Type: split by comma for multiple; resolve via ticket_objects (N:M)
    $parsed = parseObjectString($objectRaw, $allObjects, $objectNameMap);
    $objectIds = $parsed['object_ids'];
    $affectedCount = $parsed['affected_count'];
    $affectedNote = $parsed['affected_note'];

    // Customer Scope: match reporter (created_by) to customers table
    $customerId = null;
    if (!empty($reporter)) {
        try {
            $custStmt = $pdo->prepare("SELECT id FROM customers WHERE ? LIKE CONCAT('%', name, '%') ORDER BY LENGTH(name) DESC LIMIT 1");
            $custStmt->execute([$reporter]);
            $cust = $custStmt->fetch(PDO::FETCH_ASSOC);
            if ($cust) {
                $customerId = (int) $cust['id'];
            }
        } catch (PDOException $e) {}
    }

    try {
        $pdo->beginTransaction();

        $createdByVal = $reporter !== '' ? trim(explode('/', $reporter)[0]) : 'Import';

        try {
            $stmt = $pdo->prepare($insertTicketSql);
            $stmt->execute([
                $shortDesc,
                $goal !== '' ? $goal : null,
                $workingPrio,
                $priority !== '' ? $priority : null,
                $statusId,
                $typeId,
                $createdAt,
                $extInt ?: null,
                $fixDev ?: null,
                $ticketNo,
                $affectedCount,
                $affectedNote,
                $customerId ?: null
            ]);
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            $needCreatedBy = (strpos($msg, 'created_by') !== false || strpos($msg, 'Column count') !== false);
            $needFallbackColumns = (strpos($msg, 'affected_object') !== false || strpos($msg, 'Unknown column') !== false);

            if ($needCreatedBy) {
                try {
                    $stmt = $pdo->prepare($insertTicketWithCreatedBySql);
                    $stmt->execute([
                        $shortDesc,
                        $goal !== '' ? $goal : null,
                        $workingPrio,
                        $priority !== '' ? $priority : null,
                        $statusId,
                        $typeId,
                        $createdAt,
                        $createdByVal,
                        $extInt ?: null,
                        $fixDev ?: null,
                        $ticketNo,
                        $affectedCount,
                        $affectedNote,
                        $customerId ?: null
                    ]);
                } catch (PDOException $e2) {
                    if (strpos($e2->getMessage(), 'affected_object') !== false || strpos($e2->getMessage(), 'Unknown column') !== false) {
                        $stmt = $pdo->prepare($insertTicketWithCreatedByFallbackSql);
                        $stmt->execute([
                            $shortDesc,
                            $goal !== '' ? $goal : null,
                            $workingPrio,
                            $priority !== '' ? $priority : null,
                            $statusId,
                            $typeId,
                            $createdAt,
                            $createdByVal,
                            $extInt ?: null,
                            $fixDev ?: null,
                            $ticketNo,
                            $customerId ?: null
                        ]);
                    } else {
                        throw $e2;
                    }
                }
            } elseif ($needFallbackColumns) {
                $stmt = $pdo->prepare($insertTicketFallbackSql);
                $stmt->execute([
                    $shortDesc,
                    $goal !== '' ? $goal : null,
                    $workingPrio,
                    $priority !== '' ? $priority : null,
                    $statusId,
                    $typeId,
                    $createdAt,
                    $extInt ?: null,
                    $fixDev ?: null,
                    $ticketNo,
                    $customerId ?: null
                ]);
            } else {
                throw $e;
            }
        }
        $ticketId = (int) $pdo->lastInsertId();
        if ($ticketId <= 0) {
            throw new RuntimeException("Insert ticket failed for No. $ticketNo");
        }
        $stats['tickets']++;

        // Participants: Reporter (REP) and Responsible (RES) in ticket_participants
        $reporterNames = array_filter(array_map('trim', explode('/', $reporter)));
        foreach ($reporterNames as $name) {
            if ($name !== '') {
                $insertParticipantStmt->execute([$ticketId, $ticketNo, $name, 'REP']);
            }
        }
        $responsibleNames = array_filter(array_map('trim', explode('/', $responsible)));
        foreach ($responsibleNames as $name) {
            if ($name !== '') {
                $insertParticipantStmt->execute([$ticketId, $ticketNo, $name, 'RES']);
            }
        }

        // Objects: N:M via ticket_objects (from "Object Type", already split by comma in parseObjectString)
        foreach ($objectIds as $oid) {
            $insertObjectStmt->execute([$ticketId, $oid]);
        }

        // Parse Solution approach / Comment for audit trail
        $solutionText = is_string($solutionCol) ? $solutionCol : '';
        $defaultYear = $ticketNo < 1000 ? 2025 : 2026;

        if (preg_match_all($commentRegex, $solutionText, $matches, PREG_SET_ORDER)) {
            $cStmt = $pdo->prepare("INSERT INTO ticket_comments (ticket_id, author, comment_text, created_at) VALUES (?, ?, ?, ?)");
            foreach ($matches as $m) {
                $day = (int) $m[1];
                $month = (int) $m[2];
                $yearPart = isset($m[3]) && $m[3] !== '' ? $m[3] : null;
                $initial = isset($m[5]) ? strtoupper(trim($m[5])) : '';
                $text = trim($m[6]);

                if (empty($text)) continue;

                $authorName = isset($userMap[$initial]) ? $userMap[$initial] : ($initial ?: 'System');
                if (!isset($userMap[$initial]) && strlen($initial) >= 2 && strlen($initial) <= 5) {
                    $authorName = $initial;
                }

                $year = $defaultYear;
                if ($yearPart !== null) {
                    $y = (int) $yearPart;
                    if ($y >= 100) $year = $y;
                    elseif ($y >= 50) $year = 1900 + $y;
                    else $year = 2000 + $y;
                }
                $dbDate = sprintf('%04d-%02d-%02d 12:00:00', $year, $month, $day);

                $cStmt->execute([$ticketId, $authorName, $text, $dbDate]);
                $stats['comments']++;
            }
        }

        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $stats['errors']++;
        echo "<div class='log warn'>Row No. $ticketNo: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

echo "<div class='alert alert-success'><strong>Import finished.</strong> Tickets: {$stats['tickets']}, Comments: {$stats['comments']}" . ($stats['errors'] > 0 ? ", Errors: {$stats['errors']}" : '') . "</div>";
echo "<a href='tickets.php' class='btn btn-primary'>View Tickets</a>";

fclose($handle);
?>
        </div>
    </div>
</div>
<?php require_once 'includes/footer.php'; ?>
</body>
</html>

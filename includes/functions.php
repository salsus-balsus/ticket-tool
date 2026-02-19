<?php
/**
 * includes/functions.php
 * Core functions for the Ticket Tool (State Machine, Role-based permissions)
 */

// Ensure config/DB is available
if (!isset($pdo)) {
    require __DIR__ . '/config.php';
}

/**
 * Get the role ID for the current user. Used for permission checks on workflow transitions.
 * Resolves $current_user (from config) via app_users to role_id. Fallback 1 if not found.
 * @param int $user_id Ignored; current user is taken from environment/config
 * @return int Role ID
 */
function get_user_role($user_id = 0) {
    global $pdo, $current_user;
    // User override via cookie (navbar user dropdown)
    if (isset($_COOKIE['dev_user_id'])) {
        $dev_id = (int) $_COOKIE['dev_user_id'];
        if ($dev_id > 0) {
            try {
                $stmt = $pdo->prepare("SELECT role_id FROM app_users WHERE id = ? LIMIT 1");
                $stmt->execute([$dev_id]);
                $rid = $stmt->fetchColumn();
                if ($rid !== false) {
                    return (int) $rid;
                }
            } catch (PDOException $e) {}
        }
    }
    // Test: override role via cookie
    if (isset($_COOKIE['dev_role_id'])) {
        $override = (int) $_COOKIE['dev_role_id'];
        if ($override > 0) {
            try {
                $stmt = $pdo->prepare("SELECT id FROM roles WHERE id = ?");
                $stmt->execute([$override]);
                if ($stmt->fetchColumn() !== false) {
                    return $override;
                }
            } catch (PDOException $e) {}
        }
    }
    try {
        $stmt = $pdo->prepare("SELECT role_id FROM app_users WHERE username = ? LIMIT 1");
        $stmt->execute([$current_user ?? '']);
        $roleId = $stmt->fetchColumn();
        return $roleId !== false ? (int) $roleId : 1;
    } catch (PDOException $e) {
        return 1;
    }
}

/**
 * Get the effective user identity. Used for audit trails, reporter defaults, etc.
 * When dev_user_id cookie is set (navbar user dropdown), returns that user's username.
 * Otherwise returns $current_user from config (real login).
 * @return string Username
 */
function get_effective_user() {
    global $pdo, $current_user;
    if (isset($_COOKIE['dev_user_id']) && (int) $_COOKIE['dev_user_id'] > 0) {
        try {
            $stmt = $pdo->prepare("SELECT username FROM app_users WHERE id = ? LIMIT 1");
            $stmt->execute([(int) $_COOKIE['dev_user_id']]);
            $u = $stmt->fetchColumn();
            return $u !== false ? (string) $u : ($current_user ?? '');
        } catch (PDOException $e) {}
    }
    return $current_user ?? '';
}

/**
 * Get the effective user's app_users.id. Use when storing user_id (e.g. user_leaves).
 * @return int|null app_users.id or null if not found
 */
function get_effective_user_id() {
    global $pdo, $current_user;
    if (isset($_COOKIE['dev_user_id']) && (int) $_COOKIE['dev_user_id'] > 0) {
        try {
            $stmt = $pdo->prepare("SELECT id FROM app_users WHERE id = ? LIMIT 1");
            $stmt->execute([(int) $_COOKIE['dev_user_id']]);
            $id = $stmt->fetchColumn();
            return $id !== false ? (int) $id : null;
        } catch (PDOException $e) {}
    }
    try {
        $stmt = $pdo->prepare("SELECT id FROM app_users WHERE username = ? LIMIT 1");
        $stmt->execute([$current_user ?? '']);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int) $id : null;
    } catch (PDOException $e) {}
    return null;
}

/**
 * Get allowed transitions for a ticket based on current status, type, and user role.
 * Queries workflow_transitions with strict conditions. Joins ticket_statuses for target color/name.
 *
 * @param int $ticket_id Ticket ID (for potential future per-ticket overrides)
 * @param int $current_status_id Current status_id from ticket_statuses
 * @param int $type_id Ticket type (1=Customer, 2=Internal)
 * @param int $user_role_id User's role_id
 * @return array[] Objects: next_status_id, target_owner_role_id, button_label, target_status_name, target_status_color
 */
function get_allowed_transitions($ticket_id, $current_status_id, $type_id, $user_role_id) {
    global $pdo;
    $sql = "SELECT wt.next_status_id, wt.target_owner_role_id, wt.button_label,
                tgs.name AS target_status_name, tgs.color_code AS target_status_color
            FROM workflow_transitions wt
            LEFT JOIN ticket_statuses tgs ON wt.next_status_id = tgs.id
            WHERE wt.current_status_id = ?
              AND wt.flow_type_id = ?
              AND wt.allowed_role_id = ?
            ORDER BY wt.next_status_id ASC";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$current_status_id, $type_id, $user_role_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Resolve raw author string to app_users row (username, initials, or first_name + last_name).
 * @param string $raw Raw author from ticket_comments
 * @return array|null Row with first_name, last_name, initials or null
 */
function get_app_user_by_author($raw) {
    global $pdo;
    $raw = trim($raw ?? '');
    if ($raw === '') return null;
    static $byUsername = null;
    static $byInitials = null;
    static $byFullName = null;
    if ($byUsername === null) {
        $byUsername = [];
        $byInitials = [];
        $byFullName = [];
        try {
            $rows = $pdo->query("SELECT id, username, first_name, last_name, initials FROM app_users")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $un = trim($r['username'] ?? '');
                if ($un !== '') $byUsername[$un] = $r;
                $ini = trim($r['initials'] ?? '');
                if ($ini !== '') $byInitials[mb_strtoupper($ini)] = $r;
                $fn = trim($r['first_name'] ?? '');
                $ln = trim($r['last_name'] ?? '');
                $full = trim($fn . ' ' . $ln);
                if ($full !== '') {
                    $byFullName[$full] = $r;
                    if ($ln !== '') $byFullName[trim($ln . ' ' . $fn)] = $r;
                }
            }
        } catch (PDOException $e) {}
    }
    if (isset($byUsername[$raw])) return $byUsername[$raw];
    if (isset($byInitials[mb_strtoupper($raw)])) return $byInitials[mb_strtoupper($raw)];
    if (isset($byFullName[$raw])) return $byFullName[$raw];
    return null;
}

/**
 * Resolve comment author to display name: per-comment override, then app_users (first_name + last_name), then author_map, then author_display.
 * @param string $raw Raw author from ticket_comments
 * @param int|null $comment_id If set, per-comment override takes precedence
 * @return string Display name (Vorname Nachname for comments)
 */
function get_author_display_name($raw, $comment_id = null) {
    global $pdo;
    $raw = trim($raw ?? '');
    if ($comment_id !== null && $comment_id > 0) {
        try {
            $stmt = $pdo->prepare("SELECT display_name FROM comment_author_override WHERE comment_id = ?");
            $stmt->execute([(int) $comment_id]);
            $name = $stmt->fetchColumn();
            if ($name !== false && $name !== '') return $name;
        } catch (PDOException $e) {
            // table may not exist
        }
    }
    if ($raw === '') return '—';
    $appUser = get_app_user_by_author($raw);
    if ($appUser !== null) {
        $fn = trim($appUser['first_name'] ?? '');
        $ln = trim($appUser['last_name'] ?? '');
        $name = trim($fn . ' ' . $ln);
        if ($name !== '') return $name;
    }
    static $map = null;
    if ($map === null) {
        $map = [];
        $phpMap = @include __DIR__ . '/author_map.php';
        if (is_array($phpMap)) {
            foreach ($phpMap as $k => $v) {
                $map[$k] = $v;
                $map[$v] = $v;
            }
        }
        try {
            $rows = $pdo->query("SELECT author_raw, display_name FROM author_display")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $map[$r['author_raw']] = $r['display_name'];
            }
        } catch (PDOException $e) {
            // table may not exist
        }
    }
    return $map[$raw] ?? $raw;
}

/**
 * Get initials for comment author: from app_users.initials if maintained, else derived from display name.
 * @param string $raw Raw author from ticket_comments
 * @param int|null $comment_id For override lookup
 * @return string Initials (e.g. TM)
 */
function get_author_initials($raw, $comment_id = null) {
    $appUser = get_app_user_by_author(trim($raw ?? ''));
    if ($appUser !== null) {
        $ini = trim($appUser['initials'] ?? '');
        if ($ini !== '') return $ini;
        $fn = trim($appUser['first_name'] ?? '');
        $ln = trim($appUser['last_name'] ?? '');
        if ($fn !== '' || $ln !== '') {
            return strtoupper(mb_substr($fn, 0, 1) . mb_substr($ln, 0, 1));
        }
    }
    $displayName = get_author_display_name($raw, $comment_id);
    if (empty($displayName) || $displayName === '—') return '?';
    $parts = preg_split('/\s+/', trim($displayName), 2);
    if (count($parts) >= 2) {
        return strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1));
    }
    return strtoupper(mb_substr($displayName, 0, 2));
}

/**
 * Render a Tabler badge for a status.
 * @param string $label Status label
 * @param string|null $color Tabler color (e.g. 'blue', 'green', 'red', 'yellow'). Default 'secondary'
 * @return string HTML for badge
 */
function render_status_badge($label, $color = null) {
    $label = htmlspecialchars($label ?? '', ENT_QUOTES, 'UTF-8');
    if (empty($color)) {
        return '<span class="badge bg-secondary-lt">' . $label . '</span>';
    }
    // Hex colors: use inline style; Tabler names: use class
    if (strpos($color, '#') === 0) {
        $safe = htmlspecialchars($color, ENT_QUOTES, 'UTF-8');
        return '<span class="badge" style="background-color:' . $safe . '20;color:' . $safe . '">' . $label . '</span>';
    }
    $cls = 'bg-' . preg_replace('/[^a-z0-9_-]/i', '', $color) . '-lt';
    return '<span class="badge ' . $cls . '">' . $label . '</span>';
}

/**
 * Format a date for display.
 * @param string|null $date DB date (Y-m-d H:i:s or Y-m-d)
 * @param string $format Output format
 * @return string Formatted date or empty string
 */
function format_date($date, $format = 'd.m.Y') {
    if (empty($date)) return '';
    try {
        $dt = new DateTime($date);
        return $dt->format($format);
    } catch (Exception $e) {
        return '';
    }
}

/**
 * Format a datetime for display.
 * @param string|null $date DB datetime
 * @return string Formatted datetime
 */
function format_datetime($date) {
    return format_date($date, 'd.m.Y H:i');
}

/**
 * Build Mermaid flowchart from workflow transitions (shared with admin_workflow and ticket_detail).
 *
 * @param array[] $transitions Rows with current_status_id, next_status_id, flow_type_id, button_label, edge_type, type_code
 * @param array[] $statuses Rows with id, name, color_code (optional; used to style nodes)
 * @param int|null $flowTypeFilter Filter by flow_type_id (e.g. CUST or INT type id)
 * @param string $direction 'TB' (top-bottom) or 'LR' (left-right)
 * @param int|null $highlightStatusId If set and this status is in the diagram, add class for CSS highlight/animation
 * @param string|null $lockType Quality lock overlay: OBS, ONH, RED. When set, current status node gets lock-color class instead of default highlight.
 * @return string Mermaid diagram source
 */
function build_mermaid_flowchart($transitions, $statuses, $flowTypeFilter = null, $direction = 'TB', $highlightStatusId = null, $lockType = null) {
    $direction = ($direction === 'LR') ? 'LR' : 'TB';

    $filtered = $transitions;
    if ($flowTypeFilter !== null && $flowTypeFilter !== '') {
        $ft = (int) $flowTypeFilter;
        $filtered = array_filter($transitions, function ($t) use ($ft) {
            return (int) ($t['flow_type_id'] ?? 0) === $ft;
        });
    }

    $lines = ['flowchart ' . $direction];
    $usedStatusIds = [];
    foreach ($filtered as $t) {
        $usedStatusIds[(int) $t['current_status_id']] = true;
        $usedStatusIds[(int) $t['next_status_id']] = true;
    }
    $nodeIds = [];
    $idPad = strlen((string) max(array_keys($usedStatusIds ?: [1])));
    foreach ($statuses as $s) {
        $sid = (int) $s['id'];
        if (empty($usedStatusIds[$sid])) continue;
        $id = 's' . str_pad((string) $sid, max(2, $idPad), '0', STR_PAD_LEFT);
        $nodeIds[$sid] = $id;
        $label = preg_replace('/["\'\[\]{}()|\\\\]/u', ' ', $s['name'] ?? '');
        $lines[] = "    {$id}[\"" . trim(preg_replace('/\s+/', ' ', $label)) . "\"]";
    }

    if (empty($nodeIds)) {
        return "flowchart {$direction}\n    empty[\"Keine Transitions für diesen Typ\"]";
    }

    $seenEdges = [];
    $edgeStyles = [];
    $edgeIndex = 0;
    foreach ($filtered as $t) {
        $fromId = (int) $t['current_status_id'];
        $toId = (int) $t['next_status_id'];
        if (!isset($nodeIds[$fromId]) || !isset($nodeIds[$toId])) continue;
        $label = trim($t['button_label'] ?? '');
        $typeCode = strtoupper(trim($t['type_code'] ?? ''));
        if ($typeCode === 'INT' || $typeCode === 'INTERNAL') {
            $label = $label ? "{$label} (Internal)" : 'Internal';
        }
        $safe = preg_replace('/->|<-|=>|<=|#|"|\'|\[|\]|\{|\}|\(|\)|;|:|\||\\\\|\r|\n/', ' ', $label);
        $safe = trim(preg_replace('/\s+/', ' ', $safe));
        $edgeLabel = $safe !== '' ? "|" . $safe . "|" : "";
        $key = $nodeIds[$fromId] . '->' . $nodeIds[$toId] . $edgeLabel;
        if (isset($seenEdges[$key])) continue;
        $seenEdges[$key] = true;
        $lines[] = "    {$nodeIds[$fromId]} -->{$edgeLabel} {$nodeIds[$toId]}";
        $et = $t['edge_type'] ?? 'normal';
        if ($et === 'fallback') $edgeStyles[$edgeIndex] = '#dc3545';
        elseif ($et === 'success') $edgeStyles[$edgeIndex] = '#22c55e';
        $edgeIndex++;
    }
    foreach ($edgeStyles as $idx => $hex) {
        $lines[] = "    linkStyle {$idx} stroke:{$hex},stroke-width:2px";
    }

    // Style each node with ticket_statuses.color_code (hex); fallback light gray if empty
    foreach ($statuses as $s) {
        $sid = (int) $s['id'];
        if (!isset($nodeIds[$sid])) continue;
        $nodeId = $nodeIds[$sid];
        $color = trim($s['color_code'] ?? '');
        if ($color !== '' && strpos($color, '#') === 0) {
            $stroke = $color;
        } else {
            $color = '#e9ecef';
            $stroke = '#adb5bd';
        }
        $lines[] = "    style {$nodeId} fill:{$color},stroke:{$stroke},stroke-width:2px";
    }

    if ($highlightStatusId !== null && isset($nodeIds[(int) $highlightStatusId])) {
        $nodeId = $nodeIds[(int) $highlightStatusId];
        $lockType = $lockType ? strtoupper(trim($lockType)) : '';
        if (in_array($lockType, ['OBS', 'ONH', 'RED'], true)) {
            $lockClass = 'currentStatusLock' . $lockType;
            $lockStyles = [
                'OBS' => 'fill:#fef2f2,stroke:#dc2626,stroke-width:3px',
                'ONH' => 'fill:#fff7ed,stroke:#ea580c,stroke-width:3px',
                'RED' => 'fill:#faf5ff,stroke:#7c3aed,stroke-width:3px',
            ];
            $lines[] = "    classDef {$lockClass} " . $lockStyles[$lockType];
            $lines[] = "    class {$nodeId} {$lockClass}";
        } else {
            $lines[] = "    classDef currentStatusHighlight fill:#e3f2fd,stroke:#1976d2,stroke-width:3px";
            $lines[] = "    class {$nodeId} currentStatusHighlight";
        }
    }

    return implode("\n", $lines);
}

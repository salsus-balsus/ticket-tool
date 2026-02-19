<?php
/**
 * Admin: List comment authors not matched by abbreviation map. Set display name per comment.
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';

$knownMap = require __DIR__ . '/includes/author_map.php';
$knownKeys = array_keys($knownMap);
$knownValues = array_values($knownMap);
$overrides = [];
$unmapped = [];
$dbError = null;
$msg = '';

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS author_display (
            author_raw VARCHAR(255) NOT NULL PRIMARY KEY,
            display_name VARCHAR(255) NOT NULL,
            updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS comment_author_override (
            comment_id INT UNSIGNED NOT NULL PRIMARY KEY,
            display_name VARCHAR(255) NOT NULL,
            updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP
        )
    ");
} catch (PDOException $e) {
    // tables may already exist
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['set_comment_display'])) {
        $commentId = (int) ($_POST['comment_id'] ?? 0);
        $displayName = trim($_POST['display_name'] ?? '');
        if ($commentId > 0 && $displayName !== '') {
            try {
                $stmt = $pdo->prepare("INSERT INTO comment_author_override (comment_id, display_name) VALUES (?, ?) ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), updated_at = CURRENT_TIMESTAMP");
                $stmt->execute([$commentId, $displayName]);
                $msg = 'Display name set for this comment.';
            } catch (PDOException $e) {
                $msg = 'Error: ' . $e->getMessage();
            }
        }
    }
    if (isset($_POST['clear_comment_display'])) {
        $commentId = (int) ($_POST['comment_id'] ?? 0);
        if ($commentId > 0) {
            try {
                $stmt = $pdo->prepare("DELETE FROM comment_author_override WHERE comment_id = ?");
                $stmt->execute([$commentId]);
                $msg = 'Override cleared for this comment.';
            } catch (PDOException $e) {
                $msg = 'Error: ' . $e->getMessage();
            }
        }
    }
    if ($msg) {
        header('Location: admin_comment_authors.php?msg=' . urlencode($msg));
        exit;
    }
}

try {
    $overrides = $pdo->query("SELECT author_raw, display_name FROM author_display")->fetchAll(PDO::FETCH_ASSOC);
    $knownSet = array_flip(array_merge($knownKeys, $knownValues));
    foreach ($overrides as $r) {
        $knownSet[$r['author_raw']] = true;
    }

    $allAuthors = $pdo->query("SELECT DISTINCT author FROM ticket_comments WHERE TRIM(author) != '' ORDER BY author")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($allAuthors as $a) {
        $a = trim($a);
        if ($a === '') continue;
        if (!isset($knownSet[$a])) {
            $unmapped[] = $a;
        }
    }

    $commentCounts = [];
    $commentsByAuthor = [];
    $commentOverrides = [];
    if (!empty($unmapped)) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ticket_comments WHERE author = ?");
        foreach ($unmapped as $u) {
            $stmt->execute([$u]);
            $commentCounts[$u] = (int) $stmt->fetchColumn();
        }
        $commentsStmt = $pdo->prepare("
            SELECT tc.id AS comment_id, tc.ticket_id, tc.comment_text, tc.created_at,
                   t.title AS ticket_title, t.ticket_no
            FROM ticket_comments tc
            LEFT JOIN tickets t ON tc.ticket_id = t.id
            WHERE tc.author = ?
            ORDER BY tc.created_at DESC
        ");
        foreach ($unmapped as $u) {
            $commentsStmt->execute([$u]);
            $commentsByAuthor[$u] = $commentsStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        $allCommentIds = [];
        foreach ($commentsByAuthor as $list) {
            foreach ($list as $c) {
                $allCommentIds[] = (int) $c['comment_id'];
            }
        }
        if (!empty($allCommentIds)) {
            $placeholders = implode(',', array_fill(0, count($allCommentIds), '?'));
            $stmt = $pdo->prepare("SELECT comment_id, display_name FROM comment_author_override WHERE comment_id IN ($placeholders)");
            $stmt->execute($allCommentIds);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $commentOverrides[(int) $row['comment_id']] = $row['display_name'];
            }
        }
    }
} catch (PDOException $e) {
    $dbError = $e->getMessage();
}
$pageTitle = 'Comment authors (unmapped) – Admin';
require_once 'includes/header.php';
?>
        <div class="page-wrapper">
            <div class="page-body">
                <div class="container-xl">
                    <?php if (isset($_GET['msg'])): ?>
                    <div class="alert alert-info alert-dismissible"><?= e($_GET['msg']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>
                    <?php if ($dbError): ?>
                    <div class="alert alert-danger">Database error: <?= e($dbError) ?></div>
                    <?php endif; ?>

                    <div class="page-header d-print-none mb-4">
                        <h1 class="page-title">Comment authors (unmapped)</h1>
                        <div class="text-muted">“Who commented?” – Authors not matched by the abbreviation map. Set display name per comment below.</div>
                    </div>

                    <div class="card mb-4">
                        <div class="card-header">
                            <h3 class="card-title">Unmapped authors</h3>
                        </div>
                        <div class="card-body">
                            <?php if (empty($unmapped)): ?>
                            <p class="text-success mb-0">All comment authors are matched via the abbreviation map or overrides.</p>
                            <?php else: ?>
                            <p class="text-muted mb-3">These authors appear in comments but are not in the abbreviation map. Set the display name per comment (e.g. “Unknown” or a full name) for each comment individually.</p>
                            <ul class="list-group list-group-flush">
                                <?php foreach ($unmapped as $author): ?>
                                <li class="list-group-item">
                                    <div class="mb-2">
                                        <strong><?= e($author) ?></strong>
                                        <span class="text-muted">(<?= (int)($commentCounts[$author] ?? 0) ?> comment(s))</span>
                                    </div>
                                    <?php
                                    $comments = $commentsByAuthor[$author] ?? [];
                                    if (!empty($comments)):
                                    ?>
                                    <div class="ps-2 border-start border-2 border-secondary">
                                        <ul class="list-unstyled mb-0">
                                            <?php foreach ($comments as $c):
                                                $cid = (int) $c['comment_id'];
                                                $ticketId = (int) $c['ticket_id'];
                                                $ticketLabel = $c['ticket_no'] ? '#' . $c['ticket_no'] : 'Ticket #' . $ticketId;
                                                $ticketTitle = $c['ticket_title'] ?? '';
                                                $excerpt = mb_substr($c['comment_text'] ?? '', 0, 80);
                                                if (mb_strlen($c['comment_text'] ?? '') > 80) $excerpt .= '…';
                                                $currentOverride = $commentOverrides[$cid] ?? null;
                                            ?>
                                            <li class="comment-row mb-3 pb-2 border-bottom border-light d-flex flex-wrap align-items-start gap-2">
                                                <div class="comment-content flex-grow-1 min-w-0">
                                                    <div class="ticket-line mb-1">
                                                        <a href="ticket_detail.php?id=<?= $ticketId ?>" class="ticket-link"><?= e($ticketLabel) ?><?= $ticketTitle !== '' ? ' – ' . e($ticketTitle) : '' ?></a>
                                                        <span class="text-muted">(<?= format_datetime($c['created_at']) ?>)</span>
                                                        <?php if ($currentOverride !== null): ?>
                                                        <span class="badge bg-success-lt ms-1">Display: <?= e($currentOverride) ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="comment-excerpt text-body-secondary"><?= e($excerpt) ?></div>
                                                </div>
                                                <div class="comment-form-row d-flex align-items-center gap-2 flex-shrink-0">
                                                    <form method="post" class="d-inline-flex align-items-center gap-2">
                                                        <input type="hidden" name="comment_id" value="<?= $cid ?>">
                                                        <input type="text" name="display_name" class="form-control form-control-sm" placeholder="e.g. Unknown or full name" style="width:160px;" value="<?= e($currentOverride ?? 'Unknown') ?>">
                                                        <button type="submit" name="set_comment_display" class="btn btn-sm btn-primary">Set for this comment</button>
                                                    </form>
                                                    <?php if ($currentOverride !== null): ?>
                                                    <form method="post" class="d-inline" onsubmit="return confirm('Clear override for this comment?');">
                                                        <input type="hidden" name="comment_id" value="<?= $cid ?>">
                                                        <button type="submit" name="clear_comment_display" class="btn btn-sm btn-outline-secondary">Clear override</button>
                                                    </form>
                                                    <?php endif; ?>
                                                </div>
                                            </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                    <?php endif; ?>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Global overrides (author_display)</h3>
                        </div>
                        <div class="card-body">
                            <?php if (empty($overrides)): ?>
                            <p class="text-muted mb-0">No global overrides. Use “Set for this comment” above to set display names per comment.</p>
                            <?php else: ?>
                            <table class="table table-sm">
                                <thead><tr><th>Author (raw)</th><th>Display name</th></tr></thead>
                                <tbody>
                                    <?php foreach ($overrides as $o): ?>
                                    <tr>
                                        <td><?= e($o['author_raw']) ?></td>
                                        <td><?= e($o['display_name']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <style>
    .comment-row .comment-excerpt { font-size: 0.875rem; display: block; }
    .comment-row .comment-form-row { font-size: 0.875rem; }
    .comment-row .comment-form-row .form-control,
    .comment-row .comment-form-row .btn { font-size: 0.875rem; }
    .comment-row .ticket-link { font-size: 1.05rem; font-weight: 600; }
    </style>
    <?php require_once 'includes/footer.php'; ?>
</body>
</html>

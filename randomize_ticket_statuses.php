<?php
/**
 * randomize_ticket_statuses.php
 * Randomizes status_id of all tickets (for testing workflow transitions).
 * GET: confirmation page. POST with confirm=1: run and redirect to tickets.php.
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';

$done = false;
$error = null;
$updated = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['confirm'])) {
    try {
        $statusIds = $pdo->query("SELECT id FROM ticket_statuses ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        if (empty($statusIds)) {
            $error = 'Keine Stati in ticket_statuses gefunden.';
        } else {
            $ticketIds = $pdo->query("SELECT id FROM tickets")->fetchAll(PDO::FETCH_COLUMN);
            $stmt = $pdo->prepare("UPDATE tickets SET status_id = ? WHERE id = ?");
            foreach ($ticketIds as $tid) {
                $newStatus = $statusIds[array_rand($statusIds)];
                $stmt->execute([$newStatus, (int) $tid]);
                $updated++;
            }
            header('Location: tickets.php?randomized=1&count=' . (int) $updated);
            exit;
        }
    } catch (PDOException $e) {
        $error = $e->getMessage();
    }
}

$pageTitle = 'Stati randomisieren';
require_once 'includes/header.php';
?>
<div class="page-wrapper">
    <div class="page-body">
        <div class="container-xl">
            <div class="page-header d-print-none mb-4">
                <h1 class="page-title">Ticket-Stati zufällig verteilen</h1>
                <div class="text-muted">Setzt bei allen Tickets einen zufälligen Status (für Tests der Workflow-Übergänge).</div>
            </div>

            <?php if ($error): ?>
            <div class="alert alert-danger"><?= e($error) ?></div>
            <?php endif; ?>

            <div class="card">
                <div class="card-body">
                    <p class="mb-0">Möchtest du bei <strong>allen</strong> Tickets den Status zufällig auf einen der vorhandenen Stati setzen? Diese Aktion eignet sich, um rollen- und statusbasierte Workflow-Übergänge zu testen.</p>
                </div>
                <div class="card-footer">
                    <form method="post" action="randomize_ticket_statuses.php" class="d-flex gap-2">
                        <input type="hidden" name="confirm" value="1"/>
                        <button type="submit" class="btn btn-warning">Ja, Stati randomisieren</button>
                        <a href="tickets.php" class="btn btn-outline-secondary">Abbrechen</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require_once 'includes/footer.php'; ?>

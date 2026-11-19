<?php
// api/review_property.php: admin approves or rejects a pending listing.
// POST JSON: {"property_id": 5, "decision": "approve" | "reject", "notes": "..."}
// Approving also calculates the commute estimates for that property.
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib_commute.php';

try {
    $admin = require_login(['admin']);
    $in = read_json();
    $pid = (int)($in['property_id'] ?? 0);
    $decision = (string)($in['decision'] ?? '');
    $notes = trim((string)($in['notes'] ?? ''));

    if ($pid < 1 || !in_array($decision, ['approve', 'reject'], true)) {
        json_out(['ok' => false, 'error' => 'Invalid request.'], 422);
    }
    if (mb_strlen($notes) > 500) {
        json_out(['ok' => false, 'error' => 'Keep the note under 500 characters.'], 422);
    }
    if ($decision === 'reject' && mb_strlen($notes) < 10) {
        json_out(['ok' => false, 'error' => 'Tell the owner why the listing was not approved (at least 10 characters).'], 422);
    }

    $approve = ($decision === 'approve');
    $pdo = db();
    $pdo->beginTransaction();

    // Only a listing that is still pending can be reviewed (stops double review).
    $up = $pdo->prepare("UPDATE properties SET status = ?, is_verified = ? WHERE id = ? AND status = 'pending'");
    $up->execute([$approve ? 'approved' : 'rejected', $approve ? 1 : 0, $pid]);
    if ($up->rowCount() !== 1) {
        $pdo->rollBack();
        json_out(['ok' => false, 'error' => 'This listing was already reviewed, or it does not exist.'], 409);
    }
    $pdo->prepare('INSERT INTO listing_reviews (property_id, admin_id, decision, notes) VALUES (?, ?, ?, ?)')
        ->execute([$pid, $admin['id'], $approve ? 'approved' : 'rejected', $notes !== '' ? $notes : null]);
    $pdo->commit();

    // After approval, work out commute estimates. A failure here never undoes the approval.
    $commute = [];
    if ($approve) {
        try {
            set_time_limit(120);
            $p = $pdo->prepare('SELECT id, name, latitude, longitude FROM properties WHERE id = ?');
            $p->execute([$pid]);
            $prop = $p->fetch();
            $dests = $pdo->query('SELECT id, slug, short_name, latitude, longitude FROM destinations ORDER BY id')->fetchAll();
            $commute = compute_commutes_for_property($pdo, $prop, $dests);
        } catch (Throwable $e) {
            error_log('review_property.php commute step: ' . $e->getMessage());
        }
    }
    json_out(['ok' => true, 'status' => $approve ? 'approved' : 'rejected', 'commute' => $commute]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('review_property.php: ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'Could not save the review.'], 500);
}

<?php
// api/booking_action.php: POST JSON {"booking_id": 5, "action": "accept"|"decline"|"cancel"|"viewed", "note": "..."}
//  accept / decline : the property's owner, while the request is pending
//  cancel           : the person who sent it, while pending or accepted
//  viewed           : either side, once accepted. Records "we have seen the room".
require __DIR__ . '/bootstrap.php';
try {
    $u = require_login();
    $in = read_json();
    $id = (int)($in['booking_id'] ?? 0);
    $action = (string)($in['action'] ?? '');
    $note = trim((string)($in['note'] ?? ''));
    if (mb_strlen($note) > 300) json_out(['ok' => false, 'error' => 'Keep the note under 300 characters.'], 422);

    $pdo = db();
    $st = $pdo->prepare("SELECT b.id, b.user_id, b.status, p.owner_id FROM bookings b JOIN properties p ON p.id = b.property_id WHERE b.id = ?");
    $st->execute([$id]);
    $b = $st->fetch();
    if (!$b) json_out(['ok' => false, 'error' => 'Request not found.'], 404);

    $isStudent = (int)$b['user_id'] === (int)$u['id'];
    $isOwner = ($b['owner_id'] !== null && (int)$b['owner_id'] === (int)$u['id'])
            || ($b['owner_id'] === null && $u['role'] === 'admin');

    $deny = fn() => json_out(['ok' => false, 'error' => 'You cannot do that with this request.'], 403);
    $stale = fn() => json_out(['ok' => false, 'error' => 'This request has already changed. Reload the page.'], 409);

    if ($action === 'accept' || $action === 'decline') {
        if (!$isOwner) $deny();
        $new = $action === 'accept' ? 'accepted' : 'declined';
        $up = $pdo->prepare("UPDATE bookings SET status = ?, owner_note = ?, responded_at = NOW() WHERE id = ? AND status = 'pending'");
        $up->execute([$new, $note !== '' ? $note : null, $id]);
        if ($up->rowCount() !== 1) $stale();
    } elseif ($action === 'cancel') {
        if (!$isStudent) $deny();
        $up = $pdo->prepare("UPDATE bookings SET status = 'cancelled' WHERE id = ? AND status IN ('pending','accepted')");
        $up->execute([$id]);
        if ($up->rowCount() !== 1) $stale();
    } elseif ($action === 'viewed') {
        if (!$isStudent && !$isOwner) $deny();
        $col = $isStudent ? 'student_viewed_at' : 'owner_viewed_at';
        $up = $pdo->prepare("UPDATE bookings SET $col = COALESCE($col, NOW()) WHERE id = ? AND status = 'accepted'");
        $up->execute([$id]);
        if ($up->rowCount() < 1) {
            $chk = $pdo->prepare("SELECT status FROM bookings WHERE id = ?"); $chk->execute([$id]);
            if ($chk->fetchColumn() !== 'accepted') $stale();
        }
    } else {
        json_out(['ok' => false, 'error' => 'Unknown action.'], 422);
    }
    json_out(['ok' => true]);
} catch (Throwable $e) {
    error_log('booking_action.php: ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'Could not update the request.'], 500);
}

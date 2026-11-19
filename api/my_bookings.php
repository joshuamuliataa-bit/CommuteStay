<?php
// api/my_bookings.php: GET ?as=student (default) or ?as=owner
// student: requests I sent.  owner: requests for properties I own.
require __DIR__ . '/bootstrap.php';
try {
    $u = require_login();
    $as = ($_GET['as'] ?? 'student') === 'owner' ? 'owner' : 'student';
    if ($as === 'owner' && !in_array($u['role'], ['owner', 'admin'], true)) json_out(['ok' => false, 'error' => 'Not allowed.'], 403);
    $pdo = db();

    // Requests nobody answered for 7 days expire on their own
    $pdo->exec("UPDATE bookings SET status = 'expired' WHERE status = 'pending' AND created_at < (NOW() - INTERVAL 7 DAY)");

    $cols = "b.id, b.status, b.check_in, b.check_out, b.guests, b.message, b.created_at, b.responded_at, b.owner_note,
             b.travel_mode, b.snapshot_distance_m, b.snapshot_duration_s, b.snapshot_at,
             b.student_viewed_at, b.owner_viewed_at,
             p.id AS property_id, p.name AS property_name, p.contact_phone,
             d.short_name AS destination, i.name AS intake_name, i.starts_on AS intake_starts,
             (SELECT ph.file_path FROM property_photos ph WHERE ph.property_id = p.id
                ORDER BY ph.is_primary DESC, ph.sort_order, ph.id LIMIT 1) AS photo";
    $joins = "FROM bookings b
              JOIN properties p ON p.id = b.property_id
              LEFT JOIN destinations d ON d.id = b.destination_id
              LEFT JOIN intakes i ON i.id = b.intake_id";

    if ($as === 'student') {
        $st = $pdo->prepare("SELECT $cols, o.full_name AS owner_name $joins
                             LEFT JOIN users o ON o.id = p.owner_id
                             WHERE b.user_id = ? ORDER BY b.created_at DESC LIMIT 100");
    } else {
        $st = $pdo->prepare("SELECT $cols, s.full_name AS student_name, s.email AS student_email $joins
                             JOIN users s ON s.id = b.user_id
                             WHERE p.owner_id = ? ORDER BY b.created_at DESC LIMIT 100");
    }
    $st->execute([$u['id']]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['property_id'] = (int)$r['property_id'];
        if ($as === 'student' && !in_array($r['status'], ['pending', 'accepted'], true)) $r['contact_phone'] = null;
        if ($as === 'owner') unset($r['contact_phone']);
    }
    json_out(['ok' => true, 'as' => $as, 'count' => count($rows), 'bookings' => $rows]);
} catch (Throwable $e) {
    error_log('my_bookings.php: ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'Could not load requests.'], 500);
}

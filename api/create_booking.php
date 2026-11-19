<?php
// api/create_booking.php: a logged-in user sends a stay request.
// The request stores the commute estimate the user was shown (a snapshot),
// so both sides can see the same numbers later. No payment is involved.
require __DIR__ . '/bootstrap.php';
date_default_timezone_set('Africa/Lusaka');

const MODE_WORDS = ['walking' => 'walking', 'cycling' => 'cycling', 'driving' => 'by car'];

function fail(string $m, int $c = 422) { json_out(['ok' => false, 'error' => $m], $c); }
function valid_date($v) {
    $d = DateTime::createFromFormat('!Y-m-d', (string)$v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : null;
}

try {
    $user = require_login();
    $in = read_json();
    $pdo = db();

    $pid = (int)($in['property_id'] ?? 0);
    $st = $pdo->prepare("SELECT p.id, p.name, p.owner_id, p.contact_phone, u.full_name AS owner_name
                         FROM properties p LEFT JOIN users u ON u.id = p.owner_id
                         WHERE p.id = ? AND p.status = 'approved'");
    $st->execute([$pid]);
    $p = $st->fetch();
    if (!$p) fail('This listing is not available.', 404);
    if ((int)$p['owner_id'] === (int)$user['id']) fail('You cannot request a stay at your own property.', 403);

    $today = date('Y-m-d');
    $in_ = valid_date($in['check_in'] ?? '');
    if (!$in_) fail('Choose your move-in date.');
    if ($in_ < $today) fail('The move-in date cannot be in the past.');
    $out_ = ($in['check_out'] ?? '') === '' ? null : valid_date($in['check_out']);
    if (($in['check_out'] ?? '') !== '' && !$out_) fail('Enter a valid move-out date, or leave it empty.');
    if ($out_ && $out_ <= $in_) fail('The move-out date must be after the move-in date.');

    $guests = (int)($in['guests'] ?? 1);
    if ($guests < 1 || $guests > 6) fail('Guests must be between 1 and 6.');
    $msg = trim((string)($in['message'] ?? ''));
    if (mb_strlen($msg) > 500) fail('Keep your message under 500 characters.');

    // Destination, travel mode and snapshot
    $dest = null; $mode = null; $snap = null;
    $slug = strtolower(trim((string)($in['destination'] ?? '')));
    if ($slug !== '') {
        $d = $pdo->prepare('SELECT id, short_name FROM destinations WHERE slug = ?');
        $d->execute([$slug]);
        $dest = $d->fetch();
        if (!$dest) fail('Unknown destination.');
        $mode = (string)($in['mode'] ?? '');
        if (!isset(MODE_WORDS[$mode])) fail('Choose how you will travel.');
        $s = $pdo->prepare("SELECT route_distance_m, duration_s, calculated_at, routing_service
                            FROM commute_estimates
                            WHERE property_id = ? AND destination_id = ? AND travel_mode = ?
                              AND is_current = 1 AND validation_status = 'passed' LIMIT 1");
        $s->execute([$pid, $dest['id'], $mode]);
        $snap = $s->fetch() ?: null;
    }

    // Intake (optional) must belong to the chosen destination
    $intakeId = null;
    if (!empty($in['intake_id'])) {
        $i = $pdo->prepare('SELECT id, destination_id FROM intakes WHERE id = ?');
        $i->execute([(int)$in['intake_id']]);
        $row = $i->fetch();
        if (!$row || !$dest || (int)$row['destination_id'] !== (int)$dest['id']) fail('That intake does not match the chosen destination.');
        $intakeId = (int)$row['id'];
    }

    // One live request per property, and no more than 5 waiting at once
    $dup = $pdo->prepare("SELECT id FROM bookings WHERE user_id = ? AND property_id = ? AND status IN ('pending','accepted')");
    $dup->execute([$user['id'], $pid]);
    if ($dup->fetch()) fail('You already have a request for this property. See My requests.', 409);
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE user_id = ? AND status = 'pending'");
    $cnt->execute([$user['id']]);
    if ((int)$cnt->fetchColumn() >= 5) fail('You have 5 requests waiting for a reply. Wait for a reply or cancel one first.', 429);

    $pdo->prepare("INSERT INTO bookings
        (user_id, property_id, destination_id, intake_id, travel_mode, check_in, check_out, guests, message,
         snapshot_distance_m, snapshot_duration_s, snapshot_at, snapshot_service, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')")
        ->execute([$user['id'], $pid, $dest['id'] ?? null, $intakeId, $mode, $in_, $out_, $guests,
                   $msg !== '' ? $msg : null,
                   $snap['route_distance_m'] ?? null, $snap['duration_s'] ?? null,
                   $snap['calculated_at'] ?? null, $snap['routing_service'] ?? null]);
    $bid = (int)$pdo->lastInsertId();

    // WhatsApp hand-off: a ready-written message that carries the same numbers
    $text = "Hello, I'm {$user['full_name']}. I found \"{$p['name']}\" on CommuteStay and sent a stay request from {$in_}"
          . ($out_ ? " to {$out_}" : '') . " for {$guests} guest" . ($guests > 1 ? 's' : '') . '.';
    if ($snap && $dest) {
        $text .= sprintf(' CommuteStay showed about %d min %s (%.1f km) to %s, a map estimate from %s.',
            max(1, (int)round($snap['duration_s'] / 60)), MODE_WORDS[$mode],
            $snap['route_distance_m'] / 1000, $dest['short_name'], substr((string)$snap['calculated_at'], 0, 10));
    }
    $text .= ' Could we arrange a viewing before any payment? Thank you.';
    $digits = preg_replace('/\D/', '', (string)$p['contact_phone']);

    json_out(['ok' => true, 'booking_id' => $bid,
        'whatsapp_url' => $digits ? 'https://wa.me/' . $digits . '?text=' . rawurlencode($text) : null,
        'owner_phone' => $p['contact_phone'],
        'snapshot' => $snap ? ['distance_m' => (int)$snap['route_distance_m'], 'duration_s' => (int)$snap['duration_s'],
                               'calculated_at' => $snap['calculated_at'], 'mode' => $mode] : null]);
} catch (Throwable $e) {
    error_log('create_booking.php: ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'Could not send your request. Please try again.'], 500);
}

<?php
// api/listing.php?id=N: everything the property detail page needs.
// Only approved listings are public. The phone number is shown to logged-in users only.
require __DIR__ . '/bootstrap.php';
try {
    $id = (int)($_GET['id'] ?? 0);
    if ($id < 1) json_out(['ok' => false, 'error' => 'Listing not found.'], 404);
    $pdo = db();

    $st = $pdo->prepare(
        "SELECT p.id, p.owner_id, p.name, p.type, p.area, p.address, p.contact_phone, p.description,
                p.price, p.price_unit, p.latitude, p.longitude, p.is_verified, p.created_at,
                u.full_name AS owner_name, pa.available_units, pa.available_from
         FROM properties p
         LEFT JOIN users u ON u.id = p.owner_id
         LEFT JOIN property_availability pa ON pa.property_id = p.id
         WHERE p.id = ? AND p.status = 'approved'"
    );
    $st->execute([$id]);
    $p = $st->fetch();
    if (!$p) json_out(['ok' => false, 'error' => 'This listing is not available.'], 404);

    $ph = $pdo->prepare('SELECT file_path, caption FROM property_photos WHERE property_id = ? ORDER BY is_primary DESC, sort_order, id');
    $ph->execute([$id]);
    $photos = $ph->fetchAll();

    $fc = $pdo->prepare('SELECT facility FROM property_facilities WHERE property_id = ? ORDER BY id');
    $fc->execute([$id]);
    $facilities = array_column($fc->fetchAll(), 'facility');

    // One row per destination; the estimate is null until it has been calculated and has passed checks.
    $cm = $pdo->prepare(
        "SELECT d.id AS did, d.slug, d.name, d.short_name, d.latitude, d.longitude,
                ce.travel_mode, ce.route_distance_m, ce.duration_s, ce.calculated_at
         FROM destinations d
         LEFT JOIN commute_estimates ce
           ON ce.destination_id = d.id AND ce.property_id = ? AND ce.is_current = 1
          AND ce.validation_status = 'passed'
         ORDER BY d.id"
    );
    $cm->execute([$id]);
    $byDest = [];
    foreach ($cm->fetchAll() as $r) {
        $k = (int)$r['did'];
        if (!isset($byDest[$k])) {
            $byDest[$k] = [
                'slug' => $r['slug'], 'name' => $r['name'], 'short_name' => $r['short_name'],
                'latitude' => (float)$r['latitude'], 'longitude' => (float)$r['longitude'],
                'estimates' => ['walking' => null, 'cycling' => null, 'driving' => null],
            ];
        }
        if ($r['travel_mode'] !== null) {
            $byDest[$k]['estimates'][$r['travel_mode']] = [
                'distance_m' => (int)$r['route_distance_m'], 'duration_s' => (int)$r['duration_s'],
                'calculated_at' => $r['calculated_at'],
            ];
        }
    }
    $commute = array_values($byDest);

    $user = current_user();
    $first = $p['owner_name'] ? explode(' ', trim($p['owner_name']))[0] : null;

    json_out(['ok' => true, 'listing' => [
        'id' => (int)$p['id'], 'name' => $p['name'], 'type' => $p['type'], 'area' => $p['area'],
        'address' => $p['address'], 'description' => $p['description'],
        'price' => (float)$p['price'], 'price_unit' => $p['price_unit'],
        'latitude' => (float)$p['latitude'], 'longitude' => (float)$p['longitude'],
        'is_verified' => (bool)$p['is_verified'],
        'availability' => $p['available_units'] === null ? null : [
            'units' => (int)$p['available_units'], 'from' => $p['available_from'],
        ],
        'photos' => $photos, 'facilities' => $facilities, 'commute' => $commute,
        'contact' => [
            'logged_in' => (bool)$user,
            'owner_first_name' => $first,
            'phone' => $user ? $p['contact_phone'] : null,
        ],
        'viewer' => [
            'logged_in' => (bool)$user,
            'role' => $user['role'] ?? null,
            'is_own' => $user && $p['owner_id'] !== null && (int)$p['owner_id'] === (int)$user['id'],
        ],
    ]]);
} catch (Throwable $e) {
    error_log('listing.php: ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'Could not load this listing.'], 500);
}

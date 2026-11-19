<?php
// api/admin_listings.php: admin-only list of submissions for review.
// GET ?status=pending|approved|rejected  (default pending)
require __DIR__ . '/bootstrap.php';
try {
    require_login(['admin']);
    $status = $_GET['status'] ?? 'pending';
    if (!in_array($status, ['pending', 'approved', 'rejected'], true)) $status = 'pending';
    $pdo = db();

    $st = $pdo->prepare(
        "SELECT p.id, p.name, p.type, p.area, p.address, p.contact_phone, p.description,
                p.price, p.price_unit, p.latitude, p.longitude, p.status, p.is_verified, p.created_at,
                u.full_name AS owner_name, u.email AS owner_email,
                pa.available_units, pa.available_from,
                (SELECT lr.notes FROM listing_reviews lr WHERE lr.property_id = p.id
                   ORDER BY lr.reviewed_at DESC, lr.id DESC LIMIT 1) AS review_note
         FROM properties p
         LEFT JOIN users u ON u.id = p.owner_id
         LEFT JOIN property_availability pa ON pa.property_id = p.id
         WHERE p.status = ?
         ORDER BY p.created_at ASC, p.id ASC LIMIT 50"
    );
    $st->execute([$status]);
    $rows = $st->fetchAll();

    $byId = [];
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['price'] = (float)$r['price'];
        $r['is_verified'] = (bool)$r['is_verified'];
        $r['photos'] = [];
        $r['facilities'] = [];
        $byId[$r['id']] = &$r;
        unset($r);
    }
    if ($byId) {
        $in = implode(',', array_fill(0, count($byId), '?'));
        $ids = array_keys($byId);
        $ph = $pdo->prepare("SELECT property_id, file_path FROM property_photos WHERE property_id IN ($in) ORDER BY is_primary DESC, sort_order, id");
        $ph->execute($ids);
        foreach ($ph->fetchAll() as $x) $byId[(int)$x['property_id']]['photos'][] = $x['file_path'];
        $fc = $pdo->prepare("SELECT property_id, facility FROM property_facilities WHERE property_id IN ($in) ORDER BY id");
        $fc->execute($ids);
        foreach ($fc->fetchAll() as $x) $byId[(int)$x['property_id']]['facilities'][] = $x['facility'];
    }
    json_out(['ok' => true, 'status' => $status, 'count' => count($rows), 'listings' => array_values($byId)]);
} catch (Throwable $e) {
    error_log('admin_listings.php: ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'Could not load listings.'], 500);
}

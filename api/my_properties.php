<?php
// api/my_properties.php: the logged-in owner's own submissions and their status.
require __DIR__ . '/bootstrap.php';
try {
    $u = require_login(['owner', 'admin']);
    $st = db()->prepare(
        "SELECT p.id, p.name, p.type, p.area, p.price, p.price_unit, p.status, p.created_at,
            (SELECT ph.file_path FROM property_photos ph WHERE ph.property_id = p.id
               ORDER BY ph.is_primary DESC, ph.sort_order, ph.id LIMIT 1) AS photo,
            (SELECT lr.notes FROM listing_reviews lr WHERE lr.property_id = p.id
               ORDER BY lr.reviewed_at DESC, lr.id DESC LIMIT 1) AS review_note
         FROM properties p WHERE p.owner_id = ? ORDER BY p.created_at DESC, p.id DESC"
    );
    $st->execute([$u['id']]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['price'] = (float)$r['price']; }
    json_out(['ok' => true, 'count' => count($rows), 'properties' => $rows]);
} catch (Throwable $e) {
    error_log('my_properties.php: ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'Could not load your listings.'], 500);
}

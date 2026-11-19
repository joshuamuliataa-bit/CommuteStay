<?php
// api/featured.php: approved listings with primary photo, facilities and
// (if one exists) the latest passed, current commute estimate.
// Returns JSON only. Needs tables from commutestay_schema_v2.sql.

header('Content-Type: application/json; charset=utf-8');

// --- Database settings: XAMPP defaults. If your other api files load a
// shared config (e.g. config.php), replace this block with that include. ---
$DB_HOST = 'localhost';
$DB_NAME = 'commutestay';
$DB_USER = 'root';
$DB_PASS = '';

try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    $rows = $pdo->query(
        "SELECT p.id, p.name, p.type, p.area, p.price, p.price_unit, p.is_verified,
            (SELECT ph.file_path FROM property_photos ph WHERE ph.property_id = p.id
               ORDER BY ph.is_primary DESC, ph.sort_order, ph.id LIMIT 1) AS photo,
            (SELECT ph.caption FROM property_photos ph WHERE ph.property_id = p.id
               ORDER BY ph.is_primary DESC, ph.sort_order, ph.id LIMIT 1) AS photo_caption,
            (SELECT GROUP_CONCAT(f.facility ORDER BY f.id SEPARATOR '|')
               FROM property_facilities f WHERE f.property_id = p.id) AS facilities
         FROM properties p
         WHERE p.status = 'approved'
         ORDER BY p.id
         LIMIT 6"
    )->fetchAll();

    $commute = $pdo->prepare(
        "SELECT d.short_name, ce.travel_mode, ce.route_distance_m, ce.duration_s, ce.calculated_at
         FROM commute_estimates ce
         JOIN destinations d ON d.id = ce.destination_id
         WHERE ce.property_id = ? AND ce.is_current = 1 AND ce.validation_status = 'passed'
           AND ce.travel_mode = 'driving'
         ORDER BY ce.calculated_at DESC LIMIT 1"
    );
    $walk = $pdo->prepare(
        "SELECT ce.route_distance_m, ce.duration_s
         FROM commute_estimates ce
         JOIN destinations d ON d.id = ce.destination_id
         WHERE ce.property_id = ? AND d.short_name = ? AND ce.is_current = 1
           AND ce.validation_status = 'passed' AND ce.travel_mode = 'walking'
         ORDER BY ce.calculated_at DESC LIMIT 1"
    );

    $listings = [];
    foreach ($rows as $r) {
        $commute->execute([$r['id']]);
        $c = $commute->fetch();
        $w = null;
        if ($c) { $walk->execute([$r['id'], $c['short_name']]); $w = $walk->fetch() ?: null; }
        $listings[] = [
            'id'            => (int)$r['id'],
            'name'          => $r['name'],
            'type'          => $r['type'],
            'area'          => $r['area'],
            'price'         => (float)$r['price'],
            'price_unit'    => $r['price_unit'],
            'is_verified'   => (bool)$r['is_verified'],
            'photo'         => $r['photo'],
            'photo_caption' => $r['photo_caption'],
            'facilities'    => $r['facilities'] ? explode('|', $r['facilities']) : [],
            'commute'       => $c ? [
                'destination' => $c['short_name'],
                'mode'        => $c['travel_mode'],
                'walk_distance_m' => $w ? (int)$w['route_distance_m'] : null,
                'walk_duration_s' => $w ? (int)$w['duration_s'] : null,
                'distance_m'  => (int)$c['route_distance_m'],
                'duration_s'  => (int)$c['duration_s'],
                'calculated'  => $c['calculated_at'],
            ] : null,
        ];
    }

    echo json_encode(['ok' => true, 'count' => count($listings), 'listings' => $listings]);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('featured.php: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Could not load listings.']);
}

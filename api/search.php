<?php
// api/search.php: search approved listings.
// GET params: destination (slug), type (all|boarding|guesthouse),
//             check_in, check_out (YYYY-MM-DD), guests (1-4 or 5+)
require __DIR__ . '/bootstrap.php';

try {
    $type = $_GET['type'] ?? 'all';
    if (!in_array($type, ['all', 'boarding', 'guesthouse'], true)) $type = 'all';

    $slug = strtolower(trim((string)($_GET['destination'] ?? '')));
    if ($slug !== '' && !preg_match('/^[a-z0-9-]{1,60}$/', $slug)) $slug = '';

    $ci = $co = null;
    foreach (['check_in' => &$ci, 'check_out' => &$co] as $key => &$ref) {
        $v = (string)($_GET[$key] ?? '');
        if ($v !== '') {
            $d = DateTime::createFromFormat('!Y-m-d', $v);
            if (!$d || $d->format('Y-m-d') !== $v) {
                json_out(['ok' => false, 'error' => 'Enter valid dates.'], 422);
            }
            $ref = $v;
        }
    }
    unset($ref);
    if ($ci && $co && $co <= $ci) {
        json_out(['ok' => false, 'error' => 'Check-out must be after check-in.'], 422);
    }

    $pdo = db();

    // Destination (optional)
    $dest = null;
    if ($slug !== '') {
        $st = $pdo->prepare('SELECT id, slug, name, short_name FROM destinations WHERE slug = ?');
        $st->execute([$slug]);
        $dest = $st->fetch() ?: null;
    }

    // Build the listing query
    $where  = ["p.status = 'approved'"];
    $params = [];
    if ($type !== 'all') { $where[] = 'p.type = ?'; $params[] = $type; }
    // Hide a listing only when its owner has set availability that rules it out.
    // No availability row means "not confirmed yet", so the listing stays visible.
    $where[] = '(pa.id IS NULL OR pa.available_units > 0)';
    if ($ci) { $where[] = '(pa.id IS NULL OR pa.available_from IS NULL OR pa.available_from <= ?)'; $params[] = $ci; }

    $sql = "SELECT p.id, p.name, p.type, p.area, p.price, p.price_unit, p.is_verified,
              pa.available_units, pa.available_from,
              (SELECT ph.file_path FROM property_photos ph WHERE ph.property_id = p.id
                 ORDER BY ph.is_primary DESC, ph.sort_order, ph.id LIMIT 1) AS photo,
              (SELECT ph.caption FROM property_photos ph WHERE ph.property_id = p.id
                 ORDER BY ph.is_primary DESC, ph.sort_order, ph.id LIMIT 1) AS photo_caption,
              (SELECT GROUP_CONCAT(f.facility ORDER BY f.id SEPARATOR '|')
                 FROM property_facilities f WHERE f.property_id = p.id) AS facilities
            FROM properties p
            LEFT JOIN property_availability pa ON pa.property_id = p.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY p.id LIMIT 50";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();

    $commute = null; $walk = null;
    if ($dest) {
        $walk = $pdo->prepare(
            "SELECT route_distance_m, duration_s FROM commute_estimates
             WHERE property_id = ? AND destination_id = ? AND is_current = 1
               AND validation_status = 'passed' AND travel_mode = 'walking'
             ORDER BY calculated_at DESC LIMIT 1"
        );
        $commute = $pdo->prepare(
            "SELECT travel_mode, route_distance_m, duration_s, calculated_at
             FROM commute_estimates
             WHERE property_id = ? AND destination_id = ? AND is_current = 1
               AND validation_status = 'passed' AND travel_mode = 'driving'
             ORDER BY calculated_at DESC LIMIT 1"
        );
    }

    $listings = [];
    foreach ($rows as $r) {
        $c = null; $w = null;
        if ($commute) {
            $commute->execute([$r['id'], $dest['id']]);
            $c = $commute->fetch() ?: null;
            $walk->execute([$r['id'], $dest['id']]);
            $w = $walk->fetch() ?: null;
        }
        $listings[] = [
            'id' => (int)$r['id'], 'name' => $r['name'], 'type' => $r['type'],
            'area' => $r['area'], 'price' => (float)$r['price'],
            'price_unit' => $r['price_unit'], 'is_verified' => (bool)$r['is_verified'],
            'photo' => $r['photo'], 'photo_caption' => $r['photo_caption'],
            'facilities' => $r['facilities'] ? explode('|', $r['facilities']) : [],
            'availability' => $r['available_units'] === null ? null : [
                'units' => (int)$r['available_units'], 'from' => $r['available_from'],
            ],
            'commute' => $c ? [
                'destination' => $dest['short_name'], 'mode' => $c['travel_mode'],
                'distance_m' => (int)$c['route_distance_m'], 'duration_s' => (int)$c['duration_s'],
                'calculated' => $c['calculated_at'],
                'walk_distance_m' => $w ? (int)$w['route_distance_m'] : null,
                'walk_duration_s' => $w ? (int)$w['duration_s'] : null,
            ] : null,
        ];
    }

    // Measured commute first (shortest journey first), then the rest by price.
    usort($listings, function ($a, $b) {
        if ($a['commute'] && $b['commute']) return $a['commute']['duration_s'] <=> $b['commute']['duration_s'];
        if ($a['commute']) return -1;
        if ($b['commute']) return 1;
        return $a['price'] <=> $b['price'];
    });

    json_out([
        'ok' => true, 'count' => count($listings),
        'destination' => $dest ? ['slug' => $dest['slug'], 'name' => $dest['name'], 'short_name' => $dest['short_name']] : null,
        'listings' => $listings,
    ]);
} catch (Throwable $e) {
    error_log('search.php: ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'Search is unavailable right now.'], 500);
}

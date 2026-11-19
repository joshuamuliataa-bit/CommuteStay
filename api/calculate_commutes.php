<?php
// api/calculate_commutes.php: admin-only. Calculates and stores driving commute
// estimates for approved properties to every destination.
// POST JSON: {}  or  {"property_id": 3}
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib_commute.php';

try {
    require_login(['admin']);
    $in  = read_json();
    $pid = isset($in['property_id']) ? (int)$in['property_id'] : 0;
    $pdo = db();
    set_time_limit(180);

    if ($pid > 0) {
        $st = $pdo->prepare("SELECT id, name, latitude, longitude FROM properties WHERE status = 'approved' AND id = ?");
        $st->execute([$pid]);
    } else {
        $st = $pdo->query("SELECT id, name, latitude, longitude FROM properties WHERE status = 'approved' ORDER BY id");
    }
    $props = $st->fetchAll();
    $dests = $pdo->query('SELECT id, slug, short_name, latitude, longitude FROM destinations ORDER BY id')->fetchAll();

    if (count($props) * count($dests) * count(ROUTING_MODES) > 70) {
        json_out(['ok' => false, 'error' => 'Too many routes for one request. Calculate one property at a time.'], 422);
    }

    $results = [];
    foreach ($props as $p) {
        $results = array_merge($results, compute_commutes_for_property($pdo, $p, $dests));
    }
    json_out(['ok' => true, 'count' => count($results), 'results' => $results]);
} catch (Throwable $e) {
    error_log('calculate_commutes.php: ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'Could not calculate commute estimates.'], 500);
}

<?php
// api/lib_commute.php: routing and validation helpers for commute estimates.
// Used by calculate_commutes.php. Matches the process in Section 1.5.2.4:
// check coordinates, calculate, validate, record method and timestamp.

// Routing uses the free FOSSGIS OSRM servers (routing.openstreetmap.de), which have
// separate car, foot and bike profiles. (The other public demo server,
// router.project-osrm.org, always returns car routes, whatever profile is asked for.)
// Fair use: reasonable, non-commercial, no more than about 1 request a second.
// Every stored row records the service and profile used.
const OSRM_BASE = 'https://routing.openstreetmap.de';
const ROUTING_PROFILES = [
    'driving' => 'routed-car',
    'walking' => 'routed-foot',
    'cycling' => 'routed-bike',
];
const ROUTING_MODES = ['driving', 'walking', 'cycling'];

function routing_service_name(string $mode): string {
    return 'OSRM (FOSSGIS) ' . ROUTING_PROFILES[$mode];
}

// Rough box around Lusaka, used to catch pins dropped in the wrong place.
const LUSAKA_LAT_MIN = -15.60;
const LUSAKA_LAT_MAX = -15.25;
const LUSAKA_LNG_MIN = 28.10;
const LUSAKA_LNG_MAX = 28.50;

function in_lusaka(float $lat, float $lng): bool {
    return $lat >= LUSAKA_LAT_MIN && $lat <= LUSAKA_LAT_MAX
        && $lng >= LUSAKA_LNG_MIN && $lng <= LUSAKA_LNG_MAX;
}

// Straight-line (great-circle) distance in metres.
function haversine_m(float $lat1, float $lng1, float $lat2, float $lng2): int {
    $r = 6371000.0;
    $p1 = deg2rad($lat1); $p2 = deg2rad($lat2);
    $dp = deg2rad($lat2 - $lat1); $dl = deg2rad($lng2 - $lng1);
    $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
    return (int)round(2 * $r * asin(min(1.0, sqrt($a))));
}

// Asks OSRM for a route in the given mode. Returns ['distance_m' => int, 'duration_s' => int].
function osrm_route(string $mode, float $lat1, float $lng1, float $lat2, float $lng2): array {
    if (!isset(ROUTING_PROFILES[$mode])) {
        throw new RuntimeException('Unknown travel mode.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL is not enabled. In XAMPP, enable extension=curl in php.ini and restart Apache.');
    }
    // The profile is chosen by the path prefix (routed-car / routed-foot / routed-bike);
    // the word after /v1/ is ignored by these servers.
    $url = OSRM_BASE . '/' . ROUTING_PROFILES[$mode] . '/route/v1/driving/'
         . number_format($lng1, 6, '.', '') . ',' . number_format($lat1, 6, '.', '') . ';'
         . number_format($lng2, 6, '.', '') . ',' . number_format($lat2, 6, '.', '')
         . '?overview=false&alternatives=false&steps=false';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_USERAGENT      => 'CommuteStay-student-project/1.0',
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        // On XAMPP/Windows, "SSL certificate problem" means php.ini needs curl.cainfo set.
        throw new RuntimeException('Routing service unreachable: ' . $err);
    }
    $data = json_decode($body, true);
    if ($http !== 200 || !is_array($data) || ($data['code'] ?? '') !== 'Ok' || empty($data['routes'][0])) {
        $why = is_array($data) ? ($data['code'] ?? 'unknown') : 'bad response';
        throw new RuntimeException("Routing service returned no route (HTTP $http, $why).");
    }
    return [
        'distance_m' => (int)round($data['routes'][0]['distance']),
        'duration_s' => (int)round($data['routes'][0]['duration']),
    ];
}

// Plausibility checks. Returns [status, notes] where status is passed|flagged|failed.
function validate_estimate(string $mode, int $straightM, int $routeM, int $durationS): array {
    // Sensible average speeds (km/h) and longest believable route (m) for each mode.
    $limits = [
        'driving' => ['min_kmh' => 5,   'max_kmh' => 100, 'max_m' => 40000],
        'cycling' => ['min_kmh' => 5,   'max_kmh' => 40,  'max_m' => 25000],
        'walking' => ['min_kmh' => 1.5, 'max_kmh' => 8,   'max_m' => 12000],
    ][$mode];
    $notes = [];
    $status = 'passed';

    // A route cannot be shorter than the straight line (small tolerance for the
    // routing service snapping each pin to the nearest road or path).
    if ($routeM < $straightM * 0.95 - 50) {
        return ['failed', 'Route is shorter than the straight-line distance, which is impossible.'];
    }
    if ($straightM > 0 && $routeM / max($straightM, 1) > 3.0 && $routeM > 1500) {
        $status = 'flagged';
        $notes[] = 'Route is more than 3 times the straight-line distance.';
    }
    if ($routeM > $limits['max_m']) {
        $status = 'flagged';
        $notes[] = sprintf('Route is longer than %d km, unusual inside Lusaka.', $limits['max_m'] / 1000);
    }
    if ($durationS > 0 && $routeM > 300) {
        $kmh = ($routeM / 1000) / ($durationS / 3600);
        if ($kmh < $limits['min_kmh'] || $kmh > $limits['max_kmh']) {
            $status = 'flagged';
            $notes[] = sprintf('Implied average speed of %.1f km/h looks implausible for this mode.', $kmh);
        }
    }
    return [$status, $notes ? implode(' ', $notes) : 'Passed all checks.'];
}

// Stores one estimate. Older rows for the same property, destination and mode are
// kept as history but marked is_current = 0.
function save_estimate(PDO $pdo, string $mode, array $prop, array $dest, array $route, int $straightM, string $status, string $notes): int {
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE commute_estimates SET is_current = 0
                       WHERE property_id = ? AND destination_id = ? AND travel_mode = ?")
            ->execute([$prop['id'], $dest['id'], $mode]);
        $pdo->prepare("INSERT INTO commute_estimates
            (property_id, destination_id, travel_mode, routing_service,
             origin_lat, origin_lng, dest_lat, dest_lng,
             route_distance_m, duration_s, straight_line_m,
             validation_status, validation_notes, is_current)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)")
            ->execute([
                $prop['id'], $dest['id'], $mode, routing_service_name($mode),
                round((float)$prop['latitude'], 6), round((float)$prop['longitude'], 6),
                round((float)$dest['latitude'], 6), round((float)$dest['longitude'], 6),
                $route['distance_m'], $route['duration_s'], $straightM,
                $status, mb_substr($notes, 0, 255),
            ]);
        $id = (int)$pdo->lastInsertId();
        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// Keeps requests to the public demo server to about one per second,
// counted across everything done in this one PHP request.
function throttle_routing() {
    static $calls = 0;
    if ($calls++ > 0) usleep(1100000);
}

// Calculates and stores estimates from one property to every destination, in every mode.
// $p needs id, name, latitude, longitude. Returns one result row per destination and mode.
function compute_commutes_for_property(PDO $pdo, array $p, array $dests, array $modes = ROUTING_MODES): array {
    $results = [];
    foreach ($dests as $d) {
        foreach ($modes as $mode) {
            $row = ['property' => $p['name'], 'destination' => $d['short_name'], 'mode' => $mode];

            if ($p['latitude'] === null || $p['longitude'] === null
                || !in_lusaka((float)$p['latitude'], (float)$p['longitude'])) {
                $results[] = $row + ['status' => 'skipped', 'notes' => 'Property coordinates are missing or outside Lusaka.'];
                continue;
            }
            if (!in_lusaka((float)$d['latitude'], (float)$d['longitude'])) {
                $results[] = $row + ['status' => 'skipped', 'notes' => 'Destination coordinates are outside Lusaka.'];
                continue;
            }

            throttle_routing();
            try {
                $route = osrm_route($mode, (float)$p['latitude'], (float)$p['longitude'],
                                           (float)$d['latitude'], (float)$d['longitude']);
                $straight = haversine_m((float)$p['latitude'], (float)$p['longitude'],
                                        (float)$d['latitude'], (float)$d['longitude']);
                [$status, $notes] = validate_estimate($mode, $straight, $route['distance_m'], $route['duration_s']);
                save_estimate($pdo, $mode, $p, $d, $route, $straight, $status, $notes);
                $results[] = $row + [
                    'status' => $status, 'distance_m' => $route['distance_m'],
                    'duration_s' => $route['duration_s'], 'straight_m' => $straight, 'notes' => $notes,
                ];
            } catch (RuntimeException $e) {
                $results[] = $row + ['status' => 'error', 'notes' => $e->getMessage()];
            }
        }
    }
    return $results;
}

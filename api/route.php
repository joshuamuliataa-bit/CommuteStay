<?php
/**
 * OSRM routing proxy.
 *
 *   GET /api/route.php?modes=1
 *     -> list of transport modes and whether each is available.
 *
 *   GET /api/route.php?from_lat=..&from_lng=..&to_lat=..&to_lng=..&mode=driving
 *     -> estimated road route (distance, duration, GeoJSON geometry).
 *
 * The browser never talks to OSRM directly, so the routing server can be
 * swapped (e.g. for a self-hosted instance with foot/bike profiles) by
 * editing config/map.php only. All results are estimates from OpenStreetMap
 * road data — not verified commute times and without live traffic.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    json_error('Method not allowed.', 405, 'method_not_allowed');
}

$config = require __DIR__ . '/../config/map.php';
$osrm = $config['osrm'];
$modes = $osrm['modes'];

// --- Mode listing -----------------------------------------------------------
if (isset($_GET['modes'])) {
    $list = [];
    foreach ($modes as $id => $mode) {
        $list[] = [
            'id' => $id,
            'label' => $mode['label'],
            'enabled' => (bool) $mode['enabled'] && $mode['base_url'] !== '',
            'note' => $mode['note'],
        ];
    }
    json_response(['ok' => true, 'modes' => $list]);
}

// --- Validate input -----------------------------------------------------------
function read_coordinate(string $key, float $min, float $max): float
{
    $raw = $_GET[$key] ?? null;
    $value = filter_var($raw, FILTER_VALIDATE_FLOAT);
    if ($value === false || $value < $min || $value > $max) {
        json_error("Missing or invalid parameter: {$key}.", 400, 'invalid_coordinates');
    }
    return (float) $value;
}

$b = $osrm['bounds'];
$fromLat = read_coordinate('from_lat', $b['min_lat'], $b['max_lat']);
$fromLng = read_coordinate('from_lng', $b['min_lng'], $b['max_lng']);
$toLat   = read_coordinate('to_lat',   $b['min_lat'], $b['max_lat']);
$toLng   = read_coordinate('to_lng',   $b['min_lng'], $b['max_lng']);

$modeId = strtolower(trim((string) ($_GET['mode'] ?? 'driving')));
if (!isset($modes[$modeId])) {
    json_error('Unknown transport mode.', 400, 'invalid_mode');
}
$mode = $modes[$modeId];
if (!$mode['enabled'] || $mode['base_url'] === '') {
    json_error(
        $mode['label'] . ' routing is not available on this installation. ' . $mode['note'],
        400,
        'mode_unavailable'
    );
}

$straightLine = haversine_metres($fromLat, $fromLng, $toLat, $toLng);

// --- Call OSRM ----------------------------------------------------------------
$coords = sprintf('%.6f,%.6f;%.6f,%.6f', $fromLng, $fromLat, $toLng, $toLat); // OSRM wants lng,lat
$url = rtrim($mode['base_url'], '/') . '/route/v1/' . rawurlencode($mode['profile']) . '/' . $coords
     . '?' . http_build_query([
        'overview' => 'full',
        'geometries' => 'geojson',
        'steps' => 'false',
        'alternatives' => 'false',
    ]);

$body = null;
$httpStatus = 0;
$transportError = null;

if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => (int) $osrm['timeout_seconds'],
        CURLOPT_USERAGENT => $osrm['user_agent'],
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $body = curl_exec($ch);
    $httpStatus = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($body === false) {
        $transportError = curl_error($ch);
    }
    curl_close($ch);
} else {
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => (int) $osrm['timeout_seconds'],
            'header' => "User-Agent: {$osrm['user_agent']}\r\nAccept: application/json\r\n",
            'ignore_errors' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        $transportError = 'HTTP request failed';
    } elseif (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $httpStatus = (int) $m[1];
    }
}

if ($transportError !== null) {
    error_log('route.php: OSRM transport error: ' . $transportError);
    json_error('The routing service could not be reached. Please try again later.', 502, 'service_unavailable');
}

$data = json_decode((string) $body, true);
if (!is_array($data)) {
    error_log('route.php: OSRM returned non-JSON (HTTP ' . $httpStatus . ')');
    json_error('The routing service returned an unexpected response.', 502, 'bad_upstream_response');
}

$code = (string) ($data['code'] ?? '');

if ($code === 'NoRoute' || $code === 'NoSegment') {
    json_response([
        'ok' => false,
        'code' => 'no_route',
        'error' => 'No road route could be found between these two points.',
        'straight_line_m' => round($straightLine),
    ]);
}

if ($code !== 'Ok' || empty($data['routes'][0])) {
    error_log('route.php: OSRM error code=' . $code . ' http=' . $httpStatus);
    json_error('The routing service is currently unavailable. Please try again later.', 502, 'service_error');
}

$route = $data['routes'][0];

json_response([
    'ok' => true,
    'source' => 'OSRM (OpenStreetMap road data)',
    'mode' => $modeId,
    'mode_label' => $mode['label'],
    'profile' => $mode['profile'],
    'is_estimate' => true,
    'is_verified' => false,
    'includes_live_traffic' => false,
    'distance_m' => round((float) $route['distance']),
    'duration_s' => round((float) $route['duration']),
    'straight_line_m' => round($straightLine),
    'geometry' => $route['geometry'], // GeoJSON LineString, [lng, lat] pairs
]);

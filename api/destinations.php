<?php
/**
 * GET /api/destinations.php
 *
 * Returns the commute destinations (Evelyn Hone, NIPA, ZCAS) with their
 * coordinates and the source those coordinates were checked against.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    json_error('Method not allowed.', 405, 'method_not_allowed');
}

require_once __DIR__ . '/../config/database.php';

try {
    $stmt = $pdo->prepare(
        'SELECT slug, name, short_name, address, latitude, longitude, coordinate_source
         FROM destinations
         ORDER BY name ASC'
    );
    $stmt->execute();
    $rows = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('destinations.php: ' . $e->getMessage());
    json_error('Could not load destinations.', 500, 'database_error');
}

$destinations = array_map(static function (array $row): array {
    return [
        'slug' => (string) $row['slug'],
        'name' => (string) $row['name'],
        'short_name' => (string) $row['short_name'],
        'address' => $row['address'] !== null ? (string) $row['address'] : null,
        'latitude' => (float) $row['latitude'],
        'longitude' => (float) $row['longitude'],
        'coordinate_source' => $row['coordinate_source'] !== null ? (string) $row['coordinate_source'] : null,
    ];
}, $rows);

json_response([
    'ok' => true,
    'count' => count($destinations),
    'destinations' => $destinations,
]);

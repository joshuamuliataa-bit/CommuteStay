<?php
/**
 * GET /api/properties.php[?type=boarding|guesthouse]
 *
 * Returns administrator-approved properties that have coordinates, as JSON,
 * for display as Leaflet markers. Never exposes pending/rejected listings.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    json_error('Method not allowed.', 405, 'method_not_allowed');
}

$allowedTypes = ['boarding', 'guesthouse'];
$type = strtolower(trim((string) ($_GET['type'] ?? '')));
if ($type !== '' && $type !== 'all' && !in_array($type, $allowedTypes, true)) {
    json_error('Invalid stay type.', 400, 'invalid_type');
}

require_once __DIR__ . '/../config/database.php';

$sql = 'SELECT id, name, type, address, area, price, price_unit, latitude, longitude, is_verified
        FROM properties
        WHERE status = :status
          AND latitude IS NOT NULL
          AND longitude IS NOT NULL';
$params = [':status' => 'approved'];

if ($type !== '' && $type !== 'all') {
    $sql .= ' AND type = :type';
    $params[':type'] = $type;
}
$sql .= ' ORDER BY name ASC';

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('properties.php: ' . $e->getMessage());
    json_error('Could not load listings.', 500, 'database_error');
}

$base = app_base_path();
$properties = [];

foreach ($rows as $row) {
    $id = (int) $row['id'];
    $properties[] = [
        'id' => $id,
        'name' => (string) $row['name'],
        'type' => (string) $row['type'],
        'type_label' => property_type_label((string) $row['type']),
        'address' => (string) $row['address'],
        'area' => $row['area'] !== null ? (string) $row['area'] : null,
        'price' => (float) $row['price'],
        'price_unit' => (string) $row['price_unit'],
        'price_label' => format_price((float) $row['price'], (string) $row['price_unit']),
        'latitude' => (float) $row['latitude'],
        'longitude' => (float) $row['longitude'],
        'is_verified' => (bool) $row['is_verified'],
        'details_url' => $base . '/pages/property.php?id=' . $id,
    ];
}

json_response([
    'ok' => true,
    'count' => count($properties),
    'properties' => $properties,
]);

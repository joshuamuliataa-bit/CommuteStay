<?php
// api/intakes.php: upcoming semester intake dates (public).
// GET ?destination=slug   (optional filter)
require __DIR__ . '/bootstrap.php';
date_default_timezone_set('Africa/Lusaka');
try {
    $slug = strtolower(trim((string)($_GET['destination'] ?? '')));
    $sql = "SELECT i.id, i.name, i.starts_on, d.slug, d.short_name
            FROM intakes i JOIN destinations d ON d.id = i.destination_id
            WHERE i.starts_on >= ?";
    $params = [date('Y-m-d')];
    if ($slug !== '' && preg_match('/^[a-z0-9-]{1,60}$/', $slug)) { $sql .= ' AND d.slug = ?'; $params[] = $slug; }
    $sql .= ' ORDER BY i.starts_on, d.id LIMIT 50';
    $st = db()->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) $r['id'] = (int)$r['id'];
    json_out(['ok' => true, 'intakes' => $rows]);
} catch (Throwable $e) {
    error_log('intakes.php: ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'Could not load intake dates.'], 500);
}

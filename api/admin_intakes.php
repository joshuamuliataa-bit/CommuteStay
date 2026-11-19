<?php
// api/admin_intakes.php: admin manages intake (semester start) dates.
// GET  -> all intakes and the destinations list
// POST {"action":"add","destination_id":1,"name":"January 2027 intake","starts_on":"2027-01-18","source_note":"..."}
// POST {"action":"delete","id":3}
require __DIR__ . '/bootstrap.php';
try {
    require_login(['admin']);
    $pdo = db();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $dests = $pdo->query('SELECT id, slug, name, short_name FROM destinations ORDER BY id')->fetchAll();
        $rows = $pdo->query("SELECT i.id, i.name, i.starts_on, i.source_note, d.short_name
                             FROM intakes i JOIN destinations d ON d.id = i.destination_id
                             ORDER BY i.starts_on DESC, d.id LIMIT 200")->fetchAll();
        json_out(['ok' => true, 'destinations' => $dests, 'intakes' => $rows]);
    }

    $in = read_json();
    $action = (string)($in['action'] ?? '');

    if ($action === 'add') {
        $did  = (int)($in['destination_id'] ?? 0);
        $name = trim((string)($in['name'] ?? ''));
        $date = (string)($in['starts_on'] ?? '');
        $note = trim((string)($in['source_note'] ?? ''));
        $d = DateTime::createFromFormat('!Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) json_out(['ok' => false, 'error' => 'Enter a valid start date.'], 422);
        if (mb_strlen($name) < 3 || mb_strlen($name) > 100) json_out(['ok' => false, 'error' => 'Name the intake (3 to 100 characters).'], 422);
        if (mb_strlen($note) > 255) json_out(['ok' => false, 'error' => 'Keep the source note under 255 characters.'], 422);
        $chk = $pdo->prepare('SELECT id FROM destinations WHERE id = ?');
        $chk->execute([$did]);
        if (!$chk->fetch()) json_out(['ok' => false, 'error' => 'Choose a destination.'], 422);
        try {
            $pdo->prepare('INSERT INTO intakes (destination_id, name, starts_on, source_note) VALUES (?, ?, ?, ?)')
                ->execute([$did, $name, $date, $note !== '' ? $note : null]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') json_out(['ok' => false, 'error' => 'That intake already exists for this destination.'], 409);
            throw $e;
        }
        json_out(['ok' => true]);
    }
    if ($action === 'delete') {
        $pdo->prepare('DELETE FROM intakes WHERE id = ?')->execute([(int)($in['id'] ?? 0)]);
        json_out(['ok' => true]);
    }
    json_out(['ok' => false, 'error' => 'Unknown action.'], 422);
} catch (Throwable $e) {
    error_log('admin_intakes.php: ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'Could not complete the request.'], 500);
}

<?php
// api/create_property.php: an owner submits a listing (multipart form + photos).
// The listing is saved as 'pending'. An admin must approve it before it appears.
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib_commute.php';   // for in_lusaka()

const MAX_PHOTOS = 6;
const MAX_PHOTO_BYTES = 5 * 1024 * 1024;

function fail(string $msg, int $code = 422) { json_out(['ok' => false, 'error' => $msg], $code); }

function rrmdir(string $dir) {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $f) {
        if ($f !== '.' && $f !== '..') @unlink($dir . '/' . $f);
    }
    @rmdir($dir);
}

// Re-encodes the upload as a JPEG (max 1600 px). This strips hidden data and
// proves the file really is an image. Returns the new file name.
function save_photo(string $tmp, string $mime, string $dir): string {
    if (function_exists('imagecreatefromstring')) {
        $img = @imagecreatefromstring((string)file_get_contents($tmp));
        if (!$img) throw new RuntimeException('One of the photos could not be read as an image.');
        $w = imagesx($img); $h = imagesy($img); $max = 1600;
        $scale = max($w, $h) > $max ? $max / max($w, $h) : 1.0;
        $nw = max(1, (int)round($w * $scale)); $nh = max(1, (int)round($h * $scale));
        $out = imagecreatetruecolor($nw, $nh);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));   // flatten transparency
        imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);
        $name = bin2hex(random_bytes(12)) . '.jpg';
        $ok = imagejpeg($out, $dir . '/' . $name, 82);
        imagedestroy($out);
        if (!$ok) throw new RuntimeException('Could not save a photo.');
        return $name;
    }
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime];
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($tmp, $dir . '/' . $name)) throw new RuntimeException('Could not save a photo.');
    return $name;
}

$createdDir = null;
$pdo = null;
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST required.', 405);
    // A page on another site cannot set this header, which blocks forged requests.
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'CommuteStay') fail('Invalid request.', 400);
    $user = require_login(['owner', 'admin']);

    $t = fn($k) => trim((string)($_POST[$k] ?? ''));

    $name  = $t('name');
    $type  = $t('type');
    $area  = $t('area');
    $addr  = $t('address');
    $phone = $t('contact_phone');
    $desc  = $t('description');
    $unit  = $t('price_unit');
    $price = $t('price');
    $lat   = $t('latitude');
    $lng   = $t('longitude');
    // Only an admin can publish straight away; the flag is ignored for everyone else.
    $instant = ($user['role'] === 'admin' && $t('instant_approve') === '1');

    if (mb_strlen($name) < 3 || mb_strlen($name) > 120) fail('Enter the property name (3 to 120 characters).');
    if (!in_array($type, ['boarding', 'guesthouse'], true)) fail('Choose boarding house or guesthouse.');
    if (mb_strlen($area) < 2 || mb_strlen($area) > 80) fail('Enter the area, for example Fairview.');
    if (mb_strlen($addr) < 5 || mb_strlen($addr) > 200) fail('Enter the street address or description of how to find it.');
    if (!preg_match('/^\+?[0-9][0-9 \-]{6,18}$/', $phone)) fail('Enter a valid contact phone number.');
    if (mb_strlen($desc) < 20 || mb_strlen($desc) > 1500) fail('Describe the property in 20 to 1,500 characters.');
    if (!in_array($unit, ['per_bed_month', 'per_night'], true)) fail('Choose how the price is charged.');
    if (!is_numeric($price) || (float)$price < 50 || (float)$price > 100000) fail('Enter a price in kwacha between K50 and K100,000.');
    if (!is_numeric($lat) || !is_numeric($lng)) fail('Click the map to place a pin on the property.');
    if (!in_lusaka((float)$lat, (float)$lng)) fail('The pin is outside Lusaka. Move it onto the property.');

    // Facilities: ticked options plus an optional comma-separated list
    $fac = [];
    $ticked = $_POST['facilities'] ?? [];
    if (is_array($ticked)) foreach ($ticked as $f) $fac[] = trim((string)$f);
    foreach (explode(',', $t('facility_other')) as $f) $fac[] = trim($f);
    $clean = [];
    foreach ($fac as $f) {
        if ($f === '' || mb_strlen($f) > 80) continue;
        $clean[mb_strtolower($f)] = $f;
    }
    $fac = array_slice(array_values($clean), 0, 12);

    // Availability (optional)
    $units = $t('available_units');
    $units = ($units === '') ? null : (int)$units;
    if ($units !== null && ($units < 0 || $units > 500)) fail('Available units must be between 0 and 500.');
    $from = $t('available_from');
    if ($from !== '') {
        $d = DateTime::createFromFormat('!Y-m-d', $from);
        if (!$d || $d->format('Y-m-d') !== $from) fail('Enter a valid available-from date.');
    } else {
        $from = null;
    }

    // Photos
    if (empty($_FILES['photos']) || !is_array($_FILES['photos']['name'])) fail('Add at least one photo of the property.');
    $files = [];
    foreach ($_FILES['photos']['name'] as $i => $n) {
        if ($_FILES['photos']['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
        $files[] = [
            'tmp'   => $_FILES['photos']['tmp_name'][$i],
            'size'  => $_FILES['photos']['size'][$i],
            'error' => $_FILES['photos']['error'][$i],
        ];
    }
    if (count($files) < 1) fail('Add at least one photo of the property.');
    if (count($files) > MAX_PHOTOS) fail('You can upload up to ' . MAX_PHOTOS . ' photos.');
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    foreach ($files as $k => $f) {
        if ($f['error'] !== UPLOAD_ERR_OK) fail('A photo failed to upload. Try smaller files.');
        if ($f['size'] > MAX_PHOTO_BYTES) fail('Each photo must be 5 MB or smaller.');
        if (!is_uploaded_file($f['tmp'])) fail('Invalid upload.');
        $mime = $finfo->file($f['tmp']);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) fail('Photos must be JPEG, PNG or WebP images.');
        $info = @getimagesize($f['tmp']);
        if (!$info || $info[0] < 300 || $info[1] < 200 || $info[0] > 10000 || $info[1] > 10000) fail('A photo is too small or not a valid image (minimum 300 by 200 pixels).');
        $files[$k]['mime'] = $mime;
    }

    $pdo = db();
    $pdo->beginTransaction();

    $pdo->prepare("INSERT INTO properties
        (owner_id, name, type, description, address, contact_phone, area, price, price_unit,
         latitude, longitude, status, is_verified)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$user['id'], $name, $type, $desc, $addr, $phone, $area,
                   round((float)$price, 2), $unit, round((float)$lat, 6), round((float)$lng, 6),
                   $instant ? 'approved' : 'pending', $instant ? 1 : 0]);
    $pid = (int)$pdo->lastInsertId();

    $dir = dirname(__DIR__) . '/assets/uploads/' . $pid;
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) throw new RuntimeException('Could not create the photo folder.');
    $createdDir = $dir;

    $insPhoto = $pdo->prepare('INSERT INTO property_photos (property_id, file_path, caption, is_primary, sort_order) VALUES (?, ?, ?, ?, ?)');
    foreach ($files as $i => $f) {
        $fn = save_photo($f['tmp'], $f['mime'], $dir);
        $insPhoto->execute([$pid, 'assets/uploads/' . $pid . '/' . $fn, mb_substr($name, 0, 150), $i === 0 ? 1 : 0, $i]);
    }

    if ($fac) {
        $insFac = $pdo->prepare('INSERT IGNORE INTO property_facilities (property_id, facility) VALUES (?, ?)');
        foreach ($fac as $f) $insFac->execute([$pid, $f]);
    }
    if ($units !== null || $from !== null) {
        $pdo->prepare('INSERT INTO property_availability (property_id, available_units, available_from) VALUES (?, ?, ?)')
            ->execute([$pid, $units ?? 0, $from]);
    }

    if ($instant) {
        $pdo->prepare('INSERT INTO listing_reviews (property_id, admin_id, decision, notes) VALUES (?, ?, ?, ?)')
            ->execute([$pid, $user['id'], 'approved', 'Published directly by an administrator.']);
    }
    $pdo->commit();

    // A directly published listing gets its commute estimates now. A failure here never undoes the listing.
    if ($instant) {
        try {
            set_time_limit(120);
            $dests = $pdo->query('SELECT id, slug, short_name, latitude, longitude FROM destinations ORDER BY id')->fetchAll();
            compute_commutes_for_property($pdo, ['id' => $pid, 'name' => $name,
                'latitude' => round((float)$lat, 6), 'longitude' => round((float)$lng, 6)], $dests);
        } catch (Throwable $e) {
            error_log('create_property.php commute step: ' . $e->getMessage());
        }
    }
    json_out(['ok' => true, 'property_id' => $pid, 'status' => $instant ? 'approved' : 'pending']);
} catch (RuntimeException $e) {
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    if ($createdDir) rrmdir($createdDir);
    fail($e->getMessage(), 500);
} catch (Throwable $e) {
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    if ($createdDir) rrmdir($createdDir);
    error_log('create_property.php: ' . $e->getMessage());
    fail('Could not save the listing. Please try again.', 500);
}

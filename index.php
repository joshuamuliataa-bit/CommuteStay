<?php
/**
 * Milestone 1: redirect to static homepage (no backend logic yet).
 * Apache may prefer index.php over index.html — this keeps one entry URL working.
 */
header('Location: index.html', true, 302);
exit;

<?php

declare(strict_types=1);

// HF-01 / CR-TEST-21: root cause of the Two-Hand Reaction Time 500-on-timeout production bug was
// stale OPcache bytecode on WebHostMost's long-lived LiteSpeed PHP workers — new source files were
// uploaded correctly, but running workers kept executing a pre-fix compiled version indefinitely
// (same class of staleness as D22's browser-JS-caching finding, server-side instead of client-
// side). There is no assumed shell-level access to restart PHP-FPM/LSAPI workers on this shared
// host, so this script calls opcache_reset() from *inside* a request handled by the live web SAPI
// itself — the only mechanism guaranteed to hit the exact OPcache instance serving real traffic.
//
// Deliberately standalone (no app autoload/Config/Router) so it still works correctly even while
// the rest of the app's OPcache entries are stale — only opcache_reset() itself needs to run, and
// this file is by definition freshly uploaded on every deploy.
//
// Security: .github/workflows/deploy.yml writes opcache-reset.token (git-ignored, deploy-generated
// only, never committed) into this same directory immediately before invoking this script over
// HTTPS exactly once, then deletes BOTH files. Absent that token file (e.g. someone requests this
// URL between deploys, or after the cleanup step), the endpoint always 404s — it is never a
// standing, permanently-triggerable endpoint.

$tokenFile = __DIR__ . '/opcache-reset.token';
$given = $_GET['token'] ?? '';

if (!is_file($tokenFile) || !is_string($given) || $given === '') {
    http_response_code(404);
    exit;
}

$expected = trim((string) file_get_contents($tokenFile));
if ($expected === '' || !hash_equals($expected, $given)) {
    http_response_code(404);
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

if (function_exists('opcache_reset')) {
    $ok = opcache_reset();
    echo $ok
        ? "opcache_reset: ok\n"
        : "opcache_reset: returned false (opcache.restrict_api may be set, or OPcache is disabled for this SAPI)\n";
} else {
    echo "opcache_reset: function not available (OPcache extension not loaded)\n";
}

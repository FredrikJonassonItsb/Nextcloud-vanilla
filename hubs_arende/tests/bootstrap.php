<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * PHPUnit bootstrap for hubs_arende.
 *
 * Two run-modes, picked automatically:
 *
 *  (1) IN A NEXTCLOUD CHECKOUT — when the app lives under an apps dir and NC's
 *      own test bootstrap is reachable, we load it. That brings in the NC
 *      server class-loader (so OCP\* + OCA\HubsArende\* resolve exactly as in
 *      production) and the global test helpers. This is the path used on dev15.
 *
 *  (2) STANDALONE — when no NC checkout is present (CI, a dev laptop, a bare
 *      clone) we register a tiny PSR-4 autoloader for OCA\HubsArende\ → ../lib so
 *      the pure-unit suites (which mock every OCP collaborator) still run with
 *      nothing but PHPUnit on the path.
 *
 * The OCP interfaces themselves are provided by PHPUnit's createMock() in the
 * standalone case: createMock() only needs the interface to be loadable, and in
 * mode (2) those come from the Composer autoloader if `composer install` pulled
 * in nextcloud/ocp (see tests/README), otherwise mode (1) supplies them.
 */

$appRoot = dirname(__DIR__);

// --- Try to locate a Nextcloud server checkout and its test bootstrap. -------
$candidates = [];

// a) App is installed under a server tree: .../server/apps/hubs_arende/tests
$candidates[] = dirname($appRoot, 2) . '/tests/bootstrap.php';
// b) Custom apps dir: .../server/apps-extra/hubs_arende -> server/tests
$candidates[] = dirname($appRoot, 2) . '/tests/bootstrap.php';
// c) Explicit override for unusual layouts.
if (($env = getenv('NEXTCLOUD_TEST_BOOTSTRAP')) !== false && $env !== '') {
    array_unshift($candidates, $env);
}

$ncBootstrap = null;
foreach ($candidates as $candidate) {
    if (is_string($candidate) && is_file($candidate)) {
        $ncBootstrap = $candidate;
        break;
    }
}

if ($ncBootstrap !== null) {
    // Mode (1): real Nextcloud test environment.
    require_once $ncBootstrap;
} else {
    // Mode (2): standalone. Pull in Composer autoload if present (provides
    // PHPUnit and, when installed, nextcloud/ocp shipping the OCP/NCU sources).
    $vendorRoot = null;
    foreach ([
        $appRoot . '/vendor',
        $appRoot . '/tests/vendor',
    ] as $candidateVendor) {
        if (is_file($candidateVendor . '/autoload.php')) {
            require_once $candidateVendor . '/autoload.php';
            $vendorRoot = $candidateVendor;
            break;
        }
    }

    // PSR-4 autoloader for the app's own classes (OCA\HubsArende\ -> lib/) AND for
    // the OCP/NCU API. The nextcloud/ocp package ships the OCP sources but declares
    // NO Composer autoload section (in production NC's server class-loader provides
    // them), so we map the namespaces onto the package's source tree ourselves.
    $psr4 = ['OCA\\HubsArende\\' => $appRoot . '/lib'];
    if ($vendorRoot !== null && is_dir($vendorRoot . '/nextcloud/ocp/OCP')) {
        $psr4['OCP\\'] = $vendorRoot . '/nextcloud/ocp/OCP';
        if (is_dir($vendorRoot . '/nextcloud/ocp/NCU')) {
            $psr4['NCU\\'] = $vendorRoot . '/nextcloud/ocp/NCU';
        }
    }

    spl_autoload_register(static function (string $class) use ($psr4): void {
        foreach ($psr4 as $prefix => $baseDir) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }
            $relative = substr($class, strlen($prefix));
            $path = $baseDir . '/' . str_replace('\\', '/', $relative) . '.php';
            if (is_file($path)) {
                require_once $path;
            }
            return;
        }
    });
}

// App classes must always be loadable regardless of which mode we are in; in
// mode (1) NC's registerAutoloader has them, in mode (2) the closure above does.

<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * PHPUnit bootstrap for agent_engine — same two-mode scheme as hubs_arende:
 *
 *  (1) IN A NEXTCLOUD CHECKOUT — NC's own test bootstrap is loaded (server
 *      class loader provides OCP\* and the app classes).
 *  (2) STANDALONE — a tiny PSR-4 autoloader maps OCA\AgentEngine\ → ../lib and
 *      OCP\/NCU\ → the nextcloud/ocp composer package (which ships sources
 *      but declares no autoload section).
 */

$appRoot = dirname(__DIR__);

$candidates = [];
$candidates[] = dirname($appRoot, 2) . '/tests/bootstrap.php';
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
    require_once $ncBootstrap;
} else {
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

    // Doctrine constant stubs — OCP's IQueryBuilder references them at load.
    require_once __DIR__ . '/stubs/doctrine.php';

    $psr4 = ['OCA\\AgentEngine\\' => $appRoot . '/lib'];
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

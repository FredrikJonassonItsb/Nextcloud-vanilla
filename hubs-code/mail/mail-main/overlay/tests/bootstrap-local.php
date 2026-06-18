<?php
/**
 * Local bootstrap for running ITSL PHPUnit tests from outside a running
 * Nextcloud install. Loads NC server (via OC_CONSOLE mode to skip the
 * "not installed" check) so OCP\* classes are autoloadable, plus the
 * app/tool autoloaders for OCA\Mail\* and the ChristophWurst testing
 * helper.
 *
 * Usage: vendor/bin/phpunit --bootstrap tests/bootstrap-local.php ...
 *
 * Paths assume the platform layout: mail at apps/mail/, tests at
 * overlay/tests/ (symlinked from .build/tests/), assembled build at
 * apps/mail/.build/, NC server source at apps/server/.
 */

define('PHPUNIT_RUN', 1);
// CLI/console mode — lets NC skip the "redirect to installer" path in
// OC::checkInstalled() and load the framework without a live install.
define('OC_CONSOLE', 1);

// NC server init — registers \OC\Autoloader (handles OCP\* and OC\*),
// sets OC::$SERVERROOT, loads system config, etc. Without this, OCP\*
// classes can't be found.
require_once __DIR__ . '/../../../server/lib/base.php';

// App composer autoloader — OCA\Mail\* via psr-4.
require_once __DIR__ . '/../../.build/vendor/autoload.php';
// Phpunit tool deps (includes christophwurst/nextcloud_testing).
require_once __DIR__ . '/../../.build/vendor-bin/phpunit/vendor/autoload.php';

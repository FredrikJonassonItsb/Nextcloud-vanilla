<?php

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * PHPStan bootstrap file for static analysis.
 * Defines OC_CONSOLE to skip installation check in server bootstrap.
 */

define('OC_CONSOLE', 1);

require_once __DIR__ . '/../server/lib/base.php';

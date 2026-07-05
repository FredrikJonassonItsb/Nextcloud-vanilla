<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Standalone-mode test stubs (loaded from tests/bootstrap.php ONLY when
 * Doctrine is absent): OCP\DB\QueryBuilder\IQueryBuilder's PARAM_* constants
 * are defined in terms of Doctrine\DBAL classes, so merely autoloading the
 * interface (e.g. when mocking IDBConnection) requires them. The nextcloud/ocp
 * dev package does not ship Doctrine; pulling all of doctrine/dbal for a few
 * class constants would be waste. Values match DBAL 3.x.
 *
 * In mode (1) — a real Nextcloud checkout — the server's Doctrine wins and
 * these stubs are never loaded.
 */

namespace Doctrine\DBAL {
    if (!class_exists(ParameterType::class)) {
        final class ParameterType {
            public const NULL = 0;
            public const INTEGER = 1;
            public const STRING = 2;
            public const LARGE_OBJECT = 3;
            public const BOOLEAN = 5;
            public const BINARY = 16;
            public const ASCII = 17;

            private function __construct() {
            }
        }
    }

    if (!class_exists(ArrayParameterType::class)) {
        final class ArrayParameterType {
            public const INTEGER = 101;
            public const STRING = 102;
            public const ASCII = 117;
            public const BINARY = 116;

            private function __construct() {
            }
        }
    }
}

namespace Doctrine\DBAL\Types {
    if (!class_exists(Types::class)) {
        final class Types {
            public const ASCII_STRING = 'ascii_string';
            public const BIGINT = 'bigint';
            public const BINARY = 'binary';
            public const BLOB = 'blob';
            public const BOOLEAN = 'boolean';
            public const DATE_MUTABLE = 'date';
            public const DATE_IMMUTABLE = 'date_immutable';
            public const DATEINTERVAL = 'dateinterval';
            public const DATETIME_MUTABLE = 'datetime';
            public const DATETIME_IMMUTABLE = 'datetime_immutable';
            public const DATETIMETZ_MUTABLE = 'datetimetz';
            public const DATETIMETZ_IMMUTABLE = 'datetimetz_immutable';
            public const DECIMAL = 'decimal';
            public const FLOAT = 'float';
            public const GUID = 'guid';
            public const INTEGER = 'integer';
            public const JSON = 'json';
            public const SIMPLE_ARRAY = 'simple_array';
            public const SMALLINT = 'smallint';
            public const STRING = 'string';
            public const TEXT = 'text';
            public const TIME_MUTABLE = 'time';
            public const TIME_IMMUTABLE = 'time_immutable';

            private function __construct() {
            }
        }
    }
}

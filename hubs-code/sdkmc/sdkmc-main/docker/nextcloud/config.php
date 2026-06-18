<?php

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

$CONFIG = [
    'htaccess.RewriteBase' => '/',
    'profiler' => true,
    'apps_paths' => [
        0 => [
            'path' => '/var/www/html/apps',
            'url' => '/apps',
            'writable' => true,
        ],
        1 => [
            'path' => '/var/www/html/apps-extra',
            'url' => '/apps-extra',
            'writable' => true,
        ],
    ],

    'allow_local_remote_servers' => true,

    'mail_from_address' => 'admin',
    'mail_smtpmode' => 'smtp',
    'mail_sendmailmode' => 'smtp',
    'mail_domain' => 'mailhog',
    'mail_smtphost' => 'mail',
    'mail_smtpport' => '1025',

    //'skeletondirectory' => '/skeleton',

    //'setup_create_db_user' => false,

    'debug' => true,
    'loglevel' => 2,

    'log_query' => false,
    'query_log_file' => '/home/developer/nextcloud-query.log',
    'query_log_file_requestid' => 'yes',

    'diagnostics.logging' => false,
    'diagnostics.logging.threshold' => 0,
    'log.condition' => [
        'apps' => [
            'admin_audit',
            'diagnostics',
        ],
    ],
];

<?php

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/Utils/NameCleaner.php';

use OCA\SdkMc\Utils\NameCleaner;

function testCleanName() {
    $testCases = [
        'John Doe' => 'John Doe',
        'José María' => 'José María',
        'John 😀 Doe' => 'John Doe',
        '张三' => '张三',
        'محمد' => 'محمد',
        'Αλέξανδρος' => 'Αλέξανδρος',
        'Владимир' => 'Владимир',
        'John🚀🎉Doe' => 'John Doe',
        'John   Doe' => 'John Doe',
        '  John Doe  ' => 'John Doe',
        '' => '',
        '😀😀😀' => '',

        // Scandinavian names
        'Åsa Lindström' => 'Åsa Lindström',
        'Björn Ångström' => 'Björn Ångström',
        'Märta Öberg' => 'Märta Öberg',
        'Søren Kierkegaard' => 'Søren Kierkegaard',
        'Åse Mølgaard' => 'Åse Mølgaard',
        'Niels Børge' => 'Niels Børge',
        'Bjørn Dæhlie' => 'Bjørn Dæhlie',
        'Åsne Seierstad' => 'Åsne Seierstad',
        'Øyvind Blunck' => 'Øyvind Blunck',
        'Kåre Willoch' => 'Kåre Willoch',

        // Names with digits (must NOT be stripped — digits match \p{Emoji})
        'Autohandläggare 1' => 'Autohandläggare 1',
        'Room 42' => 'Room 42',
        'Test #hash' => 'Test #hash',

        // Mixed cases with emojis and Scandinavian characters
        'Åsa 😀 Lindström' => 'Åsa Lindström',
        '🎉Björn Ångström🎉' => 'Björn Ångström',
        'Søren 🚀 Kierkegaard' => 'Søren Kierkegaard',
    ];

    foreach ($testCases as $input => $expected) {
        $result = NameCleaner::cleanName($input);
        echo ($result === $expected ? '✅' : '⛔️') . " '$input' -> '$result' (expected '$expected')\n";
    }
}

testCleanName();

<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

require_once './vendor/autoload.php';

use Nextcloud\CodingStandard\Config as Base2;

class Config extends Base2 {
    public function __construct(string $name = 'default') {
        parent::__construct($name);
        $this->setIndent('    ');
    }

    public function getRules(): array {
        return array_merge(parent::getRules(), ['@PSR12' => true,         'header_comment' => ['validator' => '/^(SPDX-FileCopyrightText: .*\n)*SPDX-FileCopyrightText: 2025 ITSL <info@itsl.se>\nSPDX-License-Identifier: AGPL-3.0-or-later$/', 'header' => "SPDX-FileCopyrightText: ITSL <info@itsl.se>\nSPDX-License-Identifier: AGPL-3.0-or-later"],
            'no_extra_blank_lines' => ['tokens' =>  ['attribute', 'break', 'case', 'continue', 'curly_brace_block', 'default', 'extra', 'parenthesis_brace_block', 'return', 'square_brace_block', 'switch', 'throw', 'use', 'use_trait']]
        ]);
    }
}

$config = new Config();

$config
    ->getFinder()
    ->ignoreVCSIgnored(true)
    ->notPath('ansible')
    ->notPath('bin')
    ->notPath('docker')
    ->notPath('.git')
    ->notPath('.gitlab')
    ->notPath('l10n')
    ->notPath('LICENSES')
    ->notPath('vendor')
    ->notPath('build')
    ->notPath('src')
    ->in(__DIR__);

return $config;

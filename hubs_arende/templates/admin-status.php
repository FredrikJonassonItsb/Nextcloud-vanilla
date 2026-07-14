<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Admin status page for the ärende-motor. Server-rendered, PII-FREE: it prints only
 * counts, configuration and the datadrivna typ-registret — never case content. All
 * dynamic values go through p() (escaped output).
 *
 * @var array $_
 */

$status = $_['status'] ?? [];
$intMode = $status['integrationMode'] ?? [];
$typer = $status['arendetyper'] ?? [];
$brainGreeting = $_['brainGreeting'] ?? 'alla';
$botConfigured = (bool)($_['botConfigured'] ?? false);
$greetingLabel = [
    'alla' => 'På — i både ärenderum och dokument-chattar',
    'arenderum' => 'Endast ärendets diskussionsrum',
    'off' => 'Av',
][$brainGreeting] ?? $brainGreeting;

/**
 * Render a value→count map as a small two-column table.
 *
 * @param array<string,int> $map
 */
$renderCounts = static function (array $map): void {
    if ($map === []) {
        echo '<em>—</em>';
        return;
    }
    echo '<table class="grid"><tbody>';
    foreach ($map as $k => $v) {
        echo '<tr><td style="padding-right:1.5em">';
        p($k);
        echo '</td><td><strong>';
        p((string)$v);
        echo '</strong></td></tr>';
    }
    echo '</tbody></table>';
};
?>
<div class="section" id="hubs_arende_status">
	<h2>Hubs Ärende — motorstatus</h2>
	<p class="settings-hint">
		Aggregerad, läs-bara status för den huvudlösa ärende-motorn. Inga personuppgifter visas —
		endast räknare, konfiguration och det datadrivna ärendetyp-registret.
	</p>

	<h3>Översikt</h3>
	<table class="grid"><tbody>
		<tr><td style="padding-right:1.5em">App-version</td><td><strong><?php p($status['version'] ?? '?'); ?></strong></td></tr>
		<tr><td>Antal ärenden i registret</td><td><strong><?php p((string)($status['totalCases'] ?? 0)); ?></strong></td></tr>
		<tr><td>Väntar på gallring (förfallna)</td><td><strong><?php p((string)($status['gallringsbaraPending'] ?? 0)); ?></strong></td></tr>
	</tbody></table>

	<h3>Integrationsläge (per port)</h3>
	<p class="settings-hint">stub = körbar harness-stubbe · live = skarp integration.</p>
	<table class="grid"><tbody>
		<?php foreach ($intMode as $port => $mode): ?>
		<tr>
			<td style="padding-right:1.5em"><?php p($port); ?></td>
			<td><strong><?php p($mode); ?></strong></td>
		</tr>
		<?php endforeach; ?>
	</tbody></table>

	<h3>AI-assistent (Ärende-brain)</h3>
	<p class="settings-hint">
		Brain-boten aktiveras automatiskt i varje ärendes diskussionsrum och i dokumentens
		Collabora-chatt, och postar en inledande hälsning så handläggaren ser att AI-stödet
		finns (<code>!hjälp</code>, <code>!brief</code>, <code>!råd</code> m.fl.).
	</p>
	<table class="grid"><tbody>
		<tr>
			<td style="padding-right:1.5em">Inledande hälsning</td>
			<td><strong><?php p($greetingLabel); ?></strong></td>
		</tr>
		<tr>
			<td style="padding-right:1.5em">Bot-postning konfigurerad</td>
			<td><strong><?php p($botConfigured ? 'Ja' : 'Nej (talk_bot_id / talk_bot_secret saknas)'); ?></strong></td>
		</tr>
	</tbody></table>
	<p class="settings-hint">
		Ändra läget: <code>occ config:app:set hubs_arende brain_greeting --value alla|arenderum|off</code>
	</p>

	<h3>Ärenden per steg</h3>
	<?php $renderCounts($status['perSteg'] ?? []); ?>

	<h3>Ärenden per status</h3>
	<?php $renderCounts($status['perStatus'] ?? []); ?>

	<h3>Ärenden per provenans</h3>
	<?php $renderCounts($status['perProvenance'] ?? []); ?>

	<h3>Ärenden per commit-destination</h3>
	<?php $renderCounts($status['perCommitDestination'] ?? []); ?>

	<h3>Ärendetyp-register (<?php p((string)count($typer)); ?> typer)</h3>
	<table class="grid">
		<thead>
			<tr>
				<th style="text-align:left;padding-right:1.5em">Id</th>
				<th style="text-align:left;padding-right:1.5em">Namn</th>
				<th style="text-align:left;padding-right:1.5em">Commit-destination</th>
				<th style="text-align:left;padding-right:1.5em">Pre-saga-hook</th>
				<th style="text-align:left">Post-commit-hook</th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ($typer as $typ): ?>
			<tr>
				<td style="padding-right:1.5em"><?php p($typ['id'] ?? ''); ?></td>
				<td style="padding-right:1.5em"><?php p($typ['displayName'] ?? ''); ?></td>
				<td style="padding-right:1.5em"><?php p($typ['commitDestination'] ?? ''); ?></td>
				<td style="padding-right:1.5em"><?php p($typ['preSagaHook'] ?? '—'); ?></td>
				<td><?php p($typ['postCommitHook'] ?? '—'); ?></td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>

<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Personal "Min agent" settings — read-only + instructional. Shows the user's
 * agent connection and which of their Deck boards are active. Activation itself
 * is the Deck-share auto-enroll (share the board with "Agent Engine"); this page
 * only explains and reflects it. All dynamic values go through p() (escaped).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

$hasAgent = (bool)($_['hasAgent'] ?? false);
$agents = $_['agents'] ?? [];
$boards = $_['boards'] ?? [];
$engineBotName = (string)($_['engineBotName'] ?? 'Agent Engine');

$presenceDot = static function (array $presence): string {
    if (!empty($presence['paused'])) {
        return '⏸';
    }
    if (!empty($presence['online'])) {
        return '🟢';
    }
    if (!empty($presence['stale'])) {
        return '🟡';
    }
    return '⚪';
};
?>
<div class="section" id="agent_engine_personal">
	<h2><?php p($l->t('Min agent')); ?></h2>

	<h3><?php p($l->t('Din agentkoppling')); ?></h3>
	<?php if (!$hasAgent): ?>
		<p class="settings-hint">
			<?php p($l->t('Ingen agent är kopplad till dig — kontakta en administratör.')); ?>
			<br>
			<?php p($l->t('En administratör konfigurerar kopplingen i adminsektionen (Agent Engine).')); ?>
		</p>
	<?php else: ?>
		<?php foreach ($agents as $agent): ?>
			<table class="grid"><tbody>
				<tr>
					<td style="padding-right:1.5em"><?php p($l->t('Agent')); ?></td>
					<td><strong><?php p($agent['agent'] ?? ''); ?></strong> (<?php p($agent['agentCode'] ?? ''); ?>)</td>
				</tr>
				<tr>
					<td style="padding-right:1.5em"><?php p($l->t('Hjärna')); ?></td>
					<td><?php p($agent['brain'] ?? ''); ?></td>
				</tr>
				<tr>
					<td style="padding-right:1.5em"><?php p($l->t('Talk-minnesrum')); ?></td>
					<td><?php p($agent['talkRoom'] ?? ''); ?></td>
				</tr>
				<tr>
					<td style="padding-right:1.5em"><?php p($l->t('Närvaro')); ?></td>
					<td><?php p($presenceDot($agent['presence'] ?? [])); ?> <?php p($agent['presence']['label'] ?? ''); ?></td>
				</tr>
			</tbody></table>
		<?php endforeach; ?>
	<?php endif; ?>

	<h3><?php p($l->t('Aktivera en tavla')); ?></h3>
	<p class="settings-hint">
		<?php p($l->t('Aktivera en tavla: dela den med kontot "%s" i Deck.', [$engineBotName])); ?>
		<?php p($l->t('Avaktivera: ta bort delningen.')); ?>
		<br>
		<?php p($l->t('När du delar en tavla med Agent Engine kan dina agenter ta över tilldelade kort. Tavlor med klient- eller ärende-PII ska inte delas.')); ?>
	</p>

	<h3><?php p($l->t('Dina Deck-tavlor')); ?></h3>
	<?php if ($boards === []): ?>
		<p class="settings-hint">
			<?php p($l->t('Inga tavlor hittades (eller Deck kunde inte nås just nu).')); ?>
		</p>
	<?php else: ?>
		<table class="grid">
			<thead>
				<tr>
					<th style="text-align:left;padding-right:1.5em"><?php p($l->t('Tavla')); ?></th>
					<th style="text-align:left"><?php p($l->t('Status')); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($boards as $board): ?>
				<tr>
					<td style="padding-right:1.5em"><?php p($board['title'] ?? ''); ?></td>
					<td>
						<?php if (!empty($board['enrolled'])): ?>
							<strong style="color:#2E7D32">● <?php p($l->t('Aktiv')); ?></strong>
						<?php else: ?>
							<span style="color:#888">○ <?php p($l->t('Inaktiv')); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>

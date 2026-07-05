<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Admin "Agent Engine" settings — read-only health + configuration view (v1).
 * Shows the routing map (human ↔ agent), enrolled boards, the bot users and
 * engine health. PII-free: only ids, uids, counts and configuration. All
 * dynamic values go through p() (escaped output).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

$routingRows = $_['routingRows'] ?? [];
$enrolledBoards = $_['enrolledBoards'] ?? [];
$bots = $_['bots'] ?? [];
$health = $_['health'] ?? [];

$yesNo = static function (bool $v) use ($l): string {
    return $v ? $l->t('ja') : $l->t('nej');
};
?>
<div class="section" id="agent_engine_admin">
	<h2><?php p($l->t('Agent Engine')); ?></h2>
	<p class="settings-hint">
		<?php p($l->t('Läs-bar status för agentmotorn. Routingkartan sätts via provision/occ-provision.sh (HUMAN_UID_*) tills en redigerare byggs.')); ?>
	</p>

	<h3><?php p($l->t('Routingkarta (människa ↔ agent)')); ?></h3>
	<table class="grid">
		<thead>
			<tr>
				<th style="text-align:left;padding-right:1.5em"><?php p($l->t('Människa (uid)')); ?></th>
				<th style="text-align:left;padding-right:1.5em"><?php p($l->t('Agent')); ?></th>
				<th style="text-align:left;padding-right:1.5em"><?php p($l->t('Agentkod')); ?></th>
				<th style="text-align:left"><?php p($l->t('Bot-användare')); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ($routingRows as $row): ?>
			<tr>
				<td style="padding-right:1.5em"><?php p($row['human'] !== '' ? $row['human'] : '—'); ?></td>
				<td style="padding-right:1.5em"><?php p($row['agent'] ?? ''); ?></td>
				<td style="padding-right:1.5em"><?php p($row['agentCode'] ?? ''); ?></td>
				<td><?php p($row['bot'] ?? ''); ?></td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<h3><?php p($l->t('Enrollade tavlor')); ?> (<?php p((string)count($enrolledBoards)); ?>)</h3>
	<?php if ($enrolledBoards === []): ?>
		<p class="settings-hint"><?php p($l->t('Inga aktiva tavlor.')); ?></p>
	<?php else: ?>
		<table class="grid">
			<thead>
				<tr>
					<th style="text-align:left;padding-right:1.5em"><?php p($l->t('Tavla')); ?></th>
					<th style="text-align:left;padding-right:1.5em"><?php p($l->t('Id')); ?></th>
					<th style="text-align:left;padding-right:1.5em"><?php p($l->t('on_done')); ?></th>
					<th style="text-align:left;padding-right:1.5em"><?php p($l->t('Enrollad av')); ?></th>
					<th style="text-align:left"><?php p($l->t('PII granskad av')); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($enrolledBoards as $board): ?>
				<tr>
					<td style="padding-right:1.5em"><?php p($board['title'] ?? ''); ?></td>
					<td style="padding-right:1.5em"><?php p((string)($board['boardId'] ?? '')); ?></td>
					<td style="padding-right:1.5em"><?php p($board['onDone'] ?? ''); ?></td>
					<td style="padding-right:1.5em"><?php p($board['enrolledBy'] !== '' ? $board['enrolledBy'] : '—'); ?></td>
					<td><?php p($board['piiReviewedBy'] !== '' ? $board['piiReviewedBy'] : '—'); ?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<h3><?php p($l->t('Bot-användare')); ?></h3>
	<p class="settings-hint"><?php p(implode(' · ', array_map('strval', $bots))); ?></p>

	<h3><?php p($l->t('Motorhälsa')); ?></h3>
	<table class="grid"><tbody>
		<tr><td style="padding-right:1.5em"><?php p($l->t('Engine-tavla (board id)')); ?></td><td><strong><?php p((string)($health['engineBoardId'] ?? 0)); ?></strong></td></tr>
		<tr><td style="padding-right:1.5em"><?php p($l->t('Liggarkort (card id)')); ?></td><td><strong><?php p((string)($health['ledgerCardId'] ?? 0)); ?></strong></td></tr>
		<tr><td style="padding-right:1.5em"><?php p($l->t('Runner-bas')); ?></td><td><?php p((string)($health['runnerBase'] ?? '')); ?></td></tr>
		<tr><td style="padding-right:1.5em"><?php p($l->t('Push-secret konfigurerad')); ?></td><td><strong><?php p($yesNo((bool)($health['pushSecretConfigured'] ?? false))); ?></strong></td></tr>
		<tr><td style="padding-right:1.5em"><?php p($l->t('Bot-credential konfigurerad')); ?></td><td><strong><?php p($yesNo((bool)($health['botCredentialConfigured'] ?? false))); ?></strong></td></tr>
		<tr><td style="padding-right:1.5em"><?php p($l->t('Antal PII-mönster')); ?></td><td><strong><?php p((string)($health['piiPatternCount'] ?? 0)); ?></strong></td></tr>
	</tbody></table>
</div>

<?php
/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
?>
<div id="hubs-start-admin" class="section">
    <h2><?php p($l->t('Hubs Start — Flödesnavet')); ?></h2>
    <p class="settings-hint">
        <?php p($l->t('Gör Hubs Start till förstavyn efter inloggning med:')); ?>
        <code>occ config:system:set defaultapp --value='hubs_start,dashboard,files'</code>
    </p>

    <h3><?php p($l->t('Rollprofiler (grupper)')); ?></h3>
    <p class="settings-hint">
        <?php p($l->t('Användare i förvaltargruppen ser sektionen Systemhälsa. Registratorgruppen får tangentbordsläge som förval.')); ?>
    </p>
    <p>
        <label for="hubs-start-group-forvaltare"><?php p($l->t('Förvaltargrupp')); ?></label>
        <input type="text" id="hubs-start-group-forvaltare"
               value="<?php p($_['group_forvaltare']); ?>"
               data-key="group_forvaltare">
    </p>
    <p>
        <label for="hubs-start-group-registrator"><?php p($l->t('Registratorgrupp')); ?></label>
        <input type="text" id="hubs-start-group-registrator"
               value="<?php p($_['group_registrator']); ?>"
               data-key="group_registrator">
    </p>
    <p class="settings-hint">
        <?php p($l->t('Spara genom occ config:app:set hubs_start group_forvaltare --value=...')); ?>
    </p>

    <h3><?php p($l->t('Demo-data')); ?></h3>
    <p class="settings-hint">
        <?php p($l->t('DEV/DEMO — endast för demomiljö. Återställer demo-läget till utgångsläget: seedar om syntetiska demo-ärenden i ärende-motorn och nollställer dashboardens demo-konfiguration. Inga personuppgifter berörs.')); ?>
    </p>
    <p>
        <button type="button" id="hubs-start-reseed" class="button">
            <?php p($l->t('Återställ demo-data till utgångsläge')); ?>
        </button>
    </p>
    <p id="hubs-start-reseed-status" class="settings-hint" role="status" aria-live="polite"></p>
</div>

<?php
/**
 * GovPortal - Admin settings template
 */

declare(strict_types=1);

/** @var array $_ */
?>

<div id="govportal-admin-settings" class="section">
    <h2>Kommunportal - Inställningar</h2>

    <p class="settings-hint">
        Konfigurera hur Kommunportalen ska fungera för användare i din organisation.
    </p>

    <form id="govportal-admin-form">
        <div class="govportal-setting">
            <input type="checkbox"
                   id="govportal-set-as-default"
                   name="setAsDefault"
                   class="checkbox"
                   <?php echo $_['setAsDefault'] ? 'checked' : ''; ?>>
            <label for="govportal-set-as-default">
                Sätt som standardsida efter inloggning
            </label>
            <p class="settings-hint">
                Om aktiverat kommer användare att se Kommunportalen direkt efter inloggning istället för filsidan.
            </p>
        </div>

        <div class="govportal-setting">
            <label for="govportal-allowed-groups">
                Begränsa till grupper (lämna tomt för alla)
            </label>
            <select id="govportal-allowed-groups"
                    name="allowedGroups"
                    class="multiselect"
                    multiple="multiple"
                    data-placeholder="Välj grupper...">
                <!-- Groups will be populated via JavaScript -->
            </select>
            <p class="settings-hint">
                Endast användare i de valda grupperna kommer att se Kommunportalen.
            </p>
        </div>

        <button type="submit" class="primary">
            Spara inställningar
        </button>

        <span id="govportal-admin-msg" class="msg" style="display: none;"></span>
    </form>
</div>

<style>
    #govportal-admin-settings {
        max-width: 800px;
    }

    .govportal-setting {
        margin-bottom: 1.5rem;
        padding: 1rem;
        background: #f8fafc;
        border-radius: 8px;
    }

    .govportal-setting label {
        display: block;
        font-weight: 600;
        margin-bottom: 0.5rem;
    }

    .govportal-setting .settings-hint {
        margin-top: 0.5rem;
        color: #64748b;
        font-size: 0.875rem;
    }

    .govportal-setting .checkbox + label {
        display: inline;
        font-weight: normal;
        margin-left: 0.5rem;
    }

    #govportal-admin-msg {
        margin-left: 1rem;
    }

    #govportal-admin-msg.success {
        color: #107C10;
    }

    #govportal-admin-msg.error {
        color: #D13438;
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('govportal-admin-form');
    const msg = document.getElementById('govportal-admin-msg');

    form.addEventListener('submit', async function(e) {
        e.preventDefault();

        const setAsDefault = document.getElementById('govportal-set-as-default').checked;

        try {
            const response = await fetch(OC.generateUrl('/apps/govportal/admin/settings'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken,
                },
                body: JSON.stringify({
                    setAsDefault: setAsDefault,
                }),
            });

            if (response.ok) {
                msg.textContent = 'Inställningar sparade';
                msg.className = 'msg success';
            } else {
                throw new Error('Failed to save');
            }
        } catch (error) {
            msg.textContent = 'Kunde inte spara inställningar';
            msg.className = 'msg error';
        }

        msg.style.display = 'inline';
        setTimeout(() => {
            msg.style.display = 'none';
        }, 3000);
    });
});
</script>

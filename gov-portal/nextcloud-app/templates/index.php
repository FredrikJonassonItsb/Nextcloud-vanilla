<?php
/**
 * GovPortal - Main template
 *
 * This template serves as the container for the React SPA.
 * The actual UI is rendered by the JavaScript bundle.
 */

declare(strict_types=1);

use OCP\Util;

// Get the app's web path and CSP nonce for script loading
$appWebPath = \OC_App::getAppWebPath('govportal');
$nonce = \OC::$server->getContentSecurityPolicyNonceManager()->getNonce();
?>

<div id="app-content">
    <!-- Load the React app as ES module with CSP nonce -->
    <script type="module" nonce="<?php echo $nonce; ?>" src="<?php echo $appWebPath; ?>/js/govportal-main.js"></script>
    <div id="govportal-root" class="govportal-container">
        <!-- React app will mount here -->
        <noscript>
            <div class="govportal-noscript">
                <h1>JavaScript krävs</h1>
                <p>Denna portal kräver JavaScript för att fungera. Vänligen aktivera JavaScript i din webbläsare.</p>
            </div>
        </noscript>
    </div>
</div>

<style>
    /* Base styles while React loads */
    .govportal-container {
        height: 100%;
        min-height: calc(100vh - 50px);
        background-color: #f8fafc;
    }

    .govportal-noscript {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        min-height: 50vh;
        padding: 2rem;
        text-align: center;
    }

    .govportal-noscript h1 {
        color: #1e293b;
        margin-bottom: 1rem;
    }

    .govportal-noscript p {
        color: #64748b;
    }

    /* Loading indicator */
    .govportal-container:empty::after {
        content: '';
        display: block;
        width: 40px;
        height: 40px;
        margin: 20vh auto;
        border: 3px solid #e2e8f0;
        border-top-color: #005aa7;
        border-radius: 50%;
        animation: govportal-spin 1s linear infinite;
    }

    @keyframes govportal-spin {
        to {
            transform: rotate(360deg);
        }
    }
</style>

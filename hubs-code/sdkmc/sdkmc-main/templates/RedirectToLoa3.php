<?php
$url = $_['url'];

$nonceManager = \OC::$server->get(\OC\Security\CSP\ContentSecurityPolicyNonceManager::class);
$nonce = $nonceManager->getNonce();
?>
<div id='location' data-location="<?php p($url); ?>"></div>

<script nonce="<?php p($nonce); ?>">
  (function() {
    const root = document.getElementById('location');
    if (!root) return;

    window.location = root.dataset.location;
  })()
</script>

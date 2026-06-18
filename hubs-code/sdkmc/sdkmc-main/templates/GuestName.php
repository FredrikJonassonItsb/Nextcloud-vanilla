<?php
use OC\Security\CSRF\CsrfTokenManager;
use OC\Security\CSP\ContentSecurityPolicyNonceManager;

$token = is_string($_['token'] ?? null) ? $_['token'] : '';
$vf    = is_string($_['vf'] ?? null) ? $_['vf'] : '';
$requestToken = \OC::$server->get(CsrfTokenManager::class)->getToken()->getEncryptedValue();
$nonce = \OC::$server->get(ContentSecurityPolicyNonceManager::class)->getNonce();
?>

<div id="sdkmc-guest-name"
     data-token="<?php p($token); ?>"
     data-vf="<?php p($vf); ?>"
     data-requesttoken="<?php p($requestToken); ?>">

    <h1>What is your name?</h1>

    <input id="guestNameInput"
           type="text"
           placeholder="Your name"
           autocomplete="name"
           autofocus />

    <button id="joinCallBtn">Join the call</button>

    <p id="sdkmc-name-error" style="color:red; display:none;">
        Please enter your name.
    </p>
</div>

<style>
#sdkmc-guest-name {
  max-width: 420px;
  margin: 4rem auto;
  padding: 1.5rem;
  border-radius: 12px;
  background: #fff;
  box-shadow: 0 2px 12px rgba(0,0,0,.08);
  font-family: sans-serif;
}
#sdkmc-guest-name h1 { font-size: 1.25rem; margin-bottom: 1rem; }
#sdkmc-guest-name input {
  width: 100%; height: 40px; padding: 0 .75rem;
  border: 1px solid #ccc; border-radius: 8px; margin-bottom: 1rem;
}
#sdkmc-guest-name button {
  height: 40px; padding: 0 14px; border: none;
  border-radius: 8px; cursor: pointer;
  background: #1a73e8; color: #fff; font-weight: 600;
}
</style>

<script nonce="<?php p($nonce); ?>">
(function () {
  const root  = document.getElementById('sdkmc-guest-name');
  if (!root) return;

  const token = root.dataset.token || '';
  const vf    = root.dataset.vf || '';
  const rt    = root.dataset.requesttoken || '';

  const input = document.getElementById('guestNameInput');
  const btn   = document.getElementById('joinCallBtn');
  const err   = document.getElementById('sdkmc-name-error');

  function join() {
    const name = (input.value || '').trim();
    if (!name) {
      err.style.display = 'block';
      input.focus();
      return;
    }
    err.style.display = 'none';

    const params = new URLSearchParams();
    params.set('userName', name);
    if (vf) params.set('vf', vf);
    if (token) params.set('token', token);
    if (rt) params.set('requesttoken', rt);
    const encodedName = encodeURIComponent(name);

    window.location.href = `/apps/sdkmc/guest/update/${encodedName}?${params.toString()}`;
  }

  btn.addEventListener('click', join);
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') join();
  });
})();
</script>

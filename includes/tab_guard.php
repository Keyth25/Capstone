<?php
// tab_guard.php - Bind the PHP session to this browser tab.
//
// A PHP session cookie survives tab closure (and mobile app restarts where
// the browser process stays alive), so session state alone cannot detect a
// closed tab. sessionStorage, however, is wiped when the tab closes. On login
// we store the session id in sessionStorage; any page that finds a live PHP
// session without a matching sentinel forces a logout so the app returns to
// the Sign In / Get Started state instead of resurrecting a stale session.
if (!empty($_SESSION['user_id'])):
    $cnSid = json_encode(session_id());
?>
<script>
(function () {
    var ok = false;
    try { ok = sessionStorage.getItem('cn_auth') === <?= $cnSid ?>; } catch (e) {}
    if (!ok) location.replace('logout.php');
})();
</script>
<?php endif; ?>

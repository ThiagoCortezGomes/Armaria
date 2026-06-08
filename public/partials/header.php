<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
$user = $_SESSION['user'] ?? ['name' => 'Usuário', 'role' => 'POLICIAL'];
?>
<header class="main-header">
  <div class="brand">Armaria</div>
  <div class="header-right">
    <div class="header-user">
      <span class="u-name"><?= htmlspecialchars((string)($user['name'] ?? 'Usuário')) ?></span>
      <span class="u-role"><?= htmlspecialchars((string)($user['role'] ?? 'POLICIAL')) ?></span>
    </div>
    <a class="btn-logout" href="logout.php">Sair</a>
  </div>
</header>

<!-- Modal de inatividade -->
<div id="idle-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:10px;padding:36px 32px;max-width:380px;width:90%;text-align:center;box-shadow:0 8px 40px rgba(0,0,0,.3);">
    <div style="font-size:2.5rem;margin-bottom:10px;">&#9201;</div>
    <h3 style="margin:0 0 10px;color:#b71c1c;font-size:1.2rem;">Sessão expirando</h3>
    <p style="margin:0 0 18px;color:#555;font-size:.95rem;">
      Você ficou inativo por muito tempo.<br>A sessão será encerrada em:
    </p>
    <div id="idle-countdown" style="font-size:2.8rem;font-weight:700;color:#b71c1c;letter-spacing:3px;margin-bottom:24px;font-variant-numeric:tabular-nums;">05:00</div>
    <button id="idle-stay" style="background:#2e7d32;color:#fff;border:none;border-radius:6px;padding:11px 32px;font-size:1rem;cursor:pointer;font-weight:600;">
      Continuar conectado
    </button>
  </div>
</div>

<script>
(function () {
  var IDLE_MS  = 60 * 60 * 1000;
  var WARN_MS  = 5 * 60 * 1000;
  var WARN_AT  = IDLE_MS - WARN_MS;

  var warnTimer, logoutTimer, countdownInterval;
  var warnShown = false;

  var modal       = document.getElementById('idle-modal');
  var countdownEl = document.getElementById('idle-countdown');
  var stayBtn     = document.getElementById('idle-stay');

  function fmt(s) {
    var m = Math.floor(s / 60);
    var sec = s % 60;
    return (m < 10 ? '0' : '') + m + ':' + (sec < 10 ? '0' : '') + sec;
  }

  function doLogout() {
    window.location.href = 'logout.php?timeout=1';
  }

  function showWarning() {
    warnShown = true;
    modal.style.display = 'flex';
    var remaining = Math.round(WARN_MS / 1000);
    countdownEl.textContent = fmt(remaining);
    countdownInterval = setInterval(function () {
      remaining--;
      countdownEl.textContent = fmt(remaining);
      if (remaining <= 0) {
        clearInterval(countdownInterval);
        doLogout();
      }
    }, 1000);
    logoutTimer = setTimeout(doLogout, WARN_MS);
  }

  function resetTimer() {
    if (warnShown) return;
    clearTimeout(warnTimer);
    warnTimer = setTimeout(showWarning, WARN_AT);
  }

  stayBtn.addEventListener('click', function () {
    clearTimeout(logoutTimer);
    clearInterval(countdownInterval);
    modal.style.display = 'none';
    warnShown = false;
    fetch('ping.php', { method: 'POST', credentials: 'same-origin' }).catch(function () {});
    resetTimer();
  });

  ['mousemove', 'mousedown', 'keypress', 'scroll', 'touchstart', 'click'].forEach(function (ev) {
    document.addEventListener(ev, resetTimer, { passive: true });
  });

  resetTimer();
}());
</script>

(() => {
  const alertButton = document.getElementById('enableDesktopAlerts');
  let currentState = window.hxInitialAlerts || null;

  function setText(selector, value) {
    const node = document.querySelector(selector);
    if (node) node.textContent = String(value ?? 0);
  }

  function updateAlertChrome(data) {
    document.querySelectorAll('[data-alert-count]').forEach((badge) => {
      badge.textContent = String(data.count || 0);
      badge.hidden = !data.count;
    });
    setText('[data-alert-summary]', data.body || 'No urgent admin alerts.');
    setText('[data-stat-payment-pending]', data.paymentStats?.pending);
    setText('[data-stat-payment-overdue]', data.paymentStats?.overdue);
    setText('[data-stat-callback-due]', data.callbackStats?.due);
    setText('[data-stat-callback-today]', data.callbackStats?.today);
  }

  function showDesktopAlert(data, force = false) {
    if (!('Notification' in window) || Notification.permission !== 'granted' || !data.count) return;
    const lastSignature = localStorage.getItem('hx-admin-alert-signature');
    if (!force && lastSignature === data.signature) return;
    new Notification('HarbourX Admin', { body: data.body, tag: 'harbourx-admin-alerts' });
    localStorage.setItem('hx-admin-alert-signature', data.signature);
  }

  async function refreshAlerts() {
    try {
      const response = await fetch('admin_alerts.php', { cache: 'no-store', credentials: 'same-origin' });
      if (!response.ok) return;
      currentState = await response.json();
      updateAlertChrome(currentState);
      showDesktopAlert(currentState);
    } catch (_) {}
  }

  if (alertButton) {
    if (!('Notification' in window)) {
      alertButton.hidden = true;
    } else if (Notification.permission === 'granted') {
      alertButton.textContent = 'Desktop alerts enabled';
      if (currentState) showDesktopAlert(currentState);
    } else if (Notification.permission === 'denied') {
      alertButton.textContent = 'Desktop alerts blocked';
      alertButton.disabled = true;
    }

    alertButton.addEventListener('click', async () => {
      const permission = await Notification.requestPermission();
      if (permission === 'granted') {
        alertButton.textContent = 'Desktop alerts enabled';
        if (!currentState) await refreshAlerts();
        if (currentState) showDesktopAlert(currentState, true);
      } else {
        alertButton.textContent = 'Desktop alerts blocked';
        alertButton.disabled = true;
      }
    });
  }

  if (currentState) updateAlertChrome(currentState);
  else refreshAlerts();
  window.setInterval(refreshAlerts, 60000);
})();


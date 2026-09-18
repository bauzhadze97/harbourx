(() => {
  document.querySelectorAll('[data-panel-toggle]').forEach((button) => {
    const panel = document.getElementById(button.dataset.panelToggle || '');
    if (!panel) return;
    const syncButtons = (open) => {
      document.querySelectorAll(`[data-panel-toggle="${panel.id}"]`).forEach((peer) => {
        peer.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
    };
    if (window.location.hash === `#${panel.id}` || panel.dataset.open === 'true') {
      panel.hidden = false;
      syncButtons(true);
    }
    button.addEventListener('click', () => {
      const willOpen = panel.hidden;
      panel.hidden = !willOpen;
      syncButtons(willOpen);
      if (willOpen) {
        panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        window.setTimeout(() => panel.querySelector('select, input')?.focus(), 280);
      }
    });
  });

  document.querySelectorAll('[data-ops-search]').forEach((input) => {
    const scope = input.closest('[data-ops-scope]') || document;
    const records = Array.from(scope.querySelectorAll('[data-ops-record]'));
    const count = scope.querySelector('[data-visible-count]');
    const noResults = scope.querySelector('[data-search-empty]');
    const update = () => {
      const query = input.value.trim().toLowerCase();
      let visible = 0;
      records.forEach((record) => {
        const match = (record.dataset.search || '').includes(query);
        record.hidden = !match;
        if (match) visible++;
      });
      if (count) count.textContent = String(visible);
      if (noResults) noResults.hidden = visible !== 0;
    };
    input.addEventListener('input', update);
  });

  document.querySelectorAll('[data-payment-form]').forEach((form) => {
    const status = form.querySelector('[data-payment-status]');
    if (!status) return;
    const update = () => {
      form.querySelectorAll('[data-show-for]').forEach((field) => {
        const show = field.dataset.showFor.split(' ').includes(status.value);
        field.hidden = !show;
        const input = field.querySelector('input, textarea, select');
        if (input && field.dataset.requiredFor) input.required = show;
      });
    };
    status.addEventListener('change', update);
    update();
  });
})();

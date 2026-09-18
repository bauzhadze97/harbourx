(() => {
  const root = document.querySelector('[data-dashboard-overview]');
  if (!root) return;

  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  document.querySelectorAll('[data-dashboard-count]').forEach((node) => {
    const target = Number(node.dataset.dashboardCount || 0);
    const decimals = Number(node.dataset.decimals || 0);
    if (reduceMotion || !Number.isFinite(target)) return;
    const duration = 720;
    const startAt = performance.now();
    const render = (now) => {
      const progress = Math.min(1, (now - startAt) / duration);
      const eased = 1 - Math.pow(1 - progress, 3);
      node.textContent = (target * eased).toLocaleString('en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals
      });
      if (progress < 1) requestAnimationFrame(render);
    };
    node.textContent = (0).toFixed(decimals);
    requestAnimationFrame(render);
  });

  const circumference = 301.5929;
  const segments = Array.from(document.querySelectorAll('[data-donut-value]'));
  const total = segments.reduce((sum, segment) => sum + Number(segment.dataset.donutValue || 0), 0);
  let offset = 0;
  segments.forEach((segment) => {
    const value = Number(segment.dataset.donutValue || 0);
    const length = total > 0 ? (value / total) * circumference : 0;
    segment.style.opacity = length > 0 ? '1' : '0';
    segment.style.strokeDashoffset = String(-offset);
    if (!reduceMotion) segment.style.strokeDasharray = `0 ${circumference}`;
    window.requestAnimationFrame(() => {
      segment.style.strokeDasharray = `${length} ${Math.max(0, circumference - length)}`;
    });
    offset += length;
  });

  document.querySelectorAll('[data-dashboard-bar]').forEach((bar) => {
    const value = Number(bar.dataset.value || 0);
    const max = Math.max(1, Number(bar.dataset.max || 1));
    const width = Math.max(0, Math.min(100, (value / max) * 100));
    if (!reduceMotion) bar.style.width = '0%';
    window.requestAnimationFrame(() => { bar.style.width = `${width}%`; });
  });

  const directory = document.querySelector('[data-client-directory]');
  if (directory) {
    const search = directory.querySelector('#clientSearch');
    const filter = directory.querySelector('[data-client-filter]');
    const sort = directory.querySelector('[data-client-sort]');
    const list = directory.querySelector('[data-client-list]');
    const records = Array.from(directory.querySelectorAll('[data-client-record]'));
    const visibleCount = directory.querySelector('[data-client-visible]');
    const empty = directory.querySelector('#clientEmpty');
    const quickView = directory.querySelector('#clientQuickView');
    const clear = directory.querySelector('[data-client-clear]');
    const resetButtons = directory.querySelectorAll('[data-client-reset]');

    const setQuickText = (field, value) => {
      const node = quickView?.querySelector(`[data-quick-field="${field}"]`);
      if (node) node.textContent = value || '—';
    };

    const showQuickView = (record) => {
      if (!quickView || !record) return;
      records.forEach((item) => {
        const selected = item === record;
        item.classList.toggle('is-selected', selected);
        item.querySelector('[data-client-open]')?.setAttribute('aria-pressed', String(selected));
      });

      const amlLabels = { verified: 'Verified', under_review: 'Under review', unverified: 'Unverified' };
      setQuickText('initials', record.dataset.quickInitials);
      setQuickText('name', record.dataset.quickName);
      setQuickText('email', record.dataset.email);
      setQuickText('country', record.dataset.quickCountry);
      setQuickText('currency', `${record.dataset.quickCurrency || 'USD'} account`);
      setQuickText('tx', record.dataset.tx || '0');
      setQuickText('main', record.dataset.quickMain);
      setQuickText('btc', record.dataset.quickBtc);
      setQuickText('payment-title', record.dataset.quickPaymentTitle);
      setQuickText('payment-meta', record.dataset.quickPaymentMeta);
      setQuickText('callback-title', record.dataset.quickCallbackTitle);
      setQuickText('callback-meta', record.dataset.quickCallbackMeta);
      setQuickText('last-title', record.dataset.quickLastTitle);
      setQuickText('last-meta', record.dataset.quickLastMeta);

      const aml = quickView.querySelector('[data-quick-aml]');
      if (aml) {
        aml.className = `aml-badge aml-${record.dataset.aml || 'unverified'}`;
        aml.textContent = amlLabels[record.dataset.aml] || 'Unverified';
      }
      const bank = quickView.querySelector('[data-quick-bank]');
      if (bank) bank.hidden = record.dataset.bank !== '1';

      const setState = (kind, state) => {
        const node = quickView.querySelector(`[data-quick-state="${kind}"]`);
        if (!node) return;
        node.classList.remove('is-danger', 'is-warning', 'is-success');
        if (['overdue', 'missed', 'due'].includes(state)) node.classList.add('is-danger');
        else if (['pending', 'upcoming'].includes(state)) node.classList.add('is-warning');
        else if (state === 'paid' || state === 'completed') node.classList.add('is-success');
      };
      setState('payment', record.dataset.quickPaymentState || 'none');
      setState('callback', record.dataset.quickCallbackState || 'none');

      const email = record.dataset.email || '';
      const encodedEmail = encodeURIComponent(email);
      const destinations = {
        profile: `client.php?email=${encodedEmail}`,
        payment: `payments.php?client=${encodedEmail}#add-payment`,
        callback: `callbacks.php?client=${encodedEmail}#schedule-callback`,
        transactions: `transactions.php?email=${encodedEmail}`
      };
      Object.entries(destinations).forEach(([kind, href]) => {
        const link = quickView.querySelector(`[data-quick-link="${kind}"]`);
        if (link) link.href = href;
      });
      quickView.querySelectorAll('[data-quick-email-field]').forEach((input) => { input.value = email; });
      quickView.querySelectorAll('details').forEach((details) => { details.open = false; });
    };

    const matchesFilter = (record, value) => {
      if (value === 'bank') return record.dataset.bank === '1';
      if (value === 'fee') return record.dataset.fee === '1';
      if (value === 'all') return true;
      return record.dataset.aml === value;
    };

    const update = () => {
      const query = (search?.value || '').trim().toLowerCase();
      const filterValue = filter?.value || 'all';
      const sortValue = sort?.value || 'name';
      const sorted = [...records].sort((a, b) => {
        if (sortValue === 'name') return (a.dataset.name || '').localeCompare(b.dataset.name || '');
        return Number(b.dataset[sortValue] || 0) - Number(a.dataset[sortValue] || 0);
      });
      sorted.forEach((record) => list?.append(record));

      let count = 0;
      records.forEach((record) => {
        const haystack = (record.dataset.search || '').toLocaleLowerCase();
        const matchesSearch = query.split(/\s+/).every((term) => haystack.includes(term));
        const show = matchesSearch && matchesFilter(record, filterValue);
        record.hidden = !show;
        if (show) count++;
      });
      if (visibleCount) visibleCount.textContent = String(count);
      if (clear) clear.hidden = !search?.value;
      resetButtons.forEach((button) => { button.hidden = !query && filterValue === 'all'; });
      if (empty) empty.hidden = count !== 0;
      if (quickView) quickView.hidden = count === 0;
      const selected = records.find((record) => record.classList.contains('is-selected') && !record.hidden);
      if (!selected) showQuickView(sorted.find((record) => !record.hidden));
    };

    records.forEach((record) => {
      record.querySelector('[data-client-open]')?.addEventListener('click', () => {
        showQuickView(record);
        if (window.matchMedia('(max-width: 1100px)').matches) {
          quickView.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'start' });
          quickView.focus({ preventScroll: true });
        }
      });
      record.addEventListener('click', (event) => {
        if (!event.target.closest('a, button, input, select, summary')) showQuickView(record);
      });
    });
    search?.addEventListener('input', update);
    const clearSearch = () => {
      if (search) search.value = '';
      update();
      search?.focus();
    };
    clear?.addEventListener('click', clearSearch);
    resetButtons.forEach((button) => button.addEventListener('click', () => {
      if (filter) filter.value = 'all';
      clearSearch();
    }));
    search?.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        clearSearch();
      }
    });
    document.addEventListener('keydown', (event) => {
      if (event.key !== '/' || event.metaKey || event.ctrlKey || event.altKey) return;
      if (event.target.closest('input, textarea, select, [contenteditable="true"]')) return;
      event.preventDefault();
      search?.focus();
    });
    filter?.addEventListener('change', update);
    sort?.addEventListener('change', update);
    update();
  }
})();

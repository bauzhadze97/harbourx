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
        const matchesSearch = (record.dataset.search || '').includes(query);
        const show = matchesSearch && matchesFilter(record, filterValue);
        record.hidden = !show;
        if (show) count++;
      });
      if (visibleCount) visibleCount.textContent = String(count);
      if (empty) empty.hidden = count !== 0;
    };

    search?.addEventListener('input', update);
    filter?.addEventListener('change', update);
    sort?.addEventListener('change', update);
    update();
  }
})();

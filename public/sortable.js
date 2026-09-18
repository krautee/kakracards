// Click a column header to sort any table that has a <thead> (add class "no-sort" to a
// table to opt out). Numeric where cells look numeric: "93.2%", "$0.0123", "1 234",
// "#42"; "-", "·" and empty cells always sort last.
(function () {
  function cellValue(td) {
    const text = (td.textContent || '').trim();
    if (text === '' || text === '-' || text === '·') return { num: null, text: '' };
    const cleaned = text.replace(/[%$#\s]/g, '').replace(/\(.*\)$/, '').replace(',', '.');
    const num = cleaned !== '' && /^-?\d+(\.\d+)?$/.test(cleaned) ? parseFloat(cleaned) : null;
    return { num: num, text: text.toLowerCase() };
  }

  function makeSortable(table) {
    const head = table.tHead;
    const body = table.tBodies[0];
    if (!head || !body || head.rows.length === 0) return;
    const headerRow = head.rows[head.rows.length - 1];
    Array.from(headerRow.cells).forEach(function (th, index) {
      if (th.classList.contains('no-sort')) return;
      th.classList.add('sortable');
      th.addEventListener('click', function (e) {
        if (e.target && e.target.closest && e.target.closest('a, button, input, select')) return;
        const currentlyAsc = th.classList.contains('sorted-asc');
        Array.from(headerRow.cells).forEach(function (h) { h.classList.remove('sorted-asc', 'sorted-desc'); });
        const asc = !currentlyAsc;
        th.classList.add(asc ? 'sorted-asc' : 'sorted-desc');
        // Rows that span groups (section headers) cannot be sorted meaningfully; keep them out.
        const rows = Array.from(body.rows).filter(function (r) { return !r.classList.contains('section-head'); });
        const numeric = rows.some(function (r) { return r.cells[index] && cellValue(r.cells[index]).num !== null; });
        rows.sort(function (a, b) {
          const va = a.cells[index] ? cellValue(a.cells[index]) : { num: null, text: '' };
          const vb = b.cells[index] ? cellValue(b.cells[index]) : { num: null, text: '' };
          if (numeric) {
            if (va.num === null && vb.num === null) return 0;
            if (va.num === null) return 1;
            if (vb.num === null) return -1;
            return asc ? va.num - vb.num : vb.num - va.num;
          }
          if (va.text === '' && vb.text !== '') return 1;
          if (vb.text === '' && va.text !== '') return -1;
          return asc ? va.text.localeCompare(vb.text) : vb.text.localeCompare(va.text);
        });
        rows.forEach(function (r) { body.appendChild(r); });
      });
    });
  }

  function init() {
    document.querySelectorAll('table').forEach(function (table) {
      if (table.classList.contains('no-sort') || table.dataset.sortableReady === '1') return;
      table.dataset.sortableReady = '1';
      makeSortable(table);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
  window.initSortableTables = init;
})();

/* ISP_ALERT_PANEL_V1: progressive enhancement of existing status widget.
 * No network calls, hidden-alert state changes, or invented optical readings.
 */
(function () {
  'use strict';
  if (window.ISPAlertPanelV1) return;
  window.ISPAlertPanelV1 = true;
  var TITLE = /Status Warnings and Notifications/i;
  var state = { overlay: null, observer: null, last: null };

  function findWidget() {
    var titles = document.querySelectorAll('.box-header, .grid-stack-item, h3, h4');
    for (var i = 0; i < titles.length; i++) {
      var el = titles[i];
      if (!TITLE.test((el.textContent || '').trim())) continue;
      var box = el.closest('.grid-stack-item') || el.closest('.box');
      if (box && box.querySelector('table')) return box;
    }
    return null;
  }

  function statusRows(source) {
    var table = source.querySelector('table');
    if (!table) return [];
    return Array.prototype.slice.call(table.querySelectorAll('tbody tr')).filter(function (r) {
      return r.querySelectorAll('td').length > 0;
    }).map(function (row) {
      var tds = row.querySelectorAll('td');
      var cells = Array.prototype.map.call(tds, function (td) { return (td.textContent || '').trim(); });
      var text = cells.join(' ');
      var kind = /Device\s*Down|equipamento\s*(?:down|indisponível)/i.test(text) ? 'device' :
        /Port\s*Down|interface\s*down|porta\s*down/i.test(text) ? 'port' :
        /degradad|alto\s*erro|high\s*error/i.test(text) ? 'degraded' :
        /sem\s*coleta|no\s*poll|stale/i.test(text) ? 'stale' : 'other';
      return { cells: cells, kind: kind, text: text };
    });
  }

  function element(tag, cls, label) {
    var node = document.createElement(tag);
    if (cls) node.className = cls;
    if (label !== undefined) node.textContent = label;
    return node;
  }

  function detail(label, value, note) {
    var card = element('div', 'isp-alert-stat');
    card.appendChild(element('div', 'isp-alert-stat-label', label));
    card.appendChild(element('strong', '', String(value)));
    if (note) card.appendChild(element('small', '', note));
    return card;
  }

  function render() {
    if (!state.overlay) return;
    var source = findWidget();
    var rows = source ? statusRows(source) : [];
    var counts = { device: 0, port: 0, degraded: 0, stale: 0, other: 0 };
    rows.forEach(function (row) { counts[row.kind]++; });
    var cards = state.overlay.querySelector('.isp-alert-summary');
    var body = state.overlay.querySelector('tbody');
    if (!cards || !body) return;
    cards.replaceChildren(
      detail('Equipamentos DOWN', counts.device),
      detail('Portas DOWN', counts.port),
      detail('Degradadas', counts.degraded, 'Somente eventos já registrados'),
      detail('Sem coleta', counts.stale),
      detail('Sinal óptico RX/TX', '—', 'Integração SNMP ainda não configurada')
    );
    body.replaceChildren();
    var filter = state.overlay.querySelector('select').value;
    rows.filter(function (row) { return filter === 'all' || row.kind === filter; }).forEach(function (r) {
      var tr = document.createElement('tr');
      var category = { device: 'Equipamento DOWN', port: 'Porta DOWN', degraded: 'Degradado', stale: 'Sem coleta', other: 'Outro' };
      tr.appendChild(element('td', '', category[r.kind]));
      tr.appendChild(element('td', '', r.cells[0] || '—'));
      tr.appendChild(element('td', '', r.cells.slice(1).join(' · ') || '—'));
      body.appendChild(tr);
    });
    state.overlay.querySelector('.isp-alert-empty').hidden = body.children.length > 0;
    state.overlay.querySelector('.isp-alert-count').textContent = rows.length + ' eventos exibidos pelo widget';
  }

  function close() {
    if (!state.overlay) return;
    state.overlay.remove();
    state.overlay = null;
    if (state.observer) state.observer.disconnect();
    state.observer = null;
    document.body.classList.remove('isp-alert-open');
    if (state.last) state.last.focus();
  }

  function open(button) {
    if (state.overlay) return;
    state.last = button;
    var root = element('div', 'isp-alert-backdrop');
    root.setAttribute('role', 'dialog');
    root.setAttribute('aria-modal', 'true');
    root.setAttribute('aria-label', 'Central de alertas ISP');
    var modal = element('div', 'isp-alert-modal');
    var header = element('header', 'isp-alert-heading');
    header.appendChild(element('h2', '', 'Central de alertas ISP'));
    var dismiss = element('button', 'isp-alert-close', 'Fechar ×');
    dismiss.type = 'button';
    dismiss.addEventListener('click', close);
    header.appendChild(dismiss);
    modal.appendChild(header);
    modal.appendChild(element('p', 'isp-alert-count', ''));
    var layout = element('div', 'isp-alert-layout');
    var main = element('section', 'isp-alert-list');
    var toolbar = element('div', 'isp-alert-toolbar');
    toolbar.appendChild(element('strong', '', 'Ocorrências monitoradas'));
    var select = element('select', '');
    select.setAttribute('aria-label', 'Filtrar alertas');
    [['all', 'Todos'], ['device', 'Device Down'], ['port', 'Port Down'], ['degraded', 'Degradadas'], ['stale', 'Sem coleta'], ['other', 'Outros']].forEach(function (item) {
      var option = document.createElement('option');
      option.value = item[0]; option.textContent = item[1]; select.appendChild(option);
    });
    select.addEventListener('change', render);
    toolbar.appendChild(select);
    main.appendChild(toolbar);
    var scroller = element('div', 'isp-alert-scroller');
    var table = document.createElement('table');
    var thead = document.createElement('thead');
    var headings = document.createElement('tr');
    ['Tipo', 'Equipamento / referência', 'Detalhes'].forEach(function (text) {
      headings.appendChild(element('th', '', text));
    });
    thead.appendChild(headings); table.appendChild(thead);
    table.appendChild(document.createElement('tbody'));
    scroller.appendChild(table); main.appendChild(scroller);
    var empty = element('p', 'isp-alert-empty', 'Nenhuma ocorrência nessa categoria.');
    main.appendChild(empty); layout.appendChild(main);
    var aside = element('aside', 'isp-alert-summary');
    aside.setAttribute('aria-label', 'Resumo de alertas');
    layout.appendChild(aside); modal.appendChild(layout);
    modal.appendChild(element('p', 'isp-alert-notice', 'Os contadores são calculados apenas das linhas atualmente exibidas pelo widget. Alertas ocultos e páginas não carregadas não entram na contagem; dados ópticos não são inferidos.'));
    root.appendChild(modal);
    root.addEventListener('click', function (event) { if (event.target === root) close(); });
    document.body.appendChild(root);
    document.body.classList.add('isp-alert-open');
    state.overlay = root;
    state.observer = new MutationObserver(function () { render(); });
    var source = findWidget();
    if (source) state.observer.observe(source, { childList: true, subtree: true, characterData: true });
    render(); dismiss.focus();
  }

  function attach() {
    if (!/dashboard/i.test(location.pathname + location.search)) return;
    var box = findWidget();
    if (!box || box.querySelector('.isp-alert-expand-v1')) return;
    var header = box.querySelector('.box-header') || box;
    var button = element('button', 'isp-alert-expand-v1', '⛶ Tela cheia');
    button.type = 'button';
    button.addEventListener('click', function () { open(button); });
    header.appendChild(button);
  }

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && state.overlay) close();
  });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', attach);
  else attach();
  // AJAX dashboard can replace the widget, so bind only a low-cost DOM watcher.
  var watcher = new MutationObserver(function () { attach(); });
  watcher.observe(document.documentElement, { childList: true, subtree: true });
})();
